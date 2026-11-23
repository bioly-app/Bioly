# 11. Ordnerstruktur

Wo liegt was? Grob, nicht jede Datei. Den Weg einer Anfrage durch diese Ordner erklärt [Das große Bild](01-aufbau.md).

## Auf einen Blick

```text
bioly/
├── public/              Webroot: nur das, was der Browser direkt abrufen darf
├── src/                 der PHP-Code
├── templates/           Twig-Vorlagen
├── lang/                Übersetzungen des Backends und der Seite
├── resources/           Icons und Logos (Quelldateien, nicht öffentlich)
├── migrations/          Datenbank-Änderungen, nummeriert
├── config/              Grundeinstellungen von Bioly
├── bin/                 Skripte für die Kommandozeile
├── data/                alles, was beim Benutzen entsteht (gehört dem Nutzer)
├── tests/               automatische Tests
├── docker/              Docker-Start und PHP-Einstellungen für die Entwicklung
├── docs/                diese Doku, images/ mit Screenshots für die README (nicht im Release)
├── dist/                gebaute ZIPs aus make zip und make release (nicht im Git)
├── .github/             Vorlagen für Issues und Pull Requests auf GitHub (nicht im Release)
└── .intern/             interne Notizen, z. B. Release (nicht im Git, nicht im Release)
```

**Merksatz:** `data/` gehört dem Nutzer und überlebt jedes Update. Alles andere ist Programm und wird beim Update ersetzt.

## `public/`: der Webroot

Der Webserver zeigt auf diesen Ordner. Alles darin ist von außen erreichbar, deshalb liegt hier kein PHP außer `index.php`.

```text
public/
├── index.php            Einstieg für jede Anfrage
└── assets/
    ├── css/             site.css (Linkseite, bewusst eine Datei), backend/ (Backend, eine Datei pro Bereich)
    ├── js/              ein Skript pro Aufgabe, z. B. multilink.js, slider.js; editor.js mit seinen Teilen in editor/
    └── patterns/        Muster für Header und Hintergrund als SVG plus patterns.json
```

Die `index.php` im Hauptordner zeigt nur einen Hinweis, falls der Webroot nicht auf `public/` gestellt wurde.

## `src/`: der PHP-Code

```text
src/
├── Kernel.php           baut die Slim-App zusammen
├── Services.php         der Werkzeugkasten: liefert jeden Service
├── Http/                Routen, Middleware, Controller, Fehlerseiten
│   ├── Controller/      SiteController (Linkseite), Admin/ (Backend), Auth, Installer
│   └── Middleware/      Sicherheits-Header, Installer-Sperre, Sitzung, Login
├── Modules/             die Module: Link, Multilink, Image, Countdown, Divider (je Code, Vorlage, Texte)
├── Module/              Grundgerüst für Module: Registry, Validator
├── Page/                Seite bauen (PageBuilder) und veröffentlichen (Publisher)
├── Service/             Logik: Login, Konten, Mail, Mediathek, Favicon, Rechtstexte
├── Repository/          die einzige Stelle mit SQL
├── Database/            Verbindung und Migrationen
├── Theme/               aus wenigen Farben das ganze Design
├── Icon/                Icon-Bibliothek (Lucide, Plattform-Logos, eigene)
├── I18n/                Übersetzer
├── Twig/                eigene Twig-Funktionen, z. B. asset() mit Version
├── Support/             kleine Helfer: Sitzung, Uhr, Verschlüsselung, SVG-Bereinigung, Konfiguration
└── Update/              Updater: Update suchen (ReleaseFeed, UpdateCheck), Paket prüfen (Package), einspielen (Updater), Backups, AfterUpdate
```

## `templates/`: Twig-Vorlagen

```text
templates/
├── frontend/            Linkseite und Rechtsseiten (base.twig ist das Grundgerüst)
├── backend/             Seiten im Backend: Editor, Mediathek, Rechtliches, Einstellungen, Updates, Credits
├── auth/                Login, Passwort vergessen, Passwort neu
├── layout/              Rahmen für Backend und schlichte Seiten
├── partials/            kleine Bausteine: Logo, Formular-Makros
├── installer.twig       Installer
└── error.twig           Fehlerseiten
```

Die Vorlage eines Moduls liegt nicht hier, sondern im Ordner des Moduls unter `src/Modules/`.

## `resources/`: Icons und Logos

```text
resources/
├── icons/               Lucide-Icons, sortiert in Farbordner (blau, gruen, ...)
├── originals/           Plattform-Logos in Markenfarbe plus brands.json
└── ui-icons/            Icons der Oberfläche (Pfeile, Mülleimer, ...)
```

Nicht öffentlich: Bioly liest die Dateien und baut sie in die Seite ein.

## `data/`: alles vom Nutzer

```text
data/
├── app.sqlite           die Datenbank
├── config.php           Schlüssel und Einstellungen dieser Installation (geheim)
├── uploads/             Bilder und Icons aus der Mediathek, Profilbild, Favicon
├── cache/               fertige Seite (page.html, theme.css, favicon.svg), Twig-Cache
├── logs/                Fehlerprotokolle
├── backups/             Backups: vor jedem Update (Programm und Datenbank, die letzten zwei) und eigene (Datenbank und Mediathek)
└── updates/             Arbeitsordner beim Update, wird danach geleert
```

Den Cache kann man jederzeit löschen: Bioly baut ihn beim nächsten Aufruf neu.

## `bin/`: Skripte

| Skript | Wofür |
| --- | --- |
| `migrate.php` | Datenbank auf den neuesten Stand bringen |
| `release.php` | ZIP bauen: `make zip` (Test) und `make release` (signiert), nicht im Release |
| `publish.php` | `make publish`: Release auf GitHub stellen, nicht im Release |
| `keys.php` | `make keys`: Schlüsselpaar zum Signieren, nur einmal, nicht im Release |
| `seed.php` | Beispieldaten für die Entwicklung |
| `check-icons.php` | Icon-Ordner prüfen |
| `import-brand-icons.php` | Plattform-Logos aus Simple Icons und SVG Logos übernehmen (nicht im Release) |
| `bootstrap.php` | gemeinsamer Start für alle Skripte |

## Dateien im Hauptordner

| Datei | Wofür |
| --- | --- |
| `VERSION` | aktuelle Versionsnummer |
| `CHANGELOG.md` | was sich in welcher Version geändert hat |
| `composer.json` / `.lock` | PHP-Abhängigkeiten |
| `Makefile` | Kurzbefehle: prüfen, Update testen, Release, Veröffentlichen |
| `.env.example` | Vorlage für `.env` mit dem GitHub-Token für `make publish` (`.env` nie im Git) |
| `Dockerfile`, `docker-compose.yml` | lokale Entwicklung mit Docker |
| `phpunit.xml`, `phpstan.neon` | Tests und statische Prüfung |
| `README.md`, `CONTRIBUTING.md`, `SECURITY.md` | Einstieg, Mitmachen, Sicherheitslücken melden |
| `LICENSE`, `THIRD-PARTY-NOTICES.md` | Lizenzen von Bioly und den Icons |
