import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Activity, AudioLines, BookOpenText, ShieldAlert, UserRoundCog } from 'lucide-react';
import { askKnowledgeBase, fetchKnowledgeDocuments, fetchPlaybooks, fetchRecruiters, fetchSupervisorRealtime, upsertPlaybook, uploadKnowledgeDocument } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { Card } from '../components/ui/Card';

export default function Supervisor() {
  const { activeCompanyId } = useSession();
  const queryClient = useQueryClient();
  const [question, setQuestion] = useState('');
  const [title, setTitle] = useState('');
  const [document, setDocument] = useState(null);
  const [playbook, setPlaybook] = useState({ recruiter_id: '', name: '', trigger_stage: 'contactado', message_template: '' });

  const supervisorQuery = useQuery({
    queryKey: ['supervisor-realtime', activeCompanyId],
    queryFn: () => fetchSupervisorRealtime({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
    refetchInterval: 15000,
  });

  const docsQuery = useQuery({
    queryKey: ['knowledge-docs', activeCompanyId],
    queryFn: () => fetchKnowledgeDocuments({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
  });

  const recruitersQuery = useQuery({
    queryKey: ['playbook-recruiters', activeCompanyId],
    queryFn: () => fetchRecruiters({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
  });

  const playbooksQuery = useQuery({
    queryKey: ['playbooks', activeCompanyId],
    queryFn: () => fetchPlaybooks({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
  });

  const askMutation = useMutation({ mutationFn: () => askKnowledgeBase({ empresa_id: activeCompanyId, question }) });
  const uploadMutation = useMutation({
    mutationFn: () => uploadKnowledgeDocument({ empresa_id: activeCompanyId, title, document }),
    onSuccess: () => {
      setTitle('');
      setDocument(null);
      queryClient.invalidateQueries({ queryKey: ['knowledge-docs', activeCompanyId] });
    },
  });
  const playbookMutation = useMutation({
    mutationFn: () => upsertPlaybook({ ...playbook, empresa_id: activeCompanyId, recruiter_id: Number(playbook.recruiter_id) }),
    onSuccess: () => {
      setPlaybook({ recruiter_id: '', name: '', trigger_stage: 'contactado', message_template: '' });
      queryClient.invalidateQueries({ queryKey: ['playbooks', activeCompanyId] });
    },
  });

  const data = supervisorQuery.data ?? { critical_notifications: [], voice_queue: [], recruiter_playbooks: [], active_conversations: [] };
  const recruiters = recruitersQuery.data?.items ?? [];
  const documents = docsQuery.data?.items ?? [];
  const playbooks = playbooksQuery.data?.items ?? [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <p className="text-xs uppercase tracking-[0.24em] text-primary">Fase 9</p>
        <h1 className="font-display text-2xl font-bold text-text">Supervisor Center</h1>
        <p className="text-sm text-textMuted">Monitoreo en tiempo real, voz, RAG interno y playbooks por recruiter.</p>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.9fr_1.1fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4"><ShieldAlert size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">Alertas críticas</h3></div>
          <div className="space-y-3">
            {data.critical_notifications.length === 0 ? <p className="text-sm text-textMuted">Sin alertas críticas.</p> : data.critical_notifications.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <p className="text-sm font-semibold text-text">{item.title}</p>
                <p className="text-sm text-textMuted mt-1">{item.message}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4"><Activity size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">Conversaciones activas</h3></div>
          <div className="space-y-3">
            {data.active_conversations.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <p className="text-sm font-semibold text-text">{item.nombre ?? `Lead ${item.id}`}</p>
                <p className="text-xs text-textMuted">{item.current_stage} · {item.prioridad} · {item.recruiter_nombre ?? 'Sin asignar'}</p>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.8fr_1.2fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4"><AudioLines size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">Notas de voz</h3></div>
          <div className="space-y-3">
            {data.voice_queue.length === 0 ? <p className="text-sm text-textMuted">Sin notas de voz.</p> : data.voice_queue.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <p className="text-sm font-semibold text-text">{item.lead_nombre}</p>
                <p className="text-xs text-textMuted">{item.recruiter_nombre ?? 'Sin recruiter'} · {item.status}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4"><BookOpenText size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">RAG interno</h3></div>
          <div className="space-y-4">
            <div className="rounded-xl border border-border bg-bg/40 p-4 space-y-3">
              <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Título del documento" className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60" />
              <input type="file" onChange={(e) => setDocument(e.target.files?.[0] ?? null)} className="w-full text-sm text-textMuted" />
              <button type="button" className="btn-primary" onClick={() => uploadMutation.mutate()} disabled={uploadMutation.isPending || !document || !title.trim()}>Subir documento</button>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4 space-y-3">
              <textarea value={question} onChange={(e) => setQuestion(e.target.value)} rows={3} placeholder="Pregunta a la base interna..." className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60" />
              <button type="button" className="btn-ghost" onClick={() => askMutation.mutate()} disabled={askMutation.isPending || !question.trim()}>Preguntar</button>
              {askMutation.data && (
                <div className="rounded-xl border border-primary/20 bg-primary/5 p-4">
                  <p className="text-sm font-semibold text-text">{askMutation.data.answer}</p>
                  <p className="text-xs text-textMuted mt-2">Confianza: {askMutation.data.confidence}% · Fuentes: {(askMutation.data.sources ?? []).join(', ')}</p>
                </div>
              )}
            </div>
            <div className="space-y-2">
              {documents.map((doc) => (
                <div key={doc.id} className="rounded-xl border border-border bg-bg/40 p-3">
                  <p className="text-sm font-medium text-text">{doc.title}</p>
                  <p className="text-xs text-textMuted">{doc.source_filename} · {doc.status}</p>
                </div>
              ))}
            </div>
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.85fr_1.15fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4"><UserRoundCog size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">Nuevo playbook recruiter</h3></div>
          <div className="space-y-3">
            <select value={playbook.recruiter_id} onChange={(e) => setPlaybook((c) => ({ ...c, recruiter_id: e.target.value }))} className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60">
              <option value="">Selecciona recruiter</option>
              {recruiters.map((recruiter) => <option key={recruiter.id} value={recruiter.id}>{recruiter.nombre}</option>)}
            </select>
            <input value={playbook.name} onChange={(e) => setPlaybook((c) => ({ ...c, name: e.target.value }))} placeholder="Nombre del playbook" className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60" />
            <input value={playbook.trigger_stage} onChange={(e) => setPlaybook((c) => ({ ...c, trigger_stage: e.target.value }))} placeholder="Etapa trigger" className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60" />
            <textarea value={playbook.message_template} onChange={(e) => setPlaybook((c) => ({ ...c, message_template: e.target.value }))} rows={5} placeholder="Template del mensaje" className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60" />
            <button type="button" className="btn-primary" onClick={() => playbookMutation.mutate()} disabled={playbookMutation.isPending}>Guardar playbook</button>
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4"><UserRoundCog size={16} className="text-primary" /><h3 className="text-sm font-semibold text-text">Playbooks por recruiter</h3></div>
          <div className="space-y-3">
            {playbooks.length === 0 ? <p className="text-sm text-textMuted">Sin playbooks configurados.</p> : playbooks.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <p className="text-sm font-semibold text-text">{item.name}</p>
                <p className="text-xs text-textMuted">{item.recruiter_nombre} · trigger: {item.trigger_stage} · {item.active ? 'activo' : 'inactivo'}</p>
                <p className="mt-2 text-sm text-textMuted">{item.message_template}</p>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
