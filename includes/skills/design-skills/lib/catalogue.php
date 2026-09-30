<?php

declare(strict_types=1);

/**
 * The knowledge base, queried — never handed over.
 *
 * The tables are 364 KB. Putting them in front of an agent would cost more
 * tokens than the page it is building, so nothing here returns a table: the
 * queries return the one row that matched and the few fields that matter.
 *
 * Choices that are not determined by the site are seeded from the site's own
 * identity, so the same site asked twice gets the same answer and two different
 * sites get different ones. That is what stops every NibWP install shipping the
 * same landing page.
 */

if (!defined('ABSPATH')) {
    exit();
}

function nibwp_design_kb_dir(): string
{
    return __DIR__ . '/../kb/';
}

/**
 * Read a CSV table as rows keyed by column name.
 *
 * Cached per request. The files never change at runtime, and a page build asks
 * for several tables in one call.
 *
 * @return array<int, array<string, string>>
 */
function nibwp_design_table(string $name): array
{
    static $cache = [];

    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $path = nibwp_design_kb_dir() . $name;
    if (!file_exists($path)) {
        $cache[$name] = [];
        return [];
    }

    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        $cache[$name] = [];
        return [];
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        $cache[$name] = [];
        return [];
    }

    // A BOM on the first header cell would make that column unfindable by name.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

    while (($line = fgetcsv($handle)) !== false) {
        if ($line === [null] || $line === []) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[trim((string) $col)] = isset($line[$i]) ? (string) $line[$i] : '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    $cache[$name] = $rows;

    return $rows;
}

/**
 * A stable number for this site, so catalogue choices are consistent here and
 * different elsewhere.
 *
 * Keyed on the site's own address plus whatever is being built: two pages of the
 * same kind on one site agree, a pricing page and a homepage may differ, and the
 * site next door lands somewhere else entirely.
 */
function nibwp_design_seed(string $subject = ''): int
{
    $material = (string) home_url() . '|' . strtolower($subject);

    return (int) hexdec(substr(md5($material), 0, 8));
}

/**
 * Find the row whose keywords best match what the user asked for.
 *
 * Deliberately simple: score a row by how many of its keywords appear in the
 * request, prefer the longest keyword matched so "tour operator" beats "tour",
 * and fall back to the seeded pick when nothing matches at all.
 *
 * The seeded pick knows nothing about the request, so a caller can narrow the
 * rows it draws from. If the narrowing leaves nothing, every row is back in play:
 * an answer that is not ideal beats no answer.
 *
 * @param array<int, array<string, string>> $rows
 * @param (callable(array<string, string>): bool)|null $fallback_allows
 * @return array<string, string>|null
 */
function nibwp_design_match(
    array $rows,
    string $needle,
    string $keyword_column,
    string $seed_subject = '',
    ?callable $fallback_allows = null
): ?array {
    if ($rows === []) {
        return null;
    }

    $needle = strtolower(trim($needle));
    $best = null;
    $best_score = 0;

    foreach ($rows as $row) {
        $keywords = strtolower((string) ($row[$keyword_column] ?? ''));
        if ($keywords === '') {
            continue;
        }

        $score = 0;
        foreach (preg_split('/[;,]/', $keywords) ?: [] as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '' || mb_strlen($keyword) < 3) {
                continue;
            }
            if ($needle !== '' && str_contains($needle, $keyword)) {
                // Longer matches are more specific, so weight by length.
                $score = max($score, mb_strlen($keyword));
            }
        }

        if ($score > $best_score) {
            $best_score = $score;
            $best = $row;
        }
    }

    if ($best !== null) {
        return $best;
    }

    $pool = $fallback_allows === null ? $rows : array_values(array_filter($rows, $fallback_allows));
    if ($pool === []) {
        $pool = $rows;
    }

    return $pool[nibwp_design_seed($seed_subject) % count($pool)];
}

