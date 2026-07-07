# ReassignDriverAction - Tạo chuyến quay đầu

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thêm checkbox "Tạo chuyến quay đầu" vào ReassignDriverAction để khi gán lại tài xế có thể tạo kèm một chuyến không hàng (Pending, 2 checkpoints Started + Completed).

**Architecture:** Sửa trực tiếp `ReassignDriverAction.php` — thêm 2 form fields (Checkbox + Select) và logic tạo Trip + 2 TripCheckpoints trong action handler. Không tạo file/service mới.

**Tech Stack:** Laravel 13, Filament v5, SQLite

## Global Constraints

- Trip status cho chuyến quay đầu: `Pending`
- Checkpoints tạo trực tiếp (không qua TripCheckpointService vì trip không có orders)
- Vehicle select options: ưu tiên xe của chuyến hiện tại + xe của tài xế mới (`vehiclesAsDriver`)
- Không migration, không enum, không service mới

---

### Task 1: Thêm form fields và logic tạo chuyến quay đầu

**Covers:** [S1, S2, S3]

**Files:**
- Modify: `app/Filament/Resources/Trips/Actions/ReassignDriverAction.php`

**Interfaces:**
- Produces: checkbox `create_return_trip`, select `return_vehicle_id` trong form
- Creates: `Trip` mới (Pending, không orders), 2 `TripCheckpoint` (Started + Completed)

- [ ] **Step 1: Thêm import cho Checkbox**

```php
use Filament\Forms\Components\Checkbox;
```

Thêm vào block `use Filament\Forms\Components\Select;` (dòng 16).

- [ ] **Step 2: Thêm form fields sau `reason`**

Sau `Select::make('reason')...->required(),` (dòng 81), thêm:

```php
Checkbox::make('create_return_trip')
    ->label('Tạo chuyến không hàng (quay đầu)')
    ->helperText('Tạo chuyến đi rỗng cho tài xế mới để nhập km')
    ->live(),
Select::make('return_vehicle_id')
    ->label('Xe cho chuyến quay đầu')
    ->options(function (Trip $record, callable $get): array {
        $newDriverId = $get('new_driver_id');
        $vehicles = collect();

        // Xe của chuyến hiện tại
        $currentVehicle = $record->vehicle;
        if ($currentVehicle) {
            $vehicles->push($currentVehicle);
        }

        // Xe của tài xế mới
        if ($newDriverId) {
            $newDriver = User::find($newDriverId);
            if ($newDriver) {
                $driverVehicles = $newDriver->vehiclesAsDriver()
                    ->select('id', 'plate_number')
                    ->get();
                $vehicles = $vehicles->merge($driverVehicles);
            }
        }

        return $vehicles->unique('id')
            ->mapWithKeys(fn ($v) => [$v->id => $v->plate_number])
            ->all();
    })
    ->visible(fn (callable $get): bool => (bool) $get('create_return_trip'))
    ->searchable()
    ->required(),
```

- [ ] **Step 3: Thêm logic tạo chuyến quay đầu trong action handler**

Sau notification success (dòng 164), thêm:

```php
if (! empty($data['create_return_trip']) && ! empty($data['return_vehicle_id'])) {
    $returnTrip = Trip::create([
        'trip_code' => Trip::generateTripCode(),
        'vehicle_id' => $data['return_vehicle_id'],
        'driver_id' => $data['new_driver_id'],
        'shift_id' => $newShift?->id,
        'status' => TripStatus::Pending,
    ]);

    $now = now();

    TripCheckpoint::create([
        'trip_id' => $returnTrip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $now,
        'driver_id' => $data['new_driver_id'],
        'shift_id' => $newShift?->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $returnTrip->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $now,
        'driver_id' => $data['new_driver_id'],
        'shift_id' => $newShift?->id,
    ]);

    Notification::make()
        ->success()
        ->title('Đã tạo chuyến quay đầu')
        ->body("Chuyến không hàng #{$returnTrip->trip_code} đã được tạo cho tài xế {$newDriver->name}")
        ->send();
}
```

Việc tạo checkpoints nằm trong cùng `DB::transaction` nếu muốn atomicity — nhưng action handler hiện tại không wrap trong transaction. Để nhất quán, không thêm transaction ở đây.

- [ ] **Step 4: Chạy test để xác nhận không có lỗi cú pháp/import**

```bash
php artisan test --compact --filter=ReassignDriver
```

(Nếu chưa có test cho ReassignDriverAction, kiểm tra ít nhất là không có lỗi PHP syntax bằng `php -l`)

- [ ] **Step 5: Chạy Pint format**

```bash
vendor/bin/pint --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Trips/Actions/ReassignDriverAction.php
git commit -m "feat: add return trip creation to reassign driver action"
```
