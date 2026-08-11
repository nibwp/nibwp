<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill manifest — Tutor LMS Builder.
 *
 * Turns a brief, outline, transcript, URL or pasted content into a complete,
 * validated Tutor LMS course tree (course → topics → lessons → quizzes with
 * questions), then persists it through the NIBWP Tutor LMS integration (L1).
 *
 * Premium skill. Buying it also unlocks the `tutorlms` integration
 * (integration_files), so the builder can call its write paths.
 */

return [
    'id'             => 'tutorlms-builder',
    'name'           => 'Tutor LMS Builder',
    'tagline'        => 'Turn a brief, outline, transcript or URL into a complete, validated Tutor LMS course',
    'description'    => 'Give the agent a topic, an outline, a transcript, a PDF, or a URL and it plans and builds a full Tutor LMS course — topics, lessons, and quizzes with real questions — validated against pedagogy + Tutor schema rules, then persisted via the Tutor LMS integration. Turn any content into a sellable course.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'lms',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['tutorlms'],
    'entitlements'   => ['skill:tutorlms', 'skill:tutorlms-builder', 'integration:tutorlms'],
    'integration_files' => ['tutorlms'],
    'features'       => [
        'Plan a full course curriculum from a brief, outline, transcript, PDF or URL',
        'Topics → lessons hierarchy with real lesson content',
        'Auto-generate quizzes with valid questions (true/false, single, multiple, open-ended)',
        'Pedagogy + Tutor-schema validation before anything is written',
        'Engine-aware pricing (free / paid) wired to the active monetization engine',
        'Course difficulty, duration, prerequisites and instructor assignment',
        'Refine an existing course or lesson with a natural-language instruction',
        'Persists through the audited Tutor LMS integration write paths',
    ],
    'ability_files' => [
        'abilities/build-course.php',
        'abilities/refine.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',

    'triggers' => [
        '/(?i)\b(?:build|create|generate|make|plan|author)\b[^.\n]{0,40}\b(?:course|curriculum|lesson|quiz|lms)\b/',
        '/(?i)\b(?:tutor|tutorlms|tutor lms)\b[^.\n]{0,40}\b(?:course|curriculum|lesson|module|quiz)\b/',
        '/(?i)\b(?:turn|convert)\b[^.\n]{0,40}\b(?:into|to)\b[^.\n]{0,20}\b(?:a )?(?:course|curriculum|lessons?)\b/',
        '/(?i)\b(?:outline|transcript|pdf|url|content)\b[^.\n]{0,30}\b(?:into|to)\b[^.\n]{0,20}\b(?:course|lessons?)\b/',
    ],

    'commands' => [
        '/build-course'    => ['description' => 'Plan + build a full Tutor LMS course from a brief, outline, transcript or URL.'],
        '/course-from'     => ['description' => 'Alias for /build-course.'],
        '/refine-course'   => ['description' => 'Apply a written change to an existing course or lesson by ID.'],
        '/course-preflight'=> ['description' => 'Re-run the per-session Tutor LMS Builder preflight (instructor, category, pricing, structure).'],
    ],

    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'tutorlms-builder'],
                'why'           => 'Resolve instructor, course category, pricing engine + intent, and hierarchy preference. Mints the _preflight_token the builder requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'tutorlms-builder'],
                'why'           => 'Read SKILL.md + course-schema + curriculum rules + checklist + lessons-learned.',
            ],
            [
                'ability'       => 'nibwp/tutorlms-builder-build-course',
                'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Validate the course tree. Surface recommendations (flat structure, missing assessment, thin lessons) to the user.',
            ],
            [
                'ability'       => 'nibwp/tutorlms-builder-build-course',
                'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Persist the course, topics, lessons, quizzes and questions after a clean validation pass.',
            ],
            [
                'ability'       => 'nibwp/tutorlms-builder-feedback',
                'args_template' => ['skill_id' => 'tutorlms-builder'],
                'why'           => 'Record thumb-up/down so future course builds improve.',
            ],
        ],
        'forbidden_actions' => [
            'Call nibwp/wp-create-post or wp_insert_post with raw course content this skill claims',
            'Create a course with zero topics, or a topic with zero lessons and zero quizzes',
            'Create a quiz with zero questions',
            'A single_choice / true_false question without exactly one correct answer',
            'A multiple_choice question without at least one correct answer',
            'price_type "paid" with a price of 0 (or "free" with a non-zero price)',
            'Inline <style>/<script> or raw <form> markup inside lesson content',
            'Persist before a clean dry_run validation pass',
        ],
        'before_answering' => 'Do NOT improvise or write to the DB yourself. Route through the pipeline. Build the course tree as JSON (see course-schema in the playbook), validate it with dry_run:true, then persist. Calling build-course without a valid _preflight_token returns requires_user_input:true.',
    ],

    'preflight_questions' => [
        [
            'key'       => 'instructor_id',
            'prompt'    => 'Which WordPress user should be the course instructor? (user ID)',
            'type'      => 'integer',
            'required'  => true,
            'cache_key' => 'tutorlms_instructor_id',
        ],
        [
            'key'       => 'course_status',
            'prompt'    => 'Publish the course or keep it as a draft?',
            'choices'   => ['draft', 'publish'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'tutorlms_course_status',
        ],
        [
            'key'       => 'pricing',
            'prompt'    => 'Free or paid course?',
            'choices'   => ['free', 'paid'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'tutorlms_pricing',
        ],
        [
            'key'           => 'price',
            'prompt'        => 'Price (in your store currency)?',
            'type'          => 'string',
            'conditional_on'=> ['pricing' => 'paid'],
            'cache_key'     => 'tutorlms_price',
        ],
        [
            'key'       => 'depth',
            'prompt'    => 'Structure: grouped topics → lessons, or a single flat topic?',
            'choices'   => ['topics', 'flat'],
            'type'      => 'enum',
            'required'  => true,
            'cache_key' => 'tutorlms_depth',
        ],
    ],
];
