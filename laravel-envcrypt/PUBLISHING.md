# Publishing this package

Publishing needs your GitHub and Packagist accounts, so these are the steps to
run yourself. The package is otherwise ready: `composer.json`, autoloading,
Laravel auto-discovery, tests, licence and changelog are all in place.

## 1. Check the name

`composer.json` claims `npav/laravel-envcrypt`. The vendor part must match the
GitHub account or organisation you publish from, and it must be free on
<https://packagist.org/packages/npav/laravel-envcrypt> — Packagist will refuse a
name someone already holds. Change the `name` field and the `Npav\EnvCrypt`
namespace together if you pick something else.

Check the author block too — it currently reads `NPAV <tusharb@npav.net>`.

## 2. Push it to GitHub

From this directory:

```bash
git init
git add .
git commit -m "Initial release of npav/laravel-envcrypt"
git branch -M main
git remote add origin https://github.com/<account>/laravel-envcrypt.git
git push -u origin main
```

The repository must be **public** for Packagist to index it.

## 3. Tag a release

Composer resolves versions from tags, so an untagged repository installs only
as `dev-main`.

```bash
git tag v1.0.0
git push origin v1.0.0
```

Update `CHANGELOG.md` — the heading currently says `unreleased` — before you
tag.

## 4. Submit it to Packagist

1. Sign in at <https://packagist.org> with the GitHub account that owns the
   repository.
2. **Submit** → paste the repository URL → **Check** → **Submit**.
3. On the package page, click **Settings** and enable the GitHub hook (or add
   Packagist's webhook in the repository's *Settings → Webhooks*). Without it,
   new tags are not picked up automatically.

## 5. Verify

In a scratch Laravel project:

```bash
composer require npav/laravel-envcrypt
php artisan envcrypt:install
php artisan list db
```

`envcrypt:install` should publish `config/envcrypt.php` and write
`ENVCRYPT_KEY_VAR` into `.env`; `php artisan list db` should show `db:keygen`,
`db:secret-check` and the rest.

## Keeping a private package instead

If this should not be public, skip Packagist and point the consuming projects
at the repository directly:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/<account>/laravel-envcrypt.git" }
    ]
}
```

`composer require npav/laravel-envcrypt:^1.0` then resolves from the tags in
that repository. Private Packagist and self-hosted Satis work the same way with
more setup.

## Afterwards

`envcrypt-kit.php` in the parent directory is the original single-file
installer. Projects it installed carry their own copies of the code in `app/`,
and their secret variable is named `NPAV_<PROJECT>_BUILD_TAG`. To move one onto
the package:

1. `composer require npav/laravel-envcrypt`
2. Set `ENVCRYPT_KEY_VAR` in `.env` to the name that project already uses —
   read it from the `ROOT_KEY_VAR` constant in `app/Support/EnvCrypt.php`.
3. Delete `app/Support/EnvCrypt.php`, `app/EnvCryptKit.php`,
   `app/Providers/EnvCryptServiceProvider.php`, `storage/tools/envcrypt.php`,
   and the provider line those installed.
4. `php artisan config:clear && php artisan db:secret-check`

The ciphertext stays readable: the cipher, the derivation context and the
`enc:` format are unchanged, so no value needs re-encrypting.
