<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * These tests exercise the driver classes directly — the same level the
 * sibling adapters (ephpm/db-laravel, ephpm/db-doctrine) test at, with an
 * sqlite-backed polyfill of the ephpm_db_* natives — rather than booting a
 * full CodeIgniter HTTP application. The driver's error paths call a couple
 * of CodeIgniter global helpers (log_message(), lang()) that normally live in
 * the framework's Common.php, which is only loaded by that full bootstrap, so
 * provide inert stubs when the real helpers aren't present.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (! function_exists('log_message')) {
    function log_message(string $level, string $message, array $context = []): bool
    {
        return true;
    }
}

if (! function_exists('lang')) {
    /**
     * @param array<int|string, mixed> $args
     */
    function lang(string $line, array $args = [], ?string $locale = null): string
    {
        return $line;
    }
}
