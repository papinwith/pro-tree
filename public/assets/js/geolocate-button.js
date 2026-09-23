// "Use current location" button for a pair of GPS coordinate fields — reads
// the browser's own Geolocation API so an admin standing in front of a tree
// can fill lat/lng with one tap instead of switching to a separate maps app
// and copy-pasting. Markup contract:
//   <button data-geolocate data-lat-target="ID" data-lng-target="ID" data-status-target="ID">
// with a nearby element (any tag) carrying the given status-target id to
// receive progress/error text — hidden while empty.
(function () {
  document.querySelectorAll('[data-geolocate]').forEach(function (button) {
    var latInput = document.getElementById(button.dataset.latTarget);
    var lngInput = document.getElementById(button.dataset.lngTarget);
    var status = document.getElementById(button.dataset.statusTarget);
    if (!latInput || !lngInput) return;

    var idleLabel = button.textContent;

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text;
      status.hidden = !text;
      status.classList.toggle('field-hint-error', !!isError);
    }

    button.addEventListener('click', function () {
      if (!navigator.geolocation) {
        setStatus('เบราว์เซอร์นี้ไม่รองรับการระบุตำแหน่งอัตโนมัติ กรุณากรอกพิกัดด้วยตนเอง', true);
        return;
      }

      button.disabled = true;
      button.textContent = 'กำลังค้นหาตำแหน่ง...';
      setStatus('');

      navigator.geolocation.getCurrentPosition(
        function (position) {
          latInput.value = position.coords.latitude.toFixed(6);
          lngInput.value = position.coords.longitude.toFixed(6);
          var accuracy = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
          setStatus(accuracy ? ('ได้ตำแหน่งแล้ว — ความแม่นยำ ±' + accuracy + ' เมตร') : 'ได้ตำแหน่งแล้ว');
          button.disabled = false;
          button.textContent = idleLabel;
        },
        function (error) {
          var message = 'ไม่สามารถระบุตำแหน่งได้ กรุณากรอกด้วยตนเอง';
          if (error.code === error.PERMISSION_DENIED) {
            message = 'ไม่ได้รับอนุญาตให้เข้าถึงตำแหน่ง กรุณาอนุญาตแล้วลองใหม่ หรือกรอกด้วยตนเอง';
          } else if (error.code === error.TIMEOUT) {
            message = 'ค้นหาตำแหน่งใช้เวลานานเกินไป กรุณาลองใหม่ หรือกรอกด้วยตนเอง';
          }
          setStatus(message, true);
          button.disabled = false;
          button.textContent = idleLabel;
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  });
})();
