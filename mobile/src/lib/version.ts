import Constants from "expo-constants";
import { Platform } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";

export interface AppVersionInfo {
  latest_version: string;
  min_version: string;
  release_notes: string;
  store_urls: {
    android: string;
    ios: string;
  };
}

export const CURRENT_APP_VERSION = Constants.expoConfig?.version ?? "1.0.0";

const SNOOZE_KEY = "@tms_driver_update_snooze";

/**
 * So sánh 2 chuỗi semver (x.y.z).
 * Trả về:
 *  1 nếu v1 > v2
 * -1 nếu v1 < v2
 *  0 nếu v1 === v2
 */
export function compareSemver(v1: string, v2: string): number {
  const parse = (v: string) =>
    (v || "").split("-")[0].split(".").map((n) => parseInt(n, 10) || 0);
  const a = parse(v1);
  const b = parse(v2);
  const len = Math.max(a.length, b.length);
  for (let i = 0; i < len; i++) {
    const ai = a[i] ?? 0;
    const bi = b[i] ?? 0;
    if (ai > bi) return 1;
    if (ai < bi) return -1;
  }
  return 0;
}

/**
 * Lấy URL cửa hàng theo hệ điều hành iOS hoặc Android.
 */
export function getStoreUrl(urls?: { android: string; ios: string }): string {
  const fallback = Platform.select({
    ios: "https://apps.apple.com/app/id6810122889",
    default: "https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver",
  });

  if (!urls) return fallback;

  return (
    Platform.select({
      ios: urls.ios || fallback,
      default: urls.android || fallback,
    }) || fallback
  );
}

/**
 * Kiểm tra xem có nên hiển thị thông báo cập nhật tùy chọn (Soft Update) không.
 * Nếu tài xế đã bấm "Để sau" trong vòng 24 giờ cho cùng phiên bản thì bỏ qua.
 */
export async function shouldShowSoftUpdate(latestVersion: string): Promise<boolean> {
  try {
    const raw = await AsyncStorage.getItem(SNOOZE_KEY);
    if (!raw) return true;
    const { version, snoozedAt } = JSON.parse(raw);
    // Nếu có phiên bản mới hơn phiên bản đã hoãn thì nhắc luôn
    if (compareSemver(latestVersion, version) > 0) return true;

    // Hoãn trong vòng 24 giờ
    const ONE_DAY_MS = 24 * 60 * 60 * 1000;
    return Date.now() - (snoozedAt || 0) > ONE_DAY_MS;
  } catch {
    return true;
  }
}

/**
 * Lưu trạng thái bấm "Để sau" vào bộ nhớ máy (hoãn nhắc nhở 24h).
 */
export async function snoozeSoftUpdate(latestVersion: string): Promise<void> {
  try {
    await AsyncStorage.setItem(
      SNOOZE_KEY,
      JSON.stringify({ version: latestVersion, snoozedAt: Date.now() })
    );
  } catch {
    // ignore
  }
}
