<?php

namespace Urlund\WordPress\Updater;

/**
 * Shared GitHub release fetching and metadata resolution (release.json first, ZIP fallback).
 */
class GitHubClient
{
    const RELEASE_JSON = 'release.json';

    /** @var string */
    private $repository;

    /** @var array */
    private $config;

    /** @var string plugin|theme */
    private $packageType;

    /** @var string Relative path inside the packaged ZIP (e.g. slug/plugin.php or slug/style.css) */
    private $packageFile;

    /**
     * @param string $repository
     * @param array  $config
     * @param string $packageType plugin|theme
     * @param string $packageFile Path relative to ZIP root including slug folder, e.g. my-plugin/my-plugin.php
     */
    public function __construct($repository, array $config, $packageType = 'plugin', $packageFile = '')
    {
        $this->repository = $repository;
        $this->config = $config;
        $this->packageType = $packageType;
        $this->packageFile = $packageFile;
    }

    public function setPackageFile($packageFile)
    {
        $this->packageFile = $packageFile;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Resolve package metadata from GitHub release.
     *
     * @return object|null
     */
    public function get_metadata()
    {
        $data = $this->get_data();
        if (empty($data)) {
            $this->log_error('No GitHub release data available', array(
                'repository' => $this->repository,
            ));
            return null;
        }

        if (!empty($this->config['prefer_json'])) {
            $json_metadata = $this->get_json_metadata($data);
            if ($json_metadata) {
                return $json_metadata;
            }
        }

        $download_link = $this->get_download_link();
        if ($download_link) {
            $zip_metadata = $this->get_zip_metadata($download_link);
            if ($zip_metadata) {
                $zip_metadata->last_updated = $data['published_at'] ?? '';
                if (empty($zip_metadata->download_link)) {
                    $zip_metadata->download_link = $download_link;
                }
                return $zip_metadata;
            }
        }

        if (empty($this->config['prefer_json'])) {
            $json_metadata = $this->get_json_metadata($data);
            if ($json_metadata) {
                return $json_metadata;
            }
        }

        return null;
    }

    /**
     * @return array|null
     */
    public function get_data()
    {
        $cache_key = $this->cache_key('');
        $transient = get_transient($cache_key);
        if (empty($transient)) {
            $headers = array('Accept' => 'application/json');
            if (!empty($this->config['auth'])) {
                $headers['Authorization'] = 'Bearer ' . $this->config['auth'];
            }

            $transient = wp_remote_get(
                'https://api.github.com/repos/' . $this->repository . '/releases/latest',
                array(
                    'timeout' => $this->config['timeout'],
                    'headers' => $headers,
                )
            );

            if (is_wp_error($transient) || !isset($transient['response']['code']) || (int) $transient['response']['code'] !== 200 || empty($transient['body'])) {
                return $this->handle_api_error($transient, 'fetching latest release data');
            }

            set_transient($cache_key, $transient, $this->config['cache_duration']);
        }

        $response_body = wp_remote_retrieve_body($transient);
        $decoded_data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($decoded_data)) {
            $this->log_error('Invalid or empty GitHub API response', array(
                'json_error' => json_last_error_msg(),
            ));
            return null;
        }

        return $decoded_data;
    }

    /**
     * @return string
     */
    public function get_download_link()
    {
        $data = $this->get_data();
        if (empty($data) || empty($data['assets'])) {
            return '';
        }

        $assets_names = array_map(function ($asset) {
            return strtolower($asset['name']);
        }, $data['assets']);

        $slug = $this->config['slug'];
        $search_names = array(
            $slug . '.zip',
            'latest.zip',
            $this->packageType . '.zip',
        );

        foreach ($search_names as $name) {
            $index = array_search($name, $assets_names, true);
            if ($index !== false) {
                return $data['assets'][$index]['browser_download_url'];
            }
        }

        $version = preg_match('/\d+(\.\d+)+/', $data['tag_name'] ?? '', $matches) ? $matches[0] : '';
        if ($version !== '') {
            foreach (array($slug . '-' . $version . '.zip', $version . '.zip') as $name) {
                $index = array_search($name, $assets_names, true);
                if ($index !== false) {
                    return $data['assets'][$index]['browser_download_url'];
                }
            }
        }

        return '';
    }

