# Content creation (research → publish)

Take a topic from a blank page to a polished, on-brand, SEO-ready post or page — and stop short of going live without a human go. This is the full pipeline: brief, research, outline, draft, media, SEO, taxonomy, proof, and a safe save.

## When to use
- Writing a new article, blog post, or page from scratch.
- "Write a post about X", "draft a guide on Y", "create a landing page for Z".
- Not for editing/refreshing existing content (use "Content audit & cleanup") and not for the deep SEO mechanics (defer to the SEO content pass).

## Principles
- Accuracy first: never fabricate facts, stats, quotes, prices, or dates — flag anything needing a source and leave a placeholder rather than guess.
- Match the site's voice, not a generic blog tone — read real posts before writing a word.
- Structure for scanning: short paragraphs, descriptive H2/H3s, one idea per section.
- One clear takeaway and one primary keyword per piece; don't dilute either.
- Reuse the existing taxonomy and internal links; don't spawn near-duplicate categories/tags.
- Never publish straight to live — save as draft or schedule and get explicit approval (defer to "Safe changes").

## Process
1. **Brief.** Confirm the topic, the target reader, the search intent (informational / commercial / navigational), the ONE takeaway, and the primary keyword. If any of these is missing or ambiguous, ask before drafting — don't assume.
2. **Voice + conventions.** Run `memory-recall` to pull stored BRAND VOICE, style rules, and conventions. If none exist, read 2–3 recent posts via `wp-list-posts` + `wp-update-post`-free reads to infer tone, sentence length, formatting, and CTA style, then `memory-store` what you learn for next time.
3. **Research.** Read the top SERP results for the primary keyword to map what already ranks and where the gaps are. Run `wp-search` for the site's own related posts to find the unique angle and the internal-link targets. Note every claim that will need a citation.
4. **Outline.** Build an H2/H3 skeleton that fully covers the intent and answers the obvious follow-up questions. Lead with the takeaway. For a large/important piece, send the outline for a quick approval before drafting.
5. **Draft.** Write in the confirmed brand voice via `wp-create-post` (status `draft`): a hook intro that states the payoff, a scannable body (short paragraphs, one idea per section, bullets/tables where they help), and a clear conclusion with a single CTA. Keep the primary keyword natural in the title, intro, and an H2 — no stuffing.
6. **Media.** Add a featured image with descriptive, keyword-aware alt text and a compressed file size. Add in-body images only where they clarify (diagrams, examples, screenshots) — each with real alt text.
7. **SEO pass.** Set the SEO title, meta description, and clean slug, add schema, and wire internal links to 2–4 related posts found in step 3. Detect the active SEO plugin and use `seo-update-post-meta`, `seo-schema-markup`, and `seo-analyze-content`. For anything deeper, defer to the SEO content pass workflow.
8. **Taxonomy.** Assign the single best existing category and a few specific existing tags (reuse — verify against `wp-list-posts`). Propose a new term only if nothing fits, and flag it for approval.
9. **Proof.** Check typos/grammar, that every link resolves, that no placeholder/citation flag remains unresolved, and that the layout holds at mobile width (no overflow, readable type).
10. **Save safely.** Leave the post as a **draft** or **schedule** it via `wp-update-post`. Publish live only on an explicit go. Hand back the draft link and the open questions.

## Rules
**Do**
- Read real recent posts (or recall stored voice) before writing.
- Confirm topic, reader, intent, takeaway, and primary keyword up front.
- Keep paragraphs short and sections single-idea; lead with the takeaway.
- Reuse existing categories, tags, and internal-link targets.
- Flag every fact/stat/quote that needs a source.

**Don't**
- Don't fabricate facts, numbers, quotes, prices, or dates.
- Don't keyword-stuff or write for the algorithm over the reader.
- Don't create duplicate/near-synonym categories or tags.
- Don't ship images without alt text or compression.
- Don't publish to live without explicit approval.

## Validation
- Brief confirmed (reader, intent, takeaway, primary keyword) before drafting.
- Voice matches stored/recent posts; conventions stored in Memory.
- Outline covers intent; large pieces approved before draft.
- Scannable structure: short paragraphs, descriptive H2/H3s, exactly one H1.
- Featured image + in-body images have descriptive alt text and are compressed.
- SEO title, meta, slug, schema set via the detected SEO plugin; 2–4 internal links wired.
- Single best existing category + specific existing tags assigned.
- No unresolved citation flags; all links resolve; mobile width clean.
- Saved as draft or scheduled — not published — pending approval.

## Report
Return: topic, target reader, search intent, primary keyword, and the one takeaway; voice source (recalled vs inferred from posts); research summary (gaps found, angle chosen, internal-link targets); word count and structure (H2/H3 count); media added (featured + in-body, alt text done); SEO plugin detected and meta/schema/links applied; category + tags assigned (reused vs proposed); list of any facts/stats flagged for a source; draft/scheduled link and post ID; open items needing approval (publish, new term, unresolved citations).
