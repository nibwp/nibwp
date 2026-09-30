# NibWP

**Give your AI assistant real access to your WordPress site.**

Claude, ChatGPT, Cursor, Claude Code, Codex — anything that speaks MCP — can read
and write your site directly: draft posts, edit templates, build pages with your
page builder, audit what is there. Over a connection you approve, can see, and
can cut off.

The connection is between your site and your assistant. Nothing is brokered
through us: your site is its own authorization server, so this works behind a
VPN, on an intranet, and without anyone else holding a key to it.

---

## Install

Search for **NibWP** under **Plugins → Add New**, install, activate. Then open
**NibWP → Connect** and switch on **AI Abilities**.

That is the whole free plugin. No account, no key, no external service.

---

## Connect your assistant

**NibWP → Connect** lists every supported client with the exact steps for that
one — a button where the client supports one, a config block where it does not,
and a copy button beside all of it. Pick your route:

### Assistants that run in the browser

**Claude.ai** and **ChatGPT** connect from their own settings. The Connect screen
gives you a button that opens Claude with the connector pre-filled, and the
address plus the click-path for ChatGPT's developer mode.

These connect from the vendor's cloud, so they need your site to be reachable on
a public address. On a local hostname the Connect screen says so rather than
handing you instructions that cannot work.

### Editors and coding agents

Claude Desktop, Claude Code, Codex, Cursor, VS Code, GitHub Copilot, Windsurf,
Cline, Roo Code, Kilo Code, Gemini CLI, opencode, Antigravity — each has its own
entry with the right file, the right shape, and a one-click install link where
the client publishes one.

### From the terminal — one command

```sh
npx nibwp-cli auth login https://yoursite.com
```

Your browser opens, your site asks what to grant, you approve. Then point an
editor at it without touching a config file:

```sh
nibwp agent add cursor        # or vscode, claude-code, codex, gemini-cli, …
nibwp discover "build a landing page"
nibwp run nibwp/find-tools --input '{"query":"pending updates"}'
```

It also bridges clients that only speak stdio, so those need no password either.
Needs [Node.js](https://nodejs.org) 20 or newer. Full command list:
[github.com/nibwp/nibwp-cli](https://github.com/nibwp/nibwp-cli).

Once connected, ask your assistant to *discover abilities* and it will list what
your site can do.

---

## Signing in, and what you are granting

Connecting is a sign-in with an approval screen, not a password you paste. The
screen names each permission in plain language, and the far-reaching ones start
unticked:

| Permission | What it allows |
|---|---|
| **Read your site** | View posts, pages, media, settings, site information. Changes nothing. |
| **Create and edit** | Add and update content. Cannot delete. |
| **Delete and reorganize** | Delete content, users, media; run bulk changes. Not reversible from here. |
| **Read and write files** | Theme and plugin files, uploads, configuration. |
| **Run code** | Write and run PHP in the sandbox. The widest permission here. |

Grant a subset and the site enforces it: a connection approved for reading
cannot write, no matter what the client asks for.

Tokens are stored the way WordPress stores application passwords — hashed, so a
database dump yields nothing usable — and revoking one takes effect on the very
next request. Every connection is listed under **NibWP → Connect**; cut any of
them off there.

Application passwords still work if you prefer them.

An assistant with write access can change your site. Work on staging first, keep
backups, and grant only what the task needs.

---

## What the free plugin does

**Work with your content.** Posts, pages, terms, users, media, options, search
and site information, through one signed endpoint.

**Agent View** — a live window onto your own site while the assistant works. You
see each page open and each change land, with a running list of what was done,
instead of waiting and hoping.

**NibWP Design** — reads your site's own colors, fonts and spacing and decides
how a page should look *before* anything is placed, so what comes out belongs to
your brand rather than to a generic template.

**Workflows** — write a way of working once, run it whenever, share it with the
other sites on your license or with the community. A library of ready-made ones
ships with the plugin.

**Memory** — namespaced notes the assistant can keep between sessions, so you do
not re-explain your site every time.

**Read your theme and plugin files** under an allow-list you control, so an
assistant can understand the site before it changes anything.

**Audit Log** — every tool call recorded locally, with arguments and a result
summary.

**Status** — one screen that checks the setup end to end and says, in plain
words, what is wrong and how to fix it.

**User Access** — decide which administrators can use NibWP at all. Off for
everyone but you by default. You can also rename the plugin inside your own
dashboard, which agencies asked for repeatedly.

**WooCommerce** and **Voxel** read access when those are detected. Multisite:
activate per site, each gets its own endpoint.

---

## Upgrading to Pro

Pro is the same plugin with more of your site unlocked. Buy a license at
[nibwp.com](https://nibwp.com), paste the key into **NibWP → License**, and the
matching add-ons install themselves — nothing to download by hand.

**Build, not just edit.** Sandboxed PHP execution and file operations, which is
the difference between an assistant that updates content and one that ships a
feature.

**The plugins you already run.** Elementor, Bricks, Kadence, Divi, Oxygen, ACF,
JetEngine, ACSS, EtchWP, WooCommerce, FluentCRM, Tutor LMS, Voxel and many more —
each understood on its own terms rather than through generic post editing.

**Toolkits** for security, migration, SEO, notifications and content scheduling.

**Skills** — add-ons that turn a screenshot, an HTML file, a URL or a Figma frame
into real, editable output in your page builder. Everything a Skill produces is
checked before it is saved: naming grammar, design tokens, layout rules. If it
cannot be built correctly you are told what is wrong, instead of getting a broken
page. Buy the whole set, or just the one for the builder you use.

Free stays free and keeps working. Nothing here expires your existing setup.

---

## Requirements

- WordPress 6.5 or newer
- PHP 8.0 or newer
- An assistant that speaks MCP, or the terminal client above

## Support

- Documentation and guides: [nibwp.com/docs](https://nibwp.com/docs)
- Changelog: [nibwp.com/changelog](https://nibwp.com/changelog)
- Questions and bug reports: [nibwp.com](https://nibwp.com)

## License

GPLv2 or later. See [LICENSE](LICENSE).
