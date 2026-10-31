<?php

declare(strict_types=1);

/**
 * Legt die Demo-Datenbank „haushaltsbuch_demo“ mit ausgedachten Daten an (Familie Muster).
 * Dient für die Screenshots der Benutzeranleitung (docs/ANLEITUNG.md, bin/screenshots.py).
 *
 *   php bin/demo_seed.php
 *
 * Die Datenbank wird dabei komplett neu angelegt. Die echte Datenbank wird nie angefasst.
 * Anmeldung: anna@example.org / demo1234
 */

use App\Core\Database;
use App\Repositories\RecipeRepository;
use App\Repositories\ShoppingRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\UserRepository;
use App\Services\CategorizationService;
use App\Services\InventoryService;
use App\Services\Migrator;
use App\Services\RecurrenceService;

const DEMO_DB = 'haushaltsbuch_demo';

$_ENV['DB_NAME'] = DEMO_DB;
putenv('DB_NAME=' . DEMO_DB);
require dirname(__DIR__) . '/app/bootstrap.php';

$c = \App\Core\Config::get('db');
if ($c['name'] !== DEMO_DB) {
    fwrite(STDERR, "Abbruch: Datenbank ist nicht " . DEMO_DB . PHP_EOL);
    exit(1);
}
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass']);
$server->exec('DROP DATABASE IF EXISTS `' . DEMO_DB . '`');
Migrator::run(true);

$db = Database::connection();
mt_srand(42);
$today = date('Y-m-d');
$start = date('Y-m-01', strtotime('first day of -11 months'));

$hid = (new HouseholdRepository())->create('Familie Muster');
$users = new UserRepository();
$anna = $users->create($hid, 'Anna Muster', 'anna@example.org', 'demo1234', 'admin');
$tom = $users->create($hid, 'Tom Muster', 'tom@example.org', 'demo1234', 'member');
$lena = $users->create($hid, 'Lena Muster', 'lena@example.org', 'demo1234', 'child');
(new CategoryRepository())->seedDefaults($hid);

$cat = static function (string $name) use ($hid): ?int {
    return (new CategorizationService($hid))->categoryIdByName($name);
};

$insert = static function (string $table, array $row) use ($db): int {
    $cols = array_keys($row);
    $sql = sprintf('INSERT INTO %s (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?')));
    $db->prepare($sql)->execute(array_values($row));
    return (int) $db->lastInsertId();
};

// Konten
$giro = $insert('accounts', ['household_id' => $hid, 'name' => 'Girokonto', 'type' => 'giro', 'bank' => 'Sparkasse',
    'iban' => 'DE02120300000000202051', 'opening_balance' => '2350.00', 'opening_date' => $start, 'color' => '#2a78d6', 'sort_order' => 1]);
$haushalt = $insert('accounts', ['household_id' => $hid, 'name' => 'Haushaltskonto', 'type' => 'giro', 'bank' => 'DKB',
    'opening_balance' => '420.00', 'opening_date' => $start, 'color' => '#1baf7a', 'sort_order' => 2]);
$tagesgeld = $insert('accounts', ['household_id' => $hid, 'name' => 'Tagesgeld', 'type' => 'savings', 'bank' => 'ING',
    'opening_balance' => '8500.00', 'opening_date' => $start, 'color' => '#eda100', 'sort_order' => 3]);
$bargeld = $insert('accounts', ['household_id' => $hid, 'name' => 'Taschengeld Lena', 'type' => 'cash',
    'opening_balance' => '35.00', 'opening_date' => $start, 'color' => '#e87ba4', 'sort_order' => 4, 'include_in_forecast' => 0]);

// Rechte: Tom sieht alles und bucht auf dem Haushaltskonto, Lena nur ihr Taschengeld
foreach ([$giro, $haushalt, $tagesgeld, $bargeld] as $acc) {
    $insert('account_permissions', ['account_id' => $acc, 'user_id' => $tom, 'can_view' => 1, 'can_book' => (int) ($acc === $haushalt)]);
}
$insert('account_permissions', ['account_id' => $bargeld, 'user_id' => $lena, 'can_view' => 1, 'can_book' => 1]);

