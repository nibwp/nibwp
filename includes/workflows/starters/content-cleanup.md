# Content audit & cleanup

Prune and consolidate a sprawling content library: inventory everything, classify each URL, fix keyword cannibalization, and execute in safe batches — every removal or merge backed by a redirect, a backup, and approval.

## When to use
- Pruning, consolidating, or de-duplicating a large content library.
- "Clean up the blog", "we have too many thin posts", "fix overlapping articles", "content audit".
- Suspected keyword cannibalization (several pages competing for one query).
- Not for writing new content (use "Content creation") and not for the redirect mechanics themselves (defer to the 404/redirects workflow).

## Principles
- Every REMOVE or MERGE ships with a redirect — never bulk-delete a URL that has links, traffic, or ranking value.
- Decide from data, not vibes: traffic, last-updated, word count, and target keyword drive each verdict.
- One canonical URL per topic — kill cannibalization by consolidating, not by tweaking titles.
- Preserve link equity: redirect retired URLs and re-point internal links to the survivor (no orphans).
- Work in small, reversible batches behind explicit approval and a backup — no library-wide destructive sweep.

## Process
1. **Inventory.** Build the full list of posts/pages via `wp-list-posts` (and `wp-search` to probe topic clusters). For each URL capture: traffic/engagement, last-updated date, word count, and the target/ranking keyword. This table is the audit's backbone.
2. **Detect cannibalization.** Group URLs by target keyword and intent. Where 2+ pages chase the same query, pick the single canonical (best traffic + links + freshness) and mark the rest to MERGE or REDIRECT into it.
3. **Classify every URL** into exactly one verdict:
   - **KEEP** — performs well and is current; leave it (or queue a light refresh).
   - **REFRESH** — strong topic but stale/thin → update facts, expand, re-optimize (hand off to "Content creation"/SEO pass).
   - **MERGE** — overlaps/cannibalizes another → fold the unique value into the stronger URL, then 301 the weaker one to it.
   - **REDIRECT** — outdated content but the URL has links/equity → 301 to the closest living page.
   - **REMOVE** — no value, links, or traffic → delete, paired with a 301 (better target exists) or 410 (nothing relevant).
4. **Build the redirect map.** For every MERGE/REDIRECT/REMOVE, record `old URL → new URL (or 410)`. No row leaves without a destination decision. This map is the approval artifact.
5. **Approve + back up.** Present the classified inventory and redirect map. Get explicit sign-off and confirm a full backup (DB + content) exists before any change.
6. **Execute in batches.** Process a small batch at a time. For each item: create/verify its redirect FIRST (defer to the 404/redirects workflow), then perform the merge edit or `wp-update-post` to trash/410 — never delete before the redirect is live.
7. **Re-point internal links.** Use `wp-search` to find every internal link to a retired URL and update each to the survivor via `wp-update-post`. Confirm zero orphans and no internal links left hitting a redirect chain.
8. **Verify the batch.** Spot-check that redirects resolve (no loops/chains), survivor pages are intact, and the SEO plugin reflects new canonicals (`seo-update-post-meta` / `seo-analyze-content` as needed) before starting the next batch.
9. **Record + store.** Log what changed per batch and `memory-store` recurring patterns (taxonomy quirks, redirect conventions) for future audits.

## Rules
**Do**
- Base every verdict on traffic, freshness, word count, and target keyword.
- Pair every REMOVE/MERGE with a 301 (or 410 when nothing fits).
- Pick one canonical per topic and consolidate the rest into it.
- Update internal links to the surviving URL after each merge/redirect.
- Execute in small approved batches with a backup in place.

**Don't**
- Don't bulk-delete without a redirect map and approval.
- Don't delete a URL before its redirect is live.
- Don't leave orphaned internal links or redirect chains.
- Don't keep multiple pages competing for the same keyword.
- Don't refresh-rewrite here — hand REFRESH items to the content workflow.

## Validation
- Inventory complete with traffic, last-updated, word count, and target keyword per URL.
- Cannibalization groups identified; one canonical chosen per topic.
- Every URL carries exactly one verdict (KEEP/REFRESH/MERGE/REDIRECT/REMOVE).
- Redirect map covers all MERGE/REDIRECT/REMOVE rows (301 or 410); approved.
- Backup confirmed before execution.
- Redirects created before deletions; all resolve with no loops/chains.
- Internal links re-pointed to survivors; zero orphans.
- Survivor pages and SEO canonicals verified per batch.

## Report
Return: total URLs inventoried; verdict counts (KEEP/REFRESH/MERGE/REDIRECT/REMOVE); cannibalization groups found and the canonical chosen for each; the full redirect map (old → new / 410); batches executed and items per batch; internal links re-pointed (count) and orphan check result; backup + approval confirmation; redirect-resolution/SEO verification per batch; REFRESH items handed off; patterns stored in Memory; anything still pending approval.
