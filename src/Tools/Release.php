<?php
/**
 * Orchestrate bump → zip → release.json → optional publish.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class Release extends AbstractCli
{
    /** @var string|null */
    private $bumpType = null;

    protected function parseCliOptions()
    {
        $opts = $this->parseGetopt(
            array(
                'type:',
                'plugin:',
                'stylesheet:',
                'file:',
                'slug:',
                'repo:',
                'source:',
                'output-dir:',
                'tested:',
                'requires-php:',
                'sections-dir:',
                'composer:',
                'token::',
                'publish',
                'create',
                'commit',
                'tag',
                'push',
                'bump-composer',
                'dry-run',
                'help',
            ),
            'h',
            array($this, 'showHelp')
        );

        $args = array_slice($this->getArgv(), 1);
        $bump = null;
        foreach ($args as $arg) {
            if ($arg === '--' || strpos($arg, '-') === 0) {
                continue;
            }
            if (in_array($arg, array('patch', 'minor', 'major'), true) || preg_match('/^\d+\.\d+\.\d+$/', $arg)) {
                $bump = $arg;
                break;
            }
        }

        $this->options = $opts;
        $this->bumpType = $bump;
        $this->applyProjectConfig();
    }

    public function run()
    {
        try {
            $result = $this->release();
            $this->success('Release complete: v' . $result['version']);
            $this->info('ZIP:  ' . $result['zip']);
            $this->info('JSON: ' . $result['json']);
        } catch (Exception $e) {
            $this->error($e->getMessage());
            exit(1);
        }
    }

    /**
     * @return array{version:string,zip:string,json:string}
     * @throws Exception
     */
    public function release()
    {
        if ($this->bumpType === null && isset($this->options['bump'])) {
            $this->bumpType = $this->options['bump'];
        }
        if ($this->bumpType === null) {
            throw new Exception('Missing bump type (patch|minor|major|<version>)');
        }

        $type = $this->options['type'] ?? 'plugin';
        $file = ProjectConfig::resolveMainFile($this->options);
        if (!$file) {
            throw new Exception('--plugin or --stylesheet is required (or set extra.wordpress-updater)');
        }
        $this->options['file'] = $file;
        $this->options['type'] = $type;

        $slug = $this->options['slug'] ?? $this->options['name'] ?? null;
        if (!$slug) {
            $dir = dirname($file);
            $slug = (basename($dir) !== '.') ? basename($dir) : basename($file, '.' . pathinfo($file, PATHINFO_EXTENSION));
            $this->options['slug'] = $slug;
        }
        $this->options['name'] = $this->options['name'] ?? $slug;

        $outputDir = ProjectConfig::resolveOutputDir($this->options);
        $dryRun = $this->isDryRun();

        $this->info('Step 1/4: Bump version (' . $this->bumpType . ') [' . $type . ']');
        $bumpOptions = array(
            'type' => $type,
            'file' => $file,
            'plugin' => $file,
        );
        if (!empty($this->options['composer'])) {
            $bumpOptions['composer'] = $this->options['composer'];
        }
        foreach (array('bump-composer', 'commit', 'tag', 'push', 'dry-run') as $flag) {
            if (array_key_exists($flag, $this->options)) {
                $bumpOptions[$flag] = true;
            }
        }

        $bumper = new VersionBump($bumpOptions);
        $bumper->setBumpType($this->bumpType);
        $version = $bumper->bump();

        $zipPath = $outputDir . DIRECTORY_SEPARATOR . $slug . '-' . $version . '.zip';
        $jsonPath = $outputDir . DIRECTORY_SEPARATOR . ProjectConfig::RELEASE_JSON;

        $this->info('Step 2/4: Package ZIP');
        if ($dryRun) {
            $this->info('[dry-run] Would create ' . $zipPath);
        } else {
            $zipOptions = array(
                'name' => $slug,
                'slug' => $slug,
                'version' => $version,
                'output' => $zipPath,
                'source' => $this->options['source'] ?? getcwd(),
            );
            $packager = new ZipPackager($zipOptions);
            $zipPath = $packager->package();
        }

        $this->info('Step 3/4: Generate release.json');
        $jsonOptions = array(
            'type' => $type,
            'file' => $file,
            'slug' => $slug,
            'version' => $version,
            'output' => $jsonPath,
            'zip' => $zipPath,
        );
        if ($type === 'theme') {
            $jsonOptions['stylesheet'] = $file;
        } else {
            $jsonOptions['plugin'] = $file;
        }
        if (!empty($this->options['repo'])) {
            $jsonOptions['repo'] = $this->options['repo'];
            $jsonOptions['download-url'] = ProjectConfig::buildDownloadUrl($this->options['repo'], $slug, $version);
        }
        foreach (array('tested', 'requires-php', 'sections-dir', 'banners', 'icons', 'upgrade_notice', 'screenshot_url') as $key) {
            if (!empty($this->options[$key])) {
                $jsonOptions[$key] = $this->options[$key];
            }
        }

        if ($dryRun) {
            $this->info('[dry-run] Would write ' . $jsonPath);
            if (!empty($jsonOptions['download-url'])) {
                $this->info('[dry-run] download_link: ' . $jsonOptions['download-url']);
            }
        } else {
            $generator = new ReleaseJsonGenerator($jsonOptions);
            $jsonPath = $generator->generate();
        }

        if (isset($this->options['publish'])) {
            $this->info('Step 4/4: Publish to GitHub');
            if (empty($this->options['repo'])) {
                throw new Exception('--repo is required for --publish (or set extra.wordpress-updater.repo)');
            }
            $publishOptions = array(
                'repo' => $this->options['repo'],
                'zip' => $zipPath,
                'json' => $jsonPath,
                'create' => true,
            );
            if (!empty($this->options['token'])) {
                $publishOptions['token'] = $this->options['token'];
            }
            if ($dryRun) {
                $publishOptions['dry-run'] = true;
            }
            $publisher = new GitHubPublisher($publishOptions);
            $publisher->publish();
        } else {
            $this->info('Step 4/4: Publish skipped (pass --publish to upload)');
        }

        return array(
            'version' => $version,
            'zip' => $zipPath,
            'json' => $jsonPath,
        );
    }

    public function showHelp()
    {
        echo "WordPress Release\n";
        echo "=================\n\n";
        echo "Orchestrates: bump → zip → release.json → optional GitHub publish.\n\n";
        echo "Usage:\n";
        echo "  wp-release patch\n";
        echo "  wp-release minor --commit --tag --publish\n";
        echo "  wp-release patch --dry-run\n\n";
        echo "Config (composer.json):\n";
        echo "  \"extra\": {\n";
        echo "    \"wordpress-updater\": {\n";
        echo "      \"type\": \"plugin\",\n";
        echo "      \"plugin\": \"my-plugin.php\",\n";
        echo "      \"slug\": \"my-plugin\",\n";
        echo "      \"repo\": \"owner/my-plugin\",\n";
        echo "      \"tested\": \"6.7\",\n";
        echo "      \"output_dir\": \"dist\"\n";
        echo "    }\n";
        echo "  }\n\n";
        echo "For themes use \"type\": \"theme\" and \"stylesheet\": \"style.css\".\n\n";
        echo "Options:\n";
        echo "  patch|minor|major|<ver>  Version bump type (required)\n";
        echo "  --type=plugin|theme       Package type\n";
        echo "  --plugin=FILE             Main plugin PHP file\n";
        echo "  --stylesheet=FILE         Theme style.css\n";
        echo "  --slug=STRING             Slug / ZIP folder name\n";
        echo "  --repo=owner/repo         GitHub repository\n";
        echo "  --source=DIR              Source directory to package\n";
        echo "  --output-dir=DIR          Output directory (default: dist)\n";
        echo "  --publish                 Upload zip + release.json to GitHub\n";
        echo "  --commit / --tag / --push Git side effects (opt-in)\n";
        echo "  --dry-run                 Print plan without writing\n";
        echo "  --help, -h                Show this help\n";
    }
}
