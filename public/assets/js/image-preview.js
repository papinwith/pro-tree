// Shows a client-side preview of a file <input> before the form is
// submitted. Markup contract: <input type="file" data-preview-target="ID">
// — on change, an <img id="ID" class="preview-thumb"> is created (right
// before the input) or updated if one already exists (e.g. a field that
// already showed the current saved image).
(function () {
  var lastUrls = new WeakMap();

  document.querySelectorAll('input[type="file"][data-preview-target]').forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;

      var targetId = input.dataset.previewTarget;
      var img = document.getElementById(targetId);
      if (!img) {
        img = document.createElement('img');
        img.id = targetId;
        img.className = 'preview-thumb';
        input.insertAdjacentElement('beforebegin', img);
      }

      var oldUrl = lastUrls.get(input);
      if (oldUrl) {
        URL.revokeObjectURL(oldUrl);
      }
      var url = URL.createObjectURL(file);
      lastUrls.set(input, url);
      img.src = url;
    });
  });
})();
