<?php
// Claude-Code-Hook (PostToolUse auf Edit|Write): prüft geänderte PHP-Dateien per `php -l`
// und lässt danach die PHPUnit-Tests laufen (tests/Unit). Bei Fehlern Exit-Code 2 → Ausgabe geht an Claude zurück.

$input = json_decode(stream_get_contents(STDIN), true) ?: [];
$file  = $input['tool_input']['file_path'] ?? ($input['tool_response']['filePath'] ?? '');

if (!preg_match('/\.php$/i', $file) || !is_file($file)) {
    exit(0);
}

$root = dirname(__DIR__, 2);
$php  = PHP_BINARY;

exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "Syntaxfehler in $file:\n" . implode("\n", $out) . "\n");
    exit(2);
}

$out = [];
$cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($php) . ' vendor/bin/phpunit --colors=never 2>&1';
exec($cmd, $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "PHPUnit fehlgeschlagen nach Änderung an $file:\n" . implode("\n", array_slice($out, -40)) . "\n");
    exit(2);
}

exit(0);
