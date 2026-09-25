<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Money;

function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $configured = (string) Config::get('app.base_url', '');
        if ($configured !== '') {
            $base = rtrim($configured, '/');
        } else {
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            $dir = preg_replace('#/public$#', '', $dir);
            $base = rtrim($dir, '/');
        }
    }
    return $base;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function asset(string $path): string
{
    $file = __DIR__ . '/../public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return base_path() . '/assets/' . ltrim($path, '/') . $v;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** DB-Betrag oder Cent formatieren */
function money(string|int|float|null $value, bool $isCents = false): string
{
    $cents = $isCents ? (int) $value : Money::toCents($value);
    return Money::format($cents);
}

/** Betrag für Eingabefelder: "1234,56" */
function money_input(string|int|float|null $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float) $value, 2, ',', '');
}

function money_class(string|int|float|null $value): string
{
    return (float) $value < 0 ? 'text-danger' : ((float) $value > 0 ? 'text-success' : '');
}

function date_de(?string $date, bool $short = false): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($short ? 'd.m.' : 'd.m.Y', $ts) : '';
}

function month_de(int $month): string
{
    return ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'][$month] ?? '';
}

function month_label(string $ym): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    return mb_substr(month_de($m), 0, 3) . ' ' . substr((string) $y, 2);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? 'selected' : '';
}

function checked(mixed $cond): string
{
    return $cond ? 'checked' : '';
}

function nav_active(string $prefix, string $current): string
{
    if ($prefix === '/') {
        return $current === '/' ? 'active' : '';
    }
    return str_starts_with($current, $prefix) ? 'active' : '';
}

function interval_label(string $interval): string
{
    return [
        'monthly'    => 'monatlich',
        'quarterly'  => 'vierteljährlich',
        'halfyearly' => 'halbjährlich',
        'yearly'     => 'jährlich',
    ][$interval] ?? $interval;
}

function account_type_label(string $type): string
{
    return [
        'giro'        => 'Girokonto',
        'savings'     => 'Sparkonto',
        'cash'        => 'Bargeld',
        'credit_card' => 'Kreditkarte',
        'other'       => 'Sonstiges',
    ][$type] ?? $type;
}

function role_label(string $role): string
{
    return ['admin' => 'Administrator', 'member' => 'Mitglied', 'child' => 'Kind'][$role] ?? $role;
}

/** Gültiges ISO-Datum oder Fallback */
function valid_date(?string $d, ?string $fallback = null): ?string
{
    if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        if (checkdate($m, $day, $y)) {
            return $d;
        }
    }
    return $fallback;
}
