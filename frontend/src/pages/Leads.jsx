import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { Search, Filter, RefreshCw } from 'lucide-react';
import { fetchLeads } from '../lib/api';
import { Badge } from '../components/ui/Badge';
import { useSession } from '../context/SessionContext';

const ESTADOS  = ['nuevo','contactado','calificado','entrevista_agendada','entrevista_realizada','contratado','rechazado','no_interesado'];
const CANALES  = ['whatsapp','messenger','instagram','facebook','gmail','telegram'];
export default function Leads() {
  const { activeCompanyId: empresaId } = useSession();
  const [estado,  setEstado]  = useState('');
  const [canal,   setCanal]   = useState('');
  const [search,  setSearch]  = useState('');

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey:  ['leads', empresaId, estado, canal],
    queryFn:   () => fetchLeads({ empresa_id: empresaId, estado: estado || undefined, canal: canal || undefined, limite: 200 }),
    enabled: Boolean(empresaId),
    staleTime: 30_000,
  });

  const leads = (data?.leads ?? []).filter((l) => {
    if (!search) return true;
    const q = search.toLowerCase();
    return (
      (l.nombre   ?? '').toLowerCase().includes(q) ||
      (l.telefono ?? '').includes(q) ||
      (l.email    ?? '').toLowerCase().includes(q)
    );
  });

  return (
    <div className="space-y-5 animate-fade-in">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-display text-2xl font-bold text-text">Leads</h1>
          <p className="text-sm text-textMuted">{data?.total ?? 0} registros totales</p>
        </div>
        <button
          onClick={() => refetch()}
          disabled={isFetching}
          className="btn-ghost flex items-center gap-2"
        >
          <RefreshCw size={14} className={isFetching ? 'animate-spin' : ''} />
          Actualizar
        </button>
      </div>

      {/* Filtros */}
      <div className="flex flex-wrap gap-3">
        {/* Búsqueda */}
        <div className="relative flex-1 min-w-48">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-textMuted" />
          <input
            type="text"
            placeholder="Nombre, teléfono o email..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-8 pr-3 py-1.5 rounded-lg text-sm bg-surface border border-border text-text
                       placeholder:text-textMuted focus:outline-none focus:border-primary/60"
          />
        </div>

        {/* Estado */}
        <div className="relative flex items-center gap-2">
          <Filter size={14} className="text-textMuted" />
          <select
            value={estado}
            onChange={(e) => setEstado(e.target.value)}
            className="pl-2 pr-6 py-1.5 rounded-lg text-sm bg-surface border border-border text-text
                       focus:outline-none focus:border-primary/60 appearance-none cursor-pointer"
          >
            <option value="">Todos los estados</option>
            {ESTADOS.map((e) => <option key={e} value={e}>{e.replace(/_/g, ' ')}</option>)}
          </select>
        </div>

        {/* Canal */}
        <select
          value={canal}
          onChange={(e) => setCanal(e.target.value)}
          className="pl-3 pr-6 py-1.5 rounded-lg text-sm bg-surface border border-border text-text
                     focus:outline-none focus:border-primary/60 appearance-none cursor-pointer"
        >
          <option value="">Todos los canales</option>
          {CANALES.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>

      {/* Tabla */}
      <div className="bg-surface border border-border rounded-xl overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center h-40">
            <div className="w-7 h-7 border-2 border-primary border-t-transparent rounded-full animate-spin" />
          </div>
        ) : isError ? (
          <p className="text-center py-10 text-red-400 text-sm">Error al cargar leads.</p>
        ) : leads.length === 0 ? (
          <p className="text-center py-10 text-textMuted text-sm">Sin resultados.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-textMuted text-xs uppercase tracking-wide">
                  <th className="px-4 py-3 text-left">Lead</th>
                  <th className="px-4 py-3 text-left">Contacto</th>
                  <th className="px-4 py-3 text-left">Canal</th>
                  <th className="px-4 py-3 text-left">Estado</th>
                  <th className="px-4 py-3 text-left">Prioridad</th>
                  <th className="px-4 py-3 text-right">Score IA</th>
                  <th className="px-4 py-3 text-right">Última interacción</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                <AnimatePresence initial={false}>
                  {leads.map((lead, i) => (
                    <motion.tr
                      key={lead.id}
                      initial={{ opacity: 0, y: 4 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 0.15, delay: i * 0.02 }}
                      className="hover:bg-surfaceHover transition-colors"
                    >
                      <td className="px-4 py-3">
                        <Link to={`/leads/${lead.id}`} className="flex items-center gap-2 group">
                          <div className="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center text-xs font-bold text-primary shrink-0">
                            {(lead.nombre || '?')[0].toUpperCase()}
                          </div>
                          <div>
                            <p className="font-medium text-text truncate max-w-36 group-hover:text-primary transition-colors">{lead.nombre ?? '—'}</p>
                            {lead.edad && <p className="text-xs text-textMuted">{lead.edad} años</p>}
                          </div>
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-textMuted">
                        <div>
                          {lead.telefono && <p>{lead.telefono}</p>}
                          {lead.email    && <p className="text-xs truncate max-w-40">{lead.email}</p>}
                        </div>
                      </td>
                      <td className="px-4 py-3"><Badge value={lead.canal} /></td>
                      <td className="px-4 py-3"><Badge value={lead.estado} /></td>
                      <td className="px-4 py-3"><Badge value={lead.prioridad} /></td>
                      <td className="px-4 py-3 text-right">
                        {lead.score_prioridad ? (
                          <span className="font-mono text-xs text-primary">{Number(lead.score_prioridad).toFixed(0)}/100</span>
                        ) : (
                          <span className="text-textSubtle text-xs">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right text-xs text-textMuted">
                        {lead.ultima_interaccion
                          ? new Date(lead.ultima_interaccion).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
                          : '—'}
                      </td>
                    </motion.tr>
                  ))}
                </AnimatePresence>
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
