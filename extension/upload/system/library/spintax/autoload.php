<?php
/**
 * Minimal PSR-4 autoloader for the ported Spintax engine kernel.
 *
 * OpenCart's own library loader is not PSR-4, so the engine registers its own
 * SPL autoloader for the `Spintax\` prefix, mapping it to this directory:
 *   Spintax\Core\Engine\Parser  ->  <this dir>/Core/Engine/Parser.php
 *
 * The PHPUnit harness loads the same classes via Composer PSR-4 (identical map),
 * so this file is only needed at OpenCart runtime. Idempotent: safe to require
 * more than once.
 *
 * @package Spintax\OpenCart
 */

declare(strict_types=1);

(static function (): void {
    $base = __DIR__;
    $prefix = 'Spintax\\';
    $prefix_len = strlen($prefix);

    spl_autoload_register(static function (string $class) use ($base, $prefix, $prefix_len): void {
        if (strncmp($class, $prefix, $prefix_len) !== 0) {
            return;
        }
        $relative = substr($class, $prefix_len);
        $path = $base . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    // Global convenience helpers (spintax_render*), guarded by function_exists.
    require_once $base . '/functions.php';
})();
