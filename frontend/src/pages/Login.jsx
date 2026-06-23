import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Login() {
  const { login }          = useAuth();
  const navigate           = useNavigate();
  const [email,    setEmail]    = useState('');
  const [password, setPassword] = useState('');
  const [error,    setError]    = useState('');
  const [loading,  setLoading]  = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await login(email, password);
      navigate('/', { replace: true });
    } catch (err) {
      setError(err.response?.data?.error ?? 'Error al iniciar sesión');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-bg flex items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="bg-surface border border-border rounded-2xl p-8 shadow-xl">

          {/* Header */}
          <div className="mb-8">
            <p className="text-xs font-semibold tracking-[0.2em] text-primary uppercase mb-3">
              HDreams Control
            </p>
            <h1 className="text-2xl font-bold text-text mb-2">Acceso operativo</h1>
            <p className="text-sm text-textMuted leading-relaxed">
              Inicia sesión para entrar a tu cartera multiempresa y al panel ejecutivo.
            </p>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-1.5">
              <label className="text-xs font-semibold tracking-widest text-textMuted uppercase">
                Email
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="correo@empresa.com"
                required
                className="w-full px-4 py-3 rounded-lg bg-bg border border-border text-text text-sm
                           placeholder:text-textSubtle focus:outline-none focus:border-primary/60
                           transition-colors"
              />
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-semibold tracking-widest text-textMuted uppercase">
                Password
              </label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••••"
                required
                className="w-full px-4 py-3 rounded-lg bg-bg border border-border text-text text-sm
                           placeholder:text-textSubtle focus:outline-none focus:border-primary/60
                           transition-colors"
              />
            </div>

            {error && (
              <p className="text-sm text-danger text-center">{error}</p>
            )}

            <button
              type="submit"
              disabled={loading}
              className="w-full py-3 rounded-lg bg-primary hover:bg-primaryHover text-white text-sm
                         font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? 'Entrando…' : 'Entrar al panel'}
            </button>
          </form>

        </div>
        <p className="text-center text-xs text-textSubtle mt-6">v1.0.0 · HDreams 2026</p>
      </div>
    </div>
  );
}
