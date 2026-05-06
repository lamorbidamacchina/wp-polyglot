=== Polyglot for Polylang ===
Contributors: simogol
Tags: polylang, translation, localization, google translate, multilingual
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Automatically translate Polylang strings and content using Google Cloud Translation API (Basic v2).

== Description ==

Polyglot helps administrators run controlled translation jobs from Tools > Polyglot for:

- Polylang String Translations
- Pages, Posts, and public Custom Post Types (CPTs)

The plugin is designed for safe, incremental translation workflows and does not create missing translated posts.

Polyglot is built for projects that use WordPress as a CMS with a clear separation between content and presentation (for example, headless WordPress setups). It is not recommended for page-builder-heavy workflows (such as Elementor) where content and presentation are tightly coupled.

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

= Troubleshooting failed jobs =

If a job ends with done_with_errors, open the Job Progress panel and review the Failure details list.
Each row includes:

- Error category ([API], [SAVE], [OTHER])
- Error message
- Related identifiers (field key, language, post IDs, or string name)

To inspect raw job data directly:

- wp option get polyglot_translation_job --format=json

Key fields:

- status
- last_error
- totals.failed
- errors[]

If errors are API-related, verify:

- Cloud Translation API is enabled in the same Google Cloud project used by the key
- Billing is enabled for that project
- API key restrictions allow this WordPress instance (referrer/IP restrictions and API restriction to Cloud Translation API)

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

== External services ==

This plugin relies on Google Cloud Translation API (Basic v2) to perform automated translations. The service is the only third-party endpoint contacted by the plugin.

What it is used for:

- Translating Polylang String Translation entries on demand.
- Translating post/page/CPT title, content, excerpt, and (when explicitly enabled) selected custom field values on demand.
- Validating the saved API key by calling the languages list endpoint when the administrator clicks "Test Saved API Key".

What data is sent and when:

- Each translation request sends the source text, the source language code, and the target language code to https://translation.googleapis.com/language/translate/v2 along with the configured API key in the request URL. Requests are made only while a translation job is processing or when the administrator clicks "Test Saved API Key".
- The validation request sends only the API key to https://translation.googleapis.com/language/translate/v2/languages.
- No personal data, user identifiers, IP addresses, or site visitor data are sent by the plugin. Standard HTTP request metadata (such as referrer set to the site URL) is included so the API key can be restricted by HTTP referrer in Google Cloud.

Service provider: Google LLC. By using this plugin you agree to Google Cloud's terms.

- Google Cloud Terms of Service: https://cloud.google.com/terms
- Google Privacy Policy: https://policies.google.com/privacy
- Google Cloud Translation documentation: https://cloud.google.com/translate/docs

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Install and activate Polylang first
3. Activate Polyglot in WordPress admin
4. Open Tools > Polyglot
5. Save Google API key in Configuration

== Frequently Asked Questions ==

= Does Polyglot overwrite existing translated content? =

No. It only translates fields that appear untranslated by its detection rules.

= Does Polyglot create missing translated posts? =

No. It only works on already existing Polylang-linked target posts.

= Who can run translation jobs? =

By default, only Administrators can access and run Polyglot jobs.

If your site customizes roles/capabilities and grants `manage_options` to Editors (or another role), those users can run jobs too.

= Which API is used? =

Google Cloud Translation API Basic (v2), authenticated via API key.

= How do I get a Google API key for Translation? =

1. Go to Google Cloud Console and create (or select) a project.
2. Enable billing for that project.
3. Enable the Cloud Translation API.
4. Open APIs & Services > Credentials and create an API key.
5. Copy the key into Tools > Polyglot > Configuration.

For security, restrict the API key in Google Cloud:

- API restrictions: allow only Cloud Translation API.
- Application restrictions: limit usage to your required domains/referrers (or server IPs, based on your setup).

== Changelog ==

= 1.3.1 =

- Renamed text domain from `polyglot` to `polyglot-for-polylang` to match the plugin slug
- Moved inline admin scripts to dedicated JS files enqueued via wp_enqueue_script()
- Added External services section documenting Google Cloud Translation API usage, terms of service, and privacy policy links
- Updated Contributors metadata to use the WordPress.org username

= 1.2.0 =

- Added uninstall cleanup to remove plugin options, including stored encrypted Google API key, job state, and admin notice data

= 1.1.0 =

- Compliance hardening and plugin-check fixes for nonce/input handling and translation-service SQL safety
- Improved readme metadata for WordPress.org checks

= 1.0.0 =

- Initial release
- Added string translation workflow
- Added Pages/Posts/CPT workflow with optional custom field translation and safety checks
