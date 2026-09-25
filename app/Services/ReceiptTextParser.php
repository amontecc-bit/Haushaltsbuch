<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;

/**
 * Liest Einkaufsposten aus dem Text eines Kassenbons (OCR) oder einer PDF-Rechnung/Einkaufsliste.
 *
 * Erkennt u. a.:
 *   "BANANE              1,49 B"        → Posten mit Preis und Steuerklasse
 *   "  2 Stk x 0,75"                   → Menge/Einzelpreis zum vorherigen Posten
 *   "0,576 kg x 2,99 EUR/kg"           → Gewicht zum vorherigen bzw. folgenden Posten
 *   "Rabatt / Preisvorteil   -0,50"     → Abzug beim vorherigen Posten
 *   "2 x Milch 1,09 2,18" / "Milch 2 1,09 2,18"  → Tabellenzeilen aus PDFs
 *   "SUMME EUR 23,45" / "zu zahlen"     → Gesamtbetrag
 */
final class ReceiptTextParser
{
    private const STORES = [
        'REWE' => '/\brewe\b/i', 'EDEKA' => '/\bedeka\b|\bmarktkauf\b/i', 'ALDI' => '/\baldi\b/i', 'Lidl' => '/\blidl\b/i',
        'Netto' => '/\bnetto\b(?!\s*(betrag|umsatz|summe))/i', 'Penny' => '/\bpenny\b/i', 'Kaufland' => '/\bkaufland\b/i',
        'dm' => '/\bdm[\s-]*(drogerie|markt)|\bdm\b.*\bdrogerie/i', 'Rossmann' => '/\brossmann\b/i', 'Müller' => '/\bm(ü|ue)ller\b.*(drogerie|markt)/i',
        'Norma' => '/\bnorma\b/i', 'Globus' => '/\bglobus\b/i', 'tegut' => '/\btegut\b/i', 'Real' => '/\breal\s*,?-?\b/i',
        'Denns' => '/\bdenns\b/i', 'Alnatura' => '/\balnatura\b/i', 'Budni' => '/\bbudni\b/i', 'Hit' => '/\bhit\s+markt\b/i',
        'IKEA' => '/\bikea\b/i', 'OBI' => '/\bobi\b/i', 'Bauhaus' => '/\bbauhaus\b/i', 'Hornbach' => '/\bhornbach\b/i',
        'Aral' => '/\baral\b/i', 'Shell' => '/\bshell\b/i', 'Esso' => '/\besso\b/i', 'Apotheke' => '/apotheke/i',
    ];

    /** Zeilen, die nie Posten sind */
    private const SKIP = '/(^|\s)(mwst|mehrwertsteuer|ust|steuer|netto|brutto|gegeben|r(ü|u)ckgeld|wechselgeld|kartenzahlung|girocard|'
        . 'ec[\s-]?karte|maestro|visa|mastercard|kreditkarte|bar\b|tse|signatur|seriennr|transaktion|terminal|beleg|bon[\s-]?nr|'
        . 'kasse|kassierer|bediener|filiale|markt[\s-]?nr|tel\.?|telefon|fax|www\.|http|ust-?id|steuer-?nr|st\.?-?nr|datum|uhrzeit|'
        . 'vielen dank|danke|payback|punkte|coupon eingel|kundenkarte|genehmigung|autorisierung|kontaktlos|trace|zahlung|betrag eur|'
        . 'summe|zwischensumme|total|gesamt|zu zahlen|posten|artikel\s*:|anzahl artikel|trinkgeld|öffnungszeiten|mo-sa|leergutbon)/iu';

    private const TOTAL = '/(summe|zu zahlen|gesamtbetrag|gesamt|total|endbetrag|rechnungsbetrag|bar\s*eur|zahlbetrag)\b/iu';

