import { useState, useEffect } from 'react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { Bot, Save, Check, Loader2, Zap, CheckCircle2, XCircle } from 'lucide-react';
import { fetchBotConfig, saveBotConfig, testLlmConfig } from '../lib/api';

function Field({ label, name, value, onChange, type = 'text', placeholder, rows }) {
  const base = 'w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary';
  return (
    <div className="space-y-1">
      <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">{label}</label>
      {rows ? (
        <textarea rows={rows} name={name} value={value} onChange={onChange}
          placeholder={placeholder} className={base + ' resize-none'} />
      ) : (
        <input type={type} name={name} value={value} onChange={onChange}
          placeholder={placeholder} className={base} />
      )}
    </div>
  );
}

function Section({ title, children }) {
  return (
    <div className="bg-surface border border-border rounded-2xl p-5 space-y-4">
      <h2 className="text-sm font-bold text-text">{title}</h2>
      {children}
    </div>
  );
}

const DEFAULTS = {
  nombre_bot: '', empresa_nombre: '', saludo: '', fuera_horario: '',
  horario_inicio: '09:00', horario_fin: '18:00', horario_dias: 'lunes-viernes',
  escalacion_score: '80', telefono_reclutador: '', idioma: 'es',
  auto_responder: '1', max_mensajes_auto: '10',
  llm_base_url: 'http://localhost:11434/v1', llm_model_default: 'llama3.2:3b',
  llm_api_key: '', llm_api_key_set: false,
};

