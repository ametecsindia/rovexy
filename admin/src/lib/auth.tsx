import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { ApiError, get } from './api';

export type Me = {
  id: number;
  name: string;
  email: string;
  mfa_verified: boolean;
  permissions: string[];
  roles: string[];
  company: { id: number; slug: string; name: string; timezone: string };
  product_name: string;
  realtime?: { key: string; host: string; port: number; scheme: string } | null;
};

type Ctx = { me: Me | null; loading: boolean; refresh: () => Promise<void>; can: (p: string) => boolean; setMe: (m: Me | null) => void };
const AuthCtx = createContext<Ctx>({ me: null, loading: true, refresh: async () => {}, can: () => false, setMe: () => {} });

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);
  const refresh = async () => {
    try {
      const m = await get<Me>('/auth/me');
      setMe(m.mfa_verified ? m : null);
    } catch (e) {
      if (!(e instanceof ApiError)) throw e;
      setMe(null);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    void refresh();
  }, []);
  const can = (p: string) => !!me?.permissions.includes(p);
  return <AuthCtx.Provider value={{ me, loading, refresh, can, setMe }}>{children}</AuthCtx.Provider>;
}

export const useAuth = () => useContext(AuthCtx);
