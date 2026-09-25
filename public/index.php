<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

require dirname(__DIR__) . '/app/bootstrap.php';

Session::start();

$router = new Router();
require dirname(__DIR__) . '/app/routes.php';

try {
    $router->dispatch(Request::capture());
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    if (\App\Core\Config::get('app.debug')) {
        echo '<pre style="white-space:pre-wrap">' . e($e) . '</pre>';
    } else {
        \App\Core\View::render('errors/message', ['title' => 'Fehler', 'message' => 'Es ist ein unerwarteter Fehler aufgetreten.']);
    }
}
