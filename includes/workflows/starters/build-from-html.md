# Convert HTML / a URL to components

Turn pasted HTML, a Figma export, or a live URL into validated, token-driven builder components — not a dumped block of foreign markup. This kicks in whenever the source is code or a link rather than a flat image.

## When to use
- A user pastes raw HTML/CSS and wants it rebuilt natively in the site builder.
- A Figma "copy as HTML"/dev-mode export needs to become real components.
- "Recreate this page/section from this URL" — rebuild a live page in the current stack.

## Principles
- Detect the stack FIRST — builder (EtchWP vs Bricks), theme, and ACSS — before parsing anything.
- Map to REAL builder elements; never paste raw HTML into an HTML/code block as the final result.
- Strip inline styles and hardcoded values into ACSS tokens + global classes.
- Replace raw `<form>`, `<iframe>`, and hand-rolled nav with native builder elements.
- Repeated markup with dynamic data becomes a query loop, not duplicated nodes.
- Build and validate section-by-section; prefer drafts.

## Process
1. **Preflight / detect.** Run `nibwp/wp-get-site-info` + `nibwp/execute-php` to confirm builder, theme, ACSS, ACF, and CPTs. Confirm breakpoints and the ACSS palette/type/spacing tokens. `memory-recall` brand voice and tokens. If Bricks is active, use **bricks-pro**; otherwise **etchwp-pro**.
2. **Acquire the source.** For pasted HTML/Figma export, use it directly. For a URL, fetch the rendered markup and computed styles. If the source is local, read it via `nibwp/read-file`.
3. **Parse the DOM.** Break the source into logical sections. For each node, identify its semantic role and map to a real builder element: section, container, heading, text, button, image, icon, video, form, nav. Discard wrapper cruft that the builder structures natively.
4. **Extract styles into tokens.** Pull inline styles and stylesheet rules; convert colors → ACSS palette tokens, font sizes → ACSS text tokens (`var(--text-l, 20px)`, never `clamp()`), spacing → ACSS spacing tokens. Group repeated style sets into global classes. Reuse existing global classes before creating new.
5. **Replace non-native elements.** Swap raw `<form>` → native form element, `<iframe>` → native video/embed element, hand-rolled menu markup → native nav. Preserve href/labels/field names.
6. **Dedupe into loops.** Where identical blocks repeat over data (cards, posts, listings), collapse to one template bound to a query loop over a CPT/ACF source. If no source exists, propose a CPT/ACF schema for approval rather than hardcoding items.
7. **Build via the skill, section by section.** Run **etchwp-pro** / **bricks-pro** to emit each section as validated components with ACSS tokens and global classes. Use **acss** if tokens must be created/extended first.
8. **Validate (dry-run) → fix loop.** Run the hard validator. It rejects unknown elements, inline styles, hardcoded colors/sizes, missing global classes, and `clamp()` fonts. Fix each failure and re-validate until clean.
9. **Responsive + a11y + persist.** Verify mobile/tablet/desktop (no horizontal scroll, tap targets ≥44px), one H1, alt text, contrast, focus. Persist as a draft; do not publish/overwrite live without approval (defer to "Safe changes"). Store new tokens/voice via `memory-store`.

## Rules
**Do**
- Map every source node to a native builder element.
- Convert inline/hardcoded styles into ACSS tokens + global classes.
- Replace raw forms/iframes/nav with native elements.
- Collapse repeated, data-backed markup into a query loop.
- Preserve links, field names, and accessible labels from the source.

**Don't**
- Don't leave the final result as a raw HTML/code block.
- Don't carry over inline styles, hardcoded hex/px, or `clamp()` font sizing.
- Don't import a `<form>` or `<iframe>` verbatim.
- Don't hand-edit theme files — ship custom code as a NIBWP Sandbox file/snippet.
- Don't persist a section that fails the validator or publish over live content without approval.

## Validation
- Stack detected; ACSS/ACF/CPTs confirmed.
- No raw HTML/code blocks remain; every section uses native builder elements.
- All colors/type/spacing reference ACSS tokens with fallbacks; no `clamp()` fonts; no inline styles.
- Forms/iframes/nav replaced with native elements; links and field names preserved.
- Repeated data-backed blocks are query loops.
- Validator passes for every section; responsive + a11y checks pass.
- Output saved as draft; new tokens/voice stored in Memory.

## Report
Return: source type (HTML/Figma/URL) and stack detected; sections produced with the element mapping summary; styles converted to which ACSS tokens/global classes; non-native elements replaced; loops created with their data source; validator result per section; responsive + a11y status; draft location (page ID/URL); items needing approval (new CPT/ACF, publish, live overwrite).
