# Visitor Identity & Scan Deduplication

## Why not just use IP address?

- Multiple visitors can share an IP (same wifi, mobile carrier NAT, school/park network).
- A single visitor's IP can change between scans (mobile network switching, VPN).
- Using IP alone would both undercount (shared IP → looks like one visitor) and overcount (same visitor, changing IP → looks like many visitors).

The requirements explicitly call this out: **IP is stored, but is not the unique identity.**

## Identity Model

Identity = a **first-party cookie holding a generated `visitor_id` (UUID v4)**, corroborated by IP + User-Agent for auditing/anti-abuse purposes only.

```text
visitor_uuid (cookie, ~2 year expiry)
        │
        ▼
  visitors table row
        │
        ├── last_ip_address   (informational)
        ├── last_user_agent   (informational)
        └── visitor_uuid      (the actual identity key)
```

## Determining Scan Status on Each Request

```text
1. Read `visitor_id` cookie from request.
2. If missing:
     - generate UUID v4
     - create `visitors` row
     - set cookie in response (HttpOnly, Secure, SameSite=Lax, ~2yr expiry)
     - status_for_this_tree = "new visitor" (guaranteed, since no prior scans possible)
3. If present:
     - look up `visitors` row by visitor_uuid
       - not found (cookie valid format but stale/cleared DB) → treat as new: create row
     - query: SELECT 1 FROM tree_scans WHERE tree_id = ? AND visitor_id = ? LIMIT 1
       - found  → status_for_this_tree = "returning visitor"
       - not found → status_for_this_tree = "new visitor"
4. Always insert a new `tree_scans` row for this event (scan history is append-only).
```

## Metrics Definitions

| Metric | Definition | Query basis |
|---|---|---|
| Total scan count | Every page-load event, including repeats | `COUNT(*)` on `tree_scans` |
| Unique visitor count | Distinct visitor identities that ever scanned this tree | `COUNT(DISTINCT visitor_id)` |
| Repeated scans | Extra visits beyond each visitor's first | `total - unique` |

## Edge Cases & Mitigations

| Case | Handling |
|---|---|
| Visitor clears cookies | Treated as a new visitor on next scan — acceptable/expected tradeoff of cookie-based identity without login |
| Visitor uses two devices | Counted as two visitors — inherent to any no-login system; acceptable per requirements |
| Bot/crawler hitting the URL repeatedly without cookies | Each request looks "new"; optional mitigation: rate-limit scan inserts per IP+User-Agent within a short window (e.g. don't log more than 1 scan per IP per tree per minute) — noted as a future hardening step, not required for initial design |
| Double-count from page refresh | Optional: within admin reporting, a "repeat scan" a few seconds after the prior one from the same visitor can be treated as the same session rather than a new count — left as a future refinement; base design intentionally logs every request for simplicity and auditability |
| Cookie blocked entirely (privacy mode) | Falls back to "new visitor every time" — no crash, system still functions, just less accurate uniqueness for that visitor |
