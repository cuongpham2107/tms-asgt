# Kế hoạch: dùng React trong Filament 5.11, làm dần từng màn hình

> Tài liệu bàn giao cho agent coding. Viết ngày 31/10/2026, sau khi nâng Filament 5.8.1 → 5.11.0 (commit `9fa036b2`).
> Làm **lần lượt từng giai đoạn**, mỗi giai đoạn là một (hoặc vài) commit riêng, chạy test và để người dùng kiểm tra trên trình duyệt trước khi sang giai đoạn sau.

## 0. Bối cảnh

- Laravel 13, PHP 8.4, Filament **5.11.0**, Livewire **4.4.8**, Pest 4, Vite 8, Tailwind 4, DB SQLite.
- App là panel Filament `app` (`app/Providers/Filament/AppPanelProvider.php`), đã bật `->spa()`.
- Lý do làm: các màn hình custom (Blade + Alpine) gửi lại cả khối HTML mỗi lần tương tác. Ví dụ trang Thống kê tốn khoảng **311KB HTML / 25 dòng** cho mỗi lần đổi trang (đã giảm từ 540KB). React nhận JSON rồi tự vẽ, nên nhẹ hơn và mượt hơn.
- Filament 5.11 hỗ trợ sẵn 3 loại renderer JS (đọc kỹ trước khi làm):
  - Ô nhập form: https://filamentphp.com/docs/5.x/forms/custom-fields
  - Component trong schema: https://filamentphp.com/docs/5.x/schemas/custom-components
  - Widget: https://filamentphp.com/docs/5.x/widgets/custom-widgets
- Khai báo kiểu TypeScript nằm trong vendor:
  - `vendor/filament/forms/resources/js/types/js-field.d.ts`
  - `vendor/filament/schemas/resources/js/types/js-component.d.ts`
  - `vendor/filament/widgets/resources/js/types/js-widget.d.ts`

### Tóm tắt API (theo tài liệu Filament 5.x)

| | Lệnh tạo | PHP → React | React → PHP |
|---|---|---|---|
| Ô nhập form | `php artisan make:filament-form-field Ten --react --ts` | `props.value`, `props.configuration` (từ `->rendererConfiguration([...])`) | `props.onChange(giáTrịMới)` (nguyên giá trị, JSON được, rỗng là `null`). Cập nhật server theo `live()` / `live(onBlur: true)` / `live(debounce: 500)` |
| Component schema | `php artisan make:filament-schema-component Ten --react --ts` | `props.configuration` | `utilities.$get/$set` các ô khác; hàm PHP gắn `#[Exposed]` gọi bằng `utilities.$tenHam({...})` (component phải có `->key()`) |
| Widget | `php artisan make:filament-widget Ten --react --ts` | `getRendererConfiguration(): array` (đổi thì `update()` được gọi, không mount lại) | `utilities.$wire.$call('tenHamPublic', ...)` |

- File renderer mặc định export hàm `mountTen({ host, props, utilities })`. Hàm này `createRoot(host)` và trả về `{ update(props), destroy() }`.
- **Giới hạn quan trọng:**
  - Trong `host` không dùng được Blade, Alpine hay Livewire component.
  - Không có cầu nối sẵn cho action/modal/upload của Filament: phải gọi qua `$wire` hoặc phát event để trang mở modal.
  - CSS mà Vite tách ra không tự nạp: đưa style vào `resources/css/filament/app/theme.css` hoặc dùng class Tailwind.
  - `#[Exposed]` **không** tự kiểm tra quyền: validate tham số và kiểm tra quyền trong hàm PHP.
  - Hàm chỉ đọc dữ liệu thì thêm `#[Renderless]` để không render lại component.
  - Lỗi khi mount / update thì Filament hiện thông báo lỗi; phải dọn listener trong `destroy()`.

## 1. Quy tắc bắt buộc cho agent

