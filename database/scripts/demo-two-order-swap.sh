#!/bin/bash
set -euo pipefail
# ── Demo: Driver A làm 2 đơn — hết ca → swap → Driver B hoàn tất đơn 2 ──
#
# KM kỳ vọng:
#   Driver A (hoàn tất đơn 1 + đơn 2 dở dang):
#     total_km       = 10060 - 10000 = 60
#     loaded         = (10030-10010) + (10060-10040) = 20+20 = 40
#     empty          = 60-40 = 20
#   Driver B (hoàn tất đơn 2):
#     total_km       = 10100 - 10060 = 40
#     loaded         = 10090 - 10060 = 30
#     empty          = 40-30 = 10
#
# Usage:
#   1. php artisan serve --port=8080
#   2. APP_URL=http://localhost:8080 bash database/scripts/demo-two-order-swap.sh

BASE="${APP_URL:-http://localhost}"
BASE="${BASE%/}"

# ─── Helpers ──────────────────────────────────────────────────────────
step()  { local n=$1 msg=$2; echo; echo "─── [$n/$TOTAL] $msg ───"; }
info()  { echo "  ℹ️  $*"; }
ok()    { echo "  ✅ $*"; }
fail()  { echo "  ⛔ $*"; exit 1; }
json()  { if command -v jq &>/dev/null; then jq; else cat; fi; }

# ─── Seed + Resolve IDs ──────────────────────────────────────────────
echo ">>> Đang seed dữ liệu demo..."

TMPFILE=$(mktemp /tmp/seed_XXXXXX.txt)
cat << 'SEEDPHP' > "$TMPFILE"
$now = now();
$pwd = Hash::make("password");
$role = Spatie\Permission\Models\Role::firstOrCreate(["name"=>"driver","guard_name"=>"web"]);

DB::table("users")->upsert(["name"=>"Nguyen Van A","email"=>"driver.a@example.com","password"=>$pwd,"email_verified_at"=>$now,"is_active"=>true,"created_at"=>$now,"updated_at"=>$now],["email"],["name","password","created_at","updated_at","is_active"]);
$da = DB::table("users")->where("email","driver.a@example.com")->value("id");
DB::table("model_has_roles")->upsert(["role_id"=>$role->id,"model_type"=>"App\Models\User","model_id"=>$da],["role_id","model_id","model_type"],["role_id","model_id","model_type"]);

DB::table("users")->upsert(["name"=>"Nguyen Van B","email"=>"driver.b@example.com","password"=>$pwd,"email_verified_at"=>$now,"is_active"=>true,"created_at"=>$now,"updated_at"=>$now],["email"],["name","password","created_at","updated_at","is_active"]);
$db = DB::table("users")->where("email","driver.b@example.com")->value("id");
DB::table("model_has_roles")->upsert(["role_id"=>$role->id,"model_type"=>"App\Models\User","model_id"=>$db],["role_id","model_id","model_type"],["role_id","model_id","model_type"]);

DB::table("vehicles")->upsert(["plate_number"=>"99A-99999","vehicle_type"=>"normal","owner"=>"ASGT","current_mileage"=>10000,"is_active"=>true,"status"=>"on","type"=>"company","created_at"=>$now,"updated_at"=>$now],["plate_number"],["vehicle_type","owner","current_mileage","is_active","status","type","updated_at"]);
$v = DB::table("vehicles")->where("plate_number","99A-99999")->value("id");

DB::table("customers")->upsert(["code"=>"SWAPDEMO","name"=>"Khach hang Swap Demo","is_active"=>true,"created_at"=>$now,"updated_at"=>$now],["code"],["name","is_active","updated_at"]);
$c = DB::table("customers")->where("code","SWAPDEMO")->value("id");

DB::table("order_categories")->updateOrInsert(["type"=>"HHHK","code"=>"SWAPDEMO"],["name"=>"Swap Demo Route","sort_order"=>99,"is_active"=>true,"created_at"=>$now,"updated_at"=>$now]);
$cat = DB::table("order_categories")->where("type","HHHK")->where("code","SWAPDEMO")->value("id");

