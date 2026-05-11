<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_PUBLIC')) {
    define('APP_PUBLIC', APP_ROOT . '/public');
}

require_once APP_ROOT . '/app/config/config.php';
require_once APP_ROOT . '/app/helpers.php';