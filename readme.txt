=== AgentPorter ===
Contributors: maulikpaddhariya
Tags: ai, agents, mcp, woocommerce, security
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See and control how AI agents use your WordPress and WooCommerce site, safely.

== Description ==

AgentPorter helps site owners see which AI agents visit their site, decide what they may do, and give trusted agents safe, read-only access to public content.

This early version ships the secure foundation: a single access guard for every agent call, a kill switch, rate limits, an audit log with redacted inputs, and encrypted storage for the plugin's own secrets. No agent features are enabled yet.

**Security by default**

* Administrators are not agents. Agent access must be granted explicitly.
* One switch (or the `AGENTPORTER_DISABLE_AGENTS` constant) turns all agent access off.
* Audit entries never store passwords, tokens, emails or full IP addresses.

== Installation ==

1. Upload the plugin through Plugins → Add New, or copy the `agentporter` folder to `wp-content/plugins/`.
2. Activate AgentPorter. It requires WordPress 6.9+ and PHP 8.1+; on older versions it shows a notice and loads nothing.

== Frequently Asked Questions ==

= Does AgentPorter send data to external services? =

No. This version makes no outbound requests. Any future connection to an external service will be off by default, documented here, and only enabled by the site owner.

= Will it slow down my site? =

No. AgentPorter does no database writes on normal page views, and its code is checked against performance budgets on every change.

= How do I remove all data? =

Enable "Delete data on uninstall" (option `agentporter_delete_data_on_uninstall`) before deleting the plugin. Otherwise your data is kept.

== Privacy ==

AgentPorter stores agent audit entries with redacted inputs and truncated IP addresses (/24 for IPv4, /48 for IPv6), kept for 30 days by default. It sets no cookies for visitors.

== Changelog ==

= 0.1.0 =
* Foundation: requirements check, failure boundaries, database schema and migrations, security core (access guard, kill switch, rate limiter, audit log, redactor, secret store).

== Upgrade Notice ==

= 0.1.0 =
First release.