// Kredit
$loan = $insert('loans', ['household_id' => $hid, 'name' => 'Autokredit', 'lender' => 'Musterbank', 'contract_number' => '4711-0815',
    'principal' => '18000.00', 'payout_date' => date('Y-m-15', strtotime('-18 months')), 'interest_rate' => '4.900',
    'loan_type' => 'annuity', 'monthly_payment' => '339.00', 'first_payment_date' => date('Y-m-01', strtotime('first day of -17 months')),
    'account_id' => $giro]);
$insert('loan_special_payments', ['loan_id' => $loan, 'payment_date' => date('Y-m-d', strtotime('-4 months')), 'amount' => '1000.00', 'note' => 'Bonus']);

// Fixkosten (werden unten automatisch gebucht)
$rec = static function (array $r) use ($insert, $hid, $start, $anna): int {
    return $insert('recurring_transactions', $r + ['household_id' => $hid, 'interval' => 'monthly', 'start_date' => $start,
        'auto_book' => 1, 'active' => 1, 'created_by' => $anna]);
};
$rec(['account_id' => $giro, 'category_id' => $cat('Gehalt'), 'amount' => '3450.00', 'payee' => 'Muster GmbH', 'purpose' => 'Gehalt Anna', 'day_of_month' => 28]);
$rec(['account_id' => $giro, 'category_id' => $cat('Gehalt'), 'amount' => '1480.00', 'payee' => 'Beispiel AG', 'purpose' => 'Gehalt Tom (Teilzeit)', 'day_of_month' => 30]);
$rec(['account_id' => $giro, 'category_id' => $cat('Kindergeld'), 'amount' => '255.00', 'payee' => 'Familienkasse', 'day_of_month' => 5]);
$rec(['account_id' => $giro, 'category_id' => $cat('Miete / Rate'), 'amount' => '-1150.00', 'payee' => 'Hausverwaltung Schmidt', 'purpose' => 'Miete', 'day_of_month' => 1]);
$rec(['account_id' => $giro, 'category_id' => $cat('Nebenkosten'), 'amount' => '-210.00', 'payee' => 'Hausverwaltung Schmidt', 'purpose' => 'Nebenkostenvorauszahlung', 'day_of_month' => 1]);
$rec(['account_id' => $giro, 'category_id' => $cat('Schule / Kita'), 'amount' => '-285.00', 'payee' => 'Kita Sonnenschein', 'day_of_month' => 3]);
$rec(['account_id' => $giro, 'category_id' => $cat('Haftpflicht'), 'amount' => '-12.40', 'payee' => 'Allianz', 'purpose' => 'Privathaftpflicht', 'day_of_month' => 1]);
$rec(['account_id' => $giro, 'category_id' => $cat('Leben / BU'), 'amount' => '-78.00', 'payee' => 'Alte Leipziger', 'purpose' => 'Berufsunfähigkeit', 'day_of_month' => 1]);
$rec(['account_id' => $giro, 'category_id' => $cat('Internet & Telefon'), 'amount' => '-34.98', 'payee' => 'congstar', 'purpose' => 'Handyverträge', 'day_of_month' => 20]);
$rec(['account_id' => $giro, 'category_id' => $cat('Sport'), 'amount' => '-49.00', 'payee' => 'FitX', 'day_of_month' => 5]);
$rec(['account_id' => $giro, 'category_id' => $cat('Strom'), 'amount' => '-86.00', 'payee' => 'Stadtwerke', 'purpose' => 'Abschlag Strom', 'day_of_month' => 15]);
$rec(['account_id' => $giro, 'category_id' => $cat('Internet & Telefon'), 'amount' => '-39.99', 'payee' => 'Telekom', 'day_of_month' => 10]);
$rec(['account_id' => $giro, 'category_id' => $cat('Streaming & Abos'), 'amount' => '-13.99', 'payee' => 'Netflix', 'day_of_month' => 12]);
$rec(['account_id' => $giro, 'category_id' => $cat('Rundfunkbeitrag'), 'amount' => '-55.08', 'payee' => 'Rundfunk ARD ZDF', 'interval' => 'quarterly', 'day_of_month' => 15]);
$rec(['account_id' => $giro, 'category_id' => $cat('KFZ-Versicherung'), 'amount' => '-412.50', 'payee' => 'HUK', 'interval' => 'yearly', 'day_of_month' => 1,
    'start_date' => date('Y-01-01', strtotime('+1 year')), 'auto_book' => 0]);
