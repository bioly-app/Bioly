# Third-party notices

Bioly is licensed under the GPL-3.0-or-later. It includes or uses the following parts from others.

Bioly runs with these packages. They are installed by Composer into `vendor/` (also in the release zip), each with its own license file.

| Package | License |
| --- | --- |
| slim/slim, slim/psr7, slim/twig-view | MIT |
| nikic/fast-route (used by Slim) | BSD-3-Clause |
| twig/twig | BSD-3-Clause |
| symfony/mailer, symfony/mime, symfony/event-dispatcher and their contracts and polyfills | MIT |
| egulias/email-validator, doctrine/lexer | MIT |
| psr/container, psr/event-dispatcher, psr/http-factory, psr/http-message, psr/http-server-handler, psr/http-server-middleware, psr/log | MIT |
| fig/http-message-util, ralouphie/getallheaders | MIT |

Development tools (PHPUnit, PHPStan, PHP CS Fixer and their dependencies) are only used locally and are not part of a release.

Icons: Lucide, `lucide-static` 1.76.0, ISC License, some icons MIT (Feather), see below.

## Lucide

Copyright (c) 2026 Lucide Icons and Contributors (ISC License).
Icons derived from Feather: Copyright (c) 2013-present Cole Bemis (MIT License).

Lucide icons are used unchanged in three places. Each place has the full license text next to the icons:

| Folder | Content | License file |
| --- | --- | --- |
| `resources/icons/` | icon library for links (150 icons in color folders) | `resources/icons/LICENSE.txt` |
| `resources/ui-icons/` | interface icons (backend, arrows on link buttons) | `resources/ui-icons/LICENSE.txt` |
| `src/Modules/*/icon.svg` | module icons in the editor | `src/Modules/*/ICON-LICENSE.txt` |

## Simple Icons

Platform logos come from [Simple Icons](https://simpleicons.org) (npm package `simple-icons`), released under CC0-1.0.
Only icons without a license of their own or with CC0-1.0 are imported (`bin/import-brand-icons.php` checks this).
Each logo is written twice: in its brand color to `resources/originals/` and in one color to a color folder in
`resources/icons/`. `resources/originals/brands.json` lists them; every file names its source in a comment.

## SVG Logos

Some platform logos in "Brand color" (multi-colored, closer to the real logo) come from
[SVG Logos](https://github.com/gilbarbara/logos) by Gil Barbara, released under CC0-1.0. Which ones is listed in
`bin/import-brand-icons.php` (`SVG_LOGOS`) and as `"original": "svg-logos"` in `resources/originals/brands.json`.
Instagram and Steam use the Simple Icons shape with a gradient, Snapchat with an outline (`simple-icons+gradient`,
`simple-icons+outline`). The one-color versions always come from Simple Icons.

All logos are trademarks of their owners. They are only meant to link to one's own profile on that platform
and do not imply any partnership or endorsement. Please follow the brand guidelines of the platforms.
