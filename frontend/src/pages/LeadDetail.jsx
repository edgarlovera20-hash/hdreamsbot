import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, AudioLines, CalendarClock, CircleUserRound, ClipboardList, Clock3, MessageSquarePlus, Sparkles, StickyNote } from 'lucide-react';
import { autoAssignLeadCopilot, confirmInterview, createInterview, createLeadNote, fetchInterviewSlots, fetchLeadCopilot, fetchLeadDetail, markInterviewNoShow, uploadLeadVoiceNote } from '../lib/api';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { useSession } from '../context/SessionContext';

function formatDateTime(value, options = {}) {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-MX', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    ...options,
  });
}

export default function LeadDetail() {
  const { activeCompanyId, currentRecruiterId } = useSession();
  const { id } = useParams();
  const queryClient = useQueryClient();
  const [note, setNote] = useState('');
  const [slotDate, setSlotDate] = useState(new Date().toISOString().slice(0, 10));
  const [voiceFile, setVoiceFile] = useState(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['lead-detail', id],
    queryFn: () => fetchLeadDetail(id),
    enabled: Boolean(id),
    staleTime: 30_000,
  });

  const noteMutation = useMutation({
    mutationFn: (payload) => createLeadNote(id, payload),
    onSuccess: () => {
      setNote('');
      queryClient.invalidateQueries({ queryKey: ['lead-detail', id] });
    },
  });

  const leadCompanyId = Number(data?.lead?.empresa_id ?? activeCompanyId);

  const { data: slotData, isLoading: isLoadingSlots } = useQuery({
    queryKey: ['interview-slots', leadCompanyId, slotDate],
    queryFn: () => fetchInterviewSlots({ empresa_id: leadCompanyId, date: slotDate }),
    enabled: Boolean(leadCompanyId),
    staleTime: 30_000,
  });

  const scheduleMutation = useMutation({
    mutationFn: (payload) => createInterview(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lead-detail', id] });
      queryClient.invalidateQueries({ queryKey: ['interview-slots', leadCompanyId, slotDate] });
    },
  });

  const confirmMutation = useMutation({
    mutationFn: (interviewId) => confirmInterview(interviewId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-detail', id] }),
  });

  const noShowMutation = useMutation({
    mutationFn: (interviewId) => markInterviewNoShow(interviewId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-detail', id] }),
  });

  const copilotMutation = useMutation({
    mutationFn: () => fetchLeadCopilot(id),
  });

  const autoAssignMutation = useMutation({
    mutationFn: () => autoAssignLeadCopilot(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-detail', id] }),
  });

  const voiceMutation = useMutation({
    mutationFn: (file) => uploadLeadVoiceNote(id, file),
    onSuccess: () => {
      setVoiceFile(null);
      queryClient.invalidateQueries({ queryKey: ['lead-detail', id] });
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (isError || !data?.lead) {
    return (
      <div className="space-y-4">
        <Link to="/leads" className="inline-flex items-center gap-2 text-sm text-textMuted hover:text-text">
          <ArrowLeft size={14} />
          Volver a leads
        </Link>
        <p className="text-red-400 text-sm">No se pudo cargar el detalle del lead.</p>
      </div>
    );
  }

  const { lead, notes = [], events = [], interview, voice_notes: voiceNotes = [] } = data;
  const slots = slotData?.slots ?? [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <Link to="/leads" className="inline-flex items-center gap-2 text-sm text-textMuted hover:text-text mb-3">
            <ArrowLeft size={14} />
            Volver a leads
          </Link>
          <h1 className="font-display text-2xl font-bold text-text">{lead.nombre ?? 'Lead sin nombre'}</h1>
          <p className="text-sm text-textMuted">{lead.seccion} · {lead.canal_user_id}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge value={lead.canal} />
          <Badge value={lead.estado} />
          <Badge value={lead.prioridad} />
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[1.1fr_1fr_0.95fr] gap-6">
        <div className="space-y-6">
          <Card>
            <div className="flex items-center gap-2 mb-4">
              <CircleUserRound size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Ficha 360</h3>
            </div>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Telefono</p>
                <p className="text-text">{lead.telefono ?? '—'}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Email</p>
                <p className="text-text break-all">{lead.email ?? '—'}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Edad</p>
                <p className="text-text">{lead.edad ? `${lead.edad} años` : '—'}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Etapa operativa</p>
                <p className="text-text">{lead.current_stage ?? '—'}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Ultima interaccion</p>
                <p className="text-text">{formatDateTime(lead.ultima_interaccion)}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Proxima accion</p>
                <p className="text-text">{lead.next_action_type ?? '—'}</p>
              </div>
            </div>

            <div className="mt-5 pt-5 border-t border-border">
              <p className="text-textSubtle text-xs uppercase tracking-wide mb-2">Metadata</p>
              <div className="flex flex-wrap gap-2">
                {Object.entries(lead.metadata ?? {}).length ? Object.entries(lead.metadata ?? {}).map(([key, value]) => (
                  <span key={key} className="px-2.5 py-1 rounded-full text-xs bg-surfaceHover text-text border border-border">
                    {key}: {String(value)}
                  </span>
                )) : <span className="text-sm text-textMuted">Sin metadata cargada.</span>}
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center gap-2 mb-4">
              <ClipboardList size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Scoring y prioridad</h3>
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Candidato</p>
                <p className="text-2xl font-display text-text">{Number(lead.score_candidato ?? 0).toFixed(0)}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Contratacion</p>
                <p className="text-2xl font-display text-text">{Number(lead.score_contratacion ?? 0).toFixed(0)}</p>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide">Prioridad</p>
                <p className="text-2xl font-display text-primary">{Number(lead.score_prioridad ?? 0).toFixed(0)}</p>
              </div>
            </div>
            <p className="text-sm text-textMuted mt-4">{lead.razonamiento ?? 'Sin razonamiento disponible.'}</p>
            <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide mb-2">Factores positivos</p>
                <div className="flex flex-wrap gap-2">
                  {(lead.factores_positivos ?? []).length ? lead.factores_positivos.map((item) => (
                    <span key={item} className="px-2.5 py-1 rounded-full text-xs bg-success/10 text-success border border-success/20">{item}</span>
                  )) : <span className="text-sm text-textMuted">Sin factores.</span>}
                </div>
              </div>
              <div>
                <p className="text-textSubtle text-xs uppercase tracking-wide mb-2">Factores negativos</p>
                <div className="flex flex-wrap gap-2">
                  {(lead.factores_negativos ?? []).length ? lead.factores_negativos.map((item) => (
                    <span key={item} className="px-2.5 py-1 rounded-full text-xs bg-danger/10 text-danger border border-danger/20">{item}</span>
                  )) : <span className="text-sm text-textMuted">Sin factores.</span>}
                </div>
              </div>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <div className="flex items-center gap-2 mb-4">
              <StickyNote size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Notas internas</h3>
            </div>

            <form
              onSubmit={(event) => {
                event.preventDefault();
                if (!note.trim()) return;
                noteMutation.mutate({ note, recruiter_id: currentRecruiterId ?? 1 });
              }}
              className="space-y-3 mb-5"
            >
              <textarea
                value={note}
                onChange={(event) => setNote(event.target.value)}
                rows={4}
                placeholder="Agregar contexto para el siguiente contacto, objeciones o acuerdos..."
                className="w-full rounded-xl text-sm bg-bg border border-border p-3 text-text placeholder:text-textMuted focus:outline-none focus:border-primary/60"
              />
              <div className="flex items-center justify-between">
                <span className="text-xs text-textSubtle">Las notas quedan en el timeline del lead.</span>
                <button type="submit" className="btn-primary inline-flex items-center gap-2" disabled={noteMutation.isPending}>
                  <MessageSquarePlus size={14} />
                  Guardar nota
                </button>
              </div>
            </form>

            <div className="space-y-3">
              {notes.length ? notes.map((item) => (
                <div key={item.id} className="rounded-xl border border-border p-4 bg-bg/40">
                  <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-sm font-medium text-text">{item.recruiter_nombre}</p>
                    <span className="text-xs text-textSubtle">{formatDateTime(item.created_at)}</span>
                  </div>
                  <p className="text-sm text-textMuted whitespace-pre-wrap">{item.note}</p>
                </div>
              )) : <p className="text-sm text-textMuted">Aun no hay notas para este lead.</p>}
            </div>
          </Card>

          <Card>
            <div className="flex items-center gap-2 mb-4">
              <AudioLines size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Notas de voz</h3>
            </div>
            <div className="space-y-3 mb-4">
              <input type="file" accept="audio/*" onChange={(event) => setVoiceFile(event.target.files?.[0] ?? null)} className="w-full text-sm text-textMuted" />
              <button type="button" className="btn-ghost" onClick={() => voiceFile && voiceMutation.mutate(voiceFile)} disabled={voiceMutation.isPending || !voiceFile}>
                Subir y transcribir
              </button>
            </div>
            <div className="space-y-3">
              {voiceNotes.length ? voiceNotes.map((item) => (
                <div key={item.id} className="rounded-xl border border-border p-4 bg-bg/40">
                  <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-sm font-medium text-text">{item.recruiter_nombre ?? 'Sistema'}</p>
                    <span className="text-xs text-textSubtle">{formatDateTime(item.created_at)}</span>
                  </div>
                  <p className="text-xs text-textMuted mb-2">{item.status}</p>
                  <p className="text-sm text-text whitespace-pre-wrap">{item.transcript}</p>
                </div>
              )) : <p className="text-sm text-textMuted">Sin notas de voz todavía.</p>}
            </div>
          </Card>

          <Card>
            <div className="flex items-center gap-2 mb-4">
              <Clock3 size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Timeline</h3>
            </div>
            <div className="space-y-3">
              {events.length ? events.map((item) => (
                <div key={item.id} className="rounded-xl border border-border p-4 bg-bg/40">
                  <div className="flex items-center justify-between gap-3 mb-1">
                    <p className="text-sm font-medium text-text">{item.event_label}</p>
                    <span className="text-xs text-textSubtle">{formatDateTime(item.created_at)}</span>
                  </div>
                  <p className="text-xs uppercase tracking-wide text-textSubtle">{item.event_type}</p>
                  {Object.keys(item.payload ?? {}).length > 0 && (
                    <pre className="mt-2 text-xs text-textMuted whitespace-pre-wrap break-words">{JSON.stringify(item.payload, null, 2)}</pre>
                  )}
                </div>
              )) : <p className="text-sm text-textMuted">Sin eventos registrados.</p>}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <div className="flex items-center gap-2 mb-4">
              <CalendarClock size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Entrevista</h3>
            </div>
            {interview ? (
              <div className="space-y-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-textMuted">Estado</span>
                  <span className="text-text font-medium">{interview.status}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-textMuted">Fecha</span>
                  <span className="text-text font-medium">{formatDateTime(`${interview.interview_date}T${interview.interview_time}`, { year: 'numeric' })}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-textMuted">Reclutador</span>
                  <span className="text-text font-medium">{interview.recruiter_nombre ?? 'Sin asignar'}</span>
                </div>
                <div>
                  <p className="text-textMuted">Ubicacion</p>
                  <p className="text-text">{interview.office_location}</p>
                </div>
                {interview.notes && (
                  <div>
                    <p className="text-textMuted">Notas</p>
                    <p className="text-text">{interview.notes}</p>
                  </div>
                )}
                <div className="flex gap-2 pt-2">
                  <button
                    className="btn-ghost"
                    onClick={() => confirmMutation.mutate(interview.id)}
                    disabled={confirmMutation.isPending || interview.status === 'confirmada'}
                  >
                    Confirmar
                  </button>
                  <button
                    className="btn-ghost"
                    onClick={() => noShowMutation.mutate(interview.id)}
                    disabled={noShowMutation.isPending || interview.status === 'no_show'}
                  >
                    No show
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <p className="text-sm text-textMuted">Este lead aun no tiene entrevista registrada.</p>
                <div className="space-y-3">
                  <div>
                    <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Fecha</label>
                    <input
                      type="date"
                      value={slotDate}
                      onChange={(event) => setSlotDate(event.target.value)}
                      className="w-full rounded-lg text-sm bg-bg border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
                    />
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Slots disponibles</p>
                    {isLoadingSlots ? (
                      <div className="flex items-center justify-center h-24">
                        <div className="w-6 h-6 border-2 border-primary border-t-transparent rounded-full animate-spin" />
                      </div>
                    ) : slots.length === 0 ? (
                      <p className="text-sm text-textMuted">No hay slots disponibles para esa fecha.</p>
                    ) : (
                      <div className="space-y-2 max-h-80 overflow-auto">
                        {slots.filter((slot) => Number(slot.available) > 0).map((slot) => (
                          <div key={slot.id} className="rounded-xl border border-border p-3 bg-bg/40 flex items-center justify-between gap-3">
                            <div>
                              <p className="text-sm font-medium text-text">{slot.slot_time}</p>
                              <p className="text-xs text-textMuted">{slot.recruiter_nombre ?? 'Sin reclutador'} · {slot.available} disponible(s)</p>
                            </div>
                            <button
                              className="btn-primary"
                              onClick={() => scheduleMutation.mutate({
                                lead_id: Number(id),
                                slot_id: slot.id,
                                office_location: 'Av. Tlahuac 3632 Int. A301, Col. Culhuacan, Iztapalapa, Ciudad de Mexico',
                              })}
                              disabled={scheduleMutation.isPending}
                            >
                              Agendar
                            </button>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}
          </Card>

          <Card>
            <div className="flex items-center gap-2 mb-4">
              <CircleUserRound size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Responsable</h3>
            </div>
            {lead.recruiter_nombre ? (
              <div className="space-y-2 text-sm">
                <p className="text-text font-medium">{lead.recruiter_nombre}</p>
                <p className="text-textMuted">{lead.recruiter_email ?? 'Sin email'}</p>
                <p className="text-textMuted">{lead.recruiter_telefono ?? 'Sin telefono'}</p>
              </div>
            ) : (
              <p className="text-sm text-textMuted">Sin reclutador asignado.</p>
            )}
            <div className="mt-4 pt-4 border-t border-border space-y-3">
              <div className="flex items-center gap-2">
                <Sparkles size={16} className="text-primary" />
                <h4 className="text-sm font-semibold text-text">Copiloto IA</h4>
              </div>
              <div className="flex gap-2">
                <button type="button" className="btn-ghost" onClick={() => copilotMutation.mutate()} disabled={copilotMutation.isPending}>
                  Analizar lead
                </button>
                <button type="button" className="btn-ghost" onClick={() => autoAssignMutation.mutate()} disabled={autoAssignMutation.isPending}>
                  Autoasignar
                </button>
              </div>
              {copilotMutation.data?.analysis && (
                <div className="rounded-xl border border-primary/20 bg-primary/5 p-4 space-y-2">
                  <p className="text-sm font-semibold text-text">{copilotMutation.data.analysis.summary}</p>
                  <p className="text-xs text-textMuted">Score conversación: {copilotMutation.data.analysis.conversation_score} · acción sugerida: {copilotMutation.data.analysis.recommended_action}</p>
                  {copilotMutation.data.analysis.suggested_reply && (
                    <p className="text-sm text-text">{copilotMutation.data.analysis.suggested_reply}</p>
                  )}
                </div>
              )}
              {autoAssignMutation.data?.assignment && (
                <p className="text-xs text-success">Asignado automáticamente a {autoAssignMutation.data.assignment.recruiter_nombre}.</p>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
