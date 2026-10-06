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
//        data-no-match-text="..."    show this link (data-no-match-href) and/or this
//        data-no-match-create-label  button (fires ai-identify:apply same as a match —
//        "..."                       the host page decides what "no match" means, e.g.
//                                     switching to an inline "create new species" form)
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
  var MAX_SIDE = 1024; // plenty for identification; a smaller upload is faster, and a local model reads a smaller photo faster
  var SERVER_LIMIT_SECONDS = 120; // mirrors AI_IDENTIFY_MAX_SECONDS (config/config.php), for the on-screen counter only
  var NETWORK_GIVE_UP_MS = 130000; // safety net if the connection itself hangs; the server enforces the real limit

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
  // is slow on mobile data and near the server's 10 MB limit. Falls back to
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
        canvas.toBlob(function (out) { resolve(out || blob); }, 'image/jpeg', 0.82);
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
      var pct = typeof result.confidence_pct === 'number' ? ' ' + result.confidence_pct + '%' : '';
      box.appendChild(el('p', 'field-hint', 'ความมั่นใจ:' + pct + ' (' + (CONFIDENCE_LABELS[result.confidence] || result.confidence) + ') — เป็นค่าประมาณจาก AI ไม่ใช่การรับประกัน'));
      if (result.fallback_note) box.appendChild(el('p', 'field-hint', '⚠ ' + result.fallback_note));
      var second = result.second_opinion;
      if (second) {
        var secondName = second.name_th || second.name_scientific || '-';
        if (second.name_th && second.name_scientific) secondName += ' (' + second.name_scientific + ')';
        var src = second.source === 'plantnet' ? 'Pl@ntNet' : 'Gemini';
        box.appendChild(el('p', 'field-hint', second.agrees
          ? '✓ ถามความเห็นที่สองจาก ' + src + ' แล้ว: ตรงกัน (' + second.confidence_pct + '%)'
          : '⚠ ถามความเห็นที่สองจาก ' + src + ' แล้ว: ต่างกัน — ' + src + ' เห็นว่าเป็น ' + secondName + ' (' + second.confidence_pct + '%)'));
      } else if (result.second_opinion_status === 'no_key') {
        box.appendChild(el('p', 'field-hint', 'ความมั่นใจต่ำ แต่ยังไม่ได้ตั้งค่า Pl@ntNet หรือ Gemini สำหรับขอความเห็นที่สอง'));
      } else if (result.second_opinion_status === 'no_time' || result.second_opinion_status === 'failed') {
        box.appendChild(el('p', 'field-hint', 'ความมั่นใจต่ำ และขอความเห็นที่สองไม่สำเร็จ'));
      }
      if (result.needs_review) {
        box.appendChild(el('p', 'field-hint field-hint-error', 'ความมั่นใจยังต่ำ ควรให้ผู้ที่รู้จักต้นไม้ตรวจสอบก่อนบันทึก หรือถ่ายรูปใหม่ให้เห็นดอก ใบ หรือผลชัดๆ'));
      }
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
      if (result.answered_by === 'tree' && window.TreeModel) {
        var credit = el('p', 'field-hint', 'โมเดลนี้เรียนจากภาพถ่ายลิขสิทธิ์เปิด (CC0/CC-BY) จาก GBIF/iNaturalist — ');
        var creditLink = el('a', '', 'รายชื่อผู้ถ่ายภาพ');
        creditLink.href = window.TreeModel.creditsUrl; creditLink.target = '_blank'; creditLink.rel = 'noopener';
        credit.appendChild(creditLink); box.appendChild(credit);
      }
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
        if (wrapper.dataset.noMatchCreateLabel) {
          var createBtn = el('button', 'btn btn-sm', wrapper.dataset.noMatchCreateLabel);
          createBtn.type = 'button';
          createBtn.addEventListener('click', function () {
            wrapper.dispatchEvent(new CustomEvent('ai-identify:apply', { bubbles: true, detail: { result: result } }));
          });
          box.appendChild(createBtn);
        }
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

    // The photo last sent to the server AI, so that "use this result" can offer it to tree as a new example.
    var lastUploaded = null;
    function confirmAsExample(result) {
      if (!lastUploaded || !result || result.answered_by === 'tree' || !result.name_scientific) return;
      var form = wrapper.closest('form'), tokenField = form && form.querySelector('input[name="csrf_token"]');
      if (!tokenField) return;
      var data = new FormData();
      data.append('csrf_token', tokenField.value);
      data.append('name_scientific', result.name_scientific);
      data.append('image', lastUploaded, 'photo.jpg');
      fetch(wrapper.dataset.endpoint.replace(/identify_tree\.php$/, 'training_sample_add.php'), { method: 'POST', body: data, credentials: 'same-origin' })
        .catch(function () {}); // best effort: learning must never get in the admin's way
    }
    wrapper.addEventListener('ai-identify:apply', function (e) { confirmAsExample(e.detail && e.detail.result); });

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
      // Live counter so it's clear something is happening and how long it can take.
      var startedAt = Date.now();
      function tick() {
        var s = Math.floor((Date.now() - startedAt) / 1000);
        showMessage('กำลังให้ AI ดูรูป… ' + s + ' วินาที (ไม่เกิน ' + SERVER_LIMIT_SECONDS + ' วินาที)', false);
      }
      tick();
      var timer = setInterval(tick, 500);
      var abort = new AbortController();
      var giveUp = setTimeout(function () { abort.abort(); }, NETWORK_GIVE_UP_MS);

      // The small "tree" model runs first, inside this browser: when it is at least 90% sure, its answer is used
      // as it is (no photo upload, no server AI). Anything else - not sure, model not loadable - goes to the server
      // AI as before. A form that asks for the full write-up (data-detail="full") only accepts the local answer when
      // it names a species already in the system, because "tree" only knows names, not care instructions.
      function tryTree(original) {
        if (!window.TreeModel) return Promise.resolve(null);
        return window.TreeModel.classify(original).then(function (r) {
          if (!r || r.p < r.threshold) return null;
          var data = new FormData();
          data.append('csrf_token', tokenField.value);
          data.append('name_scientific', r.name);
          return fetch(wrapper.dataset.endpoint.replace(/identify_tree\.php$/, 'identify_match.php'), { method: 'POST', body: data, credentials: 'same-origin', signal: abort.signal })
            .then(function (res) { return res.json(); })
            .then(function (m) {
              if (!m.ok) return null;
              if (wrapper.dataset.detail === 'full' && !m.matched_species_id) return null;
              return {
                is_plant: true, name_th: m.matched_species_name || '', name_common: '', name_scientific: r.name,
                confidence: 'high', confidence_pct: Math.round(r.p * 100), description_th: '',
                notes_th: 'ระบุโดยโมเดล tree ที่รันในเครื่องของคุณ (ไม่ได้ส่งรูปออกไปยังเซิร์ฟเวอร์ AI)',
                alternatives: r.top3.slice(1).map(function (c) { return { name_th: '', name_scientific: c.name }; }),
                matched_species_id: m.matched_species_id, matched_species_name: m.matched_species_name,
                second_opinion: null, answered_by: 'tree', needs_review: false
              };
            });
        }).catch(function () { return null; }); // any trouble with the local model: use the server AI instead
      }

      sourceBlob()
        .then(function (original) {
          return tryTree(original).then(function (local) {
            if (local) return { ok: true, result: local };
            return downscale(original)
              .then(function (blob) {
                var data = new FormData();
                data.append('csrf_token', tokenField.value);
                data.append('image', blob, 'photo.jpg');
                lastUploaded = blob;
                if (wrapper.dataset.detail === 'full') data.append('detail', 'full');
                return fetch(wrapper.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin', signal: abort.signal });
              })
              .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'ได้รับการตอบกลับที่อ่านไม่ได้ (HTTP ' + response.status + ')' }; });
              });
          });
        })
        .then(function (payload) {
          if (payload.ok) renderResult(payload.result);
          else showMessage(payload.error || 'ระบุชนิดไม่สำเร็จ', true);
        })
        .catch(function (err) {
          showMessage(err && err.name === 'AbortError' ? 'เชื่อมต่อเซิร์ฟเวอร์ช้าเกินไป กรุณาลองใหม่อีกครั้ง' : ((err && err.message) || 'ระบุชนิดไม่สำเร็จ กรุณาลองใหม่'), true);
        })
        .then(function () {
          clearInterval(timer);
          clearTimeout(giveUp);
          refreshButton();
        });
    });
  });
})();
