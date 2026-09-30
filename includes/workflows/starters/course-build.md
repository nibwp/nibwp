# Build a Tutor LMS Course

Using **NibWP + the Tutor LMS integration** and the **Tutor LMS Builder** skill, turn a brief, outline, transcript, PDF or URL into a **complete, validated course** — topics, lessons, and quizzes with real questions — persisted through the integration rather than typed in by hand.

## When to use
- "Build a course about X", from a brief or an outline.
- Turning an existing transcript, PDF, webinar or article series into a structured curriculum.
- Filling out a course that exists as a title and nothing else.

## The one law
> **Synthesize a real curriculum, not a table of contents.**

A list of lesson titles is not a course. Each lesson needs actual content, each quiz needs real questions with correct answers, and the sequence has to teach something in an order that makes sense. The skill validates the tree before it is written, so a thin outline is refused rather than published.

## Principles
- **Structure before prose.** Decide the topics and the arc first; write lesson bodies against that skeleton.
- **Quizzes test the lesson**, not trivia — questions drawn from what the preceding lessons actually taught, with the right answer marked.
- **Realistic scope.** A course with forty one-paragraph lessons is worse than one with twelve substantial ones.
- **Persist through the integration.** The course tree goes through `nibwp/tutorlms-builder-build-course`, which validates it; writing Tutor LMS post types by hand skips every check.
- **Draft first** when the course is for a real audience, so a human reads it before students do.

## Process
1. **Preflight.** `nibwp/skill-preflight { skill_id:"tutorlms-builder" }` — confirms Tutor LMS is active and collects the course intent.
2. **Load the playbook.** `nibwp/load-skill-playbook { skill_id:"tutorlms-builder" }`.
3. **Gather the source.** A brief, an outline, a transcript, a PDF, or a URL. If the user gave only a title, ask what the course should make someone able to do.
4. **Plan the tree** — topics, lessons per topic, where quizzes belong. Show it to the user before writing the content.
5. **Write and submit.** `nibwp/tutorlms-builder-build-course`, dry run first; fix what the verdict names, then commit.
6. **Refine.** `nibwp/tutorlms-builder-refine` for changes to an existing course — it edits in place instead of rebuilding.
7. **Feedback.** `nibwp/tutorlms-builder-feedback`.

## Definition of done
- Every lesson has real content, not a placeholder.
- Every quiz question has a correct answer and comes from the lessons before it.
- The course tree validated and persisted through the ability.
- The user has seen the structure and agreed to it.
