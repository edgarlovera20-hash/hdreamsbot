import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Brain, Plus, Trash2, Pencil, Check, X, Loader2, ChevronDown, ChevronUp, Zap, CheckCircle, XCircle } from 'lucide-react';
import {
  fetchAgentes, createAgente, updateAgente, deleteAgente,
  fetchBotConfig, saveBotConfig, testLlmConfig,
} from '../lib/api';

const TIPOS = ['responder', 'scorer', 'classifier', 'extractor'];
const MODELOS = [
  'llama3.2:3b', 'llama3.1:8b', 'llama3:8b', 'mistral:7b', 'gemma2:9b',
  'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo',
  'claude-sonnet-4-6', 'claude-haiku-4-5-20251001',
  'mixtral-8x7b-32768', 'llama-3.3-70b-versatile',
];

const PROVIDERS = [
  { id: 'ollama',    label: 'Ollama (local)',  url: 'http://localhost:11434/v1',       needsKey: false },
  { id: 'lmstudio', label: 'LM Studio',        url: 'http://localhost:1234/v1',        needsKey: false },
  { id: 'openai',   label: 'OpenAI',            url: 'https://api.openai.com/v1',      needsKey: true  },
  { id: 'groq',     label: 'Groq',              url: 'https://api.groq.com/openai/v1', needsKey: true  },
  { id: 'together', label: 'Together AI',       url: 'https://api.together.xyz/v1',    needsKey: true  },
  { id: 'mistral',  label: 'Mistral AI',        url: 'https://api.mistral.ai/v1',      needsKey: true  },
  { id: 'custom',   label: 'Personalizado',     url: '',                               needsKey: true  },
];

const TIPO_COLORS = {
  responder:  'bg-blue-500/10 text-blue-600',
  scorer:     'bg-green-500/10 text-green-600',
  classifier: 'bg-purple-500/10 text-purple-600',
  extractor:  'bg-orange-500/10 text-orange-600',
};

const PROMPT_RESPONDER_DEFAULT = `Eres {{nombre_bot}}, reclutadora senior de {{empresa_nombre}}.
Tu misión: entrevistar y calificar candidatos para nuestras vacantes de forma conversacional.

VACANTES DISPONIBLES:
- Promotor de ventas (CDMX, Monterrey, Guadalajara) — sueldo base + comisiones
- Asesor comercial (Nacional) — excelentes comisiones
- Ejecutivo de atención al cliente (CDMX) — prestaciones completas

DATOS A RECOPILAR (uno por mensaje, con naturalidad):
1. Nombre completo
2. Edad — requisito: 18 a 35 años
3. Ciudad de residencia
4. Experiencia en ventas o atención al cliente
5. Disponibilidad de horario (completo / medio tiempo)
6. Teléfono de contacto

INSTRUCCIONES:
- Mensajes CORTOS: máx 2-3 líneas.
- Tono cálido, profesional y motivador.
- Si el candidato no cumple requisito de edad, declinar amablemente.
- Si ya tienes un dato del candidato, NO lo vuelvas a pedir.
- Responde SIEMPRE en español.`;

const EMPTY = { nombre: '', tipo: 'responder', modelo: 'llama3.2:3b', prompt_sistema: PROMPT_RESPONDER_DEFAULT, activo: true, config_json: {} };

