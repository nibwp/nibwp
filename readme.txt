=== NIBWP ===
Contributors: nibwp
Tags: ai, mcp, claude, automation, chatgpt
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

NIBWP turns your WordPress site into a Model Context Protocol (MCP) server so AI agents like Claude Code and ChatGPT can read and edit content through a standard, permissioned interface.

== Description ==

NIBWP exposes a curated set of WordPress abilities (read posts, search content, manage media, run a key-value memory, query options) as MCP tools. Connect any MCP-compatible AI client and let it work with your site through a single signed endpoint — no copy-pasting, no scraping.

= What you get =

* **MCP server endpoint** bundled with the WP MCP Adapter — exposes WordPress abilities to any compliant AI client.
* **Read abilities** for posts, terms, users, media, options, search, and the Abilities Discovery handshake.
* **List Directory + Read File** under a configurable allow-list so agents can inspect your themes/plugins (read-only).
* **Memory store** — namespaced key/value storage for cross-session agent memory.
* **WooCommerce read access** when WooCommerce is detected.
* **Dashboard, Connect, Settings, How-To, and Audit Log** admin screens — every tool call is logged.

= Designed for development and staging =

NIBWP is intentionally scoped for development, staging, and authorized power users. Activate it on production only after reviewing the Audit Log behavior and the Abilities → Domain lock setting.

= Privacy =

NIBWP does not transmit your site content to nibwp.com or any third party. The MCP endpoint is served from your own WordPress installation. Connections require a user with the `manage_options` capability and a per-site domain lock. The Audit Log stores every tool call locally in your WordPress database.

== Installation ==

1. Install the plugin through the WordPress.org plugin directory, or upload the `nibwp` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open **NIBWP → Connect** and follow the on-screen instructions to register the MCP endpoint with your AI client.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard from Anthropic that lets AI agents call structured "tools" over a transport layer. NIBWP implements an MCP server tailored to WordPress data and operations.

= Which clients can connect? =

Any MCP-compatible client — Claude Code, the Claude desktop app, ChatGPT custom GPTs that support MCP, etc. NIBWP follows the public MCP schema.

= Do I need a key or account? =

No. NIBWP runs entirely inside your WordPress install. There is no SaaS dependency for the Free plugin.

= Does it work on multisite? =

Yes — activate per-site. Each subsite gets its own endpoint.

== Screenshots ==

1. Dashboard overview.
2. Connect page with copy-paste MCP endpoint setup.
3. Integrations grid — toggle which abilities are exposed.
4. Audit log — every tool call recorded with arguments + result summary.

== Changelog ==

= 1.2.0 =
Our biggest release yet — a new way to watch your assistant work, control over who can use it, and much more of your site it can build.

* **New: Agent View — watch your assistant work.** Until now you asked for something and waited. Agent View gives you a live window onto your own site: you see each page open, each change land, and a running list of what was done. Included on every plan, free ones too.
* **New: NibWP Design — pages that don't look AI-made.** Ask for a page and you usually get the same safe, forgettable layout. NibWP Design reads your site's own colours, fonts and spacing first, and decides how the page should look before a single block is placed — so what comes out belongs to your brand. Free.
* **New: Status — find out why a connection won't work.** One screen that checks your setup end to end and tells you, in plain words, what is wrong and how to fix it. No more guessing.
* **New: User Access — decide who gets to use AI on your site.** Choose exactly which administrators can see NibWP, and which cannot. Off for everyone but you by default. You can also rename the plugin inside your own dashboard, which agencies asked for again and again.
* **New: sign in instead of copying passwords.** Connecting Claude, ChatGPT, Cursor and the rest is now a sign-in and an approval screen that lists, in readable language, exactly what you are allowing — and lets you refuse anything you would rather not grant. Application passwords still work if you prefer them.
* **New: Voxel support.** If your site runs the Voxel theme, your assistant can finally work with what makes it a Voxel site — listings and their fields, categories, orders and memberships, reviews, messages, and the search itself.
* **New add-on: Voxel Pro (€29).** Build the templates a Voxel site is made of: preview cards, listing pages, archives, headers, and search pages whose filters, results and map are wired together properly. Every field it uses is checked against your real listings first, so a card never goes live with a blank line where the price should be.
* **New: share a workflow with your other sites.** Write a way of working once and hand it to every site on your licence, or offer it to the community. Your workflows stay private unless you say otherwise.
* **Improved: Skills are easier to browse.** Free and Pro tabs, search, and sorting — with the free ones first.
* **Improved: a calmer connection screen.** Fewer words, one clear choice, and the fiddly parts tucked away until you want them.
* Fixed minor issues and made general improvements throughout.

