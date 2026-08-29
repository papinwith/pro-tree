# Multilingual Content & Google Gemini AI Translation

Design only — no application code yet.

## 1. Content Model — kept as-is, not restructured

The current schema already stores Thai/English/Chinese as sibling columns
(`name` / `name_en` / `name_zh`, `description` / `description_en` /
`description_zh`, etc.) on `species` and `zones` — see
[`database.md` §3](database.md#3-species). This is reused unchanged for
the multilingual requirement: Thai is always the required source column,
`_en`/`_zh` are nullable and fall back to Thai when blank (existing
`lang.php` behavior).

**Not changed:** tree name/description content stays on `species`, not
`trees` — a deliberate existing decision ("every individual of a species
shares the same classification," `database.md` §9) that this brief doesn't
ask to override. "Tree Name" on the public page is the species name;
per-specimen text stays limited to the existing `trees.label` field.

## 2. Why AI Translation Needs One New Table

The triplicated-column model is fine for *storing* a finished translation,
but has no place to hold a **pending, unreviewed** AI draft — writing
straight into `species.name_en` the moment Gemini responds would violate
the brief's explicit rule ("AI must NOT automatically overwrite... admin
must review and edit before saving"). A new table holds drafts until
approved:

```sql
CREATE TABLE translation_drafts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30)  NOT NULL,       -- 'species' today; extensible later
    entity_id   BIGINT UNSIGNED NOT NULL,
    field_name  VARCHAR(60)  NOT NULL,       -- 'name', 'description', 'care_instructions', ...
    lang        CHAR(2)      NOT NULL,       -- 'en' | 'zh'
    draft_text  TEXT         NOT NULL,
    source      VARCHAR(20)  NOT NULL DEFAULT 'gemini',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,        -- admins.id
    reviewed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drafts_entity (entity_type, entity_id, status)
) ENGINE=InnoDB;
```

Approving a draft copies `draft_text` into the real column
(`species.name_en`, etc.) and marks the row `approved` — the public page
never reads from `translation_drafts` directly, so a half-reviewed
translation is never accidentally shown to a visitor.

## 3. Translation Flow

```text
Tree Admin enters/edits Thai content on species_form.php
        │
        ▼
Clicks "AI Translate" ──► POST includes/translation.php (server-side only)
        │
        ▼
Gemini API called with the Thai source text + a structured-output
instruction (see §4) — scientific names and proper nouns flagged
to preserve, not translate
        │
        ▼
Response parsed into { en: {...}, zh: {...} } per field
        │
        ▼
One translation_drafts row inserted per (field, lang) pair, status='pending'
        │
        ▼
Admin reviews drafts inline on species_form.php (side-by-side with
the Thai source), edits any draft_text directly if needed
        │
   ┌────┴────┐
Approve            Reject
   │                  │
   ▼                  ▼
Copy draft_text    status='rejected', species.*_en/_zh
into species.*_en   left unchanged — admin can still type
or species.*_zh,    a manual translation directly into the
status='approved'   form field instead
```

## 4. Structured Gemini Request/Response Contract

Request instructs Gemini to return exactly this shape (matches the
brief's example) so the backend never has to parse free-form prose:

```json
{
  "en": { "name": "...", "description": "...", "origin": "..." },
  "zh": { "name": "...", "description": "...", "origin": "..." }
}
```

(Thai is the input, not requested back — the source of truth already
exists in the form the admin just typed.)

**Preserve, don't translate:** the request prompt explicitly instructs
Gemini to keep scientific names (Latin binomials, e.g. *Cassia fistula*)
and other proper nouns untouched — these already live in a separate column
(`species.name_scientific`) that isn't sent through translation at all,
which is the simplest way to guarantee they're never mistranslated: don't
give the model the chance.

**Fields supported for AI translation** (per the brief): tree/species
name, description, origin, and "additional information" — maps to
`species.name`, `description`, and the existing `care_instructions` /
`characteristics` / `properties` / `benefits` / `cautions` / `part_uses`
group, each translated as its own `field_name` row in `translation_drafts`
so they can be reviewed/approved independently.

## 5. Security & Error Handling

- **API key never reaches the browser.** `includes/translation.php` is the
  only file that holds `GEMINI_API_KEY` (from `config/config.php`, env-
  sourced, not committed — same convention as `DB_PASS`). All calls happen
  server-side, triggered by an admin session request, never a public one.
- **Gated by permission**, not just role convenience: `translation.request`
  (trigger a translate call) and `translation.review` (approve/edit/reject
  a draft) are separate permissions — see [`rbac.md`](rbac.md) §3/§4 update.
  `gemini.config.manage` (API key/model settings) is Programmer-only and
  distinct from general `settings.manage`, matching the brief's explicit
  "Tree Admin must NOT modify Gemini API credentials."
- **Graceful degradation:** if the Gemini API is unavailable, times out, or
  returns malformed output, `includes/translation.php` catches the failure
  and the UI falls back to plain manual text inputs for `_en`/`_zh` — the
  admin can always type a translation by hand regardless of API state. No
  part of the save flow for `species_form.php` should ever hard-depend on
  Gemini succeeding.
- **Rate/cost control:** since translation is a paid external call,
  `admin/translations.php` should show existing `pending` drafts before
  allowing a fresh translate request for the same entity+field (avoid
  accidental duplicate spend on repeated clicks). `storage/translation_cache/`
  (see [`system-architecture.md`](system-architecture.md) §3) is a future
  option if the same content ever needs re-translating.
