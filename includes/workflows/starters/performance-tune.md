# Performance tune-up
Make a slow site measurably faster by baselining first, fixing the dominant cost, and re-measuring after every single change. No guesswork, no shotgun optimization.

## When to use
- A site feels slow, or fails Core Web Vitals (LCP, CLS, INP).
- After a redesign, plugin change, or traffic complaint about speed.

## Principles
- Measure before you touch anything. No baseline = no proof you helped.
- One change at a time, then re-measure. Bundled changes hide which one mattered (or broke things).
- Fix in order of impact, not ease.
- Never trade away functionality or tracking to win a score.
- Detect the stack first (host, theme, builder, existing cache/optimization plugins) before recommending tools.

## Process
1. **Baseline.** Measure a representative home page AND one inner page: load time, total page weight, and LCP/CLS/INP on both mobile and desktop. Record numbers — this is what every later change is judged against. Use `nibwp/wp-get-site-info` for stack and `nibwp/execute-php` (read-only) to inspect cache plugins, autoloaded options, and DB state.
2. **Diagnose the dominant cost.** Identify the biggest contributor before fixing anything:
   - Images: oversized, uncompressed, not lazy-loaded, wrong format (no WebP/AVIF).
   - Render-blocking CSS/JS; unused assets from plugins loading site-wide.
   - No page cache, no object cache, no CDN.
   - Server/DB: slow queries, autoloaded-options bloat, no opcache.
   - Heavy third-party embeds, web fonts, and trackers.
3. **Fix in impact order, ONE change at a time, re-measuring after each.** Typical quick-win order:
   1. Compress/resize/lazy-load images + serve modern formats (WebP/AVIF).
   2. Add page cache + CDN.
   3. Defer/async non-critical JS; remove unused CSS/JS.
   4. Preload the hero image and critical font; subset fonts.
   5. Trim autoloaded options; clean revisions and stale transients.
4. **Back up before swapping** any caching/optimization plugin or running DB cleanup.
5. **Re-measure** the same two pages after each change; keep it only if numbers improved and the front end still looks/works right.
6. **Report** Before → After.

## Rules
**Do**
- Back up before swapping caching/optimization plugins or cleaning the DB.
- Re-measure on the same pages and devices each time.
- Visually check the front end after every minify/combine/defer change.

**Don't**
- Don't minify/combine CSS/JS without testing the live front end.
- Don't strip tracking, analytics, or functional scripts just to raise a score.
- Don't apply several optimizations at once.

## Validation
- Same two pages, same mobile+desktop conditions, baseline vs after.
- Front end verified visually after each change — no broken layout, no missing functionality.
- Improvements are real numbers, not just a higher grade letter.

## Report
```
# Performance tune-up — <site> (<date>)
Page tested: <home> / <inner>

           Before        After
LCP        <s> / <s>     <s> / <s>   (mobile/desktop)
CLS        <v> / <v>     <v> / <v>
INP        <ms>/ <ms>    <ms>/ <ms>
Weight     <KB>          <KB>

Changes applied (in order): ...
Remaining bottleneck: <what's still the biggest cost, and why left>
```
