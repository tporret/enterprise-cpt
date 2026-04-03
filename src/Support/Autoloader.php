<?php

declare(strict_types=1);

namespace EnterpriseCPT\Support;

final class Autoloader
{
    public static function register(string $prefix, string $baseDirectory): void
    {
        $normalizedPrefix = trim($prefix, '\\') . '\\';
        $normalizedBaseDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $className) use ($normalizedPrefix, $normalizedBaseDirectory): void {
            if (strncmp($className, $normalizedPrefix, strlen($normalizedPrefix)) !== 0) {
                return;
            }

            $relativeClass = substr($className, strlen($normalizedPrefix));
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            $filePath = $normalizedBaseDirectory . $relativePath;

            if (is_readable($filePath)) {
                require_once $filePath;
            }
        });
    }
}