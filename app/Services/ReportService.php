<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Auswertungen über Buchungen und Einkaufsposten. Umbuchungen zählen nur bei Filter auf ein einzelnes Konto als
 * Einnahme/Ausgabe (dort fließt Geld tatsächlich zu oder ab); über alle Konten heben sie sich auf.
 */
final class ReportService
{
    /** Pseudo-Kategorie-ID für Umbuchungen in den Kategorie-Auswertungen */
    public const TRANSFER_ID = -2;

    private PDO $db;

    public function __construct(private readonly int $householdId, private readonly array $accountIds)
    {
        $this->db = Database::connection();
    }

    private function in(): string
    {
        return $this->accountIds ? implode(',', array_fill(0, count($this->accountIds), '?')) : 'NULL';
    }

    private function rows(string $sql, array $params): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Gemeinsamer Filter für Buchungen (Alias t): Haushalt, sichtbare Konten, optional ein Konto und eine Person,
     * Zeitraum; Umbuchungen nur bei gewähltem Konto (sonst ausgeschlossen).
     * @return array{0:string, 1:array}
     */
    private function scope(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        $accIds = $accountId ? array_values(array_intersect($this->accountIds, [$accountId])) : $this->accountIds;
        $in = $accIds ? implode(',', array_fill(0, count($accIds), '?')) : 'NULL';
        $sql = "t.household_id = ? AND t.account_id IN ($in) AND t.booking_date BETWEEN ? AND ?";
        if (!$accountId) {
            $sql .= ' AND t.transfer_group IS NULL';
        }
        $params = [$this->householdId, ...$accIds, $from, $to];
        if ($userId) {
            $sql .= ' AND t.created_by = ?';
            $params[] = $userId;
        }
        return [$sql, $params];
    }

    /** @return array{income:float, expense:float} */
    public function totals(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        [$where, $params] = $this->scope($from, $to, $accountId, $userId);
        $r = $this->rows(
            "SELECT COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount END), 0) AS expense
             FROM transactions t WHERE $where",
            $params
        )[0];
        return ['income' => (float) $r['income'], 'expense' => (float) $r['expense']];
    }

