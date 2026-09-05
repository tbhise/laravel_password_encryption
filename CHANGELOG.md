# Changelog

All notable changes to `tusharb/laravel-envcrypt` are documented here.

## 1.0.0 - 2026-09-05

First stable release. Packaged from the single-file `envcrypt-kit.php`
installer, then given the guided installation and rollback flow below.

### The core

- `EnvCryptConnector`, wrapping Laravel's mysql / mariadb / pgsql / sqlsrv
  connectors so passwords are decrypted when PDO is created and no plaintext
  reaches `bootstrap/cache/config.php`.
- AES-256-CBC with a per-value IV, encrypt-then-MAC verified in constant time,
  and a cipher key derived from the stored secret rather than the secret used
  directly.
- The secret lives in the Windows registry or on an IIS application pool, never
  in `.env` on a production server. A `.env` fallback exists for `APP_ENV=local`
  only, gated rather than implicit.

### Installation

- `envcrypt:install` — the whole flow in one command: name the secret from
  `APP_NAME`, publish the config and standalone tool, verify the wiring, store a
  secret if the server has none, detect the database passwords, review them,
  confirm, back up, encrypt, `config:clear`, `db:secret-check`, and hand back
  with the restart and backup-cleanup decisions.
- The package announces itself during `package:discover` — the hook Composer
  runs after `composer require` — and offers to run the installer when a
  terminal is attached. A library cannot register a Composer script; this is the
  closest available.
- Detection now comes from Laravel's resolved database config: each connection's
  password is matched back to the `.env` key supplying it. Custom keys such as
  `DB_PASSWORD_REPORTING` are found; `MAIL_PASSWORD` and `REDIS_PASSWORD` are
  excluded because no connection uses them. `--by-name` keeps the old
  name-based sweep, which the standalone tool still uses.

### Rollback and removal

- `envcrypt:restore` — put a `.env` backup back, `--list` to see them.
- `db:password-decrypt` — write the managed passwords back to `.env` as
  plaintext, after a confirmation and a backup. `--show` keeps the previous
  behaviour of printing one value for recovery.
- `envcrypt:uninstall` — decrypt `.env` first, then remove the published files
  and the stored secret, and only then report that `composer remove` is safe. It
  stops rather than removing a secret that encrypted values still depend on.
- `envcrypt:verify` — installation and wiring only; no secret or database
  needed.

### Safety

- Backups go to `storage/app/envcrypt-backups/`, outside the web root, with a
  `.gitignore` denying the directory, `0600` where honoured, and a unique name
  that never overwrites an existing file.
- `.env` is replaced through a staged write, and restored from memory if any
  value fails to read back.
- Nothing is encrypted without an interactive confirmation, so a Composer hook,
  CI run or deploy script cannot alter credentials.
- An existing secret is never replaced automatically; `db:key-rotate` is the
  only path to a new one, and it re-encrypts as it goes.
- Values already carrying the `enc:` prefix are skipped, never re-encrypted.
- `iisreset` is never run for you.
- Passwords are never printed. Field names only, everywhere except the explicit
  `db:password-decrypt --show`, which asks first.

### Diagnostics

- `db:secret-check` reports one line per managed password, and flags a
  connection holding an `enc:` password on a driver this package does not wrap —
  the failure that otherwise looks like a wrong password.
- Connections whose password is not in `.env`, or which are configured through a
  URL, are reported rather than silently skipped.

### Changed from the installer kit

- The secret's variable name is configuration (`ENVCRYPT_KEY_VAR` in `.env`)
  rather than a constant templated into an installed file.
- The project root is discovered by walking up for `artisan` + `composer.json`,
  because the code now lives at an unknown depth under `vendor/`.
- Classes are namespaced under `Tusharb\EnvCrypt` and autoloaded; nothing is
  copied into `app/`, and no provider line is edited into your project.
- The standalone tool ships at `bin/envcrypt`; `storage/tools/envcrypt.php` is
  published as a launcher for it, so there is still one implementation.
