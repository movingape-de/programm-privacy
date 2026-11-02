# Auftrag: Libre-Glukose-Dashboard als Feature in Stingl OS einbauen

Dieses Dokument ist eine Übergabe an Claude Code im Stingl-OS-Projekt. Es enthält Ziel, Hintergrund, die funktionierende Referenzimplementierung und einen Integrationsplan. Lies es vollständig, **analysiere zuerst den Stack von Stingl OS** und baue dann das Feature passend zu den vorhandenen Konventionen ein.

## 1. Ziel

Ein Feature „Glukose“ in Stingl OS (live unter `https://os.stingldesign.com`), das die Glukosewerte von **Karina Medowa** (FreeStyle Libre 3) für **Dominik Stingl** anzeigt:

- aktueller Wert mit Trendpfeil, Alter der Messung
- Verlaufsgraph (3 h, 6 h, 12 h, 24 h, 7 d, 14 d) mit Zielbereich und Hover-Wert
- Statistik: Durchschnitt, GMI (≈ HbA1c), Variabilität (CV), Anzahl Messwerte, Time in Range (tief/im Ziel/hoch)
- Warnung, wenn der letzte Wert älter als 15 Minuten ist
- optional Browser-Benachrichtigung bei hoch/tief
- Anzeige in mg/dL oder mmol/L (Standard mg/dL)

## 2. Datenquelle: LibreLinkUp (inoffizielle API)

Libre 3 hat keine offizielle öffentliche API. Der gängige Weg ist **LibreLinkUp**, die Follower-Freigabe von Abbott.

**Stand der Einrichtung (Nov 2026):**
- Karina hat Dominik über ihre Libre-App per LibreLinkUp eingeladen (Mail „Karina Medowa möchte Messwerte mit Ihnen teilen“ ist angekommen).
- Dominik legt in der **LibreLinkUp-App** einen eigenen Account mit `stingl@me.com` an, akzeptiert die Nutzungsbedingungen und nimmt die Einladung an.
- Das Backend loggt sich mit **Dominiks LibreLinkUp-Zugang** ein, nicht mit Karinas Konto.
- Der Live-Login wurde bisher **nicht gegen echte Zugangsdaten getestet**, nur der Demo-Modus. Rechne mit kleinen Anpassungen beim ersten echten Lauf.

**Ablauf der API** (Basis `https://api-de.libreview.io`, je nach Region):

1. `POST /llu/auth/login` mit `{email, password}` und Headern `product: llu.android`, `version: 4.16.0`, `content-type: application/json`, `cache-control: no-cache`.
   - Antwort `data.redirect === true` mit `data.region`: Host auf `api-<region>.libreview.io` umstellen und Login wiederholen.
   - `status === 2`: falsche Zugangsdaten.
   - `data.step` ohne `data.authTicket`: noch ein Schritt offen (meist Nutzungsbedingungen), Nutzer muss das einmal in der offiziellen LibreLinkUp-App erledigen.
   - Token: `data.authTicket.token`.
2. Alle weiteren Aufrufe mit `Authorization: Bearer <token>` **und** `Account-Id: sha256(data.user.id)` (hex). Ohne `Account-Id` gibt es Fehler.
3. `GET /llu/connections` liefert die verbundenen Personen (`patientId`, `firstName`, `lastName`, `targetLow`, `targetHigh`, `glucoseMeasurement`).
4. `GET /llu/connections/{patientId}/graph` liefert `data.connection.glucoseMeasurement` (aktueller Wert) und `data.graphData[]` (etwa die letzten 12 h, ca. alle 15 min).
   - Felder: `ValueInMgPerDl`, `TrendArrow` (1 ⬇, 2 ↘, 3 →, 4 ↗, 5 ⬆), `FactoryTimestamp` (UTC, Format `M/D/YYYY h:mm:ss AM|PM`), `Timestamp` (lokal, nicht verwenden), `isHigh`, `isLow`.
5. HTTP 401: Token abgelaufen, neu einloggen. HTTP 429/430: Rate-Limit, bis zum nächsten Zyklus warten.

**Regeln, damit der Zugang nicht gesperrt wird:**
- Maximal **alle 60 Sekunden** abfragen, serverseitig in einem Intervall, nicht pro Browser-Request. Browser lesen nur aus dem Cache des eigenen Backends.
- Token wiederverwenden, nicht bei jeder Abfrage neu einloggen.
- Die API liefert nur etwa 12 h. Für 7/14-Tage-Ansichten müssen die Werte **selbst gespeichert** werden (siehe Speicher unten).

