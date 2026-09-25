<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:array, auth:string}> */
    private array $routes = [];

    /** auth: 'guest' (öffentlich), 'user' (angemeldet), 'admin' */
    public function get(string $path, array $handler, string $auth = 'user'): void
    {
        $this->add('GET', $path, $handler, $auth);
    }

    public function post(string $path, array $handler, string $auth = 'user'): void
    {
        $this->add('POST', $path, $handler, $auth);
    }

    private function add(string $method, string $path, array $handler, string $auth): void
    {
        $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $path) . '$#';
        $this->routes[] = compact('method', 'pattern', 'handler', 'auth');
    }

    public function dispatch(Request $request): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            if ($request->method === 'POST' && !Csrf::check($request)) {
                // 403 statt 419: Apache kennt 419 nicht und macht daraus einen 500er
                http_response_code(403);
                if ($request->wantsJson()) {
                    Response::json(['error' => 'Sitzung abgelaufen. Bitte Seite neu laden.'], 403);
                }
                View::render('errors/message', ['title' => 'Sitzung abgelaufen', 'message' => 'Bitte lade die Seite neu und versuche es noch einmal.', 'request' => $request],
                    Auth::user() ? 'layout' : 'layout_guest');
                return;
            }

            if ($route['auth'] !== 'guest') {
                Auth::requireLogin($request);
                if ($route['auth'] === 'admin') {
                    Auth::requireAdmin();
                }
            }

            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $action] = $route['handler'];
            (new $class($request))->$action(...array_values($params));
            return;
        }

        http_response_code($pathMatched ? 405 : 404);
        View::render('errors/message', ['title' => 'Nicht gefunden', 'message' => 'Die Seite gibt es nicht.', 'request' => $request],
            Auth::user() ? 'layout' : 'layout_guest');
    }
}
