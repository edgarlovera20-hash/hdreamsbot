import { useState } from 'react';
import { useSession } from '../context/SessionContext';

export default function Login() {
  const { login } = useSession();
  const [email, setEmail] = useState('operaciones@heavenlydreams.mx');
  const [password, setPassword] = useState('Cambio123!');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  return (
    <div className="min-h-screen bg-bg flex items-center justify-center p-6">
      <div className="w-full max-w-md rounded-3xl border border-border bg-surface p-8 shadow-2xl shadow-black/10">
        <div className="mb-6">
          <p className="text-xs uppercase tracking-[0.24em] text-primary">HDreams Control</p>
          <h1 className="mt-3 font-display text-3xl font-bold text-text">Acceso operativo</h1>
          <p className="mt-2 text-sm text-textMuted">Inicia sesión para entrar a tu cartera multiempresa y al panel ejecutivo.</p>
        </div>

        <form
          className="space-y-4"
          onSubmit={async (event) => {
            event.preventDefault();
            setError('');
            setIsSubmitting(true);
            try {
              await login({ email, password });
            } catch (submissionError) {
              setError(submissionError?.response?.data?.error ?? 'No se pudo iniciar sesión.');
            } finally {
              setIsSubmitting(false);
            }
          }}
        >
          <div>
            <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Email</label>
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className="w-full rounded-xl border border-border bg-bg px-4 py-3 text-sm text-text focus:outline-none focus:border-primary/60"
            />
          </div>
          <div>
            <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Password</label>
            <input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="w-full rounded-xl border border-border bg-bg px-4 py-3 text-sm text-text focus:outline-none focus:border-primary/60"
            />
          </div>
          {error && <p className="text-sm text-danger">{error}</p>}
          <button type="submit" className="btn-primary w-full" disabled={isSubmitting}>
            {isSubmitting ? 'Entrando...' : 'Entrar al panel'}
          </button>
        </form>
      </div>
    </div>
  );
}
