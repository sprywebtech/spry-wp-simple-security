# Contributing

Contributions are welcome through GitHub issues and pull requests.

## Development guidelines

1. Create a focused branch from `main`.
2. Keep the plugin compatible with PHP 7.4 and WordPress 6.0 or newer.
3. Follow WordPress PHP coding standards where practical.
4. Escape output, sanitize settings, verify capabilities, and avoid direct access to plugin files.
5. Do not replace complete configuration files when a marker-managed change is sufficient.
6. Run `php -l spry-simple-wp-security.php` before opening a pull request.
7. Describe how the change was tested, including the web-server configuration when relevant.

Security vulnerabilities should be reported privately according to [SECURITY.md](SECURITY.md), not opened as public issues.
