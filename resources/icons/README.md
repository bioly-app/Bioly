# Icons

The icon library for links and multilinks. One folder per color, the folder name must exist in `colors.json`.

## Source and license

All icons in this folder except the platform logos (see below) are original, unchanged files from [Lucide](https://lucide.dev)
(package `lucide-static`, version 1.76.0).

- Copyright (c) 2026 Lucide Icons and Contributors, ISC License
- Some Lucide icons are derived from Feather, Copyright (c) 2013-present Cole Bemis, MIT License
  (here: `at-sign`, `calendar`, `clock`, `code`, `compass`, `database`, `download`, `frown`, `headphones`, `info`, `link`, `link-2`, `lock`, `meh`, `monitor`, `moon`, `music`, `radio`, `rss`, `server`, `shopping-bag`, `smartphone`, `smile`, `target`, `terminal`, `tv`)
- Full license text: [`LICENSE.txt`](LICENSE.txt). Each SVG also keeps its `@license` comment.

## Included icons (150)

| Color | Icons |
| --- | --- |
| rot | heart, video, flame, play, tv, film, bell, heart-handshake, target, megaphone, heart-pulse, bookmark, angry, heart-crack, flame-kindling, drum, wine, calendar-heart, hand |
| orange | shopping-cart, shopping-bag, coffee, utensils, pizza, store, ticket, tag, truck, rocket, wrench, flag, dog, bone, chef-hat, tent, cookie, beer, cat |
| gelb | star, sparkles, lightbulb, gift, sun, trophy, award, zap, crown, thumbs-up, party-popper, badge-percent, laugh, frown, meh, dices, coins, key-round, hourglass |
| gruen | phone, map-pin, map, leaf, wallet, euro, piggy-bank, sprout, bike, dumbbell, paw-print, mountain, trees, fish, footprints, volleyball, compass, handshake |
| blau | globe, send, message-circle, info, briefcase, cloud, plane, newspaper, graduation-cap, users, shield-check, laptop, wifi, server, database, monitor, anchor, car, umbrella |
| lila | music, headphones, mic, podcast, calendar, gamepad-2, book-open, radio, guitar, moon, rss, pen-tool, terminal, cpu, bug, piano, infinity, book, sailboat |
| pink | camera, image, palette, smile, shirt, flower, cake, baby, scissors, hand-heart, brush, gem, eye, eye-closed, book-heart, heart-plus, ice-cream-cone, rabbit |
| grau | link, mail, house, user, file-text, download, clock, code, at-sign, smartphone, share-2, qr-code, volume-x, mic-off, keyboard, lock-keyhole, lock, link-2, pen-line |

## Adding an icon

1. Download the SVG from https://lucide.dev/icons, keep the Lucide file name
2. Put it into the folder of the color it should have. Each name may exist in one folder only
3. Add a label in `lang/de.json` and `lang/en.json` as `icon.<file name>` (otherwise the file name is shown)
4. Run `make check-icons`

Only add icons from Lucide here by hand, so `LICENSE.txt` stays correct.

## Platform logos

The color folders also hold platform logos from [Simple Icons](https://simpleicons.org) (CC0-1.0) in one color,
so they follow the icon style. They are written by `bin/import-brand-icons.php` together with their brand color
version in `resources/originals/`, which explains source, license and trademarks. Each of these files names its source in a comment;
they are listed in `resources/originals/brands.json`. Do not edit them by hand.
