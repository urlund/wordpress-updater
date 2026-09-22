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

    abstract protected function init_hooks();
}
