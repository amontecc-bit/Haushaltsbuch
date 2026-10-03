# CLAUDE.md – Haushaltsbuch

Arbeitsgrundlage für die Weiterentwicklung. Endnutzer-Doku (Funktionen, Deployment) steht in `README.md`,
technische Details zu Abläufen und Tests in `docs/DEVELOPMENT.md`, die Bedienungsanleitung mit Screenshots in
`docs/ANLEITUNG.md` (bei sichtbaren UI-Änderungen Text und Bilder mitziehen, siehe `docs/DEVELOPMENT.md`).

## Projekt in Kürze

Haushaltsbuch-Web-App für eine Familie: Konten, Buchungen, Fixkosten, CSV-Import, Einkäufe mit Einzelposten
(Hand/PDF/Kassenbon-Foto, lokale OCR oder Claude API), Kategorisierung mit Lernen, Auswertungen, Prognose, Kredite.
**UI-Sprache und Code-Kommentare: Deutsch.** Bezeichner (Klassen, Methoden, Spalten) Englisch.

- PHP 8.3 (Minimum 8.1), eigenes schlankes MVC, **kein Framework**. Composer nur für: `smalot/pdfparser`,
  `vlucas/phpdotenv`, `anthropic-ai/sdk` + `guzzlehttp/guzzle`, dev `phpunit/phpunit`.
- MariaDB 10.4 (XAMPP), Frontend: Bootstrap 5.3, Alpine.js 3, Chart.js 4, Tesseract.js 5, pdf.js 4 – **alle lokal** in
  `public/assets/vendor/`, kein CDN (Zielbetrieb: öffentlicher Webhoster mit HTTPS).
- Lokal: `C:\xampp8\htdocs\Haushaltsbuch`, erreichbar unter `http://localhost/Haushaltsbuch/`.
  Remote: `https://github.com/amontecc-bit/Haushaltsbuch` (Branch `main`).

## Befehle

```bash
composer install
php bin/migrate.php                 # DB anlegen + Migrationen (idempotent)
php vendor/bin/phpunit              # Unit-Tests (tests/Unit)
for f in $(find app bin config public tests -name "*.php"); do php -l $f >/dev/null || php -l $f; done
C:\xampp8\mysql\bin\mysql.exe -uroot haushaltsbuch   # DB-Konsole (root ohne Passwort)
```

DB komplett zurücksetzen: `php bin/reset.php` (Rückfrage mit DB-Namen, `--yes` ohne Rückfrage, `--keep-uploads`
behält Belege; löscht alle Tabellen + `storage/uploads`, migriert neu) – danach führt
`/setup` durch die Ersteinrichtung. Fehlerlog: `storage/logs/php-error.log`.
Node.js ist **nicht** installiert; JS lässt sich nur im Browser prüfen (siehe `docs/DEVELOPMENT.md`).

## Architektur

```
public/index.php        Front-Controller → app/bootstrap.php → app/routes.php → Router::dispatch
app/Core/               Router, Request, Response, View, Session, Csrf, Auth, Money, Config, Database
app/Controllers/        ein Controller je Bereich, erben von Controller (view/redirect/back/json/notFound)
app/Repositories/       PDO-Zugriff, erben von Repository (one/many/value/exec/insert/updateWhere/in/transaction)
app/Services/           Fachlogik (siehe unten) – möglichst reine, testbare statische Funktionen
app/Views/              PHP-Templates; layout.php (App), layout_guest.php (Login/Setup), partials/
app/helpers.php         e(), url(), asset(), money(), money_input(), date_de(), csrf_field(), selected(), …
database/migrations/    NNN_name.sql, werden in Reihenfolge einmalig ausgeführt (Tabelle schema_migrations)
storage/                uploads/ (Belege: purchases/{id}/n.ext, tmp/{token}/, import/), logs/ – nicht öffentlich
```

**Routing:** `app/routes.php`, Muster `/pfad/{id}` (nur Ziffern), drittes Argument `'guest' | 'user' | 'admin'`.
Parameter werden positionsweise an die Action übergeben. Jeder POST wird per CSRF geprüft (`_csrf`-Feld oder
Header `X-CSRF-Token`); `HB.post()` in `public/assets/js/app.js` setzt den Header automatisch und sendet JSON oder FormData.

