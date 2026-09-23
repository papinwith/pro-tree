// Explicit "take photo" vs "choose from gallery" buttons in front of a
// hidden <input type="file">, toggling the `capture` attribute right before
// opening the native picker — instead of relying on whatever a single plain
// file input happens to default to (varies by browser, and some in-app
// webviews hide the camera option entirely unless `capture` is set).
// Markup contract: a wrapper carrying data-photo-capture-for="INPUT_ID",
// containing buttons with data-photo-action="camera" / "gallery".
(function () {
  document.querySelectorAll('[data-photo-capture-for]').forEach(function (wrapper) {
    var input = document.getElementById(wrapper.dataset.photoCaptureFor);
    if (!input) return;

    wrapper.querySelectorAll('[data-photo-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (button.dataset.photoAction === 'camera') {
          input.setAttribute('capture', 'environment');
        } else {
          input.removeAttribute('capture');
        }
        input.click();
      });
    });
  });
})();