    /**
     * Summen je Kategorie.
     * $parentId = null → gruppiert nach Hauptkategorien; sonst Unterkategorien dieser Hauptkategorie.
     * $splitPurchases: mit Einkauf verknüpfte Buchungen werden nach den Kategorien der Posten aufgeteilt.
     * @return array<int, array{id:?int, name:string, color:string, icon:string, total:float, count:int}>
     */
    public function byCategory(string $from, string $to, string $type = 'expense', ?int $parentId = null, bool $splitPurchases = true, ?int $accountId = null, ?int $userId = null): array
    {
        $sign = $type === 'income' ? '> 0' : '< 0';
        [$scope, $base] = $this->scope($from, $to, $accountId, $userId);
        $linked = 'EXISTS (SELECT 1 FROM purchases p WHERE p.transaction_id = t.id AND EXISTS (SELECT 1 FROM purchase_items i WHERE i.purchase_id = p.id))';
        $txWhere = "$scope AND t.amount $sign";

        // Teil A: Buchungen (ggf. ohne verknüpfte Einkäufe)
        $parts = ["SELECT {$this->cid()} AS cid, t.amount AS amt, 1 AS cnt FROM transactions t WHERE $txWhere" . ($splitPurchases && $type === 'expense' ? " AND NOT $linked" : '')];
        $params = $base;

        if ($splitPurchases && $type === 'expense') {
            // Teil B: Posten der verknüpften Einkäufe
            $parts[] = "SELECT i.category_id AS cid, -i.total_price AS amt, 0 AS cnt
                        FROM transactions t JOIN purchases p ON p.transaction_id = t.id JOIN purchase_items i ON i.purchase_id = p.id
                        WHERE $txWhere";
            // Teil C: Differenz zwischen Buchungsbetrag und Summe der Posten (Rundung, Pfand, Rabatte ...)
            $parts[] = "SELECT {$this->cid()} AS cid, t.amount + (SELECT COALESCE(SUM(i.total_price), 0) FROM purchases p JOIN purchase_items i ON i.purchase_id = p.id WHERE p.transaction_id = t.id) AS amt, 1 AS cnt
                        FROM transactions t WHERE $txWhere AND $linked";
            $params = [...$base, ...$base, ...$base];
        }

        $union = implode(' UNION ALL ', $parts);
        if ($parentId === null) {
            $sql = "SELECT {$this->groupId('x.cid')} AS id, SUM(x.amt) AS total, SUM(x.cnt) AS cnt
                    FROM ($union) x LEFT JOIN categories c ON c.id = x.cid
                    GROUP BY 1";
        } else {
            $sql = "SELECT c.id AS id, SUM(x.amt) AS total, SUM(x.cnt) AS cnt
                    FROM ($union) x JOIN categories c ON c.id = x.cid
                    WHERE c.id = ? OR c.parent_id = ?
                    GROUP BY c.id";
            $params[] = $parentId;
            $params[] = $parentId;
        }
        $rows = $this->rows($sql, $params);
        $cats = $this->categoryMap();
        $out = [];
        foreach ($rows as $r) {
            $total = abs((float) $r['total']);
            if ($total < 0.005) {
                continue;
            }
            if ((int) $r['id'] === self::TRANSFER_ID) {
                $out[] = ['id' => self::TRANSFER_ID, 'name' => 'Umbuchungen', 'color' => '#6c757d', 'icon' => 'arrow-left-right',
                          'has_children' => false, 'total' => $total, 'count' => (int) $r['cnt']];
                continue;
            }
            $c = $r['id'] ? ($cats[(int) $r['id']] ?? null) : null;
            $out[] = [
                'id'    => $r['id'] ? (int) $r['id'] : null,
                'name'  => $c ? ($parentId && (int) $r['id'] === $parentId ? $c['name'] . ' (allgemein)' : $c['name']) : 'Ohne Kategorie',
                'color' => $c['color'] ?? '#adb5bd',
                'icon'  => $c['icon'] ?? 'question',
                'has_children' => $c ? $c['has_children'] : false,
                'total' => $total,
                'count' => (int) $r['cnt'],
            ];
        }
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** Einnahmen/Ausgaben je Monat: ['2024-01' => ['income' => .., 'expense' => ..]] */
    public function monthly(string $from, string $to, ?int $accountId = null, ?int $userId = null): array
    {
        [$where, $params] = $this->scope($from, $to, $accountId, $userId);
        $rows = $this->rows(
            "SELECT DATE_FORMAT(t.booking_date, '%Y-%m') AS ym,
                    SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END) AS expense
             FROM transactions t WHERE $where
             GROUP BY ym ORDER BY ym",
            $params
        );
        $out = [];
        foreach (self::monthRange($from, $to) as $ym) {
            $out[$ym] = ['income' => 0.0, 'expense' => 0.0];
        }
        foreach ($rows as $r) {
            $out[$r['ym']] = ['income' => (float) $r['income'], 'expense' => (float) $r['expense']];
        }
        return $out;
    }

    /** Ausgaben je Monat und Hauptkategorie (für gestapelte Balken) */
    public function monthlyByCategory(string $from, string $to, ?int $accountId = null, ?int $userId = null, int $top = 8): array
    {
        [$where, $params] = $this->scope($from, $to, $accountId, $userId);
        $rows = $this->rows(
            "SELECT DATE_FORMAT(t.booking_date, '%Y-%m') AS ym, {$this->groupId($this->cid())} AS cid, SUM(-t.amount) AS total
             FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
             WHERE $where AND t.amount < 0
             GROUP BY ym, cid",
            $params
        );
        $months = self::monthRange($from, $to);
        $sumByCat = [];
        foreach ($rows as $r) {
            $sumByCat[(int) $r['cid']] = ($sumByCat[(int) $r['cid']] ?? 0) + (float) $r['total'];
        }
        arsort($sumByCat);
        $topIds = array_slice(array_keys($sumByCat), 0, $top);
        $cats = $this->categoryMap();
        $series = [];
        foreach ($rows as $r) {
            $cid = (int) $r['cid'];
            $key = in_array($cid, $topIds, true) ? $cid : -1;
            $series[$key]['data'][$r['ym']] = ($series[$key]['data'][$r['ym']] ?? 0) + (float) $r['total'];
        }
        $out = [];
        foreach ($series as $cid => $s) {
            $c = $cats[$cid] ?? null;
            $out[] = [
                'label' => match ($cid) { -1 => 'Übrige', self::TRANSFER_ID => 'Umbuchungen', default => $c['name'] ?? 'Ohne Kategorie' },
                'color' => match ($cid) { -1 => '#ced4da', self::TRANSFER_ID => '#6c757d', default => $c['color'] ?? '#adb5bd' },
                'data'  => array_map(fn ($ym) => round($s['data'][$ym] ?? 0, 2), $months),
                'sum'   => array_sum($s['data']),
            ];
        }
        usort($out, fn ($a, $b) => ($a['label'] === 'Übrige') <=> ($b['label'] === 'Übrige') ?: $b['sum'] <=> $a['sum']);
        return ['labels' => array_map('month_label', $months), 'datasets' => $out];
    }

