<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;

/**
 * Einlesen von Kontoauszügen im CSV-Format (deutsche Banken).
 * Reine Verarbeitung ohne Datenbank – dadurch testbar.
 *
 * mapping-Felder: date, amount | debit+credit, payee | payee_in+payee_out, purpose, booking_text, counter_iban, own_iban, status
 * Werte sind Spaltenüberschriften (Groß-/Kleinschreibung egal) oder Spaltennummern ("#3").
 */
final class CsvImportService
{
    public const FIELDS = [
        'date'         => 'Buchungsdatum',
        'amount'       => 'Betrag',
        'debit'        => 'Betrag Soll/Ausgang',
        'credit'       => 'Betrag Haben/Eingang',
        'payee'        => 'Empfänger / Auftraggeber',
        'payee_in'     => 'Auftraggeber (bei Eingängen)',
        'payee_out'    => 'Empfänger (bei Ausgängen)',
        'purpose'      => 'Verwendungszweck',
        'booking_text' => 'Buchungstext / Umsatzart',
        'counter_iban' => 'IBAN Gegenkonto',
        'own_iban'     => 'IBAN eigenes Konto',
        'status'       => 'Status (vorgemerkt)',
    ];

    /** Datei lesen, Kodierung vereinheitlichen (UTF-8), BOM entfernen */
    public static function readFile(string $path): string
    {
        return self::normalizeEncoding((string) file_get_contents($path));
    }

