<?php
/**
 * Generate release.json from plugin PHP headers or theme style.css.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;

class ReleaseJsonGenerator extends AbstractCli
{
    private $defaults = array(
        'sections' => array(
            'description' => '',
            'installation' => '',
            'faq' => '',
            'changelog' => '',
            'screenshots' => '',
            'other_notes' => '',
        ),
        'banners' => array(),
        'icons' => array(),
        'trunk' => '',
        'upgrade_notice' => '',
    );

    protected function parseCliOptions()
    {
        $longopts = array(
            'type:',
            'plugin:',
            'stylesheet:',
            'file:',
            'output:',
            'slug:',
            'download-url:',
            'repo:',
            'tested:',
            'requires-php:',
            'sections-dir:',
            'config:',
            'version:',
            'zip:',
            'composer:',
            'help',
        );

        $this->options = $this->parseGetopt($longopts, 'h', array($this, 'showHelp'));
        $this->applyProjectConfig();
    }

    public function run()
    {
        try {
            $outputFile = $this->generate();
            $this->success('release.json generated successfully: ' . $outputFile);
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    public function generate()
    {
        $this->validateOptions();
        $metadata = $this->generateMetadata();
        return $this->writeJsonFile($metadata);
    }

    private function mainFile()
    {
        return ProjectConfig::resolveMainFile($this->options);
    }

    private function packageType()
    {
        return $this->options['type'] ?? 'plugin';
    }

    private function validateOptions()
    {
        $file = $this->mainFile();
        if (!$file) {
            throw new Exception('--plugin or --stylesheet is required (or set extra.wordpress-updater)');
        }
        if (!file_exists($file)) {
            throw new Exception('Header file does not exist: ' . $file);
        }
        if (!is_readable($file)) {
            throw new Exception('Header file is not readable: ' . $file);
        }
        $this->options['file'] = $file;
    }

    private function generateMetadata()
    {
        $headerData = $this->parseHeaderFile($this->options['file']);
        $sections = $this->loadSections();
        $config = $this->loadConfig();

        $downloadUrl = $this->options['download-url'] ?? '';
        if ($downloadUrl === '' && !empty($this->options['repo'])) {
            $slug = $this->getSlug($headerData);
            $downloadUrl = ProjectConfig::buildDownloadUrl(
                $this->options['repo'],
                $slug,
                $headerData['Version']
            );
        }

        $metadata = array(
            'name' => $headerData['Name'],
            'slug' => $this->getSlug($headerData),
            'version' => $headerData['Version'],
            'type' => $this->packageType(),
            'tested' => $this->options['tested'] ?? $headerData['Tested up to'] ?? '',
            'requires' => $headerData['Requires at least'] ?? '',
            'requires_php' => $this->options['requires-php'] ?? $headerData['Requires PHP'] ?? '',
            'author' => $headerData['Author'],
            'author_profile' => $headerData['Author URI'] ?? $headerData['URI'] ?? '',
            'last_updated' => date('Y-m-d H:i:s'),
            'download_link' => $downloadUrl,
            'trunk' => $this->defaults['trunk'],
            'sections' => array_merge($this->defaults['sections'], $sections),
            'banners' => $config['banners'] ?? $this->defaults['banners'],
            'icons' => $config['icons'] ?? $this->defaults['icons'],
            'upgrade_notice' => $config['upgrade_notice'] ?? $this->defaults['upgrade_notice'],
        );

        if (isset($this->options['zip']) && is_string($this->options['zip']) && file_exists($this->options['zip'])) {
            $metadata['sha256'] = hash_file('sha256', $this->options['zip']);
        }

        return $this->removeEmptyValues($metadata);
    }

    private function parseHeaderFile($file)
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new Exception('Could not read header file');
        }

        $isTheme = $this->packageType() === 'theme' || strtolower(basename($file)) === 'style.css';
        if ($isTheme) {
            $this->options['type'] = 'theme';
            $headers = array(
                'Name' => 'Theme Name',
                'URI' => 'Theme URI',
                'Description' => 'Description',
                'Author' => 'Author',
                'Author URI' => 'Author URI',
                'Version' => 'Version',
                'Requires at least' => 'Requires at least',
                'Tested up to' => 'Tested up to',
                'Requires PHP' => 'Requires PHP',
                'Text Domain' => 'Text Domain',
                'License' => 'License',
            );
            $nameLabel = 'Theme Name';
        } else {
            $headers = array(
                'Name' => 'Plugin Name',
                'URI' => 'Plugin URI',
                'Description' => 'Description',
                'Author' => 'Author',
                'Author URI' => 'Author URI',
                'Version' => 'Version',
                'Text Domain' => 'Text Domain',
                'Requires at least' => 'Requires at least',
                'Tested up to' => 'Tested up to',
                'Requires PHP' => 'Requires PHP',
                'License' => 'License',
            );
            $nameLabel = 'Plugin Name';
        }

        $data = array();
        foreach ($headers as $key => $header) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote($header, '/') . ':\s*(.*)$/mi';
            if (preg_match($pattern, $content, $matches)) {
                $data[$key] = trim($matches[1]);
            } else {
                $data[$key] = '';
            }
        }

        if (empty($data['Name'])) {
            throw new Exception($nameLabel . ' header is required');
        }
        if (empty($data['Version'])) {
            throw new Exception('Version header is required');
        }

        return $data;
    }

    private function getSlug($headerData)
    {
        if (isset($this->options['slug'])) {
            return $this->options['slug'];
        }

        $path = $this->options['file'];
        $dir = dirname($path);
        if (basename($dir) !== '.') {
            return basename($dir);
        }

        return $this->sanitizeTitle($headerData['Name']);
    }

    private function loadSections()
    {
        $sections = array();
        $sectionsDir = $this->options['sections-dir'] ?? dirname($this->options['file']);

        if (!is_dir($sectionsDir)) {
            return $sections;
        }

        $sectionFiles = array(
            'description' => array('description.md', 'description.txt', 'README.md'),
            'installation' => array('installation.md', 'installation.txt', 'INSTALL.md'),
            'faq' => array('faq.md', 'faq.txt', 'FAQ.md'),
            'changelog' => array('changelog.md', 'changelog.txt', 'CHANGELOG.md', 'CHANGES.md'),
            'screenshots' => array('screenshots.md', 'screenshots.txt'),
            'other_notes' => array('notes.md', 'notes.txt', 'NOTES.md'),
        );

        foreach ($sectionFiles as $section => $filenames) {
            foreach ($filenames as $filename) {
                $filepath = $sectionsDir . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($filepath) && is_readable($filepath)) {
                    $content = file_get_contents($filepath);
                    if ($content !== false && !empty(trim($content))) {
                        $sections[$section] = $this->processMarkdown($content);
                        break;
                    }
                }
            }
        }

        return $sections;
    }

    private function loadConfig()
    {
        if (!isset($this->options['config'])) {
            return array();
        }
        $configFile = $this->options['config'];
        if (!file_exists($configFile)) {
            throw new Exception('Config file does not exist: ' . $configFile);
        }
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in config file: ' . json_last_error_msg());
        }
        return $config;
    }

    private function processMarkdown($content)
    {
        $content = trim($content);
        $content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
        $content = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $content);
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
        return nl2br($content);
    }

    private function removeEmptyValues($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->removeEmptyValues($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif (empty($value) && $value !== '0') {
                unset($array[$key]);
            }
        }
        return $array;
    }

    private function writeJsonFile($metadata)
    {
        $outputFile = $this->generateOutputFilename($metadata);
        $outputDir = dirname($outputFile);
        if ($outputDir !== '.' && !is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new Exception('Cannot create output directory: ' . $outputDir);
            }
        }

        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('Failed to encode JSON: ' . json_last_error_msg());
        }
        if (file_put_contents($outputFile, $json) === false) {
            throw new Exception('Failed to write file: ' . $outputFile);
        }
        $this->info('Written ' . strlen($json) . ' bytes to ' . $outputFile);
        return $outputFile;
    }

    private function generateOutputFilename($metadata)
    {
        if (isset($this->options['output'])) {
            $outputPath = $this->options['output'];
            if (strtolower(substr($outputPath, -5)) === '.json') {
                return $outputPath;
            }
            $filename = 'release';
            $version = $this->options['version'] ?? ($metadata['version'] ?? '');
            if (!empty($version)) {
                $filename .= '-' . $version;
            }
            return rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename . '.json';
        }

        $filename = 'release';
        $version = $this->options['version'] ?? ($metadata['version'] ?? '');
        if (!empty($version)) {
            $filename .= '-' . $version;
        }
        return $filename . '.json';
    }

    private function sanitizeTitle($title)
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-_]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
    }

    public function showHelp()
    {
        echo "Release JSON Generator\n";
        echo "======================\n\n";
        echo "Usage:\n";
        echo "  wp-json --type=plugin --plugin=my-plugin.php [options]\n";
        echo "  wp-json --type=theme --stylesheet=style.css [options]\n\n";
        echo "Options:\n";
        echo "  --type=plugin|theme    Package type (or composer extra)\n";
        echo "  --plugin=FILE          Main plugin PHP file\n";
        echo "  --stylesheet=FILE      Theme style.css path\n";
        echo "  --output=PATH          Output file or directory (default: release.json)\n";
        echo "  --slug=STRING          Package slug\n";
        echo "  --repo=owner/repo      Auto-build download URL\n";
        echo "  --download-url=URL     Explicit download URL\n";
        echo "  --zip=FILE             Include sha256 of ZIP\n";
        echo "  --tested=VERSION       WordPress tested-up-to\n";
        echo "  --requires-php=VERSION Minimum PHP version\n";
        echo "  --sections-dir=DIR     Markdown sections directory\n";
        echo "  --config=FILE          Banners/icons JSON\n";
        echo "  --composer=FILE        Path to composer.json\n";
        echo "  --help, -h             Show help\n";
    }
}
