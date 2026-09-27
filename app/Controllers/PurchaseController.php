<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TransactionRepository;
use App\Services\AiReceiptRecognizer;
use App\Services\CategorizationService;
use App\Services\ReceiptTextParser;

final class PurchaseController extends Controller
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function index(): void
    {
        $r = $this->request;
        $filters = ['q' => mb_substr($r->str('q'), 0, 100), 'from' => valid_date($r->str('from')), 'to' => valid_date($r->str('to'))];
        $page = max(1, (int) $r->int('page', 1));
        $repo = new PurchaseRepository();
        $ids = Auth::accountIds('view');
        $rows = $repo->search($this->hid, $ids, $filters, 31, ($page - 1) * 30);
        $actions = '<a href="' . e(url('/purchases/new', ['mode' => 'photo'])) . '" class="btn btn-primary"><i class="bi bi-camera"></i> Bon fotografieren</a>'
            . '<a href="' . e(url('/purchases/new', ['mode' => 'manual'])) . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Von Hand</a>'
            . '<a href="' . e(url('/purchases/new', ['mode' => 'pdf'])) . '" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>';
        $this->view('purchases/index', [
            'title'   => 'Einkäufe',
            'actions' => $actions,
            'rows'    => array_slice($rows, 0, 30),
            'hasMore' => count($rows) > 30,
            'page'    => $page,
            'filters' => $filters,
            'month'   => $repo->monthTotal($this->hid, $ids, date('Y-m-01'), date('Y-m-t')),
        ]);
    }

    public function create(): void
    {
        $mode = in_array($this->request->str('mode'), ['manual', 'photo', 'pdf'], true) ? $this->request->str('mode') : 'photo';
        // Vorgabe: vom Konto buchen – zuletzt für Einkäufe benutztes Konto, sonst erstes Girokonto
        $bookable = (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
        $last = (new PurchaseRepository())->lastAccountId($this->hid, (int) Auth::id());
        $giro = array_values(array_filter($bookable, fn ($a) => $a['type'] === 'giro'));
        $default = in_array($last, array_map('intval', array_column($bookable, 'id')), true) ? $last : ($giro[0]['id'] ?? $bookable[0]['id'] ?? null);
        $purchase = ['id' => null, 'purchase_date' => date('Y-m-d'), 'store' => '', 'account_id' => $default, 'transaction_id' => null,
            'note' => '', 'source' => $mode === 'manual' ? 'manual' : $mode, 'total' => 0];
        $this->renderForm($purchase, [], $mode, 'Einkauf erfassen');
    }

    public function store(): void
    {
        $repo = new PurchaseRepository();
        [$data, $items, $error] = $this->validated();
        if ($error) {
            $this->json(['error' => $error], 422);
        }
        $id = $repo->transaction(function () use ($repo, $data, $items) {
            $id = $repo->create($this->hid, $data + ['created_by' => Auth::id()]);
            $repo->replaceItems($id, $this->learnProducts($items));
            $this->handleLink($id, $data, $items, null);
            $this->attachFiles($id);
            return $id;
        });
        $this->json(['ok' => true, 'redirect' => url("/purchases/$id"), 'message' => 'Einkauf gespeichert.']);
    }

    public function show(int $id): void
    {
        $repo = new PurchaseRepository();
        $p = $repo->find($id, $this->hid, Auth::accountIds('view')) ?? $this->notFound();
        $items = $repo->items($id);
        $byCat = [];
        foreach ($items as $i) {
            $key = $i['category_name'] ? (($i['category_parent'] ? $i['category_parent'] . ' › ' : '') . $i['category_name']) : 'Ohne Kategorie';
            $byCat[$key] ??= ['total' => 0, 'color' => $i['category_color'] ?? '#adb5bd'];
            $byCat[$key]['total'] += (float) $i['total_price'];
        }
        uasort($byCat, fn ($a, $b) => $b['total'] <=> $a['total']);
        $actions = '<a class="btn btn-outline-primary" href="' . e(url("/purchases/$id/edit")) . '"><i class="bi bi-pencil"></i> Bearbeiten</a>';
        $this->view('purchases/show', [
            'title' => ($p['store'] ?: 'Einkauf') . ' · ' . date_de($p['purchase_date']), 'back' => '/purchases',
            'p' => $p, 'items' => $items, 'byCat' => $byCat, 'files' => $this->files($p), 'actions' => $actions,
        ]);
    }

    public function edit(int $id): void
    {
        $repo = new PurchaseRepository();
        $p = $repo->find($id, $this->hid, Auth::accountIds('view')) ?? $this->notFound();
        $this->renderForm($p, $repo->items($id), 'manual', 'Einkauf bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new PurchaseRepository();
        $old = $repo->find($id, $this->hid, Auth::accountIds('view')) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        [$data, $items, $error] = $this->validated();
        if ($error) {
            $this->json(['error' => $error], 422);
        }
        unset($data['source'], $data['raw_text']);
        $repo->transaction(function () use ($repo, $id, $data, $items, $old) {
            $repo->update($id, $this->hid, $data);
            $repo->replaceItems($id, $this->learnProducts($items));
            $this->handleLink($id, $data, $items, $old);
        });
        $this->json(['ok' => true, 'redirect' => url("/purchases/$id"), 'message' => 'Einkauf gespeichert.']);
    }

    public function delete(int $id): void
    {
        $repo = new PurchaseRepository();
        $p = $repo->find($id, $this->hid, Auth::accountIds('view')) ?? $this->notFound();
        if ($p['account_id'] && !Auth::can((int) $p['account_id'], 'book')) {
            $this->redirect("/purchases/$id", 'Keine Berechtigung.', 'danger');
        }
        if ($this->request->bool('delete_transaction') && $p['transaction_id']) {
            $tx = (new TransactionRepository())->find((int) $p['transaction_id'], $this->hid);
            if ($tx && Auth::can((int) $tx['account_id'], 'book')) {
                (new TransactionRepository())->delete((int) $tx['id'], $this->hid);
            }
        }
        foreach ($this->files($p) as $f) {
            @unlink($f['abs']);
        }
        @rmdir(Config::get('paths.uploads') . "/purchases/$id");
        $repo->delete($id, $this->hid);
        $this->redirect('/purchases', 'Einkauf gelöscht.');
    }

    /** Beleg (Foto/PDF) ausliefern */
    public function file(int $id): void
    {
        $p = (new PurchaseRepository())->find($id, $this->hid, Auth::accountIds('view')) ?? $this->notFound();
        $files = $this->files($p);
        $f = $files[(int) $this->request->int('n', 0)] ?? $this->notFound();
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($f['abs']));
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline; filename="beleg-' . $id . '-' . basename($f['abs']) . '"');
        readfile($f['abs']);
        exit;
    }

    /**
     * Beleg erkennen.
     * Eingaben: mode (local|ai), text (OCR-Text aus dem Browser), files[] (Bilder/PDF), token (bereits hochgeladene Dateien)
     */
    public function recognize(): void
    {
        $mode = $this->request->str('mode') === 'ai' ? 'ai' : 'local';
        $token = preg_match('/^[a-f0-9]{24}$/', $this->request->str('token')) ? $this->request->str('token') : null;
        try {
            $token = $this->storeUploads($token);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $files = $token ? $this->tmpFiles($token) : [];
        $text = $this->request->str('text');
        $categories = (new CategoryRepository())->all($this->hid);
        $source = null;

        try {
            if ($mode === 'ai') {
                [$key, $model] = $this->aiConfig();
                if ($key === '') {
                    $this->json(['error' => 'Für die KI-Erkennung ist kein API-Schlüssel hinterlegt (Einstellungen).'], 422);
                }
                if (!$files) {
                    $this->json(['error' => 'Bitte zuerst ein Foto oder PDF wählen.'], 422);
                }
                $result = (new AiReceiptRecognizer($key, $model))->recognize(array_slice($files, 0, 5), $categories);
                $source = 'ai';
            } elseif ($text !== '') {
                $result = ReceiptTextParser::parse($text, true); // OCR: Platzhalter für unlesbare Zeilen
                $source = 'ocr';
            } else {
                $pdf = array_values(array_filter($files, fn ($f) => $f['mime'] === 'application/pdf'))[0] ?? null;
                if (!$pdf) {
                    $this->json(['error' => 'Kein Text erkannt.'], 422);
                }
                $text = $this->pdfText($pdf['path']);
                if (mb_strlen(trim($text)) < 20) {
                    // gescanntes PDF ohne Textebene → Browser rendert die Seiten und macht OCR
                    $this->json(['ok' => true, 'needs_ocr' => true, 'token' => $token]);
                }
                $result = ReceiptTextParser::parse($text);
                $source = 'pdf';
            }
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage(), 'token' => $token], 502);
        }

        $svc = new CategorizationService($this->hid);
        foreach ($result['items'] as &$item) {
            $item['suggested'] = false;
            if (empty($item['category_id'])) {
                $s = $svc->suggestForItem($item['name']);
                $item['category_id'] = $s['category_id'];
                $item['suggested'] = $s['category_id'] !== null;
            } else {
                $item['suggested'] = true;
            }
        }
        unset($item);

        $this->json([
            'ok' => true, 'token' => $token, 'source' => $source, 'raw_text' => mb_substr($text, 0, 20000),
            'store' => $result['store'], 'date' => $result['date'], 'total' => $result['total'], 'items' => $result['items'],
        ]);
    }

    /** AJAX: Kategorievorschläge für Postennamen */
    public function suggest(): void
    {
        $svc = new CategorizationService($this->hid);
        $out = [];
        foreach (array_slice($this->request->arr('names'), 0, 200) as $name) {
            $s = $svc->suggestForItem((string) $name);
            $out[] = $s['category_id'];
        }
        $this->json(['categories' => $out]);
    }

    /** AJAX: passende Buchungen zum Verknüpfen */
    public function candidates(): void
    {
        $date = valid_date($this->request->str('date'), date('Y-m-d'));
        $total = $this->request->str('total');
        $amount = $total !== '' ? Money::toDecimal(abs((int) Money::parse($total))) : null;
        $rows = (new TransactionRepository())->candidatesForPurchase($this->hid, Auth::accountIds('view'), $date, $amount);
        $current = $this->request->int('current');
        $store = $this->request->str('store');
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['id'] === $current) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'], 'label' => date_de($r['booking_date']) . ' · ' . ($r['payee'] ?: 'Buchung') . ' · ' . $r['account_name'] . ' · ' . money($r['amount']),
                'exact' => $amount !== null && Money::toCents($r['amount']) === -Money::toCents($amount),
                'same_payee' => CategorizationService::samePayee($store, (string) $r['payee']),
            ];
        }
        // Exakter Betrag beim selben Anbieter zuerst
        usort($out, fn ($a, $b) => [$b['exact'] && $b['same_payee'], $b['exact']] <=> [$a['exact'] && $a['same_payee'], $a['exact']]);
        $this->json(['candidates' => $out]);
    }

    // ---------------------------------------------------------------------

    /** @return array{0:array, 1:array, 2:?string} */
    private function validated(): array
    {
        $r = $this->request;
        $catRepo = new CategoryRepository();
        $items = [];
        $sum = 0;
        foreach ($r->arr('items') as $row) {
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 190);
            if ($name === '') {
                continue;
            }
            $qty = (float) str_replace(',', '.', (string) ($row['quantity'] ?? 1)) ?: 1;
            $total = Money::parse((string) ($row['total_price'] ?? '')) ?? 0;
            $unitPrice = Money::parse((string) ($row['unit_price'] ?? ''));
            $unitPrice ??= (int) round($total / $qty);
            $unit = in_array($row['unit'] ?? '', ['kg', 'g', 'l'], true) ? $row['unit'] : null;
            $items[] = [
                'name'        => $name,
                'quantity'    => round($qty, 3),
                'unit'        => $unit,
                'unit_price'  => Money::toDecimal($unitPrice),
                'total_price' => Money::toDecimal($total),
                'category_id' => $catRepo->validId(isset($row['category_id']) && $row['category_id'] !== '' ? (int) $row['category_id'] : null, $this->hid),
            ];
            $sum += $total;
        }
        $accountId = $r->int('account_id') ?: null;
        $totalInput = Money::parse($r->str('total'));
        $data = [
            'purchase_date' => valid_date($r->str('purchase_date'), date('Y-m-d')),
            'store'         => mb_substr($r->str('store'), 0, 150) ?: null,
            'account_id'    => $accountId,
            'total'         => Money::toDecimal($items ? $sum : ($totalInput ?? 0)),
            'note'          => mb_substr($r->str('note'), 0, 255) ?: null,
            'source'        => in_array($r->str('source'), ['manual', 'pdf', 'photo'], true) ? $r->str('source') : 'manual',
            'raw_text'      => mb_substr($r->str('raw_text'), 0, 60000) ?: null,
        ];
        $error = null;
        if (!$items && !$totalInput) {
            $error = 'Bitte mindestens einen Posten oder einen Gesamtbetrag erfassen.';
        } elseif ($accountId && !Auth::can($accountId, 'view')) {
            $error = 'Keine Berechtigung für dieses Konto.';
        } elseif ($r->str('link') === 'new' && (!$accountId || !Auth::can($accountId, 'book'))) {
            $error = 'Zum Anlegen einer Buchung bitte ein Konto wählen, auf das du buchen darfst.';
        }
        return [$data, $items, $error];
    }

    /** Produkte anlegen und gewählte Kategorien lernen */
    private function learnProducts(array $items): array
    {
        $products = new ProductRepository();
        foreach ($items as &$i) {
            $norm = CategorizationService::normalizeProduct($i['name']);
            $i['product_id'] = $norm !== '' ? $products->upsert($this->hid, $i['name'], $norm, $i['category_id']) : null;
        }
        return $items;
    }

    /**
     * Verknüpfung mit einer Buchung:
     *   none     – keine
     *   existing – vorhandene Buchung (z. B. aus CSV-Import)
     *   new      – neue Ausgabe auf dem gewählten Konto (Kategorie = größter Anteil der Posten)
     */
    private function handleLink(int $purchaseId, array $data, array $items, ?array $old): void
    {
        $link = $this->request->str('link');
        $txRepo = new TransactionRepository();
        $repo = new PurchaseRepository();
        $txId = null;

        if ($link === 'existing') {
            $tx = $txRepo->find((int) $this->request->int('transaction_id'), $this->hid);
            if ($tx && Auth::can((int) $tx['account_id'], 'view')) {
                $txId = (int) $tx['id'];
                if (!$data['account_id']) {
                    $repo->update($purchaseId, $this->hid, ['account_id' => (int) $tx['account_id']]);
                }
            }
        } elseif ($link === 'new') {
            $fields = [
                'account_id'   => $data['account_id'],
                'booking_date' => $data['purchase_date'],
                'amount'       => Money::toDecimal(-Money::toCents($data['total'])),
                'payee'        => $data['store'],
                'category_id'  => $this->dominantCategory($items),
            ];
            $existing = $old && $old['transaction_id'] ? $txRepo->find((int) $old['transaction_id'], $this->hid) : null;
            if ($existing && $existing['source'] === 'purchase') {
                $txRepo->update((int) $existing['id'], $this->hid, $fields);
                $txId = (int) $existing['id'];
            } else {
                $txId = $txRepo->create($this->hid, $fields + ['source' => 'purchase', 'created_by' => Auth::id()]);
            }
        } elseif ($link === 'keep' && $old) {
            $txId = $old['transaction_id'] ? (int) $old['transaction_id'] : null;
        }
        $repo->update($purchaseId, $this->hid, ['transaction_id' => $txId]);
    }

    /** Hauptkategorie mit dem höchsten Betragsanteil */
    private function dominantCategory(array $items): ?int
    {
        $parents = [];
        $cats = [];
        foreach ((new CategoryRepository())->all($this->hid) as $c) {
            $cats[(int) $c['id']] = $c['parent_id'] ? (int) $c['parent_id'] : (int) $c['id'];
        }
        foreach ($items as $i) {
            if ($i['category_id']) {
                $p = $cats[$i['category_id']] ?? $i['category_id'];
                $parents[$p] = ($parents[$p] ?? 0) + (float) $i['total_price'];
            }
        }
        if (!$parents) {
            return (new CategorizationService($this->hid))->suggestForTransaction((string) $this->request->str('store'), '')['category_id'];
        }
        arsort($parents);
        return (int) array_key_first($parents);
    }

    // ---- Dateien -----------------------------------------------------------

    /** Hochgeladene Dateien in einen temporären Ordner legen; gibt das Token zurück */
    private function storeUploads(?string $token): ?string
    {
        $files = $_FILES['files'] ?? null;
        if (!$files || !is_array($files['name'])) {
            return $token;
        }
        $token ??= bin2hex(random_bytes(12));
        $dir = Config::get('paths.uploads') . "/tmp/$token";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->cleanupTmp();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $n = count(glob("$dir/*") ?: []);
        foreach ($files['tmp_name'] as $k => $tmp) {
            if (($files['error'][$k] ?? 1) !== UPLOAD_ERR_OK || $n >= 6) {
                continue;
            }
            if ($files['size'][$k] > Config::get('upload_max_bytes')) {
                throw new \RuntimeException('Die Datei ist zu groß (max. 15 MB).');
            }
            $mime = $finfo->file($tmp);
            if (!isset(self::MIMES[$mime])) {
                throw new \RuntimeException('Nur Fotos (JPG, PNG, WebP) oder PDF-Dateien.');
            }
            move_uploaded_file($tmp, sprintf('%s/%d.%s', $dir, $n++, self::MIMES[$mime]));
        }
        return $token;
    }

    /** @return array<int, array{path:string, mime:string}> */
    private function tmpFiles(string $token): array
    {
        $out = [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $files = glob(Config::get('paths.uploads') . "/tmp/$token/*") ?: [];
        natsort($files);
        foreach ($files as $f) {
            $out[] = ['path' => $f, 'mime' => $finfo->file($f)];
        }
        return $out;
    }

    private function attachFiles(int $purchaseId): void
    {
        $token = $this->request->str('token');
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return;
        }
        $src = Config::get('paths.uploads') . "/tmp/$token";
        $files = $this->tmpFiles($token);
        if (!$files) {
            return;
        }
        $dir = Config::get('paths.uploads') . "/purchases/$purchaseId";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $paths = [];
        foreach ($files as $i => $f) {
            $name = $i . '.' . self::MIMES[$f['mime']];
            rename($f['path'], "$dir/$name");
            $paths[] = "purchases/$purchaseId/$name";
        }
        @rmdir($src);
        (new PurchaseRepository())->update($purchaseId, $this->hid, ['file_path' => implode('|', $paths)]);
    }

    /** @return array<int, array{abs:string, mime:string, is_pdf:bool}> */
    private function files(array $p): array
    {
        if (!$p['file_path']) {
            return [];
        }
        $out = [];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach (explode('|', $p['file_path']) as $rel) {
            $abs = Config::get('paths.uploads') . '/' . str_replace('..', '', $rel);
            if (is_file($abs)) {
                $mime = $finfo->file($abs);
                $out[] = ['abs' => $abs, 'mime' => $mime, 'is_pdf' => $mime === 'application/pdf'];
            }
        }
        return $out;
    }

    private function cleanupTmp(): void
    {
        foreach (glob(Config::get('paths.uploads') . '/tmp/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 86400) {
                array_map('unlink', glob("$d/*") ?: []);
                @rmdir($d);
            }
        }
    }

    private function pdfText(string $path): string
    {
        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            return $pdf->getText();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{0:string, 1:string} */
    private function aiConfig(): array
    {
        $s = (new SettingsRepository())->all($this->hid);
        $key = $s['ai_api_key'] !== '' ? $s['ai_api_key'] : (string) Config::get('ai.api_key');
        $model = $s['ai_model'] !== '' ? $s['ai_model'] : (string) Config::get('ai.model');
        return [$key, $model ?: AiReceiptRecognizer::DEFAULT_MODEL];
    }

    private function renderForm(array $purchase, array $items, string $mode, string $title): void
    {
        $s = (new SettingsRepository())->all($this->hid);
        [$key] = $this->aiConfig();
        $this->view('purchases/form', [
            'title'      => $title,
            'back'       => $purchase['id'] ? "/purchases/{$purchase['id']}" : '/purchases',
            'purchase'   => $purchase,
            'items'      => $items,
            'mode'       => $mode,
            'ocrMode'    => $s['ocr_mode'],
            'aiAvailable' => $key !== '',
            'accounts'   => (new AccountRepository())->options($this->hid, Auth::accountIds('view')),
            'bookable'   => Auth::accountIds('book'),
            'categories' => (new CategoryRepository())->all($this->hid, 'expense'),
            'products'   => (new ProductRepository())->names($this->hid),
            'scripts'    => ['js/purchase.js'],
        ]);
    }
}
