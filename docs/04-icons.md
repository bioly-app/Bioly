# 4. Icons und Logos

## Drei Quellen

```mermaid
flowchart TB
    L[Lucide<br/>resources/icons/&lt;farbe&gt;/] --> R[IconRegistry]
    S[Simple Icons<br/>resources/originals/ und Farbordner] --> R
    O[Eigene Uploads<br/>Mediathek] --> R
    R --> P[Icon-Auswahl im Editor]
    R --> Seite[öffentliche Seite]
```

| Quelle | Kennung | Farbe |
| --- | --- | --- |
| Lucide (Bibliothek) | `std:heart` | Farbe des Ordners, folgt dem Icon-Stil |
| Plattform-Logo in Markenfarbe | `orig:instagram` | bleibt in der Markenfarbe |
| Plattform-Logo passend zum Design | `std:instagram` | liegt im passenden Farbordner, folgt dem Icon-Stil |
| Eigenes Icon | `own:<16 Zeichen>` | Palettenfarbe oder eigene Farben |

Interface-Icons (Navigation, Buttons) sind getrennt davon in `resources/ui-icons/` und werden mit `{{ ui_icon('name') }}` eingebunden.

## Die Farbordner

Jeder Ordner in `resources/icons/` ist eine Farbe. Welche Farbe genau, steht in `resources/icons/colors.json`. Bioly passt jede Farbe automatisch an, damit sie auf dem gewählten Hintergrund gut lesbar ist.

## Anleitung: neue Icon-Farbe

1. Ordner anlegen, z. B. `resources/icons/tuerkis/`, Icons hineinlegen
2. In `colors.json` eintragen: `"tuerkis": "#0D9488"`
3. Namen in `lang/de.json` und `lang/en.json`: `"color.tuerkis": "Türkis"`
4. `make check-icons`

Der Icon-Cache merkt die Änderung selbst. Danach einmal veröffentlichen.

## Anleitung: Lucide-Icon hinzufügen

1. SVG von [lucide.dev](https://lucide.dev/icons) laden, Dateinamen behalten
2. In den passenden Farbordner legen. Jeder Name darf nur in einem Ordner liegen
3. Optional ein Label: `"icon.<name>": "..."` in `lang/`
4. `make check-icons`

Nur Lucide-Dateien von Hand hinzufügen, damit die Lizenzdatei stimmt.

## Anleitung: Plattform-Logos aktualisieren

Die Logos kommen von [Simple Icons](https://simpleicons.org). Es werden **nur** Icons ohne eigene Lizenz oder mit CC0-1.0 übernommen; das prüft das Skript.

Für „Markenfarbe“ nimmt Bioly die Version, die dem echten Logo am nächsten kommt:

| Quelle | Logos |
| --- | --- |
| [SVG Logos](https://github.com/gilbarbara/logos) (CC0-1.0), mehrfarbig | Dribbble, Facebook, Flickr, GitLab, Google Maps, Messenger, PayPal, Reddit, Stack Overflow, Telegram, TikTok, WhatsApp, YouTube |
| Simple Icons mit Verlauf | Instagram, Steam |
| Simple Icons mit Umriss | Snapchat |
| Simple Icons in der Markenfarbe | alle anderen |

„Passend zum Design“ ist immer Simple Icons. Welche Quelle ein Logo hat, steht in `brands.json` unter `original`.

1. Pakete laden und entpacken: `https://registry.npmjs.org/simple-icons/-/simple-icons-<version>.tgz` und `https://github.com/gilbarbara/logos/archive/refs/heads/main.zip`
2. `php bin/import-brand-icons.php /pfad/zum/simple-icons-package /pfad/zu/logos-main`
3. Die Ausgabe nennt jedes übersprungene Icon mit Grund
4. `make check-icons`

Welche Logos importiert werden, steht als Liste oben im Skript. Ein neues Logo = ein Name mehr in dieser Liste.

## Warum zwei Versionen pro Logo?

Manche Marken erlauben nur ihre eigene Farbe oder Schwarz / Weiß. Mit „Markenfarbe“ ist man immer auf der sicheren Seite. „Passend zum Design“ ist die Wahl des Nutzers.
