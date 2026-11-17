<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Vị trí xe {{ $vehicle->plate_number }} — ASG Vận Tải</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --primary: #4F46E5;
            --text: #111827;
            --muted: #6B7280;
            --border: #E5E7EB;
            --bg: #F3F4F6;
            --card: #FFFFFF;
            --green: #10B981;
            --amber: #F59E0B;
            --red: #EF4444;
            --radius: 16px;
            --shadow: 0 4px 20px rgba(15, 23, 42, .06);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            padding-bottom: 32px;
            -webkit-font-smoothing: antialiased;
        }

        .shell { width: 100%; max-width: 1100px; margin: 0 auto; padding: 0 16px; }

        .topbar {
            background: #FFFFFF;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 500;
        }

        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 64px;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brand img { height: 38px; width: auto; display: block; }
        .brand-name { font-size: 16px; font-weight: 800; letter-spacing: -.01em; }
        .brand-sub { font-size: 11px; color: var(--muted); font-weight: 500; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .badge-gray { background: #F3F4F6; color: #374151; border-color: #D1D5DB; }
        .badge-purple { background: #F5F3FF; color: #6D28D9; border-color: #DDD6FE; }
        .badge-info { background: #EFF6FF; color: #1D4ED8; border-color: #BFDBFE; }
        .badge-warning { background: #FFFBEB; color: #B45309; border-color: #FDE68A; }
        .badge-success { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
        .badge-danger { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }

        main { padding-top: 20px; display: flex; flex-direction: column; gap: 16px; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .vehicle-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            flex-wrap: wrap;
        }

        .eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .vehicle-head h1 {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 2px 0 8px;
        }

        .meta { display: flex; flex-wrap: wrap; gap: 8px; }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #F9FAFB;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
        }

        .head-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            text-align: right;
            font-size: 12px;
            color: var(--muted);
        }

        .map-card { position: relative; overflow: hidden; }
        #vehicle-map { height: 62vh; min-height: 420px; width: 100%; background: #E5E7EB; }

        .map-toolbar {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 450;
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .12);
            transition: background .15s ease;
        }
        .btn:hover { background: #F9FAFB; }
        .btn:disabled { opacity: .6; cursor: default; }
        .btn .spin { display: inline-block; }
        .btn.loading .spin { animation: spin .9s linear infinite; }

        @keyframes spin { to { transform: rotate(360deg); } }

        .empty {
            padding: 56px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
        }

        .live {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            flex-wrap: wrap;
        }

        .live-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .live-title { font-size: 13px; font-weight: 700; }
        .muted { color: var(--muted); font-size: 12px; }
        .small { font-size: 11px; }

        .pulse {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, .6);
            animation: pulse 2s infinite;
            flex: none;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, .55); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .warn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            color: #B45309;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .notice-error { color: #B91C1C; font-weight: 600; }
        .notice-ok { color: #047857; font-weight: 600; }
        .hidden { display: none !important; }

        .popup-title { font-size: 13px; font-weight: 700; color: #111827; }
        .popup-row { font-size: 12px; color: #4B5563; margin-top: 2px; }

        footer {
            margin-top: 18px;
            color: var(--muted);
            font-size: 11px;
            text-align: center;
            line-height: 1.7;
        }

        @media (max-width: 720px) {
            .vehicle-head h1 { font-size: 19px; }
            #vehicle-map { height: 55vh; min-height: 360px; }
            .head-right { align-items: flex-start; text-align: left; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell topbar-inner">
        <div class="brand">
            <img src="{{ asset('images/ASG.png') }}" alt="ASG Vận Tải">
            <div>
                <div class="brand-name">ASG Vận Tải</div>
                <div class="brand-sub">Vị trí xe trực tuyến</div>
            </div>
        </div>
        @php
            $badgeClass = match ($vehicle->getStatusColor()) {
                'success' => 'badge-success',
                'warning' => 'badge-warning',
                'danger' => 'badge-danger',
                'info' => 'badge-info',
                'purple' => 'badge-purple',
                default => 'badge-gray',
            };
        @endphp
        <span id="status-badge" class="badge {{ $badgeClass }}">{{ $vehicle->getStatusLabel() }}</span>
    </div>
</header>

<main class="shell">
    <section class="card vehicle-head">
        <div>
            <div class="eyebrow">Biển số xe</div>
            <h1>{{ $vehicle->plate_number }}</h1>
            <div class="meta">
                <span class="chip">Loại xe: {{ $vehicle->getVehicleTypeLabel() }}</span>
                @if ($vehicle->load_capacity !== null)
                    <span class="chip">Trọng tải: {{ number_format((float) $vehicle->load_capacity, 1, ',', '.') }} tấn ({{ (int) round((float) $vehicle->load_capacity * 1000) }} kg)</span>
                @endif
                @if ($vehicle->door_count !== null)
                    <span class="chip">Số cửa: {{ $vehicle->door_count }}</span>
                @endif
                <span id="gps-speed-chip" class="chip">
                    Tốc độ: {{ isset($initialPosition['position']['speed_kmh']) ? number_format((float) $initialPosition['position']['speed_kmh'], 0).' km/h' : '—' }}
                </span>
            </div>
            <div id="gps-address-text" class="muted" style="margin-top: 8px;">
                {{ $initialPosition['position']['address'] ?? 'Chưa có địa chỉ GPS từ thiết bị.' }}
            </div>
        </div>

        <div class="head-right">
            @if ($initialPosition['has_position'])
                <a
                    id="gmaps-link"
                    class="btn"
                    target="_blank"
                    rel="noopener noreferrer"
                    href="https://www.google.com/maps?q={{ $initialPosition['position']['latitude'] }},{{ $initialPosition['position']['longitude'] }}"
                >
                    Mở Google Maps ↗
                </a>
            @else
                <a id="gmaps-link" class="btn hidden" target="_blank" rel="noopener noreferrer" href="#">
                    Mở Google Maps ↗
                </a>
            @endif
            <span>Link có hiệu lực đến {{ $linkExpiresAt->format('H:i d/m/Y') }}</span>
        </div>
    </section>

    <section class="card map-card">
        <div id="vehicle-map" class="{{ $initialPosition['has_position'] ? '' : 'hidden' }}"></div>
        <div id="map-empty" class="empty {{ $initialPosition['has_position'] ? 'hidden' : '' }}">
            Xe chưa có tín hiệu GPS. Hệ thống sẽ tự động cập nhật ngay khi nhận dữ liệu mới.
        </div>
        <div class="map-toolbar">
            <button id="refresh-btn" type="button" class="btn" aria-label="Cập nhật vị trí xe">
                <span class="spin">↻</span> Cập nhật
            </button>
        </div>
    </section>

    <section class="card live">
        <div class="live-left">
            <span class="pulse"></span>
            <div>
                <div class="live-title">Tín hiệu GPS</div>
                <div id="gps-updated" class="muted">
                    {{ $initialPosition['position']['updated_at_human'] ?? 'Chưa có tín hiệu GPS' }}
                </div>
            </div>
        </div>
        <div id="gps-stale" class="warn {{ ($initialPosition['position']['is_stale'] ?? false) ? '' : 'hidden' }}">
            ⚠ Tín hiệu GPS đã cũ hơn 15 phút
        </div>
        <div id="poll-note" class="muted small">Tự động cập nhật mỗi 30 giây</div>
    </section>
</main>

<footer class="shell">
    Vị trí xe được đồng bộ tự động từ thiết bị GPS của phương tiện.<br>
    Mọi thắc mắc vui lòng liên hệ bộ phận điều vận ASG.
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const initialPosition = @json($initialPosition);
    const positionUrl = @json($positionUrl);
    const truckIconUrl = @json(asset('images/truck.png'));
    const plateNumber = @json($vehicle->plate_number ?? '');

    const STATUS_CLASSES = {
        purple: 'badge-purple',
        info: 'badge-info',
        warning: 'badge-warning',
        success: 'badge-success',
        danger: 'badge-danger',
        gray: 'badge-gray',
    };

    let map = null;
    let vehicleMarker = null;
    let vehicleHalo = null;
    let pollTimer = null;
    let expired = false;

    const mapEl = document.getElementById('vehicle-map');
    const emptyEl = document.getElementById('map-empty');
    const refreshBtn = document.getElementById('refresh-btn');
    const pollNote = document.getElementById('poll-note');
    const gpsUpdated = document.getElementById('gps-updated');
    const gpsStale = document.getElementById('gps-stale');
    const gpsAddress = document.getElementById('gps-address-text');
    const gpsSpeedChip = document.getElementById('gps-speed-chip');
    const gmapsLink = document.getElementById('gmaps-link');

    function textNode(tag, className, text) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        el.textContent = text;
        return el;
    }

    function vehiclePopup(position) {
        const wrap = document.createElement('div');
        wrap.appendChild(textNode('div', 'popup-title', '🚛 ' + (plateNumber || 'Xe vận chuyển')));

        const speed = position.speed_kmh !== null && position.speed_kmh !== undefined
            ? position.speed_kmh.toFixed(0) + ' km/h'
            : '—';
        wrap.appendChild(textNode('div', 'popup-row', 'Tốc độ: ' + speed));

        if (position.address) {
            wrap.appendChild(textNode('div', 'popup-row', 'Vị trí: ' + position.address));
        }
        if (position.updated_at_human) {
            wrap.appendChild(textNode('div', 'popup-row', 'Cập nhật: ' + position.updated_at_human));
        }

        return wrap;
    }

    function setNotice(message, type) {
        if (!pollNote) return;
        pollNote.textContent = message || 'Tự động cập nhật mỗi 30 giây';
        pollNote.className = 'small ' + (type === 'error' ? 'notice-error' : (type === 'ok' ? 'notice-ok' : 'muted'));
    }

    function ensureMap(latlng) {
        if (map || !mapEl) return;
        mapEl.classList.remove('hidden');
        if (emptyEl) emptyEl.classList.add('hidden');

        map = L.map('vehicle-map', {
            zoomControl: true,
            scrollWheelZoom: true,
        }).setView(latlng, 15);

        L.tileLayer(@js(\App\Enums\MapTile::Streets->value), {
            maxZoom: 20,
            attribution: @js(\App\Enums\MapTile::Streets->attribution()),
        }).addTo(map);

        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    function updateVehicle(position) {
        if (!position) {
            if (gpsUpdated) gpsUpdated.textContent = 'Chưa có tín hiệu GPS';
            if (gpsStale) gpsStale.classList.add('hidden');
            return;
        }

        const latlng = [position.latitude, position.longitude];
        ensureMap(latlng);

        if (!vehicleMarker) {
            vehicleHalo = L.circleMarker(latlng, {
                radius: 24,
                color: '#2563EB',
                fillColor: '#60A5FA',
                fillOpacity: 0.28,
                weight: 2,
            }).addTo(map);

            vehicleMarker = L.marker(latlng, {
                icon: L.icon({
                    iconUrl: truckIconUrl,
                    iconSize: [42, 42],
                    iconAnchor: [21, 21],
                    popupAnchor: [0, -18],
                }),
                zIndexOffset: 1000,
            }).addTo(map);
        } else {
            vehicleMarker.setLatLng(latlng);
            vehicleHalo.setLatLng(latlng);
            map.panTo(latlng, { animate: true });
        }

        vehicleMarker.bindPopup(vehiclePopup(position)).openPopup();

        if (gpsUpdated) {
            gpsUpdated.textContent = position.updated_at_human
                ? 'Cập nhật ' + position.updated_at_human
                : 'Đã có vị trí xe';
        }
        if (gpsStale) {
            gpsStale.classList.toggle('hidden', !position.is_stale);
        }
        if (gpsAddress) {
            gpsAddress.textContent = position.address || (position.latitude + ', ' + position.longitude);
        }
        if (gpsSpeedChip) {
            gpsSpeedChip.textContent = 'Tốc độ: ' + (position.speed_kmh !== null && position.speed_kmh !== undefined ? position.speed_kmh.toFixed(0) + ' km/h' : '—');
        }
        if (gmapsLink) {
            gmapsLink.href = 'https://www.google.com/maps?q=' + position.latitude + ',' + position.longitude;
            gmapsLink.classList.remove('hidden');
        }
    }

    function applyStatus(data) {
        const badge = document.getElementById('status-badge');
        if (!badge || !data.vehicle_status_label) return;
        badge.textContent = data.vehicle_status_label;
        badge.className = 'badge ' + (STATUS_CLASSES[data.vehicle_status_color] || 'badge-gray');
    }

    async function refreshPosition(manual) {
        if (expired) return;

        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.classList.add('loading');
        }

        try {
            const response = await fetch(positionUrl, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });

            if (response.status === 403) {
                expired = true;
                if (pollTimer !== null) clearInterval(pollTimer);
                setNotice('Link theo dõi đã hết hạn. Vui lòng lấy lại link mới.', 'error');
                return;
            }

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();
            applyStatus(data);
            updateVehicle(data.position);

            if (manual) {
                setNotice('Đã cập nhật vị trí mới nhất.', 'ok');
            }
        } catch (error) {
            if (manual) {
                setNotice('Không thể cập nhật vị trí. Vui lòng kiểm tra kết nối mạng.', 'error');
            }
        } finally {
            if (refreshBtn && !expired) {
                refreshBtn.disabled = false;
                refreshBtn.classList.remove('loading');
            }
        }
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { refreshPosition(true); });
    }

    if (initialPosition.has_position && initialPosition.position) {
        updateVehicle(initialPosition.position);
    }

    pollTimer = setInterval(function () { refreshPosition(false); }, 30000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !expired) {
            refreshPosition(false);
        }
    });
</script>
</body>
</html>
