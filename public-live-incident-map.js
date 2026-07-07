/**
 * Public home page: live incident map, click-to-set coordinates, report form submit.
 */
(function () {
    var dhaka = [23.8103, 90.4125];
    var base = window.APP_BASE || '';
    var mapElement = document.getElementById('map');
    if (!mapElement || !window.L) {
        return;
    }

    function api(path) {
        return base + '/' + path.replace(/^\/+/, '');
    }

    var map = L.map('map', { zoomControl: false, preferCanvas: true }).setView(dhaka, 12);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var markerLayer = L.layerGroup().addTo(map);
    var markers = new Map();
    var form = document.getElementById('report-form');
    var latInput = document.getElementById('latitude');
    var lngInput = document.getElementById('longitude');
    var typeSelect = document.getElementById('emergency_type_id');
    var liveCount = document.getElementById('live-count');
    var lastUpdated = document.getElementById('last-updated');
    var formStatus = document.getElementById('form-status');
    var pickMarker = null;

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    function markerIcon(color) {
        return L.divIcon({
            className: '',
            html: '<span class="map-marker" style="background:' + color + '"></span>',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });
    }

    function setPickMarker(lat, lng) {
        if (!latInput || !lngInput) {
            return;
        }
        latInput.value = Number(lat).toFixed(7);
        lngInput.value = Number(lng).toFixed(7);
        if (pickMarker) {
            pickMarker.setLatLng([lat, lng]);
            return;
        }
        pickMarker = L.marker([lat, lng], {
            draggable: true,
            icon: L.divIcon({
                className: '',
                html: '<span class="map-marker" style="background:#0f172a;border:2px solid #fff"></span>',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            })
        }).addTo(map);
        pickMarker.on('dragend', function () {
            var p = pickMarker.getLatLng();
            setPickMarker(p.lat, p.lng);
        });
    }

    map.on('click', function (e) {
        setPickMarker(e.latlng.lat, e.latlng.lng);
    });

    async function loadTypes() {
        if (!typeSelect) {
            return;
        }
        try {
            var response = await fetch(api('api/emergency_types.php'), { cache: 'no-store' });
            var data = await response.json();
            typeSelect.innerHTML = '<option value="">Select type</option>';
            (data.types || []).forEach(function (type) {
                var opt = document.createElement('option');
                opt.value = type.id;
                opt.textContent = type.name;
                typeSelect.appendChild(opt);
            });
        } catch (err) {
            typeSelect.innerHTML = '<option value="">Types unavailable</option>';
        }
    }

    async function loadIncidents() {
        try {
            var response = await fetch(api('api/incidents.php'), { cache: 'no-store' });
            var data = await response.json();
            var seen = new Set();
            (data.incidents || []).forEach(function (incident) {
                var id = String(incident.id);
                var latLng = [Number(incident.latitude), Number(incident.longitude)];
                var html = '<strong>' + escapeHtml(incident.title) + '</strong><br>' +
                    escapeHtml(incident.type_name) + ' | ' + escapeHtml(String(incident.status).replace('_', ' '));
                seen.add(id);
                if (markers.has(id)) {
                    markers.get(id).setLatLng(latLng).setPopupContent(html);
                    return;
                }
                markers.set(id, L.marker(latLng, { icon: markerIcon(incident.color || '#dc2626') })
                    .bindPopup(html).addTo(markerLayer));
            });
            markers.forEach(function (marker, id) {
                if (!seen.has(id)) {
                    markerLayer.removeLayer(marker);
                    markers.delete(id);
                }
            });
            if (liveCount) {
                liveCount.textContent = String((data.incidents || []).length);
            }
            if (lastUpdated) {
                lastUpdated.textContent = new Date().toLocaleTimeString();
            }
        } catch (err) {
            if (lastUpdated) {
                lastUpdated.textContent = 'Unavailable';
            }
        }
    }

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (formStatus) {
                formStatus.hidden = false;
                formStatus.className = 'rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm';
                formStatus.textContent = 'Submitting...';
            }
            try {
                var body = new FormData(form);
                var response = await fetch(api('api/reports.php'), { method: 'POST', body: body });
                var data = await response.json();
                if (data.success) {
                    if (formStatus) {
                        formStatus.className = 'rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800';
                        formStatus.textContent = data.message || 'Report submitted.';
                    }
                    form.reset();
                    setPickMarker(dhaka[0], dhaka[1]);
                    loadIncidents();
                } else if (formStatus) {
                    formStatus.className = 'rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800';
                    formStatus.textContent = data.message || 'Submission failed.';
                }
            } catch (err) {
                if (formStatus) {
                    formStatus.className = 'rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800';
                    formStatus.textContent = 'Network error.';
                }
            }
        });
    }

    var useLocation = document.getElementById('use-location');
    if (useLocation) {
        useLocation.addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported.');
                return;
            }
            useLocation.disabled = true;
            navigator.geolocation.getCurrentPosition(function (pos) {
                setPickMarker(pos.coords.latitude, pos.coords.longitude);
                map.setView([pos.coords.latitude, pos.coords.longitude], 14);
                useLocation.disabled = false;
            }, function () {
                alert('Could not get location.');
                useLocation.disabled = false;
            });
        });
    }

    setPickMarker(dhaka[0], dhaka[1]);
    loadTypes();
    loadIncidents();
    setInterval(loadIncidents, 5000);
})();
