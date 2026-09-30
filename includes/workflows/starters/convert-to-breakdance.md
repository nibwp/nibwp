# Convert a Design to Breakdance

Using **NibWP + the Breakdance integration** and the **Breakdance Pro** skill, turn HTML, a URL, a screenshot, or a Figma frame into a **native, editable Breakdance section** — real nodes in the builder, not markup pasted into the post body.

## When to use
- "Rebuild this in Breakdance / convert this to Breakdance."
- Turning a static HTML export, a live URL, a screenshot, or a Figma frame into a maintainable Breakdance page or section.
- Auditing a Breakdance page that renders but cannot be edited properly in the builder.

## The one law
> **A Breakdance page is a JSON node tree in post meta. It is not `post_content`.**

Writing HTML into the post body changes nothing a visitor sees. Every structural change goes through the tree. This is the single most common way a "successful" build produces a page that looks empty in the builder and unchanged on the front end.

## Principles
- **Real Breakdance elements only.** Read what this install actually registers with `nibwp/breakdance-pro-elements` before authoring — a node type Breakdance does not know is ignored, not refused.
- **Design lives in element settings**, so the site owner can still change it in the builder.
- **Tokens with fallbacks** — `var(--space-l, 32px)` — for every value that maps to a design-system token.
- **Reuse before you rebuild.** `nibwp/breakdance-pro-library` surfaces what already exists on the site; a saved block reused beats a fifth copy of the same card.
- **Conditions belong to Breakdance**, not to markup: `nibwp/breakdance-conditions` for display rules rather than duplicating sections.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"breakdance-pro" }` — confirms Breakdance is active and collects brand and target.
2. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"breakdance-pro" }`.
3. **Read the install.** `nibwp/breakdance-pro-elements` for the real node catalogue; `nibwp/breakdance-pro-library` for what is already built.
4. **Build the node tree** and submit with `nibwp/breakdance-pro-html-to-section` at `dry_run: true`. Fix everything the verdict names, then re-submit to commit.
5. **Audit.** `nibwp/breakdance-pro-audit` on the result — it checks the page against the rules rather than trusting that the write returned success.
6. **Feedback.** `nibwp/breakdance-pro-feedback`.

## Other entry points
- `nibwp/breakdance-pro-image-to-section` — a screenshot or mockup.
- `nibwp/breakdance-pro-figma-to-section` — a Figma frame.

## Definition of done
- The section exists as nodes in the Breakdance tree and opens correctly in the builder.
- Every node type came from the live element catalogue, not from memory.
- Styling is in element settings; nothing important lives in raw markup.
- The audit passed, and the page renders what the source showed.
