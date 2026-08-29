# User Flows

## 1. Visitor Flow — Scanning a Tree

```text
[Scan QR Code]
      │
      ▼
[Open Tree Info URL] ──► App checks visitor cookie
      │                        │
      │                No cookie found        Cookie found
      │                        │                    │
      │                        ▼                    ▼
      │                 Generate visitor_uuid   Reuse visitor_uuid
      │                 Set cookie (2yr expiry) 
      │                        │                    │
      │                        └────────┬───────────┘
      │                                 ▼
      │                    Upsert `visitors` row
      │                    (ip_address, user_agent, last_seen_at)
      │                                 │
      │                                 ▼
      │                     Browser requests GPS permission (non-blocking;
      │                     denied/unavailable → scan proceeds normally,
      │                     see tree-identity-and-relationships.md §6)
      │                                 ▼
      │                     Insert new row into `tree_scans`
      │                     (tree_id, visitor_id, ip, user_agent, now,
      │                      scan_lat/scan_lng if granted, snapshot of the
      │                      tree's CURRENT zone/lat/lng into
      │                      registered_zone_id/registered_lat/registered_lng —
      │                      repeats are logged the same as first-time
      │                      scans, never flagged to the visitor)
      │                                 ▼
      │                     Recompute unique_visitor_count,
      │                     total_scan_count for this tree
      │                                 ▼
      ▼                     Render Tree Information Page
[Tree Info Page Loads]◄─────────────────┘
      │
      ├──► Views image/name/classification/description
      ├──► Views detail sections (characteristics, properties, benefits,
      │           cautions, uses by part — whichever the admin filled in)
      ├──► Sees unique visitor count
      ├──► Clicks Map Banner ──► Redirect to configured map_url
      ├──► Clicks "I'm Interested" ──► Email form appears
      │           └──► Submits email ──► POST /tree/{id}/interest
      │                        └──► Insert into `tree_interests`
      │                        └──► Show confirmation
      └──► Clicks Previous/Next ──► GET /tree/{id}/prev|next
                   └──► Resolve neighbor by display_order
                   └──► Redirect (loop repeats from "Open Tree Info URL")
```

## 2. Admin Flow — Managing Trees

```text
[Admin visits /admin/login]
      │
      ▼
[Enter credentials] ──► Validate against `admins` table
      │
      ▼ (success)
[Admin Dashboard]
      │
      ├──► Zones ──► Create / Edit a zone (code, name, description)
      │
      ├──► Species ──► Create / Edit a species (name, scientific/common name,
      │                classification, care instructions, characteristics/
      │                properties/benefits/cautions/part uses) — one edit here
      │                updates every tree of that species at once
      │
      ├──► Trees List ──► Search by species/zone/label/Tree ID
      │                   Create / Edit / Reorder / Deactivate a tree
      │                        └──► Assigns species + zone, sets label,
      │                             status, image, location, display_order
      │
      ├──► Map Settings ──► Configure default map image + URL
      │                        (or per-tree override in tree edit form)
      │
      └──► Reports ──► View per-tree: total scans, unique visitors,
                        repeat scans, interest submissions
                        └──► Export CSV
```

A tree can't be created until at least one species and one zone exist —
`admin/tree_form.php` prompts the admin to add those first if the lists are empty.

## 3. Key Decisions Encoded in These Flows

1. **No login barrier for visitors** — the cookie/visitor_id exchange happens transparently on first page load; nothing is asked of the visitor.
2. **Every page load logs a scan event** — repeat scans are expected and counted; "uniqueness" is a property of the visitor, not the event. A repeat is never shown to the visitor as a "duplicate scan" notice — it's recorded silently and only surfaces later in admin reports.
3. **The QR-scanner page keeps the camera alive across language changes** — switching languages there rewrites the on-screen text via JS and sets the language cookie directly, instead of navigating to a new URL (which would tear down the active `getUserMedia` stream).
4. **IP + User-Agent are captured but never used alone to define identity** — they support the cookie-based `visitor_uuid` as corroborating/audit data (see [visitor-identity.md](visitor-identity.md)).
5. **Admin and visitor systems never intersect** — different tables, different auth, different session scope.
6. **Prev/Next is driven by a single ordering field** (`display_order`), keeping tree sequencing simple to reason about and easy for admins to change (drag-and-drop reordering maps directly to updating integers). *(Superseded by the explicit `tree_relationships` edge table — see [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §4 — which keeps `display_order` for admin-list sorting only.)*
7. **Species/zone content is entered once, not per specimen.** A tree (individual plant) only stores which species/zone it belongs to plus specimen-specific data (photo, location, status); name/description/care content lives on the species row it references, so a correction there is instant everywhere.

## 4. Additional Flows (Tree Admin / Programmer)

Not duplicated here — each has enough moving parts to warrant its own
document:

| Flow | Where |
|---|---|
| Tree movement / location update | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5 |
| Previous/Next relationship rewiring | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §4 |
| QR code resolution | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §6 |
| Scan location / GPS capture | [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §7 |
| Multilingual content + AI (Gemini) translation review | [`multilingual-and-ai-translation.md`](multilingual-and-ai-translation.md) §3 |
| Interactive map (public browsing + admin editing) | [`map-system.md`](map-system.md) §2–3 |
| Role/permission enforcement on every flow above | [`rbac.md`](rbac.md) §7–8 |
