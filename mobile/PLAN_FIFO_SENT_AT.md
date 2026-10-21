# KẾ HOẠCH & HƯỚNG DẪN CẬP NHẬT MOBILE APP (FIFO & THỜI GIAN GỬI LỆNH)

Tài liệu này tổng hợp toàn bộ các thay đổi:
1. **Phần Backend API**: Đã hoàn thành và kiểm thử tự động (285/285 tests passed) trên server.
2. **Phần Mobile App**: Hướng dẫn chi tiết từng file, vị trí dòng, code cần thay đổi để bạn thực hiện khi về máy local.

---

## I. TỔNG QUAN YÊU CẦU

1. **Yêu cầu 1 (FIFO)**: Các đơn hàng / chuyến xe gán cho lái xe trên app: cái nào gửi lệnh cho lái xe trước thì phải nằm trên cùng, cái nào gửi sau thì nằm ở dưới để lái xe làm lần lượt theo thứ tự tiếp nhận.
2. **Yêu cầu 2 (Thời gian gửi lệnh)**: Thêm hiển thị "Thời gian gửi lệnh" (`sent_at`) trên thẻ chuyến xe / đơn hàng trên app để lái xe nắm rõ đơn nào nhận được trước, đơn nào nhận được sau.

---

## II. CÁC THAY ĐỔI ĐÃ THỰC HIỆN TRÊN BACKEND API (ĐÃ XONG TRÊN SERVER)

Toàn bộ logic backend đã được triển khai và kiểm thử 285 tests thành công:

