# WordPress Updater

GitHub-based updates for WordPress **plugins and themes**, plus CLI tools to bump versions, build ZIPs, generate `release.json`, and publish releases.

## Installation

```bash
composer require urlund/wordpress-updater
```

Requires PHP 7.4+ (`ext-curl`, `ext-zip`, `ext-json`) and WordPress 5.0+ for the runtime updaters.

CLIs install to `vendor/bin/`. Composer does not inherit scripts from dependencies; call the binaries directly, or add optional [Composer scripts](#optional-composer-scripts).

---

## Quick start

### 1. Configure `composer.json`

**Plugin** — details UI uses `banners`, `icons`, and optional `upgrade_notice`:

```json
{
  "extra": {
    "wordpress-updater": {
      "type": "plugin",
      "plugin": "my-plugin.php",
      "slug": "my-plugin",
      "repo": "owner/my-plugin",
      "tested": "6.7",
      "output_dir": "dist",
      "banners": {
        "low": "https://example.com/banner-772x250.jpg",
        "high": "https://example.com/banner-1544x500.jpg"
      },
      "icons": {
        "1x": "https://example.com/icon-128x128.png",
        "2x": "https://example.com/icon-256x256.png"
      },
      "upgrade_notice": "Please update."
    }
  }
}
```

**Theme** — details UI uses a single `screenshot_url` (not plugin banners/icons):

```json
{
  "extra": {
    "wordpress-updater": {
      "type": "theme",
      "stylesheet": "style.css",
      "slug": "my-theme",
      "repo": "owner/my-theme",
      "tested": "6.7",
      "output_dir": "dist",
      "screenshot_url": "https://example.com/screenshot.png"
    }
  }
}
```

Image URLs must be publicly reachable; the CLI does not upload images.

### 2. Release

```bash
./vendor/bin/wp-release patch
./vendor/bin/wp-release minor --commit --tag --publish --no-dev
./vendor/bin/wp-release patch --dry-run
```

`wp-release` runs **bump → zip → release.json**. Pass `--publish` to upload to GitHub. Pass `--no-dev` to install production Composer dependencies before packaging, then restore `require-dev` afterward.

Publishing needs a token: `--token=…` or `GITHUB_TOKEN` (e.g. `export GITHUB_TOKEN=ghp_…`).

Download URLs are built as:

`https://github.com/{repo}/releases/download/v{version}/{slug}-{version}.zip`

---

## Configuration reference

### Shared keys

| Key | Description |
|-----|-------------|
| `type` | `plugin` or `theme` |
| `slug` | Slug / folder name inside the ZIP |
| `repo` | GitHub `owner/repo` |
| `tested` | WordPress “tested up to” |
| `requires_php` | Minimum PHP version |
| `sections_dir` | Directory with section files (default: directory of the main file) |
| `source` | Source directory to package (default: cwd) |
| `output_dir` | Output directory (default: `dist`) |

### Plugin-only keys

| Key | Description |
|-----|-------------|
| `plugin` | Path to main plugin PHP file |
| `banners` | Banner URLs: `low` (772×250), `high` (1544×500) |
| `icons` | Icon URLs: `1x`, `2x`, optional `svg` |
| `upgrade_notice` | Text shown with the plugin update |

### Theme-only keys

| Key | Description |
|-----|-------------|
| `stylesheet` | Path to `style.css` (default: `style.css`) |
| `screenshot_url` | Screenshot URL for the theme details modal |

### Section files

`wp-json` fills `release.json` → `sections` from `sections_dir` (or `--sections-dir`). First matching filename wins; Markdown is lightly converted to HTML.

| Section | Filenames (first match) |
|---------|-------------------------|
| `description` | `description.md`, `description.txt`, `README.md` |
| `installation` | `installation.md`, `installation.txt`, `INSTALL.md` |
| `faq` | `faq.md`, `faq.txt`, `FAQ.md` |
| `changelog` | `changelog.md`, `changelog.txt`, `CHANGELOG.md`, `CHANGES.md` |
| `screenshots` | `screenshots.md`, `screenshots.txt` |
| `other_notes` | `notes.md`, `notes.txt`, `NOTES.md` |

```text
my-plugin/
  my-plugin.php
  sections/
    description.md
    changelog.md
    faq.md
```

Set `"sections_dir": "sections"` in `extra.wordpress-updater`.

---

## Runtime (WordPress)

Register the updater in your main plugin file or theme `functions.php`. It checks GitHub for newer releases and shows them in wp-admin.

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

Each GitHub release should include the versioned ZIP and `release.json` (what `wp-release --publish` uploads). The updater prefers `release.json`, then falls back to parsing the ZIP if `release.json` does not exist (slower).

---

## CLI tools

| Command | Role |
|---------|------|
| `wp-version` | Bump version in plugin PHP or `style.css` |
| `wp-zip` | Package `{slug}-{version}.zip` (`--no-dev` for production vendor) |
| `wp-json` | Generate `release.json` |
| `wp-publish` | Upload ZIP + `release.json` to GitHub |
| `wp-release` | Full pipeline (`--no-dev` before zip, restore after) |

```bash
./vendor/bin/wp-version --plugin=my-plugin.php patch
./vendor/bin/wp-version --type=theme --stylesheet=style.css minor --commit --tag
./vendor/bin/wp-json --type=plugin --plugin=my-plugin.php --output=dist/release.json
./vendor/bin/wp-publish --repo=owner/repo --zip=dist/my-plugin-1.0.1.zip --json=dist/release.json --create
```

Git flags on version bump are opt-in: `--commit`, `--tag` (requires `--commit`), `--push`. Use `--bump-composer` only if you also want to bump Composer’s top-level `version`.

### Optional Composer scripts

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

Then: `composer run wp-release -- patch --publish --no-dev`.

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
      - run: composer install
      - name: Release
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          ./vendor/bin/wp-release ${{ github.event.inputs.bump }} \
            --commit --tag --push --publish --no-dev
```

---

## License

MIT
