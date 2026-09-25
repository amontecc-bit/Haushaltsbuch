<?php

declare(strict_types=1);

use App\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

Config::load(require dirname(__DIR__) . '/config/config.php');

date_default_timezone_set(Config::get('app.timezone'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', Config::get('paths.logs') . '/php-error.log');

require __DIR__ . '/helpers.php';
