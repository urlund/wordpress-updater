<?php
/**
 * GitHub Release Publisher
 *
 * Publishes a plugin ZIP and release.json as release assets.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class GitHubPublisher extends AbstractCli
{
    protected function parseCliOptions()
    {
        $longopts = array(
            'repo:',
            'zip:',
            'json:',
            'token::',
            'create',
            'composer:',
            'dry-run',
            'help',
        );
        $this->options = $this->parseGetopt($longopts, 'h', array($this, 'showHelp'));
        $this->applyProjectConfig();
    }

    public function run()
    {
        try {
            $this->publish();
        } catch (Exception $e) {
            $this->error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Publish zip + json to GitHub release.
     *
     * @throws Exception
     */
    public function publish()
    {
        $this->validateOptions();

        if ($this->isDryRun()) {
            $this->info('[dry-run] Would publish ' . $this->options['zip'] . ' and ' . $this->options['json'] . ' to ' . $this->options['repo']);
            return;
        }

        $jsonData = json_decode(file_get_contents($this->options['json']), true);
        $version = $jsonData['version'] ?? null;
        if (!$version) {
            throw new Exception('No version found in release.json');
        }
        $version = ltrim($version, 'v');
        $tag = 'v' . $version;

        if (!empty($jsonData['sha256'])) {
            $actualSha = hash_file('sha256', $this->options['zip']);
            if (strtolower($jsonData['sha256']) !== strtolower($actualSha)) {
                throw new Exception("SHA-256 mismatch: release.json has {$jsonData['sha256']}, but zip file is {$actualSha}");
            }
            $this->info('SHA-256 validated for zip file');
        }

        $release = $this->findRelease($version);
        if (!$release) {
            if (isset($this->options['create'])) {
                $this->info("Release not found for tag {$tag}, creating new release...");
                $release = $this->createRelease($version, $tag);
                $this->success("Created new release for tag {$tag} (ID: {$release['id']})");
            } else {
                throw new Exception("No release found for tag {$tag} (use --create to create one)");
            }
        } else {
            $this->info("Found release for tag {$tag} (ID: {$release['id']})");
        }

        $this->info("Uploading ZIP asset to release {$tag}");
        $this->uploadAsset($release['id'], $this->options['zip']);
        $this->success("Uploaded ZIP asset to release {$tag}");

        $this->info("Uploading release.json to release {$tag}");
        $this->uploadAsset($release['id'], $this->options['json']);
        $this->success("Uploaded release.json to release {$tag}");
    }

    private function validateOptions()
    {
        foreach (array('repo', 'zip', 'json') as $key) {
            if (empty($this->options[$key])) {
                throw new Exception("--{$key} is required (or set in composer.json extra.wordpress-updater)");
            }
        }
        if (!file_exists($this->options['zip'])) {
            throw new Exception('ZIP file not found: ' . $this->options['zip']);
        }
        if (!file_exists($this->options['json'])) {
            throw new Exception('release.json not found: ' . $this->options['json']);
        }
        if (!$this->isDryRun() && empty($this->options['token']) && getenv('GITHUB_TOKEN') === false) {
            throw new Exception('GitHub token required via --token or GITHUB_TOKEN env var');
        }
    }

    private function getToken()
    {
        return $this->options['token'] ?? getenv('GITHUB_TOKEN');
    }

    /**
     * Find a release matching version with or without v prefix.
     *
     * @param string $version
     * @return array|null
     * @throws Exception
     */
    private function findRelease($version)
    {
        $version = ltrim($version, 'v');
        $candidates = array($version, 'v' . $version);

        $url = 'https://api.github.com/repos/' . $this->options['repo'] . '/releases';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: token ' . $this->getToken(),
            'Accept: application/vnd.github+json',
        ));
        $result = curl_exec($ch);
        if ($result === false) {
            throw new Exception('Failed to fetch releases: ' . curl_error($ch));
        }
        $releases = json_decode($result, true);
        if (!is_array($releases)) {
            throw new Exception('Unexpected GitHub API response when listing releases');
        }

        foreach ($releases as $release) {
            $tagName = $release['tag_name'] ?? '';
            $name = $release['name'] ?? '';
            if (in_array($tagName, $candidates, true) || in_array($name, $candidates, true)) {
                return $release;
            }
        }
        return null;
    }

    private function createRelease($version, $tag)
    {
        $url = 'https://api.github.com/repos/' . $this->options['repo'] . '/releases';
        $data = array(
            'tag_name' => $tag,
            'name' => $tag,
            'body' => 'Release ' . $version,
            'draft' => false,
            'prerelease' => false,
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: token ' . $this->getToken(),
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($result === false) {
            throw new Exception('Failed to create release: ' . curl_error($ch));
        }

        if ($httpCode >= 300) {
            throw new Exception("Failed to create release: HTTP {$httpCode}\n" . $result);
        }

        return json_decode($result, true);
    }

    private function uploadAsset($releaseId, $filePath)
    {
        $repo = $this->options['repo'];
        $url = 'https://uploads.github.com/repos/' . $repo . '/releases/' . $releaseId . '/assets?name=' . urlencode(basename($filePath));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'zip':
                $contentType = 'application/zip';
                break;
            case 'json':
                $contentType = 'application/json';
                break;
            default:
                $contentType = 'application/octet-stream';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: token ' . $this->getToken(),
            'Content-Type: ' . $contentType,
            'Accept: application/vnd.github+json',
        ));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filePath));
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 300) {
            throw new Exception("Failed to upload asset: HTTP {$httpCode}\n" . $result);
        }
    }

    public function showHelp()
    {
        echo "GitHub Release Publisher\n";
        echo "========================\n\n";
        echo "Usage:\n";
        echo "  wp-publish --repo=owner/repo --zip=dist/plugin.zip --json=dist/release.json [--token=ghp_xxx] [--create]\n\n";
        echo "Options:\n";
        echo "  --repo=owner/repo   GitHub repository (required, or composer extra)\n";
        echo "  --zip=FILE          Path to plugin ZIP file (required)\n";
        echo "  --json=FILE         Path to release.json (required, for version)\n";
        echo "  --token=TOKEN       GitHub token (optional, else use GITHUB_TOKEN env)\n";
        echo "  --create            Create release if it doesn't exist\n";
        echo "  --composer=FILE     Path to composer.json for project config\n";
        echo "  --dry-run           Print actions without calling GitHub\n";
        echo "  --help              Show this help\n";
    }
}
