// Replaces native window.confirm() popups with a styled in-page message box.
// Usage: add data-confirm="ข้อความยืนยัน" to a <form> instead of
// onsubmit="return confirm('...')". Requires the #confirmModal markup
// (admin/_confirm_modal.php) to be present on the page.
(function () {
  var modal = document.getElementById('confirmModal');
  if (!modal) return;

  var messageEl = document.getElementById('confirmModalMessage');
  var okBtn = document.getElementById('confirmModalOk');
  var cancelBtn = document.getElementById('confirmModalCancel');
  var pendingForm = null;

  function closeModal() {
    modal.hidden = true;
    pendingForm = null;
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === 'true') {
        return; // already confirmed via the modal — let the real submit through
      }
      e.preventDefault();
      pendingForm = form;
      messageEl.textContent = form.dataset.confirm;
      modal.hidden = false;
      okBtn.focus();
    });
  });

  okBtn.addEventListener('click', function () {
    var form = pendingForm;
    modal.hidden = true;
    pendingForm = null;
    if (form) {
      form.dataset.confirmed = 'true';
      form.submit();
    }
  });

  cancelBtn.addEventListener('click', closeModal);

  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