Referenz für Details und Sonderfälle: <https://github.com/nightscout/nightscout-connect> (LibreLinkUp-Quelle: Regionen, Terms-Handling, Rate-Limits). Das Projekt ist ein Nightscout-Sidecar ohne dokumentierte Bibliotheks-API, daher wurde es **nicht** als Abhängigkeit genutzt.

## 3. Referenzimplementierung (fertig, im Demo-Modus getestet)

Repo `movingape-de/programm-privacy`, Branch **`add-libre-dashboard`**, Ordner `libre-dashboard/`:

| Datei | Inhalt |
|---|---|
| `server.js` | Node ≥ 18, keine Abhängigkeiten. LibreLinkUp-Client, Polling, Verlauf in `data/history.json`, `GET /api/glucose?hours=N`, `GET /api/patients`, optional Basic-Auth, Demo-Modus (`DEMO=1`) |
| `public/index.html` | Komplettes Frontend (Vanilla JS, Inline-SVG-Chart, Hell/Dunkel per `prefers-color-scheme`) |
| `.env.example` | Konfiguration |
| `README.md` | Einrichtung |

Hole die Dateien mit `git fetch` auf den Branch (oder über die GitHub-MCP-Tools, falls das Repo zugänglich ist) und lies sie als Vorlage. **Kopiere nicht blind**, sondern passe sie an den Stack von Stingl OS an.

**Kern des Datenvertrags** (`GET /api/glucose?hours=24`), den das Frontend erwartet:

```json
{
  "ok": true, "error": null, "updated": 1793533910663,
  "unit": "mgdl", "demo": false, "pollSeconds": 60,
  "patient": "Karina M.",
  "current": { "mgdl": 112, "trend": 3, "isHigh": false, "isLow": false, "t": 1793533860000 },
  "targets": { "low": 70, "high": 180 },
  "readings": [[1793523960000, 75], [1793524860000, 87]]
}
```

`readings` sind `[Zeitstempel in ms (UTC), mg/dL]`, aufsteigend sortiert. Umrechnung mmol/L erst im Frontend: `mgdl / 18.016`. GMI = `3.31 + 0.02392 × Durchschnitt(mg/dL)`.

Kernlogik des Servers, gekürzt (volle Version im Branch):

