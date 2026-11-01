// Libre-Dashboard: holt Glukosewerte per LibreLinkUp (Follower-Freigabe) und liefert sie an ein lokales Dashboard.
// Keine Abhängigkeiten, benötigt Node >= 18.
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// --- .env laden (ohne Abhängigkeit) ---
try {
  for (const line of fs.readFileSync(path.join(__dirname, '.env'), 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
} catch { /* keine .env */ }

const env = process.env;
const CFG = {
  email: env.LLU_EMAIL, password: env.LLU_PASSWORD,
  region: (env.LLU_REGION || '').toLowerCase(),
  patientId: env.LLU_PATIENT_ID || '',
  unit: env.UNIT === 'mmol' ? 'mmol' : 'mgdl',
  poll: Math.max(30, Number(env.POLL_SECONDS) || 60) * 1000,
  host: env.HOST || '127.0.0.1', port: Number(env.PORT) || 3000,
  user: env.DASH_USER, pass: env.DASH_PASS,
  demo: env.DEMO === '1',
};
if (!CFG.demo && (!CFG.email || !CFG.password)) {
  console.error('LLU_EMAIL und LLU_PASSWORD fehlen (siehe .env.example). Zum Testen: DEMO=1 node server.js');
  process.exit(1);
}

const DATA_DIR = path.join(__dirname, 'data');
const HISTORY_FILE = path.join(DATA_DIR, 'history.json');
const KEEP_MS = 14 * 24 * 3600 * 1000;

// --- Verlauf (lokal gespeichert, damit mehr als die ~12h der API zusammenkommen) ---
let history = new Map(); // t(ms) -> mg/dL
if (!CFG.demo) {
  try { for (const [t, v] of JSON.parse(fs.readFileSync(HISTORY_FILE, 'utf8'))) history.set(t, v); } catch { /* neu */ }
}
function saveHistory() {
  if (CFG.demo) return;
  const cut = Date.now() - KEEP_MS;
  for (const t of history.keys()) if (t < cut) history.delete(t);
  fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(HISTORY_FILE, JSON.stringify([...history]));
}

// --- LibreLinkUp-Client ---
const REGION_HOSTS = {
  '': 'api.libreview.io', global: 'api.libreview.io',
  de: 'api-de.libreview.io', eu: 'api-eu.libreview.io', eu2: 'api-eu2.libreview.io',
  us: 'api-us.libreview.io', ae: 'api-ae.libreview.io', ap: 'api-ap.libreview.io',
  au: 'api-au.libreview.io', ca: 'api-ca.libreview.io', jp: 'api-jp.libreview.io',
  fr: 'api-fr.libreview.io', la: 'api-la.libreview.io', ru: 'api.libreview.ru',
};
const BASE_HEADERS = {
  'content-type': 'application/json', 'cache-control': 'no-cache',
  product: 'llu.android', version: '4.16.0',
};
let host = REGION_HOSTS[CFG.region] || REGION_HOSTS[''];
let session = null; // { token, accountId }

async function llu(method, p, body, auth = true) {
  const headers = { ...BASE_HEADERS };
  if (auth && session) { headers.authorization = `Bearer ${session.token}`; headers['account-id'] = session.accountId; }
  const res = await fetch(`https://${host}${p}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  if (res.status === 429 || res.status === 430) throw Object.assign(new Error('LibreLinkUp: zu viele Anfragen (429), warte bis zum nächsten Zyklus'), { rateLimited: true });
  if (res.status === 401) { session = null; throw new Error('LibreLinkUp: Sitzung abgelaufen (401)'); }
  if (!res.ok) throw new Error(`LibreLinkUp: HTTP ${res.status}`);
  return res.json();
}

async function login() {
  for (let hop = 0; hop < 3; hop++) {
    const r = await llu('POST', '/llu/auth/login', { email: CFG.email, password: CFG.password }, false);
    if (r.status === 2) throw new Error('LibreLinkUp: E-Mail oder Passwort falsch');
    if (r.data?.redirect && r.data.region) { host = REGION_HOSTS[r.data.region.toLowerCase()] || `api-${r.data.region}.libreview.io`; continue; }
    const step = r.data?.step?.type;
    if (step && !r.data?.authTicket) throw new Error(`LibreLinkUp verlangt noch einen Schritt (${step}). Öffne die offizielle LibreLinkUp-App, melde dich an und akzeptiere die Bedingungen.`);
    const token = r.data?.authTicket?.token;
    if (!token) throw new Error('LibreLinkUp: Login ohne Token, unerwartete Antwort');
    session = { token, accountId: crypto.createHash('sha256').update(r.data.user.id).digest('hex') };
    return;
  }
  throw new Error('LibreLinkUp: zu viele Region-Weiterleitungen');
}

async function listPatients() {
  if (!session) await login();
  return (await llu('GET', '/llu/connections')).data || [];
}

// "11/1/2026 9:05:00 AM" (UTC, FactoryTimestamp) -> ms
function parseFactory(s) {
  const m = String(s).match(/^(\d+)\/(\d+)\/(\d+) (\d+):(\d+):(\d+)(?: (AM|PM))?$/);
  if (!m) return Date.parse(s);
  let [, mo, d, y, h, mi, se, ap] = m; h = +h;
  if (ap === 'PM' && h < 12) h += 12; if (ap === 'AM' && h === 12) h = 0;
  return Date.UTC(+y, mo - 1, +d, h, +mi, +se);
}

async function fetchLive() {
  if (!session) await login();
  const patients = (await llu('GET', '/llu/connections')).data || [];
  if (!patients.length) throw new Error('Keine verbundene Person gefunden. Wurde die LibreLinkUp-Einladung angenommen?');
  const p = patients.find(x => x.patientId === CFG.patientId) || patients[0];
  const g = (await llu('GET', `/llu/connections/${p.patientId}/graph`)).data;
  const cur = g.connection?.glucoseMeasurement || p.glucoseMeasurement;
  const points = [...(g.graphData || []), cur].filter(Boolean);
  for (const x of points) {
    const t = parseFactory(x.FactoryTimestamp || x.Timestamp);
    if (Number.isFinite(t) && x.ValueInMgPerDl > 0) history.set(t, x.ValueInMgPerDl);
  }
  saveHistory();
  return {
    patient: [p.firstName, p.lastName?.[0] && p.lastName[0] + '.'].filter(Boolean).join(' '),
    current: cur && {
      mgdl: cur.ValueInMgPerDl, trend: cur.TrendArrow, isHigh: !!cur.isHigh, isLow: !!cur.isLow,
      t: parseFactory(cur.FactoryTimestamp || cur.Timestamp),
    },
    targets: { low: p.targetLow || 70, high: p.targetHigh || 180 },
  };
}

// --- Demo ---
function fetchDemo() {
  const now = Math.floor(Date.now() / 60000) * 60000;
  history.clear();
  for (let i = 7 * 24 * 4; i >= 0; i--) {
    const t = now - i * 15 * 60000, h = (t / 3600000) % 24;
    const v = 115 + 35 * Math.sin(t / 5.5e6) + 30 * Math.sin(t / 1.9e6) + 12 * Math.sin(h / 24 * 6.28 * 3);
    history.set(t, Math.round(Math.max(48, Math.min(290, v))));
  }
  const arr = [...history.entries()], last = arr.at(-1)[1], prev = arr.at(-2)[1], d = last - prev;
  return {
    patient: 'Demo',
    current: { mgdl: last, trend: d > 8 ? 5 : d > 3 ? 4 : d < -8 ? 1 : d < -3 ? 2 : 3, isHigh: last > 180, isLow: last < 70, t: arr.at(-1)[0] },
    targets: { low: 70, high: 180 },
  };
}

// --- Polling ---
let state = { ok: false, error: 'noch nicht abgefragt', updated: 0 };
let meta = null;
async function poll() {
  try {
    meta = CFG.demo ? fetchDemo() : await fetchLive();
    state = { ok: true, updated: Date.now() };
  } catch (e) {
    state = { ok: false, error: e.message, updated: state.updated };
    console.error(new Date().toISOString(), e.message);
  }
}
poll();
setInterval(poll, CFG.poll);

// --- HTTP ---
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' };
function authorized(req) {
  if (!CFG.user && !CFG.pass) return true;
  const m = (req.headers.authorization || '').match(/^Basic (.+)$/);
  if (!m) return false;
  const [u, ...pw] = Buffer.from(m[1], 'base64').toString().split(':');
  const eq = (a, b) => a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));
  return eq(u, CFG.user || '') && eq(pw.join(':'), CFG.pass || '');
}

http.createServer(async (req, res) => {
  if (!authorized(req)) { res.writeHead(401, { 'www-authenticate': 'Basic realm="Libre-Dashboard"' }); return res.end('Login erforderlich'); }
  const url = new URL(req.url, 'http://x');
  const send = (code, obj) => { res.writeHead(code, { 'content-type': 'application/json', 'cache-control': 'no-store' }); res.end(JSON.stringify(obj)); };

  if (url.pathname === '/api/glucose') {
    const sinceH = Math.min(14 * 24, Number(url.searchParams.get('hours')) || 24);
    const cut = Date.now() - sinceH * 3600000;
    const readings = [...history].filter(([t]) => t >= cut).sort((a, b) => a[0] - b[0]);
    return send(200, { ...state, unit: CFG.unit, demo: CFG.demo, pollSeconds: CFG.poll / 1000, ...(meta || {}), readings });
  }
  if (url.pathname === '/api/patients' && !CFG.demo) {
    try { return send(200, (await listPatients()).map(({ patientId, firstName, lastName }) => ({ patientId, firstName, lastName }))); }
    catch (e) { return send(502, { error: e.message }); }
  }
  const file = path.normalize(path.join(__dirname, 'public', url.pathname === '/' ? 'index.html' : url.pathname));
  if (!file.startsWith(path.join(__dirname, 'public') + path.sep)) { res.writeHead(403); return res.end(); }
  fs.readFile(file, (err, buf) => {
    if (err) { res.writeHead(404); return res.end('Nicht gefunden'); }
    res.writeHead(200, { 'content-type': MIME[path.extname(file)] || 'application/octet-stream' });
    res.end(buf);
  });
}).listen(CFG.port, CFG.host, () => console.log(`Libre-Dashboard: http://${CFG.host}:${CFG.port}${CFG.demo ? ' (DEMO)' : ''}`));
