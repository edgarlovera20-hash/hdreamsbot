'use strict';

const {
  makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  Browsers,
} = require('@whiskeysockets/baileys');
const { Boom }  = require('@hapi/boom');
const express   = require('express');
const qrcode    = require('qrcode');
const axios     = require('axios');
const pino      = require('pino');

const app    = express();
const PORT   = 4000;
const API_URL = process.env.PHP_API_URL   || 'http://nginx:8080';
const SECRET  = process.env.BAILEYS_SECRET || '';
const logger     = pino({ level: 'warn' });

app.use(express.json());

let status   = 'disconnected'; // 'disconnected' | 'qr' | 'connected'
let qrImage  = null;           // base64 PNG data URL
let sock     = null;
let reconnectTimer = null;

async function connect() {
  clearTimeout(reconnectTimer);
  const { state, saveCreds } = await useMultiFileAuthState('/app/auth');

  let version;
  try {
    const res = await fetchLatestBaileysVersion();
    version = res.version;
  } catch {
    version = [2, 3000, 1023456789];
  }

  sock = makeWASocket({
    version,
    auth: state,
    logger,
    browser: Browsers.macOS('Chrome'),
    connectTimeoutMs: 30_000,
    defaultQueryTimeoutMs: 15_000,
    keepAliveIntervalMs: 25_000,
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      status  = 'qr';
      qrImage = await qrcode.toDataURL(qr).catch(() => null);
      console.log('[baileys] QR generado');
    }

    if (connection === 'close') {
      const code   = (lastDisconnect?.error instanceof Boom)
        ? lastDisconnect.error.output.statusCode
        : null;
      const logout = code === DisconnectReason.loggedOut;
      status  = 'disconnected';
      qrImage = null;
      console.log('[baileys] Desconectado, código:', code, logout ? '(logout)' : '(reconectando)');
      if (!logout) reconnectTimer = setTimeout(connect, 5000);
    } else if (connection === 'open') {
      status  = 'connected';
      qrImage = null;
      console.log('[baileys] Conectado a WhatsApp');
    }
  });

  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    if (type !== 'notify') return;
    for (const msg of messages) {
      if (msg.key.fromMe || !msg.message) continue;

      const jid  = msg.key.remoteJid || '';
      if (!jid.endsWith('@s.whatsapp.net')) continue; // skip groups

      const phone = jid.replace('@s.whatsapp.net', '');
      const text  = msg.message?.conversation
        || msg.message?.extendedTextMessage?.text
        || msg.message?.imageMessage?.caption
        || '';

      if (!text) continue;

      try {
        await axios.post(`${API_URL}/webhook-baileys.php`, {
          phone,
          text,
          message_id: msg.key.id,
          timestamp:  msg.messageTimestamp,
        }, {
          headers: { 'X-Baileys-Secret': SECRET },
          timeout: 15_000,
        });
      } catch (e) {
        console.error('[baileys] Error reenviando mensaje:', e.message);
      }
    }
  });
}

// ── HTTP API ────────────────────────────────────────────

app.get('/status', (_req, res) => {
  res.json({ status, qr: qrImage });
});

