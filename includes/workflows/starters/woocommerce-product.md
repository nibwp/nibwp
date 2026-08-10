# Create a WooCommerce product
Add or optimize a single WooCommerce product end-to-end — type, pricing, media, copy, variations, inventory, shipping, taxonomy, and SEO — without touching global store settings.

## When to use
- Adding a new product to the catalog, or optimizing an existing listing.
- A request mentions a product name, SKU, price, variations, or "list/sell this item".

## Principles
- Confirm the product TYPE first — it dictates which fields exist and what "done" means.
- Real money is involved: never ship a €0 price, placeholder image, or empty description.
- Touch the product only. Global tax, currency, and shipping settings are off-limits here.
- Reuse existing categories, tags, and attributes before creating new ones.
- Every variation of a variable product must be individually priced and stock-correct.
- Save as DRAFT for review unless the user explicitly says publish live.

## Process
1. **Confirm the type before anything else.** Ask/decide: simple, variable, grouped, or virtual+downloadable. This shapes every later step — variable needs attributes+variations; virtual skips shipping; grouped references child products. Do not proceed on a guess.
2. **Discover the catalog.** Use `nibwp/wc-list-products` (and the WooCommerce abilities) to see existing categories, tags, attributes, and naming conventions. For an optimization task, load the current product first and note what's missing or weak.
3. **Core fields.** Set name, a unique SKU, and a regular price. Add a sale price only if requested; if so set its schedule (from/to dates). Confirm the price > 0 and the SKU does not collide with an existing product.
4. **Images.** Set a main image plus a gallery. Each image needs descriptive `alt` text (what the product IS, not "image1"). Use web-sized, compressed files. Never leave the featured image empty or a placeholder.
5. **Descriptions.** Write a benefit-led long description (what the buyer gets, who it's for, why it's better) and a tight short description (1–3 lines, the hook + key spec) for the summary area. No lorem ipsum, no empty body.
6. **Attributes & variations (variable only).** Define attributes (e.g. Size, Color), reusing global attributes where they exist. Generate variations and set per-variation price, SKU, stock qty, and image. A variation with no price will not be purchasable — fill every one.
7. **Inventory.** Set stock status (in stock / out of stock / on backorder). If managing stock, set quantity and backorder policy. For variable products, stock lives per variation.
8. **Shipping.** For physical goods set weight and dimensions, and assign a shipping class only if one already exists. For virtual/downloadable, mark virtual (and downloadable + upload the file) so shipping is correctly skipped.
9. **Categories + tags.** Assign at least one existing category and relevant existing tags. Create a new category only if clearly needed and named consistently with siblings.
10. **SEO.** Use the SEO abilities (`seo-update-post-meta`, `seo-schema-markup`) to set an SEO title and meta description, and emit Product schema with price, availability, and brand. Keep it engine-agnostic.
11. **Save as DRAFT.** Save for review. Only set live status if the user explicitly approved publishing (defer to the "Safe changes" workflow for go-live).

## Rules
**Do**
- Confirm product type before building anything.
- Reuse existing categories, tags, and global attributes.
- Verify every variation has a price and a correct stock state.
- Write real, benefit-led copy and descriptive alt text.

**Don't**
- Change global tax, currency, or shipping settings as a side effect.
- Publish with a €0/empty price, placeholder image, or empty description.
- Invent a duplicate SKU or a redundant near-identical category.
- Auto-publish live without explicit approval.

## Validation
- Type is correct and matches the fields populated.
- Price > 0; SKU unique; sale schedule valid if a sale price is set.
- Featured image + gallery present, all with meaningful alt text.
- Long + short descriptions both non-empty and on-message.
- Variable: every variation priced, SKU'd, and stock-correct.
- Physical: weight/dimensions set; virtual: marked virtual (file attached if downloadable).
- At least one category + relevant tags; SEO title, meta, and Product schema present.
- Status is Draft (or live only with explicit approval).

## Report
Return: product name + ID, type, status (draft/live), price (and sale + schedule if any), SKU, image count, variation count with confirmation all are priced, inventory state, shipping (physical dims or "virtual"), categories/tags assigned, SEO fields + schema set, and any items left for the user to review or decide.
