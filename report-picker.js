/** @deprecated Use user-report-location-map.js — shim for old URLs. */
(function () {
    var s = document.createElement('script');
    s.src = (window.APP_BASE || '') + '/assets/js/user-report-location-map.js';
    document.head.appendChild(s);
})();
