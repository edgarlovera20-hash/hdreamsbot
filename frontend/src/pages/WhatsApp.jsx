import { useState, useEffect, useRef } from 'react';
import { Smartphone, CheckCircle2, XCircle, Loader2, RefreshCw } from 'lucide-react';

export default function WhatsApp() {
  const [data,    setData]    = useState(null);   // {status, qr}
  const [loading, setLoading] = useState(false);
  const timerRef = useRef(null);

  const poll = async () => {
    try {
      const r = await fetch('/baileys/status');
      if (r.ok) setData(await r.json());
    } catch { /* ignore */ }
  };

  useEffect(() => {
    poll();
    timerRef.current = setInterval(poll, 2500);
    return () => clearInterval(timerRef.current);
  }, []);

  const disconnect = async () => {
    setLoading(true);
    try {
      await fetch('/baileys/disconnect', { method: 'POST' });
      setData({ status: 'disconnected' });
      setTimeout(poll, 1500);
    } finally {
      setLoading(false);
    }
  };

  const s = data?.status;

  return (
    <div className="max-w-md mx-auto">
      <div className="flex items-center gap-3 mb-6">
        <Smartphone size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">WhatsApp via QR</h1>
      </div>

      <div className="bg-surface border border-border rounded-2xl p-8 text-center shadow-sm">

        {/* Loading skeleton */}
        {!data && (
          <div className="flex flex-col items-center gap-4 py-8">
            <Loader2 size={32} className="text-textMuted animate-spin" />
            <p className="text-sm text-textMuted">Conectando con el servicio…</p>
          </div>
        )}

        {/* QR code */}
        {s === 'qr' && data?.qr && (
          <div className="flex flex-col items-center gap-5">
            <p className="text-sm font-medium text-textMuted uppercase tracking-widest">Escanea con WhatsApp</p>
            <div className="rounded-xl overflow-hidden border-4 border-white shadow-md inline-block">
              <img src={data.qr} alt="QR WhatsApp" className="w-56 h-56" />
            </div>
            <p className="text-xs text-textSubtle leading-relaxed max-w-xs">
              Abre WhatsApp → Dispositivos vinculados → Vincular un dispositivo → escanea el código.
            </p>
            <div className="flex items-center gap-2 text-xs text-amber-400">
              <Loader2 size={13} className="animate-spin" />
              Esperando escaneo…
            </div>
          </div>
        )}

        {/* Connected */}
        {s === 'connected' && (
          <div className="flex flex-col items-center gap-5 py-4">
            <CheckCircle2 size={52} className="text-green-500" />
            <div>
              <p className="text-lg font-bold text-text">WhatsApp Conectado</p>
              <p className="text-sm text-textMuted mt-1">El bot está recibiendo y respondiendo mensajes.</p>
            </div>
            <button
              onClick={disconnect}
              disabled={loading}
              className="mt-2 px-4 py-2 text-xs font-semibold rounded-lg border border-danger text-danger
                         hover:bg-danger hover:text-white transition-colors disabled:opacity-50"
            >
              {loading ? 'Desconectando…' : 'Desconectar número'}
            </button>
          </div>
        )}

        {/* Disconnected */}
        {s === 'disconnected' && (
          <div className="flex flex-col items-center gap-4 py-6">
            <XCircle size={44} className="text-textSubtle" />
            <div>
              <p className="text-base font-semibold text-text">Sin conexión</p>
              <p className="text-sm text-textMuted mt-1">El servicio está iniciando, el código QR aparecerá en segundos.</p>
            </div>
            <div className="flex items-center gap-2 text-xs text-textSubtle">
              <Loader2 size={13} className="animate-spin" />
              Reconectando automáticamente…
            </div>
          </div>
        )}

        {/* Error */}
        {s === 'error' && (
          <div className="flex flex-col items-center gap-4 py-6">
            <XCircle size={44} className="text-danger" />
            <p className="text-sm text-textMuted">No se puede contactar el servicio Baileys.</p>
            <button onClick={poll} className="flex items-center gap-2 text-xs text-primary hover:underline">
              <RefreshCw size={13} /> Reintentar
            </button>
          </div>
        )}

      </div>

      {/* Info footer */}
      <p className="text-xs text-textSubtle text-center mt-5 leading-relaxed">
        Este método conecta un número de WhatsApp personal o empresa directamente via QR,
        sin necesidad de la API oficial de Meta.
      </p>
    </div>
  );
}