DB::table("orders")->upsert(["order_code"=>"ORD-SWAP-001","type"=>"HHHK","order_category_id"=>$cat,"customer_id"=>$c,"vehicle_id"=>$v,"driver_id"=>$da,"status"=>"sent","is_return_trip"=>false,"created_by"=>$da,"created_at"=>$now,"updated_at"=>$now],["order_code"],["type","order_category_id","customer_id","vehicle_id","driver_id","status","updated_at"]);
$o1 = DB::table("orders")->where("order_code","ORD-SWAP-001")->value("id");
DB::table("orders")->upsert(["order_code"=>"ORD-SWAP-002","type"=>"HHHK","order_category_id"=>$cat,"customer_id"=>$c,"vehicle_id"=>$v,"driver_id"=>$da,"status"=>"sent","is_return_trip"=>false,"created_by"=>$da,"created_at"=>$now,"updated_at"=>$now],["order_code"],["type","order_category_id","customer_id","vehicle_id","driver_id","status","updated_at"]);
$o2 = DB::table("orders")->where("order_code","ORD-SWAP-002")->value("id");

DB::table("order_delivery_points")->insert(["order_id"=>$o1,"sequence"=>1,"status"=>"pending","created_at"=>$now,"updated_at"=>$now]);
$DP1_ID = DB::table("order_delivery_points")->where("order_id",$o1)->where("sequence",1)->value("id");
DB::table("order_delivery_points")->insert(["order_id"=>$o2,"sequence"=>1,"status"=>"pending","created_at"=>$now,"updated_at"=>$now]);
$DP2_ID = DB::table("order_delivery_points")->where("order_id",$o2)->where("sequence",1)->value("id");

echo "DA_ID=$da DB_ID=$db V_ID=$v O1_ID=$o1 O2_ID=$o2 DP1_ID=$DP1_ID DP2_ID=$DP2_ID";
SEEDPHP

eval "$(php artisan tinker < "$TMPFILE" 2>/dev/null | grep '^DA_ID=')"
rm -f "$TMPFILE"

if [ -z "${DA_ID:-}" ]; then fail "Seed thất bại"; fi
info "Driver A ID: $DA_ID, Driver B ID: $DB_ID, Vehicle ID: $V_ID"
info "Order 1: $O1_ID, Order 2: $O2_ID, DP1: $DP1_ID, DP2: $DP2_ID"

# Dọn dẹp dữ liệu ca cũ
echo ""
echo ">>> Đang dọn dẹp dữ liệu ca cũ..."
php artisan tinker --execute '
$da='$DA_ID';$db='$DB_ID';$v='$V_ID';$o1='$O1_ID';$o2='$O2_ID';
DB::table("shift_vehicles")->whereIn("shift_id",fn($q)=>$q->select("id")->from("driver_shifts")->whereIn("driver_id",[$da,$db]))->delete();
DB::table("trip_checkpoints")->whereIn("driver_id",[$da,$db])->delete();
DB::table("driver_swaps")->whereIn("from_driver_id",[$da,$db])->orWhereIn("to_driver_id",[$da,$db])->delete();
DB::table("driver_shifts")->whereIn("driver_id",[$da,$db])->delete();
DB::table("orders")->whereIn("order_code",["ORD-SWAP-001","ORD-SWAP-002"])->update(["status"=>"sent","driver_id"=>$da,"shift_id"=>null]);
DB::table("order_delivery_points")->whereIn("order_id",[$o1,$o2])->update(["status"=>"pending","arrived_at"=>null,"delivered_at"=>null]);
DB::table("vehicles")->where("id",$v)->update(["current_mileage"=>10000,"current_driver_id"=>null]);
echo "OK";
' 2>/dev/null
info "Đã dọn dẹp & reset dữ liệu"

TOTAL=14
DT_FMT=$(date -u +%Y-%m-%dT%H:%M:%SZ)

