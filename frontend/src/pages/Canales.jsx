import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Network, Phone, Instagram, MessageSquare, Facebook, Send, Trash2,
  CheckCircle, XCircle, Loader2, Copy, Check, Plus, Zap,
} from 'lucide-react';
import { fetchCanales, saveCanal, deleteCanal, testCanal } from '../lib/api';

const BASE_URL = 'https://bot.heavenlydreams.com.mx';

const CHANNEL_META = {
  whatsapp:  { label: 'WhatsApp Cloud API', icon: Phone,        color: 'text-green-500',  webhook: `${BASE_URL}/webhook-whatsapp.php`,  envNote: 'Phone ID y Token en .env (WA_PHONE_ID, WA_TOKEN)' },
  instagram: { label: 'Instagram',           icon: Instagram,    color: 'text-pink-500',   webhook: `${BASE_URL}/webhook-instagram.php`,  envNote: null },
  messenger: { label: 'Messenger',           icon: MessageSquare,color: 'text-blue-500',   webhook: `${BASE_URL}/webhook-messenger.php`,  envNote: null },
  facebook:  { label: 'Facebook / Lead Ads', icon: Facebook,     color: 'text-blue-700',   webhook: `${BASE_URL}/webhook-lead-ads.php`,   envNote: null },
  telegram:  { label: 'Telegram',            icon: Send,         color: 'text-sky-500',    webhook: null,                                  envNote: 'Token en .env (TELEGRAM_BOT_TOKEN)' },
};

const CANAL_ORDER = ['whatsapp', 'instagram', 'messenger', 'facebook', 'telegram'];

function CopyBtn({ value }) {
  const [copied, setCopied] = useState(false);
  return (
    <button
      onClick={() => { navigator.clipboard.writeText(value); setCopied(true); setTimeout(() => setCopied(false), 1800); }}
      className="shrink-0 text-textSubtle hover:text-primary transition-colors"
      title="Copiar"
    >
      {copied ? <Check size={13} className="text-green-500" /> : <Copy size={13} />}
    </button>
  );
}

function Field({ label, value, onChange, type = 'text', placeholder }) {
  return (
    <div className="space-y-1">
      <p className="text-2xs font-semibold tracking-widest text-textMuted uppercase">{label}</p>
      <input
        type={type}
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary"
      />
    </div>
  );
}