    public static function normalizeEncoding(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    public static function detectDelimiter(string $content): string
    {
        $sample = implode("\n", array_slice(explode("\n", $content), 0, 30));
        $counts = [];
        foreach ([';', ',', "\t", '|'] as $d) {
            $counts[$d] = substr_count($sample, $d);
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /** @return array<int, string[]> */
    public static function parse(string $content, string $delimiter): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $row);
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Sucht die Kopfzeile, in der die Pflichtspalten des Mappings vorkommen.
     * @return array{index:int, columns:array<string,int>}|null
     */
    public static function locateHeader(array $rows, array $mapping): ?array
    {
        $required = array_filter([$mapping['date'] ?? null, $mapping['amount'] ?? ($mapping['debit'] ?? null)]);
        if (!$required) {
            return null;
        }
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $lower = array_map(fn ($c) => mb_strtolower(trim($c)), $row);
            $ok = true;
            foreach ($required as $col) {
                if (!str_starts_with($col, '#') && !in_array(mb_strtolower($col), $lower, true)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $columns = [];
            foreach ($mapping as $field => $col) {
                if ($col === null || $col === '') {
                    continue;
                }
                if (str_starts_with((string) $col, '#')) {
                    $columns[$field] = (int) substr((string) $col, 1);
                    continue;
                }
                $idx = array_search(mb_strtolower((string) $col), $lower, true);
                if ($idx !== false) {
                    $columns[$field] = (int) $idx;
                }
            }
            return ['index' => $i, 'columns' => $columns];
        }
        return null;
    }

    /**
     * Wählt aus den Profilen dasjenige, dessen Spalten am besten passen.
     * @param array<int, array{id:int, name:string, mapping:string|array}> $profiles
     */
    public static function detectProfile(array $rows, array $profiles): ?array
    {
        $best = null;
        $bestScore = 0;
        foreach ($profiles as $p) {
            $mapping = is_array($p['mapping']) ? $p['mapping'] : (json_decode($p['mapping'], true) ?: []);
            $h = self::locateHeader($rows, $mapping);
            if (!$h) {
                continue;
            }
            $score = count($h['columns']) * 10 - (count($mapping) - count($h['columns'])) * 3;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $p + ['mapping_array' => $mapping];
            }
        }
        return $best;
    }

    /**
     * Rät die Spaltenzuordnung anhand typischer Überschriften, wenn kein Profil passt.
     * @return array<string,string> field => Spaltenname
     */
    public static function guessMapping(array $rows): array
    {
        $patterns = [
            'date'         => '/^(buchungstag|buchungsdatum|buchung|datum|date|booking date|belegdatum)$/i',
            'amount'       => '/^(betrag|umsatz|amount|betrag \(€\)|betrag \(eur\)|umsatz in eur|betrag in eur|amount \(eur\))$/iu',
            'debit'        => '/^(soll|ausgang|belastung|debit|lastschrift)$/i',
            'credit'       => '/^(haben|eingang|gutschrift|credit)$/i',
            'payee'        => '/(empfänger|auftraggeber|begünstigter|beguenstigter|zahlungsbeteiligter|name|partner|payee|gegenpartei)/iu',
            'purpose'      => '/(verwendungszweck|zweck|reference|beschreibung|description|details|memo)/i',
            'booking_text' => '/^(buchungstext|umsatzart|vorgang|transaktionstyp|type|umsatztyp)$/i',
            'counter_iban' => '/^(iban|kontonummer\/iban|iban zahlungsbeteiligter|partner iban|gegenkonto)$/i',
            'status'       => '/^(status|info)$/i',
        ];
        $bestRow = null;
        $bestHits = 0;
        foreach (array_slice($rows, 0, 40) as $row) {
            $hits = 0;
            foreach ($row as $cell) {
                foreach ($patterns as $re) {
                    if (preg_match($re, trim($cell))) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $bestRow = $row;
            }
        }
        if (!$bestRow || $bestHits < 2) {
            return [];
        }
        $mapping = [];
        foreach ($patterns as $field => $re) {
            foreach ($bestRow as $cell) {
                if (preg_match($re, trim($cell)) && !in_array($cell, $mapping, true)) {
                    $mapping[$field] = $cell;
                    break;
                }
            }
        }
        if (isset($mapping['amount'])) {
            unset($mapping['debit'], $mapping['credit']);
        }
        return $mapping;
    }

    /**
     * Wandelt die Datenzeilen in Buchungssätze.
     * @return array<int, array{row:int, date:string, amount:string, cents:int, payee:string, purpose:string, counter_iban:string, own_iban:string, pending:bool}>
     */
    public static function records(array $rows, array $header, string $decimalSep = ','): array
    {
        $c = $header['columns'];
        $get = fn (array $row, string $f) => isset($c[$f]) ? trim((string) ($row[$c[$f]] ?? '')) : '';
        $out = [];
        foreach ($rows as $i => $row) {
            if ($i <= $header['index'] || count(array_filter($row, fn ($v) => $v !== '')) < 2) {
                continue;
            }
            $date = self::parseDate($get($row, 'date'));
            if (!$date) {
                continue;
            }
            if (isset($c['amount'])) {
                $cents = self::parseAmount($get($row, 'amount'), $decimalSep);
            } else {
                $debit = self::parseAmount($get($row, 'debit'), $decimalSep);
                $credit = self::parseAmount($get($row, 'credit'), $decimalSep);
                $cents = ($credit !== null ? abs($credit) : 0) - ($debit !== null ? abs($debit) : 0);
                if ($debit === null && $credit === null) {
                    $cents = null;
                }
            }
            if ($cents === null || $cents === 0) {
                continue;
            }
            $payee = $get($row, 'payee');
            if ($payee === '') {
                $payee = $cents < 0 ? $get($row, 'payee_out') : $get($row, 'payee_in');
            }
            $purpose = preg_replace('/\s+/u', ' ', $get($row, 'purpose'));
            $bookingText = $get($row, 'booking_text');
            if ($payee === '' && $purpose === '' && $bookingText !== '') {
                $purpose = $bookingText;
            }
            $out[] = [
                'row'          => $i,
                'date'         => $date,
                'cents'        => $cents,
                'amount'       => Money::toDecimal($cents),
                'payee'        => mb_substr($payee, 0, 190),
                'purpose'      => $purpose,
                'booking_text' => $bookingText,
                'counter_iban' => strtoupper(str_replace(' ', '', $get($row, 'counter_iban'))),
                'own_iban'     => strtoupper(str_replace(' ', '', $get($row, 'own_iban'))),
                'pending'      => (bool) preg_match('/vorgemerkt|pending|nicht gebucht/i', $get($row, 'status')),
            ];
        }
        return $out;
    }

    /**
     * Eindeutiger Schlüssel je Buchung für die Duplikaterkennung.
     * Identische Zeilen in derselben Datei werden durchnummeriert, damit sie nicht zusammenfallen.
     */
    public static function assignHashes(int $accountId, array $records): array
    {
        $seen = [];
        foreach ($records as &$r) {
            $norm = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $r['payee'] . '|' . $r['purpose']));
            $base = $accountId . '|' . $r['date'] . '|' . $r['cents'] . '|' . mb_substr($norm, 0, 120);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $r['hash'] = sha1($base . '|' . $seen[$base]);
        }
        return $records;
    }

    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        return null;
    }

    public static function parseAmount(string $s, string $decimalSep = ','): ?int
    {
        $s = trim(str_replace(['EUR', '€', "\u{00A0}", ' '], '', $s));
        if ($s === '') {
            return null;
        }
        if ($decimalSep === '.') {
            $s = str_replace(',', '', $s);
            return is_numeric($s) ? (int) round((float) $s * 100) : null;
        }
        // Soll/Haben-Kennzeichen ("12,50 S" / "12,50 H")
        if (preg_match('/^(.*?)(S|H)$/i', $s, $m)) {
            $v = Money::parse($m[1]);
            return $v === null ? null : (strtoupper($m[2]) === 'S' ? -abs($v) : abs($v));
        }
        return Money::parse($s);
    }
}
