<?php

/**
 * Simple PSR-4 autoloader for the src/ directory.
 *
 * Namespace map:
 *   Core\        -> src/Core/
 *   Controllers\ -> src/Controllers/
 *   Services\    -> src/Services/
 *   Repositories\-> src/Repositories/
 *   Helpers\     -> src/Helpers/
 */
spl_autoload_register(function (string $class): void {
    $namespaceMap = [
        'Core\\'         => __DIR__ . '/Core/',
        'Controllers\\'  => __DIR__ . '/Controllers/',
        'Services\\'     => __DIR__ . '/Services/',
        'Repositories\\' => __DIR__ . '/Repositories/',
        'Helpers\\'      => __DIR__ . '/Helpers/',
    ];

    foreach ($namespaceMap as $prefix => $baseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require $file;
        }

        return;
    }
});