    public function topPayees(string $from, string $to, ?int $accountId = null, ?int $userId = null, int $limit = 15): array
    {
        [$where, $params] = $this->scope($from, $to, $accountId, $userId);
        return $this->rows(
            "SELECT t.payee, COUNT(*) AS cnt, SUM(-t.amount) AS total
             FROM transactions t
             WHERE $where AND t.amount < 0 AND CHAR_LENGTH(t.payee) > 0
             GROUP BY t.payee ORDER BY total DESC LIMIT " . (int) $limit,
            $params
        );
    }

    /**
     * Wirksame Kategorie eines Postens (Alias i, Produkt pr): eigene Kategorie, sonst die gelernte des Produkts.
     */
    private const ITEM_CAT = 'COALESCE(i.category_id, pr.default_category_id)';

    /**
     * Gemeinsamer Filter der Einzelposten-Auswertung (Aliase i, p, pr).
     * $categoryId: null = alle, 0 = ohne Kategorie, sonst die Kategorie samt Unterkategorien.
     * @return array{0:string, 1:array}
     */
    private function itemScope(string $from, string $to, ?int $categoryId = null, string $q = ''): array
    {
        $sql = "p.household_id = ? AND (p.account_id IS NULL OR p.account_id IN ({$this->in()})) AND p.purchase_date BETWEEN ? AND ?";
        $params = [$this->householdId, ...$this->accountIds, $from, $to];
        if ($categoryId === 0) {
            $sql .= ' AND ' . self::ITEM_CAT . ' IS NULL';
        } elseif ($categoryId) {
            $sql .= ' AND (' . self::ITEM_CAT . ' = ? OR ' . self::ITEM_CAT . ' IN (SELECT id FROM categories WHERE parent_id = ?))';
            $params[] = $categoryId;
            $params[] = $categoryId;
        }
        if ($q !== '') {
            $sql .= ' AND (i.name LIKE ? OR pr.name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [$sql, $params];
    }

    /**
     * Einzelposten-Auswertung: gruppiert nach Produkt.
     * Einkäufe ohne Konto sind für alle sichtbar, sonst nur mit Leserecht auf das Konto.
     */
    public function items(string $from, string $to, ?int $categoryId = null, string $q = '', string $sort = 'total', int $limit = 200): array
    {
        [$where, $params] = $this->itemScope($from, $to, $categoryId, $q);
        $order = match ($sort) {
            'count' => 'cnt DESC',
            'name'  => 'name ASC',
            'price' => 'avg_price DESC',
            default => 'total DESC',
        };
        return $this->rows(
            "SELECT COALESCE(pr.id, 0) AS product_id, COALESCE(pr.name, i.name) AS name, MIN(i.name) AS item_name,
                    COUNT(*) AS cnt, SUM(i.quantity) AS qty, SUM(i.total_price) AS total,
                    AVG(i.unit_price) AS avg_price, MIN(i.unit_price) AS min_price, MAX(i.unit_price) AS max_price,
                    MAX(p.purchase_date) AS last_date, GROUP_CONCAT(DISTINCT p.store SEPARATOR ', ') AS stores,
                    MAX(c.id) AS category_id, MAX(c.name) AS category_name, MAX(c.color) AS category_color,
                    COUNT(DISTINCT c.id) AS category_count
             FROM purchase_items i
             JOIN purchases p ON p.id = i.purchase_id
             LEFT JOIN products pr ON pr.id = i.product_id
             LEFT JOIN categories c ON c.id = " . self::ITEM_CAT . "
             WHERE $where
             GROUP BY COALESCE(pr.id, CONCAT('n:', i.name))
             ORDER BY $order LIMIT " . (int) $limit,
            $params
        );
    }

    /** Summe und Anzahl der Posten zum Filter (unabhängig vom Zeilenlimit der Liste) */
    public function itemsSummary(string $from, string $to, ?int $categoryId = null, string $q = ''): array
    {
        [$where, $params] = $this->itemScope($from, $to, $categoryId, $q);
        $r = $this->rows(
            "SELECT COALESCE(SUM(i.total_price), 0) AS total, COUNT(*) AS cnt
             FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id LEFT JOIN products pr ON pr.id = i.product_id
             WHERE $where",
            $params
        )[0];
        return ['total' => (float) $r['total'], 'count' => (int) $r['cnt']];
    }

    /** @return array<int, float> Unterkategorie-ID => Summe der Posten (nur Unterkategorien von $parentId) */
    public function itemsBySubcategory(string $from, string $to, int $parentId, string $q = ''): array
    {
        [$where, $params] = $this->itemScope($from, $to, $parentId, $q);
        $out = [];
        foreach ($this->rows(
            'SELECT ' . self::ITEM_CAT . " AS id, SUM(i.total_price) AS total
             FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id LEFT JOIN products pr ON pr.id = i.product_id
             WHERE $where GROUP BY " . self::ITEM_CAT,
            $params
        ) as $r) {
            $out[(int) $r['id']] = (float) $r['total'];
        }
        return $out;
    }

    /** Posten-Summen je Hauptkategorie (nur Einkäufe) */
    public function itemsByCategory(string $from, string $to): array
    {
        [$where, $params] = $this->itemScope($from, $to);
        $rows = $this->rows(
            "SELECT COALESCE(c.parent_id, c.id) AS id, SUM(i.total_price) AS total, COUNT(*) AS cnt
             FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id
             LEFT JOIN products pr ON pr.id = i.product_id
             LEFT JOIN categories c ON c.id = " . self::ITEM_CAT . "
             WHERE $where
             GROUP BY COALESCE(c.parent_id, c.id) ORDER BY total DESC",
            $params
        );
        $cats = $this->categoryMap();
        return array_map(fn ($r) => [
            'id' => $r['id'] ? (int) $r['id'] : 0,
            'name' => $r['id'] ? ($cats[(int) $r['id']]['name'] ?? '?') : 'Ohne Kategorie',
            'color' => $r['id'] ? ($cats[(int) $r['id']]['color'] ?? '#adb5bd') : '#adb5bd',
            'total' => (float) $r['total'], 'count' => (int) $r['cnt'],
        ], $rows);
    }

    /** Preisverlauf eines Produkts */
    public function productHistory(int $productId): array
    {
        return $this->rows(
            "SELECT p.purchase_date, p.store, i.name, i.quantity, i.unit, i.unit_price, i.total_price, p.id AS purchase_id
             FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id
             WHERE p.household_id = ? AND i.product_id = ? AND (p.account_id IS NULL OR p.account_id IN ({$this->in()}))
             ORDER BY p.purchase_date",
            [$this->householdId, $productId, ...$this->accountIds]
        );
    }

    /** Kategorie einer Buchung (Alias t); Umbuchungen bekommen die Pseudo-ID TRANSFER_ID */
    private function cid(): string
    {
        return 'CASE WHEN t.transfer_group IS NOT NULL THEN ' . self::TRANSFER_ID . ' ELSE t.category_id END';
    }

    /** Hauptkategorie zu $cidExpr (Join auf categories c); Umbuchungen behalten ihre Pseudo-ID */
    private function groupId(string $cidExpr): string
    {
        return "CASE WHEN $cidExpr = " . self::TRANSFER_ID . ' THEN ' . self::TRANSFER_ID . ' ELSE COALESCE(c.parent_id, c.id) END';
    }

    /** @return array<int, array{name:string, color:string, icon:string, parent_id:?int, has_children:bool}> */
    private function categoryMap(): array
    {
        static $cache = [];
        if (!isset($cache[$this->householdId])) {
            $rows = $this->rows('SELECT id, name, color, icon, parent_id FROM categories WHERE household_id = ?', [$this->householdId]);
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r['id']] = $r + ['has_children' => false];
            }
            foreach ($rows as $r) {
                if ($r['parent_id'] && isset($map[(int) $r['parent_id']])) {
                    $map[(int) $r['parent_id']]['has_children'] = true;
                }
            }
            $cache[$this->householdId] = $map;
        }
        return $cache[$this->householdId];
    }

    /** @return string[] 'Y-m' */
    public static function monthRange(string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = substr($to, 0, 7);
        for ($i = 0; $i < 400 && $d->format('Y-m') <= $end; $i++) {
            $out[] = $d->format('Y-m');
            $d = $d->modify('+1 month');
        }
        return $out;
    }
}
