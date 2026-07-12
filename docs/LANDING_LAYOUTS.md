# Landing layouts (frontend rendering guide)

> Data contract & section schemas are authoritative in [LANDING_CONTRACT_V2.md](LANDING_CONTRACT_V2.md).
> This file only describes how the three layouts render that same data.

`GET /tenant/landing` → `{ layout, nav, sections }`. Pick the template by `layout`;
render `sections` in `order`. All three layouts render every section type
(`hero, stats, features, about, steps, courses, testimonials, packages, cta,
contact`); dynamic `courses`/`testimonials` arrive with resolved `items`.

## `classic` — stacked
- Full-width **hero** with the teacher card + chips; sections stacked vertically, alternating background tint.
- `courses`: 3-up card grid · `testimonials`: horizontal carousel · `packages`: pricing columns · `steps`: numbered horizontal row.

## `grid` — split / dense
- **hero** split: copy column + teacher-image column; a sticky side rail mirrors `nav.links`.
- `courses`: 2-up cards with more metadata (rating, students, duration) · `features`/`stats`: tile grid · `about`: image-left / body-right.

## `spotlight` — minimal / centered
- **hero** text-only, centered, generous whitespace (ignore heavy imagery).
- Narrow centered column, typographic dividers · `courses`: 1-up list · `testimonials`: one quote per row · `steps`: vertical timeline.

**Rules for all layouts:** render sections in received order; treat any content
field as optional (hide if empty); a dynamic section with `items: []` shows an
empty state, not an error; build the top nav from `nav.links`. Adding a new
section type later = new schema server-side + a renderer in each layout, no
response-shape change.
