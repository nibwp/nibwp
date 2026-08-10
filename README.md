# NIBWP

**Model Context Protocol server for WordPress.** Lets AI agents (Claude, ChatGPT, Cursor, Claude Code) read + write WordPress through a signed REST endpoint. Includes a premium Skill marketplace for design-system-aware page-builder workflows (EtchWP, Bricks, ACSS).

---

## What it does

Three layers stacked on top of each other:

1. **Free / wp.org core** — bundles the WordPress MCP adapter and exposes ~40 read-only abilities: posts, terms, users, media, options, search, file-read, directory-list, per-site memory store. Authenticated via WP Application Passwords. No external network calls.
2. **Pro overlay** — adds ~80 more abilities: sandboxed PHP execution, file ops, premium integrations (Elementor, Bricks, EtchWP, ACF, JetEngine, ACSS, FluentCart, FluentCRM, …), security/migration/SEO toolkits.
3. **Skills** — premium add-ons that turn HTML / URL / image / Figma into validated builder output:
   - **EtchWP Pro** — etch/element + etch/component + etch/condition with ACSS tokens
   - **Bricks Pro** — Bricks templates with global classes + dynamic data + Query Loops
   - **ACSS Pro** — generates the design system itself (palette, type ramp, space ramp) from a screenshot

Skills follow a `v2 routing contract`: `mandatory_routing` field in discover → `skill-preflight` mints a token → `html-to-component` validates + persists. Improvisation is structurally blocked (validators reject; tokens enforce 3-attempt budgets; cross-skill brand prefix shared).

---

## Quick start

### Install on your site

| Distribution | When | How |
|---|---|---|
| **wp.org Free** | Anyone — minimal abilities, no skills | `wordpress.org/plugins/nibwp` (pending submission) → Install → Activate |
| **Free zip** (from `dist/`) | Pre-wp.org access; includes Skills marketplace cards | Upload `dist/nibwp-free-1.0.0.zip` via Plugins → Add New |
| **Pro zip** | Paid customer | Upload `dist/nibwp-pro-1.0.0.zip`; license activates the premium overlay + auto-installs unlocked skills |
| **Skill standalone** (e.g. EtchWP only) | Customer with one specific skill | Upload `dist/nibwp-skill-etchwp-pro-1.0.0.zip`; auto-installs alongside Free |

### Connect from the terminal (fastest)

```sh
npx nibwp auth login https://yoursite.com
```

Opens the browser, shows the site's consent screen, stores a scoped OAuth grant.
No password anywhere. Then `nibwp agent add cursor` (or `vscode`, `claude-code`,
`codex`, …) writes the connection into that editor, and `nibwp mcp` bridges
stdio-only clients over the same grant.

