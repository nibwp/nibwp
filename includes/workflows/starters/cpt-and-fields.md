# Custom post type + fields
Model new structured content as a prefixed custom post type with custom fields, registered cleanly via the Sandbox and the site's detected field plugin — never by hand-editing the theme.

## When to use
- Modeling new structured content that needs its own admin section and typed fields.
- A request describes "a list of X with fields Y/Z" that doesn't fit a page or a WooCommerce product.

## Principles
- Model the entity on paper FIRST — name, every field + type, hierarchy, taxonomies — before writing code.
- Prefix the CPT slug and every field key; treat both as permanent (renaming orphans data).
- Register code via a Sandbox file/snippet through `nibwp/execute-php` — never edit theme files.
- Detect the field plugin before modeling fields; don't assume ACF.
- Structured data belongs in fields, not stuffed into the post body.
- A generic slug (e.g. `event`, `team`) risks colliding with a plugin — always namespace it.

## Process
1. **Model the entity.** Write down the singular/plural name, and each field with its type (text, number, image, URL, date, relationship, repeater, etc.). Decide: hierarchical or flat? Which taxonomies (categories/tags) apply? What must be required vs optional? Confirm this model with the user if anything is ambiguous.
2. **Detect the field plugin.** Determine which is active — ACF, Meta Box, JetEngine, or Pods — via `nibwp/execute-php` (check active plugins). All field work below targets the detected plugin's API; do not mix plugins.
3. **Register the CPT.** Via a Sandbox file/snippet with `register_post_type`. Use a prefixed slug (e.g. `acme_event`), proper singular/plural labels, `public` and `show_in_rest` as the front end/AI need, `supports` (title, editor, thumbnail as appropriate), `has_archive`, `hierarchical` per the model, and a clear `menu_icon` (dashicon).
4. **Register taxonomies.** Register any needed taxonomies with `register_taxonomy`, prefixed and attached to the CPT, before or alongside the CPT so terms are available immediately.
5. **Build the field group.** In the detected field plugin, create a field group bound to the CPT. Use prefixed field keys, set required flags and sensible defaults, and group fields logically (tabs/sections) so the editor is readable.
6. **Admin UX.** Give a clear menu label + icon. Add useful, sortable admin columns for the key fields (e.g. date, status) via the appropriate hooks so editors can scan and order the list.
7. **Expose REST.** Ensure `show_in_rest` is true on the CPT (and field group, where the plugin supports it) if the front end or the AI needs to read/write entries via the API.
8. **Wire output.** Provide the front-end rendering path: archive + single template, or a block/shortcode that outputs the fields. Don't leave the data invisible.
9. **Verify end-to-end.** Create ONE sample entry: enter data into every field type, save, and confirm it persists and renders on the front end (and via REST if exposed). Fix anything that doesn't round-trip, then leave the sample as a draft or remove it per the user's preference.

## Rules
**Do**
- Prefix the CPT slug and every field key, and keep them stable.
- Detect the active field plugin and use only its API.
- Set `show_in_rest` when the front end or AI needs the data.
- Verify with one real sample entry before declaring done.

**Don't**
- Hand-edit theme files — register everything via the Sandbox.
- Use a generic, collision-prone slug like `event` or `product`.
- Store structured data in the post body when a field is the right home.
- Rename a shipped CPT/field slug (it orphans existing data).

## Validation
- CPT registered with prefixed slug, correct labels, supports, hierarchy, archive, and menu icon.
- Taxonomies registered, prefixed, and attached to the CPT.
- Field group bound to the CPT with prefixed keys, required flags, and logical grouping.
- Sortable admin columns present for key fields; clear menu label/icon.
- `show_in_rest` set where needed and the data reads back over REST.
- Output path (template or block/shortcode) renders the fields.
- One sample entry saved and rendered successfully end-to-end.

## Report
Return: CPT slug + labels, hierarchical?, supports, menu icon; taxonomies registered; detected field plugin and the field group name; every field with key + type + required; admin columns added; REST exposure (yes/no); output method (template vs block/shortcode); and confirmation that a sample entry was created, saved, and rendered (with what was left as draft or cleaned up).
