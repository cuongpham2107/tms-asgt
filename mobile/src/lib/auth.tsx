import { createContext, useContext, useState, type ReactNode } from "react";

interface AuthState {
  token: string | null;
  shiftId: string | null;
  shift: any | null;
  setAuth: (t: string, s?: string, sh?: any) => void;
  setShift: (s: any) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthState>({
  token: null, shiftId: null, shift: null,
  setAuth: () => {}, setShift: () => {}, logout: () => {},
});

export function useAuth() { return useContext(AuthContext); }

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [shiftId, setShiftId] = useState<string | null>(null);
  const [shift, setShift] = useState<any | null>(null);
  return (
    <AuthContext.Provider value={{
      token, shiftId, shift,
      setAuth: (t, s, sh) => { setToken(t); if (s) setShiftId(s); if (sh) setShift(sh); },
      setShift: (sh) => { setShift(sh); if (sh?.id) setShiftId(String(sh.id)); },
      logout: () => { setToken(null); setShiftId(null); setShift(null); },
    }}>
      {children}
    </AuthContext.Provider>
  );
}
