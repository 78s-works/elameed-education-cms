# Teacher Landing Page — Contract (v2)

The landing page is **data + layout**. The backend stores one shared content
contract; the frontend renders it in one of **three layouts** (`classic | grid |
spotlight`). Switching layout never changes or loses content — all three consume
the identical `sections`. Two sections are **dynamic** (`courses`,
`testimonials`): the teacher stores a `config` rule; the public endpoint resolves
it to real `items`.

Envelope for both endpoints: `{ "data": { "layout", "nav", "sections": [...] } }`.

## Concepts
- **`layout`** — `classic | grid | spotlight`. Site-wide; frontend-only rendering.
- **A section** — `{ key, type, visible, order, content, items?, config? }`.
  - `type` — renderer: `hero | stats | features | about | steps | courses | testimonials | packages | cta | contact`.
  - `content` — teacher copy (schema per type, below).
  - `items` — **public payload only**; dynamic sections resolved server-side.
  - `config` — **authoring payload only**; the source rule for a dynamic section.
- The section **set is fixed** (reorder / show-hide / edit only).

## `GET /tenant/context` — 🌐 Public
Branding only (`logo_url, cover_url, primary_color, secondary_color, bio, socials`). Landing content is NOT here — use `/tenant/landing`.

## `GET /tenant/landing` — 🌐 Public (optional auth)
Fully resolved: `{ layout, nav:{links:[{label,target}]}, sections:[…] }`. Dynamic sections carry resolved `items`. If authenticated, course items include `enrolled`. Return sections pre-sorted by `order`; zero dynamic results → `items: []`.

```jsonc
// section content schemas (content object per type)
hero:         { eyebrow, title_html("may contain <span>"), description, note,
                primary_cta:{label}, secondary_cta:{label},
                teacher:{ name, role, image_url, card_stats:[{value,label}] },
                chips:[{ text, type:"green|red|plain" }] }
stats:        { items:[{ value, label }] }
features:     { title, subtitle, items:[{ icon:"fa-*", title, desc }] }
about:        { badge, title, body, image_url, points:[string] }
courses:      { title, subtitle }   // + items[] (resolved, see below)
steps:        { title, subtitle, items:[{ n, title, desc }] }
testimonials: { title, subtitle }   // + items[] (resolved reviews)
packages:     { title, subtitle, items:[{ id, name, badge, price:{amount_minor,currency}, period, featured, features:[string] }] }
cta:          { title, subtitle, cta:{label} }
contact:      { title, subtitle }

// resolved courses item
{ id, uuid, slug, title, cover_url, grade, type:"online|center",
  price:{amount_minor,currency}, is_free, lessons_count, duration_label,
  rating, students_count, enrolled }
// resolved testimonials item
{ id, student_name, course_title, rating, comment, created_at }
```

## `GET·PUT /teacher/landing` — 🔑 Auth (teacher)
Authoring shape: dynamic sections carry **`config`** (not `items`).
```jsonc
courses.config:      { source:"featured|all|category|selected", category_id?, course_ids?[], limit:1-24 }
testimonials.config: { source:"latest|top_rated", min_rating?, limit:1-24 }
```
`PUT` body = `{ layout?, sections:[{key,type,visible,order?,content,config?}] }` — **full replace**.
**422** when: `layout` invalid; a `type` unrecognized; a dynamic `source` invalid; `category_id`/`course_ids` not owned by the tenant; `limit` outside 1–24.

**Milestone rules:**
- `stats/features/steps/packages` — only `title`/`subtitle` editable; their `content.items` are **preserved** from the last save.
- `hero.title_html` is **sanitized to a `<span>`-only allowlist** on save (renders on a public page).

## Resolution rules (public)
**courses** by `config.source`: `featured` → ranked by enrollment; `all` → published; `category` → by `category_id`; `selected` → exactly `course_ids` in order — each capped at `limit`. Computed card fields: `students_count` (active enrollments), `lessons_count`, `duration_label` (sum of lesson `duration_sec`, formatted), `rating` (avg reviews), `enrolled` (only when the request is authenticated), `type` (`is_center`→center/online), `grade` (from category).
**testimonials** by `config.source`: `latest` → recent reviews of the teacher's courses; `top_rated` → `rating >= min_rating`, by rating desc then recency — capped at `limit`.

Fixtures: `sample/tenant/landing.json` (resolved) · `sample/teacher/landing.json` (authoring). Layout rendering: [LANDING_LAYOUTS.md](LANDING_LAYOUTS.md).
