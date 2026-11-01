# Libre Dashboard

Lokales Dashboard für FreeStyle Libre 3 über **LibreLinkUp** (Follower-Freigabe). Keine Abhängigkeiten, Node ≥ 18.

## Einrichtung
1. Deine Freundin lädt dich in der Libre-App (Verbundene Apps > LibreLinkUp) per E-Mail ein.
2. Du installierst die **LibreLinkUp**-App, legst einen eigenen Account an, nimmst die Einladung an und akzeptierst einmal die Bedingungen.
3. `cp .env.example .env` und `LLU_EMAIL`, `LLU_PASSWORD`, `LLU_REGION` eintragen.
4. `node server.js` und `http://127.0.0.1:3000` öffnen.

Test ohne Zugang: `DEMO=1 node server.js`.

## Hinweise
- Inoffizielle Schnittstelle, kann sich jederzeit ändern. Kein Medizinprodukt, nicht für Therapieentscheidungen verwenden.
- Werte werden lokal in `data/history.json` gesammelt (die API liefert nur ca. 12 h). `.env` und `data/` sind per `.gitignore` ausgeschlossen.
- Mehrere verbundene Personen: `/api/patients` aufrufen und `LLU_PATIENT_ID` setzen.
- Zugriff von außen nur mit `DASH_USER`/`DASH_PASS` und besser hinter HTTPS (z. B. Tailscale oder Reverse-Proxy).
- Gesundheitsdaten deiner Freundin: Sie stimmt per Einladung zu und kann die Freigabe jederzeit widerrufen.
