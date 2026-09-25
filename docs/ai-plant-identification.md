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