    /**
     * @return array{store:?string, date:?string, total:?float, items:array<int, array{name:string, quantity:float, unit:?string, unit_price:float, total_price:float}>}
     */
    public static function parse(string $text): array
    {
        $lines = self::lines($text);
        $store = self::detectStore($lines);
        $date = self::detectDate($lines);
        [$total, $totalLine] = self::detectTotal($lines);

        $items = [];
        $pendingQty = null; // Mengenzeile vor dem Posten (Lidl/Aldi-Stil)
        $end = $totalLine ?? count($lines);

        for ($i = 0; $i < $end; $i++) {
            $line = $lines[$i];

            // Menge x Einzelpreis
            if ($q = self::parseQuantityLine($line)) {
                $last = count($items) - 1;
                if ($q['name'] !== null) {
                    // "2 x Milch 1,09" → eigener Posten
                    $items[] = self::item($q['name'], $q['qty'], $q['unit'], $q['unit_price'], $q['total'] ?? round($q['qty'] * $q['unit_price'], 2));
                } elseif ($last >= 0 && abs($items[$last]['total_price'] - round($q['qty'] * $q['unit_price'], 2)) < 0.02) {
                    $items[$last]['quantity'] = $q['qty'];
                    $items[$last]['unit'] = $q['unit'];
                    $items[$last]['unit_price'] = $q['unit_price'];
                } else {
                    $pendingQty = $q;
                }
                continue;
            }

            if (preg_match(self::SKIP, $line) && !preg_match('/pfand/i', $line)) {
                continue;
            }

            $p = self::parseItemLine($line);
            if (!$p) {
                continue;
            }

            // Rabatte / Abzüge dem vorherigen Posten zuordnen
            if ($p['total'] < 0 && $items && preg_match('/rabatt|preisvorteil|nachlass|coupon|aktion|sofortrabatt|abzug|-\s*\d+\s*%|reduziert/i', $p['name'])) {
                $last = count($items) - 1;
                $items[$last]['total_price'] = round($items[$last]['total_price'] + $p['total'], 2);
                $items[$last]['unit_price'] = round($items[$last]['total_price'] / max(0.001, $items[$last]['quantity']), 2);
                continue;
            }

            // Leergut/Pfandrückgabe ist immer ein Abzug, auch wenn die OCR das Minus verschluckt
            if ($p['total'] > 0 && preg_match('/leergut|pfandr(ü|ue|u)ck|pfand\s*zur(ü|u)ck|pfandbon/iu', $p['name'])) {
                $p['total'] = -$p['total'];
                $p['unit_price'] = $p['unit_price'] !== null ? -abs($p['unit_price']) : null;
            }

            $qty = 1.0;
            $unit = null;
            $unitPrice = $p['total'];
            if ($pendingQty && abs(round($pendingQty['qty'] * $pendingQty['unit_price'], 2) - $p['total']) < 0.02) {
                $qty = $pendingQty['qty'];
                $unit = $pendingQty['unit'];
                $unitPrice = $pendingQty['unit_price'];
            } elseif ($p['unit_price'] !== null) {
                $unitPrice = $p['unit_price'];
                $qty = $p['qty'] ?? ($unitPrice != 0 ? round($p['total'] / $unitPrice, 3) : 1.0);
            } elseif ($p['qty'] !== null && $p['qty'] > 0) {
                $qty = $p['qty'];
                $unitPrice = round($p['total'] / $qty, 2);
            }
            $pendingQty = null;
            $items[] = self::item($p['name'], $qty, $unit, $unitPrice, $p['total']);
        }

        if ($total !== null) {
            $items = self::healDigits($items, $total);
        }
        return ['store' => $store, 'date' => $date, 'total' => $total, 'items' => $items];
    }

    /** Typische OCR-Verwechslungen bei Ziffern */
    private const CONFUSABLE = ['0' => '986', '9' => '0', '8' => '036', '6' => '85', '5' => '6', '3' => '8', '1' => '7', '7' => '1', '4' => '1'];

    /**
     * Stimmt die Summe der Posten nicht mit der Bonsumme überein, wird geprüft, ob genau eine einzelne
     * Ziffernverwechslung in genau einem Preis die Differenz erklärt – dann wird sie korrigiert (corrected = true).
     */
    private static function healDigits(array $items, float $total): array
    {
        $sum = round(array_sum(array_column($items, 'total_price')), 2);
        if (!$items || abs($sum - $total) < 0.005) {
            return $items;
        }
        $fixes = [];
        foreach ($items as $idx => $it) {
            $cents = (string) abs((int) round($it['total_price'] * 100));
            for ($p = 0; $p < strlen($cents); $p++) {
                foreach (str_split(self::CONFUSABLE[$cents[$p]] ?? '') as $alt) {
                    $candidate = (int) substr_replace($cents, $alt, $p, 1) / 100 * ($it['total_price'] < 0 ? -1 : 1);
                    if (abs($sum - $it['total_price'] + $candidate - $total) < 0.005) {
                        $fixes[] = [$idx, round($candidate, 2)];
                    }
                }
            }
        }
        if (count($fixes) === 1) {
            [$idx, $value] = $fixes[0];
            $factor = $items[$idx]['total_price'] != 0 ? $value / $items[$idx]['total_price'] : 1;
            $items[$idx]['total_price'] = $value;
            $items[$idx]['unit_price'] = round($items[$idx]['unit_price'] * $factor, 2);
            $items[$idx]['corrected'] = true;
        }
        return $items;
    }

