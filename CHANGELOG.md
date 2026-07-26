# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.3] - 2026-07-25

### Fixed

- Fixed administrator backup downloads for filenames containing multiple extensions, such as `wp-config.php.bak.php` and `uploads.htaccess.bak.php`.
- Replaced `sanitize_file_name()` validation with exact basename, extension, and available-backup checks so valid protected backups are not renamed before validation.

## [1.0.2] - 2026-07-25

### Added

- Added a **Backup Files** section to the plugin settings page.
- Added secure, nonce-protected backup downloads for administrators.
- Backup downloads decode the protected stored payload and return the original file contents.
- Added path validation so requested backup files must remain inside the plugin backup directory.

### Fixed

- Prevented settings success notices from displaying twice.
- Prevented settings error notices from displaying twice.

### Changed

- Removed the Apache/Nginx informational message from the settings page.

## [1.0.1] - 2026-07-25

### Fixed

- Corrected temporary file path creation for HestiaCP and other `open_basedir`-restricted environments.
- Ensured temporary files are created inside the target file's directory by passing a trailing slash to `wp_tempnam()`.

## [1.0.0] - 2026-07-25

### Added

- Toggle for disabling the WordPress dashboard theme and plugin file editors.
- Toggle for blocking WordPress XML-RPC requests with HTTP 403.
- Removal of the `X-Pingback` response header while XML-RPC protection is enabled.
- Toggle for blocking PHP-like files in the uploads directory through Apache `.htaccess` rules.
- Marker-based, reversible file changes.
- Protected pre-change backups stored outside the uploads directory.
- Safe deactivation cleanup that removes only plugin-owned rule blocks.
- HestiaCP and Nginx reverse-proxy guidance in the project documentation.
- GitHub Actions PHP syntax checks and standard repository documentation.
