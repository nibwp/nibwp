# Build a Figma Design in WordPress

Using **NibWP + the Figma Pro skill**, turn a Figma file, frame, or component into a native WordPress build — in whichever builder the site actually uses.

The important part: the skill reads the **real Figma node tree and Variables**, not a screenshot of it. Spacing, type scale, colours and component structure come from the design data, so the result is measured rather than eyeballed.

## When to use
- Someone pastes a figma.com URL and wants it built.
- "Convert this Figma to WordPress", "build this frame in Etch / Bricks / Elementor".
- Turning a Figma library into a WordPress design system, or a Figma file into a theme's foundations.

## The one law
> **Read the design, don't guess it.** A screenshot gives you pixels; the node tree gives you the values the designer chose.

Figma Variables are the design tokens the design already has. Map them to the site's token system once, and every section built afterwards is consistent by construction instead of by eye.

## Principles
- **Detect the builder, don't assume it.** `nibwp/figma-pro-detect-builder` reads what the site runs, and the conversion targets that — Etch, Bricks, Elementor, Kadence or blocks.
- **Tokens first, sections second.** Establish the token mapping before converting frames, or the third section invents its own spacing scale.
- **Real components, not flattened copies.** A Figma component used five times becomes one reusable piece with five instances, not five pasted trees.
- **Images are assets.** Export and import them properly, and record the attachment IDs — a build referencing figma.com URLs breaks the moment the link expires.
- **Faithful, not creative.** Reproduce what the design says. Improvements are a separate conversation with the user.

## Process
1. **Connect and pick the source.** `nibwp/figma-list` and `nibwp/figma-get` to reach the file, frame, or component.
2. **Preflight.** `nibwp/skill-preflight { skill_id:"figma-pro" }` — brand, target, and the builder decision.
3. **Analyze.** `nibwp/figma-pro-analyze` reads the node tree and Variables and reports the structure, tokens and assets it found. Read this before building anything.
4. **Detect the builder.** `nibwp/figma-pro-detect-builder`, then load that builder's playbook — the Figma skill hands the normalized design to the builder skill rather than writing the builder's storage itself.
5. **Convert.** `nibwp/figma-pro-convert`, dry run first. Every validation rule of the target builder still applies.
6. **Check the result** against the frame — spacing, type scale, breakpoints.

## Definition of done
- The build went through the target builder's own validated pipeline, not a raw write.
- Figma Variables are mapped to real site tokens, with fallbacks.
- Repeated components are components, not copies.
- Images live in the media library with recorded IDs.
- The page matches the frame at the breakpoints the design defines.
