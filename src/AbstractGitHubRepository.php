<?php

namespace Urlund\WordPress\Updater;

/**
 * Multiton base for GitHub-backed WordPress updaters.
 */
abstract class AbstractGitHubRepository
{
    /** @var array */
    protected static $instances = array();

    /** @var string */
    protected $repository;

    /** @var array */
    protected $config;

    /** @var GitHubClient */
    protected $client;

    /**
     * @param string $key Unique instance key
     * @param mixed  ...$args
     * @return static
     */
    public static function getInstance($key, ...$args)
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = array();
        }
        if (!isset(self::$instances[$cls][$key])) {
            self::$instances[$cls][$key] = new static($key, ...$args);
        }
        return self::$instances[$cls][$key];
    }

    public static function clearInstances()
    {
        $cls = static::class;
        self::$instances[$cls] = array();
    }

    /**
     * @param string $repository
     * @param array  $config
     * @param string $slugDefault
     * @param string $packageType
     * @param string $packageFile Path inside ZIP (slug/file)
     */
    protected function bootClient($repository, array $config, $slugDefault, $packageType, $packageFile)
    {
        $this->repository = $repository;
        $defaults = array(
            'auth' => null,
            'slug' => $slugDefault,
            'prefer_json' => true,
            'cache_duration' => 21600,
            'timeout' => 30,
            'max_file_size' => 52428800,
        );
        $this->config = function_exists('wp_parse_args')
            ? wp_parse_args($config, $defaults)
            : array_merge($defaults, $config);

        $this->client = new GitHubClient(
            $this->repository,
            $this->config,
            $packageType,
            $packageFile
        );
    }

    /**
     * Authenticated private installs: download ZIP via API asset URL.
     *
     * Core would otherwise hit browser_download_url with no working auth.
     *
     * @param bool|\WP_Error|string $reply
     * @param string                $package
     * @param \WP_Upgrader          $upgrader
     * @return bool|\WP_Error|string
     */
    public function upgrader_pre_download($reply, $package, $upgrader)
    {
        if ($reply !== false) {
            return $reply;
        }

        if (empty($this->config['auth'])) {
            return false;
        }

        if (!$this->client->matches_package_url($package)) {
            return false;
        }

        return $this->client->download_package();
    }

    /**
     * Markup for upgrade_notice inside the update-row <p>.
     *
     * @param string $notice
     * @param string $severity info|warning|error (critical aliases error)
     * @return string
     */
    protected function format_upgrade_notice($notice, $severity = 'info')
    {
        if ($notice === '' || $notice === null) {
            return '';
        }

        $severity = is_string($severity) ? strtolower($severity) : 'info';
        $colors = array(
            'info' => '#72aee6',
            'warning' => '#dba617',
            'error' => '#d63638',
            'critical' => '#d63638',
        );
        if (!isset($colors[$severity])) {
            $severity = 'info';
        }

        return sprintf(
            '<span style="display:block;margin:0.5em 0 0;padding:0.5em 0 0;border-top:1px solid %1$s;">%2$s</span>',
            esc_attr($colors[$severity]),
            wp_kses_post($notice)
        );
    }

    abstract protected function init_hooks();
}
