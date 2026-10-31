<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path): never
    {
        header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function csv(string $filename, array $header, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvCell'], $row), ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Schutz vor Formel-Injection: Texte, die Excel/LibreOffice als Formel ausführen würden (=, +, -, @, Tab, CR am Anfang),
     * bekommen ein ' vorangestellt. Empfänger/Verwendungszweck stammen aus Kontoauszügen und damit von Fremden.
     * Reine Zahlen (z. B. "-12,50") bleiben unverändert.
     */
    public static function csvCell(mixed $value): string
    {
        $v = (string) ($value ?? '');
        if ($v === '' || preg_match('/^[+-]?\d+([.,]\d+)?$/', $v)) {
            return $v;
        }
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}
