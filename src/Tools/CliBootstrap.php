#!/usr/bin/env php
<?php
/**
 * Shared bootstrap for bin/* CLI scripts.
 *
 * Loaded directly from bin scripts before Composer autoload.
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class CliBootstrap
{
    /**
     * Locate and require Composer autoload.
     *
     * @param string $binDir Absolute path to the bin directory
     * @return void
     */
    public static function autoload($binDir)
    {
        $autoloaderPaths = array(
            $binDir . '/../vendor/autoload.php',
            $binDir . '/../../../autoload.php',
        );

        foreach ($autoloaderPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
    }

    /**
     * Require a class file if the class is not loaded.
     *
     * @param string $class
     * @param string $fallbackFile
     * @return void
     */
    public static function requireClass($class, $fallbackFile)
    {
        if (!class_exists($class) && file_exists($fallbackFile)) {
            require_once $fallbackFile;
        }
    }

    /**
     * Ensure core Tool classes are available without Composer autoload.
     *
     * @param string $binDir
     * @return void
     */
    public static function loadTools($binDir)
    {
        $base = $binDir . '/../src/Tools/';
        $files = array(
            'EnvLoader.php',
            'ProjectConfig.php',
            'AbstractCli.php',
            'ReleaseJsonGenerator.php',
            'ZipPackager.php',
            'VersionBump.php',
            'GitHubPublisher.php',
            'Release.php',
        );
        foreach ($files as $file) {
            $path = $base . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Run a CLI tool class.
     *
     * @param string $class Fully qualified class name
     * @param string $binDir
     * @param string $fallbackRelative Relative path from package root to class file
     * @return void
     */
    public static function run($class, $binDir, $fallbackRelative)
    {
        if (php_sapi_name() !== 'cli') {
            echo "This script must be run from the command line.\n";
            exit(1);
        }

        self::autoload($binDir);

        self::requireClass(
            __NAMESPACE__ . '\\EnvLoader',
            $binDir . '/../src/Tools/EnvLoader.php'
        );
        if (class_exists(__NAMESPACE__ . '\\EnvLoader')) {
            EnvLoader::load(getcwd());
        }

        if (!class_exists($class)) {
            self::loadTools($binDir);
            self::requireClass($class, $binDir . '/../' . $fallbackRelative);
        }

        if (!class_exists($class)) {
            echo "\033[31m✗ Error: Class {$class} not found. Run composer install.\033[0m\n";
            exit(1);
        }

        try {
            /** @var AbstractCli $tool */
            $tool = new $class();
            $tool->run();
        } catch (Exception $e) {
            echo "\033[31m✗ Error: " . $e->getMessage() . "\033[0m\n";
            exit(1);
        }
    }
}
