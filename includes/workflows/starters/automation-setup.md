# Build an automation (trigger → action)
Design and build an automated flow — a trigger fires, conditions filter, actions run — in whatever automation tool the site already uses. Map it on paper and get approval BEFORE you build, then prove every action fires with a real trigger.

## When to use
- Automating a flow: form → email + tag, new order → fulfillment, signup → onboarding sequence.
- "When someone submits X, do Y"; "tag people who buy"; "send a welcome series".
- Connecting a form/store event to email, CRM tags, sequences, tasks, or a webhook.

## Principles
- Map before you build: write TRIGGER → CONDITIONS → ACTIONS on paper and get approval first.
- Detect, never assume: build in the tool already installed (FluentCRM, Fluent Forms, WooCommerce + CRM), don't introduce a new one.
- Idempotent + safe: handle duplicates, unsubscribes, and failures so the flow can't loop, double-send, or email people who opted out.
- Never silently email a list — a live automation that sends mail needs explicit sign-off.
- No live automation ships without a successful real-trigger test.
- Document it so the next person (or you, later) can find and change it.

## Process
1. **Detect the stack.** Run `nibwp/wp-get-site-info` and `nibwp/execute-php` to find the automation engine: FluentCRM automations, Fluent Forms confirmations/integrations, WooCommerce (+ its CRM bridge), or a webhook/Zapier endpoint. Confirm what triggers and actions that tool actually supports. Check SMTP (FluentSMTP) is configured if the flow sends email.
2. **Map the automation (on paper).** Write it out explicitly:
   - **TRIGGER** — the one event that starts it (form submitted, order completed, user registered, tag applied).
   - **CONDITIONS / filters** — who qualifies (specific form, product, list, country, order value).
   - **ACTIONS** — ordered: send email, add/remove tag, add to sequence, create task, fire webhook. Include delays/waits.
   Present this map and **get approval before building** — especially if any action emails real people.
3. **Build the trigger + filters.** In the detected tool, create the automation, set the single trigger, and add the conditions from the map so it only runs for the right people. Don't over-broaden the entry point.
4. **Build the actions in order.** Add each action exactly as mapped, in sequence, with any waits. Use real, existing assets — link the actual email template, the actual tag/list, the actual sequence. Never invent a tag or list name; create it deliberately if it's missing.
5. **Handle edge cases.** Add a goal/exit or "skip if already tagged" so contacts don't loop or get double-processed. Respect unsubscribes and consent (don't re-add removed contacts). Decide what happens on failure (a payment that never completes, a webhook that 500s) — don't leave people stranded mid-sequence.
6. **Test end-to-end with a REAL trigger.** Fire the actual trigger with a test contact/order you control: submit the form, place a test order, register a user. Then confirm EACH action fired — the right email actually sent and arrived (check spam), the right tag/list applied, the sequence enrolled, the task/webhook fired. Inspect the automation's run log to verify it ran once, not zero or twice.
7. **Activate only after a clean test.** Flip the automation live only once the test passes. If email didn't arrive, STOP and flag SMTP before activating.
8. **Document + store in Memory.** Record the trigger, conditions, ordered actions, and exactly where it lives (which tool, which automation name/ID). Save it via Memory (`memory-store`) so the flow is discoverable and editable later.

### Worked examples
- **Lead-magnet delivery** — Trigger: contact-form submitted on the guide page → Condition: that form only → Actions: tag `lead-magnet`, send email with the download link, add to "nurture" sequence. Edge: skip if already tagged.
- **Abandoned-checkout follow-up** — Trigger: WooCommerce checkout started, order not completed after 1h → Condition: cart value over threshold, not already purchased → Actions: wait 1h, send reminder email, wait 1 day, send a second nudge. Exit the moment the order completes.
- **New-customer onboarding** — Trigger: order completed → Condition: first order → Actions: tag `customer`, remove `prospect`, enroll in 3-email onboarding sequence, create a fulfillment task. Edge: don't re-enroll repeat buyers.

## Rules
**Do**
- Map TRIGGER → CONDITIONS → ACTIONS and get approval before building.
- Build in the tool already installed; reuse real templates, tags, and lists.
- Add filters, goals/exits, and dedupe so it can't loop or double-send.
- Fire a REAL trigger and confirm every action fired before going live.
- Document the flow and store it in Memory.

**Don't**
- Don't email a list silently or enable a live automation without sign-off.
- Don't introduce a new automation tool when one is already in use.
- Don't invent tag/list/sequence names — create them deliberately.
- Don't activate on an untested or partially-tested flow.
- Don't ignore unsubscribes, consent, or failure paths.

## Validation
- Automation map (trigger, conditions, ordered actions) approved before build.
- Built in the detected tool; trigger + filters scoped to the right audience.
- Edge cases handled: dedupe/goal, unsubscribe respect, failure path.
- Real-trigger test fired EACH action — email arrived, tag/list applied, sequence/task/webhook ran (verified in the run log).
- Activated only after a clean test; SMTP confirmed if email is sent.
- Flow documented and stored in Memory.

## Report
Return: automation tool detected; the approved map (trigger → conditions → actions, with delays); assets used (email templates, tags, lists, sequences, webhook); edge cases handled (dedupe, unsubscribe, failure); **test result** — real trigger fired, each action confirmed (email arrived yes/no, tag applied, etc.) from the run log; live/activated status; where the automation lives (tool + name/ID) and that it was stored in Memory; anything needing approval (sending to a real list, touching a live funnel).