    /** @return string[] */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '  '], $text);
        $out = [];
        foreach (explode("\n", $text) as $l) {
            // OCR-Fehler: "1, 49" → "1,49", "1.49" bleibt, "O" in Preisen → 0
            $l = preg_replace('/(\d)\s*[,.]\s+(\d{2})\b/u', '$1,$2', $l);
            $l = preg_replace('/(?<=\d)[oO](?=\d)|(?<=[,.])[oO](?=\d)|(?<=[,.]\d)[oO]\b/u', '0', $l);
            // "12:99 B" → "12,99 B" (Doppelpunkt statt Komma; Uhrzeiten wie 18:03:12 bleiben unberührt)
            $l = preg_replace('/(?<=\s)(\d{1,4}):(\d{2})(?=\s*[A-Z0-9*]?\s*$)/u', '$1,$2', $l);
            // "1,198" am Zeilenende bei Artikeln: Steuerklasse (B/8, A/4, 1, 2) ohne Leerzeichen an den Preis geklebt
            if (preg_match('/^\p{L}.*\s(\d{1,4},\d{2})\d$/u', $l) && !preg_match('/\b(kg|g|l)\b/iu', $l)) {
                $l = preg_replace('/(\d{1,4},\d{2})\d$/u', '$1 X', $l);
            }
            $l = trim(preg_replace('/[ ]{2,}/u', '  ', $l));
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    private static function detectStore(array $lines): ?string
    {
        $head = implode("\n", array_slice($lines, 0, 10));
        foreach (self::STORES as $name => $re) {
            if (preg_match($re, $head)) {
                return $name;
            }
        }
        foreach (array_slice($lines, 0, 5) as $l) {
            if (preg_match('/\p{L}{3,}/u', $l) && !preg_match('/\d+[,.]\d{2}/', $l)) {
                return mb_substr(trim($l), 0, 60);
            }
        }
        return null;
    }

    private static function detectDate(array $lines): ?string
    {
        foreach ($lines as $l) {
            if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4}|\d{2})\b/', $l, $m)) {
                $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                if (checkdate((int) $m[2], (int) $m[1], $y) && $y >= 2000 && $y <= (int) date('Y') + 1) {
                    return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
                }
            }
            if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $l, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return "$m[1]-$m[2]-$m[3]";
            }
        }
        return null;
    }

    /** @return array{0:?float, 1:?int} Summe und Zeilenindex */
    private static function detectTotal(array $lines): array
    {
        $candidates = [];
        foreach ($lines as $i => $l) {
            if (preg_match(self::TOTAL, $l) && !preg_match('/zwischensumme|mwst|netto|steuer/i', $l)
                && preg_match_all('/-?\d{1,5}(?:\.\d{3})*,\d{2}|-?\d{1,5}\.\d{2}(?!\d)/', $l, $m)) {
                $v = Money::parse(end($m[0]));
                if ($v !== null && $v > 0) {
                    $prio = preg_match('/summe|zu zahlen|gesamtbetrag|endbetrag|rechnungsbetrag/i', $l) ? 2 : 1;
                    $candidates[] = ['i' => $i, 'v' => $v / 100, 'p' => $prio];
                }
            }
        }
        if (!$candidates) {
            return [null, null];
        }
        usort($candidates, fn ($a, $b) => [$b['p'], $a['i']] <=> [$a['p'], $b['i']]);
        return [$candidates[0]['v'], $candidates[0]['i']];
    }

    /**
     * Menge-mal-Preis-Zeilen.
     * @return array{qty:float, unit:?string, unit_price:float, name:?string, total:?float}|null
     */
    public static function parseQuantityLine(string $l): ?array
    {
        $num = '(\d+(?:[.,]\d{1,3})?)';
        $price = '(-?\d+[.,]\d{2})';
        // "0,576 kg x 2,99 EUR/kg" | "2 Stk x 0,75" | "2 x 0,99" | "2 x 0,99 1,98"
        if (preg_match('/^' . $num . '\s*(kg|g|l|stk\.?|st\.?|x)?\s*[x×*]\s*' . $price . '(?:\s*(?:eur|€)?(?:\s*\/\s*(?:kg|l|stk))?)?(?:\s+' . $price . '(?:\s+[A-Z0-9])?)?$/iu', $l, $m)) {
            $unit = isset($m[2]) ? strtolower(rtrim($m[2], '.')) : '';
            $unit = in_array($unit, ['kg', 'g', 'l'], true) ? $unit : null;
            return [
                'qty'        => (float) str_replace(',', '.', $m[1]),
                'unit'       => $unit,
                'unit_price' => Money::parse($m[3]) / 100,
                'name'       => null,
                'total'      => isset($m[4]) && $m[4] !== '' ? Money::parse($m[4]) / 100 : null,
            ];
        }
        // "2 x Milch 1,09 2,18" / "2x Milch 1,09"
        if (preg_match('/^(\d{1,3})\s*[x×]\s+(\p{L}.{1,80}?)\s+' . $price . '(?:\s+' . $price . ')?(?:\s+[A-Z0-9])?$/iu', $l, $m)) {
            $qty = (float) $m[1];
            $a = Money::parse($m[3]) / 100;
            $b = isset($m[4]) && $m[4] !== '' ? Money::parse($m[4]) / 100 : null;
            return ['qty' => $qty, 'unit' => null, 'unit_price' => $a, 'name' => trim($m[2]), 'total' => $b ?? round($qty * $a, 2)];
        }
        return null;
    }

    /**
     * Postenzeile: Name + Preis (+ optional Steuerklasse); bei Tabellen auch Name + Menge + Einzelpreis + Gesamtpreis.
     * @return array{name:string, total:float, unit_price:?float, qty:?float}|null
     */
    public static function parseItemLine(string $l): ?array
    {
        $price = '(-?\d{1,4}(?:\.\d{3})?[.,]\d{2})\s*(-)?';
        $tail = '(?:\s*(?:€|eur))?(?:\s+\*?[A-Z0-9]{1,2}\*?)?\s*$';
        // Name  Menge  Einzelpreis  Gesamtpreis
        if (preg_match('/^(.{2,80}?)\s+(\d{1,3}(?:[.,]\d{1,3})?)\s*(?:x|stk\.?|st\.?)?\s+' . $price . '(?:\s*(?:€|eur))?\s+' . $price . $tail . '/iu', $l, $m)) {
            $name = self::cleanName($m[1]);
            if ($name !== null) {
                $unitPrice = Money::parse($m[3]) / 100 * ($m[4] === '-' ? -1 : 1);
                $total = Money::parse($m[5]) / 100 * (($m[6] ?? '') === '-' ? -1 : 1);
                return ['name' => $name, 'total' => $total, 'unit_price' => $unitPrice, 'qty' => (float) str_replace(',', '.', $m[2])];
            }
        }
        // Name  Preis
        if (preg_match('/^(.{2,80}?)\s+' . $price . $tail . '/iu', $l, $m)) {
            $name = self::cleanName($m[1]);
            if ($name === null) {
                return null;
            }
            $total = Money::parse($m[2]) / 100 * (($m[3] ?? '') === '-' ? -1 : 1);
            return ['name' => $name, 'total' => $total, 'unit_price' => null, 'qty' => null];
        }
        return null;
    }

    private static function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name), " \t.:-*#");
        $name = preg_replace('/^\d{4,}\s+/', '', $name); // Artikelnummern
        if (mb_strlen($name) < 2 || !preg_match('/\p{L}{2,}/u', $name)) {
            return null;
        }
        return mb_substr($name, 0, 120);
    }

    private static function item(string $name, float $qty, ?string $unit, float $unitPrice, float $total): array
    {
        return ['name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => round($unitPrice, 2), 'total_price' => round($total, 2)];
    }
}