// ── Proveedor de IA ────────────────────────────────────────────────────────────
function LLMConfig() {
  const qc = useQueryClient();
  const { data: cfg = {} } = useQuery({ queryKey: ['bot-config'], queryFn: fetchBotConfig });

  const [provider, setProvider] = useState('ollama');
  const [url, setUrl]           = useState('http://localhost:11434/v1');
  const [key, setKey]           = useState('');
  const [modelo, setModelo]     = useState('llama3.2:3b');
  const [testSt, setTestSt]     = useState(null);   // null | 'testing' | 'ok' | 'error'
  const [testMsg, setTestMsg]   = useState('');
  const [saving, setSaving]     = useState(false);
  const [saved, setSaved]       = useState(false);

  useEffect(() => {
    if (cfg.llm_base_url) {
      setUrl(cfg.llm_base_url);
      const match = PROVIDERS.find(p => p.url === cfg.llm_base_url);
      setProvider(match?.id ?? 'custom');
    }
    if (cfg.llm_model_default) setModelo(cfg.llm_model_default);
  }, [cfg.llm_base_url, cfg.llm_model_default]);

  const selectProvider = (pid) => {
    setProvider(pid);
    const p = PROVIDERS.find(p => p.id === pid);
    if (p?.url) setUrl(p.url);
    setTestSt(null);
  };

  const save = async () => {
    setSaving(true);
    const data = { llm_base_url: url, llm_model_default: modelo };
    if (key) data.llm_api_key = key;
    await saveBotConfig(data);
    setKey('');
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
    qc.invalidateQueries({ queryKey: ['bot-config'] });
    setSaving(false);
  };

  const test = async () => {
    setTestSt('testing'); setTestMsg('');
    try {
      const r = await testLlmConfig({ llm_base_url: url, llm_api_key: key, modelo });
      setTestSt(r.ok ? 'ok' : 'error');
      setTestMsg(r.message);
    } catch {
      setTestSt('error'); setTestMsg('Error de red');
    }
  };

  const base = 'w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary';
  const needsKey = PROVIDERS.find(p => p.id === provider)?.needsKey ?? true;

  return (
    <div className="bg-surface border border-border rounded-2xl p-5 space-y-4">
      <div className="flex items-center gap-2">
        <Zap size={16} className="text-primary" />
        <h3 className="text-sm font-bold text-text">Proveedor de IA</h3>
        <span className="text-2xs text-textSubtle ml-auto">endpoint compartido por todos los agentes</span>
      </div>

      <div className="grid grid-cols-4 gap-1.5">
        {PROVIDERS.map(p => (
          <button key={p.id} onClick={() => selectProvider(p.id)}
            className={`text-xs px-2 py-1.5 rounded-lg border transition-colors text-center ${
              provider === p.id
                ? 'border-primary bg-primary/10 text-primary font-semibold'
                : 'border-border text-textMuted hover:text-text'
            }`}>
            {p.label}
          </button>
        ))}
      </div>

      <div className="space-y-2">
        <div className="space-y-1">
          <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">URL del API</label>
          <input value={url} onChange={e => { setUrl(e.target.value); setTestSt(null); }}
            placeholder="https://api.openai.com/v1" className={base} />
        </div>

        {needsKey && (
          <div className="space-y-1">
            <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">
              API Key{cfg.llm_api_key_set ? <span className="text-green-600 normal-case font-normal ml-1">(guardada)</span> : null}
            </label>
            <input type="password" value={key} onChange={e => setKey(e.target.value)}
              placeholder={cfg.llm_api_key_set ? '••••••• (vacío = mantener actual)' : 'sk-...'}
              className={base} />
          </div>
        )}

        <div className="space-y-1">
          <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Modelo por defecto</label>
          <input list="llm-models-list" value={modelo} onChange={e => setModelo(e.target.value)}
            placeholder="llama3.2:3b" className={base} />
          <datalist id="llm-models-list">
            {MODELOS.map(m => <option key={m} value={m} />)}
          </datalist>
        </div>
      </div>

      {testSt && testSt !== 'testing' && (
        <div className={`flex items-center gap-2 text-xs px-3 py-2 rounded-lg ${
          testSt === 'ok' ? 'bg-green-500/10 text-green-700' : 'bg-red-500/10 text-red-700'
        }`}>
          {testSt === 'ok' ? <CheckCircle size={13} /> : <XCircle size={13} />}
          {testMsg}
        </div>
      )}

      <div className="flex gap-2">
        <button onClick={test} disabled={!url || testSt === 'testing'}
          className="flex items-center gap-1.5 border border-border text-sm text-textMuted px-3 py-1.5 rounded-lg hover:text-text transition-colors disabled:opacity-50">
          {testSt === 'testing' ? <Loader2 size={13} className="animate-spin" /> : <Zap size={13} />}
          Probar conexión
        </button>
        <button onClick={save} disabled={saving}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-4 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50">
          {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
          {saved ? 'Guardado ✓' : 'Guardar'}
        </button>
      </div>
    </div>
  );
}

// ── Form de agente ─────────────────────────────────────────────────────────────
function AgentForm({ initial = EMPTY, onSave, onCancel, isLoading }) {
  const [form, setForm] = useState(initial);
  const set = k => v => setForm(f => ({ ...f, [k]: v }));
  const base = 'w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary';

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1">
          <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Nombre</label>
          <input value={form.nombre} onChange={e => set('nombre')(e.target.value)}
            placeholder="Lead Scorer Principal" className={base} />
        </div>
        <div className="space-y-1">
          <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Tipo</label>
          <select value={form.tipo} onChange={e => set('tipo')(e.target.value)} className={base}>
            {TIPOS.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </div>
      </div>

      <div className="space-y-1">
        <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Modelo LLM</label>
        <input list="agent-models-list" value={form.modelo} onChange={e => set('modelo')(e.target.value)}
          placeholder="llama3.2:3b, gpt-4o..." className={base} />
        <datalist id="agent-models-list">
          {MODELOS.map(m => <option key={m} value={m} />)}
        </datalist>
      </div>

      <div className="space-y-1">
        <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">System Prompt</label>
        <textarea rows={5} value={form.prompt_sistema}
          onChange={e => set('prompt_sistema')(e.target.value)}
          placeholder="Eres un asistente de reclutamiento de Heavenly Dreams..."
          className={base + ' resize-none'} />
      </div>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={form.activo}
          onChange={e => set('activo')(e.target.checked)}
          className="w-4 h-4 rounded border-border text-primary" />
        <span className="text-sm text-textMuted">Agente activo</span>
      </label>

      <div className="flex gap-2 pt-1">
        <button onClick={() => onSave(form)} disabled={isLoading}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50">
          {isLoading ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
          Guardar
        </button>
        <button onClick={onCancel}
          className="flex items-center gap-1.5 border border-border text-sm text-textMuted px-4 py-2 rounded-lg hover:text-text transition-colors">
          <X size={13} /> Cancelar
        </button>
      </div>
    </div>
  );
}

// ── Fila de agente ─────────────────────────────────────────────────────────────
function AgenteRow({ agente, onUpdate, onDelete }) {
  const [editing, setEditing] = useState(false);
  const [open, setOpen]       = useState(false);

  return (
    <div className="bg-surface border border-border rounded-xl overflow-hidden">
      <div className="flex items-center gap-3 px-4 py-3">
        <span className={`text-2xs font-bold px-2 py-0.5 rounded-full uppercase tracking-wide ${TIPO_COLORS[agente.tipo] ?? 'bg-surface text-textMuted'}`}>
          {agente.tipo}
        </span>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold text-text truncate">{agente.nombre}</p>
          <p className="text-2xs text-textSubtle">{agente.modelo}</p>
        </div>
        <div className={`w-2 h-2 rounded-full ${agente.activo ? 'bg-green-500' : 'bg-textSubtle'}`} />
        <button onClick={() => { setOpen(o => !o); setEditing(false); }}
          className="text-textSubtle hover:text-text transition-colors">
          {open ? <ChevronUp size={15} /> : <ChevronDown size={15} />}
        </button>
        <button onClick={() => { setEditing(true); setOpen(true); }}
          className="text-textSubtle hover:text-primary transition-colors">
          <Pencil size={14} />
        </button>
        <button onClick={() => onDelete(agente.id)}
          className="text-textSubtle hover:text-danger transition-colors">
          <Trash2 size={14} />
        </button>
      </div>

      {open && (
        <div className="px-4 pb-4 border-t border-border pt-3">
          {editing ? (
            <AgentForm
              initial={{ ...agente }}
              onSave={data => { onUpdate(agente.id, data); setEditing(false); setOpen(false); }}
              onCancel={() => setEditing(false)}
            />
          ) : (
            <pre className="text-xs text-textMuted whitespace-pre-wrap bg-bg rounded-lg p-3 border border-border max-h-40 overflow-auto">
              {agente.prompt_sistema || '(sin prompt)'}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

// ── Página principal ───────────────────────────────────────────────────────────
export default function AgentesIA() {
  const qc = useQueryClient();
  const { data: agentes = [], isLoading } = useQuery({ queryKey: ['agentes'], queryFn: fetchAgentes });
  const [creating, setCreating] = useState(false);

  const createMut = useMutation({
    mutationFn: createAgente,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['agentes'] }); setCreating(false); },
  });
  const updateMut = useMutation({
    mutationFn: ([id, data]) => updateAgente(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agentes'] }),
  });
  const deleteMut = useMutation({
    mutationFn: deleteAgente,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agentes'] }),
  });

  return (
    <div className="max-w-2xl space-y-5">
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-3">
          <Brain size={22} className="text-primary" />
          <h1 className="text-xl font-bold text-text">Agentes de IA</h1>
        </div>
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-3 py-2 rounded-lg hover:opacity-90 transition-opacity">
          <Plus size={14} /> Nuevo agente
        </button>
      </div>

      <LLMConfig />

      {creating && (
        <div className="bg-surface border border-primary/30 rounded-2xl p-5 space-y-4">
          <h2 className="text-sm font-bold text-text">Nuevo agente</h2>
          <AgentForm
            onSave={data => createMut.mutate(data)}
            onCancel={() => setCreating(false)}
            isLoading={createMut.isPending}
          />
        </div>
      )}

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Loader2 size={24} className="animate-spin text-primary" />
        </div>
      ) : agentes.length === 0 ? (
        <div className="text-center py-16 text-textSubtle">
          <Brain size={36} className="mx-auto mb-3 opacity-30" />
          <p className="text-sm">Aún no hay agentes configurados.</p>
          <p className="text-xs mt-1">Crea uno para personalizar respuestas y scoring.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {agentes.map(a => (
            <AgenteRow
              key={a.id}
              agente={a}
              onUpdate={(id, data) => updateMut.mutate([id, data])}
              onDelete={id => deleteMut.mutate(id)}
            />
          ))}
        </div>
      )}

      <div className="bg-surface border border-border rounded-2xl p-4 space-y-3">
        <h3 className="text-xs font-bold text-text">Tipos de agentes</h3>
        <div className="grid grid-cols-2 gap-2 text-xs text-textMuted">
          <div><span className="font-semibold text-blue-600">responder</span> — responde al candidato en cada mensaje</div>
          <div><span className="font-semibold text-green-600">scorer</span> — calcula score de candidato/contratación</div>
          <div><span className="font-semibold text-purple-600">classifier</span> — clasifica intención y etapa del lead</div>
          <div><span className="font-semibold text-orange-600">extractor</span> — extrae datos (edad, ciudad, experiencia)</div>
        </div>
        <div className="border-t border-border pt-3 text-xs text-textSubtle space-y-1">
          <p className="font-semibold text-textMuted">Variables disponibles en el prompt:</p>
          <p><code className="bg-bg px-1 rounded">{'{{nombre_bot}}'}</code> — nombre de la reclutadora</p>
          <p><code className="bg-bg px-1 rounded">{'{{empresa_nombre}}'}</code> — nombre de la empresa</p>
          <p className="pt-1">El agente <span className="text-blue-600 font-semibold">responder</span> activo recibe automáticamente el historial de conversación y los datos ya capturados del candidato.</p>
        </div>
      </div>
    </div>
  );
}