function ChannelCard({ meta, canal, onSave, onDelete, onTest }) {
  const Icon = meta.icon;
  const isActive = canal?.activo == 1;
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({
    canal:        canal?.canal        || '',
    page_id:      canal?.page_id      || '',
    access_token: '',
    config:       canal?.config       || {},
    activo:       canal?.activo ?? 1,
    seccion_id:   canal?.seccion_id   ?? 1,
  });
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);

  const set = k => v => setForm(f => ({ ...f, [k]: v }));

  const handleTest = async () => {
    if (!canal?.id) return;
    setTesting(true);
    setTestResult(null);
    try {
      const r = await onTest(canal.id);
      setTestResult(r);
    } catch {
      setTestResult({ ok: false, message: 'Error al conectar' });
    } finally {
      setTesting(false);
    }
  };

  return (
    <div className="bg-surface border border-border rounded-2xl overflow-hidden">
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-3 px-5 py-4 hover:bg-surfaceHover transition-colors"
      >
        <Icon size={18} className={meta.color} />
        <span className="flex-1 text-sm font-semibold text-text text-left">{meta.label}</span>
        {canal ? (
          isActive
            ? <CheckCircle size={15} className="text-green-500" />
            : <XCircle size={15} className="text-textSubtle" />
        ) : (
          <span className="text-2xs text-textSubtle">Sin configurar</span>
        )}
        <span className="text-textSubtle text-xs">{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div className="px-5 pb-5 space-y-4 border-t border-border pt-4">
          {/* Webhook URL */}
          {meta.webhook && (
            <div className="space-y-1">
              <p className="text-2xs font-semibold tracking-widest text-textMuted uppercase">URL Webhook (registra en Meta)</p>
              <div className="flex items-center gap-2 bg-bg border border-border rounded-lg px-3 py-2">
                <code className="flex-1 text-xs text-text truncate font-mono">{meta.webhook}</code>
                <CopyBtn value={meta.webhook} />
              </div>
            </div>
          )}

          {meta.envNote && (
            <p className="text-xs text-textSubtle bg-primary/5 border border-primary/20 rounded-lg px-3 py-2">
              {meta.envNote}
            </p>
          )}

          {/* Config fields */}
          {meta.label !== 'Telegram' && (
            <Field label="Page / Account ID" value={form.page_id} onChange={set('page_id')} placeholder="123456789" />
          )}
          {!meta.envNote && (
            <Field label="Access Token" value={form.access_token} onChange={set('access_token')}
              type="password" placeholder="Pega el token de la página" />
          )}
          <div className="space-y-1">
            <p className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Activo</p>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={form.activo == 1}
                onChange={e => set('activo')(e.target.checked ? 1 : 0)}
                className="w-4 h-4 rounded border-border text-primary" />
              <span className="text-sm text-textMuted">Habilitado</span>
            </label>
          </div>

          <div className="flex gap-2 pt-1">
            <button
              onClick={() => onSave({ ...form, canal: canal?.canal || '' })}
              className="flex-1 bg-primary text-white text-sm font-semibold py-2 rounded-lg hover:opacity-90 transition-opacity"
            >
              Guardar
            </button>
            {canal?.id && (
              <>
                <button
                  onClick={handleTest}
                  disabled={testing}
                  className="px-3 py-2 border border-border rounded-lg text-sm text-textMuted hover:text-text hover:border-primary transition-colors flex items-center gap-1"
                >
                  {testing ? <Loader2 size={13} className="animate-spin" /> : <Zap size={13} />}
                  Test
                </button>
                <button
                  onClick={() => onDelete(canal.id)}
                  className="px-3 py-2 border border-border rounded-lg text-sm text-danger hover:bg-danger/10 transition-colors"
                >
                  <Trash2 size={13} />
                </button>
              </>
            )}
          </div>

          {testResult && (
            <p className={`text-xs rounded-lg px-3 py-2 ${testResult.ok ? 'bg-green-500/10 text-green-600' : 'bg-danger/10 text-danger'}`}>
              {testResult.ok ? '✓' : '✗'} {testResult.message}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

export default function Canales() {
  const qc = useQueryClient();
  const { data: canales = [], isLoading } = useQuery({ queryKey: ['canales'], queryFn: fetchCanales });

  const saveMut = useMutation({
    mutationFn: saveCanal,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['canales'] }),
  });
  const deleteMut = useMutation({
    mutationFn: deleteCanal,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['canales'] }),
  });

  const byCanal = tipo => canales.find(c => c.canal === tipo);

  const handleSave = (tipo, data) => saveMut.mutate({ ...data, canal: tipo });

  return (
    <div className="max-w-2xl space-y-5">
      <div className="flex items-center gap-3 mb-6">
        <Network size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Canales de Comunicación</h1>
      </div>

      <p className="text-sm text-textMuted">
        Configura cada canal y registra los webhooks en Meta for Developers → tu app → Webhooks.
        Token de verificación: <code className="text-primary font-mono">hdreams26</code>
      </p>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Loader2 size={24} className="animate-spin text-primary" />
        </div>
      ) : (
        <div className="space-y-3">
          {CANAL_ORDER.map(tipo => (
            <ChannelCard
              key={tipo}
              meta={CHANNEL_META[tipo]}
              canal={byCanal(tipo)}
              onSave={data => handleSave(tipo, data)}
              onDelete={id => deleteMut.mutate(id)}
              onTest={testCanal}
            />
          ))}
        </div>
      )}
    </div>
  );
}
