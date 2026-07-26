=== Spry Simple WP Security ===
Contributors: sprywebtech
Tags: security, hardening, xml-rpc, uploads, file editor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Lightweight WordPress hardening for dashboard file editing, XML-RPC, and PHP execution in uploads.

== Description ==

Spry Simple WP Security provides three focused protections:

* Adds a marker-managed DISALLOW_FILE_EDIT definition to wp-config.php.
* Blocks WordPress XML-RPC requests with HTTP 403 and removes the X-Pingback header.
* Adds marker-managed Apache rules to wp-content/uploads/.htaccess to block PHP-like scripts.

Before changing wp-config.php or the uploads .htaccess file, the plugin creates backup copies in:

wp-content/spry-simple-wp-security-backups/

On deactivation, the plugin removes only its own marked rule blocks. It does not overwrite unrelated changes made by WordPress, another plugin, or an administrator.

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings > Spry Simple WP Security.
3. Enable or disable the desired protections.

== HestiaCP / Nginx ==

HestiaCP commonly uses Nginx as a reverse proxy in front of Apache. The uploads .htaccess rules are enforced by Apache after the request is proxied. This plugin intentionally does not edit Hestia Nginx templates because Hestia updates or domain rebuilds may overwrite them.

== Changelog ==

= 1.0.3 =
* Fixed backup downloads for filenames containing multiple extensions, including wp-config.php.bak.php and uploads.htaccess.bak.php.
* Replaced sanitize_file_name validation with exact basename and available-backup validation.

= 1.0.2 =
* Fixed duplicate settings success and error notices.
* Removed the Apache/Nginx note from the settings page.
* Added secure administrator downloads for protected backup files.

= 1.0.1 =
* Fixed temporary file creation under open_basedir-restricted HestiaCP environments.

= 1.0.0 =
* Initial release.
