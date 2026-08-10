# Safe changes (plan → approve → apply)
The default operating rule for touching any live site: propose a plan, get a yes, then apply the smallest reversible change. Every other workflow defers to this one.

## When to use
- Any change to a live, client, or production site — content, config, code, plugins, redirects, or data.
- Whenever a task would write, delete, or alter anything the site owner depends on.

## Principles
- The user owns the site. You propose; they decide. Never apply a change you haven't named out loud first.
- Reversible beats fast. Prefer a change you can undo in one step over a clever one you can't.
- Smallest change that solves it. No refactors, no cleanups, no "while I'm here" extras.
- Surface risk before acting — deletions, DB/schema writes, code in functions.php, redirects, payment/email flows.
- Drafts and Sandbox snippets over live theme edits. Disable, don't delete. Stage, don't publish.
- Detect the real stack before recommending anything (theme, builder, active plugins).

## Process
1. **Understand.** Restate the goal in one sentence. Inspect read-only first: `nibwp/wp-get-site-info` for WP/PHP/theme, `wp-list-posts`/`wp-list-users` for affected content, `nibwp/execute-php` to read config/DB values, `nibwp/read-file`/`list-directory` to read code. Check the Audit Log for recent related changes.
2. **Plan.** Write exactly what will change, where (file/post/option/table), and the expected effect. List every step in order. Explicitly flag anything risky: deletions, DB/schema changes, code in functions.php or mu-plugins, redirects, payment or email behavior.
3. **Get explicit approval.** Present the plan and stop. Do not write anything until the user says yes. If they change scope, re-plan and re-confirm.
4. **Apply in smallest safe increments.** One logical change at a time. Ship code as a Sandbox file/snippet via `nibwp/execute-php`, never by editing theme files in place. Create content as a draft, not published. Back up before any bulk or DB operation.
5. **Verify.** Confirm the change did what the plan said and broke nothing adjacent — reload the affected page/area, re-read the option/row, re-run the relevant check.
6. **Summarize.** Report what changed, why, how you verified, and the exact rollback step.

## Rules
**Do**
- State the full plan before the first write.
- Make each increment independently reversible.
- Keep a known-good path back at every step (backup, draft, revision, disabled file).
- Re-confirm if the approved scope changes mid-task.

**Don't**
- Don't write, delete, or publish before approval.
- Don't edit `functions.php` or theme files directly — use a Sandbox snippet.
- Don't run bulk/DB operations without a backup.
- Don't bundle unrelated changes into one approval.

## Rollback
Always leave a known-good path back before applying, and state the exact rollback step in the summary:
- **Content:** save as draft or rely on the post revision; rollback = restore the prior revision or trash the draft.
- **Code:** ship as a Sandbox snippet that can be toggled off; rollback = disable the snippet (don't delete and rebuild).
- **Files:** disable, don't delete (rename/deactivate); rollback = re-enable the original.
- **Options/DB:** record the current value (or take a backup) before writing; rollback = restore the recorded value/backup.
A change with no rollback path is not approved — pause and propose a reversible alternative.

## Report
- **Changed:** what was altered, and exactly where (file/post/option/table).
- **Why:** the goal it served.
- **Verified:** how you confirmed it worked and broke nothing.
- **Rollback:** the precise step to undo it.
