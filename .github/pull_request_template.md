## Summary

Describe the change and why it is needed.

## Testing

- [ ] `php -l spry-simple-wp-security.php` passes
- [ ] Tested activation and deactivation
- [ ] Tested enabling and disabling affected settings
- [ ] Verified unrelated file content remains unchanged
- [ ] Documented Apache, Nginx, or HestiaCP considerations when applicable

## Security checklist

- [ ] Output is escaped and input is sanitized
- [ ] Capability checks are present for administrative actions
- [ ] No secrets or production data are included
- [ ] File changes are marker-managed and reversible
