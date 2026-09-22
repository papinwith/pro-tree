// "Select all" checkbox + per-row checkboxes for a bulk-delete form that
// lives OUTSIDE the <table> (row checkboxes point at it via the HTML
// `form="..."` attribute, so nothing here needs actual form nesting).
// Markup contract: <input data-select-all="GROUP"> in the header, each row
// checkbox is <input class="row-check" data-group="GROUP">, and the submit
// button is <button data-bulk-submit="GROUP">.
(function () {
  document.querySelectorAll('[data-select-all]').forEach(function (master) {
    var groupId = master.dataset.selectAll;
    var boxes = document.querySelectorAll('input.row-check[data-group="' + groupId + '"]');
    var submitBtns = document.querySelectorAll('[data-bulk-submit="' + groupId + '"]');

    function updateState() {
      var anyChecked = Array.prototype.some.call(boxes, function (b) { return b.checked; });
      submitBtns.forEach(function (btn) { btn.disabled = !anyChecked; });
      master.checked = boxes.length > 0 && Array.prototype.every.call(boxes, function (b) { return b.checked; });
    }

    master.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = master.checked; });
      updateState();
    });
    boxes.forEach(function (b) {
      b.addEventListener('change', updateState);
    });
    updateState();
  });
})();
