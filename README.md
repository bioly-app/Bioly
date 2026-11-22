# Bioly

Self-hosted link page with a simple backend. Upload the zip, point your webroot to `/public`, open your domain, done.

> Status: 0.x, in development. Not ready for production yet.

## What works in this version

- Installer: system check, admin account, optional SMTP with confirmation code, site address
- Login after NIST SP 800-63B-4 (long passwords, lock per address and account, session timeouts), CSRF protection, strict Content Security Policy, password reset by email or by FTP file
- Link page with header (image, bio, quicklinks), links, multilinks and dividers, image module and slider
- Multilinks: links loaded only on demand, blocked in in-app browsers, optional age check, limited to 20 requests per minute and address
- Backend: drag and drop, draft, live preview, publish
- Design: colors with contrast checks, header and background patterns, background image, icon style, radius, favicon
- Media library for images and own icons, with alt texts and usage check
- Icons from color folders (`resources/icons/<color>/`) and platform logos, icon picker with color filter
- Link previews in messengers and social networks (Open Graph), canonical address
- Imprint and privacy pages (Markdown editor or external link), 404 page
- Backend in German and English
- Signed release zips (`make keys`, `make release`); after an update the first request runs migrations and rebuilds the page

Planned next: updater in the backend (download, check signature, backup and rollback), contact module.

## Requirements

- PHP 8.3 or newer with `pdo_sqlite`, `mbstring`, `sodium`, `fileinfo` (recommended: `gd`, `zip`, `intl`)
- SQLite 3.37 or newer
- Apache with `mod_rewrite` (Nginx works with your own config)
- Webroot pointing to the `/public` folder, `data/` writable for PHP

## Development

You need Docker and `make`. Run `make` to see all commands; the full flow is explained in `docs/10-arbeitsablauf.md` (German).

| Step | Commands |
| --- | --- |
| Develop | `docker compose up --build` (site http://localhost:8080, backend `/admin`, user `admin`, password `Bioly-Demo-2026`, mail http://localhost:8025), `make reset-demo`, `make reset-empty`, `make migrate` |
| Check before every commit | `make check` runs all checks: code style, PHPStan, tests, icons, `composer audit`. `make fix` corrects the code style, `make check-icons` checks only the icon folders |
| Try an update | `make zip` builds a test zip (not signed), `make testsite` puts it into a separate test site on http://localhost:8081 like an FTP upload, `make testsite-reset` empties it |
| Release | `make keys` (once, ever; asks twice), `make release` |

On the first start a seed script creates the admin user and demo content, so the installer is skipped. New migrations run automatically on every start.

## Icons

One folder per color in `resources/icons/`, colors in `resources/icons/colors.json`.
Download an icon from [lucide.dev](https://lucide.dev/icons) as SVG and put it into the folder of its color. Done.
Multi-colored logos go to `resources/originals/`. Patterns go to `public/assets/patterns/` (see the README there).

## Release

`make keys` once creates your private key in `~/.bioly/signing.key` and writes the public key into `src/Update/PublicKey.php` (commit that).
During development `VERSION` stays as it is and every change goes under `## [Unreleased]` in `CHANGELOG.md`. `make release` asks for the new version, checks that it is higher than `VERSION` and that `[Unreleased]` has entries, asks for confirmation, and builds `dist/bioly-X.Y.Z.zip` with a signed manifest. Commit and tag yourself.

## Project structure

```
bin/          command line scripts
config/       static settings
data/         database, uploads, cache, logs (never in git, never replaced by updates)
docker/       dev container files
lang/         translations (de.json, en.json)
migrations/   database migrations, applied in order
public/       webroot
resources/    icons and original logos
src/          PHP source (namespace Bioly), modules in src/Modules/<Name>/
templates/    Twig templates
tests/        PHPUnit tests
```

## License

Bioly is free software under the [GNU General Public License v3.0 or later](LICENSE).
Copyright (C) 2026 Benedikt Jany. Third-party parts: see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
