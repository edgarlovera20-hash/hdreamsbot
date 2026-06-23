import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Briefcase, FileText, GitBranch,
  Plus, Trash2, Pencil, Check, X, Loader2, ChevronDown, ChevronUp,
  MapPin, Clock, DollarSign, Tag, Hash,
} from 'lucide-react';
import {
  fetchVacantes,  createVacante,  updateVacante,  deleteVacante,
  fetchPlantillas, createPlantilla, updatePlantilla, deletePlantilla,
  fetchFlujos,    createFlujo,    updateFlujo,    deleteFlujo,
} from '../lib/api';

// ── Shared primitives ──────────────────────────────────────────────────────

const inp = 'w-full bg-bg border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary';

function Field({ label, children }) {
  return (
    <div className="space-y-1">
      <label className="text-2xs font-semibold tracking-widest text-textMuted uppercase">{label}</label>
      {children}
    </div>
  );
}

function Tabs({ tabs, active, onChange }) {
  return (
    <div className="flex gap-1 bg-surface border border-border rounded-xl p-1 mb-6">
      {tabs.map((t) => (
        <button
          key={t.id}
          onClick={() => onChange(t.id)}
          className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-lg text-sm font-semibold transition-colors ${
            active === t.id
              ? 'bg-primary text-white shadow-sm'
              : 'text-textMuted hover:text-text'
          }`}
        >
          <t.icon size={14} /> {t.label}
        </button>
      ))}
    </div>
  );
}

function EmptyState({ icon: Icon, label }) {
  return (
    <div className="text-center py-14 text-textSubtle">
      <Icon size={36} className="mx-auto mb-3 opacity-25" />
      <p className="text-sm">{label}</p>
    </div>
  );
}

// ── VACANTES ───────────────────────────────────────────────────────────────

const MODALIDAD_COLOR = {
  presencial: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  remoto:     'bg-green-500/10 text-green-400 border-green-500/20',
  hibrido:    'bg-purple-500/10 text-purple-400 border-purple-500/20',
};

const VACA_EMPTY = {
  titulo: '', descripcion: '', ubicacion: '', modalidad: 'presencial',
  salario_min: '', salario_max: '', requisitos: '', activo: true,
};

function VacanteForm({ initial = VACA_EMPTY, onSave, onCancel, saving }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target ? e.target.value : e }));

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Título del puesto">
          <input value={f.titulo} onChange={set('titulo')} placeholder="Agente de Ventas" className={inp} />
        </Field>
        <Field label="Ubicación">
          <input value={f.ubicacion} onChange={set('ubicacion')} placeholder="CDMX / Remoto" className={inp} />
        </Field>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <Field label="Modalidad">
          <select value={f.modalidad} onChange={set('modalidad')} className={inp}>
            <option value="presencial">Presencial</option>
            <option value="remoto">Remoto</option>
            <option value="hibrido">Híbrido</option>
          </select>
        </Field>
        <Field label="Salario mín. (MXN)">
          <input type="number" value={f.salario_min} onChange={set('salario_min')} placeholder="8000" className={inp} />
        </Field>
        <Field label="Salario máx. (MXN)">
          <input type="number" value={f.salario_max} onChange={set('salario_max')} placeholder="15000" className={inp} />
        </Field>
      </div>

      <Field label="Descripción">
        <textarea rows={3} value={f.descripcion} onChange={set('descripcion')}
          placeholder="¿De qué trata el puesto?" className={inp + ' resize-none'} />
      </Field>

      <Field label="Requisitos">
        <textarea rows={3} value={f.requisitos} onChange={set('requisitos')}
          placeholder="Edad, experiencia, habilidades..." className={inp + ' resize-none'} />
      </Field>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={f.activo}
          onChange={(e) => setF((p) => ({ ...p, activo: e.target.checked }))}
          className="w-4 h-4 rounded border-border text-primary" />
        <span className="text-sm text-textMuted">Vacante activa (visible para el bot)</span>
      </label>

      <div className="flex gap-2 pt-1">
        <button onClick={() => onSave(f)} disabled={saving}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50">
          {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} Guardar
        </button>
        <button onClick={onCancel}
          className="flex items-center gap-1.5 border border-border text-sm text-textMuted px-4 py-2 rounded-lg hover:text-text transition-colors">
          <X size={13} /> Cancelar
        </button>
      </div>
    </div>
  );
}

function VacanteCard({ v, onUpdate, onDelete }) {
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(false);

  const salario = v.salario_min || v.salario_max
    ? `$${Number(v.salario_min || 0).toLocaleString()} – $${Number(v.salario_max || 0).toLocaleString()}`
    : null;

  return (
    <div className="bg-surface border border-border rounded-2xl overflow-hidden">
      <div className="flex items-start gap-3 px-4 py-4">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            <p className="text-sm font-semibold text-text">{v.titulo}</p>
            <span className={`text-2xs font-bold px-2 py-0.5 rounded-full border ${MODALIDAD_COLOR[v.modalidad] ?? 'bg-surface text-textMuted border-border'}`}>
              {v.modalidad}
            </span>
            {!v.activo && <span className="text-2xs text-textSubtle border border-border rounded-full px-2 py-0.5">Inactiva</span>}
          </div>
          <div className="flex items-center gap-3 text-xs text-textSubtle flex-wrap">
            {v.ubicacion && <span className="flex items-center gap-1"><MapPin size={11} />{v.ubicacion}</span>}
            {salario && <span className="flex items-center gap-1"><DollarSign size={11} />{salario}</span>}
          </div>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          <button onClick={() => { setEditing(true); setOpen(true); }}
            className="p-1.5 text-textSubtle hover:text-primary transition-colors"><Pencil size={14} /></button>
          <button onClick={() => onDelete(v.id)}
            className="p-1.5 text-textSubtle hover:text-danger transition-colors"><Trash2 size={14} /></button>
          <button onClick={() => { setOpen((o) => !o); setEditing(false); }}
            className="p-1.5 text-textSubtle hover:text-text transition-colors">
            {open ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
          </button>
        </div>
      </div>

      {open && (
        <div className="px-4 pb-4 border-t border-border pt-3">
          {editing ? (
            <VacanteForm
              initial={{ ...v }}
              onSave={(data) => { onUpdate(v.id, data); setEditing(false); setOpen(false); }}
              onCancel={() => setEditing(false)}
            />
          ) : (
            <div className="space-y-2 text-xs text-textMuted">
              {v.descripcion && <p className="leading-relaxed">{v.descripcion}</p>}
              {v.requisitos  && <p className="leading-relaxed"><strong className="text-textSubtle">Requisitos:</strong> {v.requisitos}</p>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function VacantesTab() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const { data: vacantes = [], isLoading } = useQuery({ queryKey: ['vacantes'], queryFn: fetchVacantes });

  const createMut = useMutation({ mutationFn: createVacante, onSuccess: () => { qc.invalidateQueries({ queryKey: ['vacantes'] }); setCreating(false); } });
  const updateMut = useMutation({ mutationFn: ([id, d]) => updateVacante(id, d), onSuccess: () => qc.invalidateQueries({ queryKey: ['vacantes'] }) });
  const deleteMut = useMutation({ mutationFn: deleteVacante, onSuccess: () => qc.invalidateQueries({ queryKey: ['vacantes'] }) });

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-3 py-2 rounded-lg hover:opacity-90 transition-opacity">
          <Plus size={14} /> Nueva vacante
        </button>
      </div>

      {creating && (
        <div className="bg-surface border border-primary/30 rounded-2xl p-5">
          <p className="text-sm font-bold text-text mb-3">Nueva vacante</p>
          <VacanteForm
            onSave={(data) => createMut.mutate(data)}
            onCancel={() => setCreating(false)}
            saving={createMut.isPending}
          />
        </div>
      )}

      {isLoading
        ? <div className="flex justify-center py-12"><Loader2 size={24} className="animate-spin text-primary" /></div>
        : vacantes.length === 0 && !creating
          ? <EmptyState icon={Briefcase} label="Sin vacantes. Crea una para que el bot pueda informar a los candidatos." />
          : <div className="space-y-3">{vacantes.map((v) => (
              <VacanteCard key={v.id} v={v}
                onUpdate={(id, d) => updateMut.mutate([id, d])}
                onDelete={(id) => deleteMut.mutate(id)}
              />
            ))}</div>
      }
    </div>
  );
}

// ── PLANTILLAS ─────────────────────────────────────────────────────────────

const TIPO_COLOR = {
  saludo:       'bg-blue-500/10 text-blue-400 border-blue-500/20',
  seguimiento:  'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
  oferta:       'bg-green-500/10 text-green-400 border-green-500/20',
  rechazo:      'bg-red-500/10 text-red-400 border-red-500/20',
  entrevista:   'bg-purple-500/10 text-purple-400 border-purple-500/20',
  otro:         'bg-surface text-textMuted border-border',
};

const VARIABLES = ['{{nombre}}', '{{vacante}}', '{{ciudad}}', '{{salario}}', '{{empresa}}', '{{fecha}}'];

const PLANT_EMPTY = { nombre: '', tipo: 'saludo', contenido: '', activo: true };

function PlantillaForm({ initial = PLANT_EMPTY, onSave, onCancel, saving }) {
  const [f, setF] = useState(initial);
  const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target ? e.target.value : e }));
  const insertVar = (v) => setF((p) => ({ ...p, contenido: p.contenido + v }));

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3">
        <Field label="Nombre de la plantilla">
          <input value={f.nombre} onChange={set('nombre')} placeholder="Saludo inicial" className={inp} />
        </Field>
        <Field label="Tipo">
          <select value={f.tipo} onChange={set('tipo')} className={inp}>
            {Object.keys(TIPO_COLOR).map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
        </Field>
      </div>

      <Field label="Contenido del mensaje">
        <textarea rows={5} value={f.contenido} onChange={set('contenido')}
          placeholder="Hola {{nombre}}, te contactamos de {{empresa}} sobre la vacante de {{vacante}}..."
          className={inp + ' resize-none'} />
      </Field>

      <div className="space-y-1">
        <p className="text-2xs font-semibold tracking-widest text-textMuted uppercase">Variables disponibles</p>
        <div className="flex flex-wrap gap-1.5">
          {VARIABLES.map((v) => (
            <button key={v} onClick={() => insertVar(v)} type="button"
              className="text-xs font-mono px-2 py-0.5 bg-primary/10 text-primary border border-primary/20 rounded hover:bg-primary/20 transition-colors">
              {v}
            </button>
          ))}
        </div>
      </div>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={f.activo}
          onChange={(e) => setF((p) => ({ ...p, activo: e.target.checked }))}
          className="w-4 h-4 rounded border-border text-primary" />
        <span className="text-sm text-textMuted">Plantilla activa</span>
      </label>

      <div className="flex gap-2 pt-1">
        <button onClick={() => onSave(f)} disabled={saving}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-50">
          {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} Guardar
        </button>
        <button onClick={onCancel}
          className="flex items-center gap-1.5 border border-border text-sm text-textMuted px-4 py-2 rounded-lg hover:text-text transition-colors">
          <X size={13} /> Cancelar
        </button>
      </div>
    </div>
  );
}

function PlantillaCard({ p, onUpdate, onDelete }) {
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(false);

  return (
    <div className="bg-surface border border-border rounded-2xl overflow-hidden">
      <div className="flex items-center gap-3 px-4 py-3">
        <span className={`text-2xs font-bold px-2 py-0.5 rounded-full border ${TIPO_COLOR[p.tipo] ?? TIPO_COLOR.otro}`}>{p.tipo}</span>
        <p className="flex-1 text-sm font-semibold text-text truncate">{p.nombre}</p>
        {!p.activo && <span className="text-2xs text-textSubtle border border-border rounded-full px-2 py-0.5">Inactiva</span>}
        <div className="flex items-center gap-1">
          <button onClick={() => { setEditing(true); setOpen(true); }} className="p-1.5 text-textSubtle hover:text-primary transition-colors"><Pencil size={14} /></button>
          <button onClick={() => onDelete(p.id)} className="p-1.5 text-textSubtle hover:text-danger transition-colors"><Trash2 size={14} /></button>
          <button onClick={() => { setOpen((o) => !o); setEditing(false); }} className="p-1.5 text-textSubtle hover:text-text transition-colors">
            {open ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
          </button>
        </div>
      </div>

      {open && (
        <div className="px-4 pb-4 border-t border-border pt-3">
          {editing ? (
            <PlantillaForm
              initial={{ ...p }}
              onSave={(data) => { onUpdate(p.id, data); setEditing(false); setOpen(false); }}
              onCancel={() => setEditing(false)}
            />
          ) : (
            <pre className="text-xs text-textMuted whitespace-pre-wrap bg-bg rounded-lg p-3 border border-border leading-relaxed">{p.contenido}</pre>
          )}
        </div>
      )}
    </div>
  );
}

function PlantillasTab() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const { data: plantillas = [], isLoading } = useQuery({ queryKey: ['plantillas'], queryFn: fetchPlantillas });

  const createMut = useMutation({ mutationFn: createPlantilla, onSuccess: () => { qc.invalidateQueries({ queryKey: ['plantillas'] }); setCreating(false); } });
  const updateMut = useMutation({ mutationFn: ([id, d]) => updatePlantilla(id, d), onSuccess: () => qc.invalidateQueries({ queryKey: ['plantillas'] }) });
  const deleteMut = useMutation({ mutationFn: deletePlantilla, onSuccess: () => qc.invalidateQueries({ queryKey: ['plantillas'] }) });

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-3 py-2 rounded-lg hover:opacity-90 transition-opacity">
          <Plus size={14} /> Nueva plantilla
        </button>
      </div>

      {creating && (
        <div className="bg-surface border border-primary/30 rounded-2xl p-5">
          <p className="text-sm font-bold text-text mb-3">Nueva plantilla</p>
          <PlantillaForm onSave={(d) => createMut.mutate(d)} onCancel={() => setCreating(false)} saving={createMut.isPending} />
        </div>
      )}

      {isLoading
        ? <div className="flex justify-center py-12"><Loader2 size={24} className="animate-spin text-primary" /></div>
        : plantillas.length === 0 && !creating
          ? <EmptyState icon={FileText} label="Sin plantillas. Crea mensajes reutilizables con variables dinámicas." />
          : <div className="space-y-3">{plantillas.map((p) => (
              <PlantillaCard key={p.id} p={p}
                onUpdate={(id, d) => updateMut.mutate([id, d])}
                onDelete={(id) => deleteMut.mutate(id)}
              />
            ))}</div>
      }

      <div className="bg-surface border border-border rounded-2xl p-4 text-xs text-textMuted space-y-1">
        <p className="font-semibold text-text mb-1">Variables en las plantillas</p>
        <div className="grid grid-cols-2 gap-1">
          {VARIABLES.map((v) => <span key={v} className="font-mono text-primary">{v}</span>)}
        </div>
        <p className="text-textSubtle pt-1">El bot sustituye estas variables automáticamente al enviar el mensaje.</p>
      </div>
    </div>
  );
}

// ── FLUJOS ─────────────────────────────────────────────────────────────────

const PASO_TIPOS = ['mensaje', 'pregunta', 'condicion'];

const PASO_COLOR = {
  mensaje:   'bg-blue-500/10 text-blue-400',
  pregunta:  'bg-amber-500/10 text-amber-400',
  condicion: 'bg-purple-500/10 text-purple-400',
};

function PasoEditor({ pasos, onChange }) {
  const add = () => onChange([...pasos, { tipo: 'mensaje', contenido: '', captura: '' }]);
  const remove = (i) => onChange(pasos.filter((_, idx) => idx !== i));
  const update = (i, k, v) => onChange(pasos.map((p, idx) => idx === i ? { ...p, [k]: v } : p));

  return (
    <div className="space-y-2">
      {pasos.map((paso, i) => (
        <div key={i} className="flex gap-2 items-start bg-bg border border-border rounded-xl p-3">
          <span className="text-xs text-textSubtle font-mono mt-2 w-5 shrink-0">{i + 1}</span>
          <div className="flex-1 space-y-2">
            <div className="flex gap-2">
              <select value={paso.tipo} onChange={(e) => update(i, 'tipo', e.target.value)}
                className="bg-surface border border-border rounded-lg px-2 py-1 text-xs text-text focus:outline-none focus:ring-1 focus:ring-primary">
                {PASO_TIPOS.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
              {paso.tipo === 'pregunta' && (
                <input value={paso.captura ?? ''} onChange={(e) => update(i, 'captura', e.target.value)}
                  placeholder="variable (ej: edad)" className="flex-1 bg-surface border border-border rounded-lg px-2 py-1 text-xs text-text placeholder-textSubtle focus:outline-none focus:ring-1 focus:ring-primary" />
              )}
            </div>
            <textarea rows={2} value={paso.contenido} onChange={(e) => update(i, 'contenido', e.target.value)}
              placeholder={paso.tipo === 'condicion' ? 'score >= 70 → Excelente perfil...' : 'Texto del mensaje o pregunta'}
              className={inp + ' resize-none text-xs'} />
          </div>
          <button onClick={() => remove(i)} className="p-1 text-textSubtle hover:text-danger transition-colors mt-1">
            <X size={13} />
          </button>
        </div>
      ))}
      <button onClick={add}
        className="w-full py-2 border border-dashed border-border rounded-xl text-xs text-textSubtle hover:text-primary hover:border-primary transition-colors flex items-center justify-center gap-1">
        <Plus size={12} /> Agregar paso
      </button>
    </div>
  );
}

const FLUJO_EMPTY = { nombre: '', trigger_keyword: '', pasos: [], activo: true };

function FlujoForm({ initial = FLUJO_EMPTY, onSave, onCancel, saving }) {
  const [f, setF] = useState({ ...FLUJO_EMPTY, ...initial });

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3">
        <Field label="Nombre del flujo">
          <input value={f.nombre} onChange={(e) => setF((p) => ({ ...p, nombre: e.target.value }))}
            placeholder="Bienvenida a candidato" className={inp} />
        </Field>
        <Field label="Palabras clave de activación">
          <input value={f.trigger_keyword} onChange={(e) => setF((p) => ({ ...p, trigger_keyword: e.target.value }))}
            placeholder="trabajo, empleo, vacante" className={inp} />
        </Field>
      </div>

      <Field label="Pasos del flujo">
        <PasoEditor pasos={f.pasos} onChange={(p) => setF((prev) => ({ ...prev, pasos: p }))} />
      </Field>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={f.activo}
          onChange={(e) => setF((p) => ({ ...p, activo: e.target.checked }))}
          className="w-4 h-4 rounded border-border text-primary" />
        <span className="text-sm text-textMuted">Flujo activo</span>
      </label>

      <div className="flex gap-2 pt-1">
        <button onClick={() => onSave(f)} disabled={saving}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-50">
          {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} Guardar
        </button>
        <button onClick={onCancel}
          className="flex items-center gap-1.5 border border-border text-sm text-textMuted px-4 py-2 rounded-lg hover:text-text transition-colors">
          <X size={13} /> Cancelar
        </button>
      </div>
    </div>
  );
}

function FlujoCard({ flujo, onUpdate, onDelete }) {
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(false);

  return (
    <div className="bg-surface border border-border rounded-2xl overflow-hidden">
      <div className="flex items-center gap-3 px-4 py-3">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold text-text truncate">{flujo.nombre}</p>
          {flujo.trigger_keyword && (
            <p className="text-xs text-textSubtle flex items-center gap-1 mt-0.5">
              <Hash size={10} /> {flujo.trigger_keyword}
            </p>
          )}
        </div>
        <span className="text-xs text-textSubtle">{flujo.pasos?.length ?? 0} pasos</span>
        <div className={`w-2 h-2 rounded-full ${flujo.activo ? 'bg-green-500' : 'bg-textSubtle'}`} />
        <div className="flex items-center gap-1">
          <button onClick={() => { setEditing(true); setOpen(true); }} className="p-1.5 text-textSubtle hover:text-primary transition-colors"><Pencil size={14} /></button>
          <button onClick={() => onDelete(flujo.id)} className="p-1.5 text-textSubtle hover:text-danger transition-colors"><Trash2 size={14} /></button>
          <button onClick={() => { setOpen((o) => !o); setEditing(false); }} className="p-1.5 text-textSubtle hover:text-text transition-colors">
            {open ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
          </button>
        </div>
      </div>

      {open && (
        <div className="px-4 pb-4 border-t border-border pt-3">
          {editing ? (
            <FlujoForm
              initial={{ ...flujo }}
              onSave={(data) => { onUpdate(flujo.id, data); setEditing(false); setOpen(false); }}
              onCancel={() => setEditing(false)}
            />
          ) : (
            <div className="space-y-1.5">
              {(flujo.pasos ?? []).map((p, i) => (
                <div key={i} className="flex items-start gap-2">
                  <span className="text-2xs font-mono text-textSubtle mt-1 w-4 shrink-0">{i + 1}.</span>
                  <span className={`text-2xs font-bold px-1.5 py-0.5 rounded mt-0.5 shrink-0 ${PASO_COLOR[p.tipo] ?? 'bg-surface text-textMuted'}`}>{p.tipo}</span>
                  <p className="text-xs text-textMuted leading-relaxed">{p.contenido}</p>
                </div>
              ))}
              {flujo.pasos?.length === 0 && <p className="text-xs text-textSubtle">Sin pasos configurados.</p>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function FlujosTab() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const { data: flujos = [], isLoading } = useQuery({ queryKey: ['flujos'], queryFn: fetchFlujos });

  const createMut = useMutation({ mutationFn: createFlujo, onSuccess: () => { qc.invalidateQueries({ queryKey: ['flujos'] }); setCreating(false); } });
  const updateMut = useMutation({ mutationFn: ([id, d]) => updateFlujo(id, d), onSuccess: () => qc.invalidateQueries({ queryKey: ['flujos'] }) });
  const deleteMut = useMutation({ mutationFn: deleteFlujo, onSuccess: () => qc.invalidateQueries({ queryKey: ['flujos'] }) });

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setCreating(true)}
          className="flex items-center gap-1.5 bg-primary text-white text-sm font-semibold px-3 py-2 rounded-lg hover:opacity-90 transition-opacity">
          <Plus size={14} /> Nuevo flujo
        </button>
      </div>

      {creating && (
        <div className="bg-surface border border-primary/30 rounded-2xl p-5">
          <p className="text-sm font-bold text-text mb-3">Nuevo flujo de conversación</p>
          <FlujoForm onSave={(d) => createMut.mutate(d)} onCancel={() => setCreating(false)} saving={createMut.isPending} />
        </div>
      )}

      {isLoading
        ? <div className="flex justify-center py-12"><Loader2 size={24} className="animate-spin text-primary" /></div>
        : flujos.length === 0 && !creating
          ? <EmptyState icon={GitBranch} label="Sin flujos. Define secuencias de mensajes que el bot seguirá automáticamente." />
          : <div className="space-y-3">{flujos.map((f) => (
              <FlujoCard key={f.id} flujo={f}
                onUpdate={(id, d) => updateMut.mutate([id, d])}
                onDelete={(id) => deleteMut.mutate(id)}
              />
            ))}</div>
      }

      <div className="bg-surface border border-border rounded-2xl p-4 text-xs text-textMuted">
        <p className="font-semibold text-text mb-2">Tipos de paso</p>
        <div className="space-y-1">
          <div><span className="font-bold text-blue-400">mensaje</span> — el bot envía texto sin esperar respuesta</div>
          <div><span className="font-bold text-amber-400">pregunta</span> — el bot pregunta y guarda la respuesta en una variable</div>
          <div><span className="font-bold text-purple-400">condicion</span> — el bot evalúa una condición (ej: score &gt;= 70) y bifurca el flujo</div>
        </div>
      </div>
    </div>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────

const TABS = [
  { id: 'vacantes',   label: 'Vacantes',   icon: Briefcase  },
  { id: 'plantillas', label: 'Plantillas', icon: FileText   },
  { id: 'flujos',     label: 'Flujos',     icon: GitBranch  },
];

export default function Vacantes() {
  const [tab, setTab] = useState('vacantes');

  return (
    <div className="max-w-2xl">
      <div className="flex items-center gap-3 mb-6">
        <Briefcase size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Reclutamiento</h1>
      </div>

      <Tabs tabs={TABS} active={tab} onChange={setTab} />

      {tab === 'vacantes'   && <VacantesTab />}
      {tab === 'plantillas' && <PlantillasTab />}
      {tab === 'flujos'     && <FlujosTab />}
    </div>
  );
}
