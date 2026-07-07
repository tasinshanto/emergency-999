/**
 * Admin reports: reorder dispatch unit/responder dropdowns to show nearest organizations first,
 * and filter selections to prevent assigning invalid/unrelated resources.
 */
(function () {
    var base = window.APP_BASE || '';

    function api(path) {
        return base + '/' + path.replace(/^\/+/, '');
    }

    async function loadForm(form) {
        var reportId = form.dataset.reportId;
        var unitType = form.dataset.unitType || ''; // e.g. "fire", "ambulance"
        var hint = form.querySelector('.dispatch-nearest-hint');
        var unitSelect = form.querySelector('.dispatch-unit-select');
        var responderSelect = form.querySelector('.dispatch-responder-select');
        if (!reportId || !unitSelect || !responderSelect) {
            return;
        }

        try {
            var response = await fetch(api('api/dispatch_suggestions.php?report_id=' + encodeURIComponent(reportId)), { cache: 'no-store' });
            var data = await response.json();
            if (!data.success) {
                if (hint) {
                    hint.textContent = 'Could not load nearest suggestions.';
                }
                return;
            }

            var rangeKm = data.range_km || 12;
            
            // Filter units to show only those matching this form's specific unit type
            var filteredUnits = (data.units || []).filter(function (unit) {
                return !unitType || unit.unit_type === unitType;
            });
            var unitIds = filteredUnits.map(function (u) { return u.id; });
            var nearestUnit = filteredUnits && filteredUnits[0];

            // Rebuild unit select dropdown to show ONLY matching units of this type
            var placeholderUnit = unitSelect.querySelector('option[value=""]');
            unitSelect.innerHTML = '';
            if (placeholderUnit) {
                unitSelect.appendChild(placeholderUnit);
            }
            filteredUnits.forEach(function (unit) {
                var opt = document.createElement('option');
                opt.value = String(unit.id);
                var cleanName = unit.unit_name.replace(/\s+Unit$/i, '');
                var tag = unit.in_range ? '' : ' [out of range]';
                var rec = unitIds[0] === unit.id ? ' ★' : '';
                
                // Count responders for this unit
                var unitResponders = (data.responders || []).filter(function (r) {
                    return String(r.dispatch_unit_id) === String(unit.id);
                });
                var totalResponders = unitResponders.length;
                var freeResponders = unitResponders.filter(function (r) {
                    return r.availability_status === 'available';
                }).length;
                
                var responderText = ' (' + freeResponders + '/' + totalResponders + ' free)';
                var isBusy = (totalResponders > 0 && freeResponders === 0) || totalResponders === 0;
                var statusText = isBusy ? ' (busy)' : '';
                
                opt.textContent = cleanName + ' (' + unit.distance_km + 'km)' + responderText + rec + tag + statusText;
                unitSelect.appendChild(opt);
            });

            if (nearestUnit) {
                unitSelect.value = String(nearestUnit.id);
            }

            // Function to update responders based on selected unit
            function updateResponders() {
                var selectedUnitId = unitSelect.value;
                var placeholderResp = responderSelect.querySelector('option[value=""]');
                responderSelect.innerHTML = '';
                if (placeholderResp) {
                    responderSelect.appendChild(placeholderResp);
                }

                if (!selectedUnitId) {
                    return;
                }

                // Filter responders to only those belonging to the selected unit
                var forUnit = (data.responders || []).filter(function (r) {
                    return String(r.dispatch_unit_id) === String(selectedUnitId);
                });
                var responderIds = forUnit.map(function (r) { return r.id; });

                forUnit.forEach(function (r) {
                    var opt = document.createElement('option');
                    opt.value = String(r.id);
                    var statusText = r.availability_status === 'available' ? '' : ' (' + r.availability_status + ')';
                    var rec = responderIds[0] === r.id ? ' ★' : '';
                    opt.textContent = r.responder_name + statusText + rec;
                    responderSelect.appendChild(opt);
                });

                if (responderIds[0]) {
                    responderSelect.value = String(responderIds[0]);
                }
            }

            // Listen for unit change locally to update responders (no API call)
            unitSelect.onchange = function () {
                updateResponders();
            };

            // Initial population of responders
            updateResponders();

            if (hint) {
                var parts = [];
                if (nearestUnit) {
                    var uName = nearestUnit.unit_name.replace(/\s+Unit$/i, '');
                    parts.push('★ ' + uName + ' (' + nearestUnit.distance_km + 'km)');
                }
                
                // Show hospital suggestion only in ambulance dispatch form
                if (data.hospitals && data.hospitals[0] && unitType === 'ambulance') {
                    var nearestHospital = data.hospitals[0];
                    var hName = nearestHospital.name.length > 25 ? nearestHospital.name.substring(0, 22) + '…' : nearestHospital.name;
                    parts.push('Hospital: ' + hName + ' (' + nearestHospital.distance_km + 'km)');
                }
                parts.push('Range: ' + rangeKm + 'km');
                hint.textContent = parts.join('  ·  ');
            }
        } catch (err) {
            if (hint) {
                hint.textContent = 'Nearest suggestions unavailable.';
            }
        }
    }

    document.querySelectorAll('.dispatch-assign-form').forEach(function (form) {
        loadForm(form);
    });
})();
