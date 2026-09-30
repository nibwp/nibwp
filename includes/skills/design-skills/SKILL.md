# Design Skills

You have been handed a design direction, or you are about to ask for one. This
explains what to do with it.

## The one rule

**Call `nibwp/design-direction` before you build anything.** Not after. Not if
the user mentions design. Before any page, section, hero, card or component
exists.

It answers, from this specific site, the questions you would otherwise guess at:
what colors, what fonts, what spacing, what shape language, what the page
should be made of, and which defaults to refuse. Guessing produces the page
every assistant produces. That page is why this skill exists.

Do not ask the user what colors they want. The ability reads the site's ACSS
variables, its `theme.json` palette, and its logo. It already knows.

## What comes back

```
brand    color roles, contrast ratios, corrections applied
type     heading font, body font, scale, and where each came from
space    scale, rhythm, measure
shape    radius, shadow, border language
layout   section sequence, hero shape, where the emphasis goes
motion   what may move
style    the visual style this sits in
rules    the generic defaults to refuse on THIS page, each with its reason
ux       a handful of UX rules that bear on this page
builder  how to express all of it in the builder this site uses
source   which decisions came from the site, which from the catalogue
```

## How to use it

**Colors.** Use the roles, not the raw hex. `primary` for the main action,
`on-primary` for text on it, `surface`/`on-surface` for the page, `muted` for
secondary text, `border` for separation, `accent` for the one thing that should
stand out. The contrast has already been checked and corrected — if you invent a
pair, you are undoing that work.

**Type.** If `type.source` is `site`, those fonts are already loaded: use them
and enqueue nothing. If it is `catalogue`, the pairing comes with a source URL.
Either way the scale is in `type.scale` — reference it by slug where the site
publishes slugs.

**Spacing.** `space.scale` is the site's own where it has one. `space.rhythm`
tells you where to be tight and where to be generous — a page with one rhythm
throughout has no hierarchy, which is half of why generated pages look flat.
`space.measure` is not negotiable: 60–75 characters per line for body text.

**Layout.** `layout.sections` is the sequence for this kind of page, in order.
`layout.hero` describes the shape the opening should take — read it, because it
is usually *not* the centred stack. `layout.emphasis` names the one thing the
page is for; that element gets the space and the weight.

**Rules.** `rules[]` is the important part. Each entry has what to refuse, what
to do instead, and why. Follow the reason rather than the letter — if a rule
says no three-card row because equal cards imply equal importance, and this page
genuinely has three equally important things, the reason tells you it is fine.

**Builder.** `builder{}` says how this site expresses a design: which token
system, which structure, what never to do. On Etch that means real elements and
ACSS tokens; on Kadence, block attributes keyed by uniqueID; on core blocks,
`theme.json` slugs. Never raw HTML pasted into a block on a site that has a
builder.

## Handing off

This skill decides. It does not build.

- On **Pro or Bundle**, pass the direction to the builder skill for the site —
  `etchwp-pro`, `bricks-pro`, `elementor-pro`, `kadence-pro` — and let it emit
  and validate the markup.
- On **Free**, build with core block abilities, referencing `theme.json` slugs
  from the direction rather than literal values.

## Consistency

The direction is remembered. A second page on the same site gets the same brand,
type, spacing and shape — that is what makes a site look designed rather than
assembled. Pass `fresh: true` **only** when the user explicitly asks for a
different look. Do not pass it because the last page felt samey; samey across
one site is the goal.

## What this never does

- Never returns markup
- Never touches content, posts, options a visitor sees, or files
- Never overrides a token the site already has with one it invented
- Never asks the user for a color it could have read

The one thing it stores is the direction itself, so the next page matches this
one. That is why it is governed as a write rather than a read.
