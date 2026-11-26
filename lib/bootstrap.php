<?php
/**
 * bootstrap
 * =========================
 * Loading the autoload (without Composer), constants, and basic settings.
 * Imported by index.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('HERMES_ROOT', dirname(__DIR__));
define('HERMES_DATA_DIR', HERMES_ROOT . '/data');

// Composer autoloader (mpdf, phpoffice, ...)
if (is_file(HERMES_ROOT . '/vendor/autoload.php')) {
    require HERMES_ROOT . '/vendor/autoload.php';
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Hermes\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = HERMES_ROOT . '/lib/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Processing (transcription, image generation, LLM) can take a long time
set_time_limit(0);

$tz = date_default_timezone_get();
if ($tz === false || $tz === '') {
    date_default_timezone_set('Europe/Paris');
}
