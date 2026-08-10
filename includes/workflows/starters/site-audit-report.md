# Site audit (read-only report)
Produce a ranked, evidence-backed health report of an existing site without touching anything. This is the diagnosis step — fixes come later, only after approval.

## When to use
- Auditing, reviewing, or debugging an existing site before making any change.
- A new client/site handoff where you need to know the current state.

## Principles
- READ-ONLY. Make no changes — no edits, no deletes, no "harmless" toggles, no test posts.
- Detect the actual stack before judging anything (WP/PHP version, theme, builder, active plugins).
- Evidence over opinion. Every finding cites what you observed and where.
- Separate facts from problems: Observations (what is) vs Findings (what's wrong).
- Rank by impact, not by how easy it is to fix.

## Process
1. **Scope.** Confirm what's in scope (whole site vs an area) and that this is read-only. State it back.
2. **Inventory.** `nibwp/wp-get-site-info` for WP/PHP versions, theme, multisite. List active plugins and integrations. Use `nibwp/execute-php` to read config (debug flags, constants) — read only. Skim the Audit Log for recent activity that explains the current state.
3. **Inspect by area** (read-only the whole way):
   - **Health:** broken/erroring plugins, PHP errors or notices, `WP_DEBUG`/`display_errors` left on, outdated core/plugins.
   - **Security:** admin-role users (`wp-list-users`), users named "admin", active application passwords, file-editing enabled (`DISALLOW_FILE_EDIT`), exposed debug.
   - **SEO:** missing/duplicate titles & meta descriptions, sitemap present, robots, stray `noindex` on live pages, broken links (`nibwp/seo-broken-links`), content quality (`nibwp/seo-analyze-content`).
   - **Content:** orphan/thin/duplicate posts (`wp-list-posts`), images missing alt text.
   - **Performance:** page weight, render-blocking assets, missing page/object cache, slow or autoloaded-options-heavy DB (inspect via `execute-php`, read-only).
4. **Rank** every finding: Critical → High → Medium → Low → Note.
5. **Report** with evidence. Keep Observations and Findings separate. End with a ranked next-steps list — and apply NOTHING. Each fix requires its own approval (see `safe-changes`).

## Rules
**Do**
- Cite concrete evidence for each finding (value read, file path, URL, plugin name).
- Note when something looks fine — absence of a problem is useful.
- Flag anything you couldn't check and why.

**Don't**
- Don't change anything, including "obvious" quick fixes.
- Don't run bulk scans that write data or send email.
- Don't guess the stack — verify it.

## Validation
- Re-read this report: is every Finding backed by an Observation? Is each ranked? Did you change nothing?
- Confirm the Audit Log shows no writes from this session.

## Report
```
# Site Audit — <site> (<date>)
## Inventory
- WordPress: <ver> · PHP: <ver> · Theme: <name> · Builder: <name>
- Active plugins: <n> (<notable ones>) · Integrations: <list>

## Observations (facts)
- ...

## Findings (ranked)
### Critical
- [C1] <issue> — Evidence: <what you saw / where> — Impact: <why it matters>
### High
- [H1] ...
### Medium / Low / Note
- ...

## Recommended next steps (in order, nothing applied)
1. <fix> — addresses <C1>
2. ...
```