    public function clear_cache()
    {
        delete_transient($this->cache_key(''));
        delete_transient($this->cache_key('_json'));
        delete_transient($this->cache_key('_release'));
    }

    private function cache_key($suffix)
    {
        return 'upgrade_wp_github_api_' . $this->packageType . '_' . $this->config['slug'] . $suffix;
    }

    /**
     * @param array $data
     * @return object|null
     */
    private function get_json_metadata($data)
    {
        if (empty($data['assets'])) {
            return null;
        }

        $cache_key = $this->cache_key('_json');
        $transient = get_transient($cache_key);
        if (!empty($transient)) {
            return (object) $transient;
        }

        foreach ($data['assets'] as $asset) {
            if (strtolower($asset['name']) !== self::RELEASE_JSON) {
                continue;
            }

            $headers = array('Accept' => 'application/json');
            if (!empty($this->config['auth'])) {
                $headers['Authorization'] = 'Bearer ' . $this->config['auth'];
            }

            $response = wp_remote_get(
                $asset['browser_download_url'],
                array(
                    'timeout' => $this->config['timeout'],
                    'headers' => $headers,
                )
            );

            if (is_wp_error($response) || !isset($response['response']['code']) || (int) $response['response']['code'] !== 200) {
                $this->handle_api_error($response, 'fetching release.json asset');
                continue;
            }

            $json_data = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($json_data)) {
                $this->log_error('Invalid release.json', array(
                    'json_error' => json_last_error_msg(),
                ));
                continue;
            }

            foreach (array('name', 'version', 'slug') as $field) {
                if (empty($json_data[$field])) {
                    $this->log_error('Missing required field in release.json: ' . $field);
                    continue 2;
                }
            }

            $defaults = array(
                'tested' => '',
                'requires' => '',
                'requires_php' => '',
                'author' => '',
                'author_profile' => '',
                'last_updated' => $data['published_at'] ?? '',
                'download_link' => $this->get_download_link(),
                'trunk' => '',
                'sections' => array(),
            );
            $json_data = array_merge($defaults, $json_data);
            set_transient($cache_key, $json_data, $this->config['cache_duration']);
            return (object) $json_data;
        }

