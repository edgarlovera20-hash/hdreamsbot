import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Settings, Copy, Check, ExternalLink, Smartphone, Globe, Key,
  Facebook, CheckCircle2, XCircle, AlertCircle, Loader2,
  Network, Bot, Brain,
} from 'lucide-react';
import { fetchConfig, fetchMetaStatus } from '../lib/api';

function ModuleLink({ href, icon: Icon, title, description }) {
  return (
    <a href={href} className="flex items-center gap-3 p-3 rounded-xl border border-border hover:border-primary/40 hover:bg-primary/5 transition-all group">
      <Icon size={15} className="text-primary shrink-0" />
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold text-text group-hover:text-primary transition-colors">{title}</p>
        <p className="text-xs text-textSubtle truncate">{description}</p>
      </div>
      <ExternalLink size={11} className="text-textSubtle" />
    </a>
  );
}

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
        <button onClick={copy} className="shrink-0 text-textSubtle hover:text-primary transition-colors" title="Copiar">
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

function MetaSection() {
  const { data: cfg,    isLoading: cfgLoading  } = useQuery({ queryKey: ['config'],      queryFn: fetchConfig,      staleTime: 300_000 });
  const { data: status, isLoading: statusLoading } = useQuery({ queryKey: ['meta-status'], queryFn: fetchMetaStatus,  refetchInterval: 60_000 });

  const loading = cfgLoading || statusLoading;

  const oauthUrl = cfg?.meta_app_id
    ? `https://www.facebook.com/v18.0/dialog/oauth?client_id=${cfg.meta_app_id}&redirect_uri=${encodeURIComponent(cfg.meta_redirect_uri)}&scope=${cfg.meta_scopes}&response_type=code`
    : null;

  return (
    <Section icon={Facebook} title="Meta Business (Facebook / Instagram)">
      {loading && (
        <div className="flex items-center gap-2 text-sm text-textMuted">
          <Loader2 size={14} className="animate-spin" /> Verificando conexión…
        </div>
      )}

      {!loading && status?.connected && (
        <div className="flex items-start gap-3 bg-green-500/10 border border-green-500/20 rounded-xl p-4">
          <CheckCircle2 size={18} className="text-green-500 mt-0.5 shrink-0" />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-green-400">Conectado como {status.user_name}</p>
            <p className="text-xs text-textMuted mt-0.5">
              Token válido por {status.dias_restantes} días más (vence {new Date(status.expires_at).toLocaleDateString('es-MX')})
            </p>
            {status.pages?.length > 0 && (
              <div className="mt-2 space-y-1">
                <p className="text-2xs text-textSubtle font-semibold uppercase tracking-widest">Páginas vinculadas</p>
                {status.pages.map((p) => (
                  <p key={p.id} className="text-xs text-textMuted">• {p.name} <span className="text-textSubtle">({p.category})</span></p>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {!loading && !status?.connected && (
        <div className="flex items-start gap-3 bg-amber-500/10 border border-amber-500/20 rounded-xl p-4">
          <XCircle size={18} className="text-amber-400 mt-0.5 shrink-0" />
          <div>
            <p className="text-sm font-semibold text-amber-300">Sin conexión con Meta</p>
            <p className="text-xs text-textMuted mt-0.5">Conecta tu cuenta para habilitar Facebook Ads, Messenger e Instagram.</p>
          </div>
        </div>
      )}

      {!loading && !cfg?.meta_app_id && (
        <div className="flex items-start gap-3 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
          <AlertCircle size={16} className="text-red-400 mt-0.5 shrink-0" />
          <p className="text-xs text-red-300">
            <strong>META_APP_ID</strong> no configurado en el servidor.
            Agrégalo en <code className="bg-surface px-1 rounded">backend/.env</code> y reinicia los contenedores.
          </p>
        </div>
      )}

      {!loading && oauthUrl && (
        <a
          href={oauthUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#1877f2] hover:bg-[#166fe5]
                     text-white text-sm font-semibold transition-colors"
        >
          <Facebook size={16} />
          {status?.connected ? 'Reconectar cuenta Meta' : 'Conectar con Meta'}
          <ExternalLink size={12} />
        </a>
      )}

      <div className="pt-1">
        <CopyField label="URI de redireccionamiento OAuth" value="https://bot.heavenlydreams.com.mx/auth/meta/callback" />
        <p className="text-xs text-textSubtle mt-2 leading-relaxed">
          Agrega esta URI en Meta Developers → tu App → Facebook Login → Configuración → URI de redireccionamiento OAuth válidos.
        </p>
      </div>
    </Section>
  );
}

const BASE = 'https://bot.heavenlydreams.com.mx';

export default function Configuracion() {
  return (
    <div className="max-w-2xl space-y-5">
      <div className="flex items-center gap-3 mb-6">
        <Settings size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Configuración</h1>
      </div>

      {/* Módulos de configuración */}
      <Section icon={Settings} title="Módulos">
        <div className="space-y-2">
          <ModuleLink href="/canales" icon={Network} title="Canales de comunicación"
            description="WhatsApp, Instagram, Messenger, Facebook Pages, Telegram" />
          <ModuleLink href="/bot" icon={Bot} title="Configuración del Bot"
            description="Saludo, horario, escalación, mensajes automáticos" />
          <ModuleLink href="/agentes" icon={Brain} title="Agentes de IA"
            description="Responder, Scorer, Classifier, Extractor — prompts y modelos LLM" />
        </div>
      </Section>

      {/* Meta OAuth */}
      <MetaSection />

      {/* Webhooks Meta */}
      <Section icon={Globe} title="Webhooks Meta (WhatsApp Cloud API)">
        <div className="space-y-3">
          <CopyField label="WhatsApp Cloud API"     value={`${BASE}/webhook-whatsapp.php`} />
          <CopyField label="Instagram"              value={`${BASE}/webhook-instagram.php`} />
          <CopyField label="Messenger / Facebook"    value={`${BASE}/webhook-messenger.php`} />
          <CopyField label="Lead Ads (formularios)"  value={`${BASE}/webhook-lead-ads.php`} />
        </div>
        <CopyField label="Token de verificación" value="hdreams26" />
        <p className="text-xs text-textSubtle leading-relaxed">
          Registra estas URLs en Meta for Developers → tu app → Webhooks.
        </p>
      </Section>

      {/* WhatsApp Baileys */}
      <Section icon={Smartphone} title="WhatsApp via QR (Baileys)">
        <p className="text-sm text-textMuted leading-relaxed">
          Conecta un número de WhatsApp directamente por código QR, sin necesitar API de Meta.
        </p>
        <a href="/whatsapp" className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline">
          Ir a la pantalla de QR <ExternalLink size={13} />
        </a>
      </Section>

      {/* API */}
      <Section icon={Key} title="API Interna">
        <CopyField label="URL base de la API" value={`${BASE}/api`} />
        <p className="text-xs text-textSubtle">Usa el token de sesión (Bearer) en el header Authorization.</p>
      </Section>

      <div className="text-center text-xs text-textSubtle pt-2">
        HDreams Bot v1.2.0 · {new Date().getFullYear()}
      </div>
    </div>
  );
}
