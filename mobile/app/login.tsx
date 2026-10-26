import { useState, useEffect, useRef } from "react";
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  Image,
  ScrollView,
  KeyboardAvoidingView,
  Platform,
} from "react-native";
import { router } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { login } from "../src/lib/api";
import { logUiEvent } from "../src/lib/ui-debug";
import UiDebugPanel from "../src/components/UiDebugPanel";

const REMEMBER_KEY = "@tms_driver_saved_login";

type Field = "email" | "password";

// Trên một số máy Android (Samsung One UI) bàn phím hiện lên rồi tự đóng ngay sau cú chạm
// đầu tiên. Nếu blur xảy ra quá sớm và người dùng chưa chuyển sang ô khác, ta tự focus lại.
const REFOCUS_ELAPSED_MS = 700;
const REFOCUS_MAX_ATTEMPTS = 3;

export default function LoginScreen() {
  const { setAuth } = useAuth();
  const { showLoading, hideLoading } = useLoading();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [rememberMe, setRememberMe] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [debugVisible, setDebugVisible] = useState(false);

  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);
  const focusState = useRef<{ field: Field | null; at: number; retries: number }>({
    field: null,
    at: 0,
    retries: 0,
  });
  const suppressRefocus = useRef(false);
  const logoTapCount = useRef(0);
  const logoLastTapAt = useRef(0);

  function focusField(field: Field) {
    (field === "email" ? emailRef : passwordRef).current?.focus();
  }

  function handleInputFocus(field: Field) {
    const prev = focusState.current;
    // Giữ số lần thử khi chính watchdog vừa focus lại, tránh vòng lặp vô hạn.
    const keepRetries =
      prev.field === field && Date.now() - prev.at < 1500 ? prev.retries : 0;
    focusState.current = { field, at: Date.now(), retries: keepRetries };
    suppressRefocus.current = false;
    logUiEvent("focus", field);
  }

  function handleInputBlur(field: Field) {
    logUiEvent("blur", field);
    const st = focusState.current;
    if (st.field !== field) return;

    const elapsed = Date.now() - st.at;
    if (suppressRefocus.current || elapsed > REFOCUS_ELAPSED_MS) return;
    if (st.retries >= REFOCUS_MAX_ATTEMPTS) return;

    st.retries += 1;
    st.at = Date.now();
    logUiEvent("refocus", `${field} lần ${st.retries} (${Math.round(elapsed)}ms)`);
    setTimeout(() => {
      // Bỏ qua nếu người dùng đã chuyển sang ô khác hoặc bấm nút.
      if (focusState.current.field === field && !suppressRefocus.current) {
        focusField(field);
      }
    }, 150);
  }

  function handleLogoPress() {
    const now = Date.now();
    logoTapCount.current =
      now - logoLastTapAt.current > 1500 ? 1 : logoTapCount.current + 1;
    logoLastTapAt.current = now;
    if (logoTapCount.current >= 5) {
      logoTapCount.current = 0;
      logUiEvent("debug:open");
      setDebugVisible(true);
    }
  }

  // Tự động load thông tin đã lưu nếu người dùng từng tích "Lưu thông tin đăng nhập"
  useEffect(() => {
    AsyncStorage.getItem(REMEMBER_KEY)
      .then((raw) => {
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed?.email) setEmail(parsed.email);
          if (parsed?.password) setPassword(parsed.password);
          setRememberMe(true);
        }
      })
      .catch((err) => {
        console.log("Failed to load saved login credentials:", err);
      });
  }, []);

  async function handleLogin() {
    suppressRefocus.current = true;
    if (!email.trim() || !password) {
      setError("Vui lòng nhập mã nhân viên, email hoặc số điện thoại và mật khẩu");
      return;
    }

    setLoading(true);
    setError("");
    showLoading();

    try {
      const res = await login(email.trim(), password);
      if (!res.token) return setError("Sai tài khoản hoặc mật khẩu");

      // Xử lý lưu hoặc xóa thông tin đăng nhập
      if (rememberMe) {
        await AsyncStorage.setItem(
          REMEMBER_KEY,
          JSON.stringify({ email: email.trim(), password })
        );
      } else {
        await AsyncStorage.removeItem(REMEMBER_KEY);
      }

      const shift = res.shift;
      setAuth(res.token, shift?.id ? String(shift.id) : undefined, shift);
      router.replace("/");
    } catch (e) {
      const errorMsg = e instanceof Error ? e.message : "Không thể kết nối đến máy chủ";
      if (errorMsg === "Network request failed" || errorMsg.includes("Network request failed")) {
        setError("Lỗi kết nối mạng: Không thể kết nối tới máy chủ. Vui lòng kiểm tra lại kết nối 4G / Wifi.");
      } else {
        setError(errorMsg);
      }
    } finally {
      setLoading(false);
      hideLoading();
    }
  }

  return (
    <KeyboardAvoidingView
      style={{ flex: 1 }}
      // Android đã có `adjustResize` (window tự co lại khi bàn phím mở) nên KHÔNG dùng
      // `padding` ở đây: cộng dồn thêm padding khiến vùng hiển thị co về gần 0 và bàn phím
      // bị hệ thống đóng ngay (lỗi gặp trên Samsung M51).
      behavior={Platform.OS === "ios" ? "padding" : undefined}
    >
      <ScrollView
        style={{ flex: 1 }}
        contentContainerStyle={{ flexGrow: 1, justifyContent: "center", padding: 24 }}
        keyboardShouldPersistTaps="always"
        keyboardDismissMode="none"
      >
        <TouchableOpacity activeOpacity={0.9} onPress={handleLogoPress}>
          <Image source={require("../assets/icon.png")} style={s.logo} />
        </TouchableOpacity>
        <Text style={s.subtitle}>Đăng nhập tài xế</Text>

        {error ? (
          <View style={s.errorBox}>
            <Ionicons name="alert-circle" size={20} color="#DC2626" />
            <Text style={s.errorText}>{error}</Text>
          </View>
        ) : null}

        <TextInput
          ref={emailRef}
          style={s.input}
          placeholder="Mã NV, Email hoặc Số điện thoại"
          placeholderTextColor="#9CA3AF"
          value={email}
          onChangeText={(val) => {
            setEmail(val);
            if (error) setError("");
          }}
          onFocus={() => handleInputFocus("email")}
          onBlur={() => handleInputBlur("email")}
          onSubmitEditing={() => focusField("password")}
          autoCapitalize="none"
          keyboardType="default"
          autoCorrect={false}
          autoComplete="off"
          importantForAutofill="no"
          textContentType="none"
          returnKeyType="next"
        />

        <TextInput
          ref={passwordRef}
          style={s.input}
          placeholder="Mật khẩu"
          placeholderTextColor="#9CA3AF"
          value={password}
          onChangeText={(val) => {
            setPassword(val);
            if (error) setError("");
          }}
          onFocus={() => handleInputFocus("password")}
          onBlur={() => handleInputBlur("password")}
          onSubmitEditing={handleLogin}
          secureTextEntry
          autoCapitalize="none"
          autoCorrect={false}
          autoComplete="off"
          importantForAutofill="no"
          textContentType="none"
          returnKeyType="go"
        />

        {/* Nút tích Lưu thông tin đăng nhập */}
        <TouchableOpacity
          style={s.rememberRow}
          onPress={() => {
            suppressRefocus.current = true;
            setRememberMe(!rememberMe);
          }}
          activeOpacity={0.7}
        >
          <View style={[s.checkbox, rememberMe && s.checkboxChecked]}>
            {rememberMe && <Ionicons name="checkmark" size={14} color="#fff" />}
          </View>
          <Text style={s.rememberText}>Lưu thông tin đăng nhập</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[s.btn, loading && s.btnDisabled]}
          onPress={handleLogin}
          disabled={loading}
        >
          <Text style={s.btnText}>{loading ? "Đang đăng nhập..." : "Đăng nhập"}</Text>
        </TouchableOpacity>
      </ScrollView>

      <UiDebugPanel visible={debugVisible} onClose={() => setDebugVisible(false)} />
    </KeyboardAvoidingView>
  );
}

