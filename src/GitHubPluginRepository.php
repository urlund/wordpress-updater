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
        add_filter('upgrader_pre_download', array($this, 'upgrader_pre_download'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'upgrader_process_complete'), 10, 2);
        add_action(
            'in_plugin_update_message-' . $this->plugin,
            array($this, 'in_plugin_update_message'),
            10,
            2
        );
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
        $current_version = $plugin_data['Version'];

        if (!isset($value->response) || !is_array($value->response)) {
            $value->response = array();
        }
        if (!isset($value->no_update) || !is_array($value->no_update)) {
            $value->no_update = array();
        }

        unset($value->response[$this->plugin], $value->no_update[$this->plugin]);

        $update_available = version_compare($current_version, $metadata->version, '<');
        $wp_compatible = empty($metadata->requires)
            || version_compare($metadata->requires, get_bloginfo('version'), '<=');

        // no_update is required for the Enable auto-updates UI when current.
        if ($update_available && $wp_compatible) {
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
                'upgrade_notice' => $metadata->upgrade_notice ?? '',
            );
        } else {
            $value->no_update[$this->plugin] = (object) array(
                'id' => 'github.com/' . $this->repository,
                'slug' => $this->config['slug'],
                'plugin' => $this->plugin,
                'new_version' => $current_version,
                'tested' => $metadata->tested ?? '',
                'package' => '',
                'url' => $metadata->author_profile ?? '',
                'requires' => $metadata->requires ?? '',
                'requires_php' => $metadata->requires_php ?? '',
                'icons' => is_array($metadata->icons ?? null) ? $metadata->icons : array(),
                'banners' => is_array($metadata->banners ?? null) ? $metadata->banners : array(),
                'banners_rtl' => array(),
                'compatibility' => new \stdClass(),
            );
        }

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

    /**
     * Print upgrade_notice under the Plugins list update row.
     *
     * Core only fires in_plugin_update_message-{$file}; it does not render upgrade_notice itself.
     *
     * @param array  $plugin_data Plugin header data.
     * @param object $response    Update response object from the transient.
     */
    public function in_plugin_update_message($plugin_data, $response)
    {
        if (empty($response->upgrade_notice)) {
            return;
        }

        // Hook runs inside core's <p>; avoid wpautop/<p> so CSS does not add extra icons.
        echo '<br />' . wp_kses_post($response->upgrade_notice);
    }
}
