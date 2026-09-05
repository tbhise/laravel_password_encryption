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
php artisan envcrypt:install
```

Composer can only put files in `vendor/`. `envcrypt:install` is the step that
turns them into a working installation — and if you skip it, the package says
so the moment Composer runs `package:discover`:

```
  EnvCrypt is in vendor/, but this project is not set up yet.

  Run:  php artisan envcrypt:install
```

`envcrypt:install` names this project's secret (`ENVCRYPT_KEY_VAR` in `.env`,
e.g. `TUSHARB_BILLING_BUILD_TAG`), publishes `config/envcrypt.php` and
`storage/tools/envcrypt.php`, verifies the wiring, then prints the steps it
cannot do for you. It encrypts nothing, and re-running it reports `unchanged`
— safe in a deploy script.

Give every project on a shared server its own identifier: two projects sharing
a name share a secret, so an `enc:` value from one would decrypt in the other.

```bash
php artisan envcrypt:install --project=BILLING       # non-interactive
php artisan envcrypt:install --pool="BillingPool"    # secret on an app pool
```

Then the operator steps — an elevated prompt and the database password are
yours, not the package's:

```bash
php artisan db:keygen      # ELEVATED, then iisreset for a machine variable.
                           # Back the secret up: it is the only copy.

# open a NEW terminal - a process cannot see a variable set after it started
php artisan db:password-encrypt-all
php artisan config:clear
php artisan db:secret-check
```

`db:password-encrypt-all` finds the database passwords, shows you the list to
confirm, backs up `.env`, rewrites it, and reads every value back before it
declares success. Variable *names* are all it ever prints.

## Uninstall

```bash
php artisan envcrypt:uninstall      # published files, and the stored secret
composer remove tusharb/laravel-envcrypt
```

It refuses to delete the secret while any managed `.env` value is still an
`enc:` value, naming them, so nothing becomes unrecoverable by accident. Put
the plaintext passwords back first, or pass `--force`.

## Commands

| Command | What it does |
| --- | --- |
| `envcrypt:install` | Name this project's secret, publish the files, print the remaining steps |
| `envcrypt:verify` | Check the installation and wiring — needs no secret and no database |
| `envcrypt:uninstall` | Remove the published files and the stored secret |
| `db:keygen` | Generate the secret and store it (elevated) |
| `db:password-encrypt-all` | Find, confirm and encrypt every database password in `.env` |
| `db:password-encrypt` | Encrypt one password, prompted, for pasting into `.env` |
| `db:password-decrypt` | Print a decrypted password — recovery only |
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

Any `DB_PASSWORD_<SUFFIX>` counts as a database password when a sibling
connection field shares the suffix — `DB_HOST_SECOND` next to
`DB_PASSWORD_SECOND`. That is what tells a second connection apart from
`MAIL_PASSWORD` without hardcoding names. `MAIL_PASSWORD` and friends are
listed but never selected.

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

## Rolling back

Put the plaintext password back in `.env` and run `php artisan config:clear`.
A value without the `enc:` prefix passes through the connector untouched, so
nothing else has to change and the package can stay installed.

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
