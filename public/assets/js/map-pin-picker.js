// Click-to-place % pin on a banner image, for a single hidden x/y field pair.
// Markup contract: a [data-pin-field] wrapper containing one
// [data-pin-image-wrap] (holding the <img> and, optionally, an existing
// [data-pin-marker] .pin-picker-pin div), a hidden [data-pin-x]/[data-pin-y]
// input pair, and an optional [data-pin-remove] button. Several
// [data-pin-field]s can exist on the same page (e.g. one per form) —
// each is wired independently.
(function () {
  document.querySelectorAll('[data-pin-field]').forEach(function (field) {
    var wrap = field.querySelector('[data-pin-image-wrap]');
    var img = wrap && wrap.querySelector('img');
    var xInput = field.querySelector('[data-pin-x]');
    var yInput = field.querySelector('[data-pin-y]');
    var removeBtn = field.querySelector('[data-pin-remove]');
    var marker = wrap && wrap.querySelector('[data-pin-marker]');
    if (!wrap || !img || !xInput || !yInput) return;

    function ensureMarker() {
      if (!marker) {
        marker = document.createElement('div');
        marker.className = 'pin-picker-pin';
        marker.setAttribute('data-pin-marker', '');
        wrap.appendChild(marker);
      }
      return marker;
    }

    function setPin(x, y) {
      xInput.value = x.toFixed(2);
      yInput.value = y.toFixed(2);
      var m = ensureMarker();
      m.style.left = x + '%';
      m.style.top = y + '%';
      m.style.display = '';
    }

    img.addEventListener('click', function (e) {
      var rect = img.getBoundingClientRect();
      var x = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100));
      var y = Math.max(0, Math.min(100, ((e.clientY - rect.top) / rect.height) * 100));
      setPin(x, y);
    });

    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        xInput.value = '';
        yInput.value = '';
        if (marker) {
          marker.remove();
          marker = null;
        }
      });
    }
  });
})();
