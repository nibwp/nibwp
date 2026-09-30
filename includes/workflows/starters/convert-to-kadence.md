# Convert a Design to Kadence Blocks

Using **NibWP + the Kadence integration** and the **Kadence Pro** skill, turn HTML, a URL, or a screenshot into a **native, editable Kadence Blocks** page — where **design lives in block attributes** (Kadence renders the CSS), never in custom classes, external CSS, or `core/html`. Every element stays editable in the Kadence editor.

## When to use
- "Rebuild this in Kadence / convert this to Kadence Blocks."
- Turning a static HTML/CSS export, a URL, or a screenshot into a maintainable Kadence page, template, or pattern.
- Fixing a Kadence page that was built with custom classes + CSS, `core/html`, or blank-rendering blocks.

## The one law
> **Design lives in block ATTRIBUTES. Not in CSS. Not in custom classes. Not in `core/html`.**

Every Kadence block is a **dynamic block** — it compiles a scoped stylesheet from its attributes at render time (`.kt-adv-heading{uid}`, `.kt-row-layout-id{uid}`). So to style anything you **set the attribute** and Kadence writes the CSS; the client can still edit it. A custom class + your own CSS breaks editor sync and escalates into `!important`. Because it renders from attributes, authored pages render on the **front end with no editor save**.

**Never guess an attribute name** — a wrong name is silently ignored and renders nothing. Use `nibwp/kadence-pro-block-attributes` (reads the live registry) before authoring.

## Principles
- **Native blocks only.** Section = `kadence/rowlayout htmlTag:"section"` → `kadence/column` → content (`advancedheading`, `singlebtn` in `advancedbtn`, `iconlist`, `image`, `kadence/posts`). **No `core/html`** for cards, lists, or sections. Ever.
- **Attributes carry design** — typography, color, spacing, background, overlay, min-height, alignment. `fontSize` is `[desktop,tablet,mobile]` (never the legacy scalar `size`); `lineHeight`/`letterSpacing` are numbers; `overlay` is a color string.
- **`source:html` content in markup** — `advancedheading` content, `listitem` text, `infobox` text come from the block's inner HTML (author the text; attribute-only = blank).
- **Overlay on hero/CTA over image** = `currentOverlayTab:"gradient"` + `overlayGradient` + `overlayOpacity:100`. Never a CSS `::before`.
- **CSS storage order** — attribute → `_kad_blocks_custom_css` (Kadence's per-page Custom CSS, for post-loop `.entry-*`, third-party markup, `@keyframes`, `:hover` only) → **never** Customizer Additional CSS for page styling.
- **Dynamic + editable** — post grids → `kadence/posts`; client-editable rows (FAQ/testimonials/pricing) → an **ACF repeater** shown via a `core/shortcode` block. Reversible: build to drafts.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"kadence-pro" }` — confirm Kadence + Kadence Blocks active; answer brand, target mode (page/post/element/pattern), title. Import media, record attachment **IDs**, set the Kadence palette, and build header/footer as **Kadence Elements** (not page blocks).
2. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"kadence-pro" }` + `nibwp/kadence-pro-list-blocks` + `nibwp/kadence-pro-block-attributes { block }` — confirm real attribute names/types on THIS site.
3. **Analyze the source.** Sections, the single H1, columns, repeated cards (→ `kadence/posts`), forms, media. Map each to a native block.
4. **Build the tree** — real Kadence blocks; **design in attributes**; `source:html` text authored. `rowlayout htmlTag:"section"` (+ `uniqueID`, padding, minHeight) → `column` → content.
5. **Validate (dry-run).** `nibwp/kadence-pro-html-to-blocks { tree, target, dry_run:true, _preflight_token }`. Fix every `failed[]` (unknown block, `core/html`, illegal nesting, missing/duplicate uniqueID, `fontSize` scalar, overlay incoherence, blank source:html heading); heed `warnings[]` (div section, low overlay opacity, missing alt). Read the **score**.
6. **Persist.** Resubmit `dry_run:false`. Front end renders immediately (dynamic).
7. **Recovery-save (only if needed).** If the editor shows "Attempt Block Recovery", or you used icons / iconlist / `kadence/image` that need generated markup → run the authenticated recovery-save (references/recovery-save.md). Design is in attributes, so the save preserves it.
8. **Client-editable content → ACF.** Register the field group on the page; the repeater's `acf-field` post must have `post_parent` = the group post ID (else it renders empty in admin). Display via a `core/shortcode`. Verify with `acf_get_fields()`.
9. **Verify** at desktop / tablet(1024) / mobile(390): no horizontal overflow; heros dimmed + text readable; loops correct. Fix by adjusting **attributes** first.

## Rules
**Do** — route through the Kadence Pro pipeline; style with attributes; put `source:html` text in markup; use `_kad_blocks_custom_css` (via `nibwp/kadence-pro-custom-css`) for the rare non-attribute CSS; build to drafts.

**Don't** — use `core/html` for structure; add a custom class + CSS or `!important`; write the overlay as `::before`; put page CSS in Additional CSS; hand-write `<div class="kt-row-layout-overlay">`; use the legacy `size`; hard-duplicate post cards; reuse a `uniqueID`.

## Validation
- 0 validator failures; warnings reviewed. Every section a `<section>` (`htmlTag:"section"`); no `core/html`; all design as attributes; page-only leftovers in `_kad_blocks_custom_css`; nothing page-specific in Additional CSS.
- Heros: native gradient overlay, text readable. Loops: `kadence/posts`. Client rows: editable ACF repeater (`acf_get_fields()` verified).
- Recovery-save: **0 invalid blocks**, no recovery prompt. Responsive at 3 breakpoints; matches the design; client can edit any block's design without touching code.

## Report
**Converted:** `<source>` → Kadence Blocks (drafts: `<urls>`). **Build:** N `<section>`s · rowlayout→column · native content blocks · design in attributes. **Validator:** ✓ clean · **score:** `<grade>`. **CSS:** attributes (+ `_kad_blocks_custom_css` for `<what>`); nothing in Additional CSS. **Dynamic:** loops → kadence/posts; client rows → ACF. **Recovery-save:** 0 invalid. **Parity:** ✓ at 3 breakpoints. Flag every judgment call.
