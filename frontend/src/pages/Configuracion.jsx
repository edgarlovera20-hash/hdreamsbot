import { useState } from 'react';
import { Settings, Copy, Check, ExternalLink, Smartphone, Globe, Key } from 'lucide-react';

const BASE = 'https://bot.heavenlydreams.com.mx';

function CopyField({ label, value }) {
  const [copied, setCopied] = useState(false);

  const copy = () => {
    navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  return (
    <div className="space-y-1">
      <p className="text-2xs font-semibold tracking-widest text-textMuted uppercase">{label}</p>
      <div className="flex items-center gap-2 bg-bg border border-border rounded-lg px-3 py-2">
        <code className="flex-1 text-xs text-text truncate font-mono">{value}</code>
        <button
          onClick={copy}
          className="shrink-0 text-textSubtle hover:text-primary transition-colors"
          title="Copiar"
        >
          {copied ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
        </button>
      </div>
    </div>
  );
}

function Section({ icon: Icon, title, children }) {
  return (
    <div className="bg-surface border border-border rounded-2xl p-5 space-y-4">
      <div className="flex items-center gap-2.5">
        <Icon size={16} className="text-primary" />
        <h2 className="text-sm font-bold text-text">{title}</h2>
      </div>
      {children}
    </div>
  );
}

export default function Configuracion() {
  return (
    <div className="max-w-2xl space-y-5">
      <div className="flex items-center gap-3 mb-6">
        <Settings size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Configuración</h1>
      </div>

      {/* Webhooks Meta */}
      <Section icon={Globe} title="Webhooks Meta (WhatsApp Cloud API)">
        <div className="space-y-3">
          <CopyField label="WhatsApp Cloud API" value={`${BASE}/webhook-whatsapp.php`} />
          <CopyField label="Messenger / Facebook" value={`${BASE}/webhook-messenger.php`} />
          <CopyField label="Lead Ads (formularios)" value={`${BASE}/webhook-lead-ads.php`} />
        </div>
        <div className="pt-1">
          <CopyField label="Token de verificación" value="hdreams26" />
        </div>
        <p className="text-xs text-textSubtle leading-relaxed">
          Registra estas URLs en Meta for Developers → tu app → Webhooks.
          Usa el token de verificación para la validación inicial.
        </p>
      </Section>

      {/* WhatsApp Baileys */}
      <Section icon={Smartphone} title="WhatsApp via QR (Baileys)">
        <p className="text-sm text-textMuted leading-relaxed">
          Conecta un número de WhatsApp directamente por código QR, sin API de Meta.
          Ideal para números personales o empresariales sin acceso a WhatsApp Business API.
        </p>
        <a
          href="/whatsapp"
          className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline"
        >
          Ir a la pantalla de QR <ExternalLink size={13} />
        </a>
      </Section>

      {/* Credenciales API */}
      <Section icon={Key} title="API Interna">
        <CopyField label="URL base de la API" value={`${BASE}/api`} />
        <p className="text-xs text-textSubtle">
          Usa el token de sesión (Bearer) en el header Authorization para llamadas autenticadas.
        </p>
      </Section>

      {/* Info versión */}
      <div className="text-center text-xs text-textSubtle pt-2">
        HDreams Bot v1.1.0 · {new Date().getFullYear()}
      </div>
    </div>
  );
}