1. Đọc `CLAUDE.md` và `AGENTS.md` ở gốc repo, cùng `.ai/rules/index.md` (nếu có), trước khi sửa file.
2. **Không làm hỏng thứ đang chạy.** Mỗi màn hình chuyển sang React phải đạt đủ chức năng như bản Blade cũ trước khi xoá bản cũ. Dùng cờ cấu hình để bật/tắt (xem từng giai đoạn) cho tới khi người dùng xác nhận.
3. Sửa PHP xong chạy `vendor/bin/pint --dirty --format agent`.
4. **Test:**
   - Chạy test liên quan bằng `vendor/bin/pest --compact tests/Feature/<File>.php` (không dùng `php artisan test` cho cả bộ, vì bị tràn bộ nhớ khi chạy chung).
   - Chạy cả bộ thì dùng `php -d memory_limit=2G vendor/bin/pest --compact`.
   - Lỗi có sẵn từ trước, **không phải do bạn**:
     - `CustomerLocationSeederTest`, `SyncEmployeeCodeTest` (thiếu file dữ liệu).
     - `TripWebCompletionKmTest` (Scenario 5).
     - Migration `database/migrations/2026_10_31_133850_create_billing_zones_table.php` **cố ý không có `timestamps()`** (người dùng chọn để nguyên). Vì vậy 4 file test cước (`FreightRateResourcesTest`, `ImportFreightRatesCommandTest`, `OrderChargeCalculatorTest`, `TripStatisticsChargeTest`) báo lỗi `updated_at`. Muốn chạy các test này thì **tạm** thêm `$table->timestamps();` vào migration, chạy xong **trả lại nguyên trạng**. Không commit thay đổi đó.
5. Thêm dependency JS (react, react-dom, @vitejs/plugin-react, typescript, @types/react…) **được phép** cho kế hoạch này. Không thêm gói PHP.
6. Comment trong code viết tiếng Việt, ngắn, theo phong cách code xung quanh.
7. Commit theo dạng `feat(...)/fix(...)/refactor(...)` tiếng Việt, kết thúc bằng dòng `Co-Authored-By` theo cấu hình agent. Commit trên `main` như các commit trước (người dùng làm việc trực tiếp trên `main`), hoặc theo chỉ dẫn của người dùng.
8. `public/build` nằm trong `.gitignore`: server tự build bằng `npm ci && npm run build` khi deploy. Ghi lệnh deploy cần thêm vào phần báo cáo cuối mỗi giai đoạn.
9. **Không động vào** `public/vendor/livewire` (đã publish đúng bản, `composer update` tự cập nhật).

## 2. Việc dở dang cần xử lý trước (giai đoạn 0)

- **Realtime GPS đã xong** (commit `f31f0cf0`), không cần làm lại:
  - `app/Events/VehiclesSynced.php`: event trên kênh `vehicles`.
  - `app/Services/EupGpsService.php`: phát event sau mỗi lần đồng bộ có xe cập nhật, bọc trong `rescue()`.
  - `app/Filament/Pages/VehicleDispatchBoard.php`: nghe `#[On('echo:vehicles,.VehiclesSynced')]`.
  - Giai đoạn 4 (bản đồ) dùng lại event này.
- **Reverb trên server chưa chạy:** lúc kiểm tra, `https://tms.asgl.net.vn/app/...` trả 404. Đây là việc của người vận hành (sửa `.env`, chạy `php artisan reverb:start` bằng pm2, chuyển tiếp `location ~ ^/(app|apps)/` sang `127.0.0.1:8080` qua nginx). Agent **không** giãn `->poll('20s')` của Kiểm soát xe cho tới khi người dùng xác nhận websocket đã hoạt động (DevTools → Network → WS trả 101).

## 3. Giai đoạn 1: dựng nền React và làm thử với "Lưới bảng giá theo bậc tải"

Mục tiêu: dựng sẵn bộ công cụ React/TS bằng một màn hình nhỏ, độc lập và ít rủi ro.

**Dựng nền:**
1. `npm install` rồi chạy lệnh tạo đầu tiên với `--react --ts`. Lệnh tự cài react/react-dom và chỉnh `vite.config.js`. Nếu lệnh không chỉnh được thì làm theo hướng dẫn nó in ra (mục "Building lazy-loaded ES modules" trong tài liệu assets của Filament).
2. Kiểm tra `vite.config.js` vẫn giữ 3 input hiện có (`resources/css/app.css`, `resources/js/app.js`, `resources/css/filament/app/theme.css`), cùng plugin `tailwindcss()` và font Bunny.
3. `tsconfig.json`: alias `@filament/forms/js-field`, `@filament/schemas/js-component`, `@filament/widgets/js-widget` trỏ tới các file `.d.ts` ở mục 0.
4. `npm run build` phải chạy được; thêm script `"typecheck": "tsc --noEmit"` vào `package.json`.