| File | Nội dung đã sửa |
| :--- | :--- |
| [`app/Models/Trip.php`](file:///var/www/tms-asgt/app/Models/Trip.php) | Thêm accessor `getSentAtAttribute()` tự động lấy thời điểm gửi lệnh sớm nhất từ các đơn hàng con trong chuyến (`orders.sent_at`), fallback về `created_at`. |
| [`app/Http/Resources/TripResource.php`](file:///var/www/tms-asgt/app/Http/Resources/TripResource.php) | Thêm trường `'sent_at' => $this->sent_at?->toIso8601String()` vào resource chuyến đi trả về cho mobile app. |
| [`app/Http/Controllers/Api/TripController.php`](file:///var/www/tms-asgt/app/Http/Controllers/Api/TripController.php) | Cập nhật cả 2 method `active()` và `current()`: Sắp xếp các chuyến chờ (`pending`) theo `first_sent_at ASC` (FIFO - gửi trước lên trên cùng). Chuyến đang chạy (`started`, `delivering`...) vẫn luôn giữ ưu tiên cao nhất ở trên cùng. |
| [`app/Http/Controllers/Api/OrderController.php`](file:///var/www/tms-asgt/app/Http/Controllers/Api/OrderController.php) | Cập nhật method `index()`: Sắp xếp các đơn hàng theo `COALESCE(sent_at, created_at) ASC` (FIFO). |
| [`tests/Feature/DriverTripFifoOrderTest.php`](file:///var/www/tms-asgt/tests/Feature/DriverTripFifoOrderTest.php) | Tạo test case tự động kiểm tra thứ tự FIFO và trường `sent_at` (Passed 100%). |

---

## III. HƯỚNG DẪN CỤ THỂ 4 VỊ TRÍ CẦN SỬA TRÊN MOBILE APP (LOCAL)

Thư mục gốc của app: `mobile/`

```
mobile/app/
├── (tabs)/
│   ├── index.tsx          (Màn hình Tổng quan - Dashboard)
│   └── trips.tsx          (Màn hình Danh sách chuyến xe)
├── trip-detail.tsx        (Màn hình Chi tiết chuyến đi & đơn con)
└── order-detail.tsx       (Màn hình Chi tiết đơn hàng)
```

---

### 1. File `mobile/app/(tabs)/index.tsx` (Màn hình Tổng quan)

#### 1.1. Sửa hàm sắp xếp FIFO (Dòng ~63-65)
Hiện tại app chỉ phân biệt chuyến đang chạy và chuyến chờ, nhưng các chuyến chờ chưa được sort theo thời gian gửi lệnh:

**Code cũ:**
```tsx
  const isCurrentTrip = (t: any) => t.status !== "pending";
  const sortedTrips = [...activeTrips].sort((a, b) => (isCurrentTrip(b) ? 1 : 0) - (isCurrentTrip(a) ? 1 : 0));
```

**Thay bằng code mới:**
```tsx
  const isCurrentTrip = (t: any) => t.status !== "pending";
  const sortedTrips = [...activeTrips].sort((a, b) => {
    const curA = isCurrentTrip(a) ? 1 : 0;
    const curB = isCurrentTrip(b) ? 1 : 0;
    if (curA !== curB) return curB - curA;

    // FIFO: Chuyến gửi lệnh trước (hoặc tạo trước) nằm trên cùng
    const timeA = new Date(a.sent_at || a.created_at || 0).getTime();
    const timeB = new Date(b.sent_at || b.created_at || 0).getTime();
    return timeA - timeB;
  });
```

#### 1.2. Thêm hiển thị "📤 Gửi lệnh" trên card (Dòng ~218-224)
Bổ sung dòng thời gian gửi lệnh ngay trên dòng "Đóng hàng":

**Code sửa đổi:**
```tsx
                <Text style={st.tripKm}>
                  📏 {t.total_km ?? "-"} km · {t.start_km != null ? `${t.start_km} → ${t.end_km ?? "?"}` : "Chưa có Km"}
                </Text>
                {t.sent_at ? (
                  <Text style={st.sentTime}>
                    📤 Gửi lệnh: {new Date(t.sent_at).toLocaleString("vi-VN")}
                  </Text>
                ) : null}
                {(() => {
                  const loadingTimes = (t.orders || []).map((o: any) => o.planned_loading_at).filter(Boolean);
                  if (loadingTimes.length === 0) return null;
                  return <Text style={st.loadingTime}>🕐 Đóng hàng: {new Date(loadingTimes[0]).toLocaleString("vi-VN")}</Text>;
                })()}
```

#### 1.3. Thêm Style cho `sentTime` (Trong `StyleSheet.create` ở cuối file)
```tsx
  sentTime: {
    fontSize: 11,
    color: "#4F46E5",
    marginTop: 4,
    fontWeight: "600",
  },
```

---

### 2. File `mobile/app/(tabs)/trips.tsx` (Màn hình Chuyến đi)

#### 2.1. Sửa hàm sắp xếp (Dòng ~277-285)
Hiện tại ở dòng 283 đang return `timeB - timeA` (DESC - chuyến mới nhất lên trên). Với chuyến chờ (`pending`), phải đổi thành `timeA - timeB` (ASC - FIFO):

**Code cũ:**
```tsx
        return result.sort((a: any, b: any) => {
            const curA = isCurrent(a) ? 1 : 0;
            const curB = isCurrent(b) ? 1 : 0;
            if (curA !== curB) return curB - curA;
            const timeA = new Date(a.started_at || a.created_at || 0).getTime();
            const timeB = new Date(b.started_at || b.created_at || 0).getTime();
            return timeB - timeA;
        });
```

**Thay bằng code mới:**
```tsx
        return result.sort((a: any, b: any) => {
            const curA = isCurrent(a) ? 1 : 0;
            const curB = isCurrent(b) ? 1 : 0;
            if (curA !== curB) return curB - curA;

            // Nếu đang trong danh sách chuyến active (chờ chạy hoặc đang chạy):
            // Sắp xếp FIFO theo thời gian gửi lệnh (sent_at / created_at tăng dần)
            if (curA === 1) {
                const timeA = new Date(a.sent_at || a.created_at || 0).getTime();
                const timeB = new Date(b.sent_at || b.created_at || 0).getTime();
                return timeA - timeB;
            }

            // Với các chuyến đã hoàn thành / huỷ: chuyến kết thúc gần nhất lên trên
            const timeA = new Date(a.completed_at || a.started_at || a.created_at || 0).getTime();
            const timeB = new Date(b.completed_at || b.started_at || b.created_at || 0).getTime();
            return timeB - timeA;
        });
```

#### 2.2. Thêm hiển thị "📤 Gửi lệnh" trên card chuyến xe (Dòng ~620)
Ngay dưới dòng `s.kmLine` và trước dòng `loadingTimes`:

```tsx
                            <Text style={s.kmLine}>
                                📏 {fmt(item.total_km)} km ·{" "}
                                {fmt(item.start_km)} →{" "}
                                {isSwapped && handoverKm != null
                                    ? fmt(handoverKm)
                                    : fmt(item.end_km)}
                            </Text>
                            {item.sent_at ? (
                                <Text style={s.sentTime}>
                                    📤 Gửi lệnh:{" "}
                                    {new Date(item.sent_at).toLocaleString("vi-VN")}
                                </Text>
                            ) : null}
```

#### 2.3. Thêm Style cho `sentTime` trong file `trips.tsx`
```tsx
    sentTime: {
        fontSize: 12,
        color: "#4F46E5",
        marginTop: 2,
        fontWeight: "600",
    },
```

---

### 3. File `mobile/app/trip-detail.tsx` (Chi tiết chuyến xe)

Trong danh sách các đơn hàng con của chuyến đi (Dòng ~1236-1243):

**Bổ sung hiển thị `sent_at`:**
```tsx
                                        {o.sent_at && (
                                            <Text style={s.orderSent}>
                                                📤 Gửi lệnh:{" "}
                                                {new Date(
                                                    o.sent_at,
                                                ).toLocaleString("vi-VN")}
                                            </Text>
                                        )}
                                        {o.planned_loading_at && (
                                            <Text style={s.orderLoading}>
                                                🕐 Đóng hàng:{" "}
                                                {new Date(
                                                    o.planned_loading_at,
                                                ).toLocaleString("vi-VN")}
                                            </Text>
                                        )}
```

**Thêm Style `orderSent`:**
```tsx
    orderSent: {
        fontSize: 12,
        color: "#4F46E5",
        marginTop: 2,
        fontWeight: "600",
    },
```

---

### 4. File `mobile/app/order-detail.tsx` (Chi tiết đơn hàng)

Trong khối hiển thị thông tin thẻ tóm tắt (Dòng ~641-657):

**Thêm ô thông tin "Gửi lệnh":**
```tsx
                        {
                            icon: "paper-plane",
                            label: "Gửi lệnh",
                            value: d.sent_at
                                ? new Date(d.sent_at).toLocaleString("vi-VN")
                                : "-",
                        },
                        {
                            icon: "time",
                            label: "Đóng hàng",
                            value: d.planned_loading_at
                                ? new Date(d.planned_loading_at).toLocaleString(
                                      "vi-VN",
                                  )
                                : "-",
                        },
```

---

## IV. CÁC LỆNH KIỂM TRA & BUILD TẠI LOCAL

Sau khi sửa xong 4 file trên, bạn có thể kiểm tra và build app:

1. **Chạy app kiểm thử tại local:**
   ```bash
   cd mobile
   npx expo start
   ```

2. **Build bản Web (dùng cho PWA hoặc chạy trên trình duyệt):**
   ```bash
   npm --prefix mobile run build:web
   ```

3. **Build bản cài đặt Android APK (khi sẵn sàng xuất bản):**
   ```bash
   npm --prefix mobile run build:android:apk
   ```
