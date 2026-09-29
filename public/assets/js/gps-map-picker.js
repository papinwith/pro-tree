// Real map (OpenStreetMap via Leaflet) for picking a GPS position: tap the map
// or drag the pin and the latitude/longitude fields are filled in; typing in
// those fields (or the "use current location" button) moves the pin.
// Markup contract (see gpsMapPicker() in includes/functions.php):
//   <div class="gps-map" data-gps-map data-lat-target="ID" data-lng-target="ID"
//        data-default-lat=".." data-default-lng=".." data-default-zoom=".."></div>
// Needs Leaflet loaded first. If it couldn't load (offline, blocked CDN) the
// map box is hidden and the plain lat/lng fields keep working on their own.
(function () {
  document.querySelectorAll('[data-gps-map]').forEach(function (el) {
    if (typeof L === 'undefined') { el.hidden = true; return; }
    var latInput = document.getElementById(el.dataset.latTarget);
    var lngInput = document.getElementById(el.dataset.lngTarget);
    if (!latInput || !lngInput) return;

    function typedPosition() {
      var lat = parseFloat(latInput.value);
      var lng = parseFloat(lngInput.value);
      return isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? [lat, lng] : null;
    }

    var start = typedPosition();
    var map = L.map(el).setView(
      start || [parseFloat(el.dataset.defaultLat), parseFloat(el.dataset.defaultLng)],
      start ? 18 : parseInt(el.dataset.defaultZoom, 10)
    );
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    var marker = null;
    function writeFields(latlng) {
      latInput.value = latlng.lat.toFixed(6);
      lngInput.value = latlng.lng.toFixed(6);
    }
    function placeMarker(latlng, pan) {
      if (!marker) {
        marker = L.marker(latlng, { draggable: true }).addTo(map);
        marker.on('dragend', function () { writeFields(marker.getLatLng()); });
      } else {
        marker.setLatLng(latlng);
      }
      if (pan) map.setView(latlng, Math.max(map.getZoom(), 17));
    }

    if (start) placeMarker(start, false);
    map.on('click', function (e) {
      placeMarker(e.latlng, false);
      writeFields(e.latlng);
    });
    [latInput, lngInput].forEach(function (input) {
      input.addEventListener('input', function () {
        var pos = typedPosition();
        if (pos) placeMarker(pos, true);
      });
    });

    // A map created inside a closed <details> has no size yet — redraw on open.
    var details = el.closest('details');
    if (details) details.addEventListener('toggle', function () {
      if (details.open) map.invalidateSize();
    });
  });
})();
