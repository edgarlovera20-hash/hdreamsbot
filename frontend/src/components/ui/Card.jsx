import { motion } from 'framer-motion';

export const Card = ({ children, delay = 0, className = '', onClick }) => (
  <motion.div
    initial={{ opacity: 0, y: 20 }}
    animate={{ opacity: 1, y: 0 }}
    transition={{ duration: 0.3, delay }}
    onClick={onClick}
    className={[
      'bg-surface border border-border rounded-xl p-6',
      'hover:border-primary/40 hover:bg-surfaceHover transition-colors duration-200',
      onClick ? 'cursor-pointer' : '',
      className,
    ].join(' ')}
  >
    {children}
  </motion.div>
);
