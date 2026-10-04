import AsyncStorage from "@react-native-async-storage/async-storage";

const DEVICE_ID_KEY = "@tms_gps_device_id";
let cachedId: string | null = null;

// ponytail: Math.random UUID v4 (không có crypto.randomUUID trên Hermes); chỉ dùng làm định danh thiết bị, không phải bí mật
function uuidV4(): string {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/** UUID của thiết bị, tạo một lần và lưu trong AsyncStorage. */
export async function getDeviceId(): Promise<string> {
    if (cachedId) return cachedId;
    let id = await AsyncStorage.getItem(DEVICE_ID_KEY);
    if (!id) {
        id = uuidV4();
        await AsyncStorage.setItem(DEVICE_ID_KEY, id);
    }
    cachedId = id;
    return id;
}
