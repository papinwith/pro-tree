# Map System

Three distinct map features coexist — don't conflate them:

1. **Map banner** (existing, simple) — an image at the top of the Tree
   Information Page, tap-to-expand in a lightbox. Implemented via the
   `settings` table (`default_map_image`) plus an optional per-tree
   override (`trees.map_image_path`). `default_map_url`/`trees.map_url`
   still exist in the schema and admin UI but are currently unused by the
   public page — the banner used to be a click-through link to that URL;
   it was changed to a same-page lightbox instead (see `settings.php`'s
   field-hint on `default_map_url`). Re-wiring that URL, or removing the
   now-dead field, is open follow-up work, not covered by this document.
2. **Zone pins on the map banner** (built — see §1a below) — a
   lightweight, no-migration-beyond-two-columns way to pin each *zone*
   (not each tree — there are far too many trees to pin individually) at
   a percentage position on the same banner image from §1.
3. **Interactive zone/tree map** (design only, no application code yet) —
   a real GPS-based map (Leaflet/OpenStreetMap) showing zone boundaries
   and individual tree markers, for both a public map page and an admin
   location-editing view. §§2a–2e below cover this one specifically.

## 1a. Zone Pins (built)

Percentage-position pins on the *existing static banner image* from
feature #1 above — not real GPS, and not per-tree (see rationale above).

- **Data**: `zones.map_pin_x`/`map_pin_y` (`DECIMAL(5,2)`, % from
  left/top of the banner image; `docs/migrations/0022_add_zone_map_pins.sql`).
  Nullable — a zone with no pin placed yet just doesn't render one.
- **Admin** (`admin/zone_map.php`, gated by `zone.manage`): pick a zone
  from a dropdown, click anywhere on the banner image to place/move that
  zone's pin (client-side click-position → % of image, auto-submitted); a
  "ลบหมุด" button clears one back to NULL.
- **Public** (`public/tree.php`): every zone with a pin renders as a
  small marker overlaid on the banner at the same %, positioned
  absolutely inside a `.map-banner-wrap` container so it lines up
  identically to how the admin editor placed it. The tree page's own
  zone gets a visually distinct "you are here" marker. Tapping any other
  pin toggles a name label (no navigation target — there's no public
  per-zone browse page; see §2b below for that, still unbuilt).
- Zone name labels don't currently force a translation for every locale
  — a zone that's never been viewed in EN/ZH before falls back to Thai on
  its pin label until `ensureZoneTranslated()` runs for it (same lazy
  cache-on-first-view pattern as every other zone/species field).

## 2a. Data Model (interactive map)

```sql
ALTER TABLE zones
  ADD COLUMN center_lat      DECIMAL(10,7) NULL,
  ADD COLUMN center_lng      DECIMAL(10,7) NULL,
  ADD COLUMN boundary_geojson JSON NULL;  -- polygon coordinates, GeoJSON format
```

Tree markers need no new columns — `trees.latitude`/`longitude` (existing)
already provide marker position, and `trees.zone_id` groups markers by
zone. `boundary_geojson` is nullable: zones can exist and hold trees before
anyone has drawn a boundary for them, so the map degrades to
center-point-only pins for undrawn zones rather than failing.

## 2b. Public Map Page (`public/map.php`, new)

```text
Load all active zones (with boundary_geojson/center point) + all active,
published trees (id, lat/lng, zone_id, species name, main image, status)
        │
        ▼
Render map: zone polygons/pins + one marker per tree
        │
        ▼
Visitor taps a tree marker
        │
        ▼
Popup shows: Tree ID (display label), name, image, zone, status,
"View full page →" link to tree.php?id={trees.id}
```

No auth, no write access — purely a read view over the same public data
`tree.php` already exposes, just visualized spatially instead of one tree
at a time.

## 2c. Admin Map (`admin/map_admin.php`, new)

Same base map, with edit affordances gated by permission (see
[`rbac.md`](rbac.md)):

| Action | Permission |
|---|---|
| Add/edit a zone's boundary or center point | `zone.manage` |
| Drag a tree marker to a new position | `tree.location.manage` (writes `trees.latitude/longitude` + `plant_location_history`, exactly as a manual location edit would — see [`tree-identity-and-relationships.md`](tree-identity-and-relationships.md) §5) |
| Assign a tree to a different zone by dropping its marker inside a different polygon | `tree.location.manage` (triggers the same zone-change path as the tree edit form — one `plant_location_history` row, one `plant_code` recompute) |
| View-only (no edit controls rendered, and edit endpoints still reject the write server-side even if attempted directly) | Everyone with `tree.view` |

Dragging a marker is a location edit, not a separate feature — it must go
through the exact same write path (`plant_location_history` insert +
`plant_code` recompute) as editing coordinates in `tree_form.php`, so
there's one source of truth for "what happens when a tree moves," not two
divergent code paths that could drift apart.

## 2d. Map Provider

No specific provider is mandated by the brief. Recommend **Leaflet + a
free tile source (OpenStreetMap)** over a paid API (Google Maps/Mapbox):
zero per-load cost at this project's traffic scale, self-hostable JS/tiles
for reliability on-site, and no API key to protect. This is a
recommendation to confirm, not a requirement pulled from the brief itself.

## 2e. Relationship to Scan Location

The public map and the GPS scan-location feature
([`tree-identity-and-relationships.md`](tree-identity-and-relationships.md)
§6) are independent: the map shows *registered* tree positions for
browsing; scan location is a per-scan-event log used for analytics, not
rendered back to the visitor. An admin-only "scan heatmap" view (plotting
`tree_scans.scan_lat/scan_lng` where available) is a natural future
addition on top of this same map component, gated by `stats.zone.view`
— not required for the initial build, noted here so the map component
isn't designed in a way that would need rework to add it later.
