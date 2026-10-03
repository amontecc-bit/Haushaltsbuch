# Haushaltsbuch

Web-App für die Haushaltsführung der ganzen Familie: Konten, Buchungen, Fixkosten, CSV-Import von Kontoauszügen,
Einkäufe mit Einzelposten (von Hand, per PDF oder per Foto vom Kassenbon), automatische Kategorisierung,
Auswertungen, Prognose der Kontostände und Kreditverwaltung. Läuft im Browser auf Desktop und Smartphone
und lässt sich auf dem Handy als App installieren (PWA).

**Bedienungsanleitung mit Screenshots:** [docs/ANLEITUNG.md](docs/ANLEITUNG.md)

**Technik:** PHP 8.1+ (eigenes, schlankes MVC), MySQL/MariaDB, Bootstrap 5, Alpine.js, Chart.js.
Alle Frontend-Bibliotheken liegen lokal unter `public/assets/vendor/`. Es wird kein CDN benötigt.

## Funktionen

| Bereich | Was geht |
|---|---|
| **Familie** | Ein Haushalt, Rollen *Administrator*, *Mitglied* und *Kind*. Pro Konto einstellbar, wer es **sehen** bzw. **bebuchen** darf. |
| **Konten** | Giro, Spar, Bargeld, Kreditkarte usw. mit Anfangssaldo, IBAN und Farbe. |
| **Buchungen** | Ausgabe, Einnahme oder Umbuchung. Schnell-Eingabe mit Kategorie-Vorschlag, „Speichern & weitere“, Filter wie bei den Fixkosten (Konten als Mehrfachauswahl, Kategorie, Art, Person; Zeitraum wie in den Auswertungen – Monat, 3/6/12 Monate, Jahr oder frei; Liste und Summen folgen dem Filter), Mehrfachauswahl (kategorisieren, anderem Konto zuweisen, als Fixkosten übernehmen, löschen). Buchungen, die zu Fixkosten gehören, sind gekennzeichnet. |
| **Fixkosten** | Monatlich, vierteljährlich, halbjährlich oder jährlich. Fällige Termine werden automatisch gebucht (ohne Cronjob). Alternativ „nur Prognose“, wenn die Buchungen per CSV kommen. Filter nach Konten (Mehrfachauswahl), Kategorie, Art und Status – die Monatssummen folgen dem Filter. Sammelaktionen (nur Prognose / automatisch, pausieren, anderem Konto zuweisen) und Gegeneintrag auf einem anderen Konto (z. B. Haushaltsgeld). |
| **CSV-Import** | Profile für Sparkasse, ING, DKB, Volksbank/Raiffeisen, comdirect, Commerzbank, Postbank und N26, plus eigene Spaltenzuordnung. Erkennt Duplikate, vorgemerkte Umsätze und Buchungen aus Daueraufträgen (führt sie zusammen) und schlägt Kategorien vor. Umbuchungen zwischen eigenen Konten werden an der IBAN des Gegenkontos (sonst an der gegenläufigen Buchung) erkannt: vorhandene Gegenbuchung wird verknüpft, fehlende angelegt und beim Import des anderen Kontos zusammengeführt. |
| **Einkäufe** | Posten von Hand, per **Foto** (Kamera direkt in der App, mehrere Fotos für lange Bons) oder per **PDF** (Rechnung/Einkaufsliste, auch gescannt). Erkennung **lokal im Browser** (Tesseract.js, kostenlos) oder per **KI** (Claude API). Verknüpfbar mit einer Kontobuchung. |
| **Einkaufsliste** | Listen aus Artikeln der bisherigen Einkäufe und neuen Posten, mit Menge/Einheit, bisherigem Min-/Max-Preis und günstigstem Laden, nach Kategorie gruppiert. **Virtuelle Posten** (z. B. „Milch“) fassen gleiche Artikel aus verschiedenen Läden zusammen; Zuordnungsseite mit allen noch nicht zugeordneten Artikeln und Drag & Drop. Druckansicht bzw. PDF. Nach dem Erfassen eines Einkaufs werden gekaufte Posten automatisch abgehakt. |
| **Kategorien** | Zweistufig, gelten für Buchungen und Posten. Automatische Zuordnung in dieser Reihenfolge: eigene Regeln → Gelerntes (gleicher Empfänger, bekanntes Produkt, ähnliches Produkt) → eingebaute Stichwörter. Korrekturen werden gelernt. |
| **Auswertungen** | Ausgaben nach Kategorie mit Drilldown, Monatsverlauf, gestapelt nach Kategorien, Top-Empfänger, Sparquote. Mit Einkäufen verknüpfte Buchungen werden nach Posten aufgeschlüsselt. Bei Filter auf ein Konto zählen Umbuchungen als Einnahme/Ausgabe (eigene Zeile „Umbuchungen“), damit die Bilanz des Kontos stimmt; über alle Konten bleiben sie außen vor. Einzelposten-Analyse mit Kategorie-Pfad, Korrektur falsch zugeordneter Artikel und Preisverlauf je Produkt. CSV-Export. |
| **Prognose** | Kontostände für 3 bis 36 Monate aus Fixkosten und dem Ø der variablen Ausgaben der letzten Monate (Aufschlüsselung je Konto; importierte Umbuchungen zwischen eigenen Konten werden erkannt, Einmaleffekte lassen sich über Kategorien ausklammern). **Szenarien:** je Konto einen eigenen variablen Monatswert festlegen (leer = berechneter Ø); der berechnete Ø läuft als gestrichelte Vergleichslinie mit. Warnt, wenn der Saldo negativ wird. |
| **Kredite** | Annuitäten- und Tilgungsdarlehen, Sondertilgungen, Zins- und Ratenänderungen (Anschlussfinanzierung). Restschuld zu **jedem beliebigen Datum**, Tilgungsplan, Jahresübersicht. Rate optional als Fixkosten für die Prognose. |

