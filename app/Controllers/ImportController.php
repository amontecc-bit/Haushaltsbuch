<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Money;
use App\Core\Session;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CsvProfileRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TransactionRepository;
use App\Services\CategorizationService;
use App\Services\CsvImportService;
use App\Services\RecurrenceService;
use App\Services\TransactionService;

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

    /**
     * AJAX: Kategorie-Zuweisung als dauerhafte Regel speichern.
     * Antwort: die Zeilen (Hashes) der aktuellen Datei, auf die die neue Regel passt – die Vorschau übernimmt dort die Kategorie.
     */
    public function rule(): void
    {
        $state = $this->state();
        if (Auth::isChild()) {
            $this->json(['error' => 'Keine Berechtigung, Regeln anzulegen.'], 403);
        }
        $field = in_array($this->request->str('field'), ['payee', 'purpose', 'any'], true) ? $this->request->str('field') : 'payee';
        $operator = in_array($this->request->str('operator'), ['contains', 'equals', 'starts'], true) ? $this->request->str('operator') : 'contains';
        $value = mb_strtolower(trim(mb_substr($this->request->str('value'), 0, 190)));
        $cat = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        if ($value === '' || !$cat) {
            $this->json(['error' => 'Bitte Suchbegriff und Kategorie angeben.'], 422);
        }
        $rules = new RuleRepository();
        if (!$rules->exists($this->hid, 'transaction', $field, $operator, $value)) {
            $rules->create($this->hid, [
                'target' => 'transaction', 'field' => $field, 'operator' => $operator,
                'value' => $value, 'category_id' => $cat, 'priority' => 100,
            ]);
        }
        $rule = ['field' => $field, 'operator' => $operator, 'value' => $value];

        $content = CsvImportService::readFile($this->path($state));
        [, $mapping, $delimiter, $decimalSep] = $this->resolveMapping($state, $content);
        $rows = CsvImportService::parse($content, $delimiter);
        $header = $mapping ? CsvImportService::locateHeader($rows, $mapping) : null;
        $records = $header ? CsvImportService::assignHashes($state['account_id'], CsvImportService::records($rows, $header, $decimalSep)) : [];
        $hashes = [];
        foreach ($records as $r) {
            // gleiche Felder wie beim Vorschlag in analyse()
            if (CategorizationService::ruleMatches($rule, ['payee' => $r['payee'], 'purpose' => $r['purpose'] . ' ' . $r['booking_text']])) {
                $hashes[] = $r['hash'];
            }
        }
        $this->json(['ok' => true, 'category' => (string) $cat, 'hashes' => $hashes]);
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
        $recurring = array_filter($this->request->arr('recurring'), fn ($i) => array_key_exists((string) $i, RecurrenceService::STEPS));
        $linkPurchase = $this->request->arr('purchase');
        $catRepo = new CategoryRepository();
        $txRepo = new TransactionRepository();
        $purchaseRepo = new PurchaseRepository();
        $profiles = new CsvProfileRepository();
        $txService = new TransactionService($txRepo);

        $imported = $linked = $skipped = $templates = $purchases = $transfers = 0;
        $batch = $profiles->createBatch($this->hid, $state['account_id'], $state['name'], Auth::id());
        $txRepo->transaction(function () use ($records, $actions, $cats, $recurring, $linkPurchase, $catRepo, $txRepo, $txService, $purchaseRepo, $state, $batch, &$imported, &$linked, &$skipped, &$templates, &$purchases, &$transfers) {
            $categoryOf = function (array $r) use ($cats, $catRepo): ?int {
                $chosen = array_key_exists($r['hash'], $cats) ? (int) $cats[$r['hash']] : ($r['category_id'] ?? null);
                return $catRepo->validId($chosen ? (int) $chosen : null, $this->hid);
            };
            // Als Fixkosten markierte Zeilen → Vorlagen anlegen; gleiche Zahlungen (Empfänger + Betrag) der Datei gehören dazu
            $newTemplates = [];
            $lastDate = max(array_column($records, 'date') ?: [date('Y-m-d')]);
            foreach ($records as $r) {
                if (!isset($recurring[$r['hash']]) || $r['recurring_id'] || $r['status'] === 'duplicate' || ($actions[$r['hash']] ?? $r['default_action']) !== 'import') {
                    continue;
                }
                $key = mb_strtolower((string) $r['payee']) . '|' . $r['amount'];
                if (isset($newTemplates[$key])) {
                    continue;
                }
                $newTemplates[$key] = (new RecurringRepository())->create($this->hid, [
                    'account_id'   => $state['account_id'],
                    'category_id'  => $categoryOf($r),
                    'amount'       => $r['amount'],
                    'payee'        => mb_substr((string) $r['payee'], 0, 190) ?: null,
                    'purpose'      => mb_substr((string) $r['purpose'], 0, 255) ?: null,
                    'interval'     => $recurring[$r['hash']],
                    'day_of_month' => (int) substr($r['date'], 8, 2),
                    'start_date'   => $r['date'],
                    'auto_book'    => 1,
                    'active'       => 1,
                    // Termine bis zum Ende der Datei stehen schon im Kontoauszug – erst danach automatisch buchen
                    'last_booked_date' => max($r['date'], $lastDate),
                    'created_by'   => Auth::id(),
                ]);
                $templates++;
            }

            foreach ($records as $r) {
                $action = $actions[$r['hash']] ?? $r['default_action'];
                if ($r['status'] === 'duplicate' || $action === 'skip') {
                    $skipped++;
                    continue;
                }
                $cat = $categoryOf($r);
                $r['recurring_id'] ??= $newTemplates[mb_strtolower((string) $r['payee']) . '|' . $r['amount']] ?? null;
                if ($action === 'link' && $r['match']) {
                    $existing = $r['match'];
                    $upd = ['import_hash' => $r['hash'], 'booking_date' => $r['date'], 'import_batch_id' => $batch];
                    if (!$existing['payee'] && $r['payee']) {
                        $upd['payee'] = $r['payee'];
                    }
                    if (!$existing['purpose'] && $r['purpose']) {
                        $upd['purpose'] = $r['purpose'];
                    }
                    // Umbuchungen haben keine Kategorie
                    if (!$existing['category_id'] && $cat && !$existing['transfer_group']) {
                        $upd['category_id'] = $cat;
                    }
                    $txRepo->update((int) $existing['id'], $this->hid, $upd);
                    $linked++;
                    continue;
                }
                $transfer = $r['transfer'];
                if ($action === 'transfer' && $transfer && Auth::can($transfer['account_id'], 'book')) {
                    $row = [
                        'booking_date' => $r['date'],
                        'payee'        => $r['payee'] ?: null,
                        'purpose'      => $r['purpose'] ?: null,
                        'source'       => 'csv',
                        'created_by'   => Auth::id(),
                    ];
                    $own = ['import_hash' => $r['hash'], 'import_batch_id' => $batch];
                    if ($transfer['partner']) {
                        $txId = $txRepo->create($this->hid, $own + $row + ['account_id' => $state['account_id'], 'amount' => $r['amount']]);
                        $txService->joinAsTransfer($this->hid, $txId, (int) $transfer['partner']['id']);
                    } else {
                        // Gegenseite ohne Import-Kennung – der Import des anderen Kontos führt sie später zusammen
                        $cents = Money::toCents($r['amount']);
                        [$from, $to] = $cents < 0 ? [$state['account_id'], $transfer['account_id']] : [$transfer['account_id'], $state['account_id']];
                        $txService->createTransfer($this->hid, $from, $to, $cents, $r['date'], $row,
                            $cents < 0 ? $own : [], $cents < 0 ? [] : $own);
                    }
                    $transfers++;
                    continue;
                }
                $txId = $txRepo->create($this->hid, [
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
                // Passender Einkauf ohne Buchung wird mit der importierten Buchung verknüpft
                if ($r['purchase'] && ($linkPurchase[$r['hash']] ?? '1') === '1') {
                    $upd = ['transaction_id' => $txId];
                    if (!$r['purchase']['account_id']) {
                        $upd['account_id'] = $state['account_id'];
                    }
                    $purchaseRepo->update((int) $r['purchase']['id'], $this->hid, $upd);
                    $purchases++;
                }
                $imported++;
            }
        });
        $profiles->finishBatch($batch, count($records), $imported + $linked + $transfers, $skipped);
        if ($templates) {
            RecurrenceService::materializeDue($this->hid);
        }
        @unlink($this->path($state));
        Session::forget('import');
        $msg = "$imported Buchungen importiert";
        $msg .= $linked ? ", $linked mit vorhandenen Buchungen zusammengeführt" : '';
        $msg .= $transfers ? ", $transfers als Umbuchung übernommen" : '';
        $msg .= $purchases ? ", $purchases mit Einkäufen verknüpft" : '';
        $msg .= $templates ? ", $templates Fixkosten angelegt" : '';
        $msg .= $skipped ? ", $skipped übersprungen." : '.';
        $this->redirect('/transactions?account_id=' . $state['account_id'], $msg);
    }

    /**
     * Status je Zeile: duplicate (bereits importiert), match (entspricht vorhandener Buchung, z. B. aus Dauerauftrag,
     * Einkauf oder einer Umbuchung), transfer (Umbuchung mit eigenem Konto – Gegenbuchung vorhanden oder wird angelegt),
     * pending (vorgemerkt), new. Dazu Kategorie-Vorschlag, passende wiederkehrende Buchung und ein passender Einkauf
     * ohne Buchung.
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
        $purchaseRepo = new PurchaseRepository();
        // Eigene Konten als mögliches Ziel einer Umbuchung (nur solche, auf die gebucht werden darf)
        $others = array_values(array_filter(
            (new AccountRepository())->transferTargets($this->hid, Auth::accountIds('book')),
            fn ($a) => (int) $a['id'] !== $accountId
        ));
        $usedMatches = $usedPurchases = $usedCounterparts = [];
        foreach ($records as &$r) {
            $r['match'] = null;
            $r['purchase'] = null;
            $r['transfer'] = null;
            $r['recurring_id'] = null;
            $r['category_id'] = null;
            $r['suggestion'] = null;
            if ($txRepo->hashExists($accountId, $r['hash'])) {
                $r['status'] = 'duplicate';
                $r['default_action'] = 'skip';
                continue;
            }
            // Gegenkonto per IBAN/Kontonummer: ein eigenes Konto → Umbuchung; ein fremdes → sicher keine
            $counter = null;
            foreach ($others as $o) {
                if (CsvImportService::sameAccount($r['counter_iban'], $o['iban'])) {
                    $counter = $o;
                    break;
                }
            }
            // Vorhandene Buchung: bei Umbuchungen bevorzugt deren Seite mit passendem Gegenkonto, sonst beim selben
            // Empfänger/Geschäft, sonst nächstes Datum. Seiten mit fremdem Hash (Altlast beim Bearbeiten) zählen mit.
            $matches = array_filter($txRepo->matchesForImport($accountId, $r['amount'], $r['date']),
                fn ($m) => !isset($usedMatches[$m['id']]) && ($m['import_hash'] === null || !CsvImportService::isOwnHash($m)));
            $score = fn ($m) => [
                $counter && (int) $m['transfer_account_id'] === (int) $counter['id'],
                CategorizationService::samePayee($m['purchase_store'] ?: $m['payee'], $r['payee']),
            ];
            usort($matches, fn ($a, $b) => $score($b) <=> $score($a));
            $match = $matches[0] ?? null;
            if ($match) {
                $usedMatches[$match['id']] = true;
                $r['match'] = $match;
                $r['status'] = 'match';
                $r['default_action'] = 'link';
                $r['category_id'] = $match['category_id'];
                continue;
            }
            // Umbuchung: Gegenbuchung schon auf dem anderen Konto (z. B. aus dessen Kontoauszug) → verbinden; bei
            // erkanntem Gegenkonto sonst neu anlegen. Ohne IBAN genügt eine exakt gegenläufige Buchung (±3 Tage).
            if (!$r['pending'] && ($counter || ($r['counter_iban'] === '' && $others))) {
                $opposite = Money::toDecimal(-$r['cents']);
                $candidates = array_filter(
                    $txRepo->transferCounterparts($this->hid, $counter ? [(int) $counter['id']] : array_map('intval', array_column($others, 'id')), $opposite, $r['date'], $counter ? 5 : 3),
                    fn ($c) => !isset($usedCounterparts[$c['id']])
                );
                $partner = reset($candidates) ?: null;
                if ($partner || $counter) {
                    if ($partner) {
                        $usedCounterparts[$partner['id']] = true;
                    }
                    $r['transfer'] = [
                        'account_id'   => (int) ($partner['account_id'] ?? $counter['id']),
                        'account_name' => $partner['account_name'] ?? $counter['name'],
                        'partner'      => $partner,
                    ];
                }
            }
            // Einkauf (Bon/PDF) ohne Buchung mit gleicher Summe → beim Import verknüpfen
            if ((float) $r['amount'] < 0 && !$r['transfer']) {
                $total = Money::toDecimal(-Money::toCents($r['amount']));
                $purchases = array_filter($purchaseRepo->unlinkedForImport($this->hid, $accountId, $total, $r['date']), fn ($p) => !isset($usedPurchases[$p['id']]));
                usort($purchases, fn ($a, $b) => CategorizationService::samePayee($b['store'], $r['payee']) <=> CategorizationService::samePayee($a['store'], $r['payee']));
                if ($p = $purchases[0] ?? null) {
                    $usedPurchases[$p['id']] = true;
                    $r['purchase'] = $p;
                    $r['category_id'] = $purchaseRepo->dominantCategory((int) $p['id']);
                }
            }
            foreach ($r['transfer'] ? [] : $templates as $t) {
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
            $r['status'] = $r['transfer'] ? 'transfer' : ($r['pending'] ? 'pending' : 'new');
            $r['default_action'] = $r['transfer'] ? 'transfer' : ($r['pending'] ? 'skip' : 'import');
            // Vorschlag auch bei Umbuchungen – falls die Zeile doch normal importiert wird
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
