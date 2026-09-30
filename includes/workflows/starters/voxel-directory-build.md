# Build a Voxel Directory

Using **NibWP + the Voxel integration** and the **Voxel Pro** skill, take a listing type from nothing to a working directory: the fields people fill in, the filters people search by, and the four templates that decide what any of it looks like — preview card, single listing, archive, and a search page whose feed is actually connected to its form.

## When to use
- "Add a new listing type / directory section to this Voxel site."
- Rebuilding an existing post type's front end after its fields changed.
- A Voxel site where the templates were never built and everything renders as a bare WordPress page.

## The one law
> **Every key you name must be read from this site.** Post types, fields, filters and taxonomies are stored per install. Voxel renders a key it does not recognize as an empty string — no error, no warning, just a blank line on every listing.

Read them with `nibwp/voxel-info` and `nibwp/voxel-pro-catalog { post_type }`. A template built against remembered field names validates and renders empty.

## Principles
- **Data model first, templates second.** A card cannot bind to a field that does not exist yet. Fields and filters come before anything visual.
- **Schema changes are gated and reversible.** `nibwp/voxel-schema` is `mcp:manage`, previews before it writes, backs up what it replaces, and owes a reindex afterwards — run it until `complete`.
- **The card is the unit of design.** It renders in search results, feeds and map popups. Get it right and the rest of the site inherits it.
- **A feed's connection to its search form lives in post meta, as positions.** Never write those. `relations: "auto"` computes them; every refine recomputes them.
- **Dynamic tags everywhere the content belongs to a listing.** Hard-coded text in a template that renders once per listing is always wrong.
- **Assign deliberately.** Replacing a post type's main card changes every feed on the site at once; a named alternate does not.

## Process
1. **Read the site.** `nibwp/voxel-info` — post type keys, which modules are on, index health. Then `nibwp/voxel-post-types { action:"fields", post_type }` for what already exists.
2. **Design direction.** `nibwp/design-direction { purpose }` — color roles, type, spacing, and the generic defaults to refuse, before any visual decision.
3. **Fields and filters.** If the post type needs them: `nibwp/voxel-schema { action:"preview" }`, show the user the diff, then `{ action:"patch", confirm:true }`, then `{ action:"reindex" }` until `complete` is true. Skip entirely if the model is already right.
4. **Preflight.** `nibwp/skill-preflight { skill_id:"voxel-pro" }` — brand, kind, post type, title. Mints the token every build needs.
5. **Vocabulary.** `nibwp/load-skill-playbook { skill_id:"voxel-pro", element_type:"card" }` and `nibwp/voxel-pro-catalog { topic:"widgets", post_type }` — real widget names, real filter keys, real field keys.
6. **The preview card.** Build it first; everything else shows it. `nibwp/voxel-pro-build { template:{kind:"card", post_type}, elements, dry_run:true }`, fix every `failed[]`, then persist and assign `{post_type, pt_slot:"card"}`.
7. **The single listing.** `kind:"single-post"` — gallery, description, contact, hours, location, actions, reviews. Optional fields behind visibility rules. Assign `pt_slot:"single"`.
8. **The archive.** `kind:"archive"` — a search form and a feed for this post type, wired. Assign `pt_slot:"archive"`.
9. **The search page.** `kind:"search-page"` — form, feed, map. Leave `relations` at `"auto"` and check the dry run's reported wiring before writing.
10. **Content to see it with.** If the post type is empty, `nibwp/voxel-posts-write { action:"create" }` for two or three real listings — a directory with no listings cannot be judged.
11. **Verify.** Search returns results; a filter changes them; the map shows markers; a card links to its listing. Then `nibwp/voxel-pro-feedback { rating, kind }`.

## Rules
**Do** — read every key from the site; build the card first; leave relations on `"auto"`; use `nibwp/voxel-pro-refine` to change a template rather than rebuilding it; tell the user what each assignment replaced.

**Don't** — write `_elementor_data` or `_voxel_page_settings` through a generic post-meta ability; hand-write relation paths; invent widget or field names; assign a style kit to fix one popup; skip the reindex after a schema change; leave a feed sourced from a search form that is not on the page.

## Validation
- 0 validator failures on every build; warnings read rather than skimmed.
- Every dynamic tag resolves against a real listing — the build proves this, but check the ones it reports as empty.
- The search page's feed and map both resolve back to the form (the dry run reports the count).
- Card, single and archive all assigned; the response's `replaced` ids noted in case anything needs putting back.
- Front end checked at desktop and mobile: results render, filters work, cards link.

## Report
What was built, what each template was assigned to and what that replaced, which fields or filters were added to the data model, and anything left for the user to decide.
