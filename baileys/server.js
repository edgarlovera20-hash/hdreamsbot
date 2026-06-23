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
const API_URL    = process.env.PHP_API_URL  || 'http://nginx:8080';
const SECRET     = process.env.BAILEYS_SECRET || '';
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

// Meta OAuth callback
app.get('/auth/meta/callback', (req, res) => {
  console.log('[meta-oauth] callback recibido:', req.query);
  if (req.query.error) {
    return res.status(400).send(`<h1>Error OAuth</h1><pre>${req.query.error_description ?? req.query.error}</pre>`);
  }
  // Si es prueba de ruta (sin code)
  if (!req.query.code) {
    return res.send('Meta OAuth OK');
  }
  // TODO: intercambiar code por access token y guardarlo en DB
  res.send(`<h1>Conexión Meta completada</h1><pre>${JSON.stringify(req.query, null, 2)}</pre>`);
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