export default function BotConfig() {
  const { data, isLoading } = useQuery({ queryKey: ['bot-config'], queryFn: fetchBotConfig });
  const [form, setForm] = useState(DEFAULTS);
  const [saved, setSaved] = useState(false);
  const [llmTest, setLlmTest] = useState(null);
  const [testing, setTesting] = useState(false);

  useEffect(() => { if (data) setForm((prev) => ({ ...prev, ...data })); }, [data]);

  const mutation = useMutation({
    mutationFn: saveBotConfig,
    onSuccess: () => { setSaved(true); setTimeout(() => setSaved(false), 2000); },
  });

  const onChange = (e) => setForm((f) => ({ ...f, [e.target.name]: e.target.value }));
  const onCheck  = (e) => setForm((f) => ({ ...f, [e.target.name]: e.target.checked ? '1' : '0' }));

  const handleTestLlm = async () => {
    setTesting(true);
    setLlmTest(null);
    try {
      const r = await testLlmConfig({
        llm_base_url:      form.llm_base_url,
        llm_api_key:       form.llm_api_key,
        llm_model_default: form.llm_model_default,
        modelo:            form.llm_model_default,
      });
      setLlmTest(r);
    } catch {
      setLlmTest({ ok: false, message: 'Error de red' });
    } finally {
      setTesting(false);
    }
  };

  if (isLoading) return (
    <div className="flex justify-center py-12">
      <Loader2 size={24} className="animate-spin text-primary" />
    </div>
  );

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); mutation.mutate(form); }}
      className="max-w-2xl space-y-5"
    >
      <div className="flex items-center gap-3 mb-6">
        <Bot size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Configuración del Bot</h1>
      </div>

      <Section title="Identidad del Bot">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Nombre del agente" name="nombre_bot" value={form.nombre_bot}
            onChange={onChange} placeholder="Lic. Gissell" />
          <Field label="Empresa" name="empresa_nombre" value={form.empresa_nombre}
            onChange={onChange} placeholder="Heavenly Dreams" />
        </div>
        <div className="space-y-1">
          <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Idioma</label>
          <select name="idioma" value={form.idioma} onChange={onChange}
            className="w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text focus:outline-none focus:ring-1 focus:ring-primary">
            <option value="es">Español</option>
            <option value="en">English</option>
          </select>
        </div>
      </Section>

      <Section title="Mensajes Automáticos">
        <Field label="Saludo inicial" name="saludo" value={form.saludo}
          onChange={onChange} rows={3}
          placeholder="Hola, soy Lic. Gissell de RH..." />
        <Field label="Mensaje fuera de horario" name="fuera_horario" value={form.fuera_horario}
          onChange={onChange} rows={2}
          placeholder="Gracias por escribir. Nuestro horario es de 9am a 6pm..." />
      </Section>

      <Section title="Horario de Atención">
        <div className="grid grid-cols-3 gap-3">
          <Field label="Hora inicio" name="horario_inicio" type="time"
            value={form.horario_inicio} onChange={onChange} />
          <Field label="Hora fin" name="horario_fin" type="time"
            value={form.horario_fin} onChange={onChange} />
          <div className="space-y-1">
            <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Días</label>
            <select name="horario_dias" value={form.horario_dias} onChange={onChange}
              className="w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text focus:outline-none focus:ring-1 focus:ring-primary">
              <option value="lunes-viernes">Lun – Vie</option>
              <option value="lunes-sabado">Lun – Sáb</option>
              <option value="todos">Todos los días</option>
            </select>
          </div>
        </div>
      </Section>

      <Section title="Escalación & Notificaciones">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Score mínimo para alerta urgente" name="escalacion_score"
            type="number" value={form.escalacion_score} onChange={onChange} placeholder="80" />
          <Field label="Teléfono del reclutador (WhatsApp)" name="telefono_reclutador"
            value={form.telefono_reclutador} onChange={onChange} placeholder="5215212345678" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="auto_responder" checked={form.auto_responder === '1'}
              onChange={onCheck} className="w-4 h-4 rounded border-border text-primary" />
            <span className="text-sm text-textMuted">Respuesta automática activa</span>
          </label>
          <Field label="Máx. mensajes automáticos / lead" name="max_mensajes_auto"
            type="number" value={form.max_mensajes_auto} onChange={onChange} placeholder="10" />
        </div>
      </Section>

      {/* ── LLM / IA Config ──────────────────────────────────── */}
      <Section title="Configuración LLM / IA">
        <p className="text-xs text-textSubtle leading-relaxed">
          Conecta un modelo de lenguaje local (Ollama) o en la nube (OpenAI, Groq, Together.ai).
          Cualquier API compatible con OpenAI funciona.
        </p>

        <Field label="URL base del servidor LLM" name="llm_base_url"
          value={form.llm_base_url} onChange={onChange}
          placeholder="http://localhost:11434/v1 · https://api.openai.com/v1" />

        <div className="grid grid-cols-2 gap-3">
          <Field label="Modelo por defecto" name="llm_model_default"
            value={form.llm_model_default} onChange={onChange}
            placeholder="gpt-4o · llama3.2:3b · llama3.1:8b" />
          <div className="space-y-1">
            <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">
              API Key {form.llm_api_key_set && <span className="text-green-400 normal-case font-normal">(guardada)</span>}
            </label>
            <input
              type="password"
              name="llm_api_key"
              value={form.llm_api_key}
              onChange={onChange}
              placeholder={form.llm_api_key_set ? '••••••••••••• (deja vacío para no cambiar)' : 'sk-... (vacío = sin auth)'}
              className="w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
        </div>

        {/* Test button */}
        <div className="flex items-center gap-3 pt-1">
          <button
            type="button"
            onClick={handleTestLlm}
            disabled={testing}
            className="flex items-center gap-2 px-4 py-2 border border-border rounded-lg text-sm text-textMuted hover:text-primary hover:border-primary transition-colors disabled:opacity-50"
          >
            {testing ? <Loader2 size={14} className="animate-spin" /> : <Zap size={14} />}
            Probar conexión LLM
          </button>

          {llmTest && (
            <div className={`flex items-center gap-2 text-sm ${llmTest.ok ? 'text-green-400' : 'text-red-400'}`}>
              {llmTest.ok ? <CheckCircle2 size={15} /> : <XCircle size={15} />}
              {llmTest.message}
            </div>
          )}
        </div>

        <div className="text-xs text-textSubtle space-y-0.5">
          <p><span className="font-mono text-textMuted">Ollama local:</span> URL = http://localhost:11434/v1, sin API key</p>
          <p><span className="font-mono text-textMuted">OpenAI:</span> URL = https://api.openai.com/v1, API key = sk-...</p>
          <p><span className="font-mono text-textMuted">Groq:</span> URL = https://api.groq.com/openai/v1, modelo = llama3-8b-8192</p>
        </div>
      </Section>

      <div className="flex justify-end">
        <button type="submit" disabled={mutation.isPending}
          className="flex items-center gap-2 bg-primary text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50">
          {mutation.isPending
            ? <Loader2 size={15} className="animate-spin" />
            : saved ? <Check size={15} /> : <Save size={15} />}
          {saved ? 'Guardado' : 'Guardar cambios'}
        </button>
      </div>
    </form>
  );
}
