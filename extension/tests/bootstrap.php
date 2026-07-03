<?php
/**
 * PHPUnit bootstrap for the Spintax OpenCart engine harness.
 *
 * The ported kernel (upload/system/library/spintax/) is pure, framework-agnostic
 * PHP — no OpenCart runtime is loaded here. Classes autoload via Composer PSR-4
 * (Spintax\ -> upload/system/library/spintax/, Spintax\Tests\ -> tests/).
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload missing. Run: composer install (see scripts/test.sh).\n");
    exit(1);
}

require $autoload;

/**
 * Absolute path to a golden fixture shared with the WordPress kernel.
 */
function spintax_fixture(string $name): string
{
    return __DIR__ . '/fixtures/' . $name;
}
