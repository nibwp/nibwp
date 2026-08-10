# Convert a Page to EtchWP

Using **NibWP + all available integrations**, the **EtchWP Pro** skill, and the **ACSS Skills Pro** skill, turn whatever the user provides into a fully functional, production-ready, **pixel-perfect** WordPress build — native EtchWP, driven by a generated ACSS design system, every element editable. Source-agnostic: it works the same whether they **attach a file, paste HTML/markup, share a URL, drop a screenshot/image, link a Figma frame, or point at an existing builder page**.

## When to use
- "Convert this into a WordPress site / page with EtchWP + ACSS."
- Migrating from **Bricks / Elementor / Divi / WPBakery / Gutenberg** to EtchWP.
- Rebuilding a static **HTML/CSS** export, a **URL**, a **screenshot**, or a **Figma** design as a real, maintainable Etch build.

## Principles
- **Analyze before you touch anything.** No element gets built until the structure and design system are understood and a plan is approved.
- **Design system first.** Generate a complete ACSS configuration from the source and inject it — then everything binds to tokens.
- **Native + token-driven.** Native Etch elements only (form/video/nav-menu — never raw iframe/form/markup); every color/size/space is an ACSS variable with a `var()` fallback. No inline styles, no raw hex/px, no `clamp()` font hacks, no duplicated structures.
- **Reusable + dynamic.** Favor reusable components, dynamic templates, semantic HTML, and a maintainable architecture — everything stays fully editable in EtchWP.
- **Pixel-perfect across breakpoints.** Validate fidelity, responsiveness, accessibility, and performance at every stage, not just at the end.
- **Reversible.** Build to drafts / a staging area; keep the original recoverable until the user signs off.

## Process
1. **Detect the stack (preflight).** `nibwp/wp-get-site-info` + `nibwp/execute-php`: confirm **EtchWP** + **ACSS** are active, **ACF** + relevant CPTs, theme, breakpoints. Confirm the **EtchWP Pro** and **ACSS Pro** skills are available. Pull saved tokens/voice via `memory-recall`. (If the target builder is Bricks, switch to the **bricks-pro** skill for the build steps.)
2. **Scan & analyze the source.** Thoroughly read whatever was provided — structure, components, **design system, typography, spacing, colors, responsiveness, and interactions**. Identify sections, the single H1, repeated patterns, forms, navigation, media, and any dynamic/data-driven areas.
3. **Blueprint the site.** Produce a complete plan: **all pages, templates (header/footer/single/archive), blog structure, custom post types, taxonomies, custom fields, reusable components, and dynamic content areas.** **Present the execution plan and get approval before building** (defer the go/no-go + any live swap to the **Safe changes** workflow).
4. **Generate the ACSS design system.** Extract the design system into a **comprehensive ACSS configuration**: complete **color palette, typography scale, spacing system, containers, breakpoints, shadows, border-radius, transitions, responsive settings, and light/dark mode** support. **Inject it into ACSS options** (via the ACSS Pro skill / `execute-php`) and **validate** that all variables and tokens resolve and apply before continuing. Save the system to Memory.
5. **Build a master TODO list and track it throughout.** Include: setup, global styles, templates, components, headers, footers, forms, dynamic content, responsive adjustments, accessibility, SEO, schema markup, performance optimization, and final QA. Update it as you go.
6. **Build with EtchWP + ACSS best practices.** Through the EtchWP Pro skill (`html-to-component` / `url-to-component` / `image-to-component` / `figma-to-component` → `refine-component`), build section-by-section and template-by-template:
   - Reusable components + global classes; **no inline styles, no duplicated structures**.
   - Semantic HTML; one H1; logical heading order; native elements for forms/video/nav.
   - **Dynamic templates** + query loops for repeated/data-driven content (bind to the CPT/ACF from the blueprint).
   - Headers, footers, and global parts as reusable templates; every element editable in EtchWP.
7. **Validate at every stage.** Run the EtchWP Pro **hard validator** (rejects unknown elements, inline styles, hardcodes outside `var()`, missing global classes, static-where-dynamic, raw form/iframe/nav) → fix loop until clean. Check responsiveness, accessibility, and performance after each major section — don't defer it all to the end.
8. **Final parity + QA.** Compare the finished build against the source at **every breakpoint**: layout, type, spacing, color, content, links, images, interactions. The result must be **indistinguishable from the original** while clean, scalable, and maintainable. Run the SEO + pre-launch + performance workflows before sign-off.

## Rules
**Do**
- Analyze + **present an execution plan before building**; track the master TODO throughout.
- Generate + **inject + validate the ACSS configuration first**; bind everything to tokens.
- Use the **EtchWP Pro + ACSS Pro** skill pipelines; keep every element editable in EtchWP.
- Build to drafts; preserve attachment IDs, alt text, link targets, and SEO meta.

**Don't**
- Ship inline styles, raw hex/px, `clamp()` font hacks, or duplicated structures.
- Leave raw `<iframe>`, raw `<form>`, or pasted nav markup — native Etch elements only.
- Overwrite or delete the original before the user verifies parity and approves the swap.
- Change URLs/slugs without a 301 (defer to the redirects workflow).

## Validation
- ACSS config injected and **all tokens resolve** (palette, type, spacing, containers, breakpoints, shadows, radius, transitions, light/dark).
- EtchWP Pro hard validator passes with **zero** violations.
- **Pixel-perfect parity** with the source at mobile / tablet / desktop; no horizontal scroll; ≥44px tap targets.
- Accessibility (one H1, alt, contrast, focus, labels), SEO meta + schema, and Core Web Vitals checked.
- Everything reusable/dynamic where it should be; nothing hand-duplicated; all editable in EtchWP.

## Report
**Converted:** `<source>` → EtchWP (drafts: `<urls>`). **Blueprint:** N pages · templates · CPTs/taxonomies/fields · components. **ACSS system:** injected + validated (palette/type/spacing/breakpoints/shadows/radius/light+dark). **Build:** sections/templates via EtchWP Pro · loops: N · validator ✓ clean. **Parity:** ✓ at all breakpoints · a11y ✓ · SEO/schema ✓ · CWV ✓. **Original kept** pending your go to swap. Flag every judgment call (missing token, no data source for a loop, unsupported widget).
