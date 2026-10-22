<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Theo dõi xe {{ $trip->vehicle?->plate_number ?? $trip->trip_code }} — ASG Vận Tải</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --primary: #4F46E5;
            --primary-dark: #4338CA;
            --text: #111827;
            --muted: #6B7280;
            --border: #E5E7EB;
            --bg: #F3F4F6;
            --card: #FFFFFF;
            --green: #10B981;
            --amber: #F59E0B;
            --red: #EF4444;
            --blue: #3B82F6;
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

        /* Thanh thương hiệu */
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

        /* Badge trạng thái */
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

        /* Khối đầu trang */
        .trip-head {
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

        .trip-head h1 {
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

        .head-right { text-align: right; font-size: 13px; font-weight: 600; color: #374151; }
        .head-right .eyebrow { margin-bottom: 2px; }

        /* Danh sách điểm lấy / giao */
        .stops-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .stops { padding: 16px 18px; }

        .stops h2 {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #374151;
            margin-bottom: 12px;
        }

        .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
        .dot.amber { background: var(--amber); }
        .dot.red { background: var(--red); }

        .stop { display: flex; gap: 10px; padding: 8px 0; border-top: 1px dashed var(--border); }
        .stop:first-of-type { border-top: none; padding-top: 0; }
        .stop:last-child { padding-bottom: 0; }

        .stop-index {
            flex: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
        }
        .stop-index.amber { background: var(--amber); }
        .stop-index.red { background: var(--red); }

        .stop-name { font-size: 13px; font-weight: 600; }
        .stop-sub { font-size: 12px; color: var(--muted); margin-top: 1px; }

        .muted { color: var(--muted); font-size: 12px; }
        .small { font-size: 11px; }

        /* Bản đồ */
        .map-card { position: relative; overflow: hidden; }

        #tracking-map { height: 60vh; min-height: 420px; width: 100%; background: #E5E7EB; }

        .map-toolbar {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 450;
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
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .12);
            transition: background .15s ease;
        }
        .btn:hover { background: #F9FAFB; }
        .btn:disabled { opacity: .6; cursor: default; }
        .btn .spin { display: inline-block; }
        .btn.loading .spin { animation: spin .9s linear infinite; }

        @keyframes spin { to { transform: rotate(360deg); } }

        .legend {
            position: absolute;
            left: 12px;
            bottom: 12px;
            z-index: 450;
            background: rgba(255, 255, 255, .95);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 12px;
            max-width: calc(100% - 24px);
            box-shadow: 0 2px 8px rgba(15, 23, 42, .1);
        }

        .legend span { display: inline-flex; align-items: center; gap: 5px; }
        .legend i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }

        .empty {
            padding: 48px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
        }

        /* Thẻ trạng thái GPS */
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

        footer {
            margin-top: 18px;
            color: var(--muted);
            font-size: 11px;
            text-align: center;
            line-height: 1.7;
        }

        /* Marker trên bản đồ */
        .map-pin {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid #FFFFFF;
            color: #FFFFFF;
            font-size: 12px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .35);
        }

        .popup-title { font-size: 13px; font-weight: 700; color: #111827; }
        .popup-desc { font-size: 12px; color: #4B5563; margin-top: 2px; max-width: 220px; }
        .popup-row { font-size: 12px; color: #4B5563; margin-top: 2px; }

        @media (max-width: 720px) {
            .stops-grid { grid-template-columns: 1fr; }
            .trip-head h1 { font-size: 19px; }
            #tracking-map { height: 55vh; min-height: 360px; }
            .head-right { text-align: left; }
            .legend { font-size: 10px; gap: 3px 10px; padding: 7px 10px; }
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
                <div class="brand-sub">Theo dõi hành trình</div>
            </div>
        </div>
        @php
            $badgeClass = match ($trip->getStatusColor()) {
                'success' => 'badge-success',
                'warning' => 'badge-warning',
                'danger' => 'badge-danger',
                'info' => 'badge-info',
                'purple' => 'badge-purple',
                default => 'badge-gray',
            };
        @endphp
        <span id="status-badge" class="badge {{ $badgeClass }}">{{ $trip->getStatusLabel() }}</span>
    </div>
</header>

<main class="shell">
    <section class="card trip-head">
        <div>
            {{-- Khách theo dõi theo biển số xe; chỉ hiện mã chuyến khi chuyến chưa gán xe --}}
            <div class="eyebrow">{{ $trip->vehicle?->plate_number ? 'Biển số xe' : 'Mã chuyến' }}</div>
            <h1>{{ $trip->vehicle?->plate_number ?? $trip->trip_code }}</h1>
            <div class="meta">
                @if ($trip->driver?->name)
                    <span class="chip">👤 {{ $trip->driver->name }}</span>
                @endif
                @if ($trip->started_at)
                    <span class="chip">Bắt đầu {{ $trip->started_at->format('H:i d/m/Y') }}</span>
                @endif
            </div>
        </div>
        <div class="head-right">
            <div class="eyebrow">Link có hiệu lực đến</div>
            <div>{{ $linkExpiresAt->format('H:i d/m/Y') }}</div>
        </div>
    </section>

    <section class="stops-grid">
        <div class="card stops">
            <h2><span class="dot amber"></span> Điểm lấy hàng</h2>
            @forelse ($map['pickups'] as $index => $pickup)
                <div class="stop">
                    <span class="stop-index amber">{{ $index + 1 }}</span>
                    <div>
                        <div class="stop-name">{{ $pickup['name'] }}</div>
                        @if ($pickup['address'] && $pickup['address'] !== $pickup['name'])
                            <div class="stop-sub">{{ $pickup['address'] }}</div>
                        @endif
                        <div class="stop-sub">
                            @if ($pickup['order_code']) Đơn {{ $pickup['order_code'] }} @endif
                            @if ($pickup['planned_at'])
                                · Dự kiến {{ \Carbon\Carbon::parse($pickup['planned_at'])->format('H:i d/m') }}
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="muted">Chưa có điểm lấy hàng.</p>
            @endforelse
        </div>

        <div class="card stops">
            <h2><span class="dot red"></span> Điểm giao hàng</h2>
            @forelse ($map['deliveries'] as $index => $delivery)
                <div class="stop">
                    <span class="stop-index red">{{ $index + 1 }}</span>
                    <div>
                        <div class="stop-name">{{ $delivery['name'] }}</div>
                        @if ($delivery['address'] && $delivery['address'] !== $delivery['name'])
                            <div class="stop-sub">{{ $delivery['address'] }}</div>
                        @endif
                        <div class="stop-sub">
                            @if ($delivery['order_code']) Đơn {{ $delivery['order_code'] }} @endif
                            @if ($delivery['sequence']) · Chặng {{ $delivery['sequence'] }} @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="muted">Chưa có điểm giao hàng.</p>
            @endforelse
        </div>
    </section>

    <section class="card map-card">
        @if ($map['has_points'])
            <div id="tracking-map"></div>
            <div class="map-toolbar">
                <button id="refresh-btn" type="button" class="btn" aria-label="Cập nhật vị trí xe">
                    <span class="spin">↻</span> Cập nhật
                </button>
            </div>
            <div class="legend">
                <span><i style="background:#10B981"></i> Xuất phát</span>
                <span><i style="background:#F59E0B"></i> Lấy hàng</span>
                <span><i style="background:#EF4444"></i> Giao hàng</span>
                <span><i style="background:#3B82F6"></i> Kết thúc</span>
                @if (count($map['points']) >= 2)
                    <span><i style="background:#4F46E5;border-radius:2px;width:14px;height:4px"></i> Tuyến dự kiến</span>
                @endif
                @if (count($map['traveled']) >= 2)
                    <span><i style="background:#10B981;border-radius:2px;width:14px;height:4px"></i> Xe đã chạy</span>
                @endif
            </div>
        @else
            <div class="empty">Chuyến chưa có lộ trình trên bản đồ. Vui lòng quay lại sau.</div>
        @endif
    </section>

    <section class="card live">
        <div class="live-left">
            <span class="pulse"></span>
            <div>
                <div class="live-title">Vị trí xe</div>
                <div id="gps-updated" class="muted">
                    {{ $initialPosition['position']['updated_at_human'] ?? 'Chưa có tín hiệu GPS' }}
                </div>
            </div>
        </div>
        <div id="gps-stale" class="warn hidden">⚠ Tín hiệu GPS đã cũ</div>
        <div id="poll-note" class="muted small">Tự động cập nhật mỗi 30 giây</div>
    </section>
</main>

<footer class="shell">
    Vị trí xe được cập nhật tự động từ thiết bị GPS của phương tiện.<br>
    Mọi thắc mắc về chuyến hàng, vui lòng liên hệ bộ phận điều vận ASG.
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const mapData = @json($map);
    const initialPosition = @json($initialPosition);
    const positionUrl = @json($positionUrl);
    const truckIconUrl = @json(asset('images/truck.png'));
    const plateNumber = @json($trip->vehicle?->plate_number ?? '');
    const isFinishedInitially = {{ $initialPosition['is_finished'] ? 'true' : 'false' }};

    const STATUS_CLASSES = {
        purple: 'badge-purple',
        info: 'badge-info',
        warning: 'badge-warning',
        success: 'badge-success',
        danger: 'badge-danger',
        gray: 'badge-gray',
    };

    const POINT_COLORS = {
        start: '#10B981',
        pickup: '#F59E0B',
        delivery: '#EF4444',
        end: '#3B82F6',
    };

    let map = null;
    let markersLayer = null;
    let vehicleMarker = null;
    let vehicleHalo = null;
    let pollTimer = null;
    let finished = isFinishedInitially;

    const refreshBtn = document.getElementById('refresh-btn');
    const pollNote = document.getElementById('poll-note');
    const gpsUpdated = document.getElementById('gps-updated');
    const gpsStale = document.getElementById('gps-stale');

    function pointIcon(type, label) {
        return L.divIcon({
            className: '',
            html: '<div class="map-pin" style="background:' + (POINT_COLORS[type] || '#64748B') + '">' + label + '</div>',
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -14],
        });
    }

    function textNode(tag, className, text) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        el.textContent = text;
        return el;
    }

    function pointPopup(marker) {
        const wrap = document.createElement('div');
        wrap.appendChild(textNode('div', 'popup-title', marker.title));
        if (marker.description) {
            wrap.appendChild(textNode('div', 'popup-desc', marker.description));
        }
        return wrap;
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

    function stopPolling() {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function markExpired() {
        finished = true;
        stopPolling();
        if (refreshBtn) refreshBtn.disabled = true;
        setNotice('Link theo dõi đã hết hạn. Vui lòng liên hệ điều vận để nhận link mới.', 'error');
    }

    function markFinished() {
        finished = true;
        stopPolling();
        if (refreshBtn) refreshBtn.disabled = true;
        setNotice('Chuyến đã kết thúc. Bản đồ giữ vị trí cuối cùng của xe.');
    }

    function applyStatus(data) {
        const badge = document.getElementById('status-badge');
        if (!badge) return;
        badge.textContent = data.status_label;
        badge.className = 'badge ' + (STATUS_CLASSES[data.status_color] || 'badge-gray');
    }

    function updateVehicle(position) {
        if (!position) {
            if (vehicleMarker) {
                vehicleMarker.remove();
                vehicleMarker = null;
            }
            if (vehicleHalo) {
                vehicleHalo.remove();
                vehicleHalo = null;
            }
            if (gpsUpdated) gpsUpdated.textContent = 'Chưa có tín hiệu GPS';
            if (gpsStale) gpsStale.classList.add('hidden');
            return;
        }

        const latlng = [position.latitude, position.longitude];

        if (!map) return;

        if (!vehicleMarker) {
            vehicleHalo = L.circleMarker(latlng, {
                radius: 22,
                color: '#2563EB',
                fillColor: '#60A5FA',
                fillOpacity: 0.28,
                weight: 2,
            }).addTo(map);

            vehicleMarker = L.marker(latlng, {
                icon: L.icon({
                    iconUrl: truckIconUrl,
                    iconSize: [40, 40],
                    iconAnchor: [20, 20],
                    popupAnchor: [0, -18],
                }),
                zIndexOffset: 1000,
            }).addTo(map);
        } else {
            vehicleMarker.setLatLng(latlng);
            vehicleHalo.setLatLng(latlng);
        }

        vehicleMarker.bindPopup(vehiclePopup(position));

        if (gpsUpdated) {
            gpsUpdated.textContent = position.updated_at_human
                ? 'Cập nhật ' + position.updated_at_human
                : 'Đã có vị trí xe';
        }

        if (gpsStale) {
            gpsStale.classList.toggle('hidden', !position.is_stale);
        }
    }

    async function refreshPosition(manual) {
        if (finished) return;

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
                markExpired();
                return;
            }

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            applyStatus(data);
            updateVehicle(data.position);

            if (data.is_finished) {
                markFinished();
                return;
            }

            if (manual) {
                setNotice('Đã cập nhật vị trí mới nhất.', 'ok');
            }
        } catch (error) {
            if (manual) {
                setNotice('Không thể cập nhật vị trí. Vui lòng kiểm tra kết nối mạng.', 'error');
            }
        } finally {
            if (refreshBtn) {
                refreshBtn.disabled = finished;
                refreshBtn.classList.remove('loading');
            }
        }
    }

    function initMap() {
        if (!mapData.has_points) return;

        map = L.map('tracking-map', {
            zoomControl: true,
            scrollWheelZoom: true,
        }).setView(mapData.center, 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer">OpenStreetMap</a>',
        }).addTo(map);

        markersLayer = L.layerGroup().addTo(map);

        mapData.markers.forEach(function (marker) {
            L.marker([marker.lat, marker.lng], { icon: pointIcon(marker.type, marker.label) })
                .bindPopup(pointPopup(marker))
                .addTo(markersLayer);
        });

        if (mapData.route && mapData.route.length >= 2) {
            L.polyline(mapData.route, {
                color: '#4F46E5',
                weight: 5,
                opacity: 0.85,
                lineJoin: 'round',
            }).addTo(map);
        } else if (mapData.points.length >= 2) {
            L.polyline(mapData.points, {
                color: '#94A3B8',
                weight: 3,
                opacity: 0.8,
                dashArray: '6 6',
            }).addTo(map);
        }

        // Tuyến đường xe đã chạy thực tế (từ GPS các mốc hành trình).
        if (mapData.traveled_route && mapData.traveled_route.length >= 2) {
            L.polyline(mapData.traveled_route, {
                color: '#10B981',
                weight: 4,
                opacity: 0.9,
                lineJoin: 'round',
            }).addTo(map);
        } else if (mapData.traveled && mapData.traveled.length >= 2) {
            L.polyline(mapData.traveled, {
                color: '#10B981',
                weight: 3,
                opacity: 0.85,
                dashArray: '4 6',
            }).addTo(map);
        }

        const bounds = L.latLngBounds([]);
        (mapData.points || []).concat(mapData.traveled || []).forEach(function (point) {
            bounds.extend(L.latLng(point[0], point[1]));
        });

        if (initialPosition.has_position) {
            bounds.extend(L.latLng(initialPosition.position.latitude, initialPosition.position.longitude));
        }

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [32, 32], maxZoom: 15 });
        }

        updateVehicle(initialPosition.position);

        // Leaflet cần biết kích thước thật sau khi layout ổn định (đặc biệt trên mobile).
        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { refreshPosition(true); });
    }

    initMap();

    if (finished) {
        markFinished();
    } else {
        pollTimer = setInterval(function () { refreshPosition(false); }, 30000);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && !finished) {
                refreshPosition(false);
            }
        });
    }
</script>
</body>
</html>