**Services:**
| Service | Aufgabe |
|---|---|
| `RecurrenceService` | Termine wiederkehrender Buchungen (Monatsende-Clamping), `materializeDue()` bucht Fälliges – wird 1× pro Tag/Sitzung im `Controller`-Konstruktor ausgelöst (kein Cron) |
| `TransactionService` | Anlegen inkl. Umbuchungen (2 Buchungen mit gleicher `transfer_group`); seitenbezogene Felder (`import_hash`, `import_batch_id`, `recurring_id`) getrennt je Seite; `joinAsTransfer()` verbindet zwei vorhandene Buchungen |
| `CsvImportService` | Kodierung, Trennzeichen, Kopfzeilen-Suche, Profil-Erkennung, Datensätze, stabile Hashes (Duplikate, Hash enthält die Konto-ID), `sameAccount()` (IBAN/alte Kontonummer → eigenes Konto = Umbuchung), `isOwnHash()` |
| `CategorizationService` + `CategoryKeywords` | Vorschläge: eigene Regeln → Historie/bekanntes Produkt → ähnliches Produkt → Stichwörter |
| `ReceiptTextParser` | Bontext/PDF-Text → Geschäft, Datum, Summe, Posten; toleriert OCR-Fehler, heilt Einzelziffern über die Summe; mehrere Fotos (``-getrennt) werden einzeln gelesen und überlappend zusammengeführt; mit `$ocr = true` Platzhalter (`missing`) für unlesbare Zeilen und `suspect` für geratene/unplausible Preise; kennt EDEKA/Marktkauf-PDF-Bons und REWE-Onlinerechnungen |
| `AiReceiptRecognizer` | Claude API (offizielles PHP-SDK, Beta-Messages mit `fallbacks: 'default'`), JSON-Schema-Ausgabe; `effort` nur bei Modellen, die ihn kennen (`supportsEffort()`, sonst automatische Wiederholung ohne) |
| `ReportService` | Summen/Kategorien/Monatsverläufe; mit Einkauf verknüpfte Buchungen optional nach Posten aufgeteilt; Umbuchungen nur bei Kontofilter |
| `ForecastService` | Prognose: Saldo heute + Fixkosten-Termine + variabler Monatssaldo je Konto – Ø (ohne `recurring_id`/Umbuchungen/erkannte Import-Paare/ausgeklammerte Kategorien) oder Handwert aus einem Szenario |
| `LoanCalculator` | Tilgungsplan (30/360, erste Periode taggenau), Sondertilgungen, Zins-/Ratenänderungen, `balanceAt()` |
| `Migrator` | führt `database/migrations/*.sql` aus (auch automatisch beim ersten Seitenaufruf/Setup) |

## Konventionen (bitte einhalten)

- **Mandantentrennung:** Jede Abfrage filtert auf `household_id` (`$this->hid` im Controller). Konten-Sichtbarkeit
  immer über `Auth::accountIds('view'|'book')` bzw. `Auth::can()/authorize()`. Admins sehen alles; Einkäufe ohne Konto
  sind für alle im Haushalt sichtbar.
- **Geld:** DB `DECIMAL(12,2)`, in PHP in **Cent (int)** rechnen: `Money::parse()` (Benutzereingabe, dt./engl. Format),
  `Money::toCents()` (DB-Wert), `Money::toDecimal()` (zurück in die DB), Anzeige `money($dbWert)` bzw. `money($cent, true)`.
  Ausgaben sind negativ gespeichert.
- **Kategorie-IDs** aus Formularen immer durch `CategoryRepository::validId()` schicken.
- **Views:** Ausgabe immer mit `e()`. JSON für Alpine: `x-data="fn(<?= e(json_encode($x)) ?>)"`.
  Seitentitel `$title`, mobiler Zurück-Pfeil `$back`, Buttons in der Kopfzeile `$actions` (HTML-String),
  zusätzliche Skripte `$scripts` (Pfade relativ zu `public/assets/`).
- **Formulare mobil-freundlich:** `inputmode="decimal"` für Beträge, native `<select>` (Partial
  `partials/category_select.php`), wenige Pflichtfelder, Hauptaktion groß.
- **Neue DB-Änderungen** nur als neue Migrationsdatei `database/migrations/003_….sql` (bestehende nie ändern –
  sie sind auf Installationen bereits gelaufen). Statements mit `;` am Zeilenende trennen. **Nur SQL, das MySQL 8
  und MariaDB beide können** – kein `ADD COLUMN IF NOT EXISTS` o. ä.; „existiert schon“-Fehler überspringt der `Migrator`.