## Lokal starten (XAMPP)

```bash
composer install
cp .env.example .env          # Zugangsdaten prüfen (XAMPP: root ohne Passwort)
php bin/migrate.php           # legt die Datenbank „haushaltsbuch“ samt Tabellen an
```

Danach im Browser **http://localhost/Haushaltsbuch/** öffnen. Beim ersten Aufruf erscheint die Ersteinrichtung:
Dort legst du den Haushalt, dein Administrator-Konto und das erste Bankkonto an.

Tests: `php vendor/bin/phpunit`

## Auf einen Webhoster bringen

1. Lokal `composer install --no-dev --optimize-autoloader` ausführen und alles hochladen (inkl. `vendor/`).
2. **Webroot** möglichst auf den Ordner `public/` setzen. Geht das nicht, leitet die `.htaccess` im Hauptordner automatisch dorthin um.
   Die Ordner `app/`, `config/`, `storage/`, `vendor/` usw. sind zusätzlich per `.htaccess` gesperrt.
3. Datenbank beim Hoster anlegen, dann `.env` anpassen:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   DB_HOST=…
   DB_NAME=…
   DB_USER=…
   DB_PASS=…
   ```
   Liegt die App in einem Unterordner und wird dieser nicht automatisch erkannt: `APP_BASE_URL=/unterordner`.
4. Die Seite aufrufen. Die Tabellen werden beim ersten Aufruf automatisch angelegt, danach folgt die Ersteinrichtung.
5. **HTTPS ist Pflicht**, denn der Kamerazugriff im Handy-Browser funktioniert nur über HTTPS.
6. `storage/` muss für PHP beschreibbar sein (Belege, Importe, Logs). Für Fotos `upload_max_filesize`/`post_max_size` ≥ 16 MB.

Voraussetzungen: PHP ≥ 8.1 mit `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `gd`, außerdem Apache mit `mod_rewrite`
(bei nginx alle Anfragen, die keine Datei sind, auf `public/index.php` leiten).

## KI-Erkennung von Kassenbons (optional)

Unter *Einstellungen* einen Anthropic-API-Schlüssel hinterlegen (oder `ANTHROPIC_API_KEY` in `.env`) und den Modus „KI“ wählen.
Standardmodell ist `claude-opus-5`, mit serverseitigem Fallback bei Ablehnungen. Das Modell lässt sich in den Einstellungen ändern,
z. B. auf `claude-sonnet-5` oder `claude-haiku-4-5` für geringere Kosten. Bilder bzw. PDFs gehen dabei an die Anthropic-API.
Bei der lokalen Erkennung verlassen sie das Gerät nicht. Beim Erfassen kann jederzeit zwischen *Lokal* und *KI* gewechselt werden.
In jedem Fall landen die erkannten Posten zuerst in einer Prüfmaske. Weicht die Summe der Posten vom Beleg ab, wird das angezeigt.

## Aufbau

```
app/Core/          Router, Request, View, Session, CSRF, Auth, Money
app/Controllers/   ein Controller je Bereich
app/Repositories/  Datenbankzugriff (PDO, Prepared Statements, immer nach Haushalt gefiltert)
app/Services/      Fachlogik: RecurrenceService, CsvImportService, CategorizationService,
                   ReceiptTextParser, AiReceiptRecognizer, ReportService, ForecastService, LoanCalculator
app/Views/         PHP-Templates
database/migrations/  SQL-Migrationen (php bin/migrate.php)
public/            Webroot: index.php, Assets, Manifest, Service Worker
storage/           Uploads (Belege), temporäre Importe, Logs – nicht öffentlich
tests/             PHPUnit-Tests + Beispiel-CSVs und Bontexte
```

Geldbeträge werden als `DECIMAL(12,2)` gespeichert und in PHP in Cent gerechnet.
Kredite rechnen monatlich nach der Zinsmethode 30/360; die erste Periode ab Auszahlung wird taggenau berechnet.
