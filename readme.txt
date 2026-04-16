=== Polyglot ===
Contributors: Simone Ricci
Tags: polylang, translation, localization, google translate, multilingual
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Automatically translate Polylang strings and content using Google Cloud Translation API (Basic v2).

== Description ==

Polyglot helps administrators run controlled translation jobs from Tools > Polyglot for:

- Polylang String Translations
- Pages, Posts, and public Custom Post Types (CPTs)

The plugin is designed for safe, incremental translation workflows and does not create missing translated posts.

= Configuration =

- Save Google API key in WordPress admin
- API key is stored encrypted in WordPress options
- Tabs are locked until an API key is configured

= Translation Strings tab =

- Select Polylang string group
- Select source and target languages
- Translate only missing target-language string values
- Background queue processing with live progress

= Pages, Posts and CPT tab =

- Select one content type (page, post, or one public CPT)
- Select source and target languages
- Choose translation scope:
  - default content only (title, content, excerpt)
  - default content + custom fields
- Custom field mode includes:
  - inline preview of custom field names that Polyglot will attempt to translate
  - explicit risk acknowledgment checkbox before job start

= Translation rules =

For each eligible source/target pair, a field is translated only when:

- target value equals source value, or
- source value is non-empty and target value is empty.

If target has different non-empty content, it is treated as edited and skipped.

= Custom field safety =

When custom-field translation is enabled, Polyglot auto-detects non-protected meta keys and applies safeguards:

- skips protected/internal keys
- skips technical key patterns (for example id, slug, token, url, hash)
- skips values that look serialized/JSON/URL/numeric/token-like
- supports exclusion hook: `polyglot_excluded_meta_keys`

Default excluded keys currently include:

- item_author

= Job processing and progress =

- Jobs run in background batches using WP-Cron
- Progress view includes status, remaining, scanned, translatable, translated, skipped, failed, and last error
- If no eligible fields are found, Polyglot shows a success notice and does not start a queue

= Important billing notice =

Google Cloud Translation API is a paid service with usage-based pricing.

- Large translation jobs can generate significant costs
- Review quotas, billing settings, and pricing before running bulk jobs

= Disclaimer =

Polyglot is provided as is, without any warranty of any kind.

- Use this plugin at your own risk.
- Always create a full database and files backup before running translation jobs.
- Translation jobs can modify content and custom fields; review your settings and previews carefully before starting.
- The plugin author is not liable for data loss, site issues, translation quality issues, or other damages resulting from use.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Ensure Polylang is active
4. Open Tools > Polyglot
5. Save Google API key in Configuration

== Frequently Asked Questions ==

= Does Polyglot overwrite existing translated content? =

No. It only translates fields that appear untranslated by its detection rules.

= Does Polyglot create missing translated posts? =

No. It only works on already existing Polylang-linked target posts.

= Which API is used? =

Google Cloud Translation API Basic (v2), authenticated via API key.

== Changelog ==

= 1.1.0 =

- Compliance hardening and plugin-check fixes for nonce/input handling and translation-service SQL safety
- Improved readme metadata for WordPress.org checks

= 1.0.0 =

- Initial release
- Added string translation workflow
- Added Pages/Posts/CPT workflow with optional custom field translation and safety checks