- **Diagramme** (Chart.js): vor Änderungen den `dataviz`-Skill beachten. Farben aus `HB.series(i)` (validierte Palette,
  feste Reihenfolge, max. 8 Serien, Rest „Übrige“ = `HB.otherColor()`), `HB.chartDefaults()` aufrufen, keine zweite
  y-Achse, Legende ab 2 Serien, Tooltips an. Kategorien/Konten tragen eigene Farben (Farbe folgt der Entität).
- **Claude API:** offizielles SDK statt rohem curl; Standardmodell `claude-opus-5` (in Einstellungen/`.env` änderbar).
  Vor Änderungen am `AiReceiptRecognizer` den `claude-api`-Skill laden.

## Stolperfallen (bereits einmal passiert)

- **Alpine `x-show` + Bootstrap `d-flex`/`d-grid`:** Bootstrap setzt `display … !important` und hebelt `x-show` aus.
  Gelöst global in `app.css` (`[x-show][style*="display: none"] { display:none !important }`) – nicht entfernen.
- **HTTP 419 nicht verwenden:** Apache macht daraus 500. CSRF-Fehler liefern 403.
- **`substr` auf Umlauten** zerstört UTF-8 (z. B. „März“) – `mb_substr` verwenden.
- **Zeilenenden:** `.gitattributes` erzwingt LF; `tests/fixtures/**` und `public/assets/vendor/**` sind `-text`
  (die Test-PDF enthält Byte-Offsets, die CSVs absichtlich Windows-1252).
- **Tesseract-Sprachdaten** `deu.traineddata.gz` dürfen nicht mit `Content-Encoding: gzip` ausgeliefert werden
  (`public/.htaccess` regelt das).
- **`public/.htaccess` ohne `RewriteBase`:** Das relative Ziel `index.php` führte bei IONOS zu einem Apache-500er bei
  allen Routen außer `/` (Setup/Login nicht erreichbar). Die Basis wird jetzt per `%{ENV:BASE}` selbst ermittelt
  (funktioniert im Domain-Root, im Unterordner und unter XAMPP) – nicht auf `RewriteRule ^ index.php` zurückbauen.
- **Server-Zugangsdaten** (`.env`, `.env.ionos` o. ä.) nie committen – `.gitignore` schließt `/.env.*` außer `.env.example` aus.
- **Bash-Heredocs mit viel PHP-Quoting** sind fehleranfällig – PHP-Dateien lieber mit dem Write-Tool schreiben.
- **Lokale DB `haushaltsbuch` enthält echte Daten der Familie** – nie zurücksetzen oder mit Testdaten füllen.
  Tests gegen eine Kopie (`mysqldump haushaltsbuch | mysql haushaltsbuch_test`), siehe `docs/DEVELOPMENT.md`.
- **`import_hash` gehört immer nur zu einer Umbuchungsseite** (der Hash enthält die Konto-ID). Früher bekam beim
  Bearbeiten einer Umbuchung auch die Gegenseite den Hash – dann erkannte der Import des anderen Kontos sie nicht mehr.
  `matchesForImport()` lässt solche Altlasten deshalb noch zu (Prüfung per `CsvImportService::isOwnHash()`).
- Headless-Chrome hat eine Mindestbreite von ~500 px (Screenshots für „mobil“ mit 520 px machen).
- **Beim Beenden von Test-Chrome nie `taskkill /IM chrome.exe`** – das schließt auch die Browserfenster des Nutzers.
  Nur den eigenen Prozess beenden (`Popen.kill()` bzw. nach eigenem `--user-data-dir` filtern).

## Status / mögliche nächste Schritte

Alle geplanten Module sind umgesetzt und getestet (79 PHPUnit-Tests; Browser-Durchlauf Foto → lokale OCR → Speichern).
Noch nicht real getestet: Kamera auf echtem Smartphone (braucht HTTPS), KI-Erkennung mit echtem API-Schlüssel.
Ideen: Budgets je Kategorie mit Warnung, Sparziele, Bearbeiten von CSV-Profilen in der Oberfläche,
Konten-Export/Backup, E-Mail-Einladung für Familienmitglieder, Zwei-Faktor-Login.
