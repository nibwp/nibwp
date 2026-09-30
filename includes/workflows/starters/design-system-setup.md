# Establish the design direction before you build

Using **NibWP's Design Skills**, derive a design direction from the site you already have — colors, type scale, spacing rhythm, radii, shadows — and agree it **before** a single section gets built. Every later build then references the same tokens instead of inventing values page by page.

## When to use
- Starting a new site, or a redesign, and you want one visual language rather than twelve.
- Before a run of page builds, so each one lands consistent.
- When a site has drifted — three greys, five spacing values, two type scales — and needs a single source of truth.

## The one law
> **Derive from the site, then agree it, then build.**

A direction invented per page is not a design system; it is a pile of coincidences. The point of doing this first is that every conversion afterwards has something to reference, and "which blue?" stops being a question anyone asks twice.

## Principles
- **Read before proposing.** The direction comes from what is actually installed and used — the active theme, the framework's tokens, existing global classes — not from a template.
- **Tokens with fallbacks, always.** `var(--text-l, 20px)`, `var(--space-l, 32px)`. A token that does not resolve on this site is worse than a literal, so every reference carries the literal behind it.
- **Never `clamp()` for font sizes.** Use the framework's text tokens with a px or em fallback.
- **Name the scale, not the value.** "Section spacing" survives a redesign; `48px` does not.
- **Agree it out loud.** Show the direction to the user and get a yes before it becomes the basis of ten pages.

## Process
1. **Derive.** `nibwp/design-direction` — reads the site and proposes a direction: palette, type scale, spacing rhythm, radii, shadows, and the tokens behind each.
2. **Review with the user.** Show the proposal. Ask what to keep, what to change, what the brand actually is. This is the step that stops a rebuild later.
3. **Record the decisions.** Store the brand prefix and the accepted brand colors so the builder skills' validators recognise them instead of flagging them as hardcoded literals.
4. **Check the framework.** If Automatic.css is active, confirm which token namespaces exist. If it is not, decide now: install it, bake literal fallbacks, or use the builder's own design-system variables — do not discover this halfway through a build.
5. **Build against it.** Every subsequent workflow — Bricks, EtchWP, Kadence, Elementor — references these tokens. Their validators enforce the token-with-fallback rule, so drift shows up as a failed check rather than as a page that looks slightly wrong.

## Definition of done
- A written direction exists and the user has agreed to it.
- Brand prefix and accepted brand colors are recorded, so builder validators stop flagging them.
- The token framework question is settled — which one, or explicitly none with literals baked.
- Every value in the direction is expressed as a token with a real fallback.
