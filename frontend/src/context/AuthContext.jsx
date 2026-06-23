import { createContext, useContext, useState, useEffect } from 'react';
import { authMe, authLogin, authLogout, authSwitchEmpresa } from '../lib/api';

const AuthCtx = createContext(null);

export function AuthProvider({ children }) {
  const [user,      setUser]      = useState(null);
  const [empresas,  setEmpresas]  = useState([]);
  const [empresaId, setEmpresaId] = useState(null);
  const [loading,   setLoading]   = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('hdreams_token');
    if (!token) { setLoading(false); return; }

    authMe()
      .then(({ user, empresas }) => {
        setUser(user);
        setEmpresas(empresas);
        setEmpresaId(user.empresa_id);
      })
      .catch(() => localStorage.removeItem('hdreams_token'))
      .finally(() => setLoading(false));
  }, []);

  const login = async (email, password) => {
    const data = await authLogin(email, password);
    localStorage.setItem('hdreams_token', data.token);
    setUser(data.user);
    setEmpresas(data.empresas);
    setEmpresaId(data.empresa_id);
    return data;
  };

  const logout = async () => {
    await authLogout().catch(() => {});
    localStorage.removeItem('hdreams_token');
    setUser(null);
    setEmpresas([]);
    setEmpresaId(null);
  };

  const switchEmpresa = async (id) => {
    await authSwitchEmpresa(id);
    setEmpresaId(id);
  };

  return (
    <AuthCtx.Provider value={{ user, empresas, empresaId, loading, login, logout, switchEmpresa }}>
      {children}
    </AuthCtx.Provider>
  );
}

export const useAuth = () => useContext(AuthCtx);
