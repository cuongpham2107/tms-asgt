import {
  createContext,
  useContext,
  useState,
  useCallback,
  useMemo,
  type ReactNode,
} from "react";

interface AuthState {
  token: string | null;
  shiftId: string | null;
  shift: any | null;
  setAuth: (t: string, s?: string, sh?: any) => void;
  setShift: (s: any) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthState>({
  token: null,
  shiftId: null,
  shift: null,
  setAuth: () => {},
  setShift: () => {},
  logout: () => {},
});

export function useAuth() {
  return useContext(AuthContext);
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [shiftId, setShiftId] = useState<string | null>(null);
  const [shift, setShiftState] = useState<any | null>(null);

  const setAuth = useCallback((t: string, s?: string, sh?: any) => {
    setToken(t);
    if (s) setShiftId(s);
    setShiftState(sh ?? null);
  }, []);

  const setShift = useCallback((sh: any) => {
    const next = sh ?? null;
    setShiftState((prev: any) => {
      if (prev === next) return prev;
      if (
        prev &&
        next &&
        prev.id === next.id &&
        prev.end_time === next.end_time &&
        prev.total_km === next.total_km &&
        prev.total_km_loaded === next.total_km_loaded &&
        prev.total_km_empty === next.total_km_empty &&
        prev.vehicle?.id === next.vehicle?.id &&
        prev.vehicle?.current_mileage === next.vehicle?.current_mileage
      ) {
        const prevTrips: any[] = Array.isArray(prev.trips) ? prev.trips : [];
        const nextTrips: any[] = Array.isArray(next.trips) ? next.trips : [];
        if (prevTrips.length === nextTrips.length) {
          const sameTrips = prevTrips.every((pt, idx) => {
            const nt = nextTrips[idx];
            return (
              pt?.id === nt?.id &&
              pt?.status === nt?.status &&
              pt?.end_km === nt?.end_km &&
              pt?.completed_at === nt?.completed_at &&
              (pt?.orders?.length ?? 0) === (nt?.orders?.length ?? 0)
            );
          });
          if (sameTrips) {
            return prev;
          }
        }
      }
      return next;
    });
    setShiftId(next?.id ? String(next.id) : null);
  }, []);

  const logout = useCallback(() => {
    setToken(null);
    setShiftId(null);
    setShiftState(null);
  }, []);

  const value = useMemo(
    () => ({
      token,
      shiftId,
      shift,
      setAuth,
      setShift,
      logout,
    }),
    [token, shiftId, shift, setAuth, setShift, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
