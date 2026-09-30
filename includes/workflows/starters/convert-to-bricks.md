# Convert a Design to Bricks

Using **NibWP + the Bricks integration** and the **Bricks Pro** skill, turn HTML, a URL, a screenshot, or a Figma frame into a **native, editable Bricks** template — where **design lives in element settings** (Bricks renders the CSS), never in a wall of custom CSS. Every element stays adjustable in the Bricks builder.

## When to use
- "Rebuild this in Bricks / convert this to Bricks."
- Turning a static HTML export, a live URL, a screenshot, or a Figma frame into a maintainable Bricks template.
- Fixing a Bricks page that was built as one big `_cssCustom` blob and cannot be edited in the builder.

## The one law
> **Styling lives in element SETTINGS. Custom CSS is the last resort.**

A page styled through `_cssCustom` renders correctly and is **inert in the builder**: the control panels are empty, the breakpoint switcher does nothing — Bricks generates its media queries from setting values it can see — and the site owner has to come back to an agent for a change they should make themselves.

Set `_padding`, `_margin`, `_typography`, `_background`, `_border`, `_width`, `_display`, `_gap`, `_aspectRatio`. The validator refuses any property Bricks has a control for that you write as CSS (`bricks_css_for_native_setting`) and names the setting to use instead.

`_cssCustom` is for what genuinely has no control: `:hover` and other states, `::before` / `::after`, descendant selectors.

## Principles
- **Real Bricks elements only.** `section` → `container` → `block`/`div` for layout; `heading`, `text-basic`, `button`, `image`, `icon` for content; `form`, `nav-menu`, `video` for interactive; `posts` for query loops. A name Bricks does not register renders nothing.
- **Global classes are bundles of settings**, not stylesheets. Anything used twice becomes a BEM-prefixed global class carrying `_padding`, `_background`, `_typography` — the same keys an element uses.
- **Tokens with fallbacks** — `var(--space-l, 32px)`, `var(--text-l, 20px)`. Tokens work inside settings exactly as in CSS, so nothing is lost by moving.
- **Never write `@media`** inside `_cssCustom`. Set per-breakpoint values in the same setting object; Bricks emits the media queries.
- **Forms are shortcodes**, not hand-rolled `<form>` markup. Detect the installed plugin with `nibwp/forms-manage` and wrap its shortcode.
- **Repetition becomes a query loop** — a `posts` element plus a CPT and fields, not fifteen copies of a card.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"bricks-pro" }` — confirms Bricks + ACSS, scans existing global classes for the brand prefix, and asks for brand, template type, and push mode. Its answers override whatever the payload says.
2. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"bricks-pro" }`. Read `references/element-settings.md` first — it carries the CSS-property-to-setting map — then the element catalog and the checklist for what you are building.
3. **Analyze the source.** Sections, the single H1, columns, repeated cards, forms, media. Map each to a real element.
4. **Build the tree** — a flat array of elements with parent/children references, design in settings, BEM global classes for anything reused.
5. **Validate (dry run).** `nibwp/bricks-pro-html-to-component { payload, dry_run:true, _preflight_token }`. Fix every `failed[]`; each carries a copy-paste `fix_hint`. Surface every `recommendations[]` entry to the user before committing.
6. **Commit.** Re-submit with `dry_run:false`. Header and footer templates are written to their own meta keys; `elements_saved` reports what actually landed.
7. **Feedback.** `nibwp/bricks-pro-feedback` — a thumbs-down reason feeds the next playbook load.

## Editing afterwards — do not re-run this workflow
Changing one setting on an element that already exists is a different job. Re-running the conversion replaces the whole tree and loses anything the new payload does not restate.

Use **`nibwp/bricks-update-element`**: read the tree, take the element's `id`, and send a `settings_patch` naming only the keys you are changing. Nested objects merge, `null` deletes a key, and `dry_run` defaults to true so you see the before/after first.

Element ids are unique **within** a post, not across posts — the same id names a different element on another page — so always pass the `post_id` you read the tree from.

## Other entry points
- `nibwp/bricks-pro-url-to-component` — a live URL.
- `nibwp/bricks-pro-image-to-component` — a screenshot or mockup.
- `nibwp/bricks-pro-figma-to-component` — a Figma frame.

All three land in the same validate-then-commit pipeline.

## Definition of done
- Every element is a real Bricks element and appears in the builder's structure panel.
- Every styled property that has a control is a setting; `_cssCustom` holds only states, pseudo-elements and descendants.
- Reused styling lives on BEM-prefixed global classes.
- Responsive values are per-breakpoint settings; no `@media` anywhere.
- The dry run passed with zero `failed[]`, and every recommendation was shown to the user.
