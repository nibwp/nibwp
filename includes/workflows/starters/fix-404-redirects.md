# Fix 404s with 301 redirects
Find the broken URLs hitting your site, map each to the best live destination, and create clean single-hop 301 redirects in the active redirection manager — recovering lost link equity and rankings.

## When to use
- Broken links or 404s after a migration, rename, or site restructure.
- A redirection/SEO plugin log or crawl shows 404s accumulating hits.

## Principles
- Every redirect must land on a relevant LIVE page — never blanket-redirect everything to the homepage.
- One hop only: old URL → 301 → final 200. No chains, no loops.
- Map by intent: send the user where the old content's purpose now lives.
- Prioritize by impact: fix high-traffic and externally-linked URLs first.
- Retired-with-no-equivalent content → 410 Gone (or a genuinely relevant page), not a forced 301.
- If YOU renamed a slug, add its redirect in the same change — never leave a self-inflicted 404.

## Process
1. **Detect the redirection manager.** Via execute-php / active plugins, find what handles redirects (Redirection, Rank Math Redirections, Yoast Premium, SEOPress, or server-level). Note where rules live and how to write them.
2. **Gather 404s with hit counts.** Pull the 404 log from the redirection/SEO plugin if present; otherwise crawl internal links and run nibwp/seo-broken-links to surface 404/410s. Capture each URL, its hit count, and referrer/source where available.
3. **Triage & group by intent.** Cluster the 404s: moved (content exists at a new URL), merged (folded into another page), renamed slug, retired (no equivalent), and noise (bots/spam/old query strings — ignore). Drop noise; keep real, hit-earning URLs.
4. **Map each to the best live target.** For each kept 404, choose the single best LIVE destination: moved → new URL; merged → merge target; renamed → new slug; retired-but-related → closest relevant page; truly gone → 410. Verify each chosen target returns 200 before using it (wp-list-posts / read-file / fetch).
5. **Create the 301s.** Add rules in the detected manager (one 301 per old→new). Use 410 for genuinely gone content with no equivalent. Prefer exact-path rules; use regex/wildcards only for whole moved directories, carefully.
6. **Close self-inflicted gaps.** If any slug was changed during your own work, ensure its 301 is added here in the same change.
7. **Verify one-hop resolution.** For each old URL confirm it 301s directly to a URL that returns 200 — no intermediate 301/302, no loop. Re-test any that chain and collapse them to a single hop.
8. **Prioritize & finish.** Process the list highest-traffic / most-linked first so the biggest losses are recovered first; log anything left as 410 or intentionally unredirected.

## Rules
**Do**
- Match every 404 to the most relevant live page or 410.
- Verify the target is 200 before pointing a redirect at it.
- Collapse chains so each old URL resolves in exactly one hop.

**Don't**
- Don't blanket-redirect all 404s to the homepage.
- Don't create redirect chains or loops, or 301 to another 404.
- Don't 301 retired content with no equivalent — use 410.

## Validation
- Each handled old URL returns a single 301 → a 200 target (verified, no chain/loop).
- High-traffic / externally-linked 404s are all addressed.
- Any slug you renamed has a matching 301; gone content returns 410.

## Report
- **Manager:** detected redirection tool and where rules are stored.
- **redirects[]:** `old URL | hit count | type (moved/merged/renamed/retired) | new target (or 410) | verified one-hop`.
- **Coverage:** total 404s found, redirected, 410'd, ignored as noise.
- **Priority handled:** top traffic/linked URLs fixed; any deferred items + why.
