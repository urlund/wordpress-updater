# WordPress Updater

GitHub-based updates for WordPress **plugins and themes**, plus CLI tools to bump versions, build ZIPs, generate `release.json`, and publish releases.

## Installation

```bash
composer require urlund/wordpress-updater
```

Requirements: PHP 7.4+, `ext-curl`, `ext-zip`, `ext-json`. WordPress 5.0+ for the runtime updaters.

## Quick start (CLI)

Configure once in your project’s `composer.json`:

```json
{
  "extra": {
    "wordpress-updater": {
      "type": "plugin",
      "plugin": "my-plugin.php",
      "slug": "my-plugin",
      "repo": "owner/my-plugin",
      "tested": "6.7",
      "output_dir": "dist"
    }
  }
}
```

For themes:

```json
{
  "extra": {
    "wordpress-updater": {
      "type": "theme",
      "stylesheet": "style.css",
      "slug": "my-theme",
      "repo": "owner/my-theme",
      "tested": "6.7",
      "output_dir": "dist"
    }
  }
}
```

Release (CLIs are installed to `vendor/bin/`):

```bash
./vendor/bin/wp-release patch
./vendor/bin/wp-release minor --commit --tag --publish
./vendor/bin/wp-release patch --dry-run
```

`wp-release` runs: **bump → zip → release.json** (and **publish** only with `--publish`).

Publishing requires a GitHub token via `--token=…` or the `GITHUB_TOKEN` environment variable (e.g. `export GITHUB_TOKEN=ghp_…`).

Composer does not inherit scripts from dependencies. To use `composer run wp-release`, add them to your project’s `composer.json`:

```json
{
  "scripts": {
    "wp-json": "wp-json",
    "wp-zip": "wp-zip",
    "wp-publish": "wp-publish",
    "wp-version": "wp-version",
    "wp-release": "wp-release"
  }
}
```

Then: `composer run wp-release -- patch --publish`.

### `extra.wordpress-updater` keys

| Key | Description |
|-----|-------------|
| `type` | `plugin` or `theme` |
| `plugin` | Path to main plugin PHP file (plugins) |
| `stylesheet` | Path to `style.css` (themes; default `style.css`) |
| `slug` | Slug / folder name inside the ZIP |
| `repo` | GitHub `owner/repo` |
| `tested` | WordPress “tested up to” |
| `requires_php` | Minimum PHP version |
| `config` | JSON file for banners/icons/upgrade notice |
| `sections_dir` | Directory with description/changelog markdown |
| `source` | Source directory to package (default: cwd) |
| `output_dir` | Output directory (default: `dist`) |

Download URLs are built as:

`https://github.com/{repo}/releases/download/v{version}/{slug}-{version}.zip`

---

## Runtime: WordPress updates from GitHub

### Plugin

```php
use Urlund\WordPress\Updater\GitHubPluginRepository;

GitHubPluginRepository::getInstance(
    plugin_basename(__FILE__),
    'owner/repo',
    [
        'auth' => getenv('GITHUB_TOKEN') ?: null,
    ]
);
```

### Theme

```php
use Urlund\WordPress\Updater\GitHubThemeRepository;

GitHubThemeRepository::getInstance(
    get_stylesheet(), // or get_template() for a parent theme
    'owner/repo',
    [
        'auth' => getenv('GITHUB_TOKEN') ?: null,
    ]
);
```

Attach `release.json` and the versioned ZIP to each GitHub release. The updater prefers `release.json`, then falls back to parsing the ZIP.

---

## Individual CLI tools

| Command | Role |
|---------|------|
| `wp-version` | Bump version in plugin PHP or `style.css` |
| `wp-zip` | Package `{slug}-{version}.zip` |
| `wp-json` | Generate `release.json` |
| `wp-publish` | Upload ZIP + `release.json` to GitHub |
| `wp-release` | Full pipeline |

```bash
./vendor/bin/wp-version --plugin=my-plugin.php patch
./vendor/bin/wp-version --type=theme --stylesheet=style.css minor --commit --tag
./vendor/bin/wp-json --type=plugin --plugin=my-plugin.php --output=dist/release.json
./vendor/bin/wp-publish --repo=owner/repo --zip=dist/my-plugin-1.0.1.zip --json=dist/release.json --create
```

Git flags on version bump are opt-in: `--commit`, `--tag` (requires `--commit`), `--push`. Use `--bump-composer` only if you also want to bump Composer’s top-level `version`.

---

## GitHub Actions

```yaml
name: Release
on:
  workflow_dispatch:
    inputs:
      bump:
        description: 'patch, minor, major, or x.y.z'
        required: true
        default: 'patch'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          extensions: curl, zip
      - run: composer install --no-dev --optimize-autoloader
      - name: Release
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          ./vendor/bin/wp-release ${{ github.event.inputs.bump }} \
            --commit --tag --push --publish
```

---

## License

MIT
