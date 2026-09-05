# Changelog

All notable changes to `npav/laravel-envcrypt` are documented here.

## 1.0.0 - unreleased

First release, packaged from the single-file `envcrypt-kit.php` installer.

### Added

- `EnvCryptConnector`, wrapping Laravel's mysql / mariadb / pgsql / sqlsrv
  connectors so passwords are decrypted at connection time and no plaintext
  reaches `bootstrap/cache/config.php`.
- `envcrypt:install` — names this project's secret, publishes
  `config/envcrypt.php` and `storage/tools/envcrypt.php`, verifies the wiring,
  and prints the operator steps that need an elevated prompt and the database
  password. Idempotent, so it is safe in a deploy script.
- A notice printed during `package:discover` when the package is installed but
  the project has not been set up, so `composer require` no longer ends in
  silence with the files sitting unused in `vendor/`.
- `envcrypt:verify` — installation and wiring only; needs no secret, no
  database and no warm environment, so it answers usefully straight after
  installing.
- `envcrypt:uninstall` — removes the published files and the stored secret,
  refusing while any managed `.env` value still depends on it.
- Commands: `db:keygen`, `db:password-encrypt-all`, `db:password-encrypt`,
  `db:password-decrypt`, `db:key-rotate`, `db:secret-check`.
- `bin/envcrypt`, the standalone migration tool, which boots neither Laravel
  nor Composer and so runs when the application itself will not start.
  `storage/tools/envcrypt.php` is published as a launcher for it, so the path
  from the operational guide keeps working and there is still one
  implementation.
- Multi-database support: `DB_PASSWORD_<SUFFIX>` is recognised by its sibling
  connection fields, and the confirmed list is recorded in
  `ENVCRYPT_TARGET_KEYS`.

### Changed from the installer kit

- The secret's variable name is configuration (`ENVCRYPT_KEY_VAR` in `.env`)
  rather than a constant templated into an installed file. `envcrypt:install`
  writes it; the name, never the secret, lives in the project.
- The project root is discovered by walking up for `artisan` + `composer.json`,
  because the code now lives at an unknown depth under `vendor/`.
- Classes are namespaced under `Npav\EnvCrypt` and autoloaded. Nothing is
  copied into `app/`, and no provider line is edited into your project —
  Laravel's package discovery registers the provider.
- `db:secret-check` and `envcrypt:verify` skip the connector assertion for
  drivers that carry no password, so a SQLite project verifies cleanly.
