# Map System

Design only — no application code yet. Two distinct map features coexist —
don't conflate them:

1. **Map banner** (existing, simple) — a clickable image at the top of the
   Tree Information Page linking to a configured URL. Already fully
   specified in [`page-flow.md`](page-flow.md) and implemented via the
   `settings` table (`default_map_image`/`default_map_url`) plus optional
   per-tree overrides (`trees.map_image_path`/`map_url`). **Unchanged by
   this design.**
2. **Interactive zone/tree map** (new) — a real map showing zone
   boundaries and individual tree markers, for both the public map page
   and an admin location-editing view. This document covers #2.

## 1. Data Model

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

## 2. Public Map Page (`public/map.php`, new)

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

## 3. Admin Map (`admin/map_admin.php`, new)

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

## 4. Map Provider

No specific provider is mandated by the brief. Recommend **Leaflet + a
free tile source (OpenStreetMap)** over a paid API (Google Maps/Mapbox):
zero per-load cost at this project's traffic scale, self-hostable JS/tiles
for reliability on-site, and no API key to protect. This is a
recommendation to confirm, not a requirement pulled from the brief itself.

## 5. Relationship to Scan Location

The public map and the GPS scan-location feature
([`tree-identity-and-relationships.md`](tree-identity-and-relationships.md)
§6) are independent: the map shows *registered* tree positions for
browsing; scan location is a per-scan-event log used for analytics, not
rendered back to the visitor. An admin-only "scan heatmap" view (plotting
`tree_scans.scan_lat/scan_lng` where available) is a natural future
addition on top of this same map component, gated by `stats.zone.view`
— not required for the initial build, noted here so the map component
isn't designed in a way that would need rework to add it later.
