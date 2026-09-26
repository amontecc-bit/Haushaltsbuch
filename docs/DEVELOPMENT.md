# Entwicklung – Datenmodell, Abläufe, Tests

Ergänzt `CLAUDE.md` um Details, die man beim Ändern einzelner Bereiche braucht.

## Datenmodell (`database/migrations/001_schema.sql`)

| Tabelle | Zweck / wichtige Spalten |
|---|---|
| `households` | ein Haushalt je Installation (mehrere technisch möglich) |
| `users` | `role` admin/member/child, `failed_logins`/`locked_until` (5 Fehlversuche → 15 min Sperre) |
| `accounts` | `opening_balance` + `opening_date`, `include_in_forecast`, `archived` (Konten mit Buchungen werden nur archiviert) |
| `account_permissions` | (account, user) → `can_view`, `can_book`; Admins brauchen keine Einträge |
| `categories` | zwei Ebenen (`parent_id`), `type` expense/income, `icon` (Bootstrap-Icon-Name), `color` |
| `transactions` | `amount` mit Vorzeichen, `source` manual/csv/recurring/purchase, `import_hash` (Duplikate), `recurring_id`, `transfer_group`, `created_by` |
| `recurring_transactions` | Vorlage: `interval`, `day_of_month`, `start_date`/`end_date`, `last_booked_date`, `auto_book` (0 = nur Prognose), `to_account_id` (Umbuchung), `loan_id` |
| `csv_profiles` | `household_id` NULL = mitgeliefert (002_csv_presets.sql); `mapping` JSON: Feld → Spaltenname |
| `import_batches` | Protokoll je Import |
| `category_rules` | `target` transaction/item, `field`, `operator` contains/equals/starts/regex, `priority` (klein zuerst), `learned` |
| `products` | normalisierter Name → `default_category_id` (hier lernt die Posten-Kategorisierung) |
| `purchases` / `purchase_items` | Einkauf (optional `account_id`, `transaction_id`, `file_path` = `|`-getrennte Pfade) und Posten |
| `loans`, `loan_special_payments`, `loan_rate_changes` | Kreditdaten, Sondertilgungen, Zins-/Ratenänderungen ab Datum |
| `settings` | Schlüssel/Wert je Haushalt: `ocr_mode`, `ai_api_key`, `ai_model`, `forecast_months`, `forecast_avg_months` |

Saldo eines Kontos = `opening_balance + SUM(transactions.amount bis Stichtag)`.

## Wichtige Abläufe

**CSV-Import** (`ImportController`): Upload → Datei nach `storage/uploads/import/`, Zustand in `$_SESSION['import']`
→ `preview` (Profil: gewählt / automatisch per `detectProfile` / geraten per `guessMapping`) → `analyse()` setzt je Zeile
Status `duplicate` (Hash existiert) | `match` (vorhandene Buchung ohne Hash, gleicher Betrag ±5 Tage → „Zusammenführen“)
| `pending` (vorgemerkt) | `new`, dazu passende Fixkosten-Vorlage (`recurring_id`) und Kategorie-Vorschlag → `commit`
rechnet alles serverseitig neu (Client schickt nur Aktion + Kategorie je Hash).
Hash = Konto | Datum | Cent | normalisierter Empfänger+Zweck | laufende Nummer identischer Zeilen in der Datei.

**Neues Bankformat:** Zeile in einer neuen Migration zu `csv_profiles` hinzufügen (Spaltennamen exakt wie in der
Kopfzeile, Groß-/Kleinschreibung egal) und eine Beispieldatei unter `tests/fixtures/` + Test in `CsvImportServiceTest`.

**Einkauf erfassen** (`purchases/form.php` + `public/assets/js/purchase.js`, Alpine-Komponente `purchaseForm`):
- Foto: Bild wird im Browser auf ≤2400 px JPEG verkleinert; lokal → Graustufen/Kontrast → Tesseract.js (`deu`) →
  Text + Bilder an `POST /purchases/recognize` (`mode=local`); KI → nur Bilder (`mode=ai`).
- PDF: Server liest Text mit `smalot/pdfparser`; hat das PDF keine Textebene → Antwort `needs_ocr` + `token`,
  der Browser rendert Seiten mit pdf.js, macht OCR und schickt den Text mit demselben `token` nach.
- Hochgeladene Dateien liegen bis zum Speichern in `storage/uploads/tmp/{token}/` (nach 24 h aufgeräumt) und werden
  beim Speichern nach `storage/uploads/purchases/{id}/` verschoben; Auslieferung nur über `/purchases/{id}/file?n=`.
- Speichern per JSON (`POST /purchases` bzw. `/purchases/{id}`), Verknüpfung `link` = none | keep | existing | new.
  Beim Speichern lernt `ProductRepository::upsert()` die Kategorie je normalisiertem Produktnamen.

**Kategorie-Vorschläge:** Transaktion → `suggestForTransaction(payee, purpose)`; Posten → `suggestForItem(name)`.
Neue Stichwörter in `CategoryKeywords` (Schlüssel = Kategoriename aus dem Standardsatz in
`CategoryRepository::seedDefaults()`; bei Posten zählen auch Wortanfang/-ende, längster Treffer gewinnt).