Source: [github.com/nibwp/nibwp-cli](https://github.com/nibwp/nibwp-cli), published
to npm as `nibwp`. Requires Node 20+ and a site running this plugin with AI
Abilities on.

### Connect an AI client by hand

1. **Plugins → Add New** → install **NIBWP**.
2. **NIBWP → Connect** → click **Generate password**. Copy the one-shot password.
3. Add to your AI client:
   - **Claude Code (CLI):** `claude mcp add nibwp https://yoursite.com/wp-json --header "Authorization: Basic $(echo -n 'user:APP_PW' | base64)"`
   - **Claude desktop:** add an entry to `mcpServers` in the config: `{ "url": "https://yoursite.com/wp-json", "headers": { "Authorization": "Basic <base64>" } }`
   - **Cursor / other MCP clients:** same shape; see your client's MCP config docs.
4. In the AI client: `Discover abilities` → confirm `nibwp_instructions` + `abilities[]` are returned.

### Run a Skill (Pro only)

User to AI: *"convert this HTML to EtchWP"*

Behind the scenes:
1. Agent calls `nibwp/skill-preflight { skill_id: "etchwp-pro" }`
2. Server asks user: brand prefix, target post, push mode, ACSS decision
3. Agent receives `_preflight_token`
4. Agent builds the Etch payload (block tree + styles + components)
5. Agent calls `nibwp/etchwp-pro-html-to-component { payload, _preflight_token, dry_run: true }`
6. Server validates (BEM grammar, ACSS tokens, no clamp() font-size, etc.) + returns `recommendations[]` (loop → CPT+ACF, iframe → etch/embed, raw form → forms-manage, etc.)
7. Agent surfaces recommendations to user
8. After user-confirmed fixes, agent re-submits with `dry_run: false`
9. Server persists the component into `wp_options['etch_styles']` + the target `post_content`

Same flow for Bricks Pro + ACSS Pro skills, validators tuned to each builder.

---

## Repo layout

```
nibwp/
├── nibwp.php                           # Main plugin entry. Registers ability categories,
│                                        # loads helpers + abilities + premium overlay.
├── includes/
│   ├── abilities/                      # ~40 Free abilities + a few premium ones
│   │   ├── discover-abilities.php      # MCP entry — emits mandatory_routing + skills index
│   │   ├── skill-preflight.php         # v2 routing — token mint/consume
│   │   ├── load-skill-playbook.php     # Lazy playbook reader
│   │   ├── preferences.php             # nibwp_user_defaults store
│   │   ├── memory.php / read-file.php / list-directory.php / wordpress-core.php
│   │   └── ...
│   ├── premium/                        # Pro-only — stripped from Free + wp.org
│   │   ├── bootstrap.php               # Loads the premium runtime
│   │   ├── integrations/               # Elementor, Bricks, EtchWP, ACF, …
│   │   ├── toolkits/                   # Security, Notifications, Migration, SEO, …
│   │   └── ...
│   ├── skills/
│   │   ├── registry.php                # Skill discovery + entitlement gating
│   │   ├── etchwp-pro/                 # EtchWP Pro skill (v2 contract)
│   │   │   ├── manifest.php            # Triggers, commands, mandatory_routing, preflight Qs
│   │   │   ├── abilities/              # html/image/figma/refine/feedback
│   │   │   ├── lib/                    # validator, persister, loop-detector, recommender
│   │   │   └── etchedy-authoring/      # SKILL.md + references + per-element checklists
│   │   ├── bricks-pro/                 # Bricks Pro skill (mirrors etchwp-pro)
│   │   └── acss-pro/                   # ACSS Pro skill (palette/type/space generation)
│   ├── license.php                     # Entitlement + license activation client
│   ├── license-page.php / lock-popup.php / skills-page.php   # UI
│   ├── user-defaults.php               # nibwp_user_defaults helpers (cross-skill cache)
│   └── ...
├── server/
│   └── nibwp-license-server/           # nibwp.com-side plugin (FluentCart License Manager glue)
├── build/
│   ├── build-free.sh                   # Free zip — strips premium/, skills/abilities, skills/lib
│   ├── build-pro.sh                    # Pro overlay zip
│   ├── build-skill.sh                  # Per-skill standalone zip
│   ├── build-wporg.sh                  # wp.org submission — inlines minimal entry + abilities
│   └── build-all.sh                    # Run all four
├── dist/                               # Generated zip artifacts
├── tests/
│   └── skills/triggers.test.php        # Trigger-regex fixture (positive + negative phrases)
├── docs/
│   ├── skill-author-guide.md           # How to add a new v2 skill
│   └── troubleshooting.md              # Preflight token errors, validator rejections, etc.
└── README.md                           # This file
```

---

## v2 Skill contract — what every skill ships

Every skill under `includes/skills/<skill-id>/` follows the same shape:

| File | Required | Role |
|---|---|---|
| `manifest.php` | yes | Returns array with `id`, `name`, `triggers[]`, `commands{}`, `mandatory_routing{}`, `preflight_questions[]`, `ability_files[]`, `requires[]`, `entitlements[]` |
| `abilities/*.php` | yes | One per registered ability. Top-level `wp_register_ability(...)` call |
| `lib/validator.php` | yes | Pure-PHP rule enforcement. Returns `{passed, failed[{id,msg,path,fix_hint}], warnings[]}` |
| `lib/persister.php` | yes | Writes the validated payload to its destination (options, post meta, custom table) |
| `lib/loop-detector.php` | optional | When the skill cares about repeating patterns |
| `lib/orchestrator-recommender.php` | optional | Cross-ability suggestions (loop → CPT, iframe → embed, etc.) |
| `lib/element-registry.php` | optional | Whitelist of valid element names for builder skills |
| `authoring/SKILL.md` | yes | Full playbook — agent reads on `load-skill-playbook` |
| `authoring/references/*.md` | optional | Tokens, anti-patterns, dynamic-data tags, breakpoints |
| `authoring/references/checklists/*.md` | optional | Per-element checklists (button, hero, form, …) |

See [`docs/skill-author-guide.md`](docs/skill-author-guide.md) for the step-by-step.

---

## Skill features matrix (current)

| Capability | EtchWP Pro | Bricks Pro | ACSS Pro |
|---|---|---|---|
| HTML → builder | yes | yes | n/a |
| Image / screenshot → builder | yes (agent vision) | yes (agent vision) | yes |
| URL → builder | agent fetches | yes (fetch + sanitize) | yes |
| Figma → builder | stub | stub | stub |
| Loops (dynamic CPT) | `etch/loop-block` | `posts` Query Loop | n/a |
| Components with props | `etch/component` + properties + slots | pseudo-component: section template + CPT + ACF | n/a |
| Conditions | `etch/condition` block | element `_conditions` array | n/a |
| Per-element checklists | 7+ | 20 | n/a (preflight questions cover groups) |
| Recommender categories | 6 | 9 | (validator-driven) |
| Validator rules | 12+ hard rejects + warnings | 12 hard rejects + warnings | 7 hard rejects + warnings |
| Pricing | €49 / €99 / €199 / €299 yr | €49 / €99 / €199 / €299 yr | €19 / €39 / €49 / €69 yr (entry tier) |

---

## Pricing

| Sites | Pro yr | Pro LTD | Bundle yr | Bundle LTD | EtchWP / Bricks Skill yr | ACSS Skill yr |
|---|---|---|---|---|---|---|
| 1 | €49 | €129 | €129 | €329 | €49 | €19 |
| 5 | €99 | €249 | €199 | €499 | €99 | €39 |
| 25 | €199 | €499 | €299 | €749 | €199 | €49 |
| 100 | €299 | €749 | €499 | €1249 | €299 | €69 |

LTD = `round(yearly × 2.5)` rounded UP to nearest 9-ending. ACSS Skill is the introductory-tier add-on (cheaper yearly ladder, same 2.5× LTD multiplier rounded to ≈2.6×).

[`server/nibwp-license-server/INSTALL.md`](server/nibwp-license-server/INSTALL.md) has the full variation matrix for FluentCart.

---

## Build the zips

```bash
bash build/build-all.sh              # Free + Pro + wp.org + all skills
bash build/build-free.sh
bash build/build-pro.sh
bash build/build-skill.sh etchwp-pro # or bricks-pro / acss-pro
bash build/build-wporg.sh            # wp.org submission
```

Output goes to `dist/`. Each script verifies its own output (lint + leak-grep + size).

---

## Development

### Requirements

- WordPress 6.9+ (Free uses `wp_register_ability` from core 6.9; Pro keeps the polyfill from `vendor/wordpress/abilities-api`)
- PHP 8.0+
- For Skill work: a local site with both Etch + Bricks + ACSS active, plus the Pro license activated

### Tests

```bash
php tests/skills/triggers.test.php    # Trigger-regex fixtures (50/50 assertions)
```

### Lint

Per-file PHP lint:
```bash
php -l includes/skills/etchwp-pro/lib/validator.php
```

There is no PHPUnit suite (yet) — tests are plain PHP scripts in `tests/`.

---

## Troubleshooting

See [`docs/troubleshooting.md`](docs/troubleshooting.md). Covers:

- "Skill not appearing in `mandatory_routing[]`" — entitlement + deps_met checks
- "Preflight token invalid / expired / attempts_exhausted" — flow corrections
- "Validator rejects my payload" — reading `unchecked_items[].fix_hint`
- "Pro abilities locked but license is active" — entitlement cache invalidation
- "wp.org build fails leak-grep" — premium symbol leakage
- "MCP route 404 after plugin update" — active_plugins desync

---

## License

GPLv2 or later. See [`LICENSE`](LICENSE).