**Ô nhập `TonnagePriceGrid`** (`php artisan make:filament-form-field TonnagePriceGrid --react --ts` → `app/Filament/Forms/Components/TonnagePriceGrid.php` + `resources/js/filament/forms/components/tonnage-price-grid.tsx`):
- **Thay** `KeyValue::make('prices')` trong `app/Filament/Resources/FreightRates/Schemas/FreightRateForm.php`.
- **Giá trị:** object `{ "1.25": 750000, "5": 1300000, ... }` (khoá là bậc tải dạng chuỗi, giá trị là số). Model `FreightRate` đã chuẩn hoá khoá/giá khi lưu (`FreightRate::tonnageKey()`, `parseMoney()`). **Giữ nguyên** định dạng này.
- **UI:**
  - Một dòng các cột bậc tải chuẩn `1.25, 1.5, 2.5, 3.5, 5, 7, 8, 10, 14` (truyền qua `rendererConfiguration(['tiers' => [...]])`).
  - Mỗi ô là ô nhập tiền có dấu phân cách hàng nghìn kiểu `1.300.000`.
  - Có nút "Thêm bậc khác" cho bậc lạ.
  - **Dán được nguyên một dòng từ Excel** (giá trị cách nhau bằng tab) vào ô đầu tiên để điền liên tiếp các ô.
  - Ô trống thì bỏ khỏi object.
- Lưu bằng `->live(onBlur: true)` hoặc mặc định (gửi khi submit form). Validate phía PHP: ít nhất 1 bậc có giá > 0.
- Dùng lại cho `tiers` của Phí giờ chờ (`app/Filament/Resources/WaitingFeeRates/Schemas/WaitingFeeRateForm.php`, Repeater `[{tonnage, fee, free_minutes}]`) **chỉ khi** làm được gọn bằng một chế độ cấu hình thứ hai (mỗi bậc 2 giá trị: phí và phút miễn phí). Không gọn thì để giai đoạn sau.
- **Test** (`tests/Feature/FreightRateResourcesTest.php` đã có test tạo đơn giá qua form):
  - Cập nhật để điền `prices` dạng object qua form mới; giữ assertion `priceFor(2.5) === 800000.0`.
  - Thêm test: dữ liệu rỗng → lỗi validate.
- **Đạt khi:** tạo / sửa đơn giá trong menu "Đơn giá vận chuyển" và trong nút (+) của modal cước (`addFreightRateAction` trong `app/Filament/Pages/TripStatisticsReport.php`, dùng lại `FreightRateForm`) đều chạy; `npm run build` và `npm run typecheck` sạch.

## 4. Giai đoạn 2: ô chọn xe / lái xe

- **Hiện tại:**
  - `app/Filament/Forms/Components/CardPicker.php` + `resources/views/filament/forms/components/card-picker.blade.php` (333 dòng Blade/Alpine).
  - `VehiclePicker` (276 dòng view) và `DriverPicker` (214 dòng view) kế thừa `CardPicker`.
  - Được dùng ở `OrderForm`, `CreateOrderHHHKAction`, `CreateOrderHNAction`, `AssignTransportAction`, `BulkAssignTransportAction` (thư mục `app/Filament/Resources/Orders/`).
- **Làm:**
  1. Đọc kỹ 3 view hiện tại để liệt kê đủ chức năng (lọc theo khu vực / trạng thái, badge, ảnh, tìm kiếm…).
  2. Tạo ô nhập React `CardPickerField`; giữ **API PHP cũ** (`VehiclePicker::make('vehicle_id')->...`) để không phải sửa nơi gọi.
  3. Danh sách xe / lái xe truyền qua `rendererConfiguration` (closure có `Get`). Nếu danh sách lớn hoặc phụ thuộc ô khác thì dùng hàm `#[Exposed]` + `#[Renderless]` trên component để tải theo yêu cầu.
