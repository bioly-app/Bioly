# Changelog

All notable changes to Bioly are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com) and the project uses [Semantic Versioning](https://semver.org).

## [Unreleased]

## [0.1.6] - 2026-11-22

### Added

- Countdown module for the next event: start and optional end (date, time), heading, place and optional link; large or compact view; date and time fields sit side by side in the editor (new field option `pair`). In the compact view, details too long for one line run slowly from right to left in a loop (not with reduced motion). Before the start it counts down days, hours and minutes, during the event it shows "Happening now", afterwards it hides itself or shows "Over". The state follows the visitor's clock (`countdown.js`), the times the time zone from the settings
- Field types `date` and `time` for modules
- 22 more Lucide icons, now 150: among them lock, key, coins, chain, cat, rabbit, car, anchor, compass, book, wine, cookie
- Menu entry "Bugs, wishes, ideas" for the contact form on the Bioly website; it appears as soon as `Project::FEEDBACK_URL` is set
- Patterns can fade out towards the top as well as towards the bottom
- Link previews: Open Graph tags (title, description, header image, address) and a canonical link, built from the stored site address. The page title falls back to "Links" when no name is set
- Make commands sorted by the flow (develop, check, try an update, release) and explained in `docs/10-arbeitsablauf.md`. New: `make zip` (test zip, not signed) and `make testsite` (puts the zip into a separate test site on port 8081 the way an FTP upload does, so an update can be tried end to end). Renamed: `reset` to `reset-demo`, `fresh` to `reset-empty`. Checking is `make check` (plus `make fix` and `make check-icons`); the other single check commands are gone. `make keys` asks twice before it creates a key
- `make check` runs everything the CI runs: code style, PHPStan, tests, icons and `composer audit`; the Forgejo workflow `.forgejo/workflows/ci.yml` does the same on PHP 8.3 and 8.5
- 56 more Lucide icons, now 16 per color, among them emotions (laugh, sad, angry, heartbreak, meh), eyes, silence and mute, dog and bone, IT (terminal, server, database, processor, bug, Wi-Fi, keyboard, monitor) and hobbies (cooking, camping, fishing, hiking, drums, piano, games, reading), for subscription platforms without a logo (exclusive, become a fan); also megaphone, rocket, discount badge, QR code, share and more
- Patterns: dots, waves, contour lines, Memphis and maze, for the header and the background. Both use the same picker: tiles with a preview on their own ground color, then strength (subtle, medium, strong), size (small, medium, large; the tile stays seamless) and "Fade out towards the bottom". Pattern files carry a version (`?v=`), so a changed pattern is never taken from an old cache
- Background: choose "Color" (optionally with a pattern) or "Image" (a photo from the media library, fixed while scrolling; cards and text follow its main color). The media library counts it as used

### Changed

- Favicon "Own SVG" is chosen from the own icons of the media library like everywhere else, no separate upload any more; one-color icons follow the icon style. Favicons uploaded before keep working. The upload endpoint only takes `image` and `icon`
- Quicklinks use the icon style of the page (Design), like the link tiles; at most 5 instead of 8
- Shorter intro texts on the backend pages
- Backend CSS split into one file per area (`public/assets/css/backend/`) with cascade layers. All values come from `tokens.css`: colors, type sizes, spacing (2px steps), corners, control heights, icon sizes, shadows. A test makes sure no color is written anywhere else. The link page keeps one file; its fixed values are variables too
- The page editor script is split into parts (`assets/js/editor/`): state, preview, block list, drawer, icon picker, header, design
- PHPStan runs on level 8 (was 6); the findings are fixed (unchecked database results, missing array keys, a GD color that could be false)
- Platform logos in "Brand color" are now as close to the real logo as possible: 13 multi-colored logos from SVG Logos by Gil Barbara (CC0-1.0, e.g. Google Maps, WhatsApp, TikTok, GitLab), Instagram with its gradient, Steam in blue, Snapchat yellow with outline. "Matching the design" stays Simple Icons
- Release: the version number is set only by `make release`. During development everything is collected under `[Unreleased]`; the release stops if that section is empty, and the zip contains the changelog with the new version
- New brand color "Azur" `#0B63B8` instead of the generic blue; it is also the main color of a new page. In the backend every blue is derived from one variable (`--brand`), status colors and neutrals are variables too
- Pattern strengths differ a bit more (8, 18, 32 %), the fade starts higher; old pattern settings are taken over automatically
- Alt text: whether an image is decoration is decided by the place it is used in, not in the media library. The checkbox "Only decoration" is gone; header image and slider always use the alt text (migration `0005_media_no_decorative`)
- Age check: the fields take digits only (also when pasted), each wrong field gets its own message and a red frame, the focus jumps to the first wrong field; backspace in an empty field goes back to the previous one

- Cleanup:
  - JSON errors have one shape everywhere: `{"error": "<code>", ...}`; scripts only react to codes they know and show a neutral message otherwise (an unknown refusal no longer shows the in-app hint)
  - The list of in-app browsers exists once (`InAppBrowser::PATTERN`); the page gets it as `data-in-app`
  - Texts for the page scripts come as one JSON block instead of 20 `data-text-*` attributes
  - Page and preview share one multilink answer; URL, file name, quicklink and external legal link checks each exist once (`Validator::isWebUrl`, `MediaService::validName($name, $kinds)`, `Quicklinks`, `LegalText::externalLinks`)
  - After an update the first request runs migrations, catches up stored data and rebuilds the page (`AfterUpdate`), also without a command line; before, this waited for the first backend login
  - `MediaService::library()` only reads; catching up old rows happens once in `backfill()`
  - Imprint and privacy are published as finished pages like the link page
  - The editor's draft checks live in `Page\Draft`; backend scripts share `backend/api.js`, `backend/dom.js` and `backend/unsaved.js`
  - JavaScript files are revalidated by the browser on every visit (cheap 304), so modules that import each other are never mixed from two versions
  - CSS: link colors without `!important`, no rule defined twice, the error page uses its own card style (`legal--card`)

### Security

- Loading the links of a multilink is limited to 20 times per minute and address (sliding window, the address is stored only as an HMAC). Real visitors never notice it, bots that collect hidden links are slowed down. Too many requests get a short message to wait a minute
- Password reset: the link in the e-mail is built from the stored site address, never from the Host header of the request (a forged header could send the reset link to a foreign server). New setting "Site address" under Settings; it is set when installing, or on the first backend visit for older installations
- Login lock: per IP address as before (5 tries in 10 minutes); per account only after 50 failures in an hour, so nobody can lock the owner out just by knowing the username
- Passwords after NIST SP 800-63B-4: at least 15 and at most 128 characters, no rules about kinds of characters, and nothing easy to guess (common password words, keyboard and number runs, repeats, username, e-mail or site name). Existing passwords stay valid; the rules apply when a password is set
- Login ends after 1 hour without activity and in any case 12 hours after logging in; the backend keeps it alive while you work and warns 5 minutes before the end
- With HTTPS the session cookie is called `__Host-bioly_session` (cannot be set or overwritten by subdomains); everyone is logged out once after the update
- Logging out also clears cached backend pages and stored data in the browser (`Clear-Site-Data`)
- The database file is readable only by Bioly itself (rights 0600, like `data/config.php`)
- Settings: the SMTP fields have names of their own (`smtp_*`), so an error in the password form no longer showed up in the SMTP form as well
- Uploaded SVGs get ids of their own (prefix per file), so two icons on one page never mix up their gradients or masks; stored SVGs are cleaned again after each update

### Fixed

- Quicklinks kept their icon color with the icon styles "single color" and "mono": the color class from theme.css won over the style
- Blocks below a multilink could not be dragged: the drop zones appeared at drag start and moved the row away under the pointer

## [0.1.5] - 2026-11-21

### Added

- Platform logos from Simple Icons (only icons without a license of their own or with CC0-1.0), each in two versions: brand color (`resources/originals/`) and one color in a color folder (`resources/icons/<farbe>/`) that follows the icon style
- Icon picker tab "Platforms" with the switch "Brand color" / "Matching the design"
- `bin/import-brand-icons.php` imports the logos from an unpacked Simple Icons package and skips every icon with another license
- Quicklinks in the header: small icon links with a description (tooltip and screen reader name), edited in the "Header" tab, at most 8
- Credits page (`/admin/credits`), linked below the account box
- Multilink option "Age check from 18": visitors enter their date of birth before the popup opens; the date stays in the browser, the server hands out the links only with the confirmation header `X-Bioly-Age-Confirmed`; in-app browsers still only get the hint
- Twig recompiles changed templates (`auto_reload`), so an update never shows stale templates
- After an update, the first visit to the backend rebuilds the public page with the new version (new CSS, JavaScript and data from migrations)
- Developer docs in `docs/` (in Git, not in the release)
- Slider: interval for autoplay (3, 5, 8 or 12 seconds)
- Image module shows "No alt text yet" for each image without alt text, with a link to the media library
- Migration `0003_media_alpha`: images remember whether they are transparent
- Migration `0004_media_alpha_recheck`: images uploaded earlier are checked for transparency once more
- Transparent images show a checkerboard in the backend; a transparent header image sits directly on the header color
- Imprint and privacy: instead of own text, a link to an external page can be set; the footer then links there and `/impressum` or `/datenschutz` redirect to it

### Changed

- Slider controls: beside the image with the dots below from 640 px, below the image on phones; solid buttons that read on any background
- Transparent images (PNG logos) show the page background and are never cropped
- Dividers use the heading color for title and line
- "Label for screen readers" is the last field of the image module
- The release zip no longer contains `data/.gitkeep` and never contains the icon import script
- Account box and credits stick to the bottom of the sidebar
- Module settings are grouped into sections with a line and a short title
- Imprint and privacy work like "Page" and "General": changes are a draft with the unsaved bar, "Discard" and "Publish", and the browser asks before leaving
- The language switch in the sidebar is gone again; the backend language is set under Settings
- Imprint and privacy are full pages in the page colors instead of a card; headings, list points and lines use the main color
- CSS and JavaScript URLs carry the file date (`?v=<version>.<hash>`), also for modules loaded later; browsers never keep old files after an update
- The "Open in browser" button and the age check button react visibly to hover and tap

## [0.1.4] - 2026-11-21

### Added

- Module "Image / slider": one image or several from the media library, swipe and snap without JavaScript, arrows and dots, aspect ratio 1:1, 4:3 or 16:9, an optional link per image
- Optional autoplay for the slider (every 5 seconds) with a pause button; paused for visitors who prefer less motion, while hovered or focused and when off screen
- Average color of every library image, shown behind the image while it loads
- Links move into and out of multilinks by drag and drop (drop zone at the end of each multilink) or with the arrow buttons
- "Text" button in the imprint and privacy editor: removes heading, list, bold and italic from the selected lines
- Lucide icons `chevron-left`, `pause` and `play` in `resources/ui-icons`

### Changed

- Page layout rebuilt for all screen sizes: one content width for header, tiles, dividers and footer; one column on phones, two from 640 px; spacing and icon sizes scale with the screen
- Subtitles wrap to two lines and end with "…" instead of widening the tile
- Multilink tiles look like the other tiles (no dashed border)
- Divider lines and footer links are black or white, whichever has more contrast on the background color
- Media library: "Name" is now "Label (only for you)" with a short explanation
- Dropdowns in the backend have their own arrow with room to the edge

### Fixed

- Tiles overlapped each other and grew wider than the page when a subtitle was long
- Links in the backend preview opened the target; now a short note appears instead, popups like the multilink still open

## [0.1.3] - 2026-11-20

### Added

- Media library (`/admin/media`) with the tabs "Images" and "Icons": upload several files at once, name, alt text, "decoration only", icon color, delete
- Files that are in use (header image, icon in a module, favicon) cannot be deleted; the card says where they are used
- Own icons (SVG): one palette color (follows the icon style like the library icons) or own colors; usable in modules, quick links and as favicon
- Icon picker tab "Own" with direct upload
- Header image: "Choose image" opens the media library, uploads land there too
- Language switch (Deutsch / English) for the backend in the account box
- Migration `0002_media_library` (column `label`, index on `usage`)

### Changed

- Header images keep their shape (at most 1600 px on the longer side) and are cropped to the circle by CSS
- The header image on the page uses the alt text from the media library; decorative or missing alt text keeps it hidden from screen readers
- Favicon from an icon embeds the icon as nested SVG, so icons of any size fit the tile
- "Add module" list is ordered by `sortOrder()`; the divider comes last
- Upload endpoint `/admin/api/upload/{image|icon|favicon}` replaces `avatar`

### Fixed

- The dot between "Imprint" and "Privacy" in the page footer sits on the text line again

## [0.1.2] - 2026-11-20

### Changed

- The backend account box shows the header image (or the first letter when there is none); the upload stays in the "Header" tab
- Icon picker, icon field and favicon show icons exactly like the page: page surface, tinted square and color by icon style (tinted, single color, mono)
- Favicon from an icon follows the global icon style; the separate background option is gone
- Preview phone frame slightly slimmer
- Logout is a labeled button in its own row of the account box

## [0.1.1] - 2026-11-20

### Added

- Login with username or email address
- Show password button in all password fields
- Live checklist for password rules
- Profile image for the header (JPG, PNG, WebP up to 1 MB; cropped, scaled, metadata removed), also shown in the backend account box
- Favicon as SVG: automatic letter, icon from the library (optional background) or own SVG upload with an allow list cleaner
- Backend section "General" for design and favicon, section "Legal" with tabs for imprint and privacy
- Format bar for imprint and privacy (headings, bold, italic, list, link, line, shortcuts) with server rendered preview
- Language switch in the installer that applies right away; first language from the browser

### Changed

- Password rules: at least 12 characters with lowercase and uppercase letter, number and special character
- Login lock lasts 10 minutes and shows the remaining time with a live countdown
- Password reset by email has no address field any more: the link always goes to the stored address; at most one mail per minute and five per hour, a new link makes older ones invalid
- Buttons show a busy state and forms cannot be sent twice
- Editor tab "Profile" is now "Header"; the preview looks like a 390 px phone and scrolls on its own
- Clearer text under "About Bioly"; tidier account box with logout icon
- Demo password is now `Bioly-Demo-2026`
- Links in legal texts may also use `tel:`

## [0.1.0] - 2026-11-19

### Added

- Project setup: Slim 4, Twig, SQLite, Docker with PHP 8.5 and an 8.3 test container, Mailpit
- Installer with system check, account, SMTP confirmation code and basics
- Login with rate limit, sessions, CSRF protection, strict CSP and security headers
- Password reset by email link or by FTP file
- Link page with header, links, multilinks (in-app browser lock, links loaded on demand) and dividers
- Theme: colors follow the background automatically, icon styles tinted, single color and mono
- Icon folders by color with cache and picker, originals tab
- Backend: page editor with drag and drop, draft, live preview and publish; profile, design, settings, imprint, privacy
- German and English translations
- Signed release zips with `make keys` and `make release`
