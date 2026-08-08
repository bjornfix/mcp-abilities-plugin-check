=== MCP Abilities - Check Runner ===
Contributors: basicus
Tags: mcp, abilities, plugin-check
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: plugin-check
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP bridge for the official WordPress.org Plugin Check plugin.

== Description ==

Download the stable plugin ZIP from https://downloads.devenia.com/mcp-abilities-check-runner.zip.

This plugin exposes `plugin-check/run`, which runs the official Plugin Check runner against an installed plugin and returns structured errors and warnings.

The ability always runs the complete check set, including experimental checks. Check and category filters are ignored for release-gate consistency. Warnings are not acceptable: the ability only reports success when there are zero errors and zero warnings.

The official [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin must be installed and active.

== Changelog ==

= 0.2.3 =
* Preserve the server-configured PHP execution budget instead of mutating runtime limits from plugin code.

= 0.2.2 =
* Keep asynchronous checks alive for a bounded five-minute window and persist a terminal failure receipt if the request stops unexpectedly.

= 0.2.1 =

* Uses WordPress's scoped admin-memory filter instead of direct PHP runtime-limit mutations during complete checks.

= 0.2.0 =

* Adds a server-owned asynchronous Plugin Check job and status interface so complete official checks can finish beyond HTTP gateway timeouts without skipping checks.

= 0.1.4 =

* Raises the bounded analysis ceiling for large plugins to 1 GB and five minutes, then restores request limits.

= 0.1.3 =

* Gives the bounded Plugin Check/PHPCS run a 512 MB analysis allowance and restores the request memory limit afterward.

= 0.1.2 =

* Scopes Devenia source-design gate bypass to Plugin Check's own disposable publication fixtures so the official runner can complete without weakening normal publication requests.

= 0.1.1 =
* Always run all Plugin Check checks, including experimental checks.
* Ignore check/category filters so callers cannot accidentally skip checks.
* Treat warnings as failed gate results.

= 0.1.0 =
* Initial MCP bridge for WordPress.org Plugin Check.
