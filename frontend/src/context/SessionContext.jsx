import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchAuthMe, loginAuth, logoutAuth } from '../lib/api';

const SESSION_KEY = 'hdreams.session_token';
const COMPANY_KEY = 'hdreams.active_company_id';

const SessionContext = createContext(null);

export function SessionProvider({ children }) {
  const [sessionToken, setSessionToken] = useState(() => localStorage.getItem(SESSION_KEY) ?? '');
  const [activeCompanyId, setActiveCompanyId] = useState(() => Number(localStorage.getItem(COMPANY_KEY) ?? 0));

  const meQuery = useQuery({
    queryKey: ['auth-me', sessionToken],
    queryFn: fetchAuthMe,
    enabled: Boolean(sessionToken),
    retry: false,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (!sessionToken) {
      setActiveCompanyId(0);
      localStorage.removeItem(SESSION_KEY);
      localStorage.removeItem(COMPANY_KEY);
      return;
    }

    localStorage.setItem(SESSION_KEY, sessionToken);
  }, [sessionToken]);

  useEffect(() => {
    if (!activeCompanyId) {
      localStorage.removeItem(COMPANY_KEY);
      return;
    }

    localStorage.setItem(COMPANY_KEY, String(activeCompanyId));
  }, [activeCompanyId]);

  useEffect(() => {
    if (meQuery.isError) {
      setSessionToken('');
      setActiveCompanyId(0);
    }
  }, [meQuery.isError]);

  const companies = meQuery.data?.companies ?? [];

  useEffect(() => {
    if (!companies.length) return;
    const exists = companies.some((company) => Number(company.empresa_id) === Number(activeCompanyId));
    if (!exists) {
      setActiveCompanyId(Number(meQuery.data?.default_company_id ?? companies[0]?.empresa_id ?? 0));
    }
  }, [activeCompanyId, companies, meQuery.data?.default_company_id]);

  const value = useMemo(() => {
    const currentCompany = companies.find((company) => Number(company.empresa_id) === Number(activeCompanyId)) ?? null;

    return {
      isAuthenticated: Boolean(sessionToken && meQuery.data?.user),
      isInitializing: Boolean(sessionToken) && meQuery.isLoading,
      sessionToken,
      user: meQuery.data?.user ?? null,
      companies,
      activeCompanyId,
      currentCompany,
      currentRecruiterId: currentCompany?.recruiter_id ? Number(currentCompany.recruiter_id) : null,
      hasPermission(permission, companyId = activeCompanyId) {
        const company = companies.find((item) => Number(item.empresa_id) === Number(companyId));
        if (!company) return false;
        const permissions = company.permissions ?? [];
        return permissions.includes('*') || permissions.includes(permission);
      },
      setActiveCompanyId,
      async login(credentials) {
        const payload = await loginAuth(credentials);
        setSessionToken(payload.session_token ?? '');
        setActiveCompanyId(Number(payload.default_company_id ?? payload.companies?.[0]?.empresa_id ?? 0));
        return payload;
      },
      async logout() {
        try {
          await logoutAuth();
        } catch {
          // no-op
        }
        setSessionToken('');
        setActiveCompanyId(0);
      },
    };
  }, [activeCompanyId, companies, meQuery.data, meQuery.isLoading, sessionToken]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const context = useContext(SessionContext);
  if (!context) {
    throw new Error('useSession must be used within SessionProvider');
  }

  return context;
}
