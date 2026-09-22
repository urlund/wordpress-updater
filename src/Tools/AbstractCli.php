<?php
/**
 * Shared CLI helpers for plugin tools.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

abstract class AbstractCli
{
    /** @var array */
    protected $options = array();

    /** @var array|null */
    protected $argv;

    /**
     * @param array|null $options Pre-parsed options (skips argv parsing when provided)
     * @param array|null $argv    Custom argv (defaults to $_SERVER['argv'])
     */
    public function __construct(?array $options = null, ?array $argv = null)
    {
        if ($argv !== null) {
            $this->argv = $argv;
        } else {
            $this->argv = $_SERVER['argv'] ?? array();
        }

        if ($options !== null) {
            $this->options = $options;
        } else {
            $this->parseCliOptions();
        }
    }

    /**
     * Parse CLI options. Subclasses implement this.
     */
    abstract protected function parseCliOptions();

    /**
     * Main CLI entry. Subclasses implement this.
     */
    abstract public function run();

    /**
     * Parse long options from argv (works with flags before or after positionals).
     * Spec format matches getopt longopts: "flag", "key:", "key::".
     *
     * @param array    $longopts
     * @param string   $shortopts Unused; kept for call-site compatibility (-h handled)
     * @param callable $helpCallback
     * @return array
     */
    protected function parseGetopt(array $longopts, $shortopts, $helpCallback)
    {
        $specs = array();
        foreach ($longopts as $spec) {
            if (substr($spec, -2) === '::') {
                $specs[substr($spec, 0, -2)] = 'optional';
            } elseif (substr($spec, -1) === ':') {
                $specs[substr($spec, 0, -1)] = 'required';
            } else {
                $specs[$spec] = 'bool';
            }
        }

        $opts = array();
        $args = array_values(array_slice($this->getArgv(), 1));
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                continue;
            }

            if ($arg === '-h' || $arg === '--help') {
                call_user_func($helpCallback);
                exit(0);
            }

            if (strpos($arg, '--') !== 0) {
                continue; // positional
            }

            $body = substr($arg, 2);
            $name = $body;
            $value = null;

            if (strpos($body, '=') !== false) {
                list($name, $value) = explode('=', $body, 2);
            }

            if (!isset($specs[$name])) {
                continue;
            }

            $type = $specs[$name];
            if ($type === 'bool') {
                $opts[$name] = false;
                continue;
            }

            if ($value === null) {
                if ($type === 'optional') {
                    $opts[$name] = false;
                    continue;
                }
                // required value: take next arg if present and not a flag
                if ($i + 1 < $count && strpos($args[$i + 1], '-') !== 0) {
                    $value = $args[++$i];
                } else {
                    continue;
                }
            }

            $opts[$name] = $value;
        }

        if (isset($opts['help'])) {
            call_user_func($helpCallback);
            exit(0);
        }

        return $opts;
    }

    /**
     * Get argv (custom or server).
     *
     * @return array
     */
    protected function getArgv()
    {
        if ($this->argv !== null) {
            return $this->argv;
        }
        return $_SERVER['argv'] ?? array();
    }

    /**
     * Merge composer extra.wordpress-updater into options.
     *
     * @param string|null $composerPath
     * @return void
     */
    protected function applyProjectConfig($composerPath = null)
    {
        if ($composerPath === null && !empty($this->options['composer']) && is_string($this->options['composer'])) {
            $composerPath = $this->options['composer'];
        }

        $config = new ProjectConfig($composerPath);
        $this->options = $config->mergeInto($this->options);
    }

    /**
     * Whether --dry-run was passed.
     */
    protected function isDryRun()
    {
        return array_key_exists('dry-run', $this->options);
    }

    /**
     * Whether --no-dev was passed.
     */
    protected function wantsNoDev()
    {
        return array_key_exists('no-dev', $this->options);
    }

    /**
     * Directory for Composer install (source, composer.json dir, or cwd).
     *
     * @return string
     */
    protected function resolveComposerWorkingDir()
    {
        if (!empty($this->options['source']) && is_string($this->options['source'])) {
            return $this->options['source'];
        }

        if (!empty($this->options['composer']) && is_string($this->options['composer'])) {
            $dir = dirname($this->options['composer']);
            if ($dir !== '' && $dir !== '.') {
                return $dir;
            }
        }

        return getcwd();
    }

    /**
     * Run composer install without require-dev (for packaging).
     *
     * @param bool $dryRun
     * @throws Exception
     */
    protected function composerInstallNoDev($dryRun = false)
    {
        $workingDir = $this->resolveComposerWorkingDir();
        $cmd = 'composer install --no-dev --optimize-autoloader --working-dir=' . escapeshellarg($workingDir);

        if ($dryRun) {
            $this->info('[dry-run] Would run: ' . $cmd);
            return;
        }

        $this->info('Running: ' . $cmd);
        $this->runComposerCommand($cmd, 'composer install --no-dev failed');
    }

    /**
     * Restore full composer install including require-dev.
     *
     * @param bool $dryRun
     * @throws Exception
     */
    protected function composerInstallRestore($dryRun = false)
    {
        $workingDir = $this->resolveComposerWorkingDir();
        $cmd = 'composer install --working-dir=' . escapeshellarg($workingDir);

        if ($dryRun) {
            $this->info('[dry-run] Would run: ' . $cmd);
            return;
        }

        $this->info('Restoring: ' . $cmd);
        $this->runComposerCommand($cmd, 'composer install (restore) failed');
    }

    /**
     * @param string $cmd
     * @param string $errorMessage
     * @throws Exception
     */
    private function runComposerCommand($cmd, $errorMessage)
    {
        $out = array();
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);

        if (!empty($out)) {
            echo implode("\n", $out) . "\n";
        }

        if ($code !== 0) {
            throw new Exception($errorMessage . ' (exit ' . $code . ')');
        }
    }

    protected function success($message)
    {
        echo "\033[32m✓ " . $message . "\033[0m\n";
    }

    protected function info($message)
    {
        echo "\033[34mℹ " . $message . "\033[0m\n";
    }

    protected function error($message)
    {
        echo "\033[31m✗ " . $message . "\033[0m\n";
    }
}
