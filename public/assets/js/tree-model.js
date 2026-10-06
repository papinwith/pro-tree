// "tree" - the small plant classifier trained in ml/ (MobileNetV3-Small, ONNX), run inside the visitor's
// own browser with ONNX Runtime Web. The photo never leaves the device for this step.
//
//   TreeModel.classify(blob).then(function (r) { r.name, r.p, r.top3 })   // r = null if the model is unavailable
//
// The photo preparation below reproduces ml/03_train_tree.py (PIL thumbnail to fit 320 px, shorter side to 256,
// centre crop 224, ImageNet colour normalisation); tests/tree_browser_check.py compares it with Python.
(function () {
  var ORT_VERSION = '1.22.0';
  var ORT_BASE = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@' + ORT_VERSION + '/dist/';
  var scriptSrc = (document.currentScript && document.currentScript.src) || '';
  var modelBase = scriptSrc.replace(/\/js\/[^\/]*$/, '/models/');

  var loading = null;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (window.ort) return resolve();
      var s = document.createElement('script');
      s.src = src; s.onload = resolve; s.onerror = function () { reject(new Error('cannot load ' + src)); };
      document.head.appendChild(s);
    });
  }

  // Loads the runtime, the labels and the model once; resolves to {session, meta} or rejects.
  function load() {
    if (loading) return loading;
    loading = Promise.all([
      loadScript(ORT_BASE + 'ort.min.js'),
      fetch(modelBase + 'tree.labels.json', { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('labels HTTP ' + r.status);
        return r.json();
      })
    ]).then(function (parts) {
      var meta = parts[1];
      window.ort.env.wasm.wasmPaths = ORT_BASE;
      window.ort.env.wasm.numThreads = 1; // multi-threading needs cross-origin isolation, which this site does not have
      return window.ort.InferenceSession.create(modelBase + 'tree.onnx', { executionProviders: ['wasm'] })
        .then(function (session) { return { session: session, meta: meta }; });
    });
    loading.catch(function () { loading = null; }); // allow a retry later (e.g. after the network comes back)
    return loading;
  }

  // --- photo preparation, a port of what PIL / torchvision do in ml/03_train_tree.py -----------------------
  // A browser canvas shrinks photos with a different filter than PIL, which moved the model's probabilities by up to
  // 0.15 - enough to flip the 90 % decision - so the filters are reproduced here (Pillow's Resample.c).

  function bicubic(x) { // a = -0.5, support 2
    x = Math.abs(x);
    if (x < 1) return ((1.5 * x - 2.5) * x) * x + 1;
    if (x < 2) return (((-0.5 * x + 2.5) * x) - 4) * x + 2;
    return 0;
  }
  function bilinear(x) { x = Math.abs(x); return x < 1 ? 1 - x : 0; }

  function taps(inSize, outSize, kernel, support) {
    var scale = inSize / outSize, filterScale = Math.max(scale, 1), sup = support * filterScale, ss = 1 / filterScale, out = new Array(outSize);
    for (var o = 0; o < outSize; o++) {
      var center = (o + 0.5) * scale;
      var lo = Math.max(0, Math.trunc(center - sup + 0.5)), hi = Math.min(inSize, Math.trunc(center + sup + 0.5));
      var k = [], total = 0, x;
      for (x = lo; x < hi; x++) { var w = kernel((x - center + 0.5) * ss); k.push(w); total += w; }
      if (total !== 0) for (x = 0; x < k.length; x++) k[x] /= total;
      out[o] = { start: lo, k: k };
    }
    return out;
  }

  // img = {w, h, data: Uint8ClampedArray RGBA}; horizontal pass first, then vertical, each rounded to 8 bits like PIL.
  function pilResize(img, dw, dh, kernel, support) {
    var w = img.w, h = img.h, src = img.data, i, x, y, c, t;
    var mid = src;
    if (dw !== w) {
      var tx = taps(w, dw, kernel, support);
      mid = new Uint8ClampedArray(dw * h * 4);
      for (y = 0; y < h; y++) {
        for (x = 0; x < dw; x++) {
          t = tx[x];
          for (c = 0; c < 3; c++) {
            var acc = 0;
            for (i = 0; i < t.k.length; i++) acc += src[(y * w + t.start + i) * 4 + c] * t.k[i];
            mid[(y * dw + x) * 4 + c] = Math.round(acc);
          }
          mid[(y * dw + x) * 4 + 3] = 255;
        }
      }
    }
    var res = mid;
    if (dh !== h) {
      var ty = taps(h, dh, kernel, support);
      res = new Uint8ClampedArray(dw * dh * 4);
      for (y = 0; y < dh; y++) {
        t = ty[y];
        for (x = 0; x < dw; x++) {
          for (c = 0; c < 3; c++) {
            var acc2 = 0;
            for (i = 0; i < t.k.length; i++) acc2 += mid[((t.start + i) * dw + x) * 4 + c] * t.k[i];
            res[(y * dw + x) * 4 + c] = Math.round(acc2);
          }
          res[(y * dw + x) * 4 + 3] = 255;
        }
      }
    }
    return { w: dw, h: dh, data: res };
  }

  function roundHalfEven(v) { var f = Math.floor(v), d = v - f; return d < 0.5 ? f : d > 0.5 ? f + 1 : (f % 2 === 0 ? f : f + 1); }

  function pixelsOf(bitmap) {
    // A 12-megapixel phone photo would make the exact filters slow, so very large photos are first brought down
    // to 640 px with the browser's own (good) downscaler; the exact filters then do the final, model-relevant steps.
    var w = bitmap.width, h = bitmap.height, s = Math.min(1, 640 / Math.max(w, h));
    var cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
    var c = document.createElement('canvas');
    c.width = cw; c.height = ch;
    var ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, cw, ch);
    return { w: cw, h: ch, data: ctx.getImageData(0, 0, cw, ch).data };
  }

  function toTensorData(bitmap, meta) {
    var size = meta.input_size, resize = meta.resize;
    var img = pixelsOf(bitmap);
    // 1) PIL thumbnail((320, 320)), bicubic: fit inside the box, keep the aspect ratio, never enlarge
    if (img.w > 320 || img.h > 320) {
      var aspect = img.w / img.h, nx = 320, ny = 320;
      var pick = function (n, key) { var f = Math.floor(n), cl = Math.ceil(n), a = key(f) <= key(cl) ? f : cl; return Math.max(a, 1); };
      if (320 / 320 >= aspect) nx = pick(320 * aspect, function (n) { return Math.abs(aspect - n / 320); });
      else ny = pick(320 / aspect, function (n) { return n === 0 ? 0 : Math.abs(aspect - 320 / n); });
      img = pilResize(img, nx, ny, bicubic, 2);
    }
    // 2) torchvision Resize(256), bilinear: the shorter side becomes 256
    var w2, h2;
    if (img.w <= img.h) { w2 = resize; h2 = Math.floor(resize * img.h / img.w); } else { h2 = resize; w2 = Math.floor(resize * img.w / img.h); }
    img = pilResize(img, w2, h2, bilinear, 1);
    // 3) CenterCrop(224) - Python's round() goes to the even neighbour on .5
    var left = roundHalfEven((img.w - size) / 2), top = roundHalfEven((img.h - size) / 2);
    var out = new Float32Array(3 * size * size), plane = size * size;
    for (var yy = 0; yy < size; yy++) {
      for (var xx = 0; xx < size; xx++) {
        var base = ((top + yy) * img.w + left + xx) * 4;
        for (var ch = 0; ch < 3; ch++) out[ch * plane + yy * size + xx] = (img.data[base + ch] / 255 - meta.mean[ch]) / meta.std[ch];
      }
    }
    return out;
  }

  function classify(blob) {
    return load().then(function (m) {
      return createImageBitmap(blob).then(function (bitmap) {
        var data = toTensorData(bitmap, m.meta);
        if (bitmap.close) bitmap.close();
        var input = new window.ort.Tensor('float32', data, [1, 3, m.meta.input_size, m.meta.input_size]);
        return m.session.run({ image: input });
      }).then(function (out) {
        var logits = out.logits.data, max = -Infinity, sum = 0, i;
        for (i = 0; i < logits.length; i++) if (logits[i] > max) max = logits[i];
        var p = new Array(logits.length);
        for (i = 0; i < logits.length; i++) { p[i] = Math.exp(logits[i] - max); sum += p[i]; }
        var order = p.map(function (v, k) { return k; }).sort(function (x, y) { return p[y] - p[x]; }).slice(0, 3);
        var top3 = order.map(function (k) { return { name: m.meta.classes[k], p: p[k] / sum }; });
        return { name: top3[0].name, p: top3[0].p, top3: top3, threshold: m.meta.app_threshold || 0.9 };
      });
    });
  }

  window.TreeModel = { classify: classify, load: load, creditsUrl: modelBase + 'credits.csv' };
})();
