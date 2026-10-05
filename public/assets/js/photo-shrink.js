// Shrinks a large JPEG photo right after it is picked, before the form is
// submitted. Phones with high-resolution cameras produce 10-25 MB photos;
// a body that large goes over the server's post_max_size, and PHP then drops
// the whole form (CSRF token included), which showed up as a confusing
// "CSRF token ไม่ถูกต้อง" error on those phones only.
// Applies to every <input type="file" accept="image/..."> on the page. Only
// JPEGs over SHRINK_OVER_BYTES are touched, so PNG logos/maps keep their
// transparency. Falls back to the original file whenever the browser can't
// decode it or can't replace an input's file (older browsers).
(function () {
  var MAX_SIDE = 2560;
  var QUALITY = 0.85;
  var SHRINK_OVER_BYTES = 2.5 * 1024 * 1024;

  if (typeof DataTransfer === 'undefined') return;

  function shrink(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          resolve(blob && blob.size < file.size
            ? new File([blob], file.name.replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() })
            : file);
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
  }

  document.querySelectorAll('input[type="file"][accept*="image"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file || file.type !== 'image/jpeg' || file.size <= SHRINK_OVER_BYTES) return;
      var form = input.form;
      var submitButtons = form ? form.querySelectorAll('button[type="submit"], button:not([type])') : [];
      // Hold the form until the smaller file is in place.
      submitButtons.forEach(function (b) { b.disabled = true; });
      shrink(file).then(function (smaller) {
        if (smaller !== file && input.files[0] === file) {
          try {
            var dt = new DataTransfer();
            dt.items.add(smaller);
            input.files = dt.files;
          } catch (e) { /* keep the original */ }
        }
      }).finally(function () {
        submitButtons.forEach(function (b) { b.disabled = false; });
      });
    });
  });
})();
