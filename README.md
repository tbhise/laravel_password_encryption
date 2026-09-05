# Laravel EnvCrypt

Encrypted database passwords in `.env` for Laravel 8 – 13, decrypted at
connection time so no plaintext ever reaches `bootstrap/cache/config.php`.

```dotenv
DB_PASSWORD="enc:qUJ3l0V9…"
```

The secret that unlocks it lives in the Windows registry or on an IIS
application pool — never in `.env`, never in the repository, and never in the
config cache.

## Why a connector, and not `config/database.php`

The obvious approach — decrypting in `config/database.php` — writes the
plaintext password straight into `bootstrap/cache/config.php` the next time
anyone runs `config:cache`. This package instead wraps Laravel's database
*connectors*, which run when PDO is created:

* the config cache only ever holds the `enc:` value;
* `ConnectionFactory::make()` splits read/write configurations **above** the
  connector, so a replica's password is decrypted too — a `DB::extend` hook at
  manager level would miss it;
* a plaintext password still works unchanged, so rollback is a one-line `.env`
  edit.

**Do not edit `config/database.php`.** Leave the password lines exactly as
Laravel ships them; `db:secret-check` fails if it finds decryption in there.

## Requirements

* PHP 7.3+ with `ext-openssl`
* Laravel 8 – 13
* Windows / IIS for the secret *store* commands (`db:keygen`, `db:key-rotate`).
  Encryption, decryption and the connector are platform-independent — on Linux,
  set the variable through systemd, the web server's environment, or your
  secret manager, and skip those two commands.

## Install

```bash
composer require tusharb/laravel-envcrypt
```

Composer can only put files in `vendor/`. A library cannot register a Composer
script — only the root project can — so the package uses the next hook
available: `package:discover`, which Composer runs immediately afterwards. If a
terminal is attached, it offers to set the project up there and then:

```
  EnvCrypt is in vendor/, but this project is not set up yet.

  Set it up now? [Y/n]
```

Answer `n`, or run it later, and it is the same command:

```bash
php artisan envcrypt:install
```

**An unattended run never gets that prompt.** No TTY, a `CI` variable, or
`--no-interaction` anywhere on the command line, and the package prints the
instruction instead — a deploy or CI run must not be able to alter credentials.

### What `envcrypt:install` does

1. **Names this project's secret** — from `APP_NAME`, falling back to the
   directory name, then to a prompt. The name goes in `.env` as
   `ENVCRYPT_KEY_VAR` (e.g. `ACME_BILLING_BUILD_TAG`). Two projects
   sharing a name would share a secret, so each gets its own.
2. **Publishes** `config/envcrypt.php` and `storage/tools/envcrypt.php`.
3. **Verifies the wiring** (`envcrypt:verify`).
4. **Stores a secret** if the server has none, by offering `db:keygen`. An
   existing secret is always kept — replacing it would make every value already
   encrypted under it unreadable.
5. **Finds the database passwords** — from Laravel's resolved database config,
   not from variable names. Every connection's password is matched back to the
   `.env` line that supplies it, so `DB_PASSWORD_REPORTING` is found without any
   naming convention predicting it, and `MAIL_PASSWORD` / `REDIS_PASSWORD` are
   excluded because they are not any connection's password.
6. **Shows the field names and waits.** Only names are ever printed. You can
   remove entries (`r`), add one (`a`), cancel (`c`), or confirm. Nothing is
   encrypted until you answer.
7. **Backs `.env` up**, encrypts, and reads every value back before reporting
   success. If anything fails to verify, `.env` is restored.
8. **Runs `config:clear` and `db:secret-check`.**
9. **Tells you to restart IIS yourself**, then asks whether the application
   works — and offers to delete, keep or move the plaintext backup based on
   your answer.

Options: `--project=NAME`, `--pool="AppPool"`, `--no-keygen`, `--no-encrypt`,
`--no-tool`, `--force`.

### What it will not do

- Encrypt without an interactive confirmation.
- Replace a secret that already exists (that is `db:key-rotate`, which
  re-encrypts as it goes).
- Encrypt an `enc:` value a second time.
- Run `iisreset` — that restarts every site on the server, so it is yours to
  time.
- Print a password. Field names only, everywhere except the explicit
  `db:password-decrypt --show`.

## Removing the package

**Run this before `composer remove`.** Deleting the code that decrypts while
`.env` still says `DB_PASSWORD="enc:..."` leaves nothing able to read it:

```bash
php artisan envcrypt:uninstall
composer remove tusharb/laravel-envcrypt
```

`envcrypt:uninstall` puts the passwords back in plaintext first (with a backup
and a confirmation), then removes the published files and the stored secret,
clears the config cache, and only then says removal is safe. If the values
cannot be decrypted it stops, keeps the secret, and says so — the package stays
installed and working.

## Rolling back

| Situation | Command |
| --- | --- |
| Application broken after encrypting | `php artisan envcrypt:restore` |
| Want plaintext back, secret still works | `php artisan db:password-decrypt` |
| Need one password for a database client | `php artisan db:password-decrypt --show --key=DB_PASSWORD` |
| List the backups | `php artisan envcrypt:restore --list` |

