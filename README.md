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

Composer's `package:discover` hook runs the setup automatically: the project is
named from `APP_NAME`, the config and standalone tool are published, the wiring
is verified, and the secret is created if the server has none — asking Windows
for elevation only for that one step.

Encryption is the only thing it will not do on its own. It stops at:

```
Detected database password fields:

  1. DB_PASSWORD
  2. DB_PASSWORD_SECOND
  3. DB_PASSWORD_REPORTING

Continue with password encryption? [y/N]
```

Answer `y` and it backs `.env` up, encrypts, runs `config:clear` and
`db:secret-check`, then asks you to restart IIS yourself and confirm the
application works before offering to delete the backup.

Answer `n` and nothing is touched — the project stays set up, and
`php artisan db:password-encrypt-all` picks up where you left off.

**On Windows that question is asked by `db:password-encrypt-all`, not by
Composer** — see below for exactly why.

### How far the automation goes

On **Linux and macOS** the setup runs all the way to the encryption
confirmation: Composer redirects the child's STDIN, but /dev/tty still
reaches the terminal, so the question is asked and waits.

On **Windows** it stops one step earlier. Composer runs artisan with STDIN
redirected, and in that state PHP cannot read the console input buffer -
`fopen('CONIN$', 'r')` fails outright, and the one mode that does open
returns end-of-input immediately instead of blocking. The same probe without
a redirected STDIN blocks correctly, so the limitation is the redirection,
not the console.

So on Windows `composer require` gives you the whole setup - naming,
publishing, verification, secret creation, field detection - and then stops,
leaving one command for the encryption itself:

```bash
php artisan db:password-encrypt-all
```

which shows the detected fields and asks `Continue with password encryption?`
in your own terminal, where prompting works.

Where no terminal can be reached at all - CI, a scripted deploy,
`COMPOSER_NO_INTERACTION` - the setup still runs and **stops before
encryption**. An unattended process never rewrites credentials.

### Where administrator rights are needed

Only one operation needs them: writing the secret to
`HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment`.

The installer tries in the current process first, so an already-elevated
terminal never sees a dialog. Otherwise it asks Windows to run that single
command elevated, which raises the standard UAC consent prompt — the secret is
generated *inside* that elevated process, so it never appears on a command
line. Everything else — naming, publishing, detection, encryption, checks —
runs unprivileged.

Decline the dialog and setup continues; only the encryption step is skipped,
because there is nothing to encrypt with.

### Running it by hand

```bash
php artisan envcrypt:install                      # same flow, any time
php artisan envcrypt:install --project=BILLING    # name it explicitly
php artisan envcrypt:install --pool="AppPool"     # secret on an IIS pool
php artisan envcrypt:install --no-encrypt         # set up only
```

It is idempotent — a second run reports `unchanged` and finds nothing left to
encrypt — so it is safe in a deploy script. Once the project has named its
secret, `composer update` never re-enters the setup.

### What it will not do

- Encrypt without your explicit `y`.
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
