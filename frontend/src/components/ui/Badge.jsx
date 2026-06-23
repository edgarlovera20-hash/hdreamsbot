import { motion } from 'framer-motion';

const STYLES = {
  // prioridad
  urgente:             'bg-urgente/15 text-urgente border border-urgente/30',
  alta:                'bg-alta/15 text-alta border border-alta/30',
  media:               'bg-media/15 text-media border border-media/30',
  baja:                'bg-baja/15 text-baja border border-baja/30',
  // estado
  nuevo:               'bg-info/15 text-info border border-info/30',
  contactado:          'bg-warning/15 text-warning border border-warning/30',
  calificado:          'bg-success/15 text-success border border-success/30',
  entrevista_agendada: 'bg-primary/15 text-primary border border-primary/30',
  entrevista_realizada:'bg-primary/20 text-primary border border-primary/40',
  contratado:          'bg-success/25 text-success border border-success/50',
  rechazado:           'bg-danger/15 text-danger border border-danger/30',
  no_interesado:       'bg-baja/15 text-baja border border-baja/30',
  // canal
  whatsapp:            'bg-success/15 text-success border border-success/30',
  messenger:           'bg-info/15 text-info border border-info/30',
  instagram:           'bg-alta/15 text-alta border border-alta/30',
  facebook:            'bg-primary/15 text-primary border border-primary/30',
  telegram:            'bg-info/20 text-info border border-info/40',
  gmail:               'bg-danger/15 text-danger border border-danger/30',
  outlook:             'bg-info/15 text-info border border-info/30',
  teams:               'bg-primary/15 text-primary border border-primary/30',
};

const LABELS = {
  urgente:              'Urgente',
  alta:                 'Alta',
  media:                'Media',
  baja:                 'Baja',
  nuevo:                'Nuevo',
  contactado:           'Contactado',
  calificado:           'Calificado',
  entrevista_agendada:  'Entrevista',
  entrevista_realizada: 'Realizada',
  contratado:           'Contratado',
  rechazado:            'Rechazado',
  no_interesado:        'No interesado',
  whatsapp:             'WhatsApp',
  messenger:            'Messenger',
  instagram:            'Instagram',
  facebook:             'Facebook',
  telegram:             'Telegram',
  gmail:                'Gmail',
  outlook:              'Outlook',
  teams:                'Teams',
};

const DOT = {
  urgente:              'bg-urgente animate-pulse-soft',
  alta:                 'bg-alta',
  media:                'bg-media',
  baja:                 'bg-baja',
  nuevo:                'bg-info',
  contactado:           'bg-warning',
  calificado:           'bg-success',
  entrevista_agendada:  'bg-primary',
  entrevista_realizada: 'bg-primary',
  contratado:           'bg-success',
  rechazado:            'bg-danger',
  no_interesado:        'bg-baja',
  whatsapp:             'bg-success',
  messenger:            'bg-info',
  instagram:            'bg-alta',
  facebook:             'bg-primary',
  telegram:             'bg-info',
  gmail:                'bg-danger',
  outlook:              'bg-info',
  teams:                'bg-primary',
};

export const Badge = ({ value, showDot = true, className = '' }) => {
  const style = STYLES[value] ?? 'bg-baja/15 text-baja border border-baja/30';
  const label = LABELS[value] ?? value;
  const dot   = DOT[value]   ?? 'bg-baja';

  return (
    <motion.span
      initial={{ opacity: 0, scale: 0.9 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: 0.15 }}
      className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium font-sans ${style} ${className}`}
    >
      {showDot && <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${dot}`} />}
      {label}
    </motion.span>
  );
};
