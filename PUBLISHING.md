# Publishing this package

The package is live on Packagist as `npav/laravel-envcrypt`, from
<https://github.com/tbhise/laravel_password_encryption>. What is missing is a
**tag** — Packagist only has `dev-main`, so any project with the default
`minimum-stability: stable` refuses to install it:

```
Could not find a version of package npav/laravel-envcrypt matching your
minimum-stability (stable). Require it with an explicit version constraint
allowing its desired stability.
```

## Release the current work

1. Commit and push the changes on `main`.
2. Update `CHANGELOG.md` — the heading still reads `## 1.0.0 - unreleased`.
3. Tag it. On github.com: **Releases → Draft a new release → Choose a tag →**
   type `v1.0.0` → **Create new tag** → **Publish release**. With git installed
   locally, `git tag v1.0.0 && git push origin v1.0.0` does the same.
4. On <https://packagist.org/packages/npav/laravel-envcrypt>, click **Update**,
   or enable the GitHub hook under the repository's *Settings → Webhooks* so
   future tags sync by themselves.

Then, in each project:

```bash
composer require npav/laravel-envcrypt:^1.0
php artisan envcrypt:install
```

Projects already on `dev-main` move across with the same command — it replaces
the branch constraint with the tagged one.

## Verify a release

In a scratch Laravel project:

```bash
composer require npav/laravel-envcrypt
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

`composer require npav/laravel-envcrypt:^1.0` then resolves from that
repository's tags.

## Migrating a project installed by the old kit

Projects that `envcrypt-kit.php` installed carry their own copies of the code in
`app/`, and their secret is named `NPAV_<PROJECT>_BUILD_TAG`.

1. `composer require npav/laravel-envcrypt`
2. `php artisan envcrypt:install --project=<the same identifier>` — or set
   `ENVCRYPT_KEY_VAR` in `.env` by hand to the name that project already uses,
   read from the `ROOT_KEY_VAR` constant in `app/Support/EnvCrypt.php`.
3. Delete `app/Support/EnvCrypt.php`, `app/EnvCryptKit.php`,
   `app/Providers/EnvCryptServiceProvider.php`, `storage/tools/envcrypt.php`,
   and the provider line those installed.
4. `php artisan config:clear && php artisan db:secret-check`

The ciphertext stays readable: the cipher, the derivation context and the
`enc:` format are unchanged, so no value needs re-encrypting.
