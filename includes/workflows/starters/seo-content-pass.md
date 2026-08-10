# SEO content pass
Take one post or page from draft/existing state to fully optimized and indexable — intent, title, meta, headings, links, images, schema, slug — then publish and submit for indexing.

## When to use
- Publishing or optimizing a single post or page for search.
- A page exists but underperforms and needs a focused on-page pass.

## Principles
- Detect the active SEO engine (Yoast / Rank Math / SEOPress / Slim SEO / AIOSEO) and write through its real meta fields.
- One page, end to end: don't half-optimize — finish every element below before publishing.
- Match search intent: optimize for what the searcher wants, not for keyword density.
- Validate BEFORE publish: lengths, no accidental noindex, no foreign canonical, no duplicate title.
- Never change an existing slug without a 301 (defer to `fix-404-redirects`).
- Use the `seo-pro` skill's optimize + validate pipeline for scoring and brand-voice meta.

## Process
1. **Read the page & detect engine.** Pull the post with nibwp/wp-update-post read / read-file and seo-get-post-meta. Identify the SEO engine so you write the correct title/description/robots/canonical fields.
2. **Lock intent + primary keyword.** Decide the single primary query and intent (informational / commercial / transactional). Confirm no existing page already targets it (avoid cannibalization — check sibling titles via wp-list-posts).
3. **Title tag.** Write a ≤~60-char title with the keyword near the front, compelling and unique sitewide. Set via seo-update-post-meta.
4. **Meta description.** Write a unique ~150–160-char description that earns the click and reflects the page. Set via seo-update-post-meta. Don't duplicate another page's description.
5. **Headings.** Ensure exactly one H1 (usually the title) and a logical H2/H3 outline with no skipped levels. Use seo-analyze-content to confirm structure.
6. **Internal links — in and out.** Add 2–4 contextual internal links FROM this page to relevant pages, and add at least one link TO this page from a related existing post. Use diverse, descriptive anchors (not all exact-match).
7. **Images.** Add descriptive alt text to every meaningful image and compress oversized files via seo-image-optimize. Decorative images get empty alt.
8. **Schema.** Apply the correct schema type for the page (Article/BlogPosting, Product, FAQ, etc.) via seo-schema-markup, filling only fields that genuinely exist on the page. No invented fields.
9. **Slug.** Ensure a short, readable, keyword-relevant slug. If the page already exists and you change the slug, create the 301 in the same change (hand off to `fix-404-redirects` or add it inline).
10. **Validate, publish, submit.** Run the pre-publish checks (below). If clean, publish via wp-update-post, then submit the URL for indexing (engine "fetch"/ping or Search Console).

## Rules
**Do**
- Keep the page's robots index/follow on (it's meant to rank).
- Make title, description, and slug unique across the site.
- Pair any slug change with a same-change 301.

**Don't**
- Don't keyword-stuff the title, meta, headings, or alt text.
- Don't set a canonical to another URL unless this page is genuinely a duplicate.
- Don't publish before the validation checks pass.

## Validation
- Title ≤~60 chars, keyword front-loaded, unique; meta ~150–160 chars, unique.
- Exactly one H1; clean H2/H3 order; no skipped levels.
- Robots = index,follow; canonical is self (or intentional); no duplicate title sitewide.
- 2–4 internal links out + ≥1 in, anchors diverse; all images have alt; schema validates with only real fields.

## Report
- **Page:** ID/URL, primary keyword, intent, detected SEO engine.
- **Changes:** old → new for title, meta description, slug (+301 if changed), H1/heading fixes, internal links added (in/out), images alt'd/compressed, schema type applied.
- **Validation:** pass/fail per check above.
- **Post-publish:** published status + indexing submission result; any follow-ups.