$rec(['account_id' => $giro, 'category_id' => $cat('Kreditrate'), 'amount' => '-339.00', 'payee' => 'Musterbank', 'purpose' => 'Kreditrate Autokredit',
    'day_of_month' => 1, 'loan_id' => $loan]);
$rec(['account_id' => $giro, 'to_account_id' => $tagesgeld, 'amount' => '200.00', 'purpose' => 'Sparrate', 'day_of_month' => 29]);
$rec(['account_id' => $giro, 'to_account_id' => $bargeld, 'category_id' => $cat('Taschengeld'), 'amount' => '20.00', 'purpose' => 'Taschengeld Lena', 'day_of_month' => 1]);
$rec(['account_id' => $giro, 'to_account_id' => $haushalt, 'amount' => '900.00', 'purpose' => 'Haushaltsgeld', 'day_of_month' => 2]);
RecurrenceService::materializeDue($hid, $today);

// Variable Buchungen
$tx = static function (int $acc, string $date, int $cents, string $payee, ?string $category, ?int $user = null, ?string $purpose = null, string $source = 'manual') use ($insert, $hid, $anna): int {
    return $insert('transactions', ['household_id' => $hid, 'account_id' => $acc, 'booking_date' => $date,
        'amount' => number_format($cents / 100, 2, '.', ''), 'payee' => $payee, 'purpose' => $purpose,
        'category_id' => $category ? (new CategorizationService($hid))->categoryIdByName($category) : null,
        'source' => $source, 'created_by' => $user ?? $anna]);
};
$pattern = [
    // Empfänger, Kategorie, Konto, Anzahl je Monat, Betrag von–bis (Cent)
    ['REWE', 'Lebensmittel', $haushalt, 6, 3000, 11000],
    ['ALDI SÜD', 'Lebensmittel', $haushalt, 4, 2000, 6500],
    ['Wochenmarkt', 'Obst & Gemüse', $haushalt, 2, 1200, 2800],
    ['dm-drogerie markt', 'Drogerie & Haushalt', $haushalt, 2, 1200, 3800],
    ['Bäckerei Kamps', 'Brot & Backwaren', $haushalt, 4, 400, 1200],
    ['Aral Tankstelle', 'Tanken / Laden', $giro, 2, 5500, 8500],
    ['Pizzeria Da Mario', 'Restaurant & Café', $giro, 2, 3200, 6800],
    ['Amazon', null, $giro, 2, 1500, 6000],
    ['Zalando', 'Kleidung', $giro, 1, 3500, 14000],
    ['Cinestar', 'Kultur & Kino', $giro, 1, 2400, 4200],
    ['Eisdiele Venezia', 'Restaurant & Café', $haushalt, 2, 800, 1900],
    ['Apotheke am Markt', 'Apotheke', $haushalt, 1, 600, 2400],
];
for ($m = new DateTimeImmutable($start); $m->format('Y-m-d') <= $today; $m = $m->modify('first day of next month')) {
    $days = (int) $m->format('t');
    foreach ($pattern as [$payee, $category, $acc, $n, $min, $max]) {
        for ($i = 0; $i < $n; $i++) {
            $date = $m->setDate((int) $m->format('Y'), (int) $m->format('n'), mt_rand(1, $days))->format('Y-m-d');
            if ($date > $today) {
                continue;
            }
            $tx($acc, $date, -mt_rand($min, $max), $payee, $category, $acc === $haushalt && $i % 2 ? $tom : $anna,
                $payee === 'Amazon' ? 'Bestellung ' . mt_rand(302, 306) . '-' . mt_rand(1000000, 9999999) : null, 'csv');
        }
    }
}
// Einzelne Anschaffungen und Ausgaben vom Taschengeld
$tx($giro, date('Y-m-d', strtotime('-3 months')), -89900, 'MediaMarkt', 'Elektronik', null, 'Waschmaschine');
$tx($giro, date('Y-m-d', strtotime('-2 months')), -64000, 'Reisebüro Sonnenschein', 'Urlaub');
$tx($bargeld, date('Y-m-d', strtotime('-9 days')), -850, 'Kiosk', 'Süßes & Snacks', $lena);
$tx($bargeld, date('Y-m-d', strtotime('-3 days')), -1299, 'Buchhandlung', 'Bücher', $lena);

