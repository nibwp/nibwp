# Full SEO audit
Run a complete, scored SEO health check across a whole site or one section, then hand back a prioritized fix list. This pass is READ-ONLY — you diagnose, you do not change anything without explicit approval.

## When to use
- A complete SEO health check of a site or a section is requested.
- Before a redesign/migration, after a traffic drop, or as a recurring audit.

## Principles
- Detect, never assume: identify the active SEO engine (Yoast / Rank Math / SEOPress / Slim SEO / AIOSEO) before reading any meta.
- READ-ONLY: gather, score, recommend. Apply zero writes — every fix goes in the report for approval.
- Evidence over opinion: each finding cites the URL/post ID and the measured value.
- Score the whole, then rank the parts: a report card plus a severity-sorted issues table.
- Lean on the `seo-pro` skill's preflight + audit pipeline for the heavy per-post analysis.
- Sample large sites: audit all of a small section; on big sites sample top traffic/template pages per post type.

## Process
1. **Crawl & inventory.** Run nibwp/wp-get-site-info for WP + PHP versions, site URL, HTTPS. Detect the active SEO engine via execute-php (check active plugins / known option keys). Use nibwp/wp-list-posts to enumerate public post types and counts. Record scope (whole site vs. section).
2. **Technical & indexing.** Verify robots.txt exists and isn't blocking key paths; confirm an XML sitemap is generated (engine setting) and ideally submitted in Search Console. Check the "Discourage search engines" option (`blog_public` via execute-php) — if 0 on a live site this is Critical. Scan live pages for stray `noindex` (seo-get-post-meta robots fields) and for canonicals pointing off-site/at the wrong URL. Flag mixed content on HTTPS and obvious index bloat (tag/archive/param pages indexed).
3. **On-page.** For each in-scope post run seo-get-post-meta + seo-analyze-content: flag missing/duplicate/over-length title tags (>~60) and meta descriptions (missing or >~160), multiple or zero H1, broken heading order (skipped levels), and images missing alt (seo-image-optimize report mode).
4. **Content.** Identify thin pages (low word count / low value), duplicate or cannibalizing titles competing for one query, and orphan pages (no inbound internal links).
5. **Internal linking.** Map inbound internal links per page; list orphans and pages with very few links. Note anchor diversity (exact-match over-use vs. descriptive anchors).
6. **Structured data.** Use seo-schema-markup to report schema coverage per template (Article/BlogPosting, Product, LocalBusiness, FAQ, Breadcrumb). Flag invalid JSON-LD and missing REQUIRED fields per type — do not invent fields.
7. **Broken links.** Run nibwp/seo-broken-links across scope; bucket internal vs. external and by status (404/410/5xx).
8. **Performance snapshot.** Capture a Core Web Vitals snapshot (LCP/CLS/INP) for representative templates as a flag only — defer real fixes to the performance-tune workflow.
9. **Off-page snapshot.** Note indexed page count vs. published count (bloat or under-indexing) and any obvious issues (HTTP/HTTPS duplication, www/non-www). Keep light — no third-party backlink tooling assumed.
10. **Score & assemble.** Score each area 0–100, roll up an overall grade, and compile the issues table sorted Critical → High → Medium → Low → Note, each with a one-line fix and a pointer to the right workflow.

## Rules
**Do**
- Detect the SEO engine first and use its real field names.
- Cite the post ID / URL and the measured value for every finding.
- Cross-reference fixes to workflows: on-page → `seo-content-pass`, 404s → `fix-404-redirects`, speed → performance-tune.

**Don't**
- Don't write, update, or delete anything — recommend only.
- Don't recommend noindex on a page meant to rank, or any slug change without a 301.
- Don't invent schema fields or keyword-stuff in suggested copy.

## Validation
- Every in-scope post type was inventoried and sampled.
- Each issue has: severity, location, measured value, one-line fix.
- Area scores roll up to one overall grade; no write operations were performed.

## Report
- **Report card:** Technical, On-page, Content, Internal linking, Schema, Links, Performance, Off-page — each 0–100 + overall grade.
- **Scope:** WP/PHP versions, active SEO engine, post types + page counts audited.
- **issues[]:** `severity | location | finding (measured value) | one-line fix | workflow`, sorted Critical → Note.
- **Top 5 quick wins** and **biggest risk**, plus a one-line note that nothing was changed (read-only).
