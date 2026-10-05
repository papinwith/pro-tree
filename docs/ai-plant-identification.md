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

## Known limits

- Small local models are less accurate than large hosted ones. The confidence
  value is the model's own and can be overconfident; always read the drafted text
  before saving. Accuracy has not been benchmarked for the Ollama models.
- A single photo is often not enough (a leaf-only or bark-only shot). A photo that
  shows flower/leaf/fruit clearly matters more than any setting here.

## Testing

`php tests/plant_identify_test.php` runs the app and a mock Ollama server locally (`OLLAMA_URL` points at it), so it needs no real model. It checks auth/permission/CSRF/upload validation, the request actually sent to Ollama (vision model, base64 photo, JSON format), success, no-match, not-a-plant, malformed output, upstream HTTP errors, the time cap and the hourly quota. The endpoint part needs the project's database (PHP's `pdo_pgsql` extension).
