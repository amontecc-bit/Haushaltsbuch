<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\UserRepository;
use App\Repositories\WishRepository;
use App\Services\WishService;

/**
 * Wunschliste (Anschaffungen, Weihnachtswünsche) mit Bild und virtuellem Sparplan.
 * Wünsche sieht der ganze Haushalt; Sparplan-Details (Konto, Posten, Erspartes) nur, wer das Konto sehen darf.
 */
final class WishController extends Controller
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_IMAGE_PX = 1600;

    public function index(): void
    {
        $r = $this->request;
        $f = [
            'status'      => in_array($r->str('status'), ['open', 'fulfilled', 'all'], true) ? $r->str('status') : 'open',
            'occasion'    => mb_substr($r->str('occasion'), 0, 100),
            'for_user_id' => $r->int('for_user_id'),
            'q'           => mb_substr($r->str('q'), 0, 100),
        ];
        $repo = new WishRepository();
        $a = $this->analysis();
        $today = date('Y-m-d');
        $wishes = $repo->all($this->hid, $f);
        foreach ($wishes as &$w) {
            $w = $this->decorate($w, $a, $today);
        }
        unset($w);

        $open = array_filter($wishes, fn ($w) => $w['status'] === 'open');
        $this->view('wishes/index', [
            'title'     => 'Wunschliste',
            'wishes'    => $wishes,
            'filters'   => $f,
            'occasions' => $repo->occasions($this->hid),
            'users'     => (new UserRepository())->all($this->hid),
            'sumPrice'  => array_sum(array_map(fn ($w) => Money::toCents($w['price']), $open)),
            'sumRates'  => array_sum(array_map(fn ($p) => $p['plan']['running'] && $p['visible'] ? $p['plan']['rate'] : 0, $a['wishes'])),
            'overCount' => count(array_filter($a['usage'], fn ($u) => $u['over'])),
            'missing'   => array_sum(array_map(fn ($c) => $c['missing'], $a['coverage'])),
            'actions'   => '<a href="' . e(url('/wishes/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Wunsch</a>',
        ]);
    }

    /** Übersicht der Posten mit zugeordneten Sparplänen: Auslastung, frei/überschritten, Deckung je Konto */
    public function plans(): void
    {
        $a = $this->analysis();
        $byRec = [];
        $without = [];
        foreach ($a['wishes'] as $w) {
            if (!$w['visible']) {
                continue;
            }
            $rid = (int) $w['plan_recurring_id'];
            if ($rid && isset($a['recurring'][$rid])) {
                $byRec[$rid][] = $w;
            } elseif (!$rid) {
                $without[] = $w;
            }
        }
        $groups = [];
        foreach ($byRec as $rid => $ws) {
            $groups[] = ['rec' => $a['recurring'][$rid], 'usage' => $a['usage'][$rid], 'wishes' => $ws];
        }
        // überschrittene Posten zuerst, dann nach Betrag
        usort($groups, fn ($x, $y) => [$y['usage']['over'], $y['usage']['capacity']] <=> [$x['usage']['over'], $x['usage']['capacity']]);

        $this->view('wishes/plans', [
            'title'    => 'Sparpläne nach Posten',
            'back'     => '/wishes',
            'groups'   => $groups,
            'without'  => $without,
            'accounts' => $a['accounts'],
            'coverage' => $a['coverage'],
            'hidden'   => count(array_filter($a['wishes'], fn ($w) => !$w['visible'])),
            'actions'  => '<a href="' . e(url('/wishes/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Wunsch</a>',
        ]);
    }

    public function show(int $id): void
    {
        $w = (new WishRepository())->find($id, $this->hid) ?? $this->notFound();
        $a = $this->analysis();
        $today = date('Y-m-d');
        $w = $this->decorate($w, $a, $today);
        $this->view('wishes/show', [
            'title'    => $w['title'],
            'back'     => '/wishes',
            'w'        => $w,
            'schedule' => $w['visible'] ? WishService::schedule($w, $today, $w['price'] === null ? 12 : 120) : [],
            'rec'      => $w['plan_recurring_id'] ? ($a['recurring'][(int) $w['plan_recurring_id']] ?? null) : null,
            'usage'    => $w['plan_recurring_id'] ? ($a['usage'][(int) $w['plan_recurring_id']] ?? null) : null,
            'account'  => $w['plan_account_id'] ? ($a['accounts'][(int) $w['plan_account_id']] ?? null) : null,
            'actions'  => '<a href="' . e(url("/wishes/$id/edit")) . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Bearbeiten</a>',
        ]);
    }

    public function create(): void
    {
        $w = [
            'id' => null, 'title' => '', 'description' => '', 'price' => '', 'link' => '',
            'occasion' => $this->request->str('occasion'), 'for_user_id' => $this->request->int('for_user_id'), 'wanted_by' => '',
            'image_path' => null, 'status' => 'open',
            'plan_amount' => '', 'plan_start' => date('Y-m-d'), 'plan_saved' => '', 'plan_recurring_id' => $this->request->int('recurring_id'), 'plan_account_id' => null,
        ];
        $w['has_plan'] = (bool) $w['plan_recurring_id'];
        $this->renderForm($w, 'Neuer Wunsch');
    }

    public function store(): void
    {
        [$data, $error] = $this->validated(null);
        if ($error) {
            $this->renderForm($this->formFromInput(null), 'Neuer Wunsch', $error);
            return;
        }
        $repo = new WishRepository();
        $data['created_by'] = Auth::id();
        $id = $repo->create($this->hid, $data);
        $imgError = $this->handleImage($id, null);
        $this->redirect("/wishes/$id", $this->savedMessage($id, $imgError), $imgError || $this->isOver($id) ? 'warning' : 'success');
    }

    public function edit(int $id): void
    {
        $w = (new WishRepository())->find($id, $this->hid) ?? $this->notFound();
        $w['has_plan'] = WishService::hasPlan($w) || $this->request->bool('plan');
        if ($w['has_plan']) {
            // Sparplan auf heute umstellen: aktueller Stand + nächste Rate (Rechnung bleibt dabei gleich)
            $p = WishService::plan($w, date('Y-m-d'));
            $w['plan_saved'] = money_input($p['saved'] / 100);
            $w['plan_start'] = $p['next_rate'] ?? date('Y-m-d');
        }
        $w['price'] = $w['price'] !== null ? money_input($w['price']) : '';
        $w['plan_amount'] = $w['plan_amount'] !== null ? money_input($w['plan_amount']) : '';
        $this->renderForm($w, 'Wunsch bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new WishRepository();
        $old = $repo->find($id, $this->hid) ?? $this->notFound();
        [$data, $error] = $this->validated($old);
        if ($error) {
            $this->renderForm($this->formFromInput($old), 'Wunsch bearbeiten', $error);
            return;
        }
        $repo->update($id, $this->hid, $data);
        $imgError = $this->handleImage($id, $old);
        $this->redirect("/wishes/$id", $this->savedMessage($id, $imgError), $imgError || $this->isOver($id) ? 'warning' : 'success');
    }

    public function delete(int $id): void
    {
        $repo = new WishRepository();
        $w = $repo->find($id, $this->hid) ?? $this->notFound();
        $this->removeImage($w);
        $repo->delete($id, $this->hid);
        $this->redirect('/wishes', 'Wunsch „' . $w['title'] . '“ gelöscht.');
    }

    /** Erfüllt (gekauft/geschenkt) bzw. wieder offen */
    public function status(int $id): void
    {
        $repo = new WishRepository();
        $w = $repo->find($id, $this->hid) ?? $this->notFound();
        if ($this->request->str('status') === 'open') {
            $repo->update($id, $this->hid, ['status' => 'open', 'fulfilled_at' => null]);
            $this->redirect("/wishes/$id", 'Wunsch ist wieder offen.');
        }
        $repo->update($id, $this->hid, ['status' => 'fulfilled', 'fulfilled_at' => date('Y-m-d')]);
        $this->redirect("/wishes/$id", 'Wunsch als erfüllt markiert.'
            . (WishService::hasPlan($w) ? ' Der Sparplan läuft nicht mehr und belastet seinen Posten nicht weiter.' : ''));
    }

    public function image(int $id): void
    {
        $w = (new WishRepository())->find($id, $this->hid) ?? $this->notFound();
        $abs = $w['image_path'] ? Config::get('paths.uploads') . '/' . $w['image_path'] : '';
        if (!$abs || !is_file($abs)) {
            $this->notFound();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($abs);
        header('Content-Type: ' . (isset(self::MIMES[$mime]) ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }

    // ---------------------------------------------------------------------------------------------------------

    /**
     * Gemeinsame Auswertung aller laufenden Sparpläne: sichtbare Posten, Auslastung je Posten,
     * Kontostände und Deckung je Wunsch.
     * @return array{recurring:array<int,array>, wishes:array<int,array>, usage:array<int,array>, accounts:array<int,array>, coverage:array<int,array>}
     */
    private function analysis(): array
    {
        $today = date('Y-m-d');
        $visible = Auth::accountIds('view');
        $recurring = [];
        foreach ((new RecurringRepository())->all($this->hid, $visible) as $r) {
            $recurring[(int) $r['id']] = $r;
        }
        $wishes = [];
        $rates = [];
        foreach ((new WishRepository())->withPlans($this->hid) as $w) {
            $w['plan'] = WishService::plan($w, $today);
            $w['visible'] = !$w['plan_account_id'] || in_array((int) $w['plan_account_id'], $visible, true);
            $wishes[(int) $w['id']] = $w;
            if ($w['plan_recurring_id'] && $w['plan']['running']) {
                $rates[(int) $w['plan_recurring_id']][] = $w['plan']['rate'];
            }
        }
        $usage = [];
        foreach ($rates as $rid => $list) {
            if (isset($recurring[$rid])) {
                $usage[$rid] = WishService::usage($recurring[$rid], $list, $today);
            }
        }

        // Deckung je Konto: dringendste Wünsche zuerst aus dem Kontostand bedienen
        $byAccount = [];
        foreach ($wishes as $w) {
            if ($w['visible'] && $w['plan_account_id']) {
                $byAccount[(int) $w['plan_account_id']][WishService::urgency($w, $w['plan'])] = $w;
            }
        }
        $accounts = [];
        foreach ((new AccountRepository())->withBalances($this->hid, array_keys($byAccount), true) as $acc) {
            $accounts[(int) $acc['id']] = $acc + ['balance_cents' => Money::toCents($acc['balance'])];
        }
        $coverage = [];
        foreach ($byAccount as $aid => $ws) {
            ksort($ws);
            $saved = [];
            foreach ($ws as $w) {
                $saved[(int) $w['id']] = $w['plan']['saved'];
            }
            $alloc = WishService::allocate($saved, $accounts[$aid]['balance_cents'] ?? 0);
            $coverage += $alloc;
            if (isset($accounts[$aid])) {
                $accounts[$aid]['saved'] = array_sum($saved);
                $accounts[$aid]['missing'] = array_sum(array_column($alloc, 'missing'));
                $accounts[$aid]['wish_count'] = count($saved);
            }
        }
        return compact('recurring', 'wishes', 'usage', 'accounts', 'coverage');
    }

    /** Wunsch um Plan, Sichtbarkeit, Deckung und Posten-Warnung ergänzen */
    private function decorate(array $w, array $a, string $today): array
    {
        $w['plan'] = WishService::plan($w, $today);
        $w['visible'] = !$w['plan_account_id'] || Auth::can((int) $w['plan_account_id'], 'view');
        $w['coverage'] = $a['coverage'][(int) $w['id']] ?? null;
        $rid = (int) $w['plan_recurring_id'];
        $w['posten_over'] = $rid && $w['plan']['running'] && ($a['usage'][$rid]['over'] ?? false);
        $w['posten_name'] = $rid && isset($a['recurring'][$rid]) ? self::recurringName($a['recurring'][$rid]) : null;
        return $w;
    }

    public static function recurringName(array $r): string
    {
        return $r['payee'] ?: ($r['purpose'] ?: ($r['to_account_name'] ?? null ? 'Umbuchung auf ' . $r['to_account_name']
            : ($r['category_name'] ?? 'Posten #' . $r['id'])));
    }

    /** @return array{0:array, 1:?string} */
    private function validated(?array $old): array
    {
        $r = $this->request;
        $price = trim($r->str('price')) === '' ? null : Money::parse($r->str('price'));
        $link = trim($r->str('link'));
        $forUser = $r->int('for_user_id');
        if ($forUser && !(new UserRepository())->findInHousehold($forUser, $this->hid)) {
            $forUser = null;
        }
        $data = [
            'title'       => mb_substr(trim($r->str('title')), 0, 190),
            'description' => mb_substr(trim($r->str('description')), 0, 5000) ?: null,
            'price'       => $price !== null ? Money::toDecimal(abs($price)) : null,
            'link'        => $link !== '' ? mb_substr($link, 0, 500) : null,
            'occasion'    => mb_substr(trim($r->str('occasion')), 0, 100) ?: null,
            'for_user_id' => $forUser ?: null,
            'wanted_by'   => valid_date($r->str('wanted_by')),
        ];
        $error = null;
        if ($data['title'] === '') {
            $error = 'Bitte eine Überschrift eingeben.';
        } elseif (trim($r->str('price')) !== '' && $price === null) {
            $error = 'Der Preis ist keine gültige Zahl.';
        } elseif ($data['link'] && !preg_match('#^https?://#i', $data['link'])) {
            $error = 'Der Link muss mit http:// oder https:// beginnen.';
        }

        // Sparplan nur, wenn der Benutzer das bisherige Konto sehen darf – sonst unverändert lassen
        $planAllowed = !$old || !$old['plan_account_id'] || Auth::can((int) $old['plan_account_id'], 'view');
        if (!$planAllowed || $error) {
            return [$data, $error];
        }
        if (!$r->bool('has_plan')) {
            return [$data + ['plan_amount' => null, 'plan_start' => null, 'plan_saved' => '0.00', 'plan_recurring_id' => null, 'plan_account_id' => null], null];
        }
        $rate = Money::parse($r->str('plan_amount'));
        $saved = trim($r->str('plan_saved')) === '' ? 0 : Money::parse($r->str('plan_saved'));
        $recId = $r->int('plan_recurring_id');
        $rec = $recId ? (new RecurringRepository())->find($recId, $this->hid) : null;
        if ($rec && !Auth::can((int) $rec['account_id'], 'view')) {
            $rec = null;
        }
        $accountId = $r->int('plan_account_id');
        if ($rec) {
            $full = $this->recurringRow((int) $rec['id']);
            $accountId = WishService::incomeAccountId($full ?? $rec) ?? $accountId;
        }
        $plan = [
            'plan_amount'       => $rate !== null ? Money::toDecimal(abs($rate)) : null,
            'plan_start'        => valid_date($r->str('plan_start'), date('Y-m-d')),
            'plan_saved'        => Money::toDecimal(abs((int) $saved)),
            'plan_recurring_id' => $rec ? (int) $rec['id'] : null,
            'plan_account_id'   => $accountId ?: null,
        ];
        if (!$rate) {
            $error = 'Bitte eine monatliche Sparrate eingeben (oder den Sparplan ausschalten).';
        } elseif ($saved === null) {
            $error = '„Bereits angespart“ ist keine gültige Zahl.';
        } elseif (!$plan['plan_account_id'] || !Auth::can($plan['plan_account_id'], 'view')) {
            $error = 'Bitte das Konto wählen, auf dem das Ersparte liegt.';
        }
        return [$data + $plan, $error];
    }

    /** Posten inkl. Gegeneintrag-Konto (wie in der Liste) */
    private function recurringRow(int $id): ?array
    {
        foreach ((new RecurringRepository())->all($this->hid, Auth::accountIds('view')) as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    private function formFromInput(?array $old): array
    {
        $r = $this->request;
        return [
            'id' => $old['id'] ?? null, 'image_path' => $old['image_path'] ?? null, 'status' => $old['status'] ?? 'open',
            'title' => $r->str('title'), 'description' => $r->str('description'), 'price' => $r->str('price'),
            'link' => $r->str('link'), 'occasion' => $r->str('occasion'), 'for_user_id' => $r->int('for_user_id'),
            'wanted_by' => $r->str('wanted_by'), 'has_plan' => $r->bool('has_plan'),
            'plan_amount' => $r->str('plan_amount'), 'plan_start' => $r->str('plan_start'), 'plan_saved' => $r->str('plan_saved'),
            'plan_recurring_id' => $r->int('plan_recurring_id'), 'plan_account_id' => $r->int('plan_account_id') ?? ($old['plan_account_id'] ?? null),
        ];
    }

    private function renderForm(array $w, string $title, ?string $error = null): void
    {
        $visible = Auth::accountIds('view');
        $today = date('Y-m-d');
        $planAllowed = !$w['id'] || empty($w['plan_account_id']) || in_array((int) $w['plan_account_id'], $visible, true);
        // Posten mit Monatsbetrag, Einnahme-Konto und noch freiem Betrag (ohne diesen Wunsch)
        $a = $this->analysis();
        $posten = [];
        foreach ($a['recurring'] as $rid => $rec) {
            $rates = [];
            foreach ($a['wishes'] as $other) {
                if ((int) $other['plan_recurring_id'] === $rid && $other['plan']['running'] && (int) $other['id'] !== (int) $w['id']) {
                    $rates[] = $other['plan']['rate'];
                }
            }
            $u = WishService::usage($rec, $rates, $today);
            $posten[] = [
                'id'       => $rid,
                'name'     => self::recurringName($rec),
                'account'  => $rec['account_name'] . ($rec['to_account_name'] ? ' → ' . $rec['to_account_name'] : ($rec['counterpart_account_name'] ? ' → ' . $rec['counterpart_account_name'] : '')),
                'kind'     => $rec['to_account_id'] ? 'transfer' : (Money::toCents($rec['amount']) > 0 ? 'income' : 'expense'),
                'monthly'  => WishService::recurringMonthly($rec),
                'free'     => $u['free'],
                'runs'     => WishService::recurringRuns($rec, $today),
                'income_account_id' => WishService::incomeAccountId($rec),
            ];
        }
        usort($posten, fn ($x, $y) => [$x['kind'] === 'expense', $x['name']] <=> [$y['kind'] === 'expense', $y['name']]);

        $this->view('wishes/form', [
            'title'       => $title,
            'back'        => $w['id'] ? "/wishes/{$w['id']}" : '/wishes',
            'w'           => $w,
            'error'       => $error,
            'planAllowed' => $planAllowed && $visible,
            'posten'      => $posten,
            'accounts'    => (new AccountRepository())->options($this->hid, $visible),
            'users'       => (new UserRepository())->all($this->hid),
            'scripts'     => ['js/wishes.js'],
            'occasions'   => array_values(array_unique([...(new WishRepository())->occasions($this->hid), 'Weihnachten', 'Geburtstag', 'Anschaffung'])),
        ]);
    }

    private function isOver(int $id): bool
    {
        $w = (new WishRepository())->find($id, $this->hid);
        $rid = (int) ($w['plan_recurring_id'] ?? 0);
        return $rid && ($this->analysis()['usage'][$rid]['over'] ?? false);
    }

    private function savedMessage(int $id, ?string $imgError): string
    {
        $msg = 'Wunsch gespeichert.';
        if ($this->isOver($id)) {
            $msg .= ' Achtung: Die Sparpläne übersteigen jetzt den zugeordneten Posten – siehe „Sparpläne nach Posten“.';
        }
        return $imgError ? "$msg $imgError" : $msg;
    }

    // --- Bild -------------------------------------------------------------------------------------------------

    /** Neues Bild speichern bzw. entfernen; liefert eine Fehlermeldung oder null */
    private function handleImage(int $id, ?array $old): ?string
    {
        $file = $this->request->file('image');
        if (!$file) {
            if ($old && $this->request->bool('remove_image')) {
                $this->removeImage($old);
                (new WishRepository())->update($id, $this->hid, ['image_path' => null]);
            }
            $err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
            return $err === UPLOAD_ERR_NO_FILE ? null : 'Das Bild konnte nicht hochgeladen werden (zu groß?).';
        }
        if ($file['size'] > Config::get('upload_max_bytes')) {
            return 'Das Bild ist zu groß.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIMES[$mime])) {
            return 'Als Bild sind nur JPG, PNG oder WebP möglich.';
        }
        $dir = Config::get('paths.uploads') . '/wishes';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Das Bild konnte nicht gespeichert werden.';
        }
        if ($old) {
            $this->removeImage($old);
        }
        $rel = sprintf('wishes/%d-%s.%s', $id, bin2hex(random_bytes(4)), self::MIMES[$mime]);
        $abs = Config::get('paths.uploads') . '/' . $rel;
        if (!self::storeScaled($file['tmp_name'], $abs, $mime) && !move_uploaded_file($file['tmp_name'], $abs)) {
            return 'Das Bild konnte nicht gespeichert werden.';
        }
        (new WishRepository())->update($id, $this->hid, ['image_path' => $rel]);
        return null;
    }

    /** Große Fotos verkleinern (und nach EXIF drehen), falls GD vorhanden ist */
    private static function storeScaled(string $src, string $dest, string $mime): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (!$img) {
            return false;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($angle && ($rot = imagerotate($img, $angle, 0))) {
                $img = $rot;
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_IMAGE_PX / max($w, $h));
        if ($scale < 1) {
            $img = imagescale($img, (int) round($w * $scale), (int) round($h * $scale)) ?: $img;
        }
        if ($mime === 'image/png') {
            imagesavealpha($img, true);
            return imagepng($img, $dest, 6);
        }
        if ($mime === 'image/webp' && function_exists('imagewebp')) {
            return imagewebp($img, $dest, 82);
        }
        return imagejpeg($img, $dest, 85);
    }

    private function removeImage(array $w): void
    {
        if (!empty($w['image_path']) && str_starts_with($w['image_path'], 'wishes/')) {
            @unlink(Config::get('paths.uploads') . '/' . $w['image_path']);
        }
    }
}
