import { Users, MessageSquare, CheckCircle, Clock, TrendingUp, AlertTriangle } from 'lucide-react';
import { Metric } from '../ui/Metric';

export const KPIGrid = ({ data }) => {
  const totales   = data?.totales ?? {};
  const mensajes  = (data?.por_hora ?? []).reduce((sum, h) => sum + Number(h.mensajes || 0), 0);
  const conversion = totales.total > 0
    ? ((Number(totales.contratados) || 0) / Number(totales.total) * 100)
    : 0;

  const metrics = [
    {
      label:      'Mensajes recibidos',
      value:      mensajes,
      icon:       MessageSquare,
      delay:      0,
    },
    {
      label:      'Leads nuevos',
      value:      Number(totales.nuevos ?? 0),
      icon:       Users,
      delay:      0.06,
    },
    {
      label:      'Leads calificados',
      value:      Number(totales.calificados ?? 0),
      icon:       CheckCircle,
      delay:      0.12,
    },
    {
      label:      'T. respuesta',
      value:      Math.round(totales.tiempo_respuesta_avg ?? 0),
      suffix:     's',
      icon:       Clock,
      delay:      0.18,
    },
    {
      label:      'Conversión',
      value:      parseFloat(conversion.toFixed(1)),
      suffix:     '%',
      decimals:   1,
      icon:       TrendingUp,
      delay:      0.24,
    },
    {
      label:      'Score IA promedio',
      value:      parseFloat(totales.score_candidato_avg ?? 0),
      suffix:     '/100',
      decimals:   1,
      icon:       AlertTriangle,
      delay:      0.30,
    },
  ];

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
      {metrics.map((m) => (
        <Metric key={m.label} {...m} />
      ))}
    </div>
  );
};
