=== NIBWP ===
Contributors: nibwp
Tags: ai, mcp, claude, automation, chatgpt
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

NIBWP turns your WordPress site into a Model Context Protocol (MCP) server so AI agents like Claude Code and ChatGPT can read and edit content through a standard, permissioned interface.

== Description ==

NIBWP exposes a curated set of WordPress abilities (read posts, search content, manage media, run a key-value memory, query options) as MCP tools. Connect any MCP-compatible AI client and let it work with your site through a single signed endpoint — no copy-pasting, no scraping.

Documentation, setup guides and the full ability reference live at [nibwp.com](https://www.nibwp.com).

See it working:

https://www.youtube.com/watch?v=N4UX7ABmroA

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

= 1.2.12 =
Every tool now checks the thing you asked about, not just who asked. Plus pairing for the headless runner.

* Each WordPress tool now weighs the particular post, user, term or file in front of it, so an account reaches exactly what its role reaches in wp-admin and no further.
* Custom fields, user profiles and sign-in sessions stay with the people entitled to them.
* On a multisite network the file tools answer to the network owner, the same line WordPress draws for the theme and plugin editors.
* wp-config.php, .env and their neighbours are out of reach of the file tools altogether.
* **One step for the headless runner:** it now wants a pairing key alongside its application password. Open NibWP → Agent View, create one under "Headless runner", and give it to the runner as --pass (or NIBWP_PASS) — tick "for scheduled runs" if it lives in a cron. Revoking a key also signs out the runner it paired.
* Licensed sites take their updates from nibwp.com only.
* Every new panel and message is translated into all eight languages, Arabic included.

= 1.2.11 =
NIBWP speaks your language, and Bit Form joins the forms it works with.

* The whole admin is now available in French, Spanish, Portuguese (Portugal and Brazil), Italian, Dutch, German and Arabic.
* Arabic comes with a full right-to-left layout.
* Agent View, Connect, skills, workflows, jobs and the help drawer all follow your WordPress language.
* Counts read naturally in every language, English included: "1 site", not "1 site(s)".
* **New: Bit Form.** Forms, fields, entries and notifications, read straight from the tables Bit Form keeps for itself, plus an export of entries.
* Every Bit Form form is audited for the two ways an enquiry disappears quietly: every notification switched off, or the submission restriction discarding the entry after the visitor has been thanked.
* Bit Form also answers through the universal form tools, so "list my forms" covers it alongside every other form plugin.

= 1.2.10 =
Etch builds that look the way you asked.

* Ask for reusable Etch components and they are saved as real Etch components, with their content, properties and styles, ready to use on any page.
* Etch builds use the Automatic.css tokens your site actually defines, with the right colours and borders for light and dark sections.
* Inline icons, slots and conditions come out in Etch exactly as designed, and the playbook examples are ready to copy.
* A new repair tool brings Etch pages built with earlier versions up to date: classes restored and components made reusable.
* Design direction fits a single section into the page it joins and keeps one consistent look across your pages.
* Agents can check what they built: page reads include layout and styles, and the page check spots borders that do not show.
* Upload a local image straight into the Media Library, with alt text.
* Suggestions you decline are not repeated, and every build starts by asking where it should go.
* Etch sections with soft shadows and overlays go through smoothly.
* New in Settings: a switch for update emails, off until you turn it on.

= 1.2.9 =
Ask in your own words, get it built properly.

* Say what you want however you like - "create a hero section with Etch and ACSS" - and NibWP now reaches for the right builder skill.
* Sections arrive fully styled, with your brand classes and design tokens applied.
* Signing in from ChatGPT and Claude works on more hosting setups, including sites behind a proxy or CDN.
* Bricks: change one element without rebuilding the whole section, and header and footer templates save exactly where Bricks expects them.
* Text with special characters stays exactly as written, everywhere it is saved.
* More ready-made workflows included, covering every builder NibWP supports.

= 1.2.8 =
Sign-in that just works.

* Signing in from ChatGPT, Claude and other AI clients now works on many more hosts, including behind Cloudflare.
* Where a host genuinely blocks sign-in, the Connect page now says exactly what to ask your host, word for word.
* A one-click fix on the Status page when your server drops sign-in credentials (Apache and LiteSpeed).

= 1.2.7 =
Smoother connecting and building.

* Connecting is clearer: the application password leads, and signing in is offered only where your host supports it.
* Choosing your AI tool no longer skips past the connection choice.
* Page builds tell you exactly what was created, and stop early rather than leaving a page half-made.

= 1.2.6 =
Elementor fix.

* Fixed: pages built with the Elementor tools now save and render correctly.
* Fixed: building onto an existing page keeps that page's template.

= 1.2.5 =
Small fixes.

* Fixed a minor issue on the Connect page.
* General stability improvements.

= 1.2.4 =
Connecting an AI client, as a flow you can follow.

* **The Connect page is a guided flow.** Turn on abilities, pick your tool, choose how to connect, connect it. Finished steps fold to a single line and reopen with a click, so coming back for a second client is one glance rather than a page to re-read.
* **Sign in or use an application password — your choice.** Both are offered side by side, with the one your tool supports marked. Where signing in cannot work, the page says why instead of offering a button that fails.
* **Your key and your connection text are separate steps.** Create the key, and it folds away to make room for the text you actually came to copy.
* **Pick your tool from one scrolling row.** Seventeen clients on a single line that scrolls under the mouse.
* **A "How it works" explainer.** The whole thing in four plain sentences, one click from the page header.
* Fixed: Automatic.css — your variables and classes are read on every install, so your assistant styles with your own tokens instead of guessing.
* Fixed: Agent View can take a screenshot, so your assistant sees the page the way you see it.
* Fixed: Agent View can hover, so menus, dropdowns and hover styles can be checked without clicking them.
* Fixed: Agent View no longer has to stay open in a browser tab for your assistant to work on the page.

= 1.2.3 =
A fuller store integration, richer table and redirect control.

* **FluentCart, end to end.** Customers, coupons, subscriptions, stock, abandoned carts and revenue reporting, alongside products and orders.
* **More of Redirection.** Groups, the redirect and 404 logs, a summary of your most-hit 404s, and a check that shows what any URL will do.
* **Edit TablePress tables in place.** Change a single cell, row or column instead of sending the whole grid.
* **Built-in toolkits are yours to choose.** Security, Migration, Notifications, SEO Advanced, Content Planner and Content Fetcher can each be switched on or off.
* **Integration cards show what you get.** Each one lists how many AI actions it provides.

= 1.2.2 =
Two builders, a translator, ten form plugins and an affiliate program.

* **New: Breakdance Pro skill.** The Breakdance integration gives your assistant the data layer; this skill is the part that designs. Hand it a screenshot, an HTML page, a URL or a Figma frame and it builds the Breakdance page — every element validated against Breakdance's own registry before anything is written, so a mistyped element name cannot ship as a broken section. It reads Figma as nodes, frames and tokens rather than as a picture, which is why the spacing and the type scale survive the trip. €29.
* **Custom integration requests reach our team.** Ask for an integration from the Integrations page and it goes straight to support, with confirmation that it was sent.

* **New: Affiliate Program page.** NibWP now has an affiliate program, and you can join it from inside your own dashboard rather than going looking for a form on our site. The page sits below Settings and shows the current commission, cookie window and payout terms — fetched from nibwp.com, so what you are reading is what we are actually offering today rather than whatever was true when your copy of the plugin was built. There is an estimator that turns "how many people might I refer" into a monthly figure, and it says on the page that it is an estimate. Promote NibWP wherever you like: client sites, your own sites, a channel, a newsletter, a course, a community. Once you have joined the page stops advertising and becomes your referral link and status.

* **New: Breakdance.** Your assistant can now build Breakdance sites — pages and the elements on them, headers, footers, popups, templates and global blocks, where each one displays, and the global settings, selectors, presets and variables behind them. It edits one element at a time rather than rewriting a page to change a heading, so work you already did stays where it is. Revisions are covered too, which means a change can be undone. Oxygen 6 is the same builder under a different name, and it works there as well.
* **New: Weglot.** Translating a site is mostly configuration, and the mistakes are quiet ones — a missing hreflang tag that costs you rankings, code samples translated into nonsense, a language added without anyone checking what it does to the word count. Your assistant now handles the whole setup: languages, hreflang, translated URLs, the switcher, and the exclusions that keep code and brand names out of the translator. It can audit a Weglot site you already have and tell you what is wrong with it, and it plans the work in the right order — exclusions before the first translation pass, because a word translated once is billed whatever you do afterwards.
* **New: Formidable, Forminator and HappyForms.** Each hides the same problem somewhere different. Formidable keeps a form\u2019s email actions in separate records, so a form that stores every entry and emails nobody looks completely normal. Forminator is really three products \u2014 forms, polls and quizzes \u2014 and your assistant now reads all three rather than a third of the site. HappyForms allows the worst case of all: a form that neither emails anyone nor keeps what was submitted, where the enquiry simply disappears. Every one of them is audited for exactly that.
* **New: Ninja Forms.** The one form plugin where saving a submission is itself optional. Ninja Forms treats storing an entry as an action, exactly like sending an email, so a form can be set up to email you without keeping anything, to keep everything without telling anyone, or to do neither \u2014 and all three look identical from the outside. Your assistant now reads the actions, the fields, the submissions and the exports, and audits every form for all three cases.
* **New: WPForms and JetFormBuilder.** WPForms gets forms, fields, notifications, confirmations and settings, with entries on Pro \u2014 and it knows that WPForms Lite saves no submissions at all, so when you ask where your enquiries went it tells you they were never stored rather than showing an empty list. JetFormBuilder is a different animal: the form is Gutenberg blocks and everything it does lives in separate settings, so your assistant reads the fields out of the block markup and can see the post-submit actions \u2014 including the case where a form has none at all and has been quietly throwing every submission away.
* **New: Fluent Forms, free and Pro.** Forms and fields, submissions, email notifications, confirmations and settings \u2014 plus every integration feed an add-on writes to a form, discovered rather than guessed, so whatever you have installed is reachable. With Pro it also reads payments and subscriptions. And it audits every form for the fault that quietly costs enquiries: submissions arriving with no enabled notification, so nobody is ever told.
* **New: Gravity Forms, in full.** Forms and fields, entries and their notes, and \u2014 the parts a generic form tool never sees \u2014 notifications, confirmations and add-on feeds. Feeds are how Mailchimp, Stripe and User Registration are wired to a form, and they are where the money is. Your assistant can also validate a set of answers against a form\u0027s rules without submitting anything, and audit every form on the site for the fault that costs real enquiries: a form quietly collecting entries with no active notification, so nobody is ever told.
* **New: Contact Form 7, in depth.** The universal forms tool could already list and read CF7 forms. This goes to the part that actually breaks: the mail template. A contact form with the wrong recipient looks like it works and delivers nowhere. Your assistant can now read and change both mail templates, the form fields themselves, the messages visitors see, and the settings that quietly stop delivery — and it warns you when a mail template names a field that no longer exists, or when a form has no spam protection at all.
* **New: WS Form.** Your assistant can now build and run WS Form end to end — forms and their whole JSON definition, fields, tabs and sections, the actions that fire when someone submits, submissions and their exports, styles and templates. Ask for a form and get one that works, not an empty shell: the actions are the part that decides whether anyone is actually told about a submission, and they are covered too.
* Deleting anything in WS Form sits behind its own permission and asks for confirmation first. Trashing does not, because putting something back should never be the harder option.
* Submission exports are paginated on purpose, so a form with thousands of entries returns something usable instead of failing slowly.

= 1.2.1 =
An important fix, and the start of something new: NibWP from the command line.

* **Fixed: a Skill add-on next to Pro could take a site down.** Activating a license could install a Skill add-on that your Pro plugin already contained. Two copies of the same file then loaded at once, which PHP refuses outright — the site went blank with no way in from the dashboard. Reported by a customer on a live site. Four things changed so it cannot happen again: your license no longer installs a Skill you already have; if the two are already side by side, they now agree on which one loads; a site already in that state switches the duplicate off by itself and comes back on the next page load; and our release process now refuses to build if any file could load twice. Every Skill add-on has been rebuilt — update yours.
* **New: NibWP from the terminal.** `npx nibwp-cli auth login https://yoursite.com` opens your browser, shows your site's own approval screen, and connects — no configuration file, no password anywhere. From there one command wires up Cursor, VS Code, Claude Code, Codex, Gemini CLI and others, and another bridges assistants that cannot speak to a site directly. Free and open source. It also lets you choose exactly what to grant: a connection approved for reading cannot write, and your site is what enforces that.
* **New: work across every site at once.** Run the same thing on one site, a named site, or all of them, with a result for each. Built for anyone looking after more than a handful of installs.
* **New: edit your theme in your own editor.** Pull a folder down, change it locally with whatever tools you like, push back only what you changed. It refuses to overwrite anything that changed on the site while you were working.
* **New: repeat a build instead of re-describing it.** Your assistant works out how to build something once; NibWP records what it actually did and can carry out the same sequence on your other sites, exactly, without an assistant involved a second time.
* **New: put things back.** Capture the pages and settings you are about to let an assistant near, and restore them if it goes wrong.
* Fixed a button on the dashboard losing its rounded corners when focused.

= 1.2.0 =
Our biggest release yet — a new way to watch your assistant work, control over who can use it, and much more of your site it can build.

* **New: Agent View — watch your assistant work.** Until now you asked for something and waited. Agent View gives you a live window onto your own site: you see each page open, each change land, and a running list of what was done. Included on every plan, free ones too.
* **New: NibWP Design — pages that don't look AI-made.** Ask for a page and you usually get the same safe, forgettable layout. NibWP Design reads your site's own colors, fonts and spacing first, and decides how the page should look before a single block is placed — so what comes out belongs to your brand. Free.
* **New: Status — find out why a connection won't work.** One screen that checks your setup end to end and tells you, in plain words, what is wrong and how to fix it. No more guessing.
* **New: User Access — decide who gets to use AI on your site.** Choose exactly which administrators can see NibWP, and which cannot. Off for everyone but you by default. You can also rename the plugin inside your own dashboard, which agencies asked for again and again.
* **New: sign in instead of copying passwords.** Connecting Claude, ChatGPT, Cursor and the rest is now a sign-in and an approval screen that lists, in readable language, exactly what you are allowing — and lets you refuse anything you would rather not grant. Application passwords still work if you prefer them.
* **New: Voxel support.** If your site runs the Voxel theme, your assistant can finally work with what makes it a Voxel site — listings and their fields, categories, orders and memberships, reviews, messages, and the search itself.
* **New add-on: Voxel Pro (€29).** Build the templates a Voxel site is made of: preview cards, listing pages, archives, headers, and search pages whose filters, results and map are wired together properly. Every field it uses is checked against your real listings first, so a card never goes live with a blank line where the price should be.
* **New: share a workflow with your other sites.** Write a way of working once and hand it to every site on your license, or offer it to the community. Your workflows stay private unless you say otherwise.
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
* Improved (Pro): EtchWP Pro recognizes loops, conditions, components and dynamic data from the start, and establishes the ACSS design system before building.

= 1.1.1 =
* New: Discover community + curated workflows right inside the Workflows page — browse what NIBWP.COM and other users share, see upvotes and Featured picks, and import any with one click (you get your own editable copy).
* New: the workflow library now runs on its own dedicated hub — faster and updated independently of your sites (configurable via the `NIBWP_LIBRARY_HUB` constant).
* Improved: Restore defaults now pulls the latest curated set from the hub, not only the bundled starters.
* Improved: Featured workflows are marked with a ★ and surface first in Discover.

= 1.1.0 =
* New: Workflows — reusable, AI-followed operating playbooks. Build a library of structured procedures (build a site, full SEO audit, content pass, convert to EtchWP, safe changes, and more); pin one as always-on, or let NIBWP auto-route to the right one when a task matches its "when to use". Ships with 18 ready-made workflows across 9 categories. Create your own, import a `.md`, duplicate and customize, attribute a creator, and choose visibility (Private / License Circle / Community). Five MCP abilities expose them to your AI client, and the active workflow is injected as mandatory context so the agent actually follows it.
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