app.post('/send', async (req, res) => {
  const { phone, text } = req.body ?? {};
  if (!phone || !text)                  return res.status(400).json({ error: 'phone y text requeridos' });
  if (!sock || status !== 'connected')  return res.status(503).json({ error: 'No conectado' });
  try {
    await sock.sendMessage(`${phone}@s.whatsapp.net`, { text });
    res.json({ ok: true });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// Meta OAuth callback — intercambia code → token largo → guarda en DB
app.get('/auth/meta/callback', async (req, res) => {
  const { code, error, error_description } = req.query;
  console.log('[meta-oauth] callback:', { code: !!code, error });

  if (error) {
    return res.status(400).send(
      `<html><body style="font-family:sans-serif;text-align:center;padding:60px">
       <h1 style="color:#ef4444">Error de autorización</h1>
       <p>${error_description ?? error}</p></body></html>`
    );
  }

  // Prueba de ruta sin code (verificación de URL desde Meta)
  if (!code) return res.send('Meta OAuth OK');

  const APP_ID     = process.env.META_APP_ID     || '';
  const APP_SECRET = process.env.META_APP_SECRET  || '';
  const REDIRECT   = process.env.META_REDIRECT_URI || 'https://bot.heavenlydreams.com.mx/auth/meta/callback';

  if (!APP_ID || !APP_SECRET) {
    return res.status(500).send('<h1>Error: META_APP_ID o META_APP_SECRET no configurados</h1>');
  }

  try {
    // 1. Code → token de corta duración
    const shortRes = await axios.get('https://graph.facebook.com/v18.0/oauth/access_token', {
      params: { client_id: APP_ID, client_secret: APP_SECRET, redirect_uri: REDIRECT, code },
    });
    const shortToken = shortRes.data.access_token;

    // 2. Token corto → token largo (60 días)
    const longRes = await axios.get('https://graph.facebook.com/v18.0/oauth/access_token', {
      params: {
        grant_type:       'fb_exchange_token',
        client_id:        APP_ID,
        client_secret:    APP_SECRET,
        fb_exchange_token: shortToken,
      },
    });
    const longToken = longRes.data.access_token;
    const expiresIn = longRes.data.expires_in ?? 5184000;

    // 3. Info del usuario + páginas administradas
    const [userRes, pagesRes] = await Promise.all([
      axios.get('https://graph.facebook.com/me', {
        params: { access_token: longToken, fields: 'id,name,email' },
      }),
      axios.get('https://graph.facebook.com/me/accounts', {
        params: { access_token: longToken, fields: 'id,name,access_token,category' },
      }),
    ]);

    const pages = pagesRes.data.data ?? [];

    // 4. Guardar en DB via PHP (puerto interno nginx:8080)
    await axios.post(`${API_URL}/webhook-meta-token.php`, {
      user_id:      userRes.data.id,
      user_name:    userRes.data.name,
      access_token: longToken,
      expires_in:   expiresIn,
      pages,
    }, {
      headers: { 'X-Baileys-Secret': SECRET },
      timeout: 15_000,
    });

    console.log(`[meta-oauth] ✓ Token guardado para ${userRes.data.name} (${pages.length} páginas)`);

    res.send(`
      <html>
      <body style="font-family:sans-serif;text-align:center;padding:60px;background:#0f172a;color:#e2e8f0">
        <h1 style="color:#22c55e;font-size:2em">✅ Meta conectado</h1>
        <p style="font-size:1.1em">Conectado como <strong>${userRes.data.name}</strong></p>
        ${pages.length ? `<p>${pages.length} página(s) encontrada(s): ${pages.map(p => p.name).join(', ')}</p>` : ''}
        <p style="color:#64748b;margin-top:32px">Puedes cerrar esta ventana y volver a la app.</p>
      </body>
      </html>
    `);
  } catch (e) {
    const err = e.response?.data ?? { message: e.message };
    console.error('[meta-oauth] Error:', err);
    res.status(500).send(
      `<html><body style="font-family:sans-serif;padding:40px;background:#0f172a;color:#e2e8f0">
       <h1 style="color:#ef4444">Error al conectar Meta</h1>
       <pre style="background:#1e293b;padding:16px;border-radius:8px">${JSON.stringify(err, null, 2)}</pre>
       </body></html>`
    );
  }
});

app.post('/disconnect', async (_req, res) => {
  clearTimeout(reconnectTimer);
  try { await sock?.logout(); } catch { /* ignorar */ }
  status  = 'disconnected';
  qrImage = null;
  sock    = null;
  // Eliminar auth para forzar nuevo QR
  const { rm } = require('fs/promises');
  await rm('/app/auth', { recursive: true, force: true });
  res.json({ ok: true });
  // Reconectar para generar nuevo QR
  setTimeout(connect, 1000);
});

app.listen(PORT, () => {
  console.log(`[baileys] API en :${PORT}`);
  connect();
});
