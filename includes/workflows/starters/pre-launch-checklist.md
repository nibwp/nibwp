# Pre-launch checklist
A go/no-go pass over a site before it goes live or is handed to a client. Mark every item, surface blockers, and fix only what's approved.

## When to use
- Before taking a site live or handing it off to a client.
- Re-launch, domain change, or migration where the public state matters.

## Principles
- Mark every item honestly: ✅ pass · ⚠️ needs attention · ❌ fail. No silent skips.
- Test real behavior, not settings screens — actually submit the form, actually load the page.
- Detect the stack first (theme, builder, SEO/cache/security plugins) so checks hit the right place.
- Findings are read-only; apply fixes only after approval (see `safe-changes`).
- A single ❌ on Visibility, Forms/email, or Security is a launch blocker.

## Process
Work each section, mark every item ✅/⚠️/❌ with a one-line note. Use `nibwp/wp-get-site-info`, `wp-list-users`, `wp-list-posts`, `nibwp/execute-php` (read-only), `nibwp/seo-broken-links`, and a real page load to gather evidence.

1. **Backups & safety** — a working, restorable backup exists before launch; recent restore point confirmed.
2. **Visibility & indexing** — "Discourage search engines" is OFF (`blog_public = 1`); XML sitemap present; robots.txt sane; no stray `noindex` on live pages.
3. **SEO basics** — every page has a unique title & meta description; homepage title is intentional (not "Home" / theme default); OG/social share image set; key internal links in place.
4. **Links & media** — no broken links (`nibwp/seo-broken-links`); images have alt text; favicon set.
5. **Forms & email** — every form submits AND triggers admin notification AND a REAL delivery test lands in an inbox (not spam); spam protection active.
6. **Performance** — page caching on; images optimized; Core Web Vitals checked on a real page (mobile + desktop).
7. **Security & access** — no user named "admin"; file editing disabled (`DISALLOW_FILE_EDIT`); SSL active and HTTP forces HTTPS; `WP_DEBUG`/`display_errors` OFF.
8. **Legal & analytics** — privacy & cookie pages published and linked; analytics tag actually firing on the live page.

After marking everything, list Blockers. Propose fixes and stop — apply only after approval.

## Rules
**Do**
- Send a real test email and confirm inbox delivery.
- Verify HTTPS by loading an `http://` URL and watching it redirect.
- Check Core Web Vitals on an actual representative page, mobile and desktop.

**Don't**
- Don't mark ✅ from a settings toggle alone — confirm the behavior.
- Don't apply fixes before approval.
- Don't launch with an open ❌ on Visibility, Forms/email, or Security.

## Validation
- Every item carries a mark and a note; nothing left blank.
- Every ❌ appears in the Blockers list.
- A real email test and a real HTTPS redirect test were actually performed.

## Report
```
# Pre-launch — <site> (<date>)
Backups & safety ......... ✅/⚠️/❌  <note>
Visibility & indexing .... ✅/⚠️/❌  <note>
SEO basics ............... ✅/⚠️/❌  <note>
Links & media ............ ✅/⚠️/❌  <note>
Forms & email ............ ✅/⚠️/❌  <note>
Performance .............. ✅/⚠️/❌  <note>
Security & access ........ ✅/⚠️/❌  <note>
Legal & analytics ........ ✅/⚠️/❌  <note>

## Blockers (must fix before launch)
- ...
## Recommended (post-launch OK)
- ...
Verdict: GO / NO-GO
```