= 1.1.9 =
* Fix (Pro): creating or updating a Bricks global class through NibWP could save one of its fields in the wrong data format. Bricks reads that data on every request, so the next page load failed everywhere at once — front end, wp-admin and the REST API — leaving the site unreachable and unrepairable through its own admin. Global classes are now written in the format Bricks expects, and an affected site repairs itself automatically the first time the ability runs after updating.
* Fix (Pro): updating a Bricks global class replaced its settings instead of merging them, so changing one property (custom CSS, for example) silently discarded the others — interactions, typography and breakpoint values. Updates now merge, and only touch what you asked to change.

= 1.1.8 =
* New (Pro): Figma integration + Figma Pro skill — connect a Figma account (personal access token or OAuth) and PULL frames into a local library instead of blind-converting them. Each pulled frame is cached as a 2x image plus its real design tokens (color palette + type ramp), so nothing is converted until you decide. Pull one frame, every frame in a file, or walk a whole team/project in bulk with live progress and a stop button; re-sync refreshes everything you already have. Frames get a callable handle (@figma/hero-section) you can use in a prompt, and the library has search, paging, inline rename and one-click removal. Conversion is builder-agnostic: NibWP detects the builder your site actually runs and hands the design to that builder's own validated pipeline — Etch, Bricks, Elementor, Kadence, Oxygen — falling back to core blocks when no builder skill is present. Read-only against Figma: your designs are never modified.
* Security (Pro): Figma credentials are now encrypted at rest (libsodium, with an AES-256 fallback) instead of being stored in plain text, and the interface only ever shows a masked fingerprint of the saved token.
* Fix (Pro): installing a Pro skill as a standalone add-on alongside the bundle could fail with a fatal "Cannot redeclare" error. The add-on packages no longer ship a second copy of code the base plugin already provides, and the shared includes are guarded, so both install paths work together.
* Fix (Pro): the skill preflight could treat a list of candidate pages as if it were the answer to "which page?", causing a component to be written into the wrong post. Candidate lists are now offered as choices and the question is asked properly.
* Fix (Pro): a component that passed validation in dry-run could be rejected when saving, because the brand name was compared against BEM class names with different casing. Brand matching is now case-insensitive.
* Improved (Pro): a Figma rate limit is now explained in plain language — it is a limit Figma applies to your token, not a NibWP error — with the wait in human terms, what still works meanwhile, and links to create another token, check your seat type, or review Figma's limits.
* Improved: corrected the package name recorded in the plugin's dependency metadata.

= 1.1.7 =
* New (Pro): Elementor Pro skill — convert HTML, a URL, or an image/screenshot into a native, editable Elementor page. Modern flexbox containers first, then real widgets (heading, text-editor, image, button, icon-box, image-box, video, tabs, accordion… plus your installed Pro / add-on widgets). Every widget type and control id is checked against the LIVE Elementor registry on your site, so nothing is invented. Styling maps to Elementor controls (not a wall of custom CSS), images are sideloaded to real attachment IDs, and pages are persisted the way Elementor needs them — correctly slashed data, edit-mode on, CSS regenerated, with a round-trip guard — so they render immediately. Validate + score before you commit (dry-run); read an existing page's structure; and repair a page that renders blank from corrupted data. 8 abilities.

= 1.1.6 =
* New: Jobs — an outcome-first way to run maintenance on your site. Pick a job ("Find broken links", "Security scan", "Check for updates", "Clean up the database") or type what you want in plain English; NIBWP plans it, pauses for your approval before anything changes your site, and reports back in plain English. Includes a live activity timeline, an approvals inbox, run-now / schedule (daily or weekly), and pause / delete controls, plus a running-jobs indicator in the top bar.
* New: four jobs run for real using only WordPress core — no third-party services. Find broken links (real crawl + HTTP check of your content), Security scan (debug exposure, file editor, admin user, HTTPS, outdated software), Check for updates (pending plugin / theme / core updates), and Clean up the database (revisions, spam, trashed items, expired transients, orphaned rows — with your approval, then table optimisation). More outcome jobs are marked "Planned" until the execution engine ships.
* Improved: every action on the Jobs screen is AJAX (no page reloads), with styled confirmations and inline loading states.

= 1.1.5 =
* Improved (Pro): Kadence Pro skill rebuilt around how Kadence actually works. Design now lives in **block attributes** (Kadence renders the CSS from them) — never custom classes, external CSS, or `core/html`. Adds verified attribute knowledge (responsive `fontSize`, section/overlay defaults, which fields are `source:html`), a live-registry attribute inspector so it never guesses a name, and a page-CSS writer that uses Kadence's own `_kad_blocks_custom_css`. The validator now rejects `core/html`, custom-class styling, and the classic attribute traps. The "Convert a design to Kadence Blocks" workflow was updated to match.

= 1.1.4 =
* New (Pro): Kadence Pro skill — convert HTML, a URL, or an image/screenshot into a validated Kadence Blocks layout, template, or reusable pattern. Real Kadence blocks only (rowlayout / column / advancedheading / singlebtn / image / infobox / iconlist / testimonials / posts…), correct nesting, unique block IDs, and native attribute styling. Validate and score before you commit (dry-run), then persist to a page, post, Kadence Element (header/footer/hook), or reusable pattern. 7 abilities.

