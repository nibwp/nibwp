# Build a full site from a brief

Stand up a complete multi-page WordPress site from a brief or sitemap: tokens first, then global styles, then page-by-page builds that reuse a shared system. This kicks in when the ask is a whole site, not a single page. It orchestrates the page- and section-level workflows behind approval gates.

## When to use
- "Build me a site for X" with a brief, content outline, or sitemap.
- Standing up multiple pages (home, about, services, contact, blog) in one engagement.
- Migrating/rebuilding an existing site's structure into the current builder.

## Principles
- Approval gates between phases — sitemap, tokens, and first page each get sign-off before scaling.
- Consistency and reuse over novelty: one token system, one set of global classes/components, reused everywhere.
- Detect the stack FIRST; build on EtchWP+ACSS (or Bricks) as installed — never assume.
- Tokens, not hardcodes: ACSS palette/type/spacing with `var()` fallbacks, never `clamp()` fonts.
- Drafts by default; nothing goes live without explicit approval.
- Don't reinvent per-page work — delegate to the page and screenshot/HTML workflows.

## Process
1. **Preflight / detect.** Run `nibwp/wp-get-site-info` + `nibwp/execute-php` to confirm builder, theme, ACSS, ACF, CPTs, and existing pages. `memory-recall` any brand voice/tokens. Decide EtchWP (**etchwp-pro**) vs Bricks (**bricks-pro**).
2. **Brief → sitemap (GATE).** From the brief, produce a page list with each page's purpose, key sections, and primary CTA. Identify needed CPTs/ACF (e.g. services, team, posts). Get explicit approval on the sitemap before building.
3. **Design tokens (GATE).** Define the ACSS system — palette, type scale, spacing scale — derived from the brief/brand. Use the **acss** skill to create/extend tokens. Confirm with the user, then `memory-store` the palette, type, spacing, and brand voice so every page stays consistent.
4. **Global styles + reusable system.** Establish global classes and reusable components/blocks (cards, buttons, section wrappers) on top of the tokens. These are the building blocks every page reuses.
5. **Page-by-page build.** For each page in the sitemap, follow the "Page build standard" workflow; when a page is driven by a screenshot/mockup use "Build from a screenshot", and for HTML/URL sources use "Convert HTML / a URL to components". Reuse the global classes/components from step 4 — only create new patterns when none fit. Build pages as drafts.
6. **Global header/nav + footer.** Build the site header/navigation and footer once as global/reusable elements (native nav element, not raw markup) and apply across all pages. Wire the primary menu.
7. **Forms.** For contact/lead forms, defer to the "Contact form" workflow — native form element, proper labels, spam protection, and a tested submission path. Never embed a raw `<form>`.
8. **Internal links + content wiring.** Connect pages: nav links, in-content cross-links, CTAs to the right destinations, and CPT/ACF query loops bound to real data. Verify no orphan pages and no broken/placeholder links.
9. **QA + pre-launch (GATE).** Run the QA and pre-launch workflows: per-page validator pass, responsive (mobile/tablet/desktop), accessibility (one H1/page, alt, contrast, focus), performance (optimized + lazy images), and link integrity. Fix findings, then get approval before any publish/launch (defer to "Safe changes").

## Rules
**Do**
- Get approval at each gate: sitemap, tokens, first page, pre-launch.
- Define tokens and global classes/components once; reuse them on every page.
- Store the token system and brand voice in Memory.
- Reuse existing pages/blocks before creating new ones.
- Build header/footer/nav as single global elements.

**Don't**
- Don't start building pages before the sitemap and tokens are approved.
- Don't hardcode colors/px or use `clamp()` fonts — go through ACSS tokens.
- Don't duplicate styles per page instead of using global classes.
- Don't hand-edit theme files — ship custom code as a NIBWP Sandbox file/snippet.
- Don't publish or launch without QA passing and explicit approval.

## Validation
- Sitemap, tokens, and pre-launch each approved by the user.
- One ACSS token system + one set of global classes/components used site-wide.
- Every page built as a draft and passes its per-page validator.
- Global header/nav + footer applied consistently; primary menu wired.
- Forms native, labeled, and tested; loops bound to real CPT/ACF data.
- Internal links complete — no orphans, no placeholders, no broken links.
- Responsive, accessibility, and performance checks pass on every page.
- Token system + brand voice stored in Memory.

## Report
Return: stack detected; approved sitemap (page list); ACSS token system created (palette/type/spacing) and where stored; global classes/components built; per-page build summary (workflow used, reused vs new patterns, draft IDs/URLs); header/footer/nav status; forms built; internal-link/loop wiring; QA results (validator, responsive, a11y, performance, links); outstanding items and what awaits approval before launch.
