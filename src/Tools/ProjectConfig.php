<?php
/**
 * Project configuration from composer.json extra.wordpress-updater.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class ProjectConfig
{
    const EXTRA_KEY = 'wordpress-updater';
    const RELEASE_JSON = 'release.json';

    /** @var array */
    private $data = array();

    /** @var string|null */
    private $composerPath;

    /**
     * @param string|null $composerPath
     */
    public function __construct($composerPath = null)
    {
        $this->composerPath = $composerPath ?: (getcwd() . DIRECTORY_SEPARATOR . 'composer.json');
        $this->load();
    }

    private function load()
    {
        if (!file_exists($this->composerPath)) {
            $this->data = array();
            return;
        }

        $content = file_get_contents($this->composerPath);
        if ($content === false) {
            throw new Exception('Could not read composer.json: ' . $this->composerPath);
        }

        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in composer.json: ' . json_last_error_msg());
        }

        $extra = $json['extra'][self::EXTRA_KEY] ?? array();
        if (!is_array($extra)) {
            throw new Exception('composer.json extra.' . self::EXTRA_KEY . ' must be an object');
        }

        $this->data = $extra;
    }

    public function hasConfig()
    {
        return !empty($this->data);
    }

    public function getComposerPath()
    {
        return $this->composerPath;
    }

    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function all()
    {
        return $this->data;
    }

    /**
     * @param array $cliOptions
     * @return array
     */
    public function mergeInto(array $cliOptions)
    {
        $map = array(
            'type' => 'type',
            'plugin' => 'plugin',
            'stylesheet' => 'stylesheet',
            'slug' => 'slug',
            'repo' => 'repo',
            'tested' => 'tested',
            'requires_php' => 'requires-php',
            'config' => 'config',
            'sections_dir' => 'sections-dir',
            'source' => 'source',
            'output_dir' => 'output-dir',
        );

        foreach ($map as $configKey => $cliKey) {
            if (!isset($cliOptions[$cliKey]) && isset($this->data[$configKey]) && $this->data[$configKey] !== '') {
                $cliOptions[$cliKey] = $this->data[$configKey];
            }
        }

        if (!isset($cliOptions['type'])) {
            if (!empty($cliOptions['stylesheet'])) {
                $cliOptions['type'] = 'theme';
            } elseif (!empty($cliOptions['plugin'])) {
                $cliOptions['type'] = 'plugin';
            }
        }

        // Normalize main file into --file for tools
        if (!isset($cliOptions['file'])) {
            $cliOptions['file'] = self::resolveMainFile($cliOptions);
        }

        // Keep --plugin as alias of --file for plugin type (backward within this package CLI)
        if (($cliOptions['type'] ?? '') === 'plugin' && empty($cliOptions['plugin']) && !empty($cliOptions['file'])) {
            $cliOptions['plugin'] = $cliOptions['file'];
        }
        if (($cliOptions['type'] ?? '') === 'theme' && empty($cliOptions['stylesheet'])) {
            $cliOptions['stylesheet'] = $cliOptions['file'] ?? 'style.css';
        }

        if (!isset($cliOptions['name']) && isset($cliOptions['slug'])) {
            $cliOptions['name'] = $cliOptions['slug'];
        }

        return $cliOptions;
    }

    /**
     * Resolve main header file path from options.
     *
     * @param array $options
     * @return string|null
     */
    public static function resolveMainFile(array $options)
    {
        $type = $options['type'] ?? 'plugin';
        if ($type === 'theme') {
            return $options['stylesheet'] ?? $options['file'] ?? 'style.css';
        }
        return $options['plugin'] ?? $options['file'] ?? null;
    }

    /**
     * @param string $repo
     * @param string $slug
     * @param string $version
     * @return string
     */
    public static function buildDownloadUrl($repo, $slug, $version)
    {
        $version = ltrim($version, 'v');
        $filename = $slug . '-' . $version . '.zip';
        return 'https://github.com/' . $repo . '/releases/download/v' . $version . '/' . $filename;
    }

    /**
     * @param array $options
     * @return string
     */
    public static function resolveOutputDir(array $options)
    {
        return rtrim($options['output-dir'] ?? 'dist', DIRECTORY_SEPARATOR);
    }
}
