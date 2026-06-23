import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, Inbox, KeyRound, Link2, MessageCircleMore, PlugZap, RefreshCcw, ShieldCheck, Sparkles } from 'lucide-react';
import { applyAccountsInboxMacro, assignAccountsInbox, autoAssignLeadCopilot, fetchAccountsInbox, fetchAccountsInboxThread, fetchAccountsPanel, fetchLeadCopilot, fetchRecruiters, replyAccountsInbox, upsertAccountApp } from '../lib/api';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { useSession } from '../context/SessionContext';
import { useProductionSimulator } from '../context/ProductionSimulatorContext';

const APP_OPTIONS = ['whatsapp', 'messenger', 'instagram', 'facebook', 'telegram', 'gmail', 'outlook', 'teams'];
const BUCKETS = [
  { key: 'pending', label: 'Pendiente' },
  { key: 'attended', label: 'Atendido' },
  { key: 'sla_overdue', label: 'SLA vencido' },
];

function createEmptyForm(companyId) {
  return {
    empresa_id: companyId || 1,
    seccion_id: 1,
    canal: 'whatsapp',
    nombre_cuenta: '',
    inbox_alias: '',
    page_id: '',
    status: 'connected',
    activo: true,
    access_token: '',
    phone_number: '',
    phone_number_id: '',
    verify_token: '',
    app_secret: '',
    telegram_bot_token: '',
    google_client_id: '',
    google_client_secret: '',
    google_refresh_token: '',
    google_user_email: '',
    ms_tenant_id: '',
    ms_client_id: '',
    ms_client_secret: '',
    ms_refresh_token: '',
    ms_user_email: '',
    teams_chat_id: '',
    teams_team_id: '',
    teams_channel_id: '',
    notes: '',
  };
}

