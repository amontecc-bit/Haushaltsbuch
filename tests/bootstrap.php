<?php

declare(strict_types=1);

use App\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(require dirname(__DIR__) . '/config/config.php');
require dirname(__DIR__) . '/app/helpers.php';
date_default_timezone_set('Europe/Berlin');
