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
        'REWE' => '/\brewe\b/i', 'EDEKA' => '/\bedeka\b|\bmarktkauf\b|^e[\s-]?center\b/im', 'ALDI' => '/\baldi\b/i', 'Lidl' => '/\blidl\b/i',
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
        . 'kasse(\b|n)|kassierer|bediener|filiale|markt[\s-]?nr|tel\.?|telefon|fax|www\.|http|ust-?id|steuer-?nr|st\.?-?nr|datum|uhrzeit|'
        . 'vielen dank|danke|payback|punkte|coupon eingel|kundenkarte|genehmigung|autorisierung|kontaktlos|trace|zahlung|betrag eur|'
        . 'summe|zwischensumme|total|gesamt|zu zahlen|posten|artikel\s*:|anzahl artikel|trinkgeld|öffnungszeiten|mo-sa|leergutbon)/iu';

    private const TOTAL = '/(summe|zu zahlen|gesamtbetrag|gesamt|total|endbetrag|rechnungsbetrag|bar\s*eur|zahlbetrag)\b/iu';

    /**
     * $ocr = true (Text aus Tesseract): Zeilen, die nach Posten aussehen, deren Preis aber nicht lesbar ist, kommen als
     * Platzhalter (total_price = null, missing = true) in die Liste – so lässt sie sich Zeile für Zeile mit dem Bon
     * vergleichen. Unplausible Preise tragen in `suspect` den Grund; `ocr` enthält dann die gelesene Zeile.
     *
     * @return array{store:?string, date:?string, total:?float, items:array<int, array{name:string, quantity:float, unit:?string, unit_price:?float, total_price:?float, missing?:bool, suspect?:string, ocr?:string, corrected?:bool}>}
     */
    public static function parse(string $text, bool $ocr = false): array
    {
        // Mehrere Fotos eines langen Bons kommen durch "\f" getrennt; sie überlappen sich meist
        $pages = array_values(array_filter(explode("\f", $text), fn ($p) => trim($p) !== ''));
        $lines = self::lines(implode("\n", $pages));
        $store = self::detectStore($lines);
        $date = self::detectDate($lines);
        [$total] = self::detectTotal($lines);

        $items = [];
        $looseTotal = null;
        foreach ($pages ?: [''] as $page) {
            [$pageItems, $pageTotal] = self::parseItems(self::lines($page), $ocr);
            $looseTotal ??= $pageTotal;
            $items = self::mergeOverlap($items, $pageItems);
        }
        $total ??= $looseTotal;
        $items = self::checkPlausibility($items, $total);
        if ($total !== null && !array_filter($items, fn ($it) => !empty($it['missing']))) {
            $items = self::healDigits($items, $total);
        }
        foreach ($items as &$it) {
            // Rohzeile nur dort mitgeben, wo sie beim Vergleich mit dem Bon hilft
            if (empty($it['missing']) && empty($it['suspect'])) {
                unset($it['ocr']);
            }
        }
        unset($it);
        return ['store' => $store, 'date' => $date, 'total' => $total, 'items' => $items];
    }

    /**
     * Unplausible Preise: ab Bonsumme (→ Platzhalter, Preis unlesbar), Ausreißer oder ein Vielfaches der Zahl im
     * Namen ("PFANDWERT 1,50" für 15,00).
     */
    private static function checkPlausibility(array $items, ?float $total): array
    {
        $prices = array_map('abs', array_filter(array_column($items, 'total_price'), fn ($p) => $p !== null));
        sort($prices);
        $median = $prices ? $prices[intdiv(count($prices), 2)] : 0.0;

        foreach ($items as &$it) {
            if ($it['total_price'] === null) {
                continue;
            }
            $p = abs($it['total_price']);
            if ($total !== null && count($items) > 1 && $p >= $total) {
                $it = self::placeholder($it['name'], $it['ocr'] ?? null);
            } elseif (count($prices) >= 5 && $p > 20 && $p > 10 * $median) {
                $it['suspect'] = 'Preis ungewöhnlich hoch – bitte prüfen';
            } elseif (preg_match('/(\d+),(\d{2})\b/', $it['name'], $m) && self::isPowerOfTenOff((float) "$m[1].$m[2]", $p)) {
                $it['suspect'] = 'Preis passt nicht zur Zahl im Namen – Komma verrutscht?';
            }
        }
        unset($it);
        return $items;
    }

    private static function isPowerOfTenOff(float $expected, float $price): bool
    {
        foreach ([10, 100, 1000, 0.1, 0.01] as $f) {
            if (abs($price - $expected * $f) < 0.005) {
                return true;
            }
        }
        return false;
    }

    /** Platzhalter für eine Zeile, die ein Posten ist, deren Preis aber nicht gelesen werden konnte */
    private static function placeholder(string $name, ?string $line): array
    {
        $item = ['name' => $name, 'quantity' => 1.0, 'unit' => null, 'unit_price' => null, 'total_price' => null, 'missing' => true];
        return $line !== null ? $item + ['ocr' => $line] : $item;
    }

    /** Verlesene Summenzeile ("AHLEN 115,51" statt "ZU ZAHLEN 115,51") */
    private static function isTotalName(string $name): bool
    {
        $n = preg_replace('/[^\p{L}]/u', '', mb_strtoupper($name));
        if (mb_strlen($n) < 4 || mb_strlen($n) > 14) {
            return false;
        }
        foreach (['ZUZAHLEN', 'SUMME', 'GESAMTSUMME', 'GESAMTBETRAG', 'ZAHLBETRAG', 'ENDBETRAG'] as $w) {
            similar_text($n, $w, $pct);
            if ($pct >= 75) {
                return true;
            }
        }
        return false;
    }

    /** OCR-Zeile, die nach Posten aussieht (Name + rechts etwas), aber keinen lesbaren Preis hat */
    private static function unreadableItem(string $line): ?array
    {
        if (preg_match('/\d\s*[x×]\s*\d|[x×]\s*\d+[,.]\d{2}|\/\s*k[gq]/iu', $line) || !preg_match('/\s{2,}\S|\d/u', $line)) {
            return null; // verlesene Mengenzeile bzw. reine Textzeile (Kopf, Hinweise)
        }
        $parts = preg_split('/\s{2,}/u', $line, 2);
        $name = self::cleanName($parts[0]);
        if ($name === null || !preg_match('/\p{L}{4,}/u', $name)) {
            return null;
        }
        // rechts davon ein halbwegs lesbarer Preis ("3'00 € 1", "6;79 €.1", "5.3841" = 5,38 € 1) → als Vorschlag
        $guess = '(?<![\d,.])(\d{1,3})\s?[,.;:\'’`]\s?(\d{2})';
        if (isset($parts[1]) ? preg_match('/' . $guess . '/u', $parts[1], $m) : preg_match('/\s' . $guess . '[^\p{L}\d]{0,4}\d?[^\p{L}\d]{0,3}$/u', $line, $m)) {
            $price = (float) "$m[1].$m[2]";
            return self::item($name, 1.0, null, $price, $price) + ['suspect' => 'Preis unsicher gelesen – bitte prüfen', 'ocr' => $line];
        }
        return self::placeholder($name, $line);
    }

    /**
     * Posten einer Seite bzw. eines Fotos, bis zur Summenzeile.
     * @return array{0:array, 1:?float} Posten und ggf. Betrag einer unscharf erkannten Summenzeile
     */
    private static function parseItems(array $lines, bool $ocr = false): array
    {
        [, $totalLine] = self::detectTotal($lines);
        if ($totalLine === null) {
            // Summenzeile ohne lesbaren Betrag (OCR) beendet die Posten trotzdem
            foreach ($lines as $i => $l) {
                if (preg_match('/^\W{0,3}(zu zahlen|summe|gesamtsumme|gesamtbetrag)\b/iu', $l)) {
                    $totalLine = $i;
                    break;
                }
            }
        }

        $items = [];
        $pendingQty = null; // Mengenzeile vor dem Posten (Lidl/Aldi-Stil)
        $end = $totalLine ?? count($lines);
        $looseTotal = null;
        $started = false; // Platzhalter erst ab dem ersten lesbaren Posten (davor steht der Kopf des Bons)

        for ($i = 0; $i < $end; $i++) {
            $line = $lines[$i];

            // Menge x Einzelpreis
            if ($q = self::parseQuantityLine($line)) {
                $started = true;
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
            if (self::isTotalName($p['name'] ?? preg_split('/\s{2,}/u', $line)[0])) {
                $looseTotal = $p && $p['total'] > 0 ? $p['total'] : null;
                break;
            }
            if (!$p) {
                if ($ocr && $started && ($ph = self::unreadableItem($line))) {
                    $items[] = $ph;
                }
                continue;
            }
            $started = true;

            // Rabatte / Abzüge dem vorherigen Posten zuordnen
            if ($p['total'] < 0 && $items && end($items)['total_price'] !== null && preg_match('/rabatt|preisvorteil|nachlass|coupon|aktion|sofortrabatt|abzug|-\s*\d+\s*%|reduziert/i', $p['name'])) {
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
            $items[] = self::item($p['name'], $qty, $unit, $unitPrice, $p['total']) + ($ocr ? ['ocr' => $line] : []);
        }
        return [$items, $looseTotal];
    }

    /**
     * Hängt die Posten eines weiteren Fotos an. Der Anfang des neuen Fotos wiederholt meist das Ende des vorherigen:
     * Die Überlappung wird per LCS über ähnliche Posten gesucht (mind. 2 Treffer, am Ende bzw. Anfang), doppelte
     * Posten fallen weg, und von zwei Lesungen desselben Postens bleibt die sauberere.
     */
    private static function mergeOverlap(array $prev, array $next): array
    {
        $n = count($prev);
        if ($n === 0 || !$next) {
            return array_merge($prev, $next);
        }
        $from = max(0, $n - 40);
        $a = array_slice($prev, $from);
        $b = array_slice($next, 0, 40);
        // LCS-Tabelle
        $la = count($a);
        $lb = count($b);
        $dp = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $dp[$i][$j] = self::sameItem($a[$i], $b[$j]) ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $pairs = [];
        for ($i = 0, $j = 0; $i < $la && $j < $lb;) {
            if (self::sameItem($a[$i], $b[$j]) && $dp[$i][$j] === $dp[$i + 1][$j + 1] + 1) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        // echte Überlappung: reicht bis ans Ende des alten und beginnt am Anfang des neuen Fotos; mind. 2 Treffer –
        // oder genau einer, wenn es der letzte Posten des alten und (fast) der erste des neuen Fotos ist
        $single = count($pairs) === 1 && $pairs[0][0] === $la - 1 && $pairs[0][1] <= 1;
        if (!$pairs || (count($pairs) < 2 && !$single) || $la - 1 - end($pairs)[0] > 6 || $pairs[0][1] > 6) {
            return array_merge($prev, $next);
        }
        $out = array_slice($prev, 0, $from + $pairs[0][0]);
        $pi = $pairs[0][0];
        $pj = $pairs[0][1];
        foreach ($pairs as [$i, $j]) {
            // nicht zugeordnete Posten zwischen den Treffern behalten (in einem Foto evtl. unlesbar)
            array_push($out, ...array_slice($a, $pi, $i - $pi), ...array_slice($b, $pj, $j - $pj));
            $out[] = self::better($a[$i], $b[$j]);
            $pi = $i + 1;
            $pj = $j + 1;
        }
        return array_merge($out, array_slice($a, $pi), array_slice($b, $pj), array_slice($next, 40));
    }

    private static function sameItem(array $x, array $y): bool
    {
        $nx = preg_replace('/[^\p{L}\d]/u', '', mb_strtoupper($x['name']));
        $ny = preg_replace('/[^\p{L}\d]/u', '', mb_strtoupper($y['name']));
        similar_text($nx, $ny, $pct);
        if ($x['total_price'] === null || $y['total_price'] === null) {
            return $pct >= 75; // Platzhalter: nur der Name zählt
        }
        return abs($x['total_price'] - $y['total_price']) < 0.005 ? $pct >= 55 : $pct >= 85;
    }

    /** Von zwei Lesungen desselben Postens: lieber mit Preis, dann unverdächtig, dann der sauberere Name */
    private static function better(array $a, array $b): array
    {
        $rank = fn ($it) => [$it['total_price'] !== null, empty($it['suspect']), self::cleanliness($it['name'])];
        return $rank($b) > $rank($a) ? $b : $a;
    }

    /** Anteil Buchstaben/Leerzeichen am Namen – OCR-Müll hat viele Sonderzeichen */
    private static function cleanliness(string $name): float
    {
        return preg_match_all('/[\p{L} ]/u', $name) / max(1, mb_strlen($name));
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
        $text = str_replace(["\r\n", "\r", "\t", "\u{00A0}", "\u{202F}", "\u{2009}"], ["\n", "\n", '  ', ' ', ' ', ' '], $text);
        // PDF-Rechnungen: "0,95 €11,40 €" → "0,95 € 11,40 €"
        $text = preg_replace('/€(?=-?\d)/u', '€ ', $text);
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
            // Aldi-Stil "Preis € Steuerklasse" mit OCR-Rauschen: "3,55,€'2", "1,79€. 1", "0,99 €i1", "1,49 €}", "8,73 € |"
            $l = preg_replace('/(\d{1,4}[,.]\d{2})\s*[,.]?\s*€\s*[.,;:\'‘’"`i]*\s*([12])?\s*[|\]}{)!]*\s*$/u', '$1 € $2', $l);
            // EDEKA/Marktkauf-PDF: Steuerklasse direkt am Preis ("19,90B", "1,44AW", "29,99*B")
            $l = preg_replace('/(?<=\s)(-?\d{1,4},\d{2})(\*?[A-Z]{1,2}\*?)$/u', '$1 $2', $l);
            // "2€ x 0,99GURKEN  1,98 A" (Menge vor dem Namen, "€" steht im PDF für "St") → "2 x GURKEN 0,99 1,98 A"
            $l = preg_replace('/^(\d{1,3})\s*(?:€|stk?\.?)?\s*[x×]\s*(\d{1,4},\d{2})\s*(?=\p{L})(.+?)\s+(-?\d{1,4},\d{2}(?:\s+\S{1,3})?)$/iu', '$1 x $3 $2 $4', $l);
            $l = trim(preg_replace('/[ ]{2,}/u', '  ', $l));
            if ($l === '') {
                continue;
            }
            // Tabellenzeile ohne Namen ("1 B 3,59 € 3,59 €"): Name stand umbrochen in den Zeilen davor
            if (preg_match('/^-?\d{1,3}\s+[A-Z](?:\/[A-Z])?\s+\d{1,4},\d{2}\s*€?\s+-?\d{1,4},\d{2}\s*€?$/u', $l)) {
                $name = [];
                while ($out && count($name) < 2 && !preg_match('/\d,\d{2}|einzelpreis|^produkt$/iu', end($out))) {
                    array_unshift($name, array_pop($out));
                }
                if ($name) {
                    $l = implode(' ', $name) . '  ' . $l;
                }
            }
            $out[] = $l;
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
            // in Datumszeilen liest die OCR Punkte gern als Kommas: "Datum 25,09,26", "Da um, 25,09,26 18:03 Uhr"
            $sep = preg_match('/datum|\d{1,2}:\d{2}|\buhr\b/i', $l) ? '[.\/,]' : '[.\/]';
            if (preg_match('/\b(\d{1,2})' . $sep . '(\d{1,2})' . $sep . '(\d{4}|\d{2})\b/', $l, $m)) {
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
        if (preg_match('/^(\d{1,3})\s*[x×]\s+(\p{L}.{1,80}?)\s+' . $price . '(?:\s+' . $price . ')?(?:\s+\*?[A-Z0-9]{1,2}\*?)?$/iu', $l, $m)) {
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
        // Name  Menge  [Steuerklasse]  Einzelpreis  Gesamtpreis  (REWE-Rechnung: "Milch  12 B 0,95 € 11,40 €")
        if (preg_match('/^(.{2,80}?)\s+(-?\d{1,3}(?:[.,]\d{1,3})?)\s*(?:x|stk\.?|st\.?)?\s+(?:(?-i:[A-Z](?:\/[A-Z])?)\s+)?' . $price . '(?:\s*(?:€|eur))?\s+' . $price . $tail . '/iu', $l, $m)) {
            $name = self::cleanName($m[1]);
            if ($name !== null) {
                $qty = (float) str_replace(',', '.', $m[2]);
                $unitPrice = Money::parse($m[3]) / 100 * ($m[4] === '-' ? -1 : 1);
                $total = Money::parse($m[5]) / 100 * (($m[6] ?? '') === '-' ? -1 : 1);
                if ($qty < 0) { // Rückgabe: "-9 A 0,50 € -4,50 €"
                    $qty = -$qty;
                    $unitPrice = -abs($unitPrice);
                }
                return ['name' => $name, 'total' => $total, 'unit_price' => $unitPrice, 'qty' => $qty];
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
        $name = preg_replace('/\s*[—–_~=<>|]{2,}.*$|(\s+[—–_~=<>|-]+)+$/u', '', $name); // Stiftstriche am Bon ("PURINA ——— —")
        $name = preg_replace('/^[^\p{L}\d]+\s+/u', '', $name); // OCR-Krümel vor dem Namen ("; BASICS")
        $name = preg_replace('/(\s+[^\p{L}\d\s]{1,2})+$/u', '', $name); // … und dahinter ("BIO MÖHREN NL ;")
        // Bon in Großbuchstaben: einzelne Zeichen am Rand sind Krümel vom Bildrand ("I FRUCHTSAFT", "ı WISSENSBUCH", "SORT. 19 u")
        $core = preg_replace('/^\S\s+(?=\p{L}{3})|\s+\p{Ll}$/u', '', $name);
        if (mb_strtoupper($core) === $core && preg_match('/\p{Lu}{3}/u', $core)) {
            $name = $core;
        }
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