Backups live in `storage/app/envcrypt-backups/` — outside the web root, with a
`.gitignore` that denies the whole directory, never overwritten, `0600` where
the platform honours it. They hold **plaintext passwords**: delete them once
the application is verified.

Your database passwords are never changed on the database side, so a rollback
is always just a `.env` edit.

## Commands

| Command | What it does |
| --- | --- |
| `envcrypt:install` | Name this project's secret, publish the files, print the remaining steps |
| `envcrypt:verify` | Check the installation and wiring — needs no secret and no database |
| `envcrypt:uninstall` | Decrypt `.env`, then remove the files and the secret — **run before `composer remove`** |
| `envcrypt:restore` | Restore a `.env` backup (`--list` to see them) |
| `db:keygen` | Generate the secret and store it (elevated) |
| `db:password-encrypt-all` | Find, confirm and encrypt every database password in `.env` |
| `db:password-encrypt` | Encrypt one password, prompted, for pasting into `.env` |
| `db:password-decrypt` | Write the passwords back to `.env` as plaintext; `--show` prints one instead |
| `db:key-rotate` | Replace the secret and re-encrypt every managed password |
| `db:secret-check` | Verify the whole setup end to end; exit 1 on any failure |

Every command takes `--pool=NAME` when the secret lives on an application pool
rather than machine-wide, or set `ENVCRYPT_POOL` once in `.env`.

## When the application will not boot

A wrong `DB_PASSWORD` is exactly the situation where `php artisan` is no help.
The same migration runs with neither Laravel nor Composer bootstrapped:

```bash
php vendor/tusharb/laravel-envcrypt/bin/envcrypt encrypt-all
php vendor/tusharb/laravel-envcrypt/bin/envcrypt decrypt --key=DB_PASSWORD
```

It requires only this package's framework-free classes, and refuses to run
outside the CLI.

## Several databases

The artisan commands ask Laravel. Every entry in
`config('database.connections')` has its password matched back to the `.env`
line that supplies it, so custom keys — `DB_PASSWORD_SECOND`,
`DB_PASSWORD_REPORTING`, anything — are found without a naming convention
having to predict them, and `MAIL_PASSWORD` / `REDIS_PASSWORD` are excluded
because no connection uses them. They are still *listed* during review, so you
can see they were considered and left alone.

Two cases are reported rather than silently skipped: a connection whose
password is not in `.env` at all (hardcoded in config), and a connection
configured through a single `url`, whose password lives inside that URL.

The standalone tool has no framework to ask, so it falls back to names: a
`DB_PASSWORD_<SUFFIX>` counts when a sibling connection field shares the suffix
— `DB_HOST_SECOND` next to `DB_PASSWORD_SECOND`. `php artisan
db:password-encrypt-all --by-name` uses the same fallback.

The confirmed list is recorded in `.env` as `ENVCRYPT_TARGET_KEYS`. Re-running
`db:password-encrypt-all` only asks again when a new database password turns
up, or you pass `--reconfigure`.

## Rotating the secret

```bash
php artisan db:key-rotate --dry-run    # proves it would succeed, changes nothing
php artisan db:key-rotate
```

All-or-nothing across every managed key: one value that cannot be decrypted
under the current secret stops the rotation before anything is touched. The
plaintexts exist only in memory, `.env` is backed up first, and the installed
secret is overwritten **last** — so any failure leaves the old secret beside
the old ciphertext, which is a working system.

Old `.env` backups need the outgoing secret; `--reveal-previous` prints it once,
on confirmation, so you can store it with them.

## Configuration

`config/envcrypt.php`:

| Key | `.env` | Purpose |
| --- | --- | --- |
| `key_var` | `ENVCRYPT_KEY_VAR` | Name of the variable holding the secret |
| `context` | `ENVCRYPT_CONTEXT` | Key-derivation label — **changing it makes every existing value unreadable** |
| `pool` | `ENVCRYPT_POOL` | IIS application pool, when not machine-wide |
| `target_keys` | — | Fallback managed keys before anything is declared |
| `connectors` | — | Driver ⇒ connector class to wrap |

Only the *name* of the secret is configuration. The secret itself is never read
through `config()`, because `config:cache` would then write it beside the
ciphertext it unlocks.

## How the crypto works

* AES-256-CBC with a fresh random IV per value, so the same password never
  encrypts alike.
* Encrypt-then-MAC (HMAC-SHA256 over IV ‖ ciphertext), checked in constant
  time, so a tampered value is rejected before decryption is attempted.
* The stored secret is never used as a cipher key directly — the key is
  `HMAC-SHA256(context, secret)`.
* Deliberately **not** `APP_KEY`: rotating the application key is an
  application-level operation that must stay independent of database
  credentials.

In `local` / `development` only, the secret may also come from `.env` itself.
That fallback is gated on `APP_ENV` rather than taken whenever the variable is
missing — a silent fallback would make "secret in `.env`" a supported
production configuration, which is the arrangement this package exists to
prevent. A missing or unrecognised `APP_ENV` counts as production.

### What this does and does not protect

It protects against a leaked `.env`, a stray backup, or a repository commit:
the ciphertext alone is useless. It does **not** protect against an attacker
who can already run code as the web user — that process must be able to read
the secret in order to connect to the database at all.

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md).
