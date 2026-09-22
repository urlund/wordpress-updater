# WordPress Updater

GitHub-based updates for WordPress **plugins and themes**, plus CLI tools to bump versions, build ZIPs, generate `release.json`, and publish releases.

## Installation

```bash
composer require urlund/wordpress-updater
```

Requirements: PHP 7.4+, `ext-curl`, `ext-zip`, `ext-json`. WordPress 5.0+ for the runtime updaters.

## Quick start (CLI)

Configure once in your project’s `composer.json`.

**Plugin** — use `banners`, `icons`, and optionally `upgrade_notice` (shown in the plugin details / update UI):

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

**Theme** — use `screenshot_url` (WordPress theme details use a single screenshot, not plugin banners/icons):

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

| Key | Applies to | Description |
|-----|------------|-------------|
| `type` | both | `plugin` or `theme` |
| `plugin` | plugin | Path to main plugin PHP file |
| `stylesheet` | theme | Path to `style.css` (default `style.css`) |
| `slug` | both | Slug / folder name inside the ZIP |
| `repo` | both | GitHub `owner/repo` |
| `tested` | both | WordPress “tested up to” |
| `requires_php` | both | Minimum PHP version |
| `banners` | plugin | Banner image URLs (`low` 772×250, `high` 1544×500) |
| `icons` | plugin | Icon image URLs (`1x`, `2x`, optionally `svg`) |
| `upgrade_notice` | plugin | Text shown with the plugin update |
| `screenshot_url` | theme | Theme screenshot URL for the details modal |
| `sections_dir` | both | Directory with section markdown/text files (default: directory of the plugin/theme file) |
| `source` | both | Source directory to package (default: cwd) |
| `output_dir` | both | Output directory (default: `dist`) |

Image URLs must be publicly reachable; the CLI does not upload those images. `banners` / `icons` / `upgrade_notice` are used by the plugin updater; `screenshot_url` by the theme updater.

Download URLs are built as:

`https://github.com/{repo}/releases/download/v{version}/{slug}-{version}.zip`

### Section files

`wp-json` fills `release.json` → `sections` from files in `sections_dir` (or `--sections-dir`). The first matching filename per section wins; content is lightly converted from Markdown to HTML.

| Section | Filenames (first match) |
|---------|-------------------------|
| `description` | `description.md`, `description.txt`, `README.md` |
| `installation` | `installation.md`, `installation.txt`, `INSTALL.md` |
| `faq` | `faq.md`, `faq.txt`, `FAQ.md` |
| `changelog` | `changelog.md`, `changelog.txt`, `CHANGELOG.md`, `CHANGES.md` |
| `screenshots` | `screenshots.md`, `screenshots.txt` |
| `other_notes` | `notes.md`, `notes.txt`, `NOTES.md` |

Example layout:

```text
my-plugin/
  my-plugin.php
  sections/
    description.md
    changelog.md
    faq.md
```

With `"sections_dir": "sections"` in `extra.wordpress-updater` (or `--sections-dir=sections`).

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