        return null;
    }

    /**
     * @param string $download_link
     * @return object|false|null
     */
    private function get_zip_metadata($download_link)
    {
        global $wp_filesystem;

        $cache_key = $this->cache_key('_release');
        $transient = get_transient($cache_key);
        if (!empty($transient)) {
            return (object) $transient;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('WP_Filesystem') || !WP_Filesystem()) {
            return false;
        }

        $temp_file = download_url($download_link);
        if (is_wp_error($temp_file)) {
            $this->log_error('Failed to download ZIP', array('error' => $temp_file->get_error_message()));
            return false;
        }

        $validation_result = $this->validate_zip_file($temp_file);
        if (is_wp_error($validation_result)) {
            unlink($temp_file);
            $this->log_error('ZIP validation failed', array('error' => $validation_result->get_error_message()));
            return false;
        }

        $temp_dir = trailingslashit(WP_CONTENT_DIR) . 'wp_updater_extract_' . uniqid();
        $wp_filesystem->mkdir($temp_dir);
        $result = unzip_file($temp_file, $temp_dir);
        unlink($temp_file);

        if (is_wp_error($result)) {
            $wp_filesystem->rmdir($temp_dir, true);
            $this->log_error('Failed to extract ZIP', array('error' => $result->get_error_message()));
            return false;
        }

        $file_path = $temp_dir . '/' . $this->packageFile;
        if (!file_exists($file_path)) {
            // Try slug/style.css or first matching header file
            $alt = $this->find_header_file($temp_dir);
            if ($alt) {
                $file_path = $alt;
            } else {
                $wp_filesystem->rmdir($temp_dir, true);
                $this->log_error('Package header file not found in ZIP', array(
                    'expected' => $this->packageFile,
                ));
                return false;
            }
        }

        $release = $this->parse_header_file($file_path, $download_link);
        $wp_filesystem->rmdir($temp_dir, true);

        if (!$release) {
            return false;
        }

        set_transient($cache_key, $release, $this->config['cache_duration']);
        return (object) $release;
    }

    private function find_header_file($temp_dir)
    {
        $slug = $this->config['slug'];
        $candidates = $this->packageType === 'theme'
            ? array($slug . '/style.css', 'style.css')
            : array($slug . '/' . $slug . '.php', $this->packageFile);

        foreach ($candidates as $rel) {
            $path = $temp_dir . '/' . $rel;
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    private function parse_header_file($file_path, $download_link)
    {
        if ($this->packageType === 'theme') {
            $headers = array(
                'Name' => 'Theme Name',
                'ThemeURI' => 'Theme URI',
                'Description' => 'Description',
                'Author' => 'Author',
                'AuthorURI' => 'Author URI',
                'Version' => 'Version',
                'RequiresWP' => 'Requires at least',
                'RequiresPHP' => 'Requires PHP',
                'Tested up to' => 'Tested up to',
            );
            $data = $this->get_file_data($file_path, $headers);
            return array(
                'slug' => $this->config['slug'],
                'name' => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
                'tested' => $data['Tested up to'] ?? '',
                'requires' => $data['RequiresWP'] ?? '',
                'requires_php' => $data['RequiresPHP'] ?? '',
                'author' => $data['Author'] ?? '',
                'author_profile' => $data['AuthorURI'] ?? $data['ThemeURI'] ?? '',
                'last_updated' => '',
                'download_link' => $download_link,
                'trunk' => '',
                'sections' => array(),
            );
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($file_path, false, false);
        return array(
            'slug' => $this->config['slug'],
            'name' => $plugin_data['Name'] ?? '',
            'version' => $plugin_data['Version'] ?? '',
            'tested' => $plugin_data['Tested up to'] ?? '',
            'requires' => $plugin_data['RequiresWP'] ?? '',
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
            'author' => $plugin_data['Author'] ?? '',
            'author_profile' => $plugin_data['PluginURI'] ?? '',
            'last_updated' => '',
            'download_link' => $download_link,
            'trunk' => '',
            'sections' => array(),
        );
    }

    private function get_file_data($file, array $headers)
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return array();
        }
        // Only scan first 8KB like WP
        $content = substr($content, 0, 8192);
        $data = array();
        foreach ($headers as $field => $regex) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $content, $match)) {
                $data[$field] = trim($match[1]);
            } else {
                $data[$field] = '';
            }
        }
        return $data;
    }

    private function validate_zip_file($file_path)
    {
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', 'ZIP file not found');
        }

        $max_size = $this->config['max_file_size'] ?? (50 * 1024 * 1024);
        if (filesize($file_path) > $max_size) {
            return new \WP_Error('file_too_large', 'File exceeds size limit');
        }

        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return new \WP_Error('file_read_error', 'Could not read ZIP file');
        }
        $signature = fread($file_handle, 4);
        fclose($file_handle);

        $valid = array("\x50\x4B\x03\x04", "\x50\x4B\x05\x06", "\x50\x4B\x07\x08");
        if (!in_array($signature, $valid, true)) {
            return new \WP_Error('invalid_zip_signature', 'Not a valid ZIP archive');
        }

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $result = $zip->open($file_path, \ZipArchive::CHECKCONS);
            if ($result !== true) {
                return new \WP_Error('zip_integrity_failed', 'ZIP integrity check failed');
            }
            $zip->close();
        }

        return true;
    }

    private function handle_api_error($response, $context = '')
    {
        $log_context = array(
            'repository' => $this->repository,
            'context' => $context,
        );
        if (is_wp_error($response)) {
            $log_context['error'] = $response->get_error_message();
        } elseif (isset($response['response']['code'])) {
            $log_context['http_code'] = $response['response']['code'];
        }
        $this->log_error('GitHub API request failed', $log_context);
        return null;
    }

    public function log_error($message, $context = array(), $level = 'error')
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $log_message = sprintf('[GitHubClient] [%s] %s', strtoupper($level), $message);
        if (!empty($context)) {
            $log_message .= ' - Context: ' . json_encode($context);
        }
        error_log($log_message);
    }
}
