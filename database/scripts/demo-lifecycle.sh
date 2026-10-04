#!/bin/bash
set -eo pipefail

# ─── Full Order Lifecycle Demo ─────────────────────────────────────────
# Simulates the complete mobile driver flow:
#   Seed → Login → Start Shift → Checkpoints → End Shift
#
# Usage:
#   1. Start Laravel: php artisan serve
#   2. Run: bash database/scripts/demo-lifecycle.sh
#
# Requires: curl, jq (optional)
BASE="${APP_URL:-http://localhost:8000}"
EMAIL="driver.demo@example.com"
PASS="password"

step()  { local n=$1 msg=$2; echo; echo "─── [$n/$TOTAL] $msg ───"; }
json() { if command -v jq &>/dev/null; then jq; else cat; fi; }

# ── 0. Seed + extract IDs via a single tinker call ─────────────────────
echo ">>> Seeding demo data..."
php artisan db:seed --class=FullOrderLifecycleSeeder --no-interaction 2>/dev/null

echo ">>> Resolving entity IDs..."
eval "$(php artisan tinker --execute '
$d = DB::table("users")->where("email","driver.demo@example.com")->value("id");
$v = DB::table("vehicles")->where("plate_number","99X-99999")->value("id");
$o = DB::table("orders")->where("driver_id",$d)->orderByDesc("id")->value("id");
$p = DB::table("order_delivery_points")->where("order_id",$o)->orderBy("sequence")->value("id");
echo "DRIVER_ID=$d VEHICLE_ID=$v ORDER_ID=$o DP_ID=$p";
' 2>/dev/null)"

if [ -z "$DRIVER_ID" ]; then echo ">>> ID LOOKUP FAILED"; exit 1; fi
echo "  DRIVER_ID=$DRIVER_ID VEHICLE_ID=$VEHICLE_ID ORDER_ID=$ORDER_ID DP_ID=$DP_ID"

TOTAL=8

# ── 1. Login ───────────────────────────────────────────────────────────
step 1 "🔐 Login"
LOGIN=$(curl -s -f "$BASE/api/driver/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
echo "$LOGIN" | json
TOKEN=$(echo "$LOGIN" | php -r 'echo json_decode(file_get_contents("php://stdin"))->token ?? "FAIL";')
if [ "$TOKEN" = "FAIL" ] || [ -z "$TOKEN" ]; then echo ">>> LOGIN FAILED"; exit 1; fi
AUTH="Authorization: Bearer $TOKEN"

# ── 2. Check available vehicles ───────────────────────────────────────
step 2 "🚛 Available vehicles"
curl -s "$BASE/api/driver/vehicles/available" -H "$AUTH" -H "Accept: application/json" | json

# ── 3. Start shift ────────────────────────────────────────────────────
step 3 "🟢 Start shift"
SHIFT=$(curl -s -X POST "$BASE/api/driver/shifts/start" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"vehicle_id\":$VEHICLE_ID,\"shift_type\":\"full\",\"start_gps_lat\":10.8554,\"start_gps_lng\":106.7913}")
echo "$SHIFT" | json
SHIFT_ID=$(echo "$SHIFT" | php -r 'echo json_decode(file_get_contents("php://stdin"))->shift->id ?? "FAIL";')
echo "  SHIFT_ID=$SHIFT_ID"

# ── 4. List orders ────────────────────────────────────────────────────
step 4 "📋 List my orders"
curl -s "$BASE/api/driver/orders" -H "$AUTH" -H "Accept: application/json" | json

# ── 5. Checkpoint: started ────────────────────────────────────────────
step 5 "🚀 Checkpoint: started"
curl -s -X POST "$BASE/api/driver/checkpoints" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"order_id\":$ORDER_ID,
    \"shift_id\":$SHIFT_ID,
    \"checkpoint_type\":\"started\",
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"gps_lat\":10.8554,
    \"gps_lng\":106.7913
  }" | json

# ── 6. arrived_pickup ─────────────────────────────────────────────────
step 6 "📍 arrived_pickup"
curl -s -X POST "$BASE/api/driver/checkpoints" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"order_id\":$ORDER_ID,
    \"shift_id\":$SHIFT_ID,
    \"checkpoint_type\":\"arrived_pickup\",
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"gps_lat\":10.8554,
    \"gps_lng\":106.7913
  }" | json

# ── 6b. left_pickup ──────────────────────────────────────────────────
step 6b "🚚 left_pickup"
curl -s -X POST "$BASE/api/driver/checkpoints" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"order_id\":$ORDER_ID,
    \"shift_id\":$SHIFT_ID,
    \"delivery_point_id\":$DP_ID,
    \"checkpoint_type\":\"left_pickup\",
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"gps_lat\":10.8188,
    \"gps_lng\":106.6580
  }" | json

# ── 6c. arrived_delivery ─────────────────────────────────────────────
step 6c "🏁 arrived_delivery"
curl -s -X POST "$BASE/api/driver/checkpoints" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"order_id\":$ORDER_ID,
    \"shift_id\":$SHIFT_ID,
    \"delivery_point_id\":$DP_ID,
    \"checkpoint_type\":\"arrived_delivery\",
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"gps_lat\":10.8188,
    \"gps_lng\":106.6580
  }" | json

# ── 7. completed ──────────────────────────────────────────────────────
step 7 "✅ completed"
curl -s -X POST "$BASE/api/driver/checkpoints" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"order_id\":$ORDER_ID,
    \"shift_id\":$SHIFT_ID,
    \"delivery_point_id\":$DP_ID,
    \"checkpoint_type\":\"completed\",
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"gps_lat\":10.8188,
    \"gps_lng\":106.6580
  }" | json

# ── 7b. End vehicle ──────────────────────────────────────────────────
step 7b "🚗 End vehicle"
curl -s -X POST "$BASE/api/driver/shifts/$SHIFT_ID/end-vehicle" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" | json

# ── 8. End shift ─────────────────────────────────────────────────────
step 8 "⏹️  End shift"
curl -s -X POST "$BASE/api/driver/shifts/end" \
  -H "$AUTH" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
    \"occurred_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
    \"end_gps_lat\":10.8188,
    \"end_gps_lng\":106.6580
  }" | json

echo
echo "═══════════════════════════════════════════════════════"
echo "  ✅ Full lifecycle complete!"
echo "  Check shift totals: total_km, total_km_loaded, total_km_empty"
echo "  Order status should now be: completed"
echo "═══════════════════════════════════════════════════════"