function formatInterview(date, time) {
  if (!date || !time) return null;
  return new Date(`${date}T${time}`).toLocaleString('es-MX', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function MetaTag({ children, tone = 'default' }) {
  const tones = {
    default: 'bg-surfaceHover text-text border-border',
    accent: 'bg-primary/10 text-primary border-primary/20',
    success: 'bg-success/10 text-success border-success/20',
    warning: 'bg-warning/10 text-warning border-warning/20',
  };
  return (
    <span className={`px-2 py-0.5 rounded-full text-[11px] border ${tones[tone] ?? tones.default}`}>
      {children}
    </span>
  );
}

function interviewStatusTone(status) {
  if (status === 'confirmada' || status === 'realizada') return 'success';
  if (status === 'reagendada') return 'warning';
  if (status === 'no_show') return 'warning';
  return 'default';
}

function statusPill(status) {
  if (status === 'connected') return 'bg-success/10 text-success border border-success/20';
  if (status === 'warning') return 'bg-warning/10 text-warning border border-warning/20';
  return 'bg-danger/10 text-danger border border-danger/20';
}

function getExternalIdLabel(canal) {
  if (canal === 'messenger' || canal === 'facebook') return 'Page ID';
  if (canal === 'instagram') return 'IG Account ID / Page ID';
  if (canal === 'telegram') return 'Chat ID / alias';
  if (canal === 'gmail' || canal === 'outlook') return 'Email destino por defecto';
  if (canal === 'teams') return 'Chat ID o canal Teams';
  return 'ID externo / pagina';
}

function getExternalIdPlaceholder(canal) {
  if (canal === 'messenger' || canal === 'facebook') return 'Ej. 123456789012345';
  if (canal === 'instagram') return 'Ej. 17841400000000000';
  if (canal === 'telegram') return 'Ej. @bot_reclutamiento o chat id';
  if (canal === 'gmail' || canal === 'outlook') return 'Ej. candidato@correo.com';
  if (canal === 'teams') return 'Chat ID, o usa Team ID + Channel ID abajo';
  return 'Numero, page ID o identificador';
}

function getCredentialFields(canal) {
  if (canal === 'whatsapp') {
    return [
      { key: 'phone_number', label: 'Numero visible de WhatsApp', placeholder: 'Ej. 5215512345678' },
      { key: 'phone_number_id', label: 'Phone Number ID', placeholder: 'ID numerico de Meta' },
      { key: 'access_token', label: 'Access Token', placeholder: 'Token permanente o temporal', secret: true },
      { key: 'verify_token', label: 'Verify Token', placeholder: 'Token del webhook' },
      { key: 'app_secret', label: 'App Secret', placeholder: 'Secreto de la app Meta', secret: true },
    ];
  }

  if (canal === 'telegram') {
    return [
      { key: 'telegram_bot_token', label: 'Bot Token', placeholder: '123456:AA...', secret: true },
      { key: 'page_id', label: 'Chat ID / alias', placeholder: 'Ej. @bot_reclutamiento o 123456789' },
    ];
  }

  if (canal === 'messenger' || canal === 'instagram' || canal === 'facebook') {
    return [
      { key: 'page_id', label: canal === 'instagram' ? 'IG Account ID / Page ID' : 'Page ID', placeholder: 'Ej. 123456789012345' },
      { key: 'access_token', label: 'Page Access Token', placeholder: 'Token de la pagina', secret: true },
      { key: 'verify_token', label: 'Verify Token', placeholder: 'Token del webhook' },
      { key: 'app_secret', label: 'App Secret', placeholder: 'Secreto de la app Meta', secret: true },
    ];
  }

  if (canal === 'gmail') {
    return [
      { key: 'google_user_email', label: 'Cuenta Gmail remitente', placeholder: 'reclutamiento@empresa.com' },
      { key: 'access_token', label: 'Access Token Gmail', placeholder: 'Token OAuth temporal', secret: true },
      { key: 'google_refresh_token', label: 'Refresh Token Gmail', placeholder: 'Refresh token OAuth', secret: true },
      { key: 'google_client_id', label: 'Google Client ID', placeholder: 'OAuth Client ID' },
      { key: 'google_client_secret', label: 'Google Client Secret', placeholder: 'OAuth Client Secret', secret: true },
    ];
  }

  if (canal === 'outlook') {
    return [
      { key: 'ms_user_email', label: 'Cuenta Outlook remitente', placeholder: 'reclutamiento@empresa.com' },
      { key: 'access_token', label: 'Microsoft Graph Access Token', placeholder: 'Token OAuth temporal', secret: true },
      { key: 'ms_refresh_token', label: 'Microsoft Refresh Token', placeholder: 'Refresh token OAuth', secret: true },
      { key: 'ms_tenant_id', label: 'Tenant ID', placeholder: 'common, organizations o tenant GUID' },
      { key: 'ms_client_id', label: 'Microsoft Client ID', placeholder: 'Application client ID' },
      { key: 'ms_client_secret', label: 'Microsoft Client Secret', placeholder: 'Client secret', secret: true },
    ];
  }

  if (canal === 'teams') {
    return [
      { key: 'teams_chat_id', label: 'Teams Chat ID', placeholder: '19:...@thread.v2' },
      { key: 'teams_team_id', label: 'Team ID', placeholder: 'GUID del equipo' },
      { key: 'teams_channel_id', label: 'Channel ID', placeholder: '19:...@thread.tacv2' },
      { key: 'access_token', label: 'Microsoft Graph Access Token', placeholder: 'Token OAuth temporal', secret: true },
      { key: 'ms_refresh_token', label: 'Microsoft Refresh Token', placeholder: 'Refresh token OAuth', secret: true },
      { key: 'ms_tenant_id', label: 'Tenant ID', placeholder: 'common, organizations o tenant GUID' },
      { key: 'ms_client_id', label: 'Microsoft Client ID', placeholder: 'Application client ID' },
      { key: 'ms_client_secret', label: 'Microsoft Client Secret', placeholder: 'Client secret', secret: true },
    ];
  }

  return [];
}

function appCredentialSummary(app) {
  const credentials = app.credentials ?? {};
  const flags = [];

  if (credentials.access_token) flags.push('token');
  if (credentials.phone_number_id) flags.push('phone id');
  if (credentials.telegram_bot_token) flags.push('bot token');
  if (credentials.google_refresh_token) flags.push('google oauth');
  if (credentials.ms_refresh_token) flags.push('graph oauth');
  if (credentials.teams_chat_id || credentials.teams_channel_id) flags.push('teams target');
  if (credentials.verify_token) flags.push('verify');
  if (credentials.app_secret) flags.push('secret');

  return flags.length > 0 ? flags.join(' · ') : 'sin credenciales cargadas';
}

function normalizeAppToForm(app, activeCompanyId) {
  const credentials = app.credentials ?? {};
  return {
    ...createEmptyForm(activeCompanyId),
    empresa_id: Number(app.empresa_id) || activeCompanyId || 1,
    seccion_id: Number(app.seccion_id) || 1,
    canal: app.canal || 'whatsapp',
    nombre_cuenta: app.nombre_cuenta || '',
    inbox_alias: app.inbox_alias || '',
    page_id: app.page_id || '',
    status: app.status || 'connected',
    activo: Number(app.activo) === 1,
    access_token: credentials.access_token || '',
    phone_number: credentials.phone_number || '',
    phone_number_id: credentials.phone_number_id || '',
    verify_token: credentials.verify_token || '',
    app_secret: credentials.app_secret || '',
    telegram_bot_token: credentials.telegram_bot_token || '',
    google_client_id: credentials.google_client_id || '',
    google_client_secret: credentials.google_client_secret || '',
    google_refresh_token: credentials.google_refresh_token || '',
    google_user_email: credentials.google_user_email || '',
    ms_tenant_id: credentials.ms_tenant_id || '',
    ms_client_id: credentials.ms_client_id || '',
    ms_client_secret: credentials.ms_client_secret || '',
    ms_refresh_token: credentials.ms_refresh_token || '',
    ms_user_email: credentials.ms_user_email || '',
    teams_chat_id: credentials.teams_chat_id || '',
    teams_team_id: credentials.teams_team_id || '',
    teams_channel_id: credentials.teams_channel_id || '',
    notes: credentials.notes || '',
  };
}

export default function AccountsPanel() {
  const { activeCompanyId, currentRecruiterId } = useSession();
  const { enabled, scenarioMeta, liveMetrics, simulateRequest, simulateAccountsPanel } = useProductionSimulator();
  const queryClient = useQueryClient();
  const [form, setForm] = useState(() => createEmptyForm(activeCompanyId));
  const [selectedAccountId, setSelectedAccountId] = useState(activeCompanyId || 1);
  const [selectedCanal, setSelectedCanal] = useState('');
  const [selectedLeadId, setSelectedLeadId] = useState(null);
  const [editingAppId, setEditingAppId] = useState(null);
  const [search, setSearch] = useState('');
  const [replyText, setReplyText] = useState('');
  const [bucket, setBucket] = useState('pending');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['accounts-panel', enabled, scenarioMeta.label],
    queryFn: () => simulateRequest(() => fetchAccountsPanel()).then(simulateAccountsPanel),
    staleTime: 30_000,
  });

  const saveMutation = useMutation({
    mutationFn: upsertAccountApp,
    onSuccess: () => {
      setEditingAppId(null);
      setForm(createEmptyForm(activeCompanyId));
      queryClient.invalidateQueries({ queryKey: ['accounts-panel'] });
    },
  });

  useEffect(() => {
    if (!activeCompanyId) return;
    setSelectedAccountId(activeCompanyId);
    setForm((current) => ({ ...current, empresa_id: activeCompanyId }));
  }, [activeCompanyId]);

  const { data: inboxData, isLoading: isLoadingInbox } = useQuery({
    queryKey: ['accounts-inbox', selectedAccountId, selectedCanal, search],
    queryFn: () => fetchAccountsInbox({
      empresa_id: selectedAccountId,
      canal: selectedCanal || undefined,
      q: search || undefined,
      bucket,
    }),
    enabled: Boolean(selectedAccountId),
    staleTime: 15_000,
  });

  const { data: threadData, isLoading: isLoadingThread } = useQuery({
    queryKey: ['accounts-inbox-thread', selectedLeadId],
    queryFn: () => fetchAccountsInboxThread(selectedLeadId),
    enabled: Boolean(selectedLeadId),
    staleTime: 10_000,
  });

  const replyMutation = useMutation({
    mutationFn: ({ leadId, text }) => replyAccountsInbox(leadId, { text }),
    onSuccess: (_, variables) => {
      setReplyText('');
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox-thread', variables.leadId] });
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox', selectedAccountId, selectedCanal, search] });
      queryClient.invalidateQueries({ queryKey: ['accounts-panel'] });
    },
  });

  const assignMutation = useMutation({
    mutationFn: ({ leadId, recruiterId }) => assignAccountsInbox(leadId, { recruiter_id: recruiterId, assigned_by: currentRecruiterId ?? 1 }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox', selectedAccountId, selectedCanal, search, bucket] });
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox-thread', variables.leadId] });
    },
  });

  const macroMutation = useMutation({
    mutationFn: ({ leadId, macro }) => applyAccountsInboxMacro(leadId, { macro }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox', selectedAccountId, selectedCanal, search, bucket] });
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox-thread', variables.leadId] });
      queryClient.invalidateQueries({ queryKey: ['accounts-panel'] });
    },
  });

  const copilotMutation = useMutation({
    mutationFn: (leadId) => fetchLeadCopilot(leadId),
  });

  const autoAssignMutation = useMutation({
    mutationFn: (leadId) => autoAssignLeadCopilot(leadId),
    onSuccess: (_, leadId) => {
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox', selectedAccountId, selectedCanal, search, bucket] });
      queryClient.invalidateQueries({ queryKey: ['accounts-inbox-thread', leadId] });
    },
  });

  useEffect(() => {
    copilotMutation.reset();
    autoAssignMutation.reset();
  }, [selectedLeadId]);

  const { data: recruitersData } = useQuery({
    queryKey: ['recruiters', selectedAccountId],
    queryFn: () => fetchRecruiters({ empresa_id: selectedAccountId }),
    enabled: Boolean(selectedAccountId),
    staleTime: 60_000,
  });

  const summary = data?.summary;
  const accounts = data?.accounts ?? [];
  const apps = data?.apps ?? [];

  const appGroups = useMemo(() => {
    return accounts.map((account) => ({
      ...account,
      apps: apps.filter((app) => Number(app.empresa_id) === Number(account.id)),
    }));
  }, [accounts, apps]);

  const credentialFields = useMemo(() => getCredentialFields(form.canal), [form.canal]);
  const inboxItems = inboxData?.items ?? [];
  const thread = threadData?.messages ?? [];
  const recruiters = recruitersData?.items ?? [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="font-display text-2xl font-bold text-text">Panel Multi Cuentas</h1>
          <p className="text-sm text-textMuted">Inspirado en el patrón account + inbox de Chatwoot, adaptado para centralizar todas las apps de reclutamiento.</p>
          {enabled && (
            <div className="mt-3 flex flex-wrap gap-2 text-xs text-textMuted">
              <span className="rounded-full border border-primary/20 bg-primary/10 px-2.5 py-1 text-primary">{scenarioMeta.label}</span>
              <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">{liveMetrics.queueDepth} en backlog</span>
              <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">lag {liveMetrics.webhookLagSec}s</span>
            </div>
          )}
        </div>
        <button
          className="btn-ghost inline-flex items-center gap-2"
          onClick={() => queryClient.invalidateQueries({ queryKey: ['accounts-panel'] })}
        >
          <RefreshCcw size={14} />
          Refrescar panel
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Cuentas</span>
            <Building2 size={16} className="text-primary" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary?.accounts ?? 0}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Apps activas</span>
            <PlugZap size={16} className="text-success" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary?.active_apps ?? 0}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Leads urgentes</span>
            <MessageCircleMore size={16} className="text-warning" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary?.urgent_leads ?? 0}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Entrevistas pendientes</span>
            <ShieldCheck size={16} className="text-primary" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary?.pending_interviews ?? 0}</p>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[1.2fr_0.9fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Inbox size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Centralización por cuenta</h3>
          </div>
          {isLoading ? (
            <div className="flex items-center justify-center h-48">
              <div className="w-7 h-7 border-2 border-primary border-t-transparent rounded-full animate-spin" />
            </div>
          ) : isError ? (
            <p className="text-sm text-red-400">No se pudo cargar el panel.</p>
          ) : (
            <div className="space-y-4">
              {appGroups.map((account) => (
                <div key={account.id} className="rounded-xl border border-border p-4 bg-bg/40">
                  <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                      <h4 className="text-sm font-semibold text-text">{account.nombre}</h4>
                      <p className="text-xs text-textMuted">{account.apps_activas ?? 0} apps activas · {account.leads_activos ?? 0} leads activos · {account.entrevistas_pendientes ?? 0} entrevistas pendientes</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <span className="px-2.5 py-1 rounded-full text-xs bg-primary/10 text-primary border border-primary/20">
                        {account.leads_urgentes ?? 0} urgentes
                      </span>
                      <span className="px-2.5 py-1 rounded-full text-xs bg-surfaceHover text-text border border-border">
                        {account.apps_total ?? 0} canales
                      </span>
                    </div>
                  </div>

                  <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    {account.apps.map((app) => (
                      <div key={app.id} className="rounded-xl border border-border p-3 bg-surface/50 space-y-3">
                        <div className="flex items-center justify-between gap-3 mb-2">
                          <div className="flex items-center gap-2">
                            <Badge value={app.canal} />
                            <span className={`px-2 py-0.5 rounded-full text-[11px] ${statusPill(app.status)}`}>{app.status}</span>
                          </div>
                          <span className="text-xs text-textSubtle">{app.inbox_alias ?? 'sin alias'}</span>
                        </div>
                        <div>
                          <p className="text-sm font-medium text-text">{app.nombre_cuenta ?? app.page_id ?? 'Cuenta sin nombre'}</p>
                          <p className="text-xs text-textMuted mt-1">{app.leads_activos ?? 0} leads activos · {app.urgentes ?? 0} urgentes</p>
                          <p className="text-xs text-textSubtle mt-2">{appCredentialSummary(app)}</p>
                        </div>
                        <button
                          type="button"
                          className="btn-ghost w-full inline-flex items-center justify-center gap-2"
                          onClick={() => {
                            setEditingAppId(app.id);
                            setForm(normalizeAppToForm(app, activeCompanyId));
                          }}
                        >
                          <KeyRound size={14} />
                          Editar credenciales
                        </button>
                      </div>
                    ))}
                    {account.apps.length === 0 && (
                      <p className="text-sm text-textMuted">Esta cuenta aún no tiene apps conectadas.</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Link2 size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">{editingAppId ? 'Editar integración centralizada' : 'Registrar app centralizada'}</h3>
          </div>

          <form
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              saveMutation.mutate(form);
            }}
          >
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Cuenta</label>
                <select
                  value={form.empresa_id}
                  onChange={(event) => setForm((current) => ({ ...current, empresa_id: Number(event.target.value) }))}
                  className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                >
                  {accounts.map((account) => <option key={account.id} value={account.id}>{account.nombre}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">App</label>
                <select
                  value={form.canal}
                  onChange={(event) => setForm((current) => ({ ...current, canal: event.target.value }))}
                  className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                >
                  {APP_OPTIONS.map((option) => <option key={option} value={option}>{option}</option>)}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Nombre de cuenta</label>
              <input
                value={form.nombre_cuenta}
                onChange={(event) => setForm((current) => ({ ...current, nombre_cuenta: event.target.value }))}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                placeholder="Ej. WhatsApp RH CDMX"
              />
            </div>

            <div>
              <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Alias de inbox</label>
              <input
                value={form.inbox_alias}
                onChange={(event) => setForm((current) => ({ ...current, inbox_alias: event.target.value }))}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                placeholder="Ej. RH-Principal"
              />
            </div>

            <div>
              <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">{getExternalIdLabel(form.canal)}</label>
              <input
                value={form.page_id}
                onChange={(event) => setForm((current) => ({ ...current, page_id: event.target.value }))}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                placeholder={getExternalIdPlaceholder(form.canal)}
              />
            </div>

            {credentialFields.length > 0 && (
              <div className="rounded-xl border border-border bg-bg/40 p-4 space-y-4">
                <div className="flex items-center gap-2">
                  <KeyRound size={14} className="text-primary" />
                  <p className="text-xs uppercase tracking-wide text-textSubtle">Credenciales de integración</p>
                </div>
                <div className="grid grid-cols-1 gap-3">
                  {credentialFields.map((field) => (
                    <div key={field.key}>
                      <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">{field.label}</label>
                      <input
                        type={field.secret ? 'password' : 'text'}
                        value={form[field.key] ?? ''}
                        onChange={(event) => setForm((current) => ({ ...current, [field.key]: event.target.value }))}
                        className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                        placeholder={field.placeholder}
                      />
                    </div>
                  ))}
                </div>
                <p className="text-xs text-textSubtle">Las credenciales quedan asociadas al canal para que el panel pueda enviar respuestas desde esa app.</p>
              </div>
            )}

            <div>
              <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Notas internas</label>
              <textarea
                value={form.notes}
                onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
                rows={3}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                placeholder="Webhook, URL, responsable o cualquier detalle operativo"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Estado</label>
                <select
                  value={form.status}
                  onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}
                  className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                >
                  <option value="connected">connected</option>
                  <option value="warning">warning</option>
                  <option value="disconnected">disconnected</option>
                </select>
              </div>
              <div className="flex items-end">
                <label className="inline-flex items-center gap-2 text-sm text-text">
                  <input
                    type="checkbox"
                    checked={form.activo}
                    onChange={(event) => setForm((current) => ({ ...current, activo: event.target.checked }))}
                  />
                  Activa
                </label>
              </div>
            </div>

            <div className="flex gap-3">
              <button type="submit" className="btn-primary flex-1" disabled={saveMutation.isPending}>
                {editingAppId ? 'Actualizar conexión' : 'Guardar conexión'}
              </button>
              <button
                type="button"
                className="btn-ghost"
                onClick={() => {
                  setEditingAppId(null);
                  setForm(createEmptyForm(activeCompanyId));
                }}
              >
                Limpiar
              </button>
            </div>
          </form>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.95fr_1.4fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <MessageCircleMore size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Inbox unificado multi-canal</h3>
          </div>

            <div className="space-y-3 mb-4">
            <div className="flex flex-wrap gap-2">
              {BUCKETS.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => {
                    setBucket(item.key);
                    setSelectedLeadId(null);
                  }}
                  className={`px-3 py-1.5 rounded-full text-sm border transition-colors ${bucket === item.key ? 'bg-primary/10 text-primary border-primary/30' : 'bg-bg text-textMuted border-border hover:text-text'}`}
                >
                  {item.label}
                </button>
              ))}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <select
                value={selectedAccountId}
                onChange={(event) => {
                  const nextId = Number(event.target.value);
                  setSelectedAccountId(nextId);
                  setSelectedLeadId(null);
                }}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
              >
                {accounts.map((account) => <option key={account.id} value={account.id}>{account.nombre}</option>)}
              </select>
              <select
                value={selectedCanal}
                onChange={(event) => {
                  setSelectedCanal(event.target.value);
                  setSelectedLeadId(null);
                }}
                className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
              >
                <option value="">Todos los canales</option>
                {APP_OPTIONS.map((option) => <option key={option} value={option}>{option}</option>)}
              </select>
            </div>
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
              placeholder="Buscar por nombre, teléfono, email o user ID..."
            />
          </div>

          <div className="space-y-3 max-h-[38rem] overflow-auto">
            {isLoadingInbox ? (
              <div className="flex items-center justify-center h-40">
                <div className="w-7 h-7 border-2 border-primary border-t-transparent rounded-full animate-spin" />
              </div>
            ) : inboxItems.length === 0 ? (
              <p className="text-sm text-textMuted">No hay conversaciones para ese filtro.</p>
            ) : inboxItems.map((item) => (
              <div
                key={item.id}
                className={`w-full text-left rounded-xl border p-4 transition-colors ${selectedLeadId === item.id ? 'border-primary bg-primary/5' : 'border-border bg-bg/40 hover:bg-surfaceHover'}`}
              >
                <button type="button" onClick={() => setSelectedLeadId(item.id)} className="w-full text-left">
                  <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-sm font-semibold text-text">{item.nombre ?? item.canal_user_id}</p>
                    <Badge value={item.canal} />
                  </div>
                  <p className="text-xs text-textMuted">{item.nombre_cuenta ?? item.inbox_alias ?? item.empresa_nombre}</p>
                  <div className="flex items-center gap-2 mt-2 flex-wrap">
                    <Badge value={item.prioridad} />
                    <MetaTag>{item.bucket}</MetaTag>
                    <span className="text-xs text-textSubtle">{item.message_count} mensajes</span>
                  </div>
                  <div className="flex flex-wrap gap-2 mt-2">
                    {item.vacante && <MetaTag tone="accent">{item.vacante}</MetaTag>}
                    {item.edad_label && <MetaTag>{item.edad_label}</MetaTag>}
                    {item.experiencia && <MetaTag>{item.experiencia}</MetaTag>}
                    {item.interview_date && item.interview_time && (
                      <MetaTag tone="success">{formatInterview(item.interview_date, item.interview_time)}</MetaTag>
                    )}
                    {item.interview_record_status && <MetaTag tone={interviewStatusTone(item.interview_record_status)}>{item.interview_record_status}</MetaTag>}
                    {item.next_action_type && <MetaTag tone="warning">{item.next_action_type}</MetaTag>}
                    {item.origen_label && <MetaTag>{item.origen_label}</MetaTag>}
                  </div>
                  <div className="mt-2 space-y-1">
                    <p className="text-xs text-textSubtle">{item.recruiter_nombre ? `Asignado a ${item.recruiter_nombre}` : 'Sin asignar'}</p>
                    {item.last_contact_label && <p className="text-xs text-textSubtle">{item.last_contact_label}</p>}
                  </div>
                </button>
                <div className="mt-3">
                  <select
                    value={item.assigned_recruiter_id ?? ''}
                    onChange={(event) => assignMutation.mutate({ leadId: item.id, recruiterId: Number(event.target.value) })}
                    className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                  >
                    <option value="">Asignar recruiter</option>
                    {recruiters.map((recruiter) => (
                      <option key={recruiter.id} value={recruiter.id}>{recruiter.nombre}</option>
                    ))}
                  </select>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Inbox size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Hilo centralizado</h3>
          </div>

          {!selectedLeadId ? (
            <p className="text-sm text-textMuted">Selecciona una conversación del panel izquierdo para abrir el hilo.</p>
          ) : isLoadingThread ? (
            <div className="flex items-center justify-center h-56">
              <div className="w-7 h-7 border-2 border-primary border-t-transparent rounded-full animate-spin" />
            </div>
          ) : (
            <div className="space-y-4">
              <div className="rounded-xl border border-border p-4 bg-bg/40">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-text">{threadData?.lead?.nombre ?? 'Lead'}</p>
                    <p className="text-xs text-textMuted">{threadData?.lead?.empresa_nombre} · {threadData?.lead?.seccion_nombre} · {threadData?.lead?.canal_user_id}</p>
                  </div>
                  <div className="flex gap-2">
                    <Badge value={threadData?.lead?.canal} />
                    <Badge value={threadData?.lead?.prioridad} />
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  {threadData?.lead?.vacante && <MetaTag tone="accent">{threadData.lead.vacante}</MetaTag>}
                  {threadData?.lead?.edad_label && <MetaTag>{threadData.lead.edad_label}</MetaTag>}
                  {threadData?.lead?.experiencia && <MetaTag>{threadData.lead.experiencia}</MetaTag>}
                  {threadData?.lead?.interview_date && threadData?.lead?.interview_time && (
                    <MetaTag tone="success">{formatInterview(threadData.lead.interview_date, threadData.lead.interview_time)}</MetaTag>
                  )}
                  {threadData?.lead?.interview_record_status && <MetaTag tone={interviewStatusTone(threadData.lead.interview_record_status)}>{threadData.lead.interview_record_status}</MetaTag>}
                  {threadData?.lead?.next_action_type && <MetaTag tone="warning">{threadData.lead.next_action_type}</MetaTag>}
                  {threadData?.lead?.ciudad && <MetaTag>{threadData.lead.ciudad}</MetaTag>}
                  {threadData?.lead?.origen_label && <MetaTag>{threadData.lead.origen_label}</MetaTag>}
                  {threadData?.lead?.last_contact_label && <MetaTag>{threadData.lead.last_contact_label}</MetaTag>}
                </div>
                <div className="mt-3">
                  <select
                    value={threadData?.lead?.assigned_recruiter_id ?? ''}
                    onChange={(event) => assignMutation.mutate({ leadId: selectedLeadId, recruiterId: Number(event.target.value) })}
                    className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                  >
                    <option value="">Asignar recruiter</option>
                    {recruiters.map((recruiter) => (
                      <option key={recruiter.id} value={recruiter.id}>{recruiter.nombre}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="space-y-3 max-h-[28rem] overflow-auto rounded-xl border border-border p-4 bg-bg/30">
                {thread.length === 0 ? (
                  <p className="text-sm text-textMuted">No hay mensajes registrados todavía.</p>
                ) : thread.map((message) => (
                  <div key={message.id} className={`flex ${message.direction === 'outbound' ? 'justify-end' : 'justify-start'}`}>
                    <div className={`max-w-[80%] rounded-2xl px-4 py-3 text-sm border ${message.direction === 'outbound' ? 'bg-primary text-white border-primary/40' : 'bg-surface text-text border-border'}`}>
                      <p className={message.direction === 'outbound' ? 'text-white' : 'text-text'}>{message.text}</p>
                      <p className={`mt-2 text-[11px] ${message.direction === 'outbound' ? 'text-white/75' : 'text-textSubtle'}`}>{new Date(message.created_at).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
                    </div>
                  </div>
                ))}
              </div>

              <div className="rounded-xl border border-border p-4 bg-bg/40 space-y-3">
                <div>
                  <div className="flex items-center gap-2 mb-2">
                    <Sparkles size={14} className="text-primary" />
                    <p className="text-xs uppercase tracking-wide text-textSubtle">Copiloto IA</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      className="btn-ghost"
                      onClick={() => copilotMutation.mutate(selectedLeadId)}
                      disabled={copilotMutation.isPending}
                    >
                      Analizar lead
                    </button>
                    <button
                      type="button"
                      className="btn-ghost"
                      onClick={() => autoAssignMutation.mutate(selectedLeadId)}
                      disabled={autoAssignMutation.isPending}
                    >
                      Autoasignar IA
                    </button>
                  </div>
                  {copilotMutation.data?.analysis && (
                    <div className="mt-3 rounded-xl border border-primary/20 bg-primary/5 p-4 space-y-2">
                      <p className="text-sm font-semibold text-text">{copilotMutation.data.analysis.summary}</p>
                      <div className="flex flex-wrap gap-2 text-xs">
                        <MetaTag tone="accent">Score conversación: {copilotMutation.data.analysis.conversation_score}</MetaTag>
                        <MetaTag tone={copilotMutation.data.analysis.candidate_temperature === 'hot' ? 'success' : 'warning'}>
                          {copilotMutation.data.analysis.candidate_temperature}
                        </MetaTag>
                        <MetaTag>{copilotMutation.data.analysis.recommended_action}</MetaTag>
                      </div>
                      {copilotMutation.data.analysis.recommended_recruiter_name && (
                        <p className="text-xs text-textMuted">Recruiter sugerido: {copilotMutation.data.analysis.recommended_recruiter_name} · {copilotMutation.data.analysis.recruiter_reason}</p>
                      )}
                      <button
                        type="button"
                        className="btn-ghost"
                        onClick={() => setReplyText(copilotMutation.data.analysis.suggested_reply ?? '')}
                      >
                        Usar respuesta sugerida
                      </button>
                    </div>
                  )}
                  {autoAssignMutation.data?.assignment && (
                    <p className="mt-2 text-xs text-success">Asignado automáticamente a {autoAssignMutation.data.assignment.recruiter_nombre}.</p>
                  )}
                </div>

                <div>
                  <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Templates rápidos</p>
                  <div className="flex flex-wrap gap-2">
                    {(threadData?.templates ?? []).map((template) => (
                      <button
                        key={template.key}
                        type="button"
                        className="btn-ghost"
                        onClick={() => setReplyText(template.text)}
                      >
                        {template.label}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Macros</p>
                  <div className="flex flex-wrap gap-2">
                    {(threadData?.macros ?? []).map((macro) => (
                      <button
                        key={macro.key}
                        type="button"
                        className="btn-ghost"
                        onClick={() => macroMutation.mutate({ leadId: selectedLeadId, macro: macro.key })}
                        disabled={macroMutation.isPending}
                      >
                        {macro.label}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              <form
                className="space-y-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  if (!replyText.trim()) return;
                  replyMutation.mutate({ leadId: selectedLeadId, text: replyText });
                }}
              >
                <textarea
                  value={replyText}
                  onChange={(event) => setReplyText(event.target.value)}
                  rows={4}
                  placeholder="Responder desde el panel multi cuentas..."
                  className="w-full rounded-xl text-sm bg-bg border border-border p-3 text-text placeholder:text-textMuted focus:outline-none focus:border-primary/60"
                />
                <div className="flex items-center justify-between">
                  <span className="text-xs text-textSubtle">La respuesta se envía por el canal original del lead.</span>
                  <button type="submit" className="btn-primary" disabled={replyMutation.isPending}>
                    Enviar respuesta
                  </button>
                </div>
              </form>
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}










