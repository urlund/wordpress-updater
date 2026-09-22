<?php
/**
 * Optional .env loader for CLI tools.
 *
 * Parses KEY=VALUE lines; never overrides variables already in the environment.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

class EnvLoader
{
    /**
     * Load .env from a directory into the process environment.
     *
     * @param string $directory
     * @return void
     */
    public static function load($directory)
    {
        $envFile = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (stripos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            if (getenv($key) !== false) {
                continue;
            }

            $value = trim(substr($line, $eq + 1));
            if ($value !== '') {
                $first = $value[0];
                $last = substr($value, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
