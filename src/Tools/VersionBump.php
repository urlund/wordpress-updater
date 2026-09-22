<?php
/**
 * Plugin version bump CLI.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class VersionBump extends AbstractCli
{
    /** @var string|null */
    private $bumpType = null;

    /** @var bool */
    private $bumpedComposer = false;

    protected function parseCliOptions()
    {
        $opts = $this->parseGetopt(
            array('type:', 'plugin:', 'stylesheet:', 'file:', 'composer:', 'bump-composer', 'commit', 'tag', 'push', 'dry-run', 'help'),
            '',
            array($this, 'showHelp')
        );

        $args = array_slice($this->getArgv(), 1);
        $bump = null;
        foreach ($args as $arg) {
            if (in_array($arg, array('patch', 'minor', 'major'), true) || preg_match('/^\d+\.\d+\.\d+$/', $arg)) {
                $bump = $arg;
                break;
            }
        }

        $this->options = $opts;
        $this->applyProjectConfig();
        $opts = $this->options;
        $opts['file'] = ProjectConfig::resolveMainFile($opts);
        $opts['plugin'] = $opts['file'];

        if (empty($opts['file'])) {
            $this->showHelp('Missing --plugin or --stylesheet (or extra.wordpress-updater)');
            exit(1);
        }
        if (!$bump) {
            $this->showHelp('Missing bump type (patch|minor|major|<version>)');
            exit(1);
        }

        $this->options = $opts;
        $this->bumpType = $bump;
    }

    public function run()
    {
        try {
            $newVersion = $this->bump();
            $this->success('Version is now ' . $newVersion);
        } catch (Exception $e) {
            $this->error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Bump version and optionally commit/tag/push. Returns new version string.
     *
     * @return string
     * @throws Exception
     */
    public function bump()
    {
        if ($this->bumpType === null && isset($this->options['bump'])) {
            $this->bumpType = $this->options['bump'];
        }
        if ($this->bumpType === null) {
            throw new Exception('Missing bump type (patch|minor|major|<version>)');
        }
        if (empty($this->options['file'])) {
            $this->options['file'] = ProjectConfig::resolveMainFile($this->options);
        }
        if (empty($this->options['file'])) {
            throw new Exception('--plugin or --stylesheet is required');
        }
        $this->options['plugin'] = $this->options['file'];

        $dryRun = $this->isDryRun();
        $newVersion = null;
        $this->bumpedComposer = false;

        // Only bump composer.json version when --bump-composer is set
        if (isset($this->options['bump-composer'])) {
            $composerFile = !empty($this->options['composer']) && is_string($this->options['composer'])
                ? $this->options['composer']
                : 'composer.json';
            $newVersion = $this->bumpComposerJson($composerFile, $this->bumpType, $dryRun);
            $this->bumpedComposer = true;
        }

        if ($newVersion) {
            $this->setPluginFileVersion($this->options['plugin'], $newVersion, $dryRun);
        } else {
            $newVersion = $this->bumpPluginFile($this->options['plugin'], $this->bumpType, $dryRun);
        }

        if ($dryRun) {
            $this->info('[dry-run] Would set version to ' . $newVersion);
            return $newVersion;
        }

        $wantCommit = isset($this->options['commit']);
        $wantTag = isset($this->options['tag']);
        $wantPush = isset($this->options['push']);

        if ($wantTag && !$wantCommit) {
            throw new Exception('--tag requires --commit');
        }

        if (($wantCommit || $wantTag || $wantPush) && !$this->isGitManaged($this->options['plugin'])) {
            throw new Exception('Plugin file is not inside a git repository');
        }

        if ($wantCommit) {
            $this->commitVersionChanges($newVersion);
        }

        if ($wantTag) {
            $this->createGitTag($newVersion);
        }

        if ($wantPush) {
            $this->pushGit($newVersion, $wantTag);
        }

        return $newVersion;
    }

    /**
     * Set bump type when constructing with pre-parsed options.
     *
     * @param string $bumpType
     * @return $this
     */
    public function setBumpType($bumpType)
    {
        $this->bumpType = $bumpType;
        return $this;
    }

    private function bumpComposerJson($composerFile, $bumpType, $dryRun = false)
    {
        if (!file_exists($composerFile)) {
            throw new Exception('composer.json not found: ' . $composerFile);
        }
        $data = json_decode(file_get_contents($composerFile), true);
        if (empty($data['version'])) {
            throw new Exception('No version found in ' . $composerFile);
        }
        $oldVersion = $data['version'];
        $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);

        if ($dryRun) {
            $this->info("[dry-run] Would update {$composerFile}: {$oldVersion} → {$newVersion}");
            return $newVersion;
        }

        $data['version'] = $newVersion;
        file_put_contents($composerFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->success("composer.json version updated: {$oldVersion} → {$newVersion}");
        return $newVersion;
    }

    private function setPluginFileVersion($pluginFile, $version, $dryRun = false)
    {
        $ext = strtolower(pathinfo($pluginFile, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $data = json_decode(file_get_contents($pluginFile), true);
            $oldVersion = $data['version'] ?? null;
            if ($dryRun) {
                $this->info("[dry-run] Would update {$pluginFile}: {$oldVersion} → {$version}");
                return;
            }
            $data['version'] = $version;
            file_put_contents($pluginFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->success("{$pluginFile} version set: {$oldVersion} → {$version}");
        } elseif ($ext === 'php' || $ext === 'css') {
            $lines = file($pluginFile);
            $found = false;
            $oldVersion = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^(\s*\*?\s*Version:\s*)(.+)$/i', $line, $m)) {
                    $oldVersion = trim($m[2]);
                    $lines[$i] = $m[1] . $version . "\n";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new Exception('No Version: header found in ' . $pluginFile);
            }
            if ($dryRun) {
                $this->info("[dry-run] Would update {$pluginFile}: {$oldVersion} → {$version}");
                return;
            }
            file_put_contents($pluginFile, implode('', $lines));
            $this->success("{$pluginFile} version set: {$oldVersion} → {$version}");
        } else {
            throw new Exception('Unsupported plugin file type: ' . $pluginFile);
        }
    }

    private function bumpPluginFile($pluginFile, $bumpType, $dryRun = false)
    {
        if (!file_exists($pluginFile)) {
            throw new Exception('Plugin file not found: ' . $pluginFile);
        }
        $ext = strtolower(pathinfo($pluginFile, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $data = json_decode(file_get_contents($pluginFile), true);
            if (empty($data['version'])) {
                throw new Exception('No version found in ' . $pluginFile);
            }
            $oldVersion = $data['version'];
            $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);
            if ($dryRun) {
                $this->info("[dry-run] Would update {$pluginFile}: {$oldVersion} → {$newVersion}");
                return $newVersion;
            }
            $data['version'] = $newVersion;
            file_put_contents($pluginFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->success("{$pluginFile} version updated: {$oldVersion} → {$newVersion}");
            return $newVersion;
        } elseif ($ext === 'php' || $ext === 'css') {
            $lines = file($pluginFile);
            $found = false;
            $oldVersion = null;
            $newVersion = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*\*?\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/i', $line, $m)) {
                    $oldVersion = $m[1];
                    $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);
                    $lines[$i] = preg_replace('/(Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)/i', '${1}' . $newVersion, $line);
                    $found = true;
                    break;
                }
            }
            if (!$found || !$oldVersion || !$newVersion) {
                throw new Exception('No Version: header found in ' . $pluginFile);
            }
            if ($dryRun) {
                $this->info("[dry-run] Would update {$pluginFile}: {$oldVersion} → {$newVersion}");
                return $newVersion;
            }
            file_put_contents($pluginFile, implode('', $lines));
            $this->success("{$pluginFile} version updated: {$oldVersion} → {$newVersion}");
            return $newVersion;
        }

        throw new Exception('Unsupported plugin file type: ' . $pluginFile);
    }

    private function isGitManaged($file)
    {
        $real = realpath($file);
        if ($real === false) {
            return false;
        }
        $dir = dirname($real);
        while ($dir && $dir !== '/' && $dir !== '.') {
            if (is_dir($dir . '/.git')) {
                return true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return false;
    }

    private function commitVersionChanges($version)
    {
        $files = array();
        if (isset($this->options['plugin'])) {
            $files[] = $this->options['plugin'];
        }
        if ($this->bumpedComposer) {
            $composerFile = !empty($this->options['composer']) && is_string($this->options['composer'])
                ? $this->options['composer']
                : 'composer.json';
            $files[] = $composerFile;
        }

        if (empty($files)) {
            return;
        }

        $addCmd = 'git add ' . implode(' ', array_map('escapeshellarg', $files));
        exec($addCmd, $out, $code);
        if ($code !== 0) {
            throw new Exception('Failed to add files to git');
        }

        $commitMsg = 'Bump version to ' . $version;
        $commitCmd = 'git commit -m ' . escapeshellarg($commitMsg);
        exec($commitCmd, $commitOut, $commitCode);
        if ($commitCode !== 0) {
            throw new Exception('Failed to commit version changes');
        }
        $this->success('Version changes committed: ' . $commitMsg);
    }

    private function createGitTag($version)
    {
        $tag = 'v' . $version;
        exec('git tag ' . escapeshellarg($tag), $out, $code);
        if ($code !== 0) {
            throw new Exception("Failed to create git tag {$tag} (maybe it already exists?)");
        }
        $this->success("Git tag {$tag} created");
    }

    private function pushGit($version, $includeTag)
    {
        exec('git push origin HEAD', $pushOut, $pushCode);
        if ($pushCode !== 0) {
            throw new Exception('Failed to push commit to origin');
        }
        $this->success('Commit pushed to origin');

        if ($includeTag) {
            $tag = 'v' . $version;
            exec('git push origin ' . escapeshellarg($tag), $tagOut, $tagCode);
            if ($tagCode !== 0) {
                throw new Exception("Failed to push git tag {$tag} to origin");
            }
            $this->success("Git tag {$tag} pushed to origin");
        }
    }

    private function getBumpedVersion($oldVersion, $bumpType)
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $bumpType)) {
            return $bumpType;
        }
        $parts = explode('.', $oldVersion);
        if (count($parts) < 3) {
            throw new Exception('Version must be semver x.y.z, got: ' . $oldVersion);
        }
        list($major, $minor, $patch) = $parts;
        switch ($bumpType) {
            case 'patch':
                $patch++;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            default:
                throw new Exception('Unknown bump type: ' . $bumpType);
        }
        return "{$major}.{$minor}.{$patch}";
    }

    public function showHelp($msg = null)
    {
        if ($msg) {
            echo "\033[31m{$msg}\033[0m\n\n";
        }
        echo "Plugin Version Bump\n";
        echo "===================\n\n";
        echo "Usage:\n";
        echo "  wp-version --plugin=plugin.php patch\n";
        echo "  wp-version --plugin=plugin.php minor --commit --tag\n";
        echo "  wp-version --plugin=plugin.php 1.2.3 --commit --tag --push\n";
        echo "  wp-version --plugin=plugin.php patch --bump-composer\n\n";
        echo "Options:\n";
        echo "  --type=plugin|theme Package type\n";
        echo "  --plugin=FILE      Path to plugin PHP file\n";
        echo "  --stylesheet=FILE  Path to theme style.css\n";
        echo "  --composer=FILE    Path to composer.json (for project config / --bump-composer)\n";
        echo "  --bump-composer    Also bump top-level version in composer.json\n";
        echo "  --commit           Commit version file changes\n";
        echo "  --tag              Create v{version} git tag (requires --commit)\n";
        echo "  --push             Push commit and tag to origin\n";
        echo "  --dry-run          Show what would change without writing\n";
        echo "  patch|minor|major  Bump type, or explicit version (e.g., 1.2.3)\n";
        echo "  --help             Show this help\n";
    }
}
