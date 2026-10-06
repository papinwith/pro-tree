// Admin side of the 360-degree spin (tree form). The admin films a slow walk once around the tree (or picks photos taken
// around it); this script picks evenly spaced frames out of the video IN THE BROWSER, shrinks them, lets the admin preview
// the turn, and uploads them in small batches to admin/spin_upload.php. The new spin only goes live when it is complete.
//
//   <section data-spin-capture data-tree-id="12" data-endpoint="spin_upload.php" data-csrf="..." data-has-spin="1">
//     <input type="file" data-spin-video accept="video/*"> <input type="file" data-spin-photos accept="image/*" multiple>
//     <input type="number" data-spin-count value="36"> <div data-spin-preview></div>
//     <button data-spin-save>...</button> <button data-spin-delete>...</button> <div data-spin-status></div>
(function () {
  var MAX_SIDE = 720, QUALITY = 0.72, BATCH = 6;

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function canvasToBlob(canvas) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('cannot encode frame')); }, 'image/jpeg', QUALITY);
    });
  }

  function drawScaled(source, w, h) {
    var s = Math.min(1, MAX_SIDE / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w * s));
    c.height = Math.max(1, Math.round(h * s));
    var ctx = c.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, 0, 0, c.width, c.height);
    return c;
  }

  // n evenly spaced frames across the whole video. The last one is NOT at the very end, so a full circle does not repeat its
  // first picture and the loop has no stutter.
  function framesFromVideo(file, n, onProgress) {
    return new Promise(function (resolve, reject) {
      var video = document.createElement('video');
      var url = URL.createObjectURL(file);
      video.muted = true; video.playsInline = true; video.preload = 'auto'; video.src = url;
      var frames = [];
      function done(err) { URL.revokeObjectURL(url); err ? reject(err) : resolve(frames); }
      video.onerror = function () { done(new Error('video cannot be read by this browser')); };
      video.onloadedmetadata = async function () {
        try {
          // some recorded files report no duration until the end has been reached once
          if (!isFinite(video.duration)) {
            video.currentTime = 1e9;
            await new Promise(function (r) { video.onseeked = r; setTimeout(r, 3000); });
          }
          var duration = video.duration;
          if (!isFinite(duration) || duration <= 0.5) throw new Error('video is too short');
          for (var k = 0; k < n; k++) {
            var t = (k + 0.5) / n * duration;
            await new Promise(function (r) { video.onseeked = r; video.currentTime = t; setTimeout(r, 4000); });
            if (!video.videoWidth) throw new Error('no picture in the video');
            frames.push(await canvasToBlob(drawScaled(video, video.videoWidth, video.videoHeight)));
            if (onProgress) onProgress(k + 1, n);
          }
          done();
        } catch (e) { done(e); }
      };
    });
  }

  async function framesFromPhotos(files, onProgress) {
    var list = Array.prototype.slice.call(files).sort(function (a, b) { return a.name.localeCompare(b.name, undefined, { numeric: true }); });
    var frames = [];
    for (var i = 0; i < list.length; i++) {
      var bmp = await createImageBitmap(list[i]);
      frames.push(await canvasToBlob(drawScaled(bmp, bmp.width, bmp.height)));
      if (bmp.close) bmp.close();
      if (onProgress) onProgress(i + 1, list.length);
    }
    return frames;
  }

  function init(root) {
    var $ = function (sel) { return root.querySelector(sel); };
    var video = $('[data-spin-video]'), photos = $('[data-spin-photos]'), countInput = $('[data-spin-count]');
    var preview = $('[data-spin-preview]'), saveBtn = $('[data-spin-save]'), delBtn = $('[data-spin-delete]'), status = $('[data-spin-status]'), bar = $('[data-spin-bar]');
    var treeId = root.dataset.treeId, endpoint = root.dataset.endpoint, csrf = root.dataset.csrf;
    var frames = null, previewUrls = [], busy = false;

    function say(text, isError) { status.textContent = text; status.className = 'field-hint' + (isError ? ' field-hint-error' : ''); }
    function progress(frac) { if (bar) bar.style.width = Math.round(frac * 100) + '%'; }
    function lock(on) { busy = on; [video, photos, countInput, saveBtn, delBtn].forEach(function (el) { if (el) el.disabled = on; }); }

    function showPreview() {
      previewUrls.forEach(URL.revokeObjectURL);
      previewUrls = frames.map(function (b) { return URL.createObjectURL(b); });
      preview.textContent = '';
      var holder = document.createElement('div');
      holder.dataset.label = '360°';
      preview.appendChild(holder);
      new window.SpinViewer(holder, { urls: previewUrls });
      saveBtn.hidden = false;
      var kb = Math.round(frames.reduce(function (s, b) { return s + b.size; }, 0) / 1024);
      say(frames.length + ' เฟรม (ประมาณ ' + kb + ' KB) — ดูตัวอย่างการหมุนด้านบน ถ้าพอใจกด "บันทึกภาพหมุน"', false);
    }

    async function prepare(getFrames) {
      if (busy) return;
      lock(true); saveBtn.hidden = true; progress(0); frames = null;
      try {
        say('กำลังเลือกเฟรมจากไฟล์… (อยู่ในเครื่องของคุณ ยังไม่ได้อัปโหลด)', false);
        frames = await getFrames(function (done, total) { progress(done / total * 0.5); say('กำลังเลือกเฟรม ' + done + '/' + total + '…', false); });
        if (frames.length < 8) throw new Error('ต้องมีอย่างน้อย 8 เฟรม (ตอนนี้ ' + frames.length + ')');
        if (frames.length > 72) frames = frames.slice(0, 72);
        showPreview(); progress(0);
      } catch (e) {
        frames = null;
        say('เตรียมภาพหมุนไม่สำเร็จ: ' + e.message + ' — ลองไฟล์อื่น หรือเลือกเป็นรูปหลายรูปแทน', true);
      }
      lock(false);
    }

    video.addEventListener('change', function () {
      if (!video.files.length) return;
      var n = Math.max(12, Math.min(72, parseInt(countInput.value, 10) || 36));
      prepare(function (cb) { return framesFromVideo(video.files[0], n, cb); });
    });
    photos.addEventListener('change', function () {
      if (photos.files.length) prepare(function (cb) { return framesFromPhotos(photos.files, cb); });
    });

    async function post(fields, blobs) {
      var body = new FormData();
      body.append('csrf_token', csrf);
      body.append('tree_id', treeId);
      Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
      (blobs || []).forEach(function (b) { body.append('idx[]', b.i); body.append('frames[]', b.blob, 'f' + b.i + '.jpg'); });
      var res = await fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' });
      var json = await res.json().catch(function () { return { ok: false, error: 'ได้รับการตอบกลับที่อ่านไม่ได้ (HTTP ' + res.status + ')' }; });
      if (!json.ok) throw new Error(json.error || 'ไม่สำเร็จ');
      return json;
    }

    saveBtn.addEventListener('click', async function () {
      if (busy || !frames) return;
      lock(true);
      try {
        say('กำลังอัปโหลด…', false);
        var token = (await post({ action: 'start' })).token;
        for (var i = 0; i < frames.length; i += BATCH) {
          var batch = frames.slice(i, i + BATCH).map(function (blob, k) { return { i: i + k, blob: blob }; });
          await post({ action: 'frames', token: token }, batch);
          progress(Math.min(1, (i + batch.length) / frames.length) * 0.95);
          say('กำลังอัปโหลด ' + Math.min(i + BATCH, frames.length) + '/' + frames.length + '…', false);
        }
        await post({ action: 'commit', token: token });
        progress(1);
        say('บันทึกภาพหมุนแล้ว — กำลังโหลดหน้าใหม่…', false);
        await sleep(600);
        location.reload();
      } catch (e) {
        say('อัปโหลดไม่สำเร็จ: ' + e.message + ' (ภาพหมุนเดิมยังใช้งานอยู่ ไม่ถูกแตะต้อง) — กดบันทึกอีกครั้งได้', true);
        lock(false);
      }
    });

    if (delBtn) delBtn.addEventListener('click', async function () {
      if (busy || !window.confirm(delBtn.dataset.confirm || 'ลบภาพหมุนของต้นไม้ต้นนี้?')) return;
      lock(true);
      try { await post({ action: 'delete' }); location.reload(); } catch (e) { say('ลบไม่สำเร็จ: ' + e.message, true); lock(false); }
    });
  }

  document.querySelectorAll('[data-spin-capture]').forEach(init);
  window.SpinCapture = { framesFromVideo: framesFromVideo, framesFromPhotos: framesFromPhotos };
})();