- **UX:** tìm kiếm tức thì phía client, điều hướng bằng phím ↑ ↓ Enter, giá trị trả về là id (`props.onChange(id)`).
- **Cờ:** `config('app.react_pickers', true)`, tắt được để quay về view cũ trong thời gian kiểm tra.
- **Test:** các test hiện có của `CreateOrderHNTest`, `CreateOrderHHHKAndBulkReturnTripTest`, `AssignTransportActionTest`, `ReassignTransportActionTest` phải vẫn qua (chúng điền `vehicle_id` / `driver_id` qua form data).

## 5. Giai đoạn 3: bảng trang Thống kê (lợi ích lớn nhất)

- **Hiện tại:** `app/Filament/Pages/TripStatisticsReport.php` (1356 dòng) + `resources/views/filament/pages/trip-statistics-report.blade.php` + `resources/views/filament/pages/partials/trip-statistics-row.blade.php`.
- **Dữ liệu đã có sẵn ở phía PHP, giữ nguyên:**
  - `getTripRows()`: mỗi chuyến một dòng, dòng đơn con nằm trong `orders`.
  - `getPaginator()`, `getSummaryMetrics()`, `getTabCounts()`, `getChargeStatusCounts()`.
  - `getChargesForRows()`: cước của các đơn trên trang.
  - Cache dữ liệu theo bộ lọc: `getBaseFilteredRows()`.
- **Cách làm đề xuất:**
  1. Tạo widget React `TripStatisticsTable` (`php artisan make:filament-widget TripStatisticsTable --react --ts`) và nhúng vào trang (thay khối `<table>` trong Blade). Thanh lọc, tab, header actions và các modal Filament **giữ ở trang**.
  2. Widget nhận bộ lọc qua thuộc tính `#[Reactive]` (`startDate`, `endDate`, `activeTab`, `customerId`, `vehicleOwner`, `search`, `chargeStatus`, `page`, `perPage`). Nếu `#[Reactive]` không hợp với widget JS thì cho trang trả JSON qua hàm `#[Renderless]`, React gọi `$wire.$call(...)`.
  3. `getRendererConfiguration()` chỉ trả **JSON của trang hiện tại**: các dòng chuyến cùng dòng đơn con, cột cước (`price_code`, `rate_type`, `base_price`, `fuel_adjustment_percent`, `adjusted_price`, `waiting_fee`, `waiting_minutes`, `other_fees_total`, `vat_amount`, `total`, `exported_at`), tổng thanh toán của chuyến và cờ `showCharges` / `showChargeableWeight`. Kiểu dữ liệu khai báo bằng TS.
  4. **Chức năng phải giữ đủ:**
     - Cột cố định (STT, mã, BSX) khi cuộn ngang.
     - Header 2 tầng có nhóm cột.
     - Màu dòng: đã xuất bảng kê = xanh lá, chưa có giá = vàng.
     - Dòng chuyến có nhiều đơn hiện thêm các dòng đơn con.
     - **Sửa nhanh PCS / GW** ngay trong ô: gọi `$wire.$call('updateOrderMetric', orderId, field, value)` (hàm đã có trên trang).
     - Nút **Sửa cước / Tính lại / Mở khoá**:
       - Sửa cước: mở modal Filament của trang bằng `$wire.$call('mountAction', 'editCharge', { orderId })`. **Thử trước** cách này; không được thì phát event `open-charge` và để trang `#[On('open-charge')]` gọi `mountAction`.
       - Tính lại / Mở khoá: `recalculateCharge(orderId)`, `unlockCharge(orderId)`.
     - Phân trang bằng `<x-filament::pagination>` của trang (giữ ở Blade, ngoài widget).
  5. CSS: dùng class Tailwind; style riêng (sticky, viền header kiểu Safari) đang nằm ở `resources/views/filament/pages/partials/dispatch-table-styles.blade.php` → chuyển phần cần dùng sang `theme.css`.
  6. **Cờ:** `config('app.react_trip_statistics', false)` lúc đầu. Bật để so sánh song song với bảng Blade; khi người dùng xác nhận thì bật mặc định, sau một thời gian ổn định mới xoá partial Blade cũ.