# =====================================================================
# PHASE 1: Driver A hoàn tất Order 1
# =====================================================================

# ── 1. Đăng nhập Driver A ──────────────────────────────────────────
step 1 "🔐 Đăng nhập Driver A"

LOGIN_A=$(curl -s -f "$BASE/api/driver/login" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"email":"driver.a@example.com","password":"password"}') || fail "Login A thất bại"
TOKEN_A=$(echo "$LOGIN_A" | php -r 'echo json_decode(file_get_contents("php://stdin"))->token ?? "FAIL";')
[ "$TOKEN_A" = "FAIL" ] && fail "Token A rỗng"
ok "Token: ${TOKEN_A:0:20}..."
AUTH_A="Authorization: Bearer $TOKEN_A"

# ── 2. Vào ca A ────────────────────────────────────────────────────
step 2 "🟢 Vào ca (Driver A)"

SFT_A_R=$(curl -s -X POST "$BASE/api/driver/shifts/start" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"shift_type":"full","start_time":"'$DT_FMT'"}')
SFT_A_ID=$(echo "$SFT_A_R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->shift->id ?? "FAIL";')
[ "$SFT_A_ID" = "FAIL" ] && fail "Vào ca A thất bại: $(echo "$SFT_A_R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->message ?? "unknown";')"
ok "Shift ID: $SFT_A_ID"

php artisan tinker --execute 'DB::table("vehicles")->where("id",'$V_ID')->update(["current_mileage"=>10000]);' 2>/dev/null
info "Đặt vehicle.current_mileage = 10000"

# ── 3-6. Điều phối đơn 1 ──────────────────────────────────────────
step 3 "🚀 Order 1: started"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O1_ID',"shift_id":'$SFT_A_ID',"checkpoint_type":"started","occurred_at":"'$DT_FMT'"}' | php -r '$r=json_decode(file_get_contents("php://stdin"));echo $r->checkpoint->id??$r->message??"FAIL";echo "\n";'
ok "Checkpoint started"

step 4 "📍 Order 1: arrived_pickup (km=10010)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O1_ID',"shift_id":'$SFT_A_ID',"delivery_point_id":'$DP1_ID',"checkpoint_type":"arrived_pickup","km_reading":10010,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "ArrivedPickup"

step 5 "🚚 Order 1: left_pickup (km=10015)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O1_ID',"shift_id":'$SFT_A_ID',"checkpoint_type":"left_pickup","km_reading":10015,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "LeftPickup → Delivering"

step 6 "🏁 Order 1: arrived_delivery (km=10025)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O1_ID',"shift_id":'$SFT_A_ID',"delivery_point_id":'$DP1_ID',"checkpoint_type":"arrived_delivery","km_reading":10025,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "ArrivedDelivery"

# ── 7. Order 1: completed ─────────────────────────────────────────
step 7 "✅ Order 1: completed (km=10030)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O1_ID',"shift_id":'$SFT_A_ID',"delivery_point_id":'$DP1_ID',"checkpoint_type":"completed","km_reading":10030,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "Completed"

# =====================================================================
# PHASE 2: Driver A bắt đầu Order 2 → hết ca
# =====================================================================

# ── 8-10. Điều phối đơn 2 (A) ─────────────────────────────────────
step 8 "🚀 Order 2: started (A)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_A_ID',"checkpoint_type":"started","occurred_at":"'$DT_FMT'"}' > /dev/null
ok "Started"

step 9 "📍 Order 2: arrived_pickup (km=10040)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_A_ID',"delivery_point_id":'$DP2_ID',"checkpoint_type":"arrived_pickup","km_reading":10040,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "ArrivedPickup"

step 10 "🚚 Order 2: left_pickup (km=10045)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_A_ID',"checkpoint_type":"left_pickup","km_reading":10045,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "LeftPickup → Delivering"

# ── 11. Kết thúc ca A → auto DriverSwap ───────────────────────────
step 11 "⏹️  Kết thúc ca A (end_km=10060) → auto DriverSwap"
END_A_R=$(curl -s -X POST "$BASE/api/driver/shifts/end" -H "$AUTH_A" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"end_km":10060,"end_time":"'$DT_FMT'"}')
END_A_MSG=$(echo "$END_A_R" | php -r '$r=json_decode(file_get_contents("php://stdin"));echo $r->shift->total_km??$r->message??"FAIL";')
ok "Kết thúc ca A, total_km=$END_A_MSG"

php artisan tinker --execute '
$s=DB::table("driver_shifts")->find('$SFT_A_ID');
echo "  📊 Shift A: total_km=$s->total_km loaded=$s->total_km_loaded empty=$s->total_km_empty (kỳ vọng: total=60, loaded=40, empty=20)\n";
$o2s=DB::table("orders")->find('$O2_ID');
echo "  📄 Trạng thái đơn 2: $o2s->status (kỳ vọng: driver_swap)\n";
' 2>/dev/null

# =====================================================================
# PHASE 3: Điều hành swap → Driver B hoàn tất Order 2
# =====================================================================

# ── 12. Tạo DriverSwap record ─────────────────────────────────────
step 12 "🔄 Điều hành swap: Driver A → Driver B"
php artisan tinker --execute '
DB::table("driver_swaps")->insert([
  "order_id"=>'$O2_ID',"from_driver_id"=>'$DA_ID',"to_driver_id"=>'$DB_ID',
  "from_shift_id"=>'$SFT_A_ID',"to_shift_id"=>null,"handover_km"=>10060,
  "reason"=>"shift_handover","note"=>"Hết ca, bàn giao cho tài xế B","created_by"=>'$DA_ID',"created_at"=>now()
]);
DB::table("orders")->where("id",'$O2_ID')->update(["driver_id"=>'$DB_ID',"status"=>"driver_swap","updated_at"=>now()]);
echo "OK";
' 2>/dev/null
DS_ID=$(php artisan tinker --execute 'echo DB::table("driver_swaps")->where("order_id",'$O2_ID')->value("id");' 2>/dev/null)
ok "DriverSwap record #$DS_ID — A($DA_ID) → B($DB_ID)"

# ── 13. Đăng nhập Driver B ────────────────────────────────────────
step 13 "🔐 Đăng nhập Driver B"
LOGIN_B=$(curl -s -f "$BASE/api/driver/login" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"email":"driver.b@example.com","password":"password"}') || fail "Login B thất bại"
TOKEN_B=$(echo "$LOGIN_B" | php -r 'echo json_decode(file_get_contents("php://stdin"))->token ?? "FAIL";')
[ "$TOKEN_B" = "FAIL" ] && fail "Token B rỗng"
ok "Token: ${TOKEN_B:0:20}..."
AUTH_B="Authorization: Bearer $TOKEN_B"

# ── 14. Vào ca B ──────────────────────────────────────────────────
step 14 "🟢 Vào ca (Driver B)"
SFT_B_R=$(curl -s -X POST "$BASE/api/driver/shifts/start" -H "$AUTH_B" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"shift_type":"full","start_time":"'$DT_FMT'"}')
SFT_B_ID=$(echo "$SFT_B_R" | php -r 'echo json_decode(file_get_contents("php://stdin"))->shift->id ?? "FAIL";')
[ "$SFT_B_ID" = "FAIL" ] && fail "Vào ca B thất bại"
ok "Shift ID: $SFT_B_ID"

# Gán shift B cho đơn 2
php artisan tinker --execute 'DB::table("orders")->where("id",'$O2_ID')->update(["shift_id"=>'$SFT_B_ID',"updated_at"=>now()]);' 2>/dev/null
info "Đã gán shift B cho đơn 2"

# ── 15-17. Driver B hoàn tất Order 2 ──────────────────────────────
step 15 "🚀 Order 2: started (B)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_B" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_B_ID',"checkpoint_type":"started","occurred_at":"'$DT_FMT'"}' > /dev/null
ok "Started"

step 16 "🏁 Order 2: arrived_delivery (km=10080)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_B" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_B_ID',"delivery_point_id":'$DP2_ID',"checkpoint_type":"arrived_delivery","km_reading":10080,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "ArrivedDelivery"

step 17 "✅ Order 2: completed (km=10090)"
curl -s -X POST "$BASE/api/driver/checkpoints" -H "$AUTH_B" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"order_id":'$O2_ID',"shift_id":'$SFT_B_ID',"delivery_point_id":'$DP2_ID',"checkpoint_type":"completed","km_reading":10090,"occurred_at":"'$DT_FMT'"}' > /dev/null
ok "Completed"

