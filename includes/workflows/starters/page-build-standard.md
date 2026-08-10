# Page build standard

The baseline standard for building any page or section with the site's page builder: builder-agnostic, token-driven, sectioned, responsive, accessible, and performant. Every other build workflow leans on this one. It kicks in whenever you're constructing UI in the builder.

## When to use
- Building or extending any page or individual section in the page builder.
- The shared baseline invoked by "Full site build", "Build from a screenshot", and "Convert HTML / a URL to components".
- Adding a new section to an existing page while keeping it on-system.

## Principles
- Detect the builder, theme, and CSS framework FIRST — never assume the stack.
- Design tokens + global classes only; no one-off hardcoded colors, sizes, or spacing.
- Semantic, sectioned structure: build in discrete sections with logical heading order.
- Responsive by construction: mobile, tablet, and desktop all correct — no horizontal scroll.
- Accessible and performant from the start, not as an afterthought.
- Reuse existing patterns/global blocks before creating anything new.

## Process
1. **Detect the stack.** Run `nibwp/wp-get-site-info` + `nibwp/execute-php` to confirm the active builder (EtchWP/Bricks), theme, and whether ACSS is installed; check ACF/CPTs. `memory-recall` brand voice and design tokens. Pick the skill: **etchwp-pro** (EtchWP) or **bricks-pro** (Bricks).
2. **Plan the sections.** Break the page into ordered sections (hero, content blocks, CTA, etc.). Decide the heading hierarchy up front: exactly one H1, then logical H2/H3 nesting — no skipped levels.
3. **Reuse first.** Before creating anything, check for existing global classes, components, or reusable blocks that fit. Prefer reuse; extend rather than duplicate.
4. **Build section-by-section with tokens.** For each section, build with real builder elements and apply ACSS tokens: colors from the palette, font sizes via text tokens (`var(--text-l, 20px)`, never `clamp()`), spacing via spacing tokens. Repeated style sets become global classes. Use the **acss** skill if a needed token doesn't exist yet. Use native elements for nav/forms/video/embeds — never raw `<iframe>`/`<form>`.
5. **Bind data where dynamic.** If a section repeats over data, build one template on a query loop over a CPT/ACF source rather than duplicating nodes.
6. **Responsive pass.** Check each section at mobile, tablet, and desktop: no horizontal scroll, readable type at every breakpoint, tap targets ≥44px, images and grids reflow cleanly.
7. **Accessibility pass.** Confirm one H1 and logical heading order, alt text on all images, sufficient color contrast, visible focus states, and proper labels on inputs/controls.
8. **Performance pass.** Use optimized, appropriately sized images with lazy loading; avoid a heavy background video by default; keep DOM lean. Ship any custom code as a NIBWP Sandbox file/snippet, never in theme files.
9. **Validate → fix → persist.** Run the hard validator (rejects unknown elements, inline styles, hardcoded colors/sizes, missing global classes, `clamp()` fonts). Fix each failure and re-validate until clean, then persist as a draft. Don't publish/overwrite live without approval (defer to "Safe changes"). `memory-store` any new reusable tokens/classes.

## Rules
**Do**
- Detect builder/theme/framework before building.
- Use ACSS tokens and global classes for every visual value.
- Maintain one H1 and a logical heading order.
- Reuse existing global blocks/components before creating new.
- Use native elements for forms, nav, video, and embeds.

**Don't**
- Don't hardcode colors/px or use `clamp()` for font sizing.
- Don't create one-off styles when a global class fits.
- Don't produce horizontal scroll or sub-44px tap targets.
- Don't hand-edit theme files — use a NIBWP Sandbox file/snippet.
- Don't persist a section that fails the validator or publish over live content without approval.

## Validation
- Builder/theme/framework detected and confirmed.
- All colors/type/spacing reference ACSS tokens with fallbacks; no inline styles; no `clamp()` fonts.
- Reused existing global classes/components where they fit.
- Exactly one H1 with logical heading order; alt text, contrast, focus, and labels pass.
- Responsive at mobile/tablet/desktop; no horizontal scroll; tap targets ≥44px.
- Images optimized + lazy-loaded; no unnecessary background video.
- Validator passes for every section; output saved as draft.

## Report
Return: builder/theme/framework detected; sections built and which reused existing global blocks vs new; ACSS tokens/global classes used or created; any query loops and their data source; responsive + a11y + performance checklist status; validator result per section; draft location (page ID/URL); items needing approval (publish, live overwrite, new CPT/ACF).
