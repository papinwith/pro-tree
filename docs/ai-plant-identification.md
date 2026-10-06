# AI plant identification from a photo

When an admin is cataloguing a tree they don't recognize, the photo field on
**เพิ่ม/แก้ไขชนิดพันธุ์** (`admin/species_form.php`) and **เพิ่ม/แก้ไขต้นไม้**
(`admin/tree_form.php`) has a **"🔍 ให้ AI ช่วยระบุชนิดต้นไม้จากรูปนี้"** button. It
sends the photo to a **local Ollama vision model** (default `qwen2.5vl:7b`) and
shows a best guess. No API key, no paid service, the photo never leaves the machine
running Ollama.

It is only ever a **suggestion** — nothing is written to the database until the
admin reviews it and saves the form.

## Setup

1. Install and start [Ollama](https://ollama.com) (listens on `http://localhost:11434`).
2. `ollama pull qwen2.5vl:7b` (about 6 GB) for photo identification, and
   `ollama pull qwen3:8b` for translation (see
   [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md)).

Settings (`config/local.php` constants or environment variables; see
`config/local.example.php`): `OLLAMA_URL`, `OLLAMA_MODEL` (translation; empty
string turns all AI off), `OLLAMA_VISION_MODEL`. The "ตั้งค่า" page shows what is
active. Any Ollama vision model works, e.g. `llama3.2-vision`.

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
| Button, client-side downscale (max 1024px JPEG), result panel | `public/assets/js/ai-identify-button.js` |
| JSON endpoint (POST, login + `species.manage`/`tree.create`/`tree.update`, CSRF, image validation) | `admin/identify_tree.php` |
| Prompt, response normalization, catalogue matching | `includes/plant_identify.php` |
| Shared Ollama HTTP call (also used by translation) | `includes/ollama.php` |

The model's JSON is never trusted as-is: `normalizePlantIdentification()`
coerces types, caps lengths and the alternatives list, and treats
`is_plant: true` with no name as "not identified".

## Cost, limits and time cap

One model call per click (not per photo taken) — deliberately a button, not
automatic. A local vision model is slow and occupies the machine, so each admin
is limited to `AI_IDENTIFY_MAX_PER_HOUR` (default 30) calls per hour. Upload
limit 10 MB (the browser shrinks photos well below that first). The endpoint
releases the PHP session lock before the AI call so other admin tabs stay
responsive.

The request is cut off at `AI_IDENTIFY_MAX_SECONDS` (default **120**, minimum 2),
measured from the moment the server has received the photo. On timeout the admin
gets HTTP 504 and "AI ตอบช้าเกินเวลาที่กำหนด … กรุณาลองใหม่" instead of waiting.
The first call after Ollama starts is slower while the model loads into memory.
If the machine has no GPU, raise the limit or use a smaller vision model.

Opening the species/tree form hands the endpoint the category/subtype/species
lists (a small cache file, `AI_IDENTIFY_CACHE_SECONDS`, default 600), so the click
doesn't re-read them (`warmIdentifyRequest()`). The admin's permission is checked
against the database on every click, so a revoked role stops working at once.

The nursery's own species (scientific names from the cached list) are offered to
the model as candidates, which settles look-alikes in favour of species the
nursery stocks; the wording tells the model to ignore the list unless the photo
clearly matches. An answer of "Unknown ..." / "ไม่ทราบ" is treated as "could not
tell" instead of being shown as a name.

## Details every time

Whichever engine names the plant - tree in the browser, Qwen, Pl@ntNet or Gemini - the answer always comes with the
long write-up (description, care, characteristics, properties, benefits, cautions, uses of each part). The engines now
only have to give the **name** (a short, quick answer, so Qwen is faster than when it also wrote everything);
`completeWithDetails()` in `includes/species_details.php` then fills the details, looking in this order:

1. the species already **catalogued** in the system (text people wrote and approved);
2. the **cache** (`species_details_cache`, created on first use) of what the AI wrote for that scientific name before;
3. a **fresh AI write-up**, Gemini if `GEMINI_API_KEY` is set (about 5 s), else the local text model (measured at about
   80 s on a 4 GB GPU, so with only Ollama it may not finish inside the time limit), then cached.

A write-up describes the species, not the photo, so it is reused for every photo of it. The in-browser model gets its
details through `admin/identify_match.php?want_details=1` (the cache makes repeat species instant). If no engine can write
the details, the answer is still returned with `details_status: "failed"` and the page says so.

Things to know: the text is AI-written and can be wrong (a trial of the local model said teak leaves are used as
herbal medicine - doubtful), and **a cached text is reused for every later photo of that species**, so a mistake spreads;
the page tells the reader to check it before saving, and a species saved in the system takes precedence over the cache.
To discard a bad cached text, delete its row: `DELETE FROM species_details_cache WHERE name_scientific = 'Tectona grandis'`.
Tests: `php tests/species_details_test.php` (21 checks) and the details cases in `tests/failover_test.php`.

## The in-browser model "tree"

Before any server AI is asked, the button lets a small model named `tree` look at the photo **inside the visitor's
own browser** (`public/assets/js/tree-model.js`, ONNX Runtime Web; files in `public/assets/models/`). If it is at
least 90 % sure (`app_threshold` in `tree.labels.json`) and the species exists in the system (looked up by
`admin/identify_match.php`, no AI), that answer is shown straight away: no upload, no cost, no server AI. Otherwise
the photo goes to the server AI as described below. A form that asks for the full write-up (`data-detail="full"`)
accepts the local answer only when the species is already catalogued, since `tree` knows names, not care texts.

- **Coverage:** 273 species. On held-out GBIF photos, answers at >= 90 % confidence were right 97.5 % of the time
  but covered only 7.5 % of photos (15 % for the 27 catalogue species) - so most requests still reach the server AI.
  Photos taken in the exhibition may differ from GBIF's, so these numbers can be optimistic.
- **Tests:** `python tests/tree_browser_check.py` (browser vs Python give the same answer; 60/60 and a probability
  difference of at most 0.004 when written) and `python tests/tree_button_check.py` (the button's four paths).
  Both need `ml/data_sea` and `ml/models`, so they are run by hand. The photo preparation in `tree-model.js`
  deliberately reproduces PIL's filters: a plain browser canvas shrink moved probabilities by up to 0.15.
- **First use:** the visitor downloads about 6 MB of model plus ONNX Runtime's ~11 MB WebAssembly once (then cached).
- **Credits:** `public/assets/models/credits.csv` lists the photographers (CC-BY); the result panel links to it.

## When the local model is down

The local model (Qwen on Ollama) can be off, unreachable (your PC asleep, a tunnel that died), too slow or give nothing
usable. If Pl@ntNet and/or Gemini are configured, `identifyWithoutLocalModel()` takes over instead of failing:

| Configured | What answers |
|---|---|
| Gemini + Pl@ntNet | Gemini is the main answer (it can write the full Thai write-up); Pl@ntNet is the second opinion on the name, merged like the normal flow (agree: higher + 10, max 95; differ: the surer one wins and the other is listed) |
| Gemini only | Gemini alone |
| Pl@ntNet only | the name only; the page says the Thai name and write-up must be filled in by hand |
| neither | the original error (e.g. "cannot connect to Ollama") |

The result carries `main_source` (`qwen` / `gemini` / `plantnet`) and `fallback_note`, which the page shows as a warning.
With a backup present the local model is given about 60 % of the time budget (at least 15 s), so a hang cannot starve
the backup. Translation does the same: Ollama first (60 s when Gemini can back it up), then Gemini; with neither, the
page keeps the Thai text. AI is reported enabled when **any** engine is configured; `OLLAMA_MODEL=off` switches only the
local one off. Tested over real HTTP against mock services with Ollama pointed at a closed port: `php tests/failover_test.php`.

## Teaching "tree" from the teachers' answers

When tree is not sure and the server AI is asked, the outcome can become a training photo (`includes/training_samples.php`,
table `training_samples`, created on first use):

| Situation | Stored as |
|---|---|
| Qwen and the second teacher (Pl@ntNet, else Gemini) name the same species | **approved**, automatically |
| they disagree | **pending** - a person decides on `admin/training_samples.php` (nav: ตัวอย่างสอน AI) |
| an admin presses "use this result" | **approved** (the photo is re-sent to `admin/training_sample_add.php`) |
| only one teacher answered | not stored |

Photos tree answered by itself are not stored (no new information). Pending queue is capped at 300. Approved photos
are downloaded with `ml/pull_feedback.py` through `admin/training_samples_export.php`, which is off unless the host sets
`TRAINING_EXPORT_TOKEN` (24+ characters) and answers 404 without it. Retraining and publishing are manual, and
`ml/publish_model.py` refuses a model whose answers at the 90 % gate are not right at least 90 % of the time - see
`ml/README.md`. Risk to know: two teachers can agree and still be wrong, and an admin who does not know the plant can
approve a mistake; wrong labels make tree worse, so reject what you are unsure of.

## Confidence percentage and second opinion

The local model reports `confidence_pct` (0-100); the page shows it as e.g.
"ความมั่นใจ: 82%". It is the model's own estimate, not a calibrated probability.

When it is below `AI_CONFIDENCE_MIN` (default 70), a second opinion is asked for
(`askSecondOpinion()` in `includes/plant_identify.php`):

1. **Pl@ntNet** (`PLANTNET_API_KEY`, `includes/plantnet.php`) - a plant-identification
   service, preferred because it is far better than a general model on look-alike
   species. In a quick check it got Cassia fistula and Tectona grandis right, the two
   species the local model and the trained student kept missing. The free key allows
   about 500 identifications per day. It returns only scientific/common names.
2. **Gemini** (`GEMINI_API_KEY`, `GEMINI_MODEL`, `includes/second_opinion.php`) - used
   only if Pl@ntNet is not configured or fails; it writes a full answer like the local model.

`applySecondOpinion()` then merges the two:

| Result | What happens |
|---|---|
| Same species | confidence = higher of the two + 10 (max 95); the local write-up is kept |
| Different species | the more confident answer is the main one, the other goes to the alternatives, confidence - 20. If the winner is Pl@ntNet, the Thai name and write-up are empty and a note says to fill them in by hand |
| No answer | `second_opinion_status` is `no_key`, `no_time` (under ~6 s of the budget left) or `failed`; the local answer is kept |

`needs_review` is true while the final confidence is still under the threshold, and
the page then asks for a person who knows plants to check it, or a better photo.
The photo only leaves the machine when a second opinion is actually requested.
Two sources agreeing is a good sign, not a guarantee. Keys are server-side only
(Railway variables); never commit them.

## Known limits

- Small local models are less accurate than large hosted ones. The confidence
  value is the model's own and can be overconfident; always read the drafted text
  before saving. Accuracy has not been benchmarked for the Ollama models.
- A single photo is often not enough (a leaf-only or bark-only shot). A photo that
  shows flower/leaf/fruit clearly matters more than any setting here.

## Testing

`php tests/plant_identify_test.php` runs the app and a mock Ollama server locally (`OLLAMA_URL` points at it), so it needs no real model. It checks auth/permission/CSRF/upload validation, the request actually sent to Ollama (vision model, base64 photo, JSON format), success, no-match, not-a-plant, malformed output, upstream HTTP errors, the time cap and the hourly quota. The endpoint part needs the project's database (PHP's `pdo_pgsql` extension).
