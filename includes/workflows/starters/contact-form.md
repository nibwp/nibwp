# Build a contact / lead form
Add a working contact or lead-capture form to a site, then prove it end-to-end: a real submission lands in the inbox and (optionally) in the CRM. Reuse the form plugin that's already installed — never bolt on a second one.

## When to use
- Adding a contact or lead-capture form to a site.
- "Add a contact form", "build a quote/enquiry form", "let people email us from this page".
- Replacing a broken or `mailto:` "form" with a real, validated one.

## Principles
- Detect, never assume: identify the ACTIVE forms plugin before building, and reuse it.
- Required + validated: Name and Email are required; Email is format-validated server-side, not just in the browser.
- Spam-protect every public form: honeypot at minimum, CAPTCHA if exposed or already spammed.
- An admin notification that can't be replied to is broken: always set Reply-To to the submitter's email.
- Never collect sensitive data (passwords, full card numbers, government IDs) through a contact form.
- Not done until a REAL test submission arrives in the inbox — delivery is the deliverable.

## Process
1. **Detect the stack.** Run `nibwp/wp-get-site-info`, then `nibwp/execute-php` to list active plugins and find the forms engine: Fluent Forms, Gravity Forms, WPForms, Contact Form 7, Ninja Forms, or Formidable. Reuse whichever is active. Only if none exists, recommend ONE (default Fluent Forms) and get approval before installing. Also detect the CRM/automation tool (FluentCRM?) and whether an SMTP plugin (FluentSMTP) is configured — note it now; you'll need it for delivery.
2. **Define fields.** Core set: Name (required), Email (required, email-validated), Message (required, textarea). Add Phone or Subject ONLY if the user asks or the use case needs it. Mark required fields and keep the form short — every extra field costs conversions. Use the plugin's native field types via `execute-php` (its API/CRUD), not raw HTML.
3. **Validation + inline errors.** Make required fields required in the form config; set the Email field to the email type so it validates format server-side. Ensure each field shows a clear, specific inline error ("Enter a valid email address"), not a generic page-level failure.
4. **Spam protection.** Enable the form's honeypot. If the page is high-traffic, public, or already getting spam, add CAPTCHA (the plugin's built-in / reCAPTCHA / hCAPTCHA / Turnstile). Confirm keys are present before enabling CAPTCHA, or submissions will silently fail.
5. **Admin notification email.** Configure the admin notification to a verified recipient. Include EVERY submitted field in the body (use the plugin's merge tags/smart tags). Set **Reply-To = the submitter's email field** and a clear subject like "New contact from {name}". Verify the From address is on a domain that passes SPF/DKIM (not a random Gmail), or it lands in spam.
6. **Autoresponder (optional).** If wanted, add a confirmation email TO the submitter: friendly, sets expectations ("We'll reply within 1 business day"), From the site's real address. Keep it plain and non-spammy.
7. **Success behavior.** Set either an inline success message or a thank-you-page redirect. Pick one, make it explicit, and confirm it's reachable.
8. **CRM hook (optional).** If FluentCRM (or another CRM) is present and wanted, map the form to add the contact to a specific list/tag on submit. Use the form↔CRM integration, not a second tool. Defer to the **automation-setup** workflow if the flow grows beyond "add to list".
9. **Embed + render.** Place the form on the target page via its shortcode/block. Load the page (Playwright if available) and confirm it renders, fields show, and there's no JS error.
10. **REAL end-to-end test.** Submit a genuine test entry. Confirm: validation fires on bad input; the entry is saved; the **admin email actually arrives** (check spam) with all fields and a working Reply-To; the autoresponder arrives if enabled; the success message/redirect shows; the CRM tag/list was applied if configured. If the email never arrives, STOP and flag SMTP setup (FluentSMTP) — a saved entry with no email is a failure, not a pass.

## Rules
**Do**
- Detect and reuse the active forms plugin.
- Make Name + Email required and validate Email server-side.
- Add honeypot (and CAPTCHA where exposed).
- Put all fields in the notification and set Reply-To to the submitter.
- Send one REAL submission and confirm the email lands.

**Don't**
- Don't install a second forms plugin when one already works.
- Don't request passwords, full card numbers, or other sensitive data.
- Don't enable CAPTCHA without valid keys, or notifications without verifying delivery.
- Don't email a thank-you From a domain that fails SPF/DKIM.
- Don't call it done on a saved entry alone — the email must arrive.

## Validation
- Active forms plugin reused; no duplicate plugin installed.
- Name/Email required, Email format-validated, helpful inline errors shown.
- Spam protection enabled (honeypot ± CAPTCHA with valid keys).
- Admin notification includes every field, Reply-To = submitter, From passes SPF/DKIM.
- Success message or redirect works; CRM list/tag applied if configured.
- Real test submission arrived in the inbox (not spam); autoresponder verified if enabled.

## Report
Return: forms plugin detected/reused; CRM + SMTP status; fields built (which required); validation + inline errors confirmed; spam protection used; notification recipient + Reply-To + From domain check; autoresponder on/off; success behavior (message or redirect URL); CRM list/tag mapped (if any); page the form is embedded on; **test result** — submitted real entry, email arrived yes/no (and spam/SMTP flag if no); anything needing approval (new plugin, live funnel change).
