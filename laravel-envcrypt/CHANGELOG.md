# Changelog

All notable changes to `npav/laravel-envcrypt` are documented here.

## 1.0.0 - unreleased

First release, packaged from the single-file `envcrypt-kit.php` installer.

### Added

- `EnvCryptConnector`, wrapping Laravel's mysql / mariadb / pgsql / sqlsrv
  connectors so passwords are decrypted at connection time and no plaintext
  reaches `bootstrap/cache/config.php`.
- Commands: `envcrypt:install`, `db:keygen`, `db:password-encrypt-all`,
  `db:password-encrypt`, `db:password-decrypt`, `db:key-rotate`,
  `db:secret-check`.
- `bin/envcrypt`, the standalone migration tool, which boots neither Laravel
  nor Composer and so runs when the application itself will not start.
- Multi-database support: `DB_PASSWORD_<SUFFIX>` is recognised by its sibling
  connection fields, and the confirmed list is recorded in
  `ENVCRYPT_TARGET_KEYS`.

### Changed from the installer kit

- The secret's variable name is configuration (`ENVCRYPT_KEY_VAR` /
  `config/envcrypt.php`) rather than a constant templated into an installed
  file. `envcrypt:install` writes it for you.
- The project root is discovered by walking up for `artisan` + `composer.json`,
  because the code now lives at an unknown depth under `vendor/`.
- Classes are namespaced under `Npav\EnvCrypt` and autoloaded; nothing is
  copied into `app/`, and no provider line is edited into your project.
- The standalone tool is no longer written to `storage/tools/` — it ships in
  the package at `bin/envcrypt`.