# ── 18. Kết thúc ca B ─────────────────────────────────────────────
step 18 "⏹️  Kết thúc ca B (end_km=10100)"
END_B_R=$(curl -s -X POST "$BASE/api/driver/shifts/end" -H "$AUTH_B" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"end_km":10100,"end_time":"'$DT_FMT'"}')
END_B_MSG=$(echo "$END_B_R" | php -r '$r=json_decode(file_get_contents("php://stdin"));echo $r->shift->total_km??$r->message??"FAIL";')
ok "Kết thúc ca B, total_km=$END_B_MSG"

# =====================================================================
# VERIFICATION
# =====================================================================
echo ""
echo "════════════════════════════════════════════════"
echo "  ✅ Hoàn tất!"
echo ""

php artisan tinker --execute '
$v=DB::table("vehicles")->find('$V_ID');
$sa=DB::table("driver_shifts")->find('$SFT_A_ID');
$sb=DB::table("driver_shifts")->find('$SFT_B_ID');
$o1=DB::table("orders")->find('$O1_ID');
$o2=DB::table("orders")->find('$O2_ID');
$ds=DB::table("driver_swaps")->where("order_id",'$O2_ID')->first();
echo "  📊 Kết quả KM:\n";
echo "    Driver A: total_km=$sa->total_km loaded=$sa->total_km_loaded empty=$sa->total_km_empty\n";
echo "    Driver B: total_km=$sb->total_km loaded=$sb->total_km_loaded empty=$sb->total_km_empty\n";
echo "\n  📄 Trạng thái:\n";
echo "    Order 1 ($o1->order_code): $o1->status\n";
echo "    Order 2 ($o2->order_code): $o2->status\n";
echo "    Swap: $ds->from_driver_id → $ds->to_driver_id ($ds->reason)\n";
echo "\n  🚛 Xe: current_mileage=$v->current_mileage\n";
' 2>/dev/null

