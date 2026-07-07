/**
 * Admin & responder dashboard live map — incidents, resources, dispatch range, self-dispatch.
 */
(function () {
    const base = window.APP_BASE || '';
    const mapElement = document.getElementById('dashboard-map');
    const statusTarget = document.getElementById('dashboard-status');

    const isResponder = mapElement && mapElement.dataset.responder === 'true';
    const baseLat = mapElement ? parseFloat(mapElement.dataset.baseLat || '23.8103') : 23.8103;
    const baseLng = mapElement ? parseFloat(mapElement.dataset.baseLng || '90.4125') : 90.4125;
    let dispatchRangeKm = 12;
    let rangeCircle = null;

    function api(path) {
        return base + '/' + path.replace(/^\/+/, '');
    }

    function setText(selector, value) {
        var el = document.querySelector(selector);
        if (el) {
            el.textContent = String(value);
        }
    }

    function rowsToObject(rows) {
        var out = {};
        (rows || []).forEach(function (row) {
            out[row.status] = Number(row.total);
        });
        return out;
    }

    async function loadStatus() {
        try {
            var response = await fetch(api('api/status.php'), { cache: 'no-store' });
            var data = await response.json();
            var reports = rowsToObject(data.reports);
            var units = rowsToObject(data.units);
            var responders = rowsToObject(data.responders);
            var assignments = rowsToObject(data.assignments);

            setText('[data-count="pending"]', reports.pending || 0);
            setText('[data-count="active"]', (reports.verified || 0) + (reports.assigned || 0) + (reports.dispatched || 0) + (reports.in_progress || 0));
            setText('[data-count="resolved"]', reports.resolved || 0);
            setText('[data-count="available-units"]', units.available || 0);
            setText('[data-count="available-responders"]', responders.available || 0);
            setText('[data-count="open-assignments"]', (assignments.assigned || 0) + (assignments.accepted || 0) + (assignments.arrived || 0));

            if (statusTarget) {
                statusTarget.textContent = 'Updated ' + new Date().toLocaleTimeString();
            }
        } catch (error) {
            if (statusTarget) {
                statusTarget.textContent = 'Status API unavailable';
            }
        }
    }

    if (!mapElement || !window.L) {
        loadStatus();
        setInterval(loadStatus, 5000);
        return;
    }

    var initialZoom = isResponder ? 13 : 12;
    var map = L.map('dashboard-map').setView([baseLat, baseLng], initialZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var layer = L.layerGroup().addTo(map);
    var resourceLayer = L.layerGroup().addTo(map);
    var markers = new Map();
    var resourceMarkers = new Map();

    function drawRangeCircle() {
        if (!isResponder) {
            return;
        }
        var radiusM = dispatchRangeKm * 1000;
        if (rangeCircle) {
            rangeCircle.setRadius(radiusM);
            return;
        }
        rangeCircle = L.circle([baseLat, baseLng], {
            radius: radiusM,
            color: '#2563eb',
            fillColor: '#3b82f6',
            fillOpacity: 0.08,
            weight: 1,
            dashArray: '6 4'
        }).addTo(map);
        rangeCircle.bindTooltip('Your dispatch range (' + dispatchRangeKm + ' km)', { permanent: false, direction: 'top' });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
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

    function severitySymbol(severity) {
        switch (severity) {
            case 'critical': return '‼';
            case 'high': return '!';
            case 'medium': return '~';
            default: return '·';
        }
    }

    function severityRingColor(severity) {
        switch (severity) {
            case 'critical': return '#dc2626';
            case 'high': return '#ea580c';
            case 'medium': return '#ca8a04';
            default: return '#64748b';
        }
    }

    function responderMarkerIcon(color, severity, isAssigned, canSelfDispatch) {
        var symbol = severitySymbol(severity);
        var ringColor = severityRingColor(severity);
        var pulse = (severity === 'critical' || severity === 'high') ? ' marker-pulse' : '';
        var mine = isAssigned ? ' marker-mine' : '';
        var dispatchable = canSelfDispatch ? ' marker-dispatchable' : '';
        return L.divIcon({
            className: 'incident-marker-icon',
            html: '<span class="map-marker-priority' + pulse + mine + dispatchable + '" style="background:' + color + '; border-color:' + ringColor + '">' +
                '<span class="priority-badge" style="background:' + ringColor + '">' + symbol + '</span>' +
                (isAssigned ? '<span class="assigned-ring"></span>' : '') +
                '</span>',
            iconSize: [38, 38],
            iconAnchor: [19, 19]
        });
    }

    function resourceIcon(label, color) {
        return L.divIcon({
            className: '',
            html: '<span class="resource-marker" style="background:' + color + '">' + label + '</span>',
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });
    }

    function canAssignIncident(incident) {
        return Number(incident.can_self_dispatch) === 1 && Number(incident.is_assigned_to_me) !== 1;
    }

    function buildIncidentPopup(incident) {
        var distInfo = incident.distance_km !== undefined ? '<br><em>' + incident.distance_km + ' km away</em>' : '';
        var assignedInfo = Number(incident.is_assigned_to_me) === 1
            ? '<br><strong style="color:#dc2626">Assigned to you</strong>'
            : '';
        var html = '<div class="incident-popup">' +
            '<strong>' + escapeHtml(incident.title) + '</strong><br>' +
            escapeHtml(incident.type_name) + ' | ' + escapeHtml(String(incident.status).replace('_', ' ')) + '<br>' +
            '<span style="font-weight:700;text-transform:capitalize">' + escapeHtml(incident.severity) + ' priority</span><br>' +
            escapeHtml(incident.address) + distInfo + assignedInfo;

        if (isResponder && Number(incident.is_assigned_to_me) !== 1) {
            var note = canAssignIncident(incident)
                ? ''
                : '<p style="margin:0 0 0.5rem;font-size:0.8rem;color:#64748b">' +
                    escapeHtml(selfDispatchReasonLabel(incident.self_dispatch_reason)) + '</p>';
            html += note +
                '<button type="button" class="btn-self-dispatch" data-report-id="' + escapeHtml(incident.id) +
                '" data-report-title="' + escapeHtml(incident.title) + '"' +
                ' style="display:block;width:100%;margin-top:0.75rem;padding:0.55rem 0.75rem;border:0;border-radius:0.375rem;background:#dc2626;color:#fff;font-size:0.875rem;font-weight:700;cursor:pointer">' +
                'Assign myself to this incident</button>';
        } else if (isResponder) {
            html += '<p style="margin:0.75rem 0 0;font-size:0.85rem;color:#475569">You are already on this incident.</p>';
        }

        html += '</div>';
        return html;
    }

    function selfDispatchReasonLabel(reason) {
        switch (reason) {
            case 'responder_busy': return 'Set your status to available to self-assign.';
            case 'assigned_to_other': return 'Another responder is already assigned.';
            case 'already_assigned_to_you': return 'You are already assigned to this incident.';
            case 'no_unit': return 'Ask admin to link you to a dispatch unit first.';
            case 'invalid_report_status': return 'This incident is not open for self-assignment.';
            default: return 'Self-assignment is not available for this incident.';
        }
    }

    async function selfDispatchToIncident(reportId, title) {
        var prompt = title
            ? 'Assign yourself to "' + title + '" (incident #' + reportId + ')?'
            : 'Assign yourself to incident #' + reportId + '?';
        if (!confirm(prompt)) {
            return;
        }
        try {
            var body = new URLSearchParams();
            body.set('report_id', String(reportId));
            if (window.CSRF_TOKEN) {
                body.set('csrf_token', window.CSRF_TOKEN);
            }
            var response = await fetch(api('api/responder_self_dispatch.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            var data = await response.json();
            if (data.success) {
                alert(data.message || 'Dispatched.');
                window.location.reload();
            } else {
                alert(data.message || 'Could not self-dispatch.');
            }
        } catch (err) {
            alert('Self-dispatch request failed.');
        }
    }

    function wirePopupAssignButton(popup) {
        var root = popup && popup.getElement();
        if (!root) {
            return;
        }
        var btn = root.querySelector('.btn-self-dispatch');
        if (!btn) {
            return;
        }
        L.DomEvent.disableClickPropagation(btn);
        btn.onclick = function (ev) {
            L.DomEvent.stopPropagation(ev);
            selfDispatchToIncident(btn.getAttribute('data-report-id'), btn.getAttribute('data-report-title'));
        };
    }

    map.on('popupopen', function (e) {
        wirePopupAssignButton(e.popup);
    });

    function bindIncidentMarker(marker, html) {
        marker.unbindPopup();
        marker.unbindTooltip();
        marker.off('click.openPopup');
        marker.bindPopup(html, {
            maxWidth: 280,
            closeButton: true,
            autoClose: true,
            closeOnClick: false
        });
        marker.on('click.openPopup', function () {
            marker.openPopup();
        });
        if (marker.isPopupOpen && marker.isPopupOpen()) {
            wirePopupAssignButton(marker.getPopup());
        }
    }

    async function loadIncidents() {
        try {
            var endpoint = isResponder ? 'api/responder_incidents.php' : 'api/incidents.php';
            var response = await fetch(api(endpoint), { cache: 'no-store' });
            var data = await response.json();
            if (data.dispatch_range_km) {
                dispatchRangeKm = Number(data.dispatch_range_km);
                drawRangeCircle();
            } else if (isResponder && !rangeCircle) {
                drawRangeCircle();
            }

            var seen = new Set();

            (data.incidents || []).forEach(function (incident) {
                var id = String(incident.id);
                var latLng = [Number(incident.latitude), Number(incident.longitude)];
                var html = buildIncidentPopup(incident);
                seen.add(id);

                var canSelfDispatch = canAssignIncident(incident);
                var icon = isResponder
                    ? responderMarkerIcon(
                        incident.color || '#dc2626',
                        incident.severity || 'medium',
                        Number(incident.is_assigned_to_me) === 1,
                        canSelfDispatch
                    )
                    : markerIcon(incident.color || '#dc2626');

                if (markers.has(id)) {
                    var existing = markers.get(id);
                    existing.setLatLng(latLng).setIcon(icon);
                    bindIncidentMarker(existing, html);
                    return;
                }

                var marker = L.marker(latLng, {
                    icon: icon,
                    interactive: true,
                    riseOnHover: true,
                    zIndexOffset: Number(incident.is_assigned_to_me) === 1 ? 1000 : (canSelfDispatch ? 500 : 0)
                });
                bindIncidentMarker(marker, html);
                marker.addTo(layer);
                markers.set(id, marker);
            });

            markers.forEach(function (marker, id) {
                if (!seen.has(id)) {
                    layer.removeLayer(marker);
                    markers.delete(id);
                }
            });
        } catch (error) {
            if (statusTarget) {
                statusTarget.textContent = 'Incident API unavailable';
            }
        }
    }

    async function loadResources() {
        if (mapElement.dataset.resources !== 'true') {
            return;
        }
        try {
            var response = await fetch(api('api/resources.php'), { cache: 'no-store' });
            var data = await response.json();
            var seen = new Set();

            (data.units || []).forEach(function (unit) {
                var respCount = Number(unit.responder_count || 0);
                var lowStaff = respCount < 5;
                var unitColor = lowStaff ? '#ea580c' : '#2563eb';
                var staffNote = lowStaff
                    ? '<br><span style="color:#ea580c;font-weight:700;font-size:0.8rem">&#9888; Low staffing &mdash; ' + respCount + ' responder' + (respCount !== 1 ? 's' : '') + '</span>'
                    : '<br><span style="color:#16a34a;font-size:0.8rem">' + respCount + ' responder' + (respCount !== 1 ? 's' : '') + ' on unit</span>';
                addResource('unit-' + unit.id, [Number(unit.latitude), Number(unit.longitude)],
                    lowStaff ? '!' : 'U',
                    unitColor,
                    '<strong>' + escapeHtml(unit.unit_name) + '</strong><br>' +
                    escapeHtml(unit.unit_type) + ' | ' + escapeHtml(unit.status) + '<br>' +
                    escapeHtml(unit.base_address) + staffNote);
                seen.add('unit-' + unit.id);
            });

            (data.hospitals || []).forEach(function (hospital) {
                var beds = Number(hospital.available_beds || 0);
                var lowBeds = beds < 5;
                var hospColor = lowBeds ? '#ea580c' : '#16a34a';
                var bedNote = lowBeds
                    ? '<br><span style="color:#ea580c;font-weight:700;font-size:0.8rem">&#9888; Low capacity &mdash; ' + beds + ' bed' + (beds !== 1 ? 's' : '') + ' available</span>'
                    : '<br>Beds: ' + escapeHtml(hospital.available_beds) + ' | ' + escapeHtml(hospital.status);
                addResource('hospital-' + hospital.id, [Number(hospital.latitude), Number(hospital.longitude)],
                    lowBeds ? '!' : 'H',
                    hospColor,
                    '<strong>' + escapeHtml(hospital.name) + '</strong><br>' +
                    bedNote + '<br>' + escapeHtml(hospital.address));
                seen.add('hospital-' + hospital.id);
            });

            (data.responders || []).forEach(function (responder) {
                if (!responder.latitude || !responder.longitude) {
                    return;
                }
                addResource('responder-' + responder.id, [Number(responder.latitude), Number(responder.longitude)], 'R', '#7c3aed',
                    '<strong>' + escapeHtml(responder.name) + '</strong><br>' +
                    escapeHtml(responder.designation) + ' | ' + escapeHtml(responder.availability_status) + '<br>' +
                    escapeHtml(responder.unit_name || 'No unit'));
                seen.add('responder-' + responder.id);
            });

            resourceMarkers.forEach(function (marker, id) {
                if (!seen.has(id)) {
                    resourceLayer.removeLayer(marker);
                    resourceMarkers.delete(id);
                }
            });
        } catch (error) {
            if (statusTarget) {
                statusTarget.textContent = 'Resource API unavailable';
            }
        }
    }

    function addResource(id, latLng, label, color, html) {
        if (resourceMarkers.has(id)) {
            resourceMarkers.get(id).setLatLng(latLng).setPopupContent(html);
            return;
        }
        resourceMarkers.set(id, L.marker(latLng, { icon: resourceIcon(label, color) }).bindPopup(html).addTo(resourceLayer));
    }

    if (isResponder) {
        drawRangeCircle();
    }

    function tick() {
        loadStatus();
        loadIncidents();
        loadResources();
    }

    tick();
    setInterval(tick, 5000);
})();
