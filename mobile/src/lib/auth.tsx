import { createContext, useContext, useState, type ReactNode } from "react";

interface AuthState {
  token: string | null;
  shiftId: string | null;
  shift: any | null;
  user: any | null;
  minAppVersion: string | null;
  setAuth: (t: string, s?: string, sh?: any, u?: any) => void;
  setUser: (u: any) => void;
  setShift: (s: any) => void;
  setMinAppVersion: (v: string | null | undefined) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthState>({
  token: null,
  shiftId: null,
  shift: null,
  user: null,
  minAppVersion: null,
  setAuth: () => {},
  setUser: () => {},
  setShift: () => {},
  setMinAppVersion: () => {},
  logout: () => {},
});

export function useAuth() {
  return useContext(AuthContext);
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [shiftId, setShiftId] = useState<string | null>(null);
  const [shift, setShift] = useState<any | null>(null);
  const [user, setUser] = useState<any | null>(null);
  const [minAppVersion, setMinAppVersion] = useState<string | null>(null);

  return (
    <AuthContext.Provider
      value={{
        token,
        shiftId,
        shift,
        user,
        minAppVersion,
        setAuth: (t, s, sh, u) => {
          setToken(t);
          if (s) setShiftId(s);
          setShift(sh ?? null);
          if (u !== undefined) setUser(u ?? null);
          else if (sh?.driver) setUser(sh.driver);
        },
        setUser,
        setShift: (sh) => {
          setShift(sh);
          if (sh?.id) setShiftId(String(sh.id));
          if (sh?.driver && !user) setUser(sh.driver);
        },
        setMinAppVersion: (v) => {
          if (v) setMinAppVersion(v);
        },
        logout: () => {
          setToken(null);
          setShiftId(null);
          setShift(null);
          setUser(null);
        },
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}
