<?php

declare(strict_types=1);

/**
 * NOD32 Mirror Update Script - Entry Point
 *
 * Modern PHP 8.1+ implementation with:
 * - PSR-4 autoloading
 * - Dependency Injection
 * - Strong typing
 * - Guzzle HTTP client for concurrent downloads
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inc/directories.php';

use Nod32Mirror\Application;

// Ensure DIRECTORIES is available
if (!isset($DIRECTORIES) || !is_array($DIRECTORIES)) {
    throw new RuntimeException('$DIRECTORIES configuration not found');
}

try {
    $app = new Application($DIRECTORIES);
    $app->run();
} catch (Throwable $e) {
    error_log(sprintf(
        "[FATAL] %s in %s:%d\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    exit(1);
}