/**
 * Whether the purpose names one section or component rather than a whole page.
 *
 * Every layout pattern is a page's plan. Asked for a "5-step process timeline
 * section", the keyword match found no page and the seeded fallback answered
 * with a blog index — Latest, By topic, Archive — for a single timeline. A
 * section joins a page that already has its order, so it gets no order of its
 * own.
 *
 * Page words win: "landing page with a pricing section" is still a page.
 */
function nibwp_design_is_section(string $purpose): bool
{
    if (preg_match('/\b(pages?|homepages?|websites?|sites?)\b/i', $purpose)) {
        return false;
    }

    return (bool) preg_match(
        '/\b(sections?|blocks?|components?|hero(es)?|ctas?|banners?|timelines?|steps?|process(es)?|grids?|bento|cards?|faqs?|testimonials?|pricing|features?|stats?|headers?|footers?|nav|navbar|menus?)\b/i',
        $purpose
    );
}

/**
 * The layout pattern for what is being built — ours, WordPress-shaped.
 *
 * Null for a section: see nibwp_design_is_section().
 *
 * @return array<string, string>|null
 */
function nibwp_design_layout_for(string $purpose): ?array
{
    if (nibwp_design_is_section($purpose)) {
        return null;
    }

    return nibwp_design_match(
        nibwp_design_table('wordpress/layout-patterns.csv'),
        $purpose,
        'Keywords',
        'layout:' . $purpose
    );
}

/**
 * How this builder wants a direction expressed.
 *
 * @return array<string, string>|null
 */
function nibwp_design_builder_notes(string $builder): ?array
{
    foreach (nibwp_design_table('wordpress/builder-notes.csv') as $row) {
        if (strtolower((string) ($row['Builder'] ?? '')) === strtolower($builder)) {
            return $row;
        }
    }

    return null;
}

/**
 * The bans that apply to this page.
 *
 * Rules scoped to a page type ("landing") come back only for that type; rules
 * scoped to "any" always do. Each carries its reason, so the agent can explain
 * a choice rather than cite a rule.
 *
 * A section only ever gets the "any" rules. The scopes name kinds of page, and
 * matching is by word, so "services bento grid" used to collect the services
 * page's rules without being a services page.
 *
 * @return array<int, array{rule: string, refuse: string, instead: string, why: string}>
 */
function nibwp_design_rules_for(string $purpose): array
{
    $section = nibwp_design_is_section($purpose);
    $purpose = strtolower($purpose);
    $out = [];

    foreach (nibwp_design_table('wordpress/anti-generic.csv') as $row) {
        $applies = strtolower((string) ($row['Applies To'] ?? 'any'));

        if ($applies !== 'any' && $applies !== '') {
            if ($section) {
                continue;
            }
            $hit = false;
            foreach (explode(';', $applies) as $scope) {
                $scope = trim($scope);
                if ($scope !== '' && str_contains($purpose, $scope)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }

        $out[] = [
            'rule' => (string) ($row['Rule'] ?? ''),
            'refuse' => (string) ($row['Refuse'] ?? ''),
            'instead' => (string) ($row['Instead'] ?? ''),
            'why' => (string) ($row['Why'] ?? ''),
        ];
    }

    return $out;
}

/**
 * A visual style from the imported catalogue, matched to what is being built.
 *
 * @return array<string, string>|null
 */
function nibwp_design_style_for(string $purpose, string $product_type = ''): ?array
{
    $rows = nibwp_design_table('styles.csv');
    $needle = trim($purpose . ' ' . $product_type);

    // When no style's keywords match, the seeded pick is blind, and it has handed
    // requests a style whose own notes rule that kind of work out. The least the
    // blind pick can do is skip styles that name the request as a bad fit.
    $words = nibwp_design_significant_words($needle);
    $allows = static function (array $row) use ($words): bool {
        $avoid = strtolower((string) ($row['Do Not Use For'] ?? ''));
        foreach ($words as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '/', $avoid)) {
                return false;
            }
        }
        return true;
    };

    return nibwp_design_match($rows, $needle, 'Keywords', 'style:' . $needle, $allows);
}

