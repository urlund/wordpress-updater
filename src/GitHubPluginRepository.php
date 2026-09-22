<?php

namespace Urlund\WordPress\Updater;

/**
 * GitHub-backed updater for WordPress plugins.
 */
class GitHubPluginRepository extends AbstractGitHubRepository
{
    /** @var string plugin_basename path e.g. my-plugin/my-plugin.php */
    protected $plugin;

    /**
     * @param string $plugin     plugin_basename(__FILE__)
     * @param string $repository owner/repo
     * @param array  $config
     */
    protected function __construct($plugin, $repository, $config = array())
    {
        $this->plugin = $plugin;

        if (empty($this->plugin) || empty($repository)) {
            throw new \InvalidArgumentException('Both $plugin and $repository parameters are required.');
        }

        $slugDefault = dirname($this->plugin);
        if ($slugDefault === '.' || $slugDefault === '') {
            $slugDefault = basename($this->plugin, '.php');
        }

        $this->bootClient($repository, $config, $slugDefault, 'plugin', $this->plugin);

        if (!defined('WP_PLUGIN_DIR') || !file_exists(WP_PLUGIN_DIR . '/' . $this->plugin)) {
            return;
        }

        $this->init_hooks();
    }

    protected function init_hooks()
    {
        add_filter('plugins_api', array($this, 'plugins_api'), 20, 3);
        add_filter('site_transient_update_plugins', array($this, 'site_transient_update_plugins'));
        add_action('upgrader_process_complete', array($this, 'upgrader_process_complete'), 10, 2);
    }

    public function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->config['slug']) {
            return $result;
        }

        $metadata = $this->client->get_metadata();
        if (empty($metadata)) {
            return $result;
        }

        $data = $this->client->get_data();
        if (!empty($data['body'])) {
            if (!isset($metadata->sections) || !is_array($metadata->sections)) {
                $metadata->sections = array();
            }
            $metadata->sections['other_notes'] = wp_kses_post($data['body']);
        }

        return (object) array(
            'slug' => $this->config['slug'],
            'name' => $metadata->name,
            'version' => $metadata->version,
            'tested' => $metadata->tested ?? '',
            'requires' => $metadata->requires ?? '',
            'requires_php' => $metadata->requires_php ?? '',
            'author' => $metadata->author ?? '',
            'author_profile' => $metadata->author_profile ?? '',
            'last_updated' => $metadata->last_updated ?? '',
            'download_link' => $metadata->download_link ?? '',
            'trunk' => $metadata->trunk ?? '',
            'sections' => $metadata->sections ?? array(),
            'banners' => $metadata->banners ?? array(),
            'icons' => $metadata->icons ?? array(),
            'upgrade_notice' => $metadata->upgrade_notice ?? '',
        );
    }

    public function site_transient_update_plugins($value)
    {
        if (empty($value) || empty($value->checked)) {
            return $value;
        }

        $metadata = $this->client->get_metadata();
        if (empty($metadata)) {
            return $value;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin, false, false);
        if (version_compare($plugin_data['Version'], $metadata->version, '>=')) {
            return $value;
        }

        if (!empty($metadata->requires) && version_compare($metadata->requires, get_bloginfo('version'), '>')) {
            return $value;
        }

        $value->response[$this->plugin] = (object) array(
            'id' => 'github.com/' . $this->repository,
            'slug' => $this->config['slug'],
            'plugin' => $this->plugin,
            'new_version' => $metadata->version,
            'tested' => $metadata->tested ?? '',
            'package' => $metadata->download_link ?? '',
            'url' => $metadata->author_profile ?? '',
            'requires' => $metadata->requires ?? '',
            'requires_php' => $metadata->requires_php ?? '',
        );

        return $value;
    }

    public function upgrader_process_complete($upgrader, $options)
    {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = $options['plugins'] ?? array();
        if (in_array($this->plugin, $plugins, true)) {
            $this->client->clear_cache();
        }
    }
}
