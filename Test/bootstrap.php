<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap for DhlPoland_HyvaDhl module tests.
 *
 * Adaptive autoloading for standalone CI and full Magento environments.
 */

// 1. Locate Composer autoloader
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    // Fallback when running inside full Magento (app/code/DhlPoland/HyvaDhl)
    $parentVendor = dirname(__DIR__, 4) . '/vendor/autoload.php';
    if (file_exists($parentVendor)) {
        $vendorAutoload = $parentVendor;
    }
}

if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Autoloader for DhlPoland\HyvaDhl
spl_autoload_register(static function (string $class): void {
    $prefix = 'DhlPoland\\HyvaDhl\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Autoloader for base DhlPoland\Dhl if in app/code
spl_autoload_register(static function (string $class): void {
    $prefix = 'DhlPoland\\Dhl\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = dirname(__DIR__, 2) . '/Dhl/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Autoloader for Hyvä Checkout test stubs (fallback when running without commercial packages)
spl_autoload_register(static function (string $class): void {
    $prefix = 'Hyva\\Checkout\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/Stubs/hyva-checkout/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Autoloader for Magewire test stubs (fallback when running standalone)
spl_autoload_register(static function (string $class): void {
    $prefix = 'Magewirephp\\Magewire\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/Stubs/Magewire/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// 2. Define temp directory for generated test code
if (!defined('TESTS_TEMP_DIR')) {
    define('TESTS_TEMP_DIR', sys_get_temp_dir() . '/magento_unit_tests_' . hash('sha256', __DIR__));
}

// 3. Load generated code autoloader
require_once __DIR__ . '/autoload.php';

// 4. Custom error handler
if (!function_exists('magentoUnitTestsPhpErrorHandler')) {
    function magentoUnitTestsPhpErrorHandler(int $errNo, string $errStr, string $errFile, int $errLine): bool
    {
        $errLevel = error_reporting();
        if (($errLevel & $errNo) !== 0) {
            throw new ErrorException($errStr, 0, $errNo, $errFile, $errLine);
        }
        return true;
    }
    set_error_handler('magentoUnitTestsPhpErrorHandler');
}