echo "🔍 Kiểm tra kết quả:"
php artisan tinker --execute '
$sa=DB::table("driver_shifts")->find('$SFT_A_ID');
$sb=DB::table("driver_shifts")->find('$SFT_B_ID');
$v=DB::table("vehicles")->find('$V_ID');
echo "  Driver A total_km:       $sa->total_km (kỳ vọng: 60) " . ($sa->total_km==60 ? "✅" : "❌") . "\n";
echo "  Driver A loaded: $sa->total_km_loaded (kỳ vọng: 40) " . ($sa->total_km_loaded==40 ? "✅" : "❌") . "\n";
echo "  Driver A empty:  $sa->total_km_empty (kỳ vọng: 20) " . ($sa->total_km_empty==20 ? "✅" : "❌") . "\n";
echo "  Driver B total_km:       $sb->total_km (kỳ vọng: 40) " . ($sb->total_km==40 ? "✅" : "❌") . "\n";
echo "  Driver B loaded: $sb->total_km_loaded (kỳ vọng: 30) " . ($sb->total_km_loaded==30 ? "✅" : "❌") . "\n";
echo "  Driver B empty:  $sb->total_km_empty (kỳ vọng: 10) " . ($sb->total_km_empty==10 ? "✅" : "❌") . "\n";
echo "  Vehicle mileage:         $v->current_mileage (kỳ vọng: 10100) " . ($v->current_mileage==10100 ? "✅" : "❌") . "\n";
' 2>/dev/null
echo "════════════════════════════════════════════════"