// Einkäufe mit Posten (einer mit einer Buchung verknüpft)
$purchase = static function (string $date, string $store, ?int $acc, array $items, bool $link) use ($insert, $tx, $hid, $anna, $db): void {
    $total = array_sum(array_column($items, 1));
    $txId = $link ? $tx($acc, $date, -$total, $store, 'Lebensmittel', null, null, 'purchase') : null;
    $pid = $insert('purchases', ['household_id' => $hid, 'purchase_date' => $date, 'store' => $store, 'account_id' => $acc,
        'transaction_id' => $txId, 'total' => number_format($total / 100, 2, '.', ''), 'source' => 'photo', 'created_by' => $anna]);
    foreach ($items as $i => [$name, $cents, $category]) {
        $catId = (new CategorizationService($hid))->categoryIdByName($category);
        $prod = (new \App\Repositories\ProductRepository())->upsert($hid, $name, CategorizationService::normalizeProduct($name), $catId);
        $insert('purchase_items', ['purchase_id' => $pid, 'product_id' => $prod, 'name' => $name, 'quantity' => 1,
            'unit_price' => number_format($cents / 100, 2, '.', ''), 'total_price' => number_format($cents / 100, 2, '.', ''),
            'category_id' => $catId, 'sort_order' => $i]);
    }
};
$weekly = [
    ['Bio Vollmilch 1L', 129, 'Milchprodukte & Eier'], ['Bananen', 189, 'Obst & Gemüse'], ['Vollkornbrot', 249, 'Brot & Backwaren'],
    ['Gouda Scheiben', 219, 'Milchprodukte & Eier'], ['Tomaten', 299, 'Obst & Gemüse'], ['Hähnchenbrust', 699, 'Fleisch & Fisch'],
    ['Spaghetti', 119, 'Vorrat & Konserven'], ['Mineralwasser 6x1,5L', 234, 'Getränke'], ['Schokolade', 149, 'Süßes & Snacks'],
    ['Spülmittel', 179, 'Reinigung'], ['Pfand', -150, 'Pfand'],
];
foreach ([-40, -26, -12, -4] as $k => $offset) {
    $items = array_slice($weekly, 0, 7 + $k);
    foreach ($items as &$it) {
        $it[1] = (int) round($it[1] * (1 + 0.03 * $k));
    }
    unset($it);
    $purchase(date('Y-m-d', strtotime("$offset days")), 'REWE', $haushalt, $items, true);
}

// Zweiter Laden mit ähnlichen Produkten (für virtuelle Posten der Einkaufsliste)
$purchase(date('Y-m-d', strtotime('-18 days')), 'ALDI', $haushalt, [
    ['Milsani Frische Milch 1L', 109, 'Milchprodukte & Eier'], ['Bananen', 169, 'Obst & Gemüse'], ['Roggenbrot', 189, 'Brot & Backwaren'],
    ['Gouda jung Scheiben', 179, 'Milchprodukte & Eier'], ['Penne Rigate', 89, 'Vorrat & Konserven'],
], false);
$purchase(date('Y-m-d', strtotime('-2 days')), 'ALDI', $haushalt, [
    ['Milsani Frische Milch 1L', 115, 'Milchprodukte & Eier'], ['Penne Rigate', 95, 'Vorrat & Konserven'],
], false);

// Einkaufsliste aus virtuellen Posten
$product = static fn (string $name): int => (int) $db->query('SELECT id FROM products WHERE household_id = ' . $hid
    . ' AND normalized = ' . $db->quote(CategorizationService::normalizeProduct($name)))->fetchColumn();
