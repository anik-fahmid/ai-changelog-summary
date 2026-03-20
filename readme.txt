=== Changelog Tracker ===
Contributors: anikfahmid
Tags: changelog, ai, email notifications, release notes, automation
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered changelog tracking that monitors plugin/service release pages and emails you summaries of what changed.

== Description ==

**Changelog Tracker** monitors changelog URLs (plugin pages, SaaS apps, documentation sites) and uses AI to generate plain-English summaries of what changed. Summaries are delivered to your inbox on your chosen schedule or on demand.

= Key Features =

* **Multi-URL tracking** — monitor multiple changelog pages simultaneously
* **AI summaries** — supports Google Gemini, OpenAI (GPT-4), and Anthropic Claude
* **Scheduled emails** — daily, weekly, or bi-weekly delivery
* **Force send** — fetch and email latest changelogs on demand (independent from scheduled emails)
* **Change detection** — only emails when content actually changes (MD5 hash comparison)
* **Custom SMTP** — configure Gmail or any SMTP server for reliable delivery
* **Auto-detect** — automatically find changelog URLs from any domain
* **Dashboard widget** — quick-glance status from the WordPress admin dashboard

= External Services =

This plugin connects to the following external services. By using this plugin, you agree to their respective terms and privacy policies.

**Jina Reader (r.jina.ai)**
Used to convert changelog web pages into clean, readable text for AI processing. The URL of each tracked changelog page is sent to Jina Reader on each fetch.
* Service: https://jina.ai
* Privacy Policy: https://jina.ai/privacy-policy/

**Google Gemini API**
Used for AI summarization when Gemini is selected as the provider. Changelog page content is sent to the API.
* Service: https://ai.google.dev
* Terms: https://ai.google.dev/terms

**OpenAI API**
Used for AI summarization when OpenAI is selected as the provider. Changelog page content is sent to the API.
* Service: https://openai.com
* Privacy Policy: https://openai.com/policies/privacy-policy/

**Anthropic Claude API**
Used for AI summarization when Claude is selected as the provider. Changelog page content is sent to the API.
* Service: https://anthropic.com
* Privacy Policy: https://www.anthropic.com/privacy

No data is collected or stored by the plugin author. All external API calls are made directly from your WordPress server to the respective service.

== Installation ==

1. Upload the `changelog-tracker` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Settings > Changelog Tracker**
4. Enter your AI provider API key (Gemini, OpenAI, or Claude)
5. Add your changelog URLs to track
6. Configure your notification email and schedule

== Frequently Asked Questions ==

= Which AI providers are supported? =

Google Gemini (free tier available), OpenAI GPT-4, and Anthropic Claude. You need to provide your own API key for the chosen provider.

= How does change detection work? =

The plugin stores an MD5 hash of each changelog page's content. On each fetch, it compares the new hash with the stored one. If they differ, the page is summarised and included in the next email.

= Does the force-send button affect scheduled emails? =

No. Force-send is completely independent from scheduled emails. It bypasses the cache and sends immediately without updating the change-detection hash, so scheduled emails still fire normally.

= What is Jina Reader used for? =

Jina Reader (r.jina.ai) converts changelog web pages into clean markdown before they are sent to the AI provider. This improves summary quality and reduces token usage. The plugin sends only the URL to Jina Reader; Jina fetches the page and returns its text content.

= Can I use this without an AI provider API key? =

No. An API key for at least one supported provider is required to generate summaries.

= How do I set up Gmail SMTP? =

Enable 2-Factor Authentication on your Google account, then generate an App Password at myaccount.google.com/apppasswords. Use `smtp.gmail.com`, port `587`, encryption `TLS`, and enter your Gmail address and the App Password.

== Screenshots ==

1. General settings — configure AI provider and changelog URLs
2. Notifications settings — email schedule, SMTP configuration
3. Preview panel — fetch and preview AI summaries on demand
4. Email notification — sample changelog summary email

== Changelog ==

= 2.0 =
* Renamed plugin to Changelog Tracker
* Added auto-detect changelog URL feature
* Added SMTP configuration with test button
* Added eye toggle for API key and password fields
* Fixed force-fetch isolation from scheduled emails
* Improved content extraction via Jina Reader
* Fixed PHP execution timeout on multi-URL fetch

= 1.0 =
* Initial release

== Upgrade Notice ==

= 2.0 =
Major update with SMTP support, auto-detect, and improved email reliability. Re-enter your API key after upgrading.
