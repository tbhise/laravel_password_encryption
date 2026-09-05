# Publishing this package

This package is now `tusharb/laravel-envcrypt`, from
<https://github.com/tbhise/laravel_password_encryption>.

**It was published under the old name first.** `npav/laravel-envcrypt` is still
on Packagist, at `dev-main`, and a renamed package is a *new* Packagist entry —
the old one does not follow the rename. Two things follow: submit the new name,
and retire the old one.

There is also still no **tag**. Packagist has only `dev-main`, so any project
with the default `minimum-stability: stable` refuses to install it:

```
Could not find a version of package tusharb/laravel-envcrypt matching your
minimum-stability (stable). Require it with an explicit version constraint
allowing its desired stability.
```

## Release the current work

1. Commit and push the changes on `main`.
2. Update `CHANGELOG.md` — the heading still reads `## 1.0.0 - unreleased`.
3. Tag it. On github.com: **Releases → Draft a new release → Choose a tag →**
   type `v1.0.0` → **Create new tag** → **Publish release**. With git installed
   locally, `git tag v1.0.0 && git push origin v1.0.0` does the same.
4. Submit <https://github.com/tbhise/laravel_password_encryption> at
   <https://packagist.org/packages/submit> again — Packagist reads the new name
   out of `composer.json` and creates `tusharb/laravel-envcrypt`.
5. On the **old** package's page, use **Abandon** and give
   `tusharb/laravel-envcrypt` as the replacement, so anyone still on it is told
   where it went. Delete it instead if nothing depends on it.

## Projects already on the old name

`composer require` does not rename a dependency, so each project needs the swap
made explicitly:

```bash
composer remove npav/laravel-envcrypt
composer require tusharb/laravel-envcrypt:^1.0
php artisan envcrypt:install
```

Nothing is lost by the switch as long as `envcrypt:install` had not been run
yet. If it had, the secret is named `NPAV_<PROJECT>_BUILD_TAG` and that name is
in `.env` as `ENVCRYPT_KEY_VAR` — **leave it exactly as it is**. The name is
read from `.env`, not from the package, so the old name keeps working; renaming
it would strand every value already encrypted under it. Only `db:key-rotate`
can move a live installation onto a differently-named secret.
4. On <https://packagist.org/packages/tusharb/laravel-envcrypt>, click **Update**,
   or enable the GitHub hook under the repository's *Settings → Webhooks* so
   future tags sync by themselves.

Then, in each project:

```bash
composer require tusharb/laravel-envcrypt:^1.0
php artisan envcrypt:install
```

Projects already on `dev-main` move across with the same command — it replaces
the branch constraint with the tagged one.

## Verify a release

In a scratch Laravel project:

```bash
composer require tusharb/laravel-envcrypt
```

The install should end with the package's own notice — that is the signal the
service provider was discovered:

```
  EnvCrypt is in vendor/, but this project is not set up yet.

  Run:  php artisan envcrypt:install
```

Then `php artisan envcrypt:install --project=SCRATCH` and
`php artisan envcrypt:verify`, which should report every line `[ ok ]`.

## Installing into an older project

Two projects here needed dependency work first, and neither problem was in this
package:

* **A lock file that predates the installed PHP.** `empreg` had `mockery/mockery
  1.6.4` and `nette/schema v1.2.3`, both capped below PHP 8.4, and each blocked
  the other's update. `composer update mockery/mockery nette/schema -W` cleared
  both, after which the require succeeded. Note that this moved
  `laravel/framework` from `v8.83.27` to `8.x-dev`, because the stable 8.83.27
  dependency set cannot resolve under PHP 8.4 — if that server runs an older
  PHP, pin resolution to it instead with `"config": {"platform": {"php":
  "8.0.30"}}` and keep the stable tag.
* **No stable tag**, as above.

## Keeping a private package instead

Skip Packagist and point the consuming projects at the repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/tbhise/laravel_password_encryption.git" }
    ]
}
```

`composer require tusharb/laravel-envcrypt:^1.0` then resolves from that
repository's tags.

## Migrating a project installed by the old kit

Projects that `envcrypt-kit.php` installed carry their own copies of the code in
`app/`, and their secret is named `NPAV_<PROJECT>_BUILD_TAG` — the form that
kit hardcodes. The package now names new installs `<PROJECT>_BUILD_TAG`, but the
name is read from `ENVCRYPT_KEY_VAR` in `.env`, so an existing project keeps
whatever name its values were encrypted under.

1. `composer require tusharb/laravel-envcrypt`
2. `php artisan envcrypt:install --project=<the same identifier>` — or set
   `ENVCRYPT_KEY_VAR` in `.env` by hand to the name that project already uses,
   read from the `ROOT_KEY_VAR` constant in `app/Support/EnvCrypt.php`.
3. Delete `app/Support/EnvCrypt.php`, `app/EnvCryptKit.php`,
   `app/Providers/EnvCryptServiceProvider.php`, `storage/tools/envcrypt.php`,
   and the provider line those installed.
4. `php artisan config:clear && php artisan db:secret-check`

The ciphertext stays readable: the cipher, the derivation context and the
`enc:` format are unchanged, so no value needs re-encrypting.
