import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { MessageSquare, ArrowLeft, User, Clock } from 'lucide-react';
import { fetchConversaciones, fetchMensajes } from '../lib/api';

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const diff = (Date.now() - new Date(dateStr)) / 1000;
  if (diff < 60)    return 'Ahora';
  if (diff < 3600)  return `Hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `Hace ${Math.floor(diff / 3600)} h`;
  return `Hace ${Math.floor(diff / 86400)} d`;
}

const ESTADO_COLOR = {
  nuevo:                 'bg-blue-500/10 text-blue-400',
  contactado:            'bg-yellow-500/10 text-yellow-400',
  calificado:            'bg-purple-500/10 text-purple-400',
  entrevista_agendada:   'bg-indigo-500/10 text-indigo-400',
  entrevista_realizada:  'bg-teal-500/10 text-teal-400',
  contratado:            'bg-green-500/10 text-green-400',
  rechazado:             'bg-red-500/10 text-red-400',
  no_interesado:         'bg-gray-500/10 text-gray-400',
};

function ChatBubble({ msg }) {
  return (
    <div className="space-y-2">
      {/* Mensaje entrante */}
      {msg.pregunta && (
        <div className="flex justify-start">
          <div className="max-w-[75%] bg-surfaceHover rounded-2xl rounded-tl-sm px-4 py-2.5">
            <p className="text-sm text-text">{msg.pregunta}</p>
            <p className="text-2xs text-textSubtle mt-1">
              {new Date(msg.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}
            </p>
          </div>
        </div>
      )}
      {/* Respuesta bot */}
      {msg.respuesta && (
        <div className="flex justify-end">
          <div className="max-w-[75%] bg-primary/15 rounded-2xl rounded-tr-sm px-4 py-2.5">
            <p className="text-sm text-primary">{msg.respuesta}</p>
            <p className="text-2xs text-primary/50 mt-1 text-right">
              {new Date(msg.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

function ConversacionDetail({ conv, onBack }) {
  const { data: mensajes = [], isLoading } = useQuery({
    queryKey: ['mensajes', conv.canal_user_id],
    queryFn: () => fetchMensajes(conv.canal_user_id),
    refetchInterval: 8000,
  });

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center gap-3 mb-4">
        <button
          onClick={onBack}
          className="p-2 rounded-lg hover:bg-surfaceHover text-textMuted hover:text-text transition-colors"
        >
          <ArrowLeft size={18} />
        </button>
        <div className="w-9 h-9 rounded-full bg-primary/15 flex items-center justify-center shrink-0">
          <User size={16} className="text-primary" />
        </div>
        <div className="min-w-0">
          <p className="font-semibold text-text text-sm truncate">
            {conv.nombre || conv.canal_user_id}
          </p>
          {conv.telefono && (
            <p className="text-xs text-textMuted">{conv.telefono}</p>
          )}
        </div>
        {conv.estado && (
          <span className={`ml-auto text-2xs font-medium px-2 py-0.5 rounded-full ${ESTADO_COLOR[conv.estado] ?? 'bg-surface text-textMuted'}`}>
            {conv.estado.replace('_', ' ')}
          </span>
        )}
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto space-y-4 pr-1">
        {isLoading && (
          <div className="flex justify-center py-12">
            <div className="w-6 h-6 border-2 border-primary border-t-transparent rounded-full animate-spin" />
          </div>
        )}
        {!isLoading && mensajes.length === 0 && (
          <p className="text-center text-sm text-textSubtle py-12">Sin mensajes registrados</p>
        )}
        {mensajes.map((m) => <ChatBubble key={m.id} msg={m} />)}
      </div>
    </div>
  );
}

export default function Conversaciones() {
  const [selected, setSelected] = useState(null);

  const { data: lista = [], isLoading } = useQuery({
    queryKey: ['conversaciones'],
    queryFn: fetchConversaciones,
    refetchInterval: 15_000,
  });

  if (selected) {
    return (
      <div className="h-[calc(100vh-96px)]">
        <ConversacionDetail conv={selected} onBack={() => setSelected(null)} />
      </div>
    );
  }

  return (
    <div>
      <div className="flex items-center gap-3 mb-6">
        <MessageSquare size={22} className="text-primary" />
        <h1 className="text-xl font-bold text-text">Bandeja WhatsApp</h1>
        <span className="ml-auto text-xs text-textMuted">{lista.length} conversaciones</span>
      </div>

      {isLoading && (
        <div className="flex justify-center py-20">
          <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
        </div>
      )}

      {!isLoading && lista.length === 0 && (
        <div className="text-center py-20 text-textSubtle">
          <MessageSquare size={40} className="mx-auto mb-3 opacity-30" />
          <p className="text-sm">Sin conversaciones aún</p>
          <p className="text-xs mt-1">Los mensajes de WhatsApp aparecerán aquí</p>
        </div>
      )}

      <div className="space-y-1">
        {lista.map((c) => (
          <button
            key={c.id}
            onClick={() => setSelected(c)}
            className="w-full flex items-center gap-3 px-4 py-3 rounded-xl bg-surface border border-border
                       hover:border-primary/30 hover:bg-surfaceHover transition-colors text-left"
          >
            <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
              <User size={16} className="text-primary" />
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-text truncate">
                  {c.nombre || c.canal_user_id}
                </p>
                <span className="flex items-center gap-1 text-2xs text-textSubtle shrink-0">
                  <Clock size={10} />
                  {timeAgo(c.ultimo_mensaje_at || c.ultima_interaccion)}
                </span>
              </div>
              <p className="text-xs text-textMuted truncate mt-0.5">
                {c.ultimo_mensaje || 'Sin mensajes'}
              </p>
            </div>
            {c.mensajes_recibidos > 0 && (
              <span className="w-5 h-5 rounded-full bg-primary text-white text-2xs flex items-center justify-center shrink-0 font-bold">
                {c.mensajes_recibidos > 9 ? '9+' : c.mensajes_recibidos}
              </span>
            )}
          </button>
        ))}
      </div>
    </div>
  );
}
