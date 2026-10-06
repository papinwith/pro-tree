# 360° spin of a tree

Each tree can have a **spin**: photos taken while walking once around it, shown on the public tree page as a picture that
keeps turning by itself (like a GIF) and that a visitor can drag - or use the arrow keys on - to look around the tree.

## Recording one (admin)
Admin -> edit a tree -> section **ภาพหมุน 360° รอบต้นไม้**.
1. Film a slow, steady walk **once around the tree** (15-30 s, the tree in the middle, same distance throughout), or take
   8+ photos around it (name the files in order).
2. Pick the video (or the photos). The browser picks evenly spaced frames **on the admin's own device** (default 36, 12-72;
   more = smoother but a bigger file), shrinks them to <= 720 px and shows a preview of the turn.
3. Press **บันทึกภาพหมุน**. It uploads in small batches and the new spin only replaces the old one when it is complete,
   so visitors never see half of one. A failed upload leaves the current spin untouched; pressing save again retries.

Frames are not put at the exact end of the video, so a full circle does not repeat its first picture and the loop has no stutter.

## What visitors get
- turns by itself, one revolution in about 12 s, without end; the still photo is shown until the first frame arrives;
- touch / drag takes over (with a little inertia); after 2.5 s without touch it turns again; a pause button stops it for good;
- frames are blended into each other, so it looks smooth even with 24-36 photos;
- arrow keys step one frame (and take over from the automatic turn), space pauses; the canvas is focusable and labelled;
- it does nothing while off-screen or in a background tab (saves battery) and obeys "reduce motion": no automatic turning
  then (dragging still works);
- if the frames cannot be loaded, the still photo simply stays.

## Where it lives
| Piece | File |
|---|---|
| storage rules (tables created on first use, atomic commit, limits) | `includes/spin.php` |
| frame delivery, cached for a year (the token is in the URL) | `public/spin_frame.php` |
| admin upload: start / frames / commit / delete | `admin/spin_upload.php` |
| visitor viewer | `public/assets/js/spin-viewer.js` |
| admin capture (frames from video) + upload | `public/assets/js/spin-capture.js` |

**Frames are stored in the database** (base64 text, ~50-90 KB each, so roughly 2-3 MB per 36-frame tree), not as files:
the container's disk is wiped on every deploy. Deleting a tree deletes its spin. Limits: 8-72 frames, 400 KB per frame,
JPEG / PNG / WebP (the type is read from the bytes). Uploads that were started but never committed are discarded after a day.

## Uploaded photos on Railway
Ordinary uploaded photos (tree photo, species photo, QR codes) ARE files and need a **persistent Volume** on the service:
Railway -> the service -> Settings -> Volumes -> New Volume -> Mount path `/var/www/html/public/assets/uploads`.
Without it they disappear at every deploy (the committed seed images are restored by `docker/entrypoint.sh`, so those
survive and it is easy to miss). The admin dashboard shows a red warning while no volume is detected
(`uploadsPersistenceWarning()`), based on Railway's `RAILWAY_VOLUME_MOUNT_PATH`.

## Tests
- `php tests/spin_test.php` - storage rules on SQLite (23 checks).
- `python tests/spin_browser_check.py` - headless Chromium against a fake server (18 checks): the viewer turns, drag,
  resume, pause, keys, real blending, reduced motion, a broken frame; the admin capture turns a video into frames and
  uploads in order (start -> batches of <= 6 -> commit), and a failed batch sends no commit.
Not tested: real phones/iOS Safari (video seeking differs between browsers), the PostgreSQL side (the code is plain SQL,
tested on SQLite), and the pages on the live site.
