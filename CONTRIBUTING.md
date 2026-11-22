# Contributing to Bioly

Thanks for helping! Please keep these rules in mind.

## How to contribute

- **Bugs and ideas:** open an [issue](https://github.com/bioly-app/bioly/issues/new/choose). Security problems privately, see [SECURITY.md](SECURITY.md)
- **Code:** fork the repository, create a branch from `main`, make your change, run `make check`, open a pull request against `main`
- Describe in the pull request what changes, why, and how to test it. Add a line under `## [Unreleased]` in `CHANGELOG.md`
- Larger changes or new modules: please open an issue first, so we can agree on the way before you build it
- Version numbers and releases are done by the maintainers only. Every release is signed

The maintainer develops in a private repository; GitHub gets `main` and the release tags. Accepted pull requests are merged there and come back to GitHub with the next release.

## Principles

- **KISS, DRY, YAGNI:** keep it simple, don't repeat yourself, only build what is needed
- **Minimal dependencies:** every Composer package is a security and update cost. Discuss new ones first
- **Security first:** escape all output, use prepared statements, validate all input
- **Privacy first:** no tracking, no external resources (fonts, icons and scripts are shipped locally)
- **Accessible:** semantic HTML, keyboard usable, WCAG AA contrast

## PHP

- PSR-12 / PER coding style, checked with `make check`
- `declare(strict_types=1);` in every file
- Types for parameters, return values and properties
- PHPStan level 8 must pass (`make check`)
- Small classes with one job. No business logic in controllers
- SQL only inside repository classes
- Code, comments and commit messages in English. Comments explain *why*, not *what*

## Frontend

- CSS class names follow **BEM**: `.tile`, `.tile__icon`, `.tile--multilink`
- Design values only as CSS custom properties (`--color-accent`, `--radius`)
- Vanilla JS, as little as possible. No inline styles or inline scripts (strict CSP)

## Twig

- Reusable parts as partials or macros
- One template per module
- Never use `|raw` unless the HTML was sanitized

## Translations

- All user facing text goes through `lang/*.json` and the `|t` filter
- Keys are dotted and grouped by area: `backend.links.add`
- German and English must both be updated

## Database

- Never change a released migration. Add a new one instead
- Never rename or drop columns in one step. Add the new one, migrate data, remove the old one later

## Git

- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org): `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`
- Versions follow [Semantic Versioning](https://semver.org)
- Before a pull request: `make check` must pass. There is no CI: the checks run locally
