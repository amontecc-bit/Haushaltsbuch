<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Geldbeträge werden intern als Cent (int) verrechnet.
 */
final class Money
{
    /** DB-Wert ("1234.56") oder Zahl → Cent */
    public static function toCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        return (int) round(((float) $value) * 100);
    }

    /** Cent → DB-Wert "1234.56" */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Benutzereingabe in deutschem oder englischem Format → Cent.
     * Akzeptiert "1.234,56", "1234,56", "1234.56", "-12", "12,5 €", "1,234.56".
     */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null) {
            return null;
        }
        if (is_int($input) || is_float($input)) {
            return (int) round($input * 100);
        }
        $s = trim(str_replace(["\u{00A0}", ' ', '€', 'EUR', 'eur', '+'], '', $input));
        if ($s === '') {
            return null;
        }
        $negative = false;
        if (str_ends_with($s, '-')) { // "12,50-" wie auf manchen Kassenbons
            $negative = true;
            $s = substr($s, 0, -1);
        }
        if (str_starts_with($s, '-')) {
            $negative = !$negative;
            $s = substr($s, 1);
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // das zuletzt stehende Zeichen ist das Dezimaltrennzeichen
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace($thousands, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($lastComma !== false) {
            $s = str_replace(',', '.', $s);
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s); // "1.234.567"
        } elseif ($lastDot !== false && strlen($s) - $lastDot - 1 === 3 && $lastDot > 0 && !str_starts_with($s, '0.')) {
            $s = str_replace('.', '', $s); // "1.234" → Tausender
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            return null;
        }
        $cents = (int) round(((float) $s) * 100);
        return $negative ? -$cents : $cents;
    }

    /** Cent → "1.234,56 €" */
    public static function format(int $cents, bool $withSymbol = true): string
    {
        $out = number_format($cents / 100, 2, ',', '.');
        return $withSymbol ? $out . ' €' : $out;
    }
}
