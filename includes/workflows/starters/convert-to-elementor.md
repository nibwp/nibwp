# Convert a Design to Elementor

Using **NibWP + the Elementor integration** and the **Elementor Pro** skill, turn HTML, a URL, or a screenshot into a **native, editable Elementor** page — real widgets with real control values, not an HTML widget with a stylesheet glued to it.

Works with both Elementor generations: classic widgets and **V4 atomic elements** (`e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-divider`, `e-tabs`, `e-div-block`, `e-flexbox`). The skill reads your install rather than shipping a fixed list, so whatever your site registers is what it builds with.

## When to use
- "Rebuild this in Elementor / convert this to Elementor."
- Turning a static HTML export, a URL, or a screenshot into a maintainable Elementor page or template.
- Repairing a page that opens blank or broken in the editor.

## The one law
> **Never guess a widget name or a control id.** A wrong one is silently ignored and renders nothing.

Call `nibwp/elementor-pro-list-widgets` and `nibwp/elementor-pro-widget-schema { widget }` before authoring. They read the live registry on **this** site, so Pro widgets, third-party add-ons and version differences are reflected exactly.

## Principles
- **Native widgets carry the design.** Set the widget's own controls — typography, spacing, colors, backgrounds — so the client can edit them in the panel. An `html` widget wrapping your markup is the thing to avoid, not the shortcut to reach for.
- **Containers, not legacy sections**, on any install where the flexbox container is active. The skill checks rather than assumes.
- **Atomic and classic differ, and the skill knows how.** Atomic widgets render as a bare tag with a `-base` class (`<h2 class="e-heading-base">`) instead of a `.elementor-widget-*` wrapper, and store values as `{ "$$type": …, "value": … }` rather than plain strings. Both are handled; you do not hand-write either.
- **Tokens and globals over literals.** Use Elementor's global colors and fonts where they exist so a brand change stays one edit.
- **Repetition becomes a loop**, not fifteen duplicated widgets.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"elementor-pro" }` — confirms Elementor (and Pro, which gates form / posts / loop / theme-builder widgets), and collects brand and target.
2. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"elementor-pro" }`.
3. **Read the registry.** `nibwp/elementor-pro-list-widgets`, then `nibwp/elementor-pro-widget-schema` for each widget you intend to use. Confirm real names and control ids before writing anything.
4. **Analyze the source.** Sections, the single H1, columns, repeated cards, forms, media. Map each to a real widget.
5. **Build and submit.** `nibwp/elementor-pro-html-to-page` with `dry_run:true`, fix what the verdict names, then re-submit with `dry_run:false`.
6. **Check the render.** The skill verifies the page actually drew itself — a marker plus real words — rather than trusting that the write succeeded.
7. **Feedback.** `nibwp/elementor-pro-feedback`.

## Other entry points
- `nibwp/elementor-pro-url-to-page` — a live URL.
- `nibwp/elementor-pro-image-to-page` — a screenshot or mockup.
- `nibwp/elementor-pro-get-structure` — read an existing page's tree before changing it.
- `nibwp/elementor-pro-repair` — a page that renders on the front end but will not open in the editor.

## Definition of done
- Every widget name and control id came from the live registry, not from memory.
- The design lives in widget controls; no `html` widget standing in for a section.
- The page opens cleanly in the Elementor editor and every element is selectable.
- The render check passed — the page drew a real marker and real words, not an empty shell.