const s = StyleSheet.create({
  container: { backgroundColor: "#F9FAFB" },
  logo: { width: 120, height: 120, alignSelf: "center", marginBottom: 16, resizeMode: "contain" },
  subtitle: { fontSize: 14, color: "#6B7280", textAlign: "center", marginBottom: 28 },
  input: {
    backgroundColor: "#fff",
    padding: 14,
    borderRadius: 12,
    fontSize: 16,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    marginBottom: 12,
    color: "#111827",
  },
  rememberRow: {
    flexDirection: "row",
    alignItems: "center",
    marginBottom: 16,
    marginTop: 2,
  },
  checkbox: {
    width: 20,
    height: 20,
    borderRadius: 6,
    borderWidth: 1.5,
    borderColor: "#9CA3AF",
    backgroundColor: "#fff",
    alignItems: "center",
    justifyContent: "center",
    marginRight: 8,
  },
  checkboxChecked: {
    backgroundColor: "#4F46E5",
    borderColor: "#4F46E5",
  },
  rememberText: {
    fontSize: 14,
    color: "#4B5563",
    fontWeight: "500",
  },
  btn: { backgroundColor: "#4F46E5", padding: 16, borderRadius: 12, alignItems: "center", marginTop: 8 },
  btnDisabled: { opacity: 0.6 },
  btnText: { color: "#fff", fontSize: 16, fontWeight: "600" },
  errorBox: {
    flexDirection: "row",
    alignItems: "center",
    backgroundColor: "#FEE2E2",
    borderColor: "#FCA5A5",
    borderWidth: 1,
    borderRadius: 10,
    paddingHorizontal: 12,
    paddingVertical: 10,
    marginBottom: 16,
    gap: 8,
  },
  errorText: {
    color: "#DC2626",
    fontSize: 14,
    fontWeight: "500",
    flex: 1,
  },
});
