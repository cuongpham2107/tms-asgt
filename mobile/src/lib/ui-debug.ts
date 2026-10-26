import AsyncStorage from "@react-native-async-storage/async-storage";
import Constants from "expo-constants";
import { Dimensions, Keyboard, Platform } from "react-native";

/**
 * Nhật ký sự kiện UI/bàn phím dùng để chẩn đoán lỗi "bàn phím hiện rồi tắt ngay"
 * trên thiết bị không cầm tay được (máy khách hàng).
 *
 * - Ghi vào RAM + AsyncStorage (giữ 60 sự kiện gần nhất) nên mở lại app vẫn còn.
 * - Xem bằng cách bấm logo ở màn đăng nhập 5 lần (xem `UiDebugPanel`).
 */

const STORAGE_KEY = "@tms_driver_ui_debug";
const MAX_EVENTS = 60;

export interface UiDebugEvent {
  /** epoch ms */
  t: number;
  /** tên sự kiện, vd: focus / blur / kb:show */
  ev: string;
  detail?: string;
}

let events: UiDebugEvent[] = [];
let hydrated = false;
let persistTimer: ReturnType<typeof setTimeout> | null = null;

function schedulePersist() {
  if (persistTimer) clearTimeout(persistTimer);
  persistTimer = setTimeout(() => {
    persistTimer = null;
    AsyncStorage.setItem(STORAGE_KEY, JSON.stringify(events)).catch(() => {
      // Ghi log lỗi không được phép làm gián đoạn app.
    });
  }, 400);
}

export function logUiEvent(ev: string, detail?: string) {
  events.push({ t: Date.now(), ev, detail });
  if (events.length > MAX_EVENTS) events = events.slice(-MAX_EVENTS);
  schedulePersist();
}

/** Nạp lại log của các lần mở app trước (chỉ chạy 1 lần mỗi phiên). */
export async function hydrateUiDebugLog(): Promise<void> {
  if (hydrated) return;
  hydrated = true;
  try {
    const raw = await AsyncStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw) as UiDebugEvent[];
    if (Array.isArray(saved)) {
      events = [...saved, ...events].slice(-MAX_EVENTS);
    }
  } catch {
    // Log hỏng thì bỏ qua.
  }
}

export function getUiDebugEvents(): UiDebugEvent[] {
  return [...events];
}

export async function clearUiDebugLog(): Promise<void> {
  events = [];
  try {
    await AsyncStorage.removeItem(STORAGE_KEY);
  } catch {
    // bỏ qua
  }
}

function two(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

export function formatUiDebugLog(list: UiDebugEvent[]): string {
  const win = Dimensions.get("window");
  const head =
    `ASGT ${Platform.OS} ${Platform.Version} · ` +
    `cửa sổ ${Math.round(win.width)}x${Math.round(win.height)} · font ${Math.round(win.fontScale * 100)}%`;
  const lines = list.map((e) => {
    const d = new Date(e.t);
    const ts =
      `${two(d.getHours())}:${two(d.getMinutes())}:${two(d.getSeconds())}` +
      `.${String(d.getMilliseconds()).padStart(3, "0")}`;
    return `${ts} ${e.ev}${e.detail ? ` · ${e.detail}` : ""}`;
  });
  return [head, ...lines].join("\n");
}

/**
 * Bật ghi log sự kiện bàn phím + thay đổi kích thước cửa sổ cho toàn app.
 * Trả về hàm cleanup (dùng trong useEffect).
 */
export function installUiDebugTracking(): () => void {
  const subscriptions = [
    Keyboard.addListener("keyboardDidShow", (e) => {
      logUiEvent(
        "kb:show",
        `h=${Math.round(e.endCoordinates.height)} y=${Math.round(e.endCoordinates.screenY)}`
      );
    }),
    Keyboard.addListener("keyboardDidHide", () => {
      logUiEvent("kb:hide");
    }),
    Dimensions.addEventListener("change", ({ window }) => {
      logUiEvent(
        "win:change",
        `${Math.round(window.width)}x${Math.round(window.height)} font ${Math.round(window.fontScale * 100)}%`
      );
    }),
  ];

  logUiEvent(
    "app:start",
    `${Platform.OS} ${Platform.Version} · app ${Constants.expoConfig?.version ?? "?"}`
  );

  return () => subscriptions.forEach((sub) => sub.remove());
}
