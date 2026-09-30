# Build a Course Landing Page

Using **NibWP + the Tutor LMS Course Mini-site** skill, build a landing or sales page for a Tutor LMS course that is **bound to the live course data** — title, curriculum, instructor, price — and saved as a WordPress page linked back to the course.

## When to use
- A course exists and needs a page that sells it.
- "Make a landing page for this course."
- Replacing a hand-built sales page that has drifted out of step with the actual curriculum.

## The one law
> **Read the course, don't retype it.**

The page is built from the live course record, so the curriculum on the page is the curriculum in the course. A page that hardcodes the module list is wrong the first time a lesson is added, and nobody notices until a student does.

## Principles
- **Structure that sells** — outcome first, then proof, then curriculum, then price and CTA. Not a feature list.
- **Real numbers only.** Lesson counts, durations and prices come from the course; inventing testimonials or student counts is not on the table.
- **One clear action.** Every section points at enrolling, and the CTA repeats without becoming noise.
- **Linked, not orphaned.** The page is saved against the course so the relationship survives.
- **Draft first** — a sales page is public-facing, and the owner should read it before it is.

## Process
1. **Pick the course.** `nibwp/tutorlms-courses` lists what exists on the site with its real data.
2. **Preflight.** `nibwp/skill-preflight { skill_id:"tutorlms-minisite" }` — confirms Tutor LMS and collects brand and target.
3. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"tutorlms-minisite" }`.
4. **Plan the sections** against the course's own data — what it teaches, who it is for, what it costs.
5. **Build and submit.** `nibwp/tutorlms-minisite-build`, dry run first, then commit. The skill validates the section tree and renders it.
6. **Feedback.** `nibwp/tutorlms-minisite-feedback`.

## Definition of done
- Curriculum, price and instructor on the page match the course record because they were read from it.
- Nothing on the page is invented — no fabricated testimonials, ratings or student counts.
- The page is linked to the course and saved as a draft for review.
- One clear enrolment action, repeated where it belongs.