= 1.1.3 =
* New (Pro): Builderius integration — a first-class connection to the Builderius visual builder (wordpress.org/plugins/builderius). Read your templates and their git-like version graph (branches, commits, releases), components, fragments, global settings and form submissions; and author templates, components and fragments by committing configs through Builderius's own versioning. 21 abilities, including a config builder and validator so an AI client can author Builderius modules correctly.

= 1.1.2 =
* New: when Application Passwords are disabled, the Connect page now explains exactly why — no HTTPS, or a security plugin blocking them — with an expandable, step-by-step guide to switch them back on.
* Improved (Pro): ACSS Pro writes your global design config through Automatic.css's own engine, so it saves and recompiles instantly in ACSS's exact schema (full ACSS 4.x / OKLCH support) — no manual re-save.
* Improved (Pro): EtchWP Pro recognises loops, conditions, components and dynamic data from the start, and establishes the ACSS design system before building.

= 1.1.1 =
* New: Discover community + curated workflows right inside the Workflows page — browse what NIBWP.COM and other users share, see upvotes and Featured picks, and import any with one click (you get your own editable copy).
* New: the workflow library now runs on its own dedicated hub — faster and updated independently of your sites (configurable via the `NIBWP_LIBRARY_HUB` constant).
* Improved: Restore defaults now pulls the latest curated set from the hub, not only the bundled starters.
* Improved: Featured workflows are marked with a ★ and surface first in Discover.

= 1.1.0 =
* New: Workflows — reusable, AI-followed operating playbooks. Build a library of structured procedures (build a site, full SEO audit, content pass, convert to EtchWP, safe changes, and more); pin one as always-on, or let NIBWP auto-route to the right one when a task matches its "when to use". Ships with 18 ready-made workflows across 9 categories. Create your own, import a `.md`, duplicate and customise, attribute a creator, and choose visibility (Private / License Circle / Community). Five MCP abilities expose them to your AI client, and the active workflow is injected as mandatory context so the agent actually follows it.
* New: Workflow popularity — a cross-install upvote on each shipped workflow card, so you can see what the wider NIBWP community finds most useful. One vote per install; instant.
* New: Tools detection on workflows — pick the plugins, themes, and skills a workflow relies on from an auto-detected list (with live active / available / missing status), or type your own.
* New: NibWP Library (nibwp.com) — a community + curated asset hub behind the Workflows experience: moderation, ratings, and a distribution API, ready to grow beyond workflows.
* Improved: lighter admin — per-page stylesheets, a connected-integrations menu in the top bar, an in-context documentation helper, and polish across Skills, Audit, and the License/pricing pages.
* Build: release packages no longer carry developer or tooling files.

= 1.0.10 =
* Improved: Integrations grid now orders active integrations first, then detected (plugin installed) ones, then the rest. Skills page cards render at an equal, compact height with a scrollable feature list so long feature sets stay readable without stretching the card.

= 1.0.9 =
* Updater fix: a PHP 8 fatal in the update-notification code could lock admins out of wp-admin once an update became available (the front-end and login stayed up, so it looked like a spontaneous outage). The faulty call is corrected and the notifier is now crash-isolated so a future bug there can never take down the admin. Affects PHP 8 sites on 1.0.8 and earlier.

= 1.0.8 =
* Fixed: EtchWP Pro — the html-to-component writer could persist only the first-child "spine" of a page when a payload's block `innerContent` didn't match its `innerBlocks`, silently dropping sibling blocks. The serializer now rebuilds `innerContent` from the real block tree, a post-serialize round-trip check aborts (loudly) rather than save a truncated page, and the validator warns on any mismatch.

= 1.0.7 =
* New: Claude Desktop one-click .mcpb bundle download on the Connect page — credentials embedded, no JSON to edit.
* New: "How it works" intro and a numbered step connector on the Connect page.
* New: Integrations grid bulk actions — Activate all / Activate detected / Deactivate all (AJAX, with a progress overlay).
* Improved: Connect-page copy buttons restyled; the client list is now a single-line, hover-to-scroll strip.
* Fixed: stylesheet flash (FOUC) on the Integrations page; assorted pill/tab width-jump glitches.

= 1.0.0 =
* First public release.
* MCP Adapter bundled (no separate plugin required).
* New: Multi-root skills registry — allows companion plugins to register skill packs.
* New: Audit log table with per-call drill-down.
* New: Dashboard, Connect, Settings, How-To, Integrations admin shells.
* Improved: dark-mode polish across every admin screen.
* Improved: read-file / list-directory now enforce a configurable base path.

== Upgrade Notice ==

= 1.0.7 =
Adds the Claude Desktop .mcpb one-click bundle, Connect-page UX polish, and bulk integration toggles.

= 1.0.0 =
First public release.
