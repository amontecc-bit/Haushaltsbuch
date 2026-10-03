<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function index(): void
    {
        [$period, $from, $to] = $this->period();
        $accountId = $this->request->int('account_id');
        $userId = $this->request->int('user_id');
        $parent = $this->request->int('parent');
        $split = $this->request->query('split', '1') !== '0';
        $ids = Auth::accountIds('view');
        $svc = new ReportService($this->hid, $ids);

        // Monatsverlauf: mindestens 12 Monate zeigen
        $trendFrom = min($from, date('Y-m-01', strtotime($to . ' -11 months')));
        $parentCat = $parent ? (new CategoryRepository())->find($parent, $this->hid) : null;

        $this->view('reports/index', [
            'title'      => 'Auswertungen',
            'period'     => $period,
            'from'       => $from,
            'to'         => $to,
            'accountId'  => $accountId,
            'userId'     => $userId,
            'split'      => $split,
            'parentCat'  => $parentCat,
            'totals'     => $svc->totals($from, $to, $accountId, $userId),
            'expenses'   => $svc->byCategory($from, $to, 'expense', $parentCat ? $parent : null, $split, $accountId, $userId),
            'incomes'    => $svc->byCategory($from, $to, 'income', null, false, $accountId, $userId),
            'monthly'    => $svc->monthly($trendFrom, $to, $accountId, $userId),
            'stacked'    => $svc->monthlyByCategory($trendFrom, $to, $accountId, $userId),
            'payees'     => $svc->topPayees($from, $to, $accountId, $userId),
            'accounts'   => (new AccountRepository())->options($this->hid, $ids),
            'users'      => (new UserRepository())->all($this->hid),
            'scripts'    => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }

    public function items(): void
    {
        [$period, $from, $to] = $this->period('3m');
        $q = mb_substr($this->request->str('q'), 0, 100);
        $sort = in_array($this->request->str('sort'), ['total', 'count', 'name', 'price'], true) ? $this->request->str('sort') : 'total';
        $svc = new ReportService($this->hid, Auth::accountIds('view'));
        $categories = (new CategoryRepository())->all($this->hid, 'expense');

        // Kategorie-Filter: leer = alle, 0 = ohne Kategorie, sonst eine (Unter-)Kategorie des Haushalts
        $categoryId = $this->request->int('category_id');
        $byId = array_column($categories, null, 'id');
        if ($categoryId && !isset($byId[$categoryId])) {
            $categoryId = null;
        }
        $current = $categoryId ? $byId[$categoryId] : null;
        $parentCat = $current && $current['parent_id'] ? ($byId[(int) $current['parent_id']] ?? null) : null;
        $children = [];
        if ($current && !$current['parent_id']) {
            $sums = $svc->itemsBySubcategory($from, $to, (int) $current['id'], $q);
            foreach ($categories as $c) {
                if ((int) $c['parent_id'] === (int) $current['id']) {
                    $children[] = $c + ['total' => $sums[(int) $c['id']] ?? 0.0];
                }
            }
        }

        $this->view('reports/items', [
            'title'      => 'Einzelposten',
            'back'       => '/reports',
            'period'     => $period,
            'from'       => $from,
            'to'         => $to,
            'categoryId' => $categoryId,
            'current'    => $current,
            'parentCat'  => $parentCat,
            'children'   => $children,
            'q'          => $q,
            'sort'       => $sort,
            'rows'       => $svc->items($from, $to, $categoryId, $q, $sort),
            'summary'    => $svc->itemsSummary($from, $to, $categoryId, $q),
            'byCategory' => $svc->itemsByCategory($from, $to),
            'categories' => $categories,
            'scripts'    => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }

    /** Kategorie eines Artikels in allen sichtbaren Einkäufen korrigieren und als Standard lernen */
    public function recategorize(): void
    {
        $productId = $this->request->int('product_id') ?: null;
        $name = mb_substr($this->request->str('name'), 0, 190);
        $categoryId = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        $products = new ProductRepository();
        if ($productId && !$products->find($productId, $this->hid)) {
            $this->back('/reports/items', 'Artikel nicht gefunden.');
        }
        if (!$productId && $name === '') {
            $this->back('/reports/items', 'Artikel fehlt.');
        }
        $repo = new PurchaseRepository();
        $n = $repo->transaction(function () use ($repo, $products, $productId, $name, $categoryId) {
            if ($productId) {
                $products->setDefaultCategory($productId, $this->hid, $categoryId);
            }
            return $repo->recategorizeItems($this->hid, Auth::accountIds('view'), $productId, $name, $categoryId);
        });
        $this->back('/reports/items', $n === 1 ? 'Kategorie für 1 Posten geändert.' : "Kategorie für $n Posten geändert.", 'success');
    }

    public function product(): void
    {
        $product = (new ProductRepository())->find((int) $this->request->int('id'), $this->hid) ?? $this->notFound();
        $history = (new ReportService($this->hid, Auth::accountIds('view')))->productHistory((int) $product['id']);
        $this->view('reports/product', [
            'title' => $product['name'], 'back' => '/reports/items', 'product' => $product, 'history' => $history,
            'scripts' => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }

    /** CSV-Export aller Buchungen im Zeitraum */
    public function export(): void
    {
        [, $from, $to] = $this->period();
        $filters = ['from' => $from, 'to' => $to, 'account_id' => $this->request->int('account_id')];
        $rows = (new TransactionRepository())->search($this->hid, Auth::accountIds('view'), $filters, 100000);
        Response::csv("buchungen_{$from}_{$to}.csv",
            ['Datum', 'Konto', 'Betrag', 'Empfänger/Auftraggeber', 'Verwendungszweck', 'Hauptkategorie', 'Kategorie', 'Notiz', 'Umbuchung'],
            (function () use ($rows) {
                foreach (array_reverse($rows) as $t) {
                    yield [
                        date_de($t['booking_date']), $t['account_name'], number_format((float) $t['amount'], 2, ',', ''),
                        $t['payee'], $t['purpose'], $t['category_parent'] ?? $t['category_name'], $t['category_parent'] ? $t['category_name'] : '',
                        $t['note'], $t['transfer_group'] ? 'ja' : '',
                    ];
                }
            })()
        );
    }
}
