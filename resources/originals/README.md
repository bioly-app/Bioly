# Platform logos

Logos of platforms in their brand color, for links to one's own profiles.

## Source and license

The logos come from [Simple Icons](https://simpleicons.org) (npm package `simple-icons`, version in `brands.json`),
released under CC0-1.0. Only icons without a license of their own or with exactly CC0-1.0 are taken.
For the brand color version, the one closest to the real logo is used: multi-colored logos from
[SVG Logos](https://github.com/gilbarbara/logos) by Gil Barbara (CC0-1.0) where they are current, and the Simple Icons
shape with a gradient (Instagram, Steam) or an outline (Snapchat). `brands.json` names the source of each original
under `original`. Each SVG names its source in a comment.

The logos are trademarks of their owners. Using them to link to your own profile is what the platforms
intend; please follow their brand guidelines. Simple Icons does not include some brands at all
(for example LinkedIn and Amazon), so they are missing here too.

## Two versions per logo

| File | Look |
| --- | --- |
| `resources/originals/<name>.svg` | as close to the real logo as possible ("Markenfarbe"); black or white logos use the page's text color |
| `resources/icons/<farbe>/<name>.svg` | one color, in the color folder closest to the brand color; follows the icon style ("Passend zum Design") |

`brands.json` lists all logos with title, brand color and color folder.

## Updating

1. Download and unpack `https://registry.npmjs.org/simple-icons/-/simple-icons-<version>.tgz` and `https://github.com/gilbarbara/logos/archive/refs/heads/main.zip`
2. `php bin/import-brand-icons.php /path/to/simple-icons/package /path/to/logos-main`
3. The script removes the logos of the last import, writes the new ones and lists every skipped icon with the reason
4. Run `make check-icons`