```js
const sha256 = s => crypto.createHash('sha256').update(s).digest('hex');
const H = { 'content-type':'application/json','cache-control':'no-cache', product:'llu.android', version:'4.16.0' };

async function login() {
  for (let hop = 0; hop < 3; hop++) {
    const r = await (await fetch(`https://${host}/llu/auth/login`, { method:'POST', headers:H,
      body: JSON.stringify({ email, password }) })).json();
    if (r.status === 2) throw new Error('E-Mail oder Passwort falsch');
    if (r.data?.redirect && r.data.region) { host = `api-${r.data.region.toLowerCase()}.libreview.io`; continue; }
    if (r.data?.step && !r.data?.authTicket) throw new Error('Nutzungsbedingungen in der LibreLinkUp-App akzeptieren');
    return { token: r.data.authTicket.token, accountId: sha256(r.data.user.id) };
  }
}
// danach: headers = { ...H, authorization:`Bearer ${token}`, 'account-id': accountId }
// GET /llu/connections -> patientId; GET /llu/connections/{id}/graph -> graphData + current
```

## 4. Integrationsplan für Stingl OS

**Schritt 0: Analyse (vor dem Coden, kurz berichten):**
- Stack, Framework, Routing, Build, Deployment auf `os.stingldesign.com`
- bestehende Auth/Login und Rollen
- wo ein neues „Feature/Tool“ normalerweise registriert wird (Navigation, Routen, Kachel)
- ob es ein Backend mit Node gibt und wo sich dauerhafter, **nicht öffentlicher** Speicher befindet
- Hinweis: In `programm-privacy` gab es einen früheren Commit „tools portal with magic-link login and per-tool access control“ (später revertet). Wenn Stingl OS auf dieser Idee aufbaut, sind pro-Tool-Zugriffsrechte der passende Mechanismus.

**Schritt 1: Backend-Modul**
- LibreLinkUp-Client und Polling als Server-Modul, im bestehenden Backend registriert, ein Polling-Intervall pro Prozess (nicht pro Request, nicht pro Nutzer).
- Endpunkt `GET /api/glucose` nach dem Datenvertrag oben, **nur für berechtigte Nutzer**.
- Verlauf dauerhaft speichern (SQLite/Datei/DB, je nach Stack), Aufbewahrung 14 Tage oder mehr, Duplikate über den Zeitstempel vermeiden.
- Zugangsdaten nur aus Umgebungsvariablen/Secret-Store, nie ins Repo, nie ins Frontend, nie ins Log.

**Schritt 2: Frontend**
- Seite/Komponente „Glukose“ im Stil von Stingl OS (Design-Tokens, Navigation, Hell/Dunkel des OS verwenden, nicht die Farben aus der Referenz festschreiben).
- Logik und Darstellung aus `public/index.html` übernehmen: Zeitraum-Tabs, SVG-Chart mit Lücken bei mehr als 20 min ohne Wert, Zielbereich als Band, Statistik und TIR-Balken.
- Auf Smartphone-Breite prüfen (Touch statt Hover).
- Polling im Frontend alle 60 s gegen das eigene Backend.

**Schritt 3: Konfiguration**

| Variable | Bedeutung |
|---|---|
| `LLU_EMAIL` | LibreLinkUp-Login von Dominik (`stingl@me.com`) |
| `LLU_PASSWORD` | Passwort, nur als Secret |
| `LLU_REGION` | `DE` (sonst automatische Erkennung per Redirect) |
| `LLU_PATIENT_ID` | optional, nur bei mehreren verbundenen Personen |
| `GLUCOSE_UNIT` | `mgdl` (Standard) oder `mmol` |
| `GLUCOSE_DEMO` | `1` = simulierte Daten ohne Zugangsdaten |

**Schritt 4: Tests**
- Demo-Modus im Browser prüfen (Desktop + Smartphone, hell + dunkel, alle Zeiträume, keine Konsolenfehler).
- Fehlerfälle prüfen: falsches Passwort, Rate-Limit (429), keine Verbindung, veraltete Daten (>15 min), leerer Verlauf.
- Erst danach echter Login mit den Zugangsdaten. Wenn die LibreLinkUp-Antwort vom erwarteten Format abweicht, **Rohantwort ohne Token/Namen** loggen, Parser anpassen und kurz berichten, was abwich.

## 5. Datenschutz und Sicherheit (verbindlich)

- Das sind **Gesundheitsdaten einer Person, die nur Dominik zugestimmt hat** (Karina, LibreLinkUp-Einladung). Sie kann die Freigabe jederzeit in ihrer Libre-App widerrufen.
- Die Seite ist öffentlich im Internet erreichbar. Deshalb: **HTTPS, Login erforderlich, Zugriff nur für Dominik** (optional später ein eigener Zugang für Karina nach Rücksprache). Kein Endpunkt ohne Authentifizierung, kein Caching durch CDN/Proxy (`Cache-Control: no-store`).
- Keine Glukosewerte, Namen oder Zugangsdaten in Logs, Fehlermeldungen an den Client oder Analytics.
- Gespeicherte Verlaufsdaten liegen nicht in einem öffentlich ausgelieferten Ordner und nicht im Git-Repo (`.gitignore`).
- Die Datenschutzseite des Projekts (`programm-privacy`) sollte nicht stillschweigend für dieses Feature gelten. Wenn nötig, einen Hinweis ergänzen und Dominik fragen.
- **Kein Medizinprodukt:** deutlicher Hinweis im UI, dass die Anzeige nicht für Therapieentscheidungen gedacht ist. Die LibreLinkUp-Schnittstelle ist inoffiziell und kann sich jederzeit ändern. Alarme dürfen nicht als einziger Schutz vor Unterzuckerung beworben werden.

## 6. Abnahmekriterien

- [ ] Feature ist in Stingl OS erreichbar und nur nach Login sichtbar
- [ ] Aktueller Wert, Trendpfeil, Alter der Messung korrekt (Gegenprobe mit der LibreLinkUp-App)
- [ ] Graph für alle Zeiträume, nach einigen Tagen Laufzeit auch 7 und 14 Tage
- [ ] Statistik (Ø, GMI, CV, TIR) plausibel
- [ ] Nach Neustart des Servers bleibt der gespeicherte Verlauf erhalten
- [ ] Polling höchstens alle 60 s, kein erneuter Login bei jeder Abfrage
- [ ] Zugangsdaten nicht im Repo, nicht im Frontend, nicht in Logs
- [ ] Mobil nutzbar, Hell/Dunkel passend zu Stingl OS

## 7. Rückfragen an Dominik, falls nach der Analyse etwas offen ist

- Soll Karina später einen eigenen Zugang zu ihrem Dashboard bekommen?
- Sollen Benachrichtigungen (Browser/Push) bei hoch/tief aktiv sein, und mit welchen Grenzwerten (Standard: 70/180 mg/dL aus Karinas Zielbereich in der Libre-App)?
- Wo soll der Verlauf dauerhaft liegen, wenn Stingl OS bisher keine Datenbank hat?
