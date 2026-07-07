/**
 * User dashboard: draggable marker on #report-map to set latitude/longitude fields.
 */
(function () {
    var picker = document.getElementById('report-map');
    if (!picker || !window.L) {
        return;
    }

    var latInput = document.getElementById('latitude');
    var lngInput = document.getElementById('longitude');
    var button = document.getElementById('use-location');
    var defaultLat = Number(latInput && latInput.value ? latInput.value : 23.8103);
    var defaultLng = Number(lngInput && lngInput.value ? lngInput.value : 90.4125);

    var map = L.map('report-map').setView([defaultLat, defaultLng], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    function syncInputs(lat, lng) {
        if (latInput) {
            latInput.value = Number(lat).toFixed(7);
        }
        if (lngInput) {
            lngInput.value = Number(lng).toFixed(7);
        }
    }

    var marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);
    marker.on('dragend', function () {
        var p = marker.getLatLng();
        syncInputs(p.lat, p.lng);
    });

    map.on('click', function (e) {
        marker.setLatLng(e.latlng);
        syncInputs(e.latlng.lat, e.latlng.lng);
    });

    if (button) {
        button.addEventListener('click', function () {
            if (!navigator.geolocation) {
                return;
            }
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                marker.setLatLng([lat, lng]);
                map.setView([lat, lng], 14);
                syncInputs(lat, lng);
            });
        });
    }
})();
