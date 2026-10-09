import { createContext, useContext, useState, useRef, useCallback, type ReactNode } from "react";

interface LoadingContextType {
  visible: boolean;
  showLoading: () => void;
  hideLoading: () => void;
}

const LoadingContext = createContext<LoadingContextType>({
  visible: false,
  showLoading: () => {},
  hideLoading: () => {},
});

export function useLoading() {
  return useContext(LoadingContext);
}

export function LoadingProvider({ children }: { children: ReactNode }) {
  const countRef = useRef(0);
  const [visible, setVisible] = useState(false);

  const showLoading = useCallback(() => {
    countRef.current += 1;
    setVisible(true);
  }, []);

  const hideLoading = useCallback(() => {
    countRef.current = Math.max(0, countRef.current - 1);
    if (countRef.current === 0) {
      setVisible(false);
    }
  }, []);

  return (
    <LoadingContext.Provider value={{ visible, showLoading, hideLoading }}>
      {children}
    </LoadingContext.Provider>
  );
}
