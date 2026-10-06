// 360-degree "spin" viewer: photos taken while walking once around a tree, shown as a picture that keeps turning (like a GIF)
// and that the visitor can drag - or use the arrow keys - to look around the tree.
//
//   <div data-spin data-src="spin_frame.php?tree=12&t=TOKEN&i=" data-count="36" data-poster="photo.jpg"
//        data-label="..." data-hint="..." data-play-label="..." data-pause-label="..." data-loading-label="Loading {n}/{total}"></div>
//
// Behaviour: it turns by itself, slowly and without end; touching or dragging it takes over (inertia, then it slows to a stop);
// after a few idle seconds it starts turning again - unless the visitor pressed pause. Frames are blended into each other
// so the turn looks smooth even with few photos. It does nothing while off-screen or in a background tab, and honours the
// device's "reduce motion" setting (no automatic turning then; dragging still works). If the frames cannot be loaded, the
// static poster photo stays. Also usable for previews: new SpinViewer(element, { urls: [...] }).
(function () {
  var IDLE_RESUME_MS = 2500;       // after the visitor lets go, turn again after this long
  var TURN_SECONDS = 12;           // one full automatic turn
  var PARALLEL_LOADS = 6;

  function SpinViewer(root, options) {
    options = options || {};
    var d = root.dataset;
    this.root = root;
    this.count = options.urls ? options.urls.length : parseInt(d.count, 10) || 0;
    this.url = options.urls ? function (i) { return options.urls[i]; } : function (i) { return d.src + i; };
    this.labels = {
      label: d.label || '360°', hint: d.hint || '', play: d.playLabel || 'Play', pause: d.pauseLabel || 'Pause',
      loading: d.loadingLabel || '{n}/{total}'
    };
    this.images = [];
    this.loaded = 0;
    this.angle = 0;                // in frames, may be fractional; wraps around count
    this.velocity = 0;             // frames per second while coasting after a drag
    this.autoplay = true;
    this.userPaused = false;
    this.dragging = false;
    this.visible = true;
    this.lastTick = 0;
    this.idleTimer = null;
    this.raf = 0;
    this.reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if (this.reduceMotion) this.userPaused = true;
    if (this.count >= 2) this.build();
  }

  SpinViewer.prototype.build = function () {
    var self = this, root = this.root;
    root.classList.add('spin');
    root.setAttribute('role', 'group');
    root.setAttribute('aria-label', this.labels.label);

    this.canvas = document.createElement('canvas');
    this.canvas.className = 'spin-canvas';
    this.canvas.tabIndex = 0;
    this.canvas.setAttribute('role', 'img');
    this.canvas.setAttribute('aria-label', this.labels.label + (this.labels.hint ? ' — ' + this.labels.hint : ''));
    this.canvas.style.touchAction = 'pan-y';   // vertical swipes still scroll the page, horizontal ones turn the tree
    this.ctx = this.canvas.getContext('2d');

    this.status = document.createElement('div');
    this.status.className = 'spin-status';
    this.status.setAttribute('role', 'status');

    this.button = document.createElement('button');
    this.button.type = 'button';
    this.button.className = 'spin-toggle';
    this.button.hidden = true;
    this.button.addEventListener('click', function () { self.setUserPaused(!self.userPaused); });

    this.hint = document.createElement('div');
    this.hint.className = 'spin-hint';
    this.hint.textContent = this.labels.hint;
    this.hint.hidden = true;

    var poster = root.dataset.poster;
    if (poster) {
      this.posterImg = document.createElement('img');
      this.posterImg.className = 'spin-poster';
      this.posterImg.src = poster;
      this.posterImg.alt = '';
      root.appendChild(this.posterImg);
    }
    root.appendChild(this.canvas);
    root.appendChild(this.status);
    root.appendChild(this.button);
    root.appendChild(this.hint);
    this.canvas.style.visibility = 'hidden';

    this.bindInput();
    this.updateButton();
    this.load();

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        self.visible = entries[entries.length - 1].isIntersecting;
        self.visible ? self.start() : self.stop();
      }, { threshold: 0.05 }).observe(root);
    }
    document.addEventListener('visibilitychange', function () { document.hidden ? self.stop() : self.start(); });
  };

  // ---- loading: all frames, a few at a time; the first frame shows as soon as it arrives -------------------------------
  SpinViewer.prototype.load = function () {
    var self = this, next = 0, failed = false;
    function showProgress() {
      self.status.textContent = self.loaded < self.count ? self.labels.loading.replace('{n}', self.loaded).replace('{total}', self.count) : '';
    }
    function fail() {
      if (failed) return;
      failed = true;
      self.status.textContent = '';
      self.root.classList.add('spin-failed');
      self.canvas.hidden = true;
      if (self.posterImg) self.posterImg.style.display = '';   // the still photo comes back (it was hidden when frame 1 arrived)
      self.button.hidden = true;
      self.hint.hidden = true;
      self.stop();
    }
    function pump() {
      if (failed || next >= self.count) return;
      var i = next++, img = new Image();
      img.decoding = 'async';
      img.onload = function () {
        if (failed) return;
        self.images[i] = img;
        self.loaded++;
        if (i === 0 || (!self.ready && self.images[0])) self.firstFrame();
        showProgress();
        if (self.loaded === self.count) self.allLoaded();
        pump();
      };
      img.onerror = fail;
      img.src = self.url(i);
    }
    showProgress();
    for (var k = 0; k < PARALLEL_LOADS; k++) pump();
  };

  SpinViewer.prototype.firstFrame = function () {
    if (this.ready) return;
    var img = this.images[0];
    if (!img) return;
    this.ready = true;
    this.canvas.style.visibility = 'visible';
    this.canvas.style.aspectRatio = img.naturalWidth + ' / ' + img.naturalHeight;
    this.fit();
    this.draw();
    if (this.posterImg) this.posterImg.style.display = 'none';
    window.addEventListener('resize', this.onResize = this.fit.bind(this));
  };

  SpinViewer.prototype.allLoaded = function () {
    this.button.hidden = false;
    this.hint.hidden = !this.labels.hint;
    this.status.textContent = '';
    this.updateButton();
    if (this.labels.hint) {
      var self = this;
      setTimeout(function () { self.hint.hidden = true; }, 6000);
    }
    this.start();
  };

  SpinViewer.prototype.fit = function () {
    var img = this.images[0];
    if (!img) return;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var cssWidth = this.canvas.clientWidth || this.root.clientWidth || img.naturalWidth;
    var w = Math.min(img.naturalWidth, Math.round(cssWidth * dpr));
    this.canvas.width = Math.max(1, w);
    this.canvas.height = Math.max(1, Math.round(w * img.naturalHeight / img.naturalWidth));
    this.draw();
  };

  // ---- drawing: the two frames around the current angle, blended ------------------------------------------------------
  SpinViewer.prototype.draw = function () {
    if (!this.ready) return;
    var n = this.count, a = ((this.angle % n) + n) % n, i0 = Math.floor(a) % n, i1 = (i0 + 1) % n, f = a - Math.floor(a);
    var c = this.ctx, w = this.canvas.width, h = this.canvas.height;
    var A = this.images[i0], B = this.images[i1];
    if (!A) A = this.images[0];
    c.globalAlpha = 1;
    c.drawImage(A, 0, 0, w, h);
    if (B && f > 0.01) {                        // between two photos: fade the next one in
      c.globalAlpha = f;
      c.drawImage(B, 0, 0, w, h);
      c.globalAlpha = 1;
    }
    this.currentFrame = Math.round(a) % n;
  };

  // ---- motion --------------------------------------------------------------------------------------------------------
  SpinViewer.prototype.start = function () {
    if (this.raf || !this.ready || !this.visible || document.hidden) return;
    this.lastTick = 0;
    var self = this;
    this.raf = requestAnimationFrame(function tick(t) {
      self.raf = 0;
      var dt = self.lastTick ? Math.min((t - self.lastTick) / 1000, 0.1) : 0;
      self.lastTick = t;
      self.step(dt);
      if (self.needsFrames()) self.raf = requestAnimationFrame(tick);
    });
  };

  SpinViewer.prototype.needsFrames = function () {
    return this.ready && this.visible && !document.hidden && (this.autoplay && !this.userPaused && !this.dragging || Math.abs(this.velocity) > 0.05);
  };

  SpinViewer.prototype.step = function (dt) {
    if (this.dragging) return;
    if (Math.abs(this.velocity) > 0.05) {
      this.angle += this.velocity * dt;
      this.velocity *= Math.pow(0.04, dt);       // coast to a stop
      if (Math.abs(this.velocity) <= 0.05) this.velocity = 0;
    } else if (this.autoplay && !this.userPaused && this.loaded === this.count) {
      this.angle += this.count / TURN_SECONDS * dt;
    }
    this.draw();
  };

  SpinViewer.prototype.stop = function () {
    if (this.raf) cancelAnimationFrame(this.raf);
    this.raf = 0;
  };

  SpinViewer.prototype.setUserPaused = function (paused) {
    this.userPaused = paused;
    clearTimeout(this.idleTimer);
    this.updateButton();
    paused ? this.stop() : this.start();
  };

  SpinViewer.prototype.updateButton = function () {
    if (!this.button) return;
    this.button.textContent = this.userPaused ? '▶' : '⏸';
    this.button.setAttribute('aria-label', this.userPaused ? this.labels.play : this.labels.pause);
    this.button.setAttribute('aria-pressed', this.userPaused ? 'true' : 'false');
  };

  SpinViewer.prototype.scheduleResume = function () {
    var self = this;
    clearTimeout(this.idleTimer);
    if (this.userPaused) return;
    this.idleTimer = setTimeout(function () { self.autoplay = true; self.start(); }, IDLE_RESUME_MS);
  };

  // ---- input: drag / swipe, arrow keys ------------------------------------------------------------------------------
  SpinViewer.prototype.bindInput = function () {
    var self = this, c = this.canvas, lastX = 0, lastT = 0, id = null;
    c.addEventListener('pointerdown', function (e) {
      if (!self.ready || (e.pointerType === 'mouse' && e.button !== 0)) return;
      id = e.pointerId;
      try { c.setPointerCapture(id); } catch (err) { /* not fatal */ }
      self.dragging = true;
      self.autoplay = false;
      self.velocity = 0;
      lastX = e.clientX; lastT = e.timeStamp;
      clearTimeout(self.idleTimer);
      self.hint.hidden = true;
    });
    c.addEventListener('pointermove', function (e) {
      if (!self.dragging || e.pointerId !== id) return;
      var dx = e.clientX - lastX, dt = Math.max(e.timeStamp - lastT, 1) / 1000;
      var frames = -dx / Math.max(c.clientWidth, 1) * self.count;       // dragging across the whole width = one full turn
      self.angle += frames;
      self.velocity = frames / dt * 0.6 + self.velocity * 0.4;
      lastX = e.clientX; lastT = e.timeStamp;
      self.draw();
    });
    function release(e) {
      if (!self.dragging || (e && e.pointerId !== id)) return;
      self.dragging = false;
      var v = self.velocity;
      self.velocity = Math.max(-self.count * 1.5, Math.min(self.count * 1.5, v));
      self.start();
      self.scheduleResume();
    }
    c.addEventListener('pointerup', release);
    c.addEventListener('pointercancel', release);
    c.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        self.autoplay = false;
        self.velocity = 0;
        self.angle += e.key === 'ArrowRight' ? 1 : -1;
        self.draw();
        self.scheduleResume();
      } else if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        self.setUserPaused(!self.userPaused);
      }
    });
  };

  window.SpinViewer = SpinViewer;
  document.querySelectorAll('[data-spin]').forEach(function (el) { el._spin = new SpinViewer(el); });
})();
