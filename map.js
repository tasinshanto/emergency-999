/** @deprecated Use public-live-incident-map.js — shim for old URLs. */
(function () {
    var s = document.createElement('script');
    s.src = (window.APP_BASE || '') + '/assets/js/public-live-incident-map.js';
    document.head.appendChild(s);
})();
