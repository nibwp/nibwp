<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill manifest — Tutor LMS Course Mini-site.
 *
 * Builds a styled landing / sales page for a Tutor LMS course, bound to the
 * course data (the L1 course DTO), and persists it as a WordPress page linked
 * back to the course. Theme-neutral output (portable HTML + a scoped style).
 *
 * Premium skill; unlocks the `tutorlms` integration (integration_files).
 */

return [
    'id'             => 'tutorlms-minisite',
    'name'           => 'Tutor LMS Course Mini-site',
    'tagline'        => 'Generate a styled landing / sales page for any Tutor LMS course',
    'description'    => 'Point it at a Tutor LMS course and it builds a polished landing micro-site — hero, what-you-will-learn, curriculum, instructor, pricing, FAQ and an enroll CTA — bound to the live course data and saved as a WordPress page linked to the course.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.1',
    'category'       => 'lms',
    'premium'        => true,
    'price'          => 39,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['tutorlms'],
    'entitlements'   => ['skill:tutorlms', 'skill:tutorlms-minisite', 'integration:tutorlms'],
    'integration_files' => ['tutorlms'],
    'features'       => [
        'One styled landing page per course, from the live course data',
        'Hero, what-you-will-learn, curriculum, instructor, pricing, FAQ, enroll CTA',
        'Theme-neutral, self-contained output (no page builder required)',
        'Bound to the course DTO — title, price, curriculum, instructor pulled in',
        'Saved as a draft WordPress page linked back to the course',
        'Validated structure before anything is written',
    ],
    'ability_files' => [
        'abilities/build.php',
        'abilities/feedback.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',

    'triggers' => [
        '/(?i)\b(?:build|create|generate|make)\b[^.\n]{0,40}\b(?:landing|sales|mini[- ]?site|micro[- ]?site)\b[^.\n]{0,20}\b(?:page|site)?\b/',
        '/(?i)\b(?:course)\b[^.\n]{0,30}\b(?:landing|sales|mini[- ]?site|page)\b/',
        '/(?i)\b(?:landing|sales)\s*page\b[^.\n]{0,30}\b(?:for|of)\b[^.\n]{0,20}\b(?:course|tutor)\b/',
    ],

    'commands' => [
        '/course-minisite' => ['description' => 'Build a styled landing page for a Tutor LMS course.'],
        '/course-landing'  => ['description' => 'Alias for /course-minisite.'],
        '/minisite-preflight' => ['description' => 'Re-run the per-session mini-site preflight (course, page status).'],
    ],

    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            ['ability' => 'nibwp/skill-preflight', 'args_template' => ['skill_id' => 'tutorlms-minisite'], 'why' => 'Resolve which course + page status. Mints the _preflight_token.'],
            ['ability' => 'nibwp/load-skill-playbook', 'args_template' => ['skill_id' => 'tutorlms-minisite'], 'why' => 'Read SKILL.md + section schema. First call nibwp/tutorlms-courses action=get to read the course DTO.'],
            ['ability' => 'nibwp/tutorlms-minisite-build', 'args_template' => ['dry_run' => true, '_preflight_token' => '{from_preflight.token}'], 'why' => 'Validate the section tree.'],
            ['ability' => 'nibwp/tutorlms-minisite-build', 'args_template' => ['dry_run' => false, '_preflight_token' => '{from_preflight.token}'], 'why' => 'Persist the landing page linked to the course.'],
            ['ability' => 'nibwp/tutorlms-minisite-feedback', 'args_template' => ['skill_id' => 'tutorlms-minisite'], 'why' => 'Record thumb-up/down.'],
        ],
        'forbidden_actions' => [
            'Invent course facts — read them with nibwp/tutorlms-courses action=get (the course DTO) first',
            'Build a mini-site with no hero or no enroll CTA',
            'Inline <script> in the page content',
            'Persist before a clean dry_run validation pass',
        ],
        'before_answering' => 'Do NOT improvise. First read the course DTO via nibwp/tutorlms-courses action=get, then synthesize the section tree (see section-schema), validate with dry_run:true, then persist.',
    ],

    'preflight_questions' => [
        ['key' => 'course_id', 'prompt' => 'Which Tutor LMS course (post ID) is this landing page for?', 'type' => 'integer', 'required' => true, 'cache_key' => 'tutorlms_minisite_course_id'],
        ['key' => 'page_status', 'prompt' => 'Publish the landing page or keep it as a draft?', 'choices' => ['draft', 'publish'], 'type' => 'enum', 'required' => true, 'cache_key' => 'tutorlms_minisite_page_status'],
    ],
];
