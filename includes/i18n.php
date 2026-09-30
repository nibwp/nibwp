<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Translation helpers.
 *
 * Skill manifests and the starter workflow catalogue are data, and they stay
 * English: agents read the same taglines and summaries the admin shows, and an
 * agent routing on a French tagline is an agent guessing. The admin screens
 * translate those strings at the moment they print them instead.
 *
 * make-pot only extracts literal gettext calls, so the data strings reach the
 * .pot through includes/i18n-data-strings.php, which `bash build/i18n.sh`
 * regenerates from the manifests and the starter catalogue.
 */

/**
 * The viewer's translation of a string that lives in data, not in a gettext call.
 */
function nibwp_i18n_data(string $text): string
{
    if ($text === '') {
        return '';
    }
    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- data strings, extracted via i18n-data-strings.php
    return translate($text, 'nibwp');
}

/**
 * Every plural form of a string, for a script that learns the count only after
 * the page has loaded.
 *
 * make-pot sees the _n_noop() the caller passes. The result is small enough to
 * inline: the distinct translated forms, and which form each count from 0 to
 * 199 takes. Counts from 200 up behave like 100 + n % 100 in every plural rule
 * gettext uses — the rules look at n % 10 and n % 100, never at the hundreds —
 * so the table covers every count. The JavaScript side is one line:
 *
 *   function plural(p, n) { return p.forms[p.index[n < 200 ? n : 100 + n % 100]].replace('%d', n); }
 *
 * @param array $nooped Result of _n_noop().
 * @return array{forms: string[], index: int[]}
 */
function nibwp_i18n_js_plural(array $nooped): array
{
    $forms = [];
    $index = [];
    for ($n = 0; $n < 200; $n++) {
        $text = translate_nooped_plural($nooped, $n, 'nibwp');
        $at = array_search($text, $forms, true);
        if ($at === false) {
            $forms[] = $text;
            $at = count($forms) - 1;
        }
        $index[] = $at;
    }
    return ['forms' => $forms, 'index' => $index];
}