**Prognose:** variable Beträge = Buchungen der letzten N **vollen** Monate ohne `recurring_id` und ohne Umbuchungen,
geteilt durch die verfügbaren Monate, gleichmäßig auf Tage verteilt. Kreditraten fließen nur ein, wenn sie als
Fixkosten angelegt sind (Button auf der Kreditseite).

**Fixkosten ↔ Buchungen:** `transactions.recurring_id` kennzeichnet eine Buchung als Fixkosten (Liste, Filter
„Fixkosten“) und nimmt sie aus dem variablen Ø der Prognose. `RecurrenceService::linkExisting()` ordnet vorhandene
Buchungen ohne Vorlage zu: gleiches Konto + Betrag, Datum ±5 Tage um einen Termin **und** passender Empfänger
(Vorlage oder bereits zugeordnete Buchungen; nur Betrag wäre zu unscharf). Läuft täglich mit `materializeDue()` sowie
nach dem Speichern einer Vorlage. Wird eine Vorlage wieder automatisch gebucht (nur Prognose/pausiert → aktiv),
setzt der Controller `last_booked_date` auf gestern, damit keine alten Termine nachgebucht werden.
**Gegeneintrag:** zwei Vorlagen mit umgekehrtem Betrag auf verschiedenen Konten, gegenseitig über `counterpart_id`
verknüpft (Migration 003); beim Bearbeiten werden Betrag, Bezeichnung, Termine und „Aktiv“ optional übertragen.
Monatssummen der Fixkosten-Übersicht: `RecurrenceService::monthlyTotals()` – mit Kontofilter zählen Umbuchungen über
die Grenze der gewählten Konten als Einnahme/Ausgabe.

## Tests

- `tests/Unit/*Test.php` decken Money, Recurrence, CSV-Import (Fixtures Sparkasse Windows-1252, ING mit Vorspann,
  DKB, generisch), ReceiptTextParser (inkl. echter Tesseract-Ausgabe `rewe_ocr.txt`), Categorization,
  LoanCalculator (gegen geschlossene Annuitätenformel) und Forecast ab. Controller/Views haben keine Unit-Tests.
- `tests/bootstrap.php` lädt Config + Helfer, **keine DB** – Services, die DB brauchen, über ihre statischen
  reinen Funktionen testen.

## Manuelle / Browser-Tests (bewährtes Vorgehen)

1. **HTTP-Smoke-Test mit curl:** Cookie-Jar + CSRF-Token aus einer Seite lesen (`name="_csrf" value="…"`), dann
   Formulare posten; JSON-Endpunkte mit Header `X-CSRF-Token` und `Accept: application/json`. Danach alle GET-Seiten
   abrufen und auf `Fatal error|Warning:|Notice:` prüfen sowie `storage/logs/php-error.log` lesen.
2. **Browser mit JS (Chrome headless):** `C:\Program Files\Google\Chrome\Application\chrome.exe` ist vorhanden.
   - Für angemeldete Seiten eine **temporäre** `public/__devlogin.php` anlegen (nur `REMOTE_ADDR` 127.0.0.1 und
     `APP_ENV=local`, setzt `$_SESSION['user_id']` und leitet weiter) – **nach dem Test wieder löschen**.
   - Screenshots: `--headless=new --screenshot=… --window-size=520,1000 --virtual-time-budget=6000 <url>`
     (Dark Mode: `--force-dark-mode --blink-settings=preferredColorScheme=0`), Bild mit dem Read-Tool ansehen.
   - Web-Worker (Tesseract) laufen mit `--virtual-time-budget` nicht zu Ende → über das DevTools-Protokoll steuern:
     Chrome mit `--remote-debugging-port=… --remote-allow-origins=* --user-data-dir=<eigenes Profil>` starten,
     per Python `websocket-client` (installiert) `Runtime.evaluate` pollen, Dateien mit `DOM.setFileInputFiles`
     setzen, Alpine-Zustand über `Alpine.$data(el)` lesen. Prozess am Ende gezielt mit `Popen.kill()` beenden.
   - Test-Kassenbon als Bild: mit GD + `imagettftext` und `C:/Windows/Fonts/consola.ttf` aus einer Textdatei erzeugen.
3. **Die lokale DB enthält echte Daten** – nicht zurücksetzen. Stattdessen eine Kopie `haushaltsbuch_test` anlegen
   (`mysqldump -uroot haushaltsbuch | mysql -uroot haushaltsbuch_test`) und nur Testanfragen dorthin leiten:
   temporär `public/__test.php` (nur 127.0.0.1; setzt `$_ENV['DB_NAME']`/`putenv`, optional `?__login=<user_id>`,
   dann `require index.php`) plus in `public/.htaccess` direkt nach `RewriteEngine On`:
   `RewriteCond %{HTTP_COOKIE} hbtest=1` / `RewriteCond %{REQUEST_FILENAME} !-f` / `RewriteRule ^ __test.php [L,QSA]`.
   Test-Client mit Cookie `hbtest=1` gegen `http://127.0.0.1/...` (Pythons Cookie-Jar verliert Cookies bei `localhost`).
   Danach `.htaccess` zurücksetzen, `__test.php` löschen, Test-DB droppen und eigene Uploads in `storage/uploads/` entfernen.
