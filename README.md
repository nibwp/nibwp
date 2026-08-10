# NibWP

**Give your AI assistant real access to your WordPress site.**

Claude, ChatGPT, Cursor, Claude Code, Codex and anything else that speaks MCP can
read and write your site directly — draft posts, edit templates, build pages with
your page builder, run audits — over a connection you approve and can revoke.

The connection is between your site and your assistant. Nothing is brokered
through us: your site is its own authorization server, so this works behind a
VPN, on an intranet, and without anyone else holding a key to it.

---

## Install

Search for **NibWP** under **Plugins → Add New**, install, and activate.

To upgrade to Pro, or to add a single Skill, buy a licence at
[nibwp.com](https://nibwp.com) and paste the key into **NibWP → License**. The
matching add-on installs itself.

## Connect

### From the terminal — one command

```sh
npx nibwp auth login https://yoursite.com
```

Your browser opens, your site asks which permissions to grant, you approve. No
password anywhere, and you choose how much access to hand over — a connection
approved for reading cannot write, and the site is what enforces that.

Then point an editor at it:

```sh
nibwp agent add cursor        # or vscode, claude-code, codex, gemini-cli, …
nibwp discover "build a landing page"
```

Needs [Node.js](https://nodejs.org) 20 or newer. Source and full command list:
[github.com/nibwp/nibwp-cli](https://github.com/nibwp/nibwp-cli).

### From the admin screen

**NibWP → Connect** lists every supported assistant with the exact steps for
each — a button for the ones that support one, a config block for the ones that
do not, and a copy button beside all of it.

Once connected, ask the assistant to *discover abilities* and it will list what
your site can do.

---

## What you get

**Free** — the core connection and a broad set of abilities: posts, pages,
terms, users, media, options, search, site information, a per-site memory the
assistant can keep notes in, and the workflow library.

**Pro** — sandboxed PHP execution, file operations, and integrations with the
plugins you already run: Elementor, Bricks, Kadence, ACF, JetEngine, ACSS,
WooCommerce, FluentCRM and many more, plus security, migration and SEO toolkits.

**Skills** — add-ons that turn a screenshot, an HTML file, a URL or a Figma frame
into real, editable output in your page builder, checked against your design
system rather than improvised. Available for EtchWP, Bricks, Kadence, Elementor
and ACSS, among others.

Everything a Skill produces is validated before it is saved: naming grammar,
design tokens, layout rules. If it cannot be built correctly, you get told what
is wrong instead of a broken page.

---

## Permissions and safety

Five permissions, named in plain language on the approval screen, with the
far-reaching ones unticked by default:

| | |
|---|---|
| **Read your site** | View content, media, settings. Changes nothing. |
| **Create and edit** | Add and update content. Cannot delete. |
| **Delete and reorganise** | Deletions are not reversible from here. |
| **Read and write files** | Theme and plugin files, uploads, configuration. |
| **Run code** | Write and run PHP in the sandbox. The widest permission. |

Tokens are stored the way WordPress stores application passwords — hashed, so a
database dump yields nothing usable — and revoking one takes effect on the very
next request. Every connection is listed under **NibWP → Connect**, and you can
cut any of them off there.

An assistant with write access can change your site. Work on staging first, keep
backups, and grant only what the task needs.

---

## Requirements

- WordPress 6.8 or newer
- PHP 8.1 or newer
- An assistant that supports MCP, or the terminal client above

## Support

- Documentation and guides: [nibwp.com/docs](https://nibwp.com/docs)
- Changelog: [nibwp.com/changelog](https://nibwp.com/changelog)
- Questions and bug reports: [nibwp.com](https://nibwp.com)

## Licence

GPLv2 or later. See [LICENSE](LICENSE).
