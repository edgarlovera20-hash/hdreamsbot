import { useEffect, useRef, useState } from 'react';
import { motion } from 'framer-motion';

function useCountUp(target, duration = 900) {
  const [value, setValue] = useState(0);
  const raf = useRef(null);

  useEffect(() => {
    const to   = parseFloat(target) || 0;
    const start = performance.now();

    const tick = (now) => {
      const t = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      setValue(Math.round(to * eased * 10) / 10);
      if (t < 1) raf.current = requestAnimationFrame(tick);
    };

    raf.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf.current);
  }, [target, duration]);

  return value;
}

export const Metric = ({
  label,
  value,
  suffix    = '',
  prefix    = '',
  trend,
  trendLabel,
  icon: Icon,
  delay     = 0,
  decimals  = 0,
}) => {
  const counted   = useCountUp(value);
  const displayed = decimals ? counted.toFixed(decimals) : Math.round(counted);
  const positive  = (trend ?? 0) >= 0;

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay }}
      className="bg-surface border border-border rounded-xl p-5 flex flex-col gap-3 hover:border-primary/40 transition-colors"
    >
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium font-sans text-textMuted">{label}</span>
        {Icon && (
          <span className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary shrink-0">
            <Icon size={16} />
          </span>
        )}
      </div>

      <div className="flex items-end gap-2">
        <span className="font-display text-3xl font-bold text-text tracking-tight leading-none">
          {prefix}{displayed}{suffix}
        </span>
        {trend !== undefined && (
          <span className={`text-xs font-medium mb-0.5 px-1.5 py-0.5 rounded ${positive ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>
            {positive ? '+' : ''}{trend}%
          </span>
        )}
      </div>

      {trendLabel && (
        <p className="text-xs text-textSubtle font-sans leading-tight">{trendLabel}</p>
      )}
    </motion.div>
  );
};
