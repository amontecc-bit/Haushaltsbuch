<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CsvProfileRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\TransactionRepository;
use App\Services\CategorizationService;
use App\Services\CsvImportService;
use App\Services\RecurrenceService;

final class ImportController extends Controller
{
    public function index(): void
    {
        $ids = Auth::accountIds('book');
        $accounts = (new AccountRepository())->options($this->hid, $ids);
        if (!$accounts) {
            $this->redirect('/accounts', 'Es gibt noch kein Konto, auf das du buchen darfst.', 'warning');
        }
        $this->view('import/index', [
            'title'    => 'Kontoauszug importieren (CSV)',
            'accounts' => $accounts,
            'profiles' => (new CsvProfileRepository())->all($this->hid),
            'batches'  => (new CsvProfileRepository())->recentBatches($this->hid, Auth::accountIds('view')),
            'selected' => $this->request->int('account_id'),
        ]);
    }

    public function upload(): void
    {
        $file = $this->request->file('csv');
        $accountId = (int) $this->request->int('account_id');
        if (!$file) {
            $this->redirect('/import', 'Bitte eine CSV-Datei auswählen.', 'danger');
        }
        if (!Auth::can($accountId, 'book')) {
            $this->redirect('/import', 'Auf dieses Konto darfst du nicht buchen.', 'danger');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true) || $file['size'] > 10 * 1024 * 1024) {
            $this->redirect('/import', 'Bitte eine CSV-Datei (max. 10 MB) hochladen.', 'danger');
        }
        $dir = Config::get('paths.uploads') . '/import';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->cleanupOld($dir);
        $name = bin2hex(random_bytes(12)) . '.csv';
        move_uploaded_file($file['tmp_name'], "$dir/$name");
        $profile = $this->request->int('profile_id');
        Session::set('import', [
            'file' => $name, 'name' => $file['name'], 'account_id' => $accountId,
            'profile_id' => $profile, 'mapping' => null,
        ]);
        $this->redirect('/import/preview');
    }

    public function preview(): void
    {
        $state = $this->state();
        $account = (new AccountRepository())->find($state['account_id'], $this->hid);
        $content = CsvImportService::readFile($this->path($state));
        [$profile, $mapping, $delimiter, $decimalSep] = $this->resolveMapping($state, $content);
        $rows = CsvImportService::parse($content, $delimiter);
        $header = $mapping ? CsvImportService::locateHeader($rows, $mapping) : null;

        $records = [];
        $warnings = [];
        if ($header) {
            $records = $this->analyse($state['account_id'], CsvImportService::records($rows, $header, $decimalSep));
            $ownIbans = array_unique(array_filter(array_column($records, 'own_iban')));
            if ($ownIbans && $account['iban'] && !in_array(strtoupper(str_replace(' ', '', $account['iban'])), $ownIbans, true)) {
                $warnings[] = 'Die IBAN in der Datei (' . implode(', ', $ownIbans) . ') passt nicht zum gewählten Konto „' . $account['name'] . '“.';
            }
        }

        $this->view('import/preview', [
            'title'      => 'Import prüfen',
            'back'       => '/import',
            'state'      => $state,
            'account'    => $account,
            'profile'    => $profile,
            'mapping'    => $mapping ?? [],
            'delimiter'  => $delimiter,
            'decimalSep' => $decimalSep,
            'header'     => $header,
            'headerRow'  => $header ? $rows[$header['index']] : ($rows[0] ?? []),
            'sampleRows' => array_slice($rows, 0, 12),
            'records'    => $records,
            'warnings'   => $warnings,
            'categories' => (new CategoryRepository())->all($this->hid),
            'profiles'   => (new CsvProfileRepository())->all($this->hid),
        ]);
    }

    /** Spaltenzuordnung manuell ändern (optional als Profil speichern) */
    public function remap(): void
    {
        $state = $this->state();
        if ($this->request->int('profile_id')) {
            $state['profile_id'] = $this->request->int('profile_id');
            $state['mapping'] = null;
            Session::set('import', $state);
            $this->redirect('/import/preview');
        }
        $mapping = [];
        foreach (array_keys(CsvImportService::FIELDS) as $f) {
            $v = $this->request->str("map_$f");
            if ($v !== '') {
                $mapping[$f] = $v;
            }
        }
        $delimiter = $this->request->str('delimiter');
        $delimiter = $delimiter === 'tab' ? "\t" : (in_array($delimiter, [';', ',', '|'], true) ? $delimiter : ';');
        $decimalSep = $this->request->str('decimal_sep') === '.' ? '.' : ',';
        $state['mapping'] = ['fields' => $mapping, 'delimiter' => $delimiter, 'decimal_sep' => $decimalSep];
        $state['profile_id'] = null;
        $name = mb_substr($this->request->str('profile_name'), 0, 100);
        if ($name !== '' && $mapping) {
            $state['profile_id'] = (new CsvProfileRepository())->save($this->hid, $name, $delimiter, $decimalSep, $mapping);
            $state['mapping'] = null;
        }
        Session::set('import', $state);
        $this->redirect('/import/preview', $name !== '' ? "Profil „{$name}“ gespeichert." : null);
    }

    public function commit(): void
    {
        $state = $this->state();
        Auth::authorize($state['account_id'], 'book');
        $content = CsvImportService::readFile($this->path($state));
        [, $mapping, $delimiter, $decimalSep] = $this->resolveMapping($state, $content);
        $rows = CsvImportService::parse($content, $delimiter);
        $header = $mapping ? CsvImportService::locateHeader($rows, $mapping) : null;
        if (!$header) {
            $this->redirect('/import/preview', 'Die Spalten konnten nicht zugeordnet werden.', 'danger');
        }
        $records = $this->analyse($state['account_id'], CsvImportService::records($rows, $header, $decimalSep));
        $actions = $this->request->arr('action');
        $cats = $this->request->arr('category');
        $catRepo = new CategoryRepository();
        $txRepo = new TransactionRepository();
        $profiles = new CsvProfileRepository();

        $imported = $linked = $skipped = 0;
        $batch = $profiles->createBatch($this->hid, $state['account_id'], $state['name'], Auth::id());
        $txRepo->transaction(function () use ($records, $actions, $cats, $catRepo, $txRepo, $state, $batch, &$imported, &$linked, &$skipped) {
            foreach ($records as $r) {
                $action = $actions[$r['hash']] ?? $r['default_action'];
                if ($r['status'] === 'duplicate' || $action === 'skip') {
                    $skipped++;
                    continue;
                }
                $chosen = array_key_exists($r['hash'], $cats) ? (int) $cats[$r['hash']] : ($r['category_id'] ?? null);
                $cat = $catRepo->validId($chosen ? (int) $chosen : null, $this->hid);
                if ($action === 'link' && $r['match']) {
                    $existing = $r['match'];
                    $upd = ['import_hash' => $r['hash'], 'booking_date' => $r['date'], 'import_batch_id' => $batch];
                    if (!$existing['payee'] && $r['payee']) {
                        $upd['payee'] = $r['payee'];
                    }
                    if (!$existing['purpose'] && $r['purpose']) {
                        $upd['purpose'] = $r['purpose'];
                    }
                    if (!$existing['category_id'] && $cat) {
                        $upd['category_id'] = $cat;
                    }
                    $txRepo->update((int) $existing['id'], $this->hid, $upd);
                    $linked++;
                    continue;
                }
                $txRepo->create($this->hid, [
                    'account_id'      => $state['account_id'],
                    'booking_date'    => $r['date'],
                    'amount'          => $r['amount'],
                    'payee'           => $r['payee'] ?: null,
                    'purpose'         => $r['purpose'] ?: null,
                    'category_id'     => $cat,
                    'source'          => 'csv',
                    'import_hash'     => $r['hash'],
                    'import_batch_id' => $batch,
                    'recurring_id'    => $r['recurring_id'],
                    'created_by'      => Auth::id(),
                ]);
                $imported++;
            }
        });
        $profiles->finishBatch($batch, count($records), $imported + $linked, $skipped);
        @unlink($this->path($state));
        Session::forget('import');
        $msg = "$imported Buchungen importiert";
        $msg .= $linked ? ", $linked mit vorhandenen Buchungen zusammengeführt" : '';
        $msg .= $skipped ? ", $skipped übersprungen." : '.';
        $this->redirect('/transactions?account_id=' . $state['account_id'], $msg);
    }

    /**
     * Status je Zeile: duplicate (bereits importiert), match (entspricht vorhandener Buchung, z. B. aus Dauerauftrag),
     * pending (vorgemerkt), new. Dazu Kategorie-Vorschlag und passende wiederkehrende Buchung.
     */
    private function analyse(int $accountId, array $records): array
    {
        $records = CsvImportService::assignHashes($accountId, $records);
        $txRepo = new TransactionRepository();
        $svc = new CategorizationService($this->hid);
        $templates = array_filter(
            (new RecurringRepository())->all($this->hid, [$accountId], true),
            fn ($t) => !$t['to_account_id'] && (int) $t['account_id'] === $accountId
        );
        $usedMatches = [];
        foreach ($records as &$r) {
            $r['match'] = null;
            $r['recurring_id'] = null;
            $r['category_id'] = null;
            $r['suggestion'] = null;
            if ($txRepo->hashExists($accountId, $r['hash'])) {
                $r['status'] = 'duplicate';
                $r['default_action'] = 'skip';
                continue;
            }
            $match = $txRepo->findMatchForImport($accountId, $r['amount'], $r['date']);
            if ($match && !isset($usedMatches[$match['id']])) {
                $usedMatches[$match['id']] = true;
                $r['match'] = $match;
                $r['status'] = 'match';
                $r['default_action'] = 'link';
                $r['category_id'] = $match['category_id'];
                continue;
            }
            foreach ($templates as $t) {
                if ($t['amount'] === $r['amount']) {
                    $from = date('Y-m-d', strtotime($r['date'] . ' -5 days'));
                    $to = date('Y-m-d', strtotime($r['date'] . ' +5 days'));
                    if (RecurrenceService::occurrences($t, $from, $to)) {
                        $r['recurring_id'] = (int) $t['id'];
                        $r['category_id'] = $t['category_id'];
                        break;
                    }
                }
            }
            $r['status'] = $r['pending'] ? 'pending' : 'new';
            $r['default_action'] = $r['pending'] ? 'skip' : 'import';
            if (empty($r['category_id'])) {
                $s = $svc->suggestForTransaction($r['payee'], $r['purpose'] . ' ' . $r['booking_text']);
                $r['category_id'] = $s['category_id'];
                $r['suggestion'] = $s['source'];
            }
        }
        return $records;
    }

    /** @return array{0:?array, 1:?array, 2:string, 3:string} Profil, Mapping, Trennzeichen, Dezimaltrennzeichen */
    private function resolveMapping(array $state, string $content): array
    {
        if (!empty($state['mapping'])) {
            return [null, $state['mapping']['fields'], $state['mapping']['delimiter'], $state['mapping']['decimal_sep']];
        }
        $repo = new CsvProfileRepository();
        if (!empty($state['profile_id']) && ($p = $repo->find((int) $state['profile_id'], $this->hid))) {
            $delimiter = $p['delimiter'] ?: CsvImportService::detectDelimiter($content);
            return [$p, json_decode($p['mapping'], true) ?: [], $delimiter, $p['decimal_sep']];
        }
        $delimiter = CsvImportService::detectDelimiter($content);
        $rows = CsvImportService::parse($content, $delimiter);
        $candidates = array_filter($repo->all($this->hid), fn ($p) => $p['delimiter'] === $delimiter);
        $p = CsvImportService::detectProfile($rows, $candidates);
        if ($p) {
            return [$p, $p['mapping_array'], $delimiter, $p['decimal_sep']];
        }
        $guess = CsvImportService::guessMapping($rows);
        return [null, $guess ?: null, $delimiter, $delimiter === ',' ? '.' : ','];
    }

    private function state(): array
    {
        $state = Session::get('import');
        if (!is_array($state) || !is_file($this->path($state))) {
            $this->redirect('/import', 'Bitte zuerst eine Datei hochladen.', 'warning');
        }
        if (!Auth::can((int) $state['account_id'], 'book')) {
            $this->redirect('/import', 'Keine Berechtigung für dieses Konto.', 'danger');
        }
        return $state;
    }

    private function path(array $state): string
    {
        return Config::get('paths.uploads') . '/import/' . basename((string) ($state['file'] ?? 'x'));
    }

    /** Abgebrochene Importe nach einem Tag entfernen */
    private function cleanupOld(string $dir): void
    {
        foreach (glob("$dir/*.csv") ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
