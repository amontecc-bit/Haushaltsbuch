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
use App\Repositories\CategoryRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\UserRepository;
use App\Services\CategorizationService;
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
$db->exec("UPDATE users SET last_login_at = NOW() - INTERVAL id * 17 HOUR");

RecurrenceService::linkExisting($hid, null, $today);

printf("Demo-Datenbank %s angelegt: %d Buchungen. Anmeldung: anna@example.org / demo1234\n", DEMO_DB,
    (int) $db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
