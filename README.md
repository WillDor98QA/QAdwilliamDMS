# Data Management System (WordPress plugin)

Registration data collection, approval workflow, electoral master data and audit for a single organization.

The specification, implementation brief, decisions and phase notes are kept privately and are not part of this repository.

## Layout

```text
plugin/data-management-system/   the WordPress plugin (symlink or copy into wp-content/plugins/)
tests/Unit                       pure PHP tests (no WordPress, no DB)
tests/Integration                WordPress + MySQL tests (wp-phpunit)
```

## Commands

```bash
composer install
composer test:unit
composer lint
bin/test-db.sh start               # private, disposable MySQL (port 3307, .dev/mysql)
composer test:integration
```

Secrets (SMS, Turnstile, SMTP) are read from `wp-config.php` constants or the environment and must never be committed.

## Local site for manual testing

```bash
bin/dev-site.sh setup    # WordPress + plugin + demo data in .dev/site
bin/dev-site.sh start    # http://127.0.0.1:8088 — logins in .dev/dev-site-credentials.txt
bin/dev-site.sh stop
```

Outgoing email (including development-gateway OTP codes) is written to `.dev/mail.log`.

