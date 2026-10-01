<?php

namespace Urlund\WordPress\Updater;

/**
 * GitHub-backed updater for WordPress themes.
 */
class GitHubThemeRepository extends AbstractGitHubRepository
{
    /** @var string Theme stylesheet slug (directory name) */
    protected $stylesheet;

    /**
     * @param string $stylesheet Theme directory slug (get_stylesheet() / get_template())
     * @param string $repository owner/repo
     * @param array  $config
     */
    protected function __construct($stylesheet, $repository, $config = array())
    {
        $this->stylesheet = $stylesheet;

        if (empty($this->stylesheet) || empty($repository)) {
            throw new \InvalidArgumentException('Both $stylesheet and $repository parameters are required.');
        }

        $packageFile = $this->stylesheet . '/style.css';
        $this->bootClient($repository, $config, $this->stylesheet, 'theme', $packageFile);

        $theme_root = function_exists('get_theme_root') ? get_theme_root($this->stylesheet) : '';
        $style_path = $theme_root
            ? trailingslashit($theme_root) . $this->stylesheet . '/style.css'
            : '';
        if ($style_path === '' || !file_exists($style_path)) {
            return;
        }

        $this->init_hooks();
    }

    protected function init_hooks()
    {
        add_filter('themes_api', array($this, 'themes_api'), 20, 3);
        add_filter('pre_set_site_transient_update_themes', array($this, 'pre_set_site_transient_update_themes'));
        add_filter('upgrader_pre_download', array($this, 'upgrader_pre_download'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'upgrader_process_complete'), 10, 2);
        add_action(
            'in_theme_update_message-' . $this->stylesheet,
            array($this, 'in_theme_update_message'),
            10,
            2
        );
    }

    public function themes_api($result, $action, $args)
    {
        if ($action !== 'theme_information') {
            return $result;
        }
        $slug = is_object($args) ? ($args->slug ?? '') : '';
        if ($slug !== $this->config['slug'] && $slug !== $this->stylesheet) {
            return $result;
        }

        $metadata = $this->client->get_metadata();
        if (empty($metadata)) {
            return $result;
        }

        return (object) array(
            'slug' => $this->config['slug'],
            'name' => $metadata->name,
            'version' => $metadata->version,
            'author' => $metadata->author ?? '',
            'screenshot_url' => $metadata->screenshot_url ?? '',
            'requires' => $metadata->requires ?? '',
            'requires_php' => $metadata->requires_php ?? '',
            'sections' => $metadata->sections ?? array(),
            'download_link' => $metadata->download_link ?? '',
            'last_updated' => $metadata->last_updated ?? '',
            'homepage' => $metadata->author_profile ?? '',
        );
    }

    public function pre_set_site_transient_update_themes($transient)
    {
        if (empty($transient)) {
            $transient = new \stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = array();
        }

        $metadata = $this->client->get_metadata();
        if (empty($metadata)) {
            return $transient;
        }

        $theme = wp_get_theme($this->stylesheet);
        if (!$theme->exists()) {
            return $transient;
        }

        $current = $theme->get('Version');

        unset($transient->response[$this->stylesheet], $transient->no_update[$this->stylesheet]);

        $update_available = version_compare($current, $metadata->version, '<');
        $wp_compatible = empty($metadata->requires)
            || version_compare($metadata->requires, get_bloginfo('version'), '<=');

        // no_update is required for the Enable auto-updates UI when current.
        if ($update_available && $wp_compatible) {
            $transient->response[$this->stylesheet] = array(
                'theme' => $this->stylesheet,
                'new_version' => $metadata->version,
                'url' => $metadata->author_profile ?? '',
                'package' => $metadata->download_link ?? '',
                'requires' => $metadata->requires ?? '',
                'requires_php' => $metadata->requires_php ?? '',
                'upgrade_notice' => $metadata->upgrade_notice ?? '',
            );
        } else {
            $transient->no_update[$this->stylesheet] = array(
                'theme' => $this->stylesheet,
                'new_version' => $current,
                'url' => $metadata->author_profile ?? '',
                'package' => '',
                'requires' => $metadata->requires ?? '',
                'requires_php' => $metadata->requires_php ?? '',
            );
        }

        return $transient;
    }

    public function upgrader_process_complete($upgrader, $options)
    {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'theme') {
            return;
        }
        $themes = $options['themes'] ?? array();
        if (in_array($this->stylesheet, $themes, true)) {
            $this->client->clear_cache();
        }
    }

    /**
     * Print upgrade_notice under the Themes list update row.
     *
     * @param \WP_Theme $theme    Theme object.
     * @param array     $response Update response from the transient.
     */
    public function in_theme_update_message($theme, $response)
    {
        $notice = is_array($response)
            ? ($response['upgrade_notice'] ?? '')
            : ($response->upgrade_notice ?? '');

        if (empty($notice)) {
            return;
        }

        // Hook runs inside core's <p>; avoid wpautop/<p> so CSS does not add extra icons.
        echo '<hr>' . wp_kses_post($notice);
    }
}
