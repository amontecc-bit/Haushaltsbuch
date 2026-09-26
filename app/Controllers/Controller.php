<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\RecurrenceService;

abstract class Controller
{
    /** Zeiträume für Auswertungen und Buchungsliste */
    public const PERIODS = [
        'month' => 'Dieser Monat', 'last_month' => 'Letzter Monat', '3m' => 'Letzte 3 Monate', '6m' => 'Letzte 6 Monate',
        '12m' => 'Letzte 12 Monate', 'year' => 'Dieses Jahr', 'last_year' => 'Letztes Jahr', 'custom' => 'Zeitraum …',
    ];

    protected int $hid;

    public function __construct(protected Request $request)
    {
        $this->hid = Auth::householdId();

        // Fällige Daueraufträge einmal pro Tag und Sitzung verbuchen (kein Cronjob nötig)
        if ($this->hid && Session::get('recurring_checked') !== date('Y-m-d')) {
            Session::set('recurring_checked', date('Y-m-d'));
            RecurrenceService::materializeDue($this->hid);
            RecurrenceService::linkExisting($this->hid);
        }
    }

    /**
     * Zeitraum aus ?period= (bzw. from/to ohne period → „Zeitraum …“, z. B. Links aus den Auswertungen).
     * Mit $allowAll ist zusätzlich 'all' erlaubt (von/bis leer = keine Einschränkung).
     * @return array{0:string, 1:string, 2:string} Periode, von, bis
     */
    protected function period(string $default = 'month', bool $allowAll = false): array
    {
        $r = $this->request;
        $p = $r->str('period') ?: (($r->str('from') || $r->str('to')) ? 'custom' : $default);
        $p = array_key_exists($p, self::PERIODS) || ($allowAll && $p === 'all') ? $p : $default;
        $today = date('Y-m-d');
        [$from, $to] = match ($p) {
            'all'        => ['', ''],
            'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            '3m'         => [date('Y-m-01', strtotime('first day of -2 months')), $today],
            '6m'         => [date('Y-m-01', strtotime('first day of -5 months')), $today],
            '12m'        => [date('Y-m-01', strtotime('first day of -11 months')), $today],
            'year'       => [date('Y-01-01'), date('Y-12-31')],
            'last_year'  => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'],
            'custom'     => [valid_date($r->str('from'), date('Y-m-01')), valid_date($r->str('to'), $today)],
            default      => [date('Y-m-01'), date('Y-m-t')],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$p, $from, $to];
    }

    protected function view(string $template, array $data = []): void
    {
        View::render($template, $data + ['request' => $this->request, 'user' => Auth::user()]);
    }

    protected function redirect(string $path, ?string $message = null, string $type = 'success'): never
    {
        if ($message !== null) {
            Session::flash($type, $message);
        }
        Response::redirect($path);
    }

    protected function back(string $fallback, ?string $message = null, string $type = 'danger'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $target = ($ref && parse_url($ref, PHP_URL_HOST) === $host) ? $ref : url($fallback);
        if ($message !== null) {
            Session::flash($type, $message);
        }
        header('Location: ' . $target);
        exit;
    }

    protected function json(mixed $data, int $status = 200): never
    {
        Response::json($data, $status);
    }

    protected function notFound(): never
    {
        http_response_code(404);
        View::render('errors/message', ['title' => 'Nicht gefunden', 'message' => 'Der Eintrag existiert nicht.']);
        exit;
    }
}
