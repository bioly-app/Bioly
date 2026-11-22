# 9. Sicherheit

Diese Regeln gelten für **jede** Änderung. Sie sind der Grund, warum Bioly ohne Plugins und Zusatzdienste sicher bleibt.

## Die elf Regeln

1. **Eingaben nie vertrauen.** Was der Browser schickt, prüft der Server immer selbst, auch wenn der Editor es schon geprüft hat.
2. **Ausgaben maskieren.** Twig macht das automatisch. `|raw` nur für HTML, das Bioly selbst erzeugt oder bereinigt hat (Icons, Rechtstexte).
3. **Kein Inline-JavaScript, keine `style`-Attribute** im HTML. Die Content-Security-Policy blockiert beides. Farben aus Daten setzt JavaScript über `element.style`, das ist erlaubt.
4. **Jedes Formular mit CSRF-Schutz:** `{{ m.csrf() }}` im Formular, bei `fetch` der Header `X-CSRF-Token`.
5. **SQL nur mit Platzhaltern** und nur in Repositories.
6. **Uploads neu aufbauen,** nicht nur prüfen: Bilder neu kodieren, SVGs über `SvgSanitizer`.
7. **Dateinamen selbst vergeben,** nie den Namen des Nutzers als Pfad verwenden.
8. **Weiterleitungen nur innerhalb von Bioly,** nie zu einer Adresse aus der Anfrage.
9. **Links prüfen:** nur `https`, `http`, `mailto`, `tel`. Nie `javascript:`.
10. **Geheimnisse verschlüsselt** speichern (SMTP-Passwort), Passwörter nur als Hash, IP-Adressen nur als HMAC.
11. **Die eigene Adresse nie aus der Anfrage nehmen.** Der Host-Header kommt vom Besucher. Links nach außen (E-Mails) baut Bioly nur aus der gespeicherten Seitenadresse: `$services->siteUrl()`.

## Wo die Schutzmechanismen sitzen

| Schutz | Datei |
| --- | --- |
| Sicherheits-Header und CSP | `src/Http/Middleware/SecurityHeaders.php` |
| Login-Pflicht fürs Backend | `src/Http/Middleware/RequireLogin.php` |
| CSRF, Sitzung | `src/Support/Session.php` |
| Login-Sperre (pro IP streng, pro Konto locker), Sitzungsdauer (1 h inaktiv, höchstens 12 h) | `src/Service/Auth.php`, Grenzen in `config/settings.php` |
| Passwortregeln (NIST: 15 bis 128 Zeichen, nichts leicht zu Erratendes) | `src/Support/PasswordPolicy.php` |
| Warnung vor dem Abmelden, Sitzung am Leben halten | `public/assets/js/backend/session.js` |
| Session-Cookie (`__Host-` bei HTTPS, HttpOnly, SameSite) | `src/Support/Session.php` |
| Seitenadresse für E-Mail-Links | `src/Support/SiteUrl.php` |
| SVG-Bereinigung, eigene IDs pro Datei | `src/Support/SvgSanitizer.php` |
| Bremse für Multilinks (20 Abrufe pro Minute und Adresse, Adresse nur als HMAC gespeichert) | `src/Service/RateLimiter.php`, Tabelle `rate_hits` |
| Feldprüfung der Module | `src/Module/Validator.php` |

## Datenschutz: was Bioly speichert

Die Betreiber schreiben Impressum und Datenschutzerklärung selbst. Was Bioly selbst verarbeitet, steht als fertiger Baustein im Backend unter Rechtliches > Datenschutz (Text: `legal.block.text` in `lang/*.json`). Der Bereich ist aufklappbar und lässt sich kopieren oder an den eigenen Text anhängen. Ob er in der Datenschutzerklärung steht, prüfen die Betreiber selbst.

| Was | Wo | Wie lange |
| --- | --- | --- |
| Keine Cookies auf der Linkseite | öffentliche Routen starten keine Session (`Routes.php`) | |
| Login-Cookie `bioly_session` | nur im Backend, technisch notwendig | bis zum Abmelden, höchstens 12 Stunden |
| Bestätigte Altersabfrage | `sessionStorage` im Browser (`multilink.js`) | bis der Tab geschlossen wird |
| IP beim Nachladen von Linklisten | Tabelle `rate_hits`, nur als HMAC (`RateLimiter`) | eine Minute, gelöscht bei der nächsten Anfrage |
| Fehler | `data/logs/app.log`: Zeit, Methode, Pfad, Fehlermeldung, keine IP | bis zum Löschen |
| Externe Dienste | keine: Schriften, Bilder und Icons kommen vom eigenen Server | |
| Update-Suche | der Server fragt GitHub nach der neuesten Version; übertragen wird nur die Adresse des Servers, keine Daten von Besuchern. Abschaltbar unter Updates | einmal am Tag |

Wer etwas daran ändert, z. B. ein Cookie oder einen externen Dienst einführt, passt den Baustein in `lang/*.json` und diese Tabelle mit an.

## Vor jedem Pull Request

- `make check` ohne Fehler
- Keine Zugangsdaten, Schlüssel oder Testdaten im Commit
- Sicherheitslücken nicht öffentlich melden, sondern an die Adresse in `SECURITY.md`
