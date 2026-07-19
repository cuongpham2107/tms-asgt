import { useState } from "react";
import { View, Text, TextInput, TouchableOpacity, StyleSheet, Image } from "react-native";
import { router } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { login, api } from "../src/lib/api";

export default function LoginScreen() {
  const { setAuth } = useAuth();
  const [email, setEmail] = useState("cvt2307b@tms.local");
  const [password, setPassword] = useState("66668888");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleLogin() {
    setLoading(true); setError("");
    try {
      const res = await login(email, password);
      if (!res.token) return setError("Sai tài khoản hoặc mật khẩu");

      // Lấy shift đang active (nếu có)
      const shiftRes = await api.shifts.current(res.token).catch(() => null);
      const shift = shiftRes?.shift;
      setAuth(res.token, shift?.id ? String(shift.id) : undefined, shift);
      router.replace("/");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Không thể kết nối đến máy chủ");
    } finally {
      setLoading(false);
    }
  }

  return (
    <View style={s.container}>
      <Image source={require("../assets/icon.png")} style={s.logo} />
      <Text style={s.subtitle}>Đăng nhập tài xế</Text>
      {error ? <Text style={s.error}>{error}</Text> : null}
      <TextInput style={s.input} placeholder="Email" placeholderTextColor="#9CA3AF" value={email} onChangeText={setEmail} autoCapitalize="none" keyboardType="email-address" />
      <TextInput style={s.input} placeholder="Mật khẩu" placeholderTextColor="#9CA3AF" value={password} onChangeText={setPassword} secureTextEntry />
      <TouchableOpacity style={[s.btn, loading && s.btnDisabled]} onPress={handleLogin} disabled={loading}>
        <Text style={s.btnText}>{loading ? "Đang đăng nhập..." : "Đăng nhập"}</Text>
      </TouchableOpacity>
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, justifyContent: "center", padding: 24, backgroundColor: "#F9FAFB" },
  logo: { width: 120, height: 120, alignSelf: "center", marginBottom: 16, resizeMode: "contain" },
  subtitle: { fontSize: 14, color: "#6B7280", textAlign: "center", marginBottom: 32 },
  input: { backgroundColor: "#fff", padding: 14, borderRadius: 12, fontSize: 16, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 12, color: "#111827" },
  btn: { backgroundColor: "#4F46E5", padding: 16, borderRadius: 12, alignItems: "center", marginTop: 8 },
  btnDisabled: { opacity: 0.6 },
  btnText: { color: "#fff", fontSize: 16, fontWeight: "600" },
  error: { color: "#EF4444", textAlign: "center", marginBottom: 12 },
});