- **Đo:**
  - Ghi lại dung lượng response Livewire khi đổi trang trước và sau (hiện ~311KB tab HHHK, ~458KB tab Hàng ngoài với 25 dòng). **Mục tiêu < 80KB.**
  - Cách đo nhanh: script dùng `Livewire::test(TripStatisticsReport::class, [...])->html()` trên bản sao DB production, đo độ dài.
- **Test:**
  - `tests/Feature/TripStatisticsReportTest.php` (16 test) và `tests/Feature/TripStatisticsChargeTest.php` (14 test). Nhiều assertion đang dựa vào HTML như `assertSee`, `assertSeeInOrder`: khi bật React, chuyển sang kiểm tra **dữ liệu cấu hình** của widget (`getRendererConfiguration()`).
  - Giữ test của phương thức PHP (`getTripRows`, metrics, bộ lọc trạng thái cước).

## 6. Giai đoạn 4: bản đồ theo dõi xe

- **Hiện tại:** `app/Filament/Pages/GoogleMapTracking.php` (636 dòng, dùng plugin `eduardoribeirodev/filament-leaflet` qua `HasMapConfig`, `MarkerCluster`) + `resources/views/filament/pages/google-map-tracking.blade.php` + widget `app/Filament/Widgets/GoogleMapSidebar.php`.
- **Làm:**
  - Widget React (react-leaflet + leaflet.markercluster, hoặc Leaflet thuần trong React) giữ bản đồ cố định, chỉ cập nhật marker.
  - Dữ liệu xe qua hàm `#[Renderless]` trả JSON (vị trí, tốc độ, trạng thái máy, chuyến hiện tại).
  - Làm mới khi có event `echo:vehicles,.VehiclesSynced` (từ giai đoạn 0); không có Reverb thì tự gọi lại sau 30s bằng `setInterval` trong React và dọn trong `destroy()`.
- **Giữ đủ:** lọc theo ngày, chọn nhiều xe, chú thích trạng thái, sidebar danh sách xe, bấm marker hoặc bấm bản đồ (`handleLayerClick`, `handleMapClick`).
- **Cờ:** `config('app.react_tracking_map', false)`.

## 7. Không chuyển sang React (ghi rõ lý do)

- **Kiểm soát tình trạng xe** (`app/Filament/Pages/VehicleDispatchBoard.php`):
  - Đây là **bảng Filament thật**, có filter, group theo địa điểm và các action có modal (`ReassignDriverAction`, `ResolveKmReportAction`, xem chuyến, xem GPS). Viết lại bằng React sẽ mất các tính năng này.
  - Hướng làm: giữ bảng Filament, dựa vào Reverb (giai đoạn 0). Khi người dùng xác nhận websocket chạy, giãn `->poll('20s')` thành `->poll('120s')`.
- **`PillFilter`, `OrderDateRangePicker`**: đơn giản, đang chạy tốt.
- **Widget biểu đồ ở Dashboard**: đã dùng chart có sẵn của Filament.

## 8. Dọn mã chết (làm cuối, mỗi mục kiểm tra lại trước khi xoá)

- `resources/views/filament/pages/real-time-tracking.blade.php` (962 dòng): không page nào dùng (`grep -rn "real-time-tracking" app resources routes` không ra kết quả).
- `app/Filament/Forms/Components/MapboxLocationPicker.php`, `resources/views/forms/components/mapbox-location-picker.blade.php`, `resources/js/mapbox-location-picker.js`: không form nào gọi `MapboxLocationPicker::make`. Form Địa điểm đang dùng `MapPicker` của plugin Leaflet. Nếu xoá thì cân nhắc gỡ gói npm `mapbox-gl`, **hỏi người dùng trước**.

## 9. Mẫu báo cáo cuối mỗi giai đoạn

1. Commit (mã + tiêu đề).
2. Kết quả test: số test qua; lỗi nào là lỗi có sẵn (mục 1.4).
3. Số đo trước / sau (nếu có).
4. Lệnh deploy cần chạy trên server, thường là:
   ```bash
   cd /var/www/tms-asgt && git pull origin main && composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan optimize:clear && php artisan filament:optimize && pm2 restart tms-queue
   ```
   **Không** chạy lệnh này trên máy local (`--no-dev` sẽ gỡ Pest / Pint).
5. Những gì người dùng cần tự kiểm tra trên trình duyệt (Safari và Chrome), và cách bật / tắt cờ.
