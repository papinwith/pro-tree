// "Ask AI what tree this is" button next to a photo field. Sends the chosen
// (or already-saved) photo to admin/identify_tree.php, shows the suggestion
// with its confidence, and — when the admin clicks "use this" — fires an
// `ai-identify:apply` event on the wrapper so each form decides for itself
// what applying means (species_form fills the name fields; tree_form picks
// the matching species in its dropdown).
//
// Markup contract:
//   <div data-ai-identify-for="INPUT_ID" data-endpoint="identify_tree.php"
//        data-apply-label="..."      label of the "use this" button
//        data-require-match="1"      optional: only offer "use this" when the AI's
//        data-no-match-href="..."    answer matches an existing species; otherwise
//        data-no-match-text="..."    show this link instead
//        data-ai-unavailable="1"    optional: keep the button disabled (e.g. no
//                                    API key configured)
//        data-detail="full">         optional: ask for the full species write-up
//                                    (care, characteristics, category, ...) too
//     <button type="button" data-ai-action="identify">...</button>
//     <div data-ai-output></div>
//   </div>
// The wrapper must sit inside the <form> carrying the csrf_token field.
// The photo comes from the file input if one is chosen, else from the image
// the input's data-preview-target points at (the already-saved photo).
(function () {
  var MAX_SIDE = 1280; // plenty for identification; keeps the upload small

  // Long-form fields the AI drafts when the form asks for the full write-up
  // (data-detail="full"), shown in this order.
  var DETAIL_LABELS = [
    ['description_th', 'คำอธิบาย'],
    ['care_instructions', 'วิธีดูแล'],
    ['characteristics', 'ลักษณะ'],
    ['properties', 'คุณสมบัติ'],
    ['benefits', 'ประโยชน์'],
    ['cautions', 'ข้อควรระวัง'],
    ['part_uses', 'การใช้ประโยชน์แต่ละส่วน']
  ];

  var CONFIDENCE_LABELS = { high: 'มั่นใจสูง', medium: 'มั่นใจปานกลาง', low: 'เดาจากภาพ (มั่นใจต่ำ)' };

  // Shrinks the photo before upload: a fresh phone photo is 3-8 MB, which
  // is slow on mobile data and near the server's 5 MB limit. Falls back to
  // the original file if the browser can't decode it (e.g. HEIC).
  function downscale(blob) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        var ctx = canvas.getContext('2d');
        // JPEG has no alpha: without this, transparent PNG/WebP/GIF areas turn black.
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (out) { resolve(out || blob); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        resolve(blob);
      };
      img.src = url;
    });
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  document.querySelectorAll('[data-ai-identify-for]').forEach(function (wrapper) {
    var input = document.getElementById(wrapper.dataset.aiIdentifyFor);
    var button = wrapper.querySelector('[data-ai-action="identify"]');
    var output = wrapper.querySelector('[data-ai-output]');
    if (!input || !button || !output) return;

    // Looked up lazily: image-preview.js creates this <img> only after a file is picked.
    function previewImg() {
      return input.dataset.previewTarget ? document.getElementById(input.dataset.previewTarget) : null;
    }

    function hasPhoto() {
      return (input.files && input.files.length > 0) || !!(previewImg() && previewImg().getAttribute('src'));
    }
    function refreshButton() {
      button.disabled = !hasPhoto() || wrapper.dataset.aiUnavailable === '1';
    }
    // The preview <img> is created/updated by image-preview.js after a
    // file is picked, so re-check after the file input's own change event.
    input.addEventListener('change', function () {
      output.textContent = '';
      setTimeout(refreshButton, 0);
    });
    refreshButton();

    function showMessage(text, isError) {
      output.textContent = '';
      output.appendChild(el('p', isError ? 'field-hint field-hint-error' : 'field-hint', text));
    }

    function renderResult(result) {
      output.textContent = '';
      var box = el('div', 'flash' + (result.is_plant ? '' : ' warning'));

      if (!result.is_plant) {
        box.appendChild(el('strong', '', 'AI ระบุชนิดจากรูปนี้ไม่ได้'));
        if (result.notes_th) box.appendChild(el('p', 'field-hint', result.notes_th));
        box.appendChild(el('p', 'field-hint', 'ลองถ่ายให้เห็นใบ ดอก หรือผลชัดๆ แล้วลองอีกครั้ง'));
        output.appendChild(box);
        return;
      }

      var title = result.name_th || result.name_scientific;
      box.appendChild(el('strong', '', 'AI คาดว่าเป็น: ' + title));
      var names = [];
      if (result.name_common) names.push(result.name_common);
      if (result.name_scientific) names.push(result.name_scientific);
      if (names.length) box.appendChild(el('p', 'field-hint', names.join(' · ')));
      box.appendChild(el('p', 'field-hint', 'ความมั่นใจ: ' + (CONFIDENCE_LABELS[result.confidence] || result.confidence)));
      if (result.category_name || (result.subtype_names && result.subtype_names.length)) {
        var kinds = [];
        if (result.category_name) kinds.push('ประเภทพืช: ' + result.category_name);
        if (result.subtype_names && result.subtype_names.length) kinds.push('ชนิด: ' + result.subtype_names.join(', '));
        box.appendChild(el('p', '', kinds.join(' · ')));
      }
      // Every drafted field, in full — the admin reviews it all here before
      // deciding to copy it into the form.
      DETAIL_LABELS.forEach(function (pair) {
        var text = result[pair[0]];
        if (!text) return;
        var section = el('div', 'ai-identify-detail');
        section.appendChild(el('strong', '', pair[1]));
        var body = el('p', '', text);
        body.style.whiteSpace = 'pre-line';
        body.style.margin = '2px 0 8px';
        section.appendChild(body);
        box.appendChild(section);
      });
      if (result.notes_th) box.appendChild(el('p', 'field-hint', result.notes_th));
      if (result.alternatives && result.alternatives.length) {
        var alts = result.alternatives.map(function (a) {
          return a.name_th && a.name_scientific ? a.name_th + ' (' + a.name_scientific + ')' : (a.name_th || a.name_scientific);
        });
        box.appendChild(el('p', 'field-hint', 'ที่เป็นไปได้อื่นๆ: ' + alts.join(', ')));
      }
      if (result.matched_species_name) box.appendChild(el('p', 'field-hint', 'มีชนิดนี้ในระบบแล้ว: ' + result.matched_species_name));
      box.appendChild(el('p', 'field-hint', 'AI อาจระบุผิดได้ — ตรวจสอบก่อนบันทึกทุกครั้ง'));

      var requireMatch = wrapper.dataset.requireMatch === '1';
      if (requireMatch && !result.matched_species_id) {
        var note = el('p', 'field-hint', 'ชนิดนี้ยังไม่มีในระบบ ');
        if (wrapper.dataset.noMatchHref) {
          var link = el('a', '', wrapper.dataset.noMatchText || 'เพิ่มเป็นชนิดพันธุ์ใหม่');
          link.href = wrapper.dataset.noMatchHref;
          note.appendChild(link);
        }
        box.appendChild(note);
      } else {
        var apply = el('button', 'btn btn-sm', wrapper.dataset.applyLabel || 'ใช้ผลนี้');
        apply.type = 'button';
        apply.addEventListener('click', function () {
          wrapper.dispatchEvent(new CustomEvent('ai-identify:apply', { bubbles: true, detail: { result: result } }));
        });
        box.appendChild(apply);
      }
      output.appendChild(box);
    }

    function sourceBlob() {
      if (input.files && input.files.length > 0) return Promise.resolve(input.files[0]);
      return fetch(previewImg().getAttribute('src'), { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('โหลดรูปที่บันทึกไว้ไม่ได้');
        return r.blob();
      });
    }

    button.addEventListener('click', function () {
      var form = wrapper.closest('form');
      var tokenField = form && form.querySelector('input[name="csrf_token"]');
      if (!tokenField) { showMessage('ไม่พบรหัสความปลอดภัยของฟอร์ม กรุณารีเฟรชหน้า', true); return; }

      button.disabled = true;
      showMessage('กำลังให้ AI ดูรูป… (ใช้เวลาประมาณ 5-20 วินาที)', false);

      sourceBlob()
        .then(downscale)
        .then(function (blob) {
          var data = new FormData();
          data.append('csrf_token', tokenField.value);
          data.append('image', blob, 'photo.jpg');
          if (wrapper.dataset.detail === 'full') data.append('detail', 'full');
          return fetch(wrapper.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin' });
        })
        .then(function (response) {
          return response.json().catch(function () { return { ok: false, error: 'ได้รับการตอบกลับที่อ่านไม่ได้ (HTTP ' + response.status + ')' }; });
        })
        .then(function (payload) {
          if (payload.ok) renderResult(payload.result);
          else showMessage(payload.error || 'ระบุชนิดไม่สำเร็จ', true);
        })
        .catch(function (err) {
          showMessage((err && err.message) || 'ระบุชนิดไม่สำเร็จ กรุณาลองใหม่', true);
        })
        .then(refreshButton);
    });
  });
})();
