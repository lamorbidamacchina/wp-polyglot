# Polyglot for Polylang

Automatically translate Polylang content using Google Cloud Translation API (Basic v2).

## Plugin Overview

Polyglot for Polylang helps administrators run controlled translation jobs from `Tools > Polyglot for Polylang` for:

- **Polylang String Translations**
- **Pages, Posts, and public Custom Post Types (CPTs)**

The plugin is designed for safe, incremental translation workflows and does not create missing translated posts.

Polyglot is built for projects that use WordPress as a CMS with a clear separation between content and presentation (for example, headless WordPress setups). It is not recommended for page-builder-heavy workflows (such as Elementor) where content and presentation are tightly coupled.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Polylang (active)
- Google Cloud Translation API (Basic v2) with API key

## License

This plugin is licensed under **GPL-2.0-or-later**.

## Features

### Configuration

- Save Google API key in WordPress admin
- API key is stored encrypted in WordPress options
- Tabs are locked until an API key is configured

### Translation Strings Tab

- Select Polylang string group
- Select source and target languages
- Translate only missing target-language string values
- Background queue processing with live progress

### Pages, Posts and CPT Tab

- Select one content type (`page`, `post`, or one public CPT)
- Select source and target languages
- Choose translation scope:
  - default content only (`title`, `content`, `excerpt`)
  - default content + custom fields
- Custom field mode includes:
  - inline preview of custom field names that Polyglot will attempt to translate
  - explicit risk acknowledgment checkbox before job start

## Translation Rules

For each eligible source/target pair, a field is translated only when:

- target value equals source value, or
- source value is non-empty and target value is empty

If target has different non-empty content, it is treated as edited and skipped.

## Custom Field Safety

When custom-field translation is enabled, Polyglot auto-detects non-protected meta keys and applies safeguards:

- skips protected/internal keys
- skips technical key patterns (for example id, slug, token, url, hash)
- skips values that look serialized/JSON/URL/numeric/token-like
- supports exclusion hook:
  - `polyglot_excluded_meta_keys`

Default excluded keys currently include:

- `item_author`

## Job Processing and Progress

- Jobs run in background batches using WP-Cron
- Progress view includes status, remaining, scanned, translatable, translated, skipped, failed, and last error
- If no eligible fields are found, Polyglot shows a success notice and does not start a queue

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Install and activate Polylang first
3. Activate the plugin in WordPress admin
4. Open `Tools > Polyglot`
5. Save Google API key in `Configuration`

## Billing Notice

Google Cloud Translation API is a paid service with usage-based pricing.

- Large translation jobs can generate significant costs
- Review quotas, billing settings, and pricing before running bulk jobs

## Disclaimer

Polyglot is provided **as is**, without any warranty of any kind.

- Use this plugin at your own risk.
- Always create a full database and files backup before running translation jobs.
- Translation jobs can modify content and custom fields; review your settings and previews carefully before starting.
- The plugin author is not liable for data loss, site issues, translation quality issues, or other damages resulting from use.

## FAQ

### Does Polyglot overwrite existing translated content?

No. It only translates fields that appear untranslated by its detection rules.

### Does Polyglot create missing translated posts?

No. It only works on already existing Polylang-linked target posts.

### Who can run translation jobs?

By default, only Administrators can access and run Polyglot jobs.

If your site customizes roles/capabilities and grants `manage_options` to Editors (or another role), those users can run jobs too.

### Which API is used?

Google Cloud Translation API Basic (v2), authenticated via API key.

### How do I get a Google API key for Translation?

1. Go to Google Cloud Console and create (or select) a project.
2. Enable billing for that project.
3. Enable the Cloud Translation API.
4. Open **APIs & Services > Credentials** and create an API key.
5. Copy the key into `Tools > Polyglot > Configuration`.

For security, restrict the API key in Google Cloud:

- **API restrictions**: allow only Cloud Translation API.
- **Application restrictions**: limit usage to your required domains/referrers (or server IPs, based on your setup).

## Changelog

### 1.1.0

- Compliance hardening and plugin-check fixes for nonce/input handling and translation-service SQL safety
- Improved readme metadata for WordPress.org checks

### 1.0.0

- Initial release
- Added string translation workflow
- Added Pages/Posts/CPT workflow with optional custom field translation and safety checks
