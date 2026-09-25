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
    protected int $hid;

    public function __construct(protected Request $request)
    {
        $this->hid = Auth::householdId();

        // Fällige Daueraufträge einmal pro Tag und Sitzung verbuchen (kein Cronjob nötig)
        if ($this->hid && Session::get('recurring_checked') !== date('Y-m-d')) {
            Session::set('recurring_checked', date('Y-m-d'));
            RecurrenceService::materializeDue($this->hid);
        }
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
