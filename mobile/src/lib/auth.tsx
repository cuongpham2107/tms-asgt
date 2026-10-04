import { createContext, useContext, useState, type ReactNode } from "react";

interface AuthState {
  token: string | null;
  shiftId: string | null;
  shift: any | null;
  minAppVersion: string | null;
  setAuth: (t: string, s?: string, sh?: any) => void;
  setShift: (s: any) => void;
  setMinAppVersion: (v: string | null | undefined) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthState>({
  token: null, shiftId: null, shift: null, minAppVersion: null,
  setAuth: () => {}, setShift: () => {}, setMinAppVersion: () => {}, logout: () => {},
});

export function useAuth() { return useContext(AuthContext); }

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [shiftId, setShiftId] = useState<string | null>(null);
  const [shift, setShift] = useState<any | null>(null);
  const [minAppVersion, setMinAppVersion] = useState<string | null>(null);
  return (
    <AuthContext.Provider value={{
      token, shiftId, shift, minAppVersion,
      setAuth: (t, s, sh) => { setToken(t); if (s) setShiftId(s); setShift(sh ?? null); },
      setShift: (sh) => { setShift(sh); if (sh?.id) setShiftId(String(sh.id)); },
      setMinAppVersion: (v) => { if (v) setMinAppVersion(v); },
      logout: () => { setToken(null); setShiftId(null); setShift(null); },
    }}>
      {children}
    </AuthContext.Provider>
  );
}
