# 7. Texte und Sprachen

Kein Text steht fest im Code. Alles kommt aus Sprachdateien.

## Wo die Texte liegen

| Datei | Inhalt |
| --- | --- |
| `lang/de.json`, `lang/en.json` | Backend, Seite, Installer |
| `src/Modules/<Name>/lang/*.json` | Texte eines Moduls |

Schlüssel sind sprechend und nach Bereich sortiert: `media.alt.help`, `editor.quicklinks.add`, `module.image.ratio`.

## Benutzen

```twig
{{ 'media.title'|t }}
{{ 'media.upload.done'|t({n: 3}) }}
```

```php
$this->t('media.saved', ['name' => $label]);
```

Im Editor-JavaScript: `t('editor.icon.choose')`. Welche Bereiche der Editor bekommt, steht in `EditorController` (Liste der Präfixe).

## Regeln

- **Jeder neue Schlüssel in beiden Sprachen.** Fehlt er auf Englisch, erscheint still der deutsche Text; fehlt er ganz, steht der Schlüssel selbst da. Deshalb das Backend beim Testen einmal auf Englisch durchklicken.
- **Keine Gedankenstriche** in Texten.
- Kurze, einfache Sätze. Hilfetexte sagen, **was passiert**, nicht wie es technisch funktioniert.
- Sprache des Backends: in „Einstellungen“. Sprache der Seite: ebenfalls dort, unabhängig davon.
