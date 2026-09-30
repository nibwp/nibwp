# Design a Voxel Preview Card

Using **NibWP + the Voxel integration** and the **Voxel Pro** skill, design the card a listing shows up as — in search results, in feeds, and in map popups. One template, rendered once per listing, bound to that listing's own data.

## When to use
- "Make the listings look better / redesign the cards."
- A post type whose card is Voxel's default and shows the wrong things.
- Needing a second card for one context — a compact one for the map popup, a wider one for a dashboard — without changing the main one.

## The one law
> **A card with hard-coded text is the same card for every listing.** Everything that varies per listing is a dynamic tag, bound to a field key read from this site.

Voxel renders an unrecognized key as an empty string. A misspelled field name gives a blank heading on every card and says nothing about it.

## Principles
- **Read the fields, don't remember them.** `nibwp/voxel-pro-catalog { post_type }` returns this post type's real field and filter keys.
- **The card does not control its width.** It renders in a grid cell that might be one column or four. Nothing inside may assume a size.
- **Optional fields need a fallback or a visibility rule.** Half the listings will not have filled everything in, and a gap in a grid reads as a bug.
- **Main card or named alternate is a real decision.** Replacing the main card changes every feed on the site; an alternate is chosen per feed with `ts_card_template__{post_type}`.
- **`ts-advanced-list` is the actions row** — verified badge, rating, distance, opening status, follow, share. It is what makes a Voxel card a Voxel card.

## Process
1. **Read the site.** `nibwp/voxel-info`, then `nibwp/voxel-pro-catalog { topic:"widgets", post_type }` for the field keys, the existing custom cards, and which template is currently the main one.
2. **Design direction.** `nibwp/design-direction { purpose }` — color, type and spacing decided against this site rather than invented.
3. **Look at what exists.** `nibwp/voxel-pro-refine { template_id }` with no operations returns the current card's outline. If the change is small, stop here and refine it instead of building a new one.
4. **Preflight.** `nibwp/skill-preflight { skill_id:"voxel-pro" }`.
5. **Playbook.** `nibwp/load-skill-playbook { skill_id:"voxel-pro", element_type:"card" }` — the card checklist and the dynamic tag reference.
6. **Compose.** Image, title, and an actions row. Title and image link to `@post(:url)`. Rating from `:reviews.average` with a fallback; address from the location field; opening status from the work hours field.
7. **Dry run.** `nibwp/voxel-pro-build { template:{kind:"card", post_type, title}, elements, dry_run:true }`. Every tag is rendered against a real listing and reported — read what each one produced, not just whether it passed.
8. **Write and assign.** `dry_run:false` with `assign`. `{post_type, pt_slot:"card"}` replaces the main card; add `mode:"custom", label:"…"` for an alternate.
9. **See it.** Open a search page or archive for that post type. Check one, two and three columns.
10. **Record.** `nibwp/voxel-pro-feedback { rating, kind:"card", reason? }`.

## Rules
**Do** — bind the title, image, link and every varying value with dynamic tags; give optional fields a fallback or a visibility rule; ask the user whether to replace the main card before doing it; keep the replaced template id.

**Don't** — put a search form or a map inside a card; assume a fixed width; use full-size images in a grid; guess a field key; write the template meta directly.

## Validation
- 0 validator failures; every reported dynamic tag renders something sensible against the test listing.
- The card links to its listing.
- It reads correctly at one, two and three columns.
- Listings missing an optional field still look intentional.
- The user knows what was assigned and what it replaced.

## Report
What the card shows, which fields it binds, where it was assigned, and what it replaced.
