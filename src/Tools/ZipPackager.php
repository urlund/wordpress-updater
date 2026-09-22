<?php
/**
 * Plugin ZIP Packager
 *
 * Package WordPress plugins into ZIP files with proper folder structure.
 *
 * @package Urlund\WordPress\Updater
 */

namespace Urlund\WordPress\Updater\Tools;

use Exception;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ZipPackager extends AbstractCli
{
    private $defaultExclusions = array(
        '.git',
        '.gitignore',
        '.gitattributes',
        '.github',
        '.gitlab-ci.yml',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'yarn.lock',
        'webpack.config.js',
        'gulpfile.js',
        'Gruntfile.js',
        '.babelrc',
        'tsconfig.json',
        'node_modules',
        'vendor/bin',
        'vendor/*/bin',
        'vendor/*/*/bin',
        '.vscode',
        '.idea',
        '.phpstorm.meta.php',
        '*.sublime-project',
        '*.sublime-workspace',
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',
        'dist',
        'build',
        '*.zip',
        '*.tar.gz',
        '*.rar',
        '*.log',
        '*.tmp',
        '*.temp',
        '.cache',
        '.env',
        '.env.local',
        '.env.example',
        'phpunit.xml',
        'phpcs.xml',
        '.phpcs.xml.dist',
        '.editorconfig',
        '.stylelintrc',
        '.eslintrc',
    );

    protected function parseCliOptions()
    {
        $longopts = array(
            'name:',
            'slug:',
            'output:',
            'source:',
            'include:',
            'exclude:',
            'no-defaults',
            'version:',
            'composer:',
            'no-dev',
            'help',
        );

        $this->options = $this->parseGetopt($longopts, 'h', array($this, 'showHelp'));
        $this->applyProjectConfig();
    }

    public function run()
    {
        try {
            $zipFile = $this->package();
            $this->success('Plugin ZIP package created: ' . $zipFile);
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Create ZIP package and return path.
     *
     * @return string
     * @throws Exception
     */
    public function package()
    {
        $this->validateOptions();

        $noDev = $this->wantsNoDev();
        $shouldRestore = false;

        if ($noDev) {
            $this->info('Installing production dependencies (--no-dev)');
            $this->composerInstallNoDev(false);
            $shouldRestore = true;
        }

        try {
            return $this->createZipPackage();
        } finally {
            if ($shouldRestore) {
                $this->info('Restoring development dependencies');
                $this->composerInstallRestore(false);
            }
        }
    }

    private function validateOptions()
    {
        // Prefer slug as folder name when name not set
        if (!isset($this->options['name']) && isset($this->options['slug'])) {
            $this->options['name'] = $this->options['slug'];
        }

        if (!isset($this->options['name'])) {
            $sourceDir = $this->options['source'] ?? getcwd();
            $this->options['name'] = basename($sourceDir);
        }

        $sourceDir = $this->options['source'] ?? getcwd();
        if (!is_dir($sourceDir)) {
            throw new Exception('Source directory does not exist: ' . $sourceDir);
        }

        if (!is_readable($sourceDir)) {
            throw new Exception('Source directory is not readable: ' . $sourceDir);
        }
    }

    private function createZipPackage()
    {
        $pluginName = $this->options['name'];
        $sourceDir = $this->options['source'] ?? getcwd();
        $version = $this->options['version'] ?? '';

        $outputFile = $this->generateOutputFilename($pluginName, $version);

        $outputDir = dirname($outputFile);
        if ($outputDir !== '.' && !is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new Exception('Cannot create output directory: ' . $outputDir);
            }
        }

        $filesToInclude = $this->getFilesToInclude($sourceDir);
        $this->info('Creating ZIP package with ' . count($filesToInclude) . ' files...');

        $zip = new ZipArchive();
        $result = $zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new Exception('Cannot create ZIP file: ' . $this->getZipError($result));
        }

        foreach ($filesToInclude as $file) {
            $relativePath = $this->getRelativePath($sourceDir, $file);
            $zipPath = $pluginName . '/' . $relativePath;

            if (is_dir($file)) {
                $zip->addEmptyDir($zipPath);
            } else {
                $zip->addFile($file, $zipPath);
            }
        }

        $zip->close();
        $this->info('Package size: ' . $this->formatBytes(filesize($outputFile)));

        return $outputFile;
    }

    private function generateOutputFilename($pluginName, $version = '')
    {
        if (isset($this->options['output'])) {
            $outputPath = $this->options['output'];

            if (strtolower(substr($outputPath, -4)) === '.zip') {
                return $outputPath;
            }

            $filename = $pluginName;
            if (!empty($version)) {
                $filename .= '-' . $version;
            }
            $filename .= '.zip';

            return rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        }

        $filename = $pluginName;
        if (!empty($version)) {
            $filename .= '-' . $version;
        }

        return $filename . '.zip';
    }

    private function getFilesToInclude($sourceDir)
    {
        $files = array();
        $exclusions = $this->getExclusionPatterns();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = $this->getRelativePath($sourceDir, $file->getPathname());

            if (!$this->shouldExcludeFile($relativePath, $exclusions)) {
                $files[] = $file->getPathname();
            }
        }

        if (isset($this->options['include'])) {
            $additionalFiles = explode(',', $this->options['include']);
            foreach ($additionalFiles as $additionalFile) {
                $additionalFile = trim($additionalFile);
                $fullPath = $sourceDir . DIRECTORY_SEPARATOR . $additionalFile;
                if (file_exists($fullPath)) {
                    $files[] = $fullPath;
                }
            }
        }

        return $files;
    }

    private function getExclusionPatterns()
    {
        $exclusions = array();

        if (!isset($this->options['no-defaults'])) {
            $exclusions = array_merge($exclusions, $this->defaultExclusions);
        }

        if (isset($this->options['exclude'])) {
            $customExclusions = explode(',', $this->options['exclude']);
            $exclusions = array_merge($exclusions, array_map('trim', $customExclusions));
        }

        return $exclusions;
    }

    private function shouldExcludeFile($relativePath, $exclusions)
    {
        if (preg_match('#^vendor/.*/bin(/.*)?$#', $relativePath)) {
            return true;
        }

        foreach ($exclusions as $pattern) {
            if (basename($relativePath) === $pattern) {
                return true;
            }
            if (strpos($relativePath, $pattern) !== false) {
                return true;
            }
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }

        return false;
    }

    private function getRelativePath($from, $to)
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = str_replace('\\', '/', $to);
        return substr($to, strlen($from) + 1);
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function getZipError($code)
    {
        $errors = array(
            ZipArchive::ER_OK => 'No error',
            ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            ZipArchive::ER_RENAME => 'Renaming temporary file failed',
            ZipArchive::ER_CLOSE => 'Closing zip archive failed',
            ZipArchive::ER_SEEK => 'Seek error',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_WRITE => 'Write error',
            ZipArchive::ER_CRC => 'CRC error',
            ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_OPEN => 'Can not open file',
            ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
            ZipArchive::ER_ZLIB => 'Zlib error',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_CHANGED => 'Entry has been changed',
            ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
            ZipArchive::ER_EOF => 'Premature EOF',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_NOZIP => 'Not a zip archive',
            ZipArchive::ER_INTERNAL => 'Internal error',
            ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            ZipArchive::ER_REMOVE => 'Can not remove file',
            ZipArchive::ER_DELETED => 'Entry has been deleted',
        );

        return $errors[$code] ?? ('Unknown error code: ' . $code);
    }

    public function showHelp()
    {
        echo "Plugin ZIP Packager\n";
        echo "===================\n\n";
        echo "Usage:\n";
        echo "  plugin-zip [options]\n\n";
        echo "Options:\n";
        echo "  --name=STRING          Plugin folder name inside ZIP (or use --slug / composer extra)\n";
        echo "  --slug=STRING          Alias for --name when packaging\n";
        echo "  --output=FILE|DIR      Output ZIP path or directory\n";
        echo "  --source=DIR           Source directory (default: current directory)\n";
        echo "  --version=STRING       Version to append to filename ({slug}-{version}.zip)\n";
        echo "  --include=FILES        Additional files to include (comma-separated)\n";
        echo "  --exclude=PATTERNS     Additional exclusion patterns (comma-separated)\n";
        echo "  --no-defaults          Don't use default exclusion patterns\n";
        echo "  --composer=FILE        Path to composer.json for project config\n";
        echo "  --no-dev               Install without require-dev before zip, restore after\n";
        echo "  --help, -h             Show this help message\n";
    }
}
