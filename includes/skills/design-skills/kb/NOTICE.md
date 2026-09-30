# Knowledge base — where each file came from

## Third-party, MIT licensed

These six tables are the work of **Next Level Builder**, taken from the
`ui-ux-pro-max` skill and redistributed here under the MIT license. The full
license text is in `LICENSE` in this directory and travels with any copy of this
plugin.

| File | Rows | What it holds |
|---|---|---|
| `styles.csv` | 84 | Visual styles — keywords, palettes, effects, what each suits |
| `colors.csv` | 160 | Role-based palettes per product type |
| `typography.csv` | 73 | Font pairings with moods and sources |
| `products.csv` | 161 | Product types mapped to styles and page patterns |
| `ux-guidelines.csv` | 98 | UX rules with do / don't examples |
| `ui-reasoning.csv` | 161 | Pattern and anti-pattern reasoning per UI category |

**Unmodified.** They are read as shipped. Nothing in NibWP edits these files, so
an upstream update is a straight replacement.

**Not taken:** the sixteen per-stack tables (React, Next.js, Vue, Svelte,
Angular, Astro, Nuxt, Flutter, SwiftUI, Jetpack Compose, React Native, Three.js,
Tailwind, shadcn, Laravel), the templates and the sync scripts. None of them
describes a WordPress site, and shipping code we cannot use would be 1.4 MB of
dead weight.

## Ours

Everything in `wordpress/` is NibWP's own work, written for this plugin:

| File | What it holds |
|---|---|
| `wordpress/layout-patterns.csv` | Section sequences per page purpose, expressed as builder structure |
| `wordpress/builder-notes.csv` | How a direction is expressed in Etch, Bricks, Elementor, Kadence, Gutenberg |
| `wordpress/anti-generic.csv` | The defaults we refuse, each with the reason it exists |

## Why both

The third-party tables carry design taste that is true regardless of platform —
which fonts pair, which palettes suit which trade, which UX rules matter. What
they cannot know is WordPress: block markup, builder element trees, ACSS tokens,
`theme.json`. That half is ours, and it is the half that decides whether a
direction can actually be built on the site in front of it.