/**
 * The words of a request that say what it is about.
 *
 * Short words and the words every request shares ("section", "page") would rule
 * out styles for reasons that have nothing to do with the request. A trailing
 * "s" is dropped and matched as a prefix, so "services" still finds "service".
 *
 * @return array<int, string>
 */
function nibwp_design_significant_words(string $text): array
{
    $common = ['with', 'that', 'this', 'from', 'into', 'about', 'your', 'their', 'page', 'section', 'block', 'component', 'site', 'website'];
    $out = [];

    foreach (preg_split('/[^a-z]+/', strtolower($text)) ?: [] as $word) {
        if (strlen($word) > 4 && str_ends_with($word, 's')) {
            $word = substr($word, 0, -1);
        }
        if (strlen($word) >= 4 && !in_array($word, $common, true)) {
            $out[$word] = $word;
        }
    }

    return array_values($out);
}

/**
 * A font pairing, matched on mood and use.
 *
 * @return array<string, string>|null
 */
function nibwp_design_type_for(string $purpose, string $mood = ''): ?array
{
    $rows = nibwp_design_table('typography.csv');
    $needle = trim($mood . ' ' . $purpose);

    $match = nibwp_design_match($rows, $needle, 'Mood/Style Keywords', 'type:' . $needle);
    if ($match !== null) {
        return $match;
    }

    return nibwp_design_match($rows, $needle, 'Best For', 'type:' . $needle);
}

/**
 * The catalogue's palette for a product type — a seed for our own color maths,
 * used only when the site itself offered nothing.
 *
 * @return array<int, string>
 */
function nibwp_design_palette_seeds_for(string $product_type): array
{
    $row = nibwp_design_match(
        nibwp_design_table('colors.csv'),
        $product_type,
        'Product Type',
        'palette:' . $product_type
    );

    if ($row === null) {
        return [];
    }

    $seeds = [];
    foreach (['Primary', 'Accent', 'Secondary'] as $key) {
        $hex = nibwp_design_normalize_hex((string) ($row[$key] ?? ''));
        if ($hex !== '') {
            $seeds[] = $hex;
        }
    }

    return $seeds;
}

/**
 * UX guidelines that bear on this page, trimmed to the few that matter.
 *
 * Severity first: an agent reading five rules will apply them, an agent reading
 * ninety-eight will apply none.
 *
 * @return array<int, array{issue: string, do: string, dont: string}>
 */
function nibwp_design_ux_rules(string $purpose, int $limit = 6): array
{
    $rows = nibwp_design_table('ux-guidelines.csv');
    if ($rows === []) {
        return [];
    }

    $scored = [];
    $needle = strtolower($purpose);

    foreach ($rows as $row) {
        $severity = strtolower((string) ($row['Severity'] ?? ''));
        $weight = match (true) {
            str_contains($severity, 'critical') => 3,
            str_contains($severity, 'high') => 2,
            default => 1,
        };

        $haystack = strtolower((string) ($row['Category'] ?? '') . ' ' . (string) ($row['Issue'] ?? ''));
        if ($needle !== '' && str_contains($haystack, $needle)) {
            $weight += 2;
        }

        $scored[] = ['w' => $weight, 'row' => $row];
    }

    usort($scored, static fn(array $a, array $b): int => $b['w'] <=> $a['w']);

    $out = [];
    foreach (array_slice($scored, 0, $limit) as $entry) {
        $out[] = [
            'issue' => (string) ($entry['row']['Issue'] ?? ''),
            'do' => (string) ($entry['row']['Do'] ?? ''),
            'dont' => (string) ($entry['row']["Don't"] ?? ''),
        ];
    }

    return $out;
}
