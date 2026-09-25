# AI plant identification from a photo

When an admin is cataloguing a tree they don't recognize, the photo field on
**เพิ่ม/แก้ไขชนิดพันธุ์** (`admin/species_form.php`) and **เพิ่ม/แก้ไขต้นไม้**
(`admin/tree_form.php`) has a **"🔍 ให้ AI ช่วยระบุชนิดต้นไม้จากรูปนี้"** button. It
sends the photo to Google Gemini (vision) and shows a best guess.

It is only ever a **suggestion** — nothing is written to the database until the
admin reviews it and saves the form.

## Setup

Uses the same Gemini API key as the translation feature (see
[`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) and
the "ตั้งค่า" page): `GEMINI_API_KEY` in `config/local.php`, or as an
environment variable on a host like Railway. Without a key the button is shown
disabled with a hint.

## What the admin sees

- **Species form** — result panel with Thai name, common name, scientific name,
  confidence (high / medium / low), a short description, what the guess was
  based on, and up to 3 alternatives. **"ใช้ชื่อนี้กรอกลงฟอร์ม"** fills the name
  fields (and the description, only if it's still empty).
- **Tree form** — same panel, but the form needs an *existing* species. If the
  answer matches one already in the catalogue (by scientific name — genus +
  species, ignoring author/cultivar suffixes — or exact Thai name), the button
  selects it in the "ชื่อต้นไม้" dropdown. If not, a link offers to add it as a
  new species first.
- The photo used is the one just picked/taken; if none was picked, the tree's
  already-saved photo is used.

## How it works

| Piece | File |
|---|---|
| Button, client-side downscale (max 1280px JPEG), result panel | `public/assets/js/ai-identify-button.js` |
| JSON endpoint (POST, login + `species.manage`/`tree.create`/`tree.update`, CSRF, image validation) | `admin/identify_tree.php` |
| Prompt, response normalization, catalogue matching | `includes/plant_identify.php` |
| Shared Gemini HTTP call (also used by translation) | `includes/gemini.php` |

The model's JSON is never trusted as-is: `normalizePlantIdentification()`
coerces types, caps lengths and the alternatives list, and treats
`is_plant: true` with no name as "not identified".

## Cost and limits

One Gemini call per click (not per photo taken) — deliberately a button, not
automatic, so an admin browsing photos isn't billed for each one. Upload limit
5 MB (the browser shrinks photos well below that first). The endpoint releases
the PHP session lock before the (up to 60 s) API call so other admin tabs stay
responsive.

## Testing

`php tests/plant_identify_test.php` — needs no API key or internet: it runs
the app and a mock Gemini API locally (`GEMINI_API_BASE` env override) and
checks auth/permission/CSRF/upload validation, the real request sent to Gemini
(photo as inline data, key in the `X-goog-api-key` header), success, no-match,
not-a-plant, malformed output and upstream HTTP errors.

## Speed and accuracy (Sept 2026 benchmark)

Measured on real photos (Wikimedia Commons species categories as ground
truth — 8-10 photos per model, so treat it as indicative, not a study):

| Model | Correct species | Median time | Reliability |
|---|---|---|---|
| `gemini-3.5-flash-lite` (**default**) | 7/8 (8/8 right genus) | ~3.5 s | no failures |
| `gemini-3.1-flash-lite` | 6/10 (8/10 genus) | ~5.7 s | no failures |
| `gemini-3.5-flash` | 5/6 of those that answered | 10-17 s | 4/10 failed (busy/quota) |
| `gemini-3-flash-preview` | 4/10 (6/10 genus) | ~10 s | 4/10 failed |
| `gemini-3.6-flash` (previous default) | — | 13 s | mostly failed (quota/busy) |

The full species write-up (all fields + category/subtype) takes ~4 s with the
default model, down from 10-27 s. Asking the model to list observed features
before naming the plant did not change accuracy, so the shorter prompt stays.

### Model chain and quota

`GEMINI_MODEL` (default `gemini-3.5-flash-lite`) is tried first, then each of
`GEMINI_FALLBACK_MODELS` (comma-separated env var / constant; default
`gemini-3.1-flash-lite,gemini-3.5-flash,gemini-3-flash-preview`) whenever a model
answers 429 (quota), 503 (overloaded), 500/502/504 or 404 (retired). If every
model was merely overloaded it pauses 2 s and goes round once more. Errors that
would fail identically everywhere (400, bad key, safety block) and network
timeouts are not retried on another model.

**The free tier allows only ~20 requests per model per day**
(`GenerateRequestsPerDayPerProjectPerModel-FreeTier`), shared by translation and
identification. The chain gives roughly 20 x the number of models per day; for
real use enable billing on the Google AI project. Some models (e.g. 3.1 Pro) have
a free-tier limit of 0.

### Known limits

- Look-alikes: e.g. *Plumeria rubra* was answered as *P. pudica* with "high"
  confidence — the confidence value is the model's own and can be overconfident.
- The lite model occasionally emits a stray non-Thai character inside long Thai
  text; always read the drafted text before saving.
- A single photo is often not enough (a leaf-only or bark-only shot). Taking a
  photo that shows flower/leaf/fruit clearly matters more than any setting here.

## Hard time cap (5 seconds)

The identify request is cut off at `AI_IDENTIFY_MAX_SECONDS` (default **5**,
minimum 2), measured from the moment the request starts — the script's own
work and any fallback models all come out of that one budget. If the AI hasn't
answered in time the admin gets HTTP 504 and "AI ตอบช้าเกินเวลาที่กำหนด …
กรุณาลองใหม่" (`timed_out: true`) instead of waiting. The button also shows a
live seconds counter.

What it took to make 5 s realistic when the database is remote (each query
~0.3 s, connecting ~0.8 s from a machine ~170 ms away):

- **No database access on the click.** Opening the species/tree form hands the
  endpoint what it already loaded: the category/subtype/species lists go to a
  small cache file (`AI_IDENTIFY_CACHE_SECONDS`, default 600) and the admin is
  marked "just verified" in their own server-side session for ~2 minutes
  (`warmIdentifyRequest()` in `includes/plant_identify.php`). A session that
  never opened the form is checked against the database (`canAny()`, one
  query). *Trade-off:* a role revoked within those ~2 minutes still works for
  this one endpoint until the marker lapses.
- Smaller upload (1024 px, JPEG 0.82) and no sleeping between fallback models.

Measured end to end through the real endpoint with the real API, from a
machine with a slow link to both Google and the database (form opened first),
5 photos x brief/full: **10/10 answered, 1.9-3.7 s** (median ~3 s). Before
these changes the same run timed out on 4 of 10 requests with a 5 s cap.