$listId = $insert('shopping_lists', ['household_id' => $hid, 'name' => 'Wocheneinkauf', 'created_by' => $anna,
    'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
$lastAldi = (int) $db->query("SELECT MAX(id) FROM purchases WHERE household_id = $hid AND store = 'ALDI'")->fetchColumn();
$itemIds = [];
foreach ([
    ['Milch', 'Milchprodukte & Eier', ['Bio Vollmilch 1L', 'Milsani Frische Milch 1L'], 3, null, null],
    ['Käse', 'Milchprodukte & Eier', ['Gouda Scheiben', 'Gouda jung Scheiben'], 1, null, 'gerne jung'],
    ['Butter', 'Milchprodukte & Eier', [], 1, 'Pck', null],
    ['Bananen', 'Obst & Gemüse', ['Bananen'], 1, 'kg', null],
    ['Tomaten', 'Obst & Gemüse', ['Tomaten'], 500, 'g', null],
    ['Brot', 'Brot & Backwaren', ['Vollkornbrot', 'Roggenbrot'], 1, null, null],
    ['Nudeln', 'Vorrat & Konserven', ['Spaghetti', 'Penne Rigate'], 2, 'Pck', 'done'],
    ['Mineralwasser', 'Getränke', ['Mineralwasser 6x1,5L'], 2, null, null],
    ['Spülmittel', 'Reinigung', ['Spülmittel'], 1, null, null],
] as [$name, $category, $products, $qty, $unit, $note]) {
    $itemId = $insert('shopping_items', ['household_id' => $hid, 'name' => $name, 'normalized' => CategorizationService::normalizeProduct($name),
        'category_id' => $cat($category)]);
    $itemIds[$name] = $itemId;
    foreach ($products as $p) {
        $insert('shopping_item_products', ['shopping_item_id' => $itemId, 'product_id' => $product($p)]);
    }
    $done = $note === 'done';
    $insert('shopping_list_entries', ['list_id' => $listId, 'shopping_item_id' => $itemId, 'quantity' => $qty, 'unit' => $unit,
        'note' => $done ? null : $note, 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'done_at' => $done ? date('Y-m-d H:i:s', strtotime('-2 days')) : null, 'purchase_id' => $done ? $lastAldi : null]);
}

// Vorrat: Referenzmengen und Verbrauchsdauer, weitere virtuelle Posten für Rezepte, Chargen aus den Einkäufen
foreach ([
    // Name => [Referenzmenge, Einheit, Verbrauchsdauer, Grundvorrat, Kategorie, Produkt]
    'Milch' => [1, 'l', 7], 'Käse' => [1, null, 14], 'Butter' => [250, 'g', 21], 'Bananen' => [1, 'kg', 6],
    'Tomaten' => [1, 'kg', 7], 'Brot' => [1, null, 4], 'Nudeln' => [500, 'g', null], 'Mineralwasser' => [1.5, 'l', null],
    'Spülmittel' => [1, null, 60],
    'Eier' => [1, null, 21, false, 'Milchprodukte & Eier'], 'Mehl' => [1, 'kg', null, false, 'Vorrat & Konserven'],
    'Zwiebeln' => [1, 'kg', 30, false, 'Obst & Gemüse'], 'Paprika' => [1, null, 7, false, 'Obst & Gemüse'],
    'Reis' => [1, 'kg', null, false, 'Vorrat & Konserven'], 'Hähnchenbrust' => [1, null, 3, false, 'Fleisch & Fisch', 'Hähnchenbrust'],
    'Olivenöl' => [1, 'l', null, true, 'Vorrat & Konserven'], 'Salz' => [1, 'kg', null, true, 'Vorrat & Konserven'],
] as $name => $cfg) {
    [$refQty, $refUnit, $days] = $cfg;
    $data = ['ref_quantity' => $refQty, 'ref_unit' => $refUnit, 'shelf_days' => $days, 'staple' => (int) ($cfg[3] ?? false)];
    if (!isset($itemIds[$name])) {
        $itemIds[$name] = $insert('shopping_items', ['household_id' => $hid, 'name' => $name,
            'normalized' => CategorizationService::normalizeProduct($name), 'category_id' => $cat($cfg[4])] + $data);
        if (isset($cfg[5])) {
            $insert('shopping_item_products', ['shopping_item_id' => $itemIds[$name], 'product_id' => $product($cfg[5])]);
        }
    } else {
        (new ShoppingRepository())->updateItem($itemIds[$name], $hid, $data);
    }
}
// Ausgleichsfaktor aus dem Produktnamen (z. B. Mineralwasser 6x1,5L = 6 Flaschen à 1,5 l)
foreach ($db->query("SELECT sip.shopping_item_id, sip.product_id, pr.name, s.ref_quantity, s.ref_unit FROM shopping_item_products sip
                     JOIN products pr ON pr.id = sip.product_id JOIN shopping_items s ON s.id = sip.shopping_item_id") as $r) {
    $f = InventoryService::guessFactor($r['name'], (float) $r['ref_quantity'], $r['ref_unit']);
    if ($f) {
        (new ShoppingRepository())->updateLink((int) $r['shopping_item_id'], (int) $r['product_id'], $f, null);
    }
}
InventoryService::rebuild($hid, 90);
foreach (['Eier' => 6, 'Mehl' => 0.8, 'Zwiebeln' => 0.5] as $name => $target) {
    InventoryService::adjust($hid, (new ShoppingRepository())->findItem($itemIds[$name], $hid), $target);
}
InventoryService::consume($hid, $itemIds['Milch'], 0.5);
// Butter: Packung vor 25 Tagen, inzwischen abgelaufen → steht auf der automatischen Einkaufsliste
$insert('inventory_lots', ['household_id' => $hid, 'shopping_item_id' => $itemIds['Butter'], 'quantity' => 1, 'remaining' => 0.4,
    'acquired_on' => date('Y-m-d', strtotime('-25 days')), 'expires_on' => date('Y-m-d', strtotime('-4 days')), 'source' => 'manual']);

// Rezepte mit Klassifikation
$recipes = new RecipeRepository();
$recipes->ensureDefaultClasses($hid);
$groups = $recipes->classGroups($hid);
$groups[] = ['name' => 'Art', 'values' => [['name' => 'vegetarisch'], ['name' => 'mit Fleisch']]];
$recipes->saveClasses($hid, $groups);
$valueId = [];
foreach ($recipes->classGroups($hid) as $g) {
    foreach ($g['values'] as $v) {
        $valueId[$v['name']] = $v['id'];
    }
}
foreach ([
    ['Pfannkuchen', 4, ['morgens', 'mittags', 'vegetarisch'], [['Mehl', 250, 'g', null], ['Milch', 0.5, 'l', null], ['Eier', 3, null, null], ['Butter', 30, 'g', 'zum Ausbacken'], ['Salz', 1, null, '1 Prise']],
        "Mehl, Milch, Eier und eine Prise Salz zu einem glatten Teig verrühren und 15 Minuten quellen lassen.\nIn einer Pfanne etwas Butter erhitzen, eine Kelle Teig hineingeben und von beiden Seiten goldbraun backen.\nDazu passen Apfelmus, Zimt und Zucker oder Marmelade."],
    ['Spaghetti mit Tomatensoße', 2, ['mittags', 'abends', 'vegetarisch'], [['Nudeln', 250, 'g', null], ['Tomaten', 500, 'g', 'reif'], ['Zwiebeln', 100, 'g', 'gewürfelt'], ['Olivenöl', 2, null, '2 EL'], ['Salz', 1, null, null]],
        "Zwiebeln in Olivenöl glasig dünsten, Tomaten würfeln und 15 Minuten mitköcheln lassen, mit Salz abschmecken.\nSpaghetti in reichlich Salzwasser bissfest kochen und mit der Soße mischen."],
    ['Käsebrot', 1, ['morgens', 'abends', 'vegetarisch'], [['Brot', 0.2, null, '2 Scheiben'], ['Käse', 0.25, null, null], ['Butter', 10, 'g', null]],
        "Brot mit Butter bestreichen und mit Käse belegen."],
    ['Hähnchen mit Paprika und Reis', 2, ['abends', 'mit Fleisch'], [['Hähnchenbrust', 1, null, null], ['Paprika', 2, null, 'rot und gelb'], ['Reis', 150, 'g', null], ['Zwiebeln', 100, 'g', null], ['Olivenöl', 2, null, '2 EL']],
        "Reis nach Packungsangabe garen. Hähnchen in Streifen schneiden und in Olivenöl scharf anbraten.\nZwiebeln und Paprika dazugeben, 8 Minuten mitbraten, würzen und mit dem Reis servieren."],
    ['Bananen-Milchshake', 2, ['morgens', 'vegetarisch'], [['Bananen', 300, 'g', null], ['Milch', 0.4, 'l', 'kalt']],
        "Bananen schälen und mit der kalten Milch im Mixer fein pürieren."],
] as [$name, $servings, $classes, $ings, $text]) {
    $rid = $recipes->create($hid, ['name' => $name, 'servings' => $servings, 'instructions' => $text, 'created_by' => $anna]);
    $recipes->replaceIngredients($rid, array_map(fn ($i) => ['shopping_item_id' => $itemIds[$i[0]], 'quantity' => $i[1], 'unit' => $i[2], 'note' => $i[3]], $ings));
    $recipes->setClasses($rid, array_map(fn ($c) => $valueId[$c], $classes));
}
InventoryService::refreshAutoList($hid);

// Szenario und Einstellungen
$sid = $insert('forecast_scenarios', ['household_id' => $hid, 'name' => 'Sparsamer Monat', 'note' => 'Weniger Essen gehen, kein Urlaub']);
$insert('forecast_scenario_values', ['scenario_id' => $sid, 'account_id' => $giro, 'monthly_amount' => '-350.00']);
$insert('forecast_scenario_values', ['scenario_id' => $sid, 'account_id' => $haushalt, 'monthly_amount' => '-600.00']);
$insert('category_rules', ['household_id' => $hid, 'target' => 'transaction', 'field' => 'payee', 'operator' => 'contains',
    'value' => 'Amazon', 'category_id' => $cat('Haushaltswaren'), 'priority' => 100]);
foreach ([['payee', 'contains', 'Netflix', 'Streaming & Abos'], ['payee', 'starts', 'Aral', 'Tanken / Laden'], ['name', 'contains', 'Pfand', 'Pfand']] as [$field, $op, $value, $name]) {
    $insert('category_rules', ['household_id' => $hid, 'target' => $field === 'name' ? 'item' : 'transaction', 'field' => $field,
        'operator' => $op, 'value' => $value, 'category_id' => $cat($name), 'priority' => 100]);
}
$insert('settings', ['household_id' => $hid, 'key' => 'ocr_mode', 'value' => 'local']);

// Claude (MCP): Zugriff an, eine verbundene App (Tokens sind Zufallswerte ohne Gegenstück) und ein paar Protokolleinträge
$insert('settings', ['household_id' => $hid, 'key' => 'mcp_enabled', 'value' => '1']);
$cid = $insert('oauth_clients', ['client_id' => 'hb_demo', 'name' => 'Claude', 'redirect_uris' => '["https://claude.ai/api/mcp/auth_callback"]']);
$insert('oauth_tokens', ['client_id' => $cid, 'user_id' => $anna, 'household_id' => $hid, 'scope' => 'haushalt',
    'access_hash' => hash('sha256', random_bytes(16)), 'access_expires_at' => date('Y-m-d H:i:s', time() + 3600),
    'refresh_hash' => hash('sha256', random_bytes(16)), 'refresh_expires_at' => date('Y-m-d H:i:s', time() + 80 * 86400),
    'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'last_used_at' => date('Y-m-d H:i:s', strtotime('-25 minutes'))]);
foreach ([
    [190, 'add_to_shopping_list', 'Auf „Wocheneinkauf“: Milch, Butter, Bananen'],
    [120, 'cook_recipe', '„Pfannkuchen“ für 4 Portionen vom Vorrat abgebucht.'],
    [60, 'update_inventory', 'Vorrat Mehl: 0,5 kg → 0 kg'],
    [25, 'book_cash_expense', 'Gebucht: 4,80 € „Bäcker“ am ' . date('d.m.Y') . ' · Kategorie: Lebensmittel · Konto: Taschengeld Lena'],
] as [$min, $tool, $summary]) {
    $insert('mcp_log', ['household_id' => $hid, 'user_id' => $anna, 'client_name' => 'Claude', 'tool' => $tool, 'summary' => $summary,
        'created_at' => date('Y-m-d H:i:s', strtotime("-$min minutes"))]);
}
// Kalender: Vorlagen, Termine mit Vor-/Nachlauf und Teilnehmern, Budgets
$mon = date('Y-m-d', strtotime('monday this week'));
$day = static fn (int $offset, string $time = '') => trim(date('Y-m-d', strtotime("$mon +$offset days")) . ' ' . $time);
$tplSwim = $insert('calendar_templates', ['household_id' => $hid, 'user_id' => $anna, 'name' => 'Schwimmtraining Lena', 'title' => 'Schwimmtraining',
    'location' => 'Hallenbad Nord', 'duration_minutes' => 90, 'lead_minutes' => 20, 'trail_minutes' => 30, 'color' => '#1baf7a',
    'budget_amount' => '4.50', 'budget_account_id' => $haushalt]);
$insert('calendar_templates', ['household_id' => $hid, 'user_id' => $anna, 'name' => 'Wochenendausflug', 'duration_minutes' => 1740,
    'lead_minutes' => 150, 'trail_minutes' => 150, 'color' => '#eda100', 'budget_amount' => '150.00', 'budget_account_id' => $giro]);
$insert('calendar_templates', ['household_id' => $hid, 'user_id' => $anna, 'name' => 'Arzttermin', 'duration_minutes' => 45,
    'lead_minutes' => 20, 'trail_minutes' => 20, 'color' => '#2a78d6']);
$event = static function (int $owner, array $data, array $participants, array $budgets = []) use ($hid): int {
    $id = \App\Services\CalendarService::save($hid, $owner, null, \App\Services\CalendarService::buildEvent($data), $participants);
    foreach ($budgets as [$user, $amount, $account, $category]) {
        (new \App\Repositories\CalendarRepository())->saveBudget($id, $user, $amount, $account, $category, null);
    }
    return $id;
};
$event($anna, ['title' => 'Schwimmtraining', 'location' => 'Hallenbad Nord', 'start' => $day(1, '17:00'), 'end' => $day(1, '18:30'),
    'lead_minutes' => 20, 'trail_minutes' => 30, 'color' => '#1baf7a', 'recurrence' => 'weekly', 'template_id' => $tplSwim],
    [$lena], [[$anna, '4.50', $haushalt, $cat('Sport')]]);
$event($tom, ['title' => 'Elternabend 3b', 'location' => 'Grundschule', 'start' => $day(2, '19:30'), 'end' => $day(2, '21:00'),
    'lead_minutes' => 15, 'trail_minutes' => 15, 'color' => '#7c5cc4'], [$anna]);
$event($anna, ['title' => 'Zahnarzt Lena', 'start' => $day(3, '08:30'), 'end' => $day(3, '09:15'), 'lead_minutes' => 20,
    'trail_minutes' => 20, 'color' => '#2a78d6'], [$lena]);
$event($anna, ['title' => 'Kindergeburtstag Mia', 'location' => 'Trampolinhalle', 'start' => $day(5, '14:00'), 'end' => $day(5, '17:00'),
    'lead_minutes' => 30, 'trail_minutes' => 30, 'color' => '#e87ba4', 'note' => 'Geschenk: Buch + Gutschein'],
    [$lena], [[$anna, '25.00', $haushalt, $cat('Geschenke & Spenden')]]);
$event($tom, ['title' => 'Wochenende im Harz', 'location' => 'Wernigerode', 'start' => $day(12, '10:00'), 'end' => $day(13, '15:00'),
    'lead_minutes' => 150, 'trail_minutes' => 150, 'color' => '#eda100'], [$anna, $lena],
    [[$tom, '120.00', $haushalt, $cat('Urlaub')], [$anna, '150.00', $giro, $cat('Urlaub')]]);
$event($anna, ['title' => 'Sommerferien', 'all_day' => true, 'start' => date('Y-07-20', strtotime('+1 year')), 'end' => date('Y-08-02', strtotime('+1 year')),
    'color' => '#0aa2c0'], [$tom, $lena]);
$insert('user_preferences', ['user_id' => $anna, 'key' => 'account_calendar', 'value' => (string) $haushalt]);

$db->exec("UPDATE users SET last_login_at = NOW() - INTERVAL id * 17 HOUR");

RecurrenceService::linkExisting($hid, null, $today);

printf("Demo-Datenbank %s angelegt: %d Buchungen. Anmeldung: anna@example.org / demo1234\n", DEMO_DB,
    (int) $db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
