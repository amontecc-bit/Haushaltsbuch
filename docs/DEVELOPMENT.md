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
- Foto: Bild wird im Browser auf ≤2400 px JPEG verkleinert; lokal → `ocrCanvas()` (Graustufen, Beleuchtung
  ausgleichen, Kontrast strecken, auf das Papier zuschneiden, Hintergrund weiß) → Tesseract.js (`deu`, PSM 4) →
  Text + Bilder an `POST /purchases/recognize` (`mode=local`); KI → nur Bilder (`mode=ai`). Die Texte mehrerer
  Fotos sind durch `` getrennt; `ReceiptTextParser` liest jedes Foto einzeln und führt die Überlappung
  zusammen (LCS über ähnliche Posten). Ohne Zuschnitt erzeugt der Untergrund Buchstabenmüll am Zeilenende,
  an dem die Preiserkennung scheitert (Test mit 3 Fotos eines Aldi-Bons: vorher 1, nachher 31 von 48 Posten).
  OCR-Text wird mit `parse($text, true)` gelesen: Postenzeilen ohne lesbaren Preis werden Platzhalter
  (`total_price = null`, `missing`), halb lesbare Preise („3'00“) Vorschläge mit `suspect`; Preise ab Bonsumme,
  Ausreißer und „PFANDWERT 1,50“ = 15,00 werden markiert; eine verlesene Summenzeile („AHLEN 115,51“) beendet die
  Posten. `ocr` enthält bei markierten Posten die gelesene Zeile. Das Formular zeigt „x von y Posten erkannt“.
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
geteilt durch die verfügbaren Monate, gleichmäßig auf Tage verteilt. Ebenfalls nicht gezählt: Kategorien mit
`exclude_from_forecast` (Migration 004, wirkt über die Hauptkategorie auf Unterkategorien) und erkannte Umbuchungspaare
aus dem CSV-Import (Gegenbuchung mit umgekehrtem Betrag auf anderem Prognose-Konto, ±3 Tage, Gegenseite weder
Umbuchung noch Fixkosten) – sonst zählt z. B. eine Fixkosten-Umbuchung doppelt. `ForecastService::run()` liefert
dazu `breakdown` je Konto (Hauptkategorien + nicht gezählte Beträge) für die Ansicht. Kreditraten fließen nur ein, wenn sie als
Fixkosten angelegt sind (Button auf der Kreditseite).

**Fixkosten ↔ Buchungen:** `transactions.recurring_id` kennzeichnet eine Buchung als Fixkosten (Liste, Filter
„Fixkosten“) und nimmt sie aus dem variablen Ø der Prognose. `RecurrenceService::linkExisting()` ordnet vorhandene
Buchungen ohne Vorlage zu (`pickMatches()`, rein/getestet): gleiches Konto, Datum ±5 Tage um einen Termin im Rhythmus
der Vorlage, je Termin höchstens eine Buchung. Mit bekanntem Empfänger (Vorlage oder bereits zugeordnete Buchungen)
muss dieser passen, der Betrag darf ±15 % abweichen und es wird bis 24 Monate vor das Startdatum geschaut (Vorlagen
entstehen oft nachträglich – sonst zählten die älteren Buchungen im Prognose-Ø doppelt). Ohne Empfänger: centgenau,
ab Start. Umbuchungs-Vorlagen: beide Konten (−/+), Empfänger egal, centgenau, mit Rückblick. Läuft täglich mit `materializeDue()` sowie
nach dem Speichern einer Vorlage. Wird eine Vorlage wieder automatisch gebucht (nur Prognose/pausiert → aktiv),
setzt der Controller `last_booked_date` auf gestern, damit keine alten Termine nachgebucht werden.
**Gegeneintrag:** zwei Vorlagen mit umgekehrtem Betrag auf verschiedenen Konten, gegenseitig über `counterpart_id`
verknüpft (Migration 003); beim Bearbeiten werden Betrag, Bezeichnung, Termine und „Aktiv“ optional übertragen.
Monatssummen der Fixkosten-Übersicht: `RecurrenceService::monthlyTotals()` – mit Kontofilter zählen Umbuchungen über
die Grenze der gewählten Konten als Einnahme/Ausgabe. Gegeneinträge (beide Seiten aktiv) zählen wie Umbuchungen:
ohne Filter gar nicht, mit Filter nur, wenn das Konto des Gegenstücks nicht mitgewählt ist.
**Prognose-Szenarien** (Migration 005, `forecast_scenarios` + `forecast_scenario_values`): je Konto ein Handwert für
den variablen Monatssaldo; Konto ohne Eintrag = berechneter Ø (`ForecastService::effectiveVariable()`, rein/getestet).
`run(..., $manual)` liefert zusätzlich `computed` (Ø), `manual` und `baseline` (Gesamtverlauf nur mit Ø, für die
Vergleichslinie; `null`, wenn das Szenario nichts ändert). Gewähltes Szenario merkt sich die Sitzung (`forecast_scenario`).
Beim Speichern werden nur die für den Nutzer sichtbaren Prognose-Konten ersetzt, Werte anderer Konten bleiben.
Auswertungen (`ReportService`): ohne Kontofilter keine Umbuchungen; mit Kontofilter zählen sie mit, in den
Kategorie-Auswertungen unter der Pseudo-ID `ReportService::TRANSFER_ID` (-2, „Umbuchungen“).

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

## Bedienungsanleitung und Screenshots

`docs/ANLEITUNG.md` ist die Anleitung für Endnutzer, die Bilder liegen in `docs/anleitung/*.png`. Sie stammen aus der
Demo-Datenbank `haushaltsbuch_demo` (Familie Muster, ausgedachte Zahlen) – **nie Screenshots aus der echten DB**.

1. `php bin/demo_seed.php` – legt `haushaltsbuch_demo` komplett neu an (12 Monate Buchungen relativ zu heute, Fixkosten,
   Einkäufe, Kredit, Szenario, Regeln; Anmeldung `anna@example.org` / `demo1234`). Bricht ab, falls die DB anders hieße.
2. Temporär `public/__test.php` (wie oben unter 3., fest auf `haushaltsbuch_demo`, `?__login=1`) und die
   Cookie-Umleitung in `public/.htaccess` einbauen.
3. `python bin/screenshots.py [name …]` – steuert Chrome headless per DevTools (eigenes Profil, Prozess wird gezielt
   beendet), Desktop 1280 px und Handy 390 px (@2x), Diagramm-Animationen aus. Liste der Bilder in `SHOTS`.
   Für die Import-Vorschau `HB_IMPORT_CSV=<Sparkasse-CSV>` setzen (Konto 1, Datei mit IBAN `DE02120300000000202051`).
4. Danach `.htaccess` zurücksetzen, `__test.php` löschen und die hochgeladene CSV aus `storage/uploads/import/` entfernen.
