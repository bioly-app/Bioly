# Bioly verstehen und weiterentwickeln

Diese Sammlung erklärt, **wie Bioly aufgebaut ist** und **wie man es erweitert**. Sie ist bewusst einfach gehalten: erst das Bild im Großen, dann kurze Anleitungen zum Nachmachen.

> Arbeitsdokumente für die Entwicklung. Der Ordner `docs/` ist im Git versioniert, kommt aber **nicht** in die Release-ZIP.

## Inhalt

| Nr. | Thema | Worum es geht |
| --- | --- | --- |
| 1 | [Das große Bild](01-aufbau.md) | Wie eine Anfrage durch Bioly läuft, welche Ordner wofür da sind |
| 2 | [Vom Entwurf zur Seite](02-entwurf-und-veroeffentlichen.md) | Editor, Vorschau, Veröffentlichen, Cache |
| 3 | [Module](03-module.md) | Wie Module funktionieren und wie man ein neues baut |
| 4 | [Icons und Logos](04-icons.md) | Lucide, Plattform-Logos, eigene Icons, neue Farben |
| 5 | [Mediathek](05-mediathek.md) | Uploads, Prüfung, Verwendung, Transparenz |
| 6 | [Design](06-design.md) | Wie aus wenigen Farben das ganze Theme wird |
| 7 | [Texte und Sprachen](07-sprachen.md) | Übersetzungen anlegen und benutzen |
| 8 | [Datenbank](08-datenbank.md) | Tabellen, Einstellungen, Migrationen |
| 9 | [Sicherheit](09-sicherheit.md) | Die Regeln, die jede Änderung einhalten muss |
| 10 | [Arbeitsablauf](10-arbeitsablauf.md) | Make-Befehle, Prüfen, Update testen, Updater und Backups, Release und Veröffentlichen |
| 11 | [Ordnerstruktur](11-ordnerstruktur.md) | Wo liegt was: jeder Ordner in ein, zwei Sätzen |

## Grundsätze in einem Satz

Bioly soll **sicher**, **schnell**, **barrierefrei** und **einfach zu hosten** sein: PHP, SQLite, keine Zusatzdienste. Jede Erweiterung folgt diesen vier Punkten.
