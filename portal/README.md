# Tools-Portal (All-Inkl, PHP)

Login per E-Mail-Link, Übersicht und Rechtevergabe pro Tool.

## Einrichtung bei All-Inkl
1. In KAS Subdomain anlegen, **Ziel-Pfad = `portal/public`** (nur dieser Ordner ist öffentlich erreichbar). SSL (Let's Encrypt) aktivieren, PHP ≥ 8.1.
2. Ordner `portal/` per FTP/Git hochladen.
3. `portal/app/config.sample.php` nach `config.php` kopieren und anpassen (URL, Absender-Mail, Admin-E-Mail).
4. `portal/data/` muss für PHP beschreibbar sein (SQLite-Datei wird automatisch angelegt).
5. Unter `/login.php` mit der Admin-Adresse anmelden, in `/admin.php` Nutzer anlegen und Tools freischalten.

## Neues Tool hinzufügen
Ordner `portal/tools/<name>/` (Kleinbuchstaben, Ziffern, Bindestrich) mit `index.html` anlegen, optional `tool.json` mit `title` und `description`. Das Tool erscheint automatisch in der Verwaltung. Relative Links/Assets im Tool funktionieren, solange sie relativ sind.
