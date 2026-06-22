import { motion, AnimatePresence } from 'framer-motion';
import { Badge } from '../ui/Badge';

const ScoreBar = ({ value, color = 'bg-primary' }) => (
  <div className="w-16 h-1.5 bg-border rounded-full overflow-hidden">
    <div
      className={`h-full ${color} rounded-full transition-all duration-700`}
      style={{ width: `${Math.min(value, 100)}%` }}
    />
  </div>
);

export const LeadQueue = ({ leads = [] }) => {
  if (!leads.length) {
    return (
      <div className="bg-surface border border-border rounded-xl p-8 text-center">
        <p className="text-textMuted text-sm">Sin leads pendientes en cola</p>
      </div>
    );
  }

  return (
    <div className="bg-surface border border-border rounded-xl overflow-hidden">
      <div className="px-5 py-3 border-b border-border flex items-center justify-between">
        <span className="text-sm font-semibold font-display text-text">Cola de Prioridad IA</span>
        <span className="text-xs text-textMuted">{leads.length} pendientes</span>
      </div>

      <ul className="divide-y divide-border max-h-96 overflow-y-auto">
        <AnimatePresence initial={false}>
          {leads.map((lead, i) => (
            <motion.li
              key={lead.id ?? i}
              initial={{ opacity: 0, x: -8 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: 8 }}
              transition={{ duration: 0.2, delay: i * 0.04 }}
              className="flex items-center gap-3 px-5 py-3 hover:bg-surfaceHover transition-colors"
            >
              {/* Avatar */}
              <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 font-bold text-xs text-primary">
                {(lead.nombre || '?')[0].toUpperCase()}
              </div>

              {/* Info */}
              <div className="flex-1 min-w-0">
                <p className="font-semibold text-sm text-text truncate">{lead.nombre ?? 'Sin nombre'}</p>
                <p className="text-xs text-textMuted">
                  {lead.edad ? `${lead.edad} años` : '—'}
                  {lead.canal ? ` • ${lead.canal}` : ''}
                </p>
                {lead.score_prioridad > 0 && (
                  <div className="mt-1">
                    <ScoreBar
                      value={lead.score_prioridad}
                      color={
                        lead.prioridad === 'urgente' ? 'bg-urgente' :
                        lead.prioridad === 'alta'    ? 'bg-alta'    :
                        lead.prioridad === 'media'   ? 'bg-media'   : 'bg-baja'
                      }
                    />
                  </div>
                )}
              </div>

              {/* Badge + Score */}
              <div className="flex flex-col items-end gap-1 shrink-0">
                <Badge value={lead.prioridad} />
                {lead.score_prioridad > 0 && (
                  <span className="text-xs font-mono text-textMuted">{lead.score_prioridad}/100</span>
                )}
              </div>
            </motion.li>
          ))}
        </AnimatePresence>
      </ul>
    </div>
  );
};
