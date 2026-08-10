<?php

declare(strict_types=1);

/**
 * NIBWP Jobs — admin page.
 *
 * The product surface for non-technical users. Renders inside the NIBWP app
 * shell (nibwp_render_admin_header/footer) and drives every action over REST
 * (nibwp/v1/jobs/*, see jobs.php) with X-WP-Nonce — same convention as the
 * Workflows page. Four in-page tabs (no reload):
 *   Do        — the "tell NIBWP what to do" box (B) + the Jobs library cards (A)
 *   My Jobs   — configured jobs; run now / pause / schedule
 *   Approvals — the inbox: risky steps waiting for a yes/no
 *   Reports   — plain-English run reports
 * A sticky "How it works" aside explains the model in-context.
 */

if (!defined('ABSPATH')) {
    exit();
}

function nibwp_render_jobs_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    nibwp_render_admin_header();

    $catalog = nibwp_jobs_catalog();
    $jobs    = nibwp_jobs_list();
    $runs    = nibwp_jobs_runs(30);
    $inbox   = nibwp_jobs_approvals_inbox();
    ?>
    <div class="nw-jobs">
        <div class="nw-jobs__head">
            <div>
                <h1 class="nw-jobs__title"><?php esc_html_e('Jobs', 'nibwp'); ?> <span class="nw-jobs__beta" data-tip="<?php esc_attr_e('Jobs is still in beta — features and outputs may change.', 'nibwp'); ?>"><?php esc_html_e('BETA', 'nibwp'); ?></span></h1>
                <p class="nw-jobs__sub"><?php esc_html_e('Tell NIBWP what you want done. It plans the work with your skills and workflows, asks before anything risky, and reports back in plain English.', 'nibwp'); ?></p>
            </div>
            <button type="button" class="nw-jobs__help-btn" id="nw-jobs-help-btn" aria-expanded="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e('How it works', 'nibwp'); ?>
            </button>
        </div>

        <!-- Tabs (shared integration-tab styling) -->
        <div class="nw-int-tabs-wrap">
            <div class="nw-int-tabs" role="tablist">
                <?php
                $tabs = [
                    'do'        => [__('Do', 'nibwp'), null],
                    'jobs'      => [__('My Jobs', 'nibwp'), count($jobs)],
                    'activity'  => [__('Activity', 'nibwp'), null],
                    'approvals' => [__('Approvals', 'nibwp'), count($inbox)],
                    'reports'   => [__('Reports', 'nibwp'), count($runs)],
                ];
                foreach ($tabs as $slug => [$label, $count]) {
                    $badge = '';
                    if ($count !== null) {
                        $id = $slug === 'approvals' ? ' id="nw-jobs-inbox-badge"' : '';
                        $hidden = ($slug === 'approvals' && !$count) ? ' hidden' : '';
                        $badge = sprintf('<span class="nw-int-tab-count"%s%s>%d</span>', $id, $hidden, (int) $count);
                    }
                    printf(
                        '<button type="button" class="nw-int-tab%s" role="tab" data-tab="%s">%s%s</button>',
                        $slug === 'do' ? ' is-active' : '',
                        esc_attr($slug),
                        esc_html($label),
                        $badge
                    );
                }
                ?>
            </div>
        </div>

        <div class="nw-jobs-body">
            <div class="nw-jobs-main">

                <!-- ===== DO ===== -->
                <section class="nw-jobs-panel is-active" data-panel="do">
                    <div class="nw-jobs-nlbox">
                        <label for="nw-jobs-brief" class="nw-jobs-nlbox__label"><?php esc_html_e('Tell NIBWP what to do', 'nibwp'); ?></label>
                        <textarea id="nw-jobs-brief" rows="2" placeholder="<?php esc_attr_e('e.g. “Audit my SEO and fix the safe issues” · “Find and fix broken links”', 'nibwp'); ?>"></textarea>
                        <div class="nw-jobs-nlbox__foot">
                            <button type="button" class="nw-jobs-nlbox__start" id="nw-jobs-intent-btn"><?php esc_html_e('Start', 'nibwp'); ?></button>
                            <span class="nw-jobs-nlbox__hint"><?php esc_html_e('NIBWP plans it, asks before anything risky, and reports back.', 'nibwp'); ?></span>
                        </div>
                    </div>

                    <div class="nw-jobs-libhead">
                        <h2 class="nw-jobs-h2"><?php esc_html_e('Or pick a job', 'nibwp'); ?></h2>
                        <div class="nw-page-search" role="search">
                            <span class="nw-page-search__icon" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                            </span>
                            <label for="nw-jobs-search" class="screen-reader-text"><?php esc_html_e('Search jobs', 'nibwp'); ?></label>
                            <input type="search" id="nw-jobs-search" class="nw-page-search__input" placeholder="<?php esc_attr_e('Search jobs & press Enter', 'nibwp'); ?>" autocomplete="off" spellcheck="false">
                            <button type="button" class="nw-page-search__clear" id="nw-jobs-search-clear" aria-label="<?php esc_attr_e('Clear search', 'nibwp'); ?>" hidden>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            </button>
                            <span class="nw-page-search__count" id="nw-jobs-search-count" hidden></span>
                        </div>
                    </div>
                    <div class="nw-jobs-cards">
                        <?php
                        $added_catalogs   = array_flip(array_filter(array_column($jobs, 'catalog')));
                        $running_catalogs = array_flip(array_filter(array_column(nibwp_jobs_running(), 'catalog')));
                        // Working jobs first, Planned (no executor) sink to the bottom.
                        $real_cards = $planned_cards = [];
                        foreach ($catalog as $ck => $cc) {
                            if (function_exists('nibwp_jobs_has_executor') && nibwp_jobs_has_executor($ck)) {
                                $real_cards[$ck] = $cc;
                            } else {
                                $planned_cards[$ck] = $cc;
                            }
                        }
                        $catalog = $real_cards + $planned_cards;
                        foreach ($catalog as $key => $card) :
                            $hay = strtolower((string) $card['title'] . ' ' . (string) $card['outcome'] . ' ' . implode(' ', (array) ($card['features'] ?? [])));
                            $is_added   = isset($added_catalogs[$key]);
                            $is_running = isset($running_catalogs[$key]); ?>
                            <?php $has_exec = function_exists('nibwp_jobs_has_executor') && nibwp_jobs_has_executor($key); ?>
                            <article class="nw-skill-card nw-jobcard<?php echo $is_added ? ' is-added' : ''; ?><?php echo $is_running ? ' is-running' : ''; ?><?php echo $has_exec ? '' : ' is-planned'; ?>" data-catalog="<?php echo esc_attr($key); ?>" data-search="<?php echo esc_attr($hay); ?>">
                                <?php if (!$has_exec) : ?>
                                    <span class="nw-jobcard__gate nw-jobcard__gate--planned" data-tip="<?php esc_attr_e('Planned — not available yet. This one needs the execution engine before it can do real work.', 'nibwp'); ?>"><?php esc_html_e('Planned', 'nibwp'); ?></span>
                                <?php elseif (!empty($card['gated'])) : ?>
                                    <span class="nw-jobcard__gate" data-tip="<?php esc_attr_e('Runs for real. Any change to your site waits for your approval.', 'nibwp'); ?>"><?php esc_html_e('Ask first', 'nibwp'); ?></span>
                                <?php else : ?>
                                    <span class="nw-jobcard__gate nw-jobcard__gate--ok" data-tip="<?php esc_attr_e('Runs for real — only reads your site, changes nothing.', 'nibwp'); ?>"><?php esc_html_e('Read-only', 'nibwp'); ?></span>
                                <?php endif; ?>
                                <div class="nw-skill-card__head">
                                    <div class="nw-skill-card__icon"><?php echo nibwp_jobs_icon((string) ($card['icon'] ?? '')); ?></div>
                                    <div class="nw-skill-card__title">
                                        <strong><?php echo esc_html((string) $card['title']); ?></strong>
                                        <span><?php echo esc_html((string) $card['outcome']); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($card['features'])) : ?>
                                    <ul class="nw-skill-card__features">
                                        <?php foreach ((array) $card['features'] as $feat) : ?>
                                            <li><?php echo esc_html((string) $feat); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <div class="nw-skill-card__foot nw-jobcard__foot">
                                    <select class="nw-jobcard__sched" aria-label="<?php esc_attr_e('Schedule', 'nibwp'); ?>"<?php disabled(!$has_exec); ?>>
                                        <?php
                                        $def = (string) ($card['schedule'] ?? 'manual');
                                        foreach (['manual' => __('When I run it', 'nibwp'), 'daily' => __('Every day', 'nibwp'), 'weekly' => __('Every week', 'nibwp')] as $v => $l) {
                                            printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($v, $def, false), esc_html($l));
                                        }
                                        ?>
                                    </select>
                                    <div class="nw-jobcard__actions">
                                        <?php if ($has_exec) : ?>
                                            <button type="button" class="nw-pillbtn nw-jobcard__add<?php echo $is_added ? ' is-added' : ''; ?>" data-tip="<?php esc_attr_e('Save to My Jobs — run it later or put it on a schedule', 'nibwp'); ?>">
                                                <?php echo nibwp_jobs_add_label($is_added); ?>
                                            </button>
                                            <button type="button" class="nw-pillbtn nw-pillbtn--go nw-jobcard__run" data-tip="<?php esc_attr_e('Start this job once, right now', 'nibwp'); ?>">
                                                <svg class="nw-run-play" width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 4 20 12 6 20 6 4"/></svg>
                                                <span class="nw-spin nw-spin--sm nw-run-spin" aria-hidden="true"></span>
                                                <?php esc_html_e('Run now', 'nibwp'); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="nw-pillbtn" disabled data-tip="<?php esc_attr_e('Coming soon — needs the execution engine', 'nibwp'); ?>"><?php esc_html_e('Add', 'nibwp'); ?></button>
                                            <button type="button" class="nw-pillbtn nw-pillbtn--go" disabled data-tip="<?php esc_attr_e('Coming soon — needs the execution engine', 'nibwp'); ?>"><?php esc_html_e('Run now', 'nibwp'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- ===== MY JOBS ===== -->
                <section class="nw-jobs-panel" data-panel="jobs" hidden>
                    <div id="nw-jobs-list">
                        <?php if (!$jobs) : ?>
                            <?php nibwp_jobs_empty(__('No jobs yet.', 'nibwp'), __('Add one from the Do tab — pick an outcome or just tell NIBWP what you want.', 'nibwp')); ?>
                        <?php else : ?>
                            <?php foreach ($jobs as $j) {
                                nibwp_jobs_render_job_row($j);
                            } ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== ACTIVITY (live Gantt timeline) ===== -->
                <section class="nw-jobs-panel" data-panel="activity" hidden>
                    <div class="nw-tl-head">
                        <span class="nw-tl-live"><span class="nw-tl-live__dot"></span><?php esc_html_e('Live', 'nibwp'); ?></span>
                        <span class="nw-tl-head__hint"><?php esc_html_e('Every action across your jobs on a live timeline. Bars grow while a step runs — click any lane to open its run.', 'nibwp'); ?></span>
                    </div>
                    <div id="nw-jobs-gantt"></div>
                </section>

                <!-- ===== APPROVALS ===== -->
                <section class="nw-jobs-panel" data-panel="approvals" hidden>
                    <div id="nw-jobs-inbox">
                        <?php if (!$inbox) : ?>
                            <?php nibwp_jobs_empty(__('Nothing waiting.', 'nibwp'), __('When a job hits a step that changes your site, it pauses here for a quick yes/no.', 'nibwp')); ?>
                        <?php else : ?>
                            <?php foreach ($inbox as $a) {
                                nibwp_jobs_render_approval($a);
                            } ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== REPORTS ===== -->
                <section class="nw-jobs-panel" data-panel="reports" hidden>
                    <div id="nw-jobs-reports">
                        <?php if (!$runs) : ?>
                            <?php nibwp_jobs_empty(__('No runs yet.', 'nibwp'), __('Run a job and its report shows up here.', 'nibwp')); ?>
                        <?php else : ?>
                            <?php foreach ($runs as $run) {
                                nibwp_jobs_render_report($run);
                            } ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>

            <!-- Sticky how-it-works aside -->
            <aside class="nw-jobs-aside" id="nw-jobs-aside">
                <?php nibwp_jobs_render_howitworks(); ?>
            </aside>
        </div>
    </div>

    <!-- Detail modal (report / approval / intent result) -->
    <div class="nw-modal" id="nw-jobs-modal" hidden>
        <div class="nw-modal__backdrop" data-close></div>
        <div class="nw-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nw-jobs-modal-title">
            <div class="nw-modal__head">
                <h2 id="nw-jobs-modal-title"><?php esc_html_e('Details', 'nibwp'); ?></h2>
                <button type="button" class="nw-modal__close" data-close aria-label="<?php esc_attr_e('Close', 'nibwp'); ?>">&times;</button>
            </div>
            <div class="nw-modal__body" id="nw-jobs-modal-body"></div>
        </div>
    </div>

    <?php nibwp_jobs_inline_js(); ?>
    <?php
    nibwp_render_admin_footer();
}

// ---------------------------------------------------------------------------
// Row / card partials (also used to build AJAX-inserted markup client-side, so
// keep the class names stable).
// ---------------------------------------------------------------------------

function nibwp_jobs_render_job_row(array $j): void
{
    $paused = $j['status'] === 'paused';
    $last   = $j['last_run'];
    ?>
    <div class="nw-job-row<?php echo $paused ? ' is-paused' : ''; ?>" data-job="<?php echo (int) $j['id']; ?>" data-name="<?php echo esc_attr($j['name']); ?>">
        <div class="nw-job-row__main">
            <div class="nw-job-row__name"><?php echo esc_html($j['name']); ?></div>
            <?php if ($j['brief']) : ?>
                <div class="nw-job-row__brief"><?php echo esc_html(wp_trim_words($j['brief'], 18, '…')); ?></div>
            <?php endif; ?>
        </div>
        <div class="nw-job-row__sched">
            <select class="nw-job-row__sched-sel" aria-label="<?php esc_attr_e('Schedule', 'nibwp'); ?>">
                <?php foreach (['manual' => __('Manual', 'nibwp'), 'daily' => __('Daily', 'nibwp'), 'weekly' => __('Weekly', 'nibwp')] as $v => $l) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($v, $j['schedule'], false), esc_html($l));
                } ?>
            </select>
        </div>
        <div class="nw-job-row__last">
            <?php if ($last) {
                printf(
                    '<span class="nw-pill %s">%s</span> <span class="nw-job-row__when">%s</span>',
                    esc_attr(nibwp_jobs_status_class($last['status'])),
                    esc_html(nibwp_jobs_status_label($last['status'])),
                    esc_html(human_time_diff(strtotime($last['when'])) . ' ' . __('ago', 'nibwp'))
                );
            } else {
                echo '<span class="nw-job-row__when">—</span>';
            } ?>
        </div>
        <div class="nw-job-row__actions">
            <button type="button" class="nw-pillbtn nw-pillbtn--go nw-job-run"><?php esc_html_e('Run now', 'nibwp'); ?></button>
            <button type="button" class="nibwp-toggle-btn nw-job-toggle<?php echo $paused ? '' : ' is-on'; ?>" title="<?php echo $paused ? esc_attr__('Paused — click to resume', 'nibwp') : esc_attr__('Active — click to pause', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Pause or resume this job', 'nibwp'); ?>">
                <span class="nibwp-toggle-track"><span class="nibwp-toggle-thumb"></span></span>
            </button>
            <button type="button" class="nw-iconbtn nw-iconbtn--danger nw-job-del" title="<?php esc_attr_e('Delete this job', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Delete this job', 'nibwp'); ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m2 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>
            </button>
        </div>
    </div>
    <?php
}

function nibwp_jobs_render_approval(array $a): void
{
    ?>
    <div class="nw-approval" data-run="<?php echo (int) $a['run_id']; ?>" data-approval="<?php echo esc_attr((string) $a['id']); ?>">
        <div class="nw-approval__body">
            <span class="nw-approval__job"><?php echo esc_html((string) $a['job_name']); ?></span>
            <h3 class="nw-approval__title"><?php echo esc_html((string) ($a['title'] ?? __('Approve this change?', 'nibwp'))); ?></h3>
            <?php if (!empty($a['detail'])) : ?>
                <p class="nw-approval__detail"><?php echo esc_html((string) $a['detail']); ?></p>
            <?php endif; ?>
            <?php if (!empty($a['preview'])) : ?>
                <pre class="nw-approval__preview"><?php echo esc_html((string) $a['preview']); ?></pre>
            <?php endif; ?>
        </div>
        <div class="nw-approval__btns">
            <button type="button" class="nw-pillbtn nw-pillbtn--ok nw-approve" data-decision="approve"><?php esc_html_e('Approve', 'nibwp'); ?></button>
            <button type="button" class="nw-pillbtn nw-approve" data-decision="deny"><?php esc_html_e('Deny', 'nibwp'); ?></button>
        </div>
    </div>
    <?php
}

function nibwp_jobs_render_report(array $run): void
{
    $report  = (array) $run['report'];
    $working = in_array($run['status'], ['queued', 'running'], true);
    ?>
    <div class="nw-report nw-report--<?php echo esc_attr($run['status']); ?>" data-run="<?php echo (int) $run['id']; ?>">
        <div class="nw-report__head">
            <span class="nw-report__ico" aria-hidden="true"><?php echo nibwp_jobs_report_icon($run['status']); ?></span>
            <div class="nw-report__headmain">
                <span class="nw-report__job"><?php echo esc_html((string) $run['job_name']); ?></span>
                <?php if ($run['started']) : ?>
                    <span class="nw-report__when"><?php echo esc_html(wp_date('M j, Y · g:i a', (int) $run['started'])); ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($run['real'])) : ?>
                <span class="nw-pill nw-pill--muted" data-tip="<?php esc_attr_e('Sample output — this job type does not do real work yet.', 'nibwp'); ?>"><?php esc_html_e('Preview', 'nibwp'); ?></span>
            <?php endif; ?>
            <span class="nw-pill <?php echo esc_attr(nibwp_jobs_status_class($run['status'])); ?>"><?php echo esc_html(nibwp_jobs_status_label($run['status'])); ?></span>
        </div>
        <?php if ($working) : ?>
            <p class="nw-report__working"><span class="nw-spin" aria-hidden="true"></span><?php esc_html_e('Working… the NIBWP engine is running this job.', 'nibwp'); ?></p>
        <?php endif; ?>
        <?php if (!empty($report['summary'])) : ?>
            <p class="nw-report__summary"><?php echo esc_html((string) $report['summary']); ?></p>
        <?php endif; ?>
        <?php if (!empty($report['items'])) : ?>
            <div class="nw-report__sec">
                <div class="nw-report__sec-label nw-report__sec-label--done">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php printf(esc_html__('What NIBWP did (%d)', 'nibwp'), count((array) $report['items'])); ?>
                </div>
                <ul class="nw-report__list nw-report__list--done">
                    <?php foreach ((array) $report['items'] as $item) : ?>
                        <li><?php echo esc_html(is_array($item) ? (string) ($item['text'] ?? '') : (string) $item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($report['flags'])) : ?>
            <div class="nw-report__flags">
                <div class="nw-report__sec-label nw-report__sec-label--flag">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                    <?php printf(esc_html__('Needs your attention (%d)', 'nibwp'), count((array) $report['flags'])); ?>
                </div>
                <ul class="nw-report__list nw-report__list--flag">
                    <?php foreach ((array) $report['flags'] as $flag) : ?>
                        <li><?php echo esc_html(is_array($flag) ? (string) ($flag['text'] ?? '') : (string) $flag); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="nw-report__foot">
            <span class="nw-report__view">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <?php esc_html_e('View timeline', 'nibwp'); ?>
            </span>
            <span class="nw-report__ctl">
                <?php if ($working || $run['status'] === 'awaiting_approval') : ?>
                    <button type="button" class="nw-pillbtn nw-pillbtn--danger nw-run-stop" data-tip="<?php esc_attr_e('Stop this run now', 'nibwp'); ?>">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
                        <?php esc_html_e('Stop', 'nibwp'); ?>
                    </button>
                <?php endif; ?>
                <button type="button" class="nw-iconbtn nw-iconbtn--danger nw-run-del" title="<?php esc_attr_e('Delete this run', 'nibwp'); ?>" aria-label="<?php esc_attr_e('Delete this run', 'nibwp'); ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m2 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>
                </button>
            </span>
        </div>
    </div>
    <?php
}

/** One timeline row. Also the exact shape the JS rebuilds for live-appended events. */
function nibwp_jobs_render_tl_item(array $ev): void
{
    $status = (string) ($ev['status'] ?? 'info');
    $ts     = (int) ($ev['ts'] ?? 0);
    ?>
    <li class="nw-tl-item nw-tl--<?php echo esc_attr($status); ?>" data-run="<?php echo (int) ($ev['run_id'] ?? 0); ?>" role="button" tabindex="0" title="<?php esc_attr_e('Open this run', 'nibwp'); ?>">
        <span class="nw-tl-item__dot" aria-hidden="true"></span>
        <div class="nw-tl-item__body">
            <div class="nw-tl-item__top">
                <span class="nw-tl-item__action"><?php echo esc_html((string) ($ev['action'] ?? '')); ?></span>
                <span class="nw-tl-item__when" data-ts="<?php echo esc_attr((string) $ts); ?>"><?php echo $ts ? esc_html(human_time_diff($ts) . ' ' . __('ago', 'nibwp')) : ''; ?></span>
            </div>
            <div class="nw-tl-item__meta">
                <span class="nw-tl-item__job"><?php echo esc_html((string) ($ev['job_name'] ?? '')); ?></span>
                <?php if (!empty($ev['actor']) && $ev['actor'] !== 'system') : ?>
                    <span class="nw-tl-item__actor nw-actor--<?php echo esc_attr((string) $ev['actor']); ?>"><?php echo esc_html((string) $ev['actor']); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($ev['detail'])) : ?>
                <div class="nw-tl-item__detail"><?php echo esc_html((string) $ev['detail']); ?></div>
            <?php endif; ?>
        </div>
    </li>
    <?php
}

function nibwp_jobs_render_howitworks(): void
{
    $steps = [
        ['1', __('Say the outcome', 'nibwp'), __('Pick a job card or type what you want. No settings, no jargon.', 'nibwp')],
        ['2', __('NIBWP plans it', 'nibwp'), __('It picks the right skills and workflows and works step by step — the AI part stays out of your way.', 'nibwp')],
        ['3', __('You approve risky steps', 'nibwp'), __('Anything that changes your site pauses in the Approvals inbox for a yes/no. Read-only checks just run.', 'nibwp')],
        ['4', __('Read the report', 'nibwp'), __('Plain-English summary of what it did and what still needs you. Share it with clients.', 'nibwp')],
    ];
    ?>
    <div class="nw-hiw">
        <h3 class="nw-hiw__title"><?php esc_html_e('How Jobs work', 'nibwp'); ?></h3>
        <ol class="nw-hiw__steps">
            <?php foreach ($steps as [$n, $t, $d]) : ?>
                <li class="nw-hiw__step">
                    <span class="nw-hiw__num"><?php echo esc_html($n); ?></span>
                    <div>
                        <div class="nw-hiw__step-title"><?php echo esc_html($t); ?></div>
                        <div class="nw-hiw__step-desc"><?php echo esc_html($d); ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="nw-hiw__note">
            <strong><?php esc_html_e('Run now vs Add', 'nibwp'); ?></strong>
            <p><?php esc_html_e('Run now starts the job once. Add keeps it in My Jobs so you can run it again or put it on a daily/weekly schedule.', 'nibwp'); ?></p>
        </div>
        <a class="nw-hiw__docs" href="https://www.nibwp.com/docs/jobs" target="_blank" rel="noopener">
            <?php esc_html_e('Read the Jobs guide', 'nibwp'); ?>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function nibwp_jobs_empty(string $title, string $sub): void
{
    printf(
        '<div class="nw-jobs-empty"><div class="nw-jobs-empty__ic" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v5"/></svg></div><h3>%s</h3><p>%s</p></div>',
        esc_html($title),
        esc_html($sub)
    );
}

function nibwp_jobs_status_label(string $s): string
{
    return [
        'queued' => __('Queued', 'nibwp'), 'running' => __('Running', 'nibwp'),
        'awaiting_approval' => __('Waiting on you', 'nibwp'), 'done' => __('Done', 'nibwp'),
        'failed' => __('Failed', 'nibwp'),
    ][$s] ?? ucfirst($s);
}

function nibwp_jobs_status_class(string $s): string
{
    return match ($s) {
        'done' => 'nw-pill--ok',
        'failed' => 'nw-pill--danger',
        'awaiting_approval' => 'nw-pill--warn',
        default => 'nw-pill--muted',
    };
}

/** Add-button label — plain "Add" or the "Added" confirmed state. */
function nibwp_jobs_add_label(bool $added): string
{
    if ($added) {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> ' . esc_html__('Added', 'nibwp');
    }
    return esc_html__('Add', 'nibwp');
}

/** Small status glyph for a report card header. */
function nibwp_jobs_report_icon(string $status): string
{
    $p = match ($status) {
        'done'     => '<circle cx="12" cy="12" r="9"/><polyline points="8.5 12 11 14.5 15.5 9.5"/>',
        'failed', 'stopped' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'awaiting_approval' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
        default    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', // queued/running = clock
    };
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** Minimal inline SVG per catalog icon name (no icon-font dependency). */
function nibwp_jobs_icon(string $name): string
{
    $p = [
        'layout-template' => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="8" height="7" rx="1"/><rect x="15" y="14" width="6" height="7" rx="1"/>',
        'search-check'    => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/><path d="M8.5 11l2 2 3-3.5"/>',
        'unlink'          => '<path d="M8 12h8"/><path d="M10 6a4 4 0 015.66 0l1.34 1.34a4 4 0 010 5.66"/><path d="M14 18a4 4 0 01-5.66 0L7 16.66a4 4 0 010-5.66"/>',
        'refresh-cw'      => '<path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'heart-pulse'     => '<path d="M12 20s-7-4.5-9-9a5 5 0 019-3 5 5 0 019 3c-2 4.5-9 9-9 9z"/><path d="M4 12h3l1.5-3 2 5 1.5-2h3"/>',
        'gauge'           => '<path d="M12 14l4-4"/><path d="M3.3 15a9 9 0 1117.4 0"/><circle cx="12" cy="14" r="1.2" fill="currentColor"/>',
        'shield'          => '<path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
        'chat'            => '<path d="M21 12a8 8 0 01-11.5 7.2L4 20l1-4.2A8 8 0 1121 12z"/>',
        'download'        => '<path d="M12 3v11"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/>',
        'archive'         => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8"/><path d="M10 12h4"/>',
        'database'        => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'accessibility'   => '<circle cx="12" cy="4.5" r="1.6"/><path d="M4 8h16"/><path d="M12 8v6"/><path d="M8.5 20l3.5-6 3.5 6"/>',
        'chart'           => '<path d="M4 20V4"/><path d="M4 20h16"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/>',
    ];
    $d = $p[$name] ?? '<path d="M12 3l2 5 5 2-5 2-2 5-2-5-5-2 5-2z"/>';
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

// ---------------------------------------------------------------------------
// Inline JS — REST-driven, no reload. Kept small; delegated listeners.
// ---------------------------------------------------------------------------

function nibwp_jobs_inline_js(): void
{
    $rest  = esc_js(rest_url('nibwp/v1/jobs/'));
    $nonce = esc_js(wp_create_nonce('wp_rest'));
    ?>
    <script>
    (function () {
        var REST = '<?php echo $rest; ?>', NONCE = '<?php echo $nonce; ?>';
        var qs = function (s, r) { return (r || document).querySelector(s); };
        var qsa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

        function post(path, body) {
            return fetch(REST + path, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body: JSON.stringify(body || {})
            }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); });
        }
        function toast(msg, err) {
            var t = document.createElement('div');
            t.className = 'nw-jobs-toast' + (err ? ' is-err' : '');
            t.textContent = msg;
            document.body.appendChild(t);
            requestAnimationFrame(function () { t.classList.add('is-in'); });
            setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 250); }, 2600);
        }
        function busy(btn, on) {
            if (!btn) return;
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }
        /* Styled confirm — reuses the global #nw-confirm modal instead of window.confirm. */
        function jobConfirm(html, okLabel, onOk) {
            var modal = document.getElementById('nw-confirm');
            if (!modal) { if (window.confirm(String(html).replace(/<[^>]+>/g, ''))) onOk(); return; }
            var msg = document.getElementById('nw-confirm-msg'),
                ok = document.getElementById('nw-confirm-ok'),
                cancel = document.getElementById('nw-confirm-cancel'),
                bd = modal.querySelector('.nw-confirm__backdrop');
            msg.innerHTML = html; ok.href = '#'; if (okLabel) ok.textContent = okLabel;
            modal.classList.add('is-open');
            function cleanup() { modal.classList.remove('is-open'); ok.removeEventListener('click', okH); cancel.removeEventListener('click', cancelH); bd.removeEventListener('click', cancelH); }
            function okH(e) { e.preventDefault(); cleanup(); onOk(); }
            function cancelH() { cleanup(); }
            ok.addEventListener('click', okH); cancel.addEventListener('click', cancelH); bd.addEventListener('click', cancelH);
        }

        /* ---- Tabs ---- */
        qsa('.nw-int-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-tab');
                qsa('.nw-int-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                qsa('.nw-jobs-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
                if (name === 'activity') { startActivity(); } else { stopActivity(); }
            });
        });
        function goTab(name) { var t = qs('.nw-int-tab[data-tab="' + name + '"]'); if (t) t.click(); }

        /* ---- Job library search (same behaviour as the Skills page) ---- */
        (function () {
            var search = qs('#nw-jobs-search'), clear = qs('#nw-jobs-search-clear'), count = qs('#nw-jobs-search-count');
            if (!search) return;
            var cards = qsa('.nw-jobcard');
            function apply() {
                var q = (search.value || '').trim().toLowerCase(), vis = 0;
                cards.forEach(function (c) {
                    var hit = !q || (c.getAttribute('data-search') || '').indexOf(q) > -1;
                    c.style.display = hit ? '' : 'none';
                    if (hit) vis++;
                });
                if (clear) clear.hidden = q === '';
                if (count) { if (q === '') { count.hidden = true; } else { count.hidden = false; count.textContent = vis + ' / ' + cards.length; } }
            }
            var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 60); });
            search.addEventListener('keydown', function (e) { if (e.key === 'Escape') { search.value = ''; apply(); } });
            if (clear) clear.addEventListener('click', function () { search.value = ''; apply(); search.focus(); });
        })();

        /* ---- How-it-works aside toggle (mobile / on demand) ---- */
        var helpBtn = qs('#nw-jobs-help-btn'), aside = qs('#nw-jobs-aside');
        if (helpBtn && aside) {
            helpBtn.addEventListener('click', function () {
                var open = aside.classList.toggle('is-open');
                helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        /* ---- Modal ---- */
        var modal = qs('#nw-jobs-modal');
        function openModal(title, html) {
            qs('#nw-jobs-modal-title').textContent = title;
            qs('#nw-jobs-modal-body').innerHTML = html;
            modal.hidden = false;
        }
        if (modal) {
            modal.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close')) modal.hidden = true; });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.hidden = true; });
        }

        /* ---- NL intent (B) ---- */
        var intentBtn = qs('#nw-jobs-intent-btn');
        if (intentBtn) intentBtn.addEventListener('click', function () {
            var ta = qs('#nw-jobs-brief'), brief = (ta.value || '').trim();
            if (!brief) { ta.focus(); return; }
            busy(intentBtn, true);
            post('intent', { brief: brief }).then(function (res) {
                if (!res.ok || !res.d.ok) { busy(intentBtn, false); toast(res.d.message || 'Could not start.', true); return; }
                // keep the spinner running through the reload — reads as "working on it"
                ta.value = '';
                toast('<?php echo esc_js(__('Job started — see Reports.', 'nibwp')); ?>');
                goTab('reports');
                setTimeout(function () { location.reload(); }, 800);
            }).catch(function () { busy(intentBtn, false); toast('Network error.', true); });
        });

        /* ---- Catalog cards: Run now / Add (A) — fully AJAX, no reload ---- */
        var ADDED_HTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php echo esc_js(__('Added', 'nibwp')); ?>';
        function bumpTab(name, by) {
            var c = qs('.nw-int-tab[data-tab="' + name + '"] .nw-int-tab-count');
            if (c) { c.textContent = (parseInt(c.textContent, 10) || 0) + (by || 1); c.hidden = false; }
        }
        function markAdded(card) {
            card.classList.add('is-added');
            var add = qs('.nw-jobcard__add', card);
            if (add && !add.classList.contains('is-added')) { add.classList.add('is-added'); add.innerHTML = ADDED_HTML; bumpTab('jobs', 1); }
        }
        qsa('.nw-jobcard').forEach(function (card) {
            var cat = card.getAttribute('data-catalog');
            var sched = qs('.nw-jobcard__sched', card);
            var runBtn = qs('.nw-jobcard__run', card), addBtn = qs('.nw-jobcard__add', card);
            if (!runBtn || !addBtn) return; // Planned card — buttons are disabled, no handlers
            runBtn.addEventListener('click', function (e) {
                var btn = e.currentTarget; busy(btn, true);
                post('create', { catalog: cat, schedule: sched.value, run_now: 1 }).then(function (res) {
                    busy(btn, false);
                    if (!res.ok || !res.d.ok) { toast(res.d.message || 'Failed.', true); return; }
                    markAdded(card);
                    card.classList.add('is-running');
                    bumpTab('reports', 1);
                    toast('<?php echo esc_js(__('Job started — see Reports.', 'nibwp')); ?>');
                }).catch(function () { busy(btn, false); toast('Network error.', true); });
            });
            addBtn.addEventListener('click', function (e) {
                var btn = e.currentTarget;
                if (card.classList.contains('is-added')) { goTab('jobs'); return; } // already added → jump to My Jobs
                busy(btn, true);
                post('create', { catalog: cat, schedule: sched.value }).then(function (res) {
                    busy(btn, false);
                    if (!res.ok || !res.d.ok) { toast(res.d.message || 'Failed.', true); return; }
                    markAdded(card);
                    toast('<?php echo esc_js(__('Added to My Jobs.', 'nibwp')); ?>');
                }).catch(function () { busy(btn, false); toast('Network error.', true); });
            });
        });

        /* ---- My Jobs: run / toggle / schedule (delegated) ---- */
        var list = qs('#nw-jobs-list');
        if (list) list.addEventListener('click', function (e) {
            var row = e.target.closest('.nw-job-row'); if (!row) return;
            var id = parseInt(row.getAttribute('data-job'), 10);
            if (e.target.closest('.nw-job-run')) {
                var b = e.target.closest('.nw-job-run'); busy(b, true);
                post('run', { job_id: id }).then(function (res) {
                    busy(b, false);
                    if (!res.ok || !res.d.ok) { toast(res.d.message || 'Failed.', true); return; }
                    toast('<?php echo esc_js(__('Started — see Reports.', 'nibwp')); ?>');
                }).catch(function () { busy(b, false); toast('Network error.', true); });
            } else if (e.target.closest('.nw-job-toggle')) {
                var t = e.target.closest('.nw-job-toggle');
                post('toggle', { job_id: id }).then(function (res) {
                    if (!res.ok || !res.d.ok) { toast('Failed.', true); return; }
                    var on = res.d.status !== 'paused';
                    t.classList.toggle('is-on', on);
                    row.classList.toggle('is-paused', !on);
                    t.title = on ? '<?php echo esc_js(__('Active — click to pause', 'nibwp')); ?>' : '<?php echo esc_js(__('Paused — click to resume', 'nibwp')); ?>';
                }).catch(function () { toast('Network error.', true); });
            } else if (e.target.closest('.nw-job-del')) {
                var db = e.target.closest('.nw-job-del');
                var nm = (row.getAttribute('data-name') || '').replace(/</g, '&lt;');
                jobConfirm('<?php echo esc_js(__('This will permanently delete', 'nibwp')); ?> ' + (nm ? '<code>' + nm + '</code>' : '<?php echo esc_js(__('this job', 'nibwp')); ?>') + ' <?php echo esc_js(__('and all its runs. This cannot be undone.', 'nibwp')); ?>', '<?php echo esc_js(__('Delete job', 'nibwp')); ?>', function () {
                    busy(db, true);
                    post('delete', { job_id: id }).then(function (res) {
                        if (!res.ok || !res.d.ok) { busy(db, false); toast('Failed.', true); return; }
                        row.style.transition = 'opacity .2s, transform .2s'; row.style.opacity = '0'; row.style.transform = 'scale(.97)';
                        setTimeout(function () { row.remove(); if (!qs('.nw-job-row', list)) location.reload(); }, 200);
                        toast('<?php echo esc_js(__('Job deleted.', 'nibwp')); ?>');
                    }).catch(function () { busy(db, false); toast('Network error.', true); });
                });
            }
        });
        if (list) list.addEventListener('change', function (e) {
            var sel = e.target.closest('.nw-job-row__sched-sel'); if (!sel) return;
            var row = e.target.closest('.nw-job-row'), id = parseInt(row.getAttribute('data-job'), 10);
            post('schedule', { job_id: id, schedule: sel.value }).then(function (res) {
                if (res.ok && res.d.ok) toast('<?php echo esc_js(__('Schedule saved.', 'nibwp')); ?>'); else toast('Failed.', true);
            }).catch(function () { toast('Network error.', true); });
        });

        /* ---- Approvals: approve / deny (delegated) ---- */
        var inbox = qs('#nw-jobs-inbox');
        if (inbox) inbox.addEventListener('click', function (e) {
            var b = e.target.closest('.nw-approve'); if (!b) return;
            var card = e.target.closest('.nw-approval');
            var runId = parseInt(card.getAttribute('data-run'), 10);
            var apId = card.getAttribute('data-approval');
            var decision = b.getAttribute('data-decision');
            busy(b, true);
            post('approve', { run_id: runId, approval_id: apId, decision: decision }).then(function (res) {
                busy(b, false);
                if (!res.ok || !res.d.ok) { toast(res.d.message || 'Failed.', true); return; }
                card.style.transition = 'opacity .2s, transform .2s';
                card.style.opacity = '0'; card.style.transform = 'scale(.97)';
                setTimeout(function () {
                    card.remove();
                    var badge = qs('#nw-jobs-inbox-badge');
                    if (badge) { var n = res.d.inbox || 0; badge.textContent = n; badge.hidden = n === 0; }
                    if (!qs('.nw-approval', inbox)) location.reload();
                }, 200);
                toast(decision === 'approve' ? '<?php echo esc_js(__('Approved.', 'nibwp')); ?>' : '<?php echo esc_js(__('Denied.', 'nibwp')); ?>');
            }).catch(function () { busy(b, false); toast('Network error.', true); });
        });

        /* ---- GET helper + escaping ---- */
        function get(path) {
            return fetch(REST + path, { credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE } })
                .then(function (r) { return r.json(); });
        }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function ago(ts) {
            var s = Math.max(1, Math.floor(Date.now() / 1000) - ts);
            if (s < 60) return s + 's <?php echo esc_js(__('ago', 'nibwp')); ?>';
            if (s < 3600) return Math.floor(s / 60) + 'm <?php echo esc_js(__('ago', 'nibwp')); ?>';
            if (s < 86400) return Math.floor(s / 3600) + 'h <?php echo esc_js(__('ago', 'nibwp')); ?>';
            return Math.floor(s / 86400) + 'd <?php echo esc_js(__('ago', 'nibwp')); ?>';
        }
        function tlItem(ev) {
            var actor = ev.actor && ev.actor !== 'system'
                ? '<span class="nw-tl-item__actor nw-actor--' + esc(ev.actor) + '">' + esc(ev.actor) + '</span>' : '';
            var detail = ev.detail ? '<div class="nw-tl-item__detail">' + esc(ev.detail) + '</div>' : '';
            return '<li class="nw-tl-item nw-tl--' + esc(ev.status || 'info') + ' is-new" data-run="' + (ev.run_id || 0) + '" role="button" tabindex="0">' +
                '<span class="nw-tl-item__dot"></span><div class="nw-tl-item__body">' +
                '<div class="nw-tl-item__top"><span class="nw-tl-item__action">' + esc(ev.action) + '</span>' +
                '<span class="nw-tl-item__when" data-ts="' + ev.ts + '">' + ago(ev.ts) + '</span></div>' +
                '<div class="nw-tl-item__meta"><span class="nw-tl-item__job">' + esc(ev.job_name) + '</span>' + actor + '</div>' +
                detail + '</div></li>';
        }

        /* ---- Live Gantt timeline ---- */
        var gMount = qs('#nw-jobs-gantt'), gInterval = null, gTick = 0;
        var gEvents = [], gSeen = {}, gLastTs = 0, gNowOffset = 0, gFirstIds = {}, gLoaded = false;
        var GANTT_LOADING = '<div class="nw-gantt nw-gantt--loading"><span class="nw-spin"></span><span><?php echo esc_js(__('Loading activity…', 'nibwp')); ?></span></div>';
        function evKey(ev) { return ev.run_id + '|' + ev.ts + '|' + ev.action; }
        function nowSec() { return Math.floor(Date.now() / 1000) + gNowOffset; }
        function niceStep(span) {
            var steps = [1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 86400];
            var target = span / 6;
            for (var i = 0; i < steps.length; i++) { if (steps[i] >= target) return steps[i]; }
            return steps[steps.length - 1];
        }
        function fmtT(s) { if (s < 60) return s + 's'; if (s < 3600) return Math.round(s / 60) + 'm'; if (s < 86400) return Math.round(s / 3600) + 'h'; return Math.round(s / 86400) + 'd'; }
        var STATUS = { running: '<?php echo esc_js(__('Running', 'nibwp')); ?>', queued: '<?php echo esc_js(__('Queued', 'nibwp')); ?>', done: '<?php echo esc_js(__('Done', 'nibwp')); ?>', approved: '<?php echo esc_js(__('Approved', 'nibwp')); ?>', denied: '<?php echo esc_js(__('Denied', 'nibwp')); ?>', failed: '<?php echo esc_js(__('Failed', 'nibwp')); ?>', info: '<?php echo esc_js(__('Step', 'nibwp')); ?>' };

        function renderGantt() {
            if (!gMount) return;
            if (!gEvents.length) {
                gMount.innerHTML = gLoaded
                    ? '<div class="nw-gantt"><div class="nw-jobs-empty"><div class="nw-jobs-empty__ic"></div><h3>' +
                        esc('<?php echo esc_js(__('No activity yet.', 'nibwp')); ?>') + '</h3><p>' +
                        esc('<?php echo esc_js(__('Run a job — each step draws itself here as it happens.', 'nibwp')); ?>') + '</p></div></div>'
                    : GANTT_LOADING;
                return;
            }
            // group by run
            var groups = {}, order = [];
            gEvents.forEach(function (ev) {
                if (!groups[ev.run_id]) { groups[ev.run_id] = []; order.push(ev.run_id); }
                groups[ev.run_id].push(ev);
            });
            order.forEach(function (rid) { groups[rid].sort(function (a, b) { return a.ts - b.ts; }); });
            var tNow = nowSec();
            var allTs = gEvents.map(function (e) { return e.ts; });
            var t0 = Math.min.apply(null, allTs);
            var tMax = Math.max(Math.max.apply(null, allTs), tNow);
            var span = Math.max(1, tMax - t0);
            var pct = function (t) { return ((t - t0) / span * 100); };
            // newest run first
            order.sort(function (a, b) { return Math.max.apply(null, groups[b].map(function (e) { return e.ts; })) - Math.max.apply(null, groups[a].map(function (e) { return e.ts; })); });

            // ruler ticks
            var step = niceStep(span), ruler = '';
            for (var t = 0; t <= span + 0.5; t += step) {
                ruler += '<div class="nw-gantt__tick" style="left:' + (t / span * 100) + '%"><span>' + fmtT(t) + '</span></div>';
            }

            var legend = '<div class="nw-gantt__legend">' +
                '<span class="nw-gantt__lg"><i style="background:linear-gradient(90deg,var(--nw-brand),#60a5fa)"></i><?php echo esc_js(__('Running', 'nibwp')); ?></span>' +
                '<span class="nw-gantt__lg"><i style="background:#9ca3af"></i><?php echo esc_js(__('Queued', 'nibwp')); ?></span>' +
                '<span class="nw-gantt__lg"><i style="background:var(--nw-ok)"></i><?php echo esc_js(__('Done / Approved', 'nibwp')); ?></span>' +
                '<span class="nw-gantt__lg"><i style="background:var(--nw-danger)"></i><?php echo esc_js(__('Denied / Failed', 'nibwp')); ?></span>' +
                '<span class="nw-gantt__lg"><i style="background:#6366f1"></i><?php echo esc_js(__('Step', 'nibwp')); ?></span></div>';

            var rulerRow = '<div class="nw-gantt__lane" style="cursor:default"><div class="nw-gantt__label" style="height:26px"></div><div class="nw-gantt__ruler">' + ruler + '</div></div>';

            var body = '';
            order.forEach(function (rid) {
                var evs = groups[rid];
                var last = evs[evs.length - 1];
                body += '<div class="nw-gantt__run-head">' + esc(last.job_name) +
                    ' <span class="nw-pill ' + statusClass(last.status) + '">' + esc(statusLabel(last.status)) + '</span></div>';
                evs.forEach(function (ev, i) {
                    var next = evs[i + 1];
                    var running = ev.status === 'running' || ev.status === 'queued';
                    var end = next ? next.ts : (running ? tNow : ev.ts);
                    var dur = Math.max(0, end - ev.ts);
                    var actor = ev.actor || 'system';
                    var isNew = gFirstIds[evKey(ev)] ? ' is-new' : '';
                    var left = pct(ev.ts);
                    var seg;
                    if (dur < Math.max(1, span * 0.012)) {
                        // point event → dot
                        seg = '<span class="nw-gantt__dot is-' + esc(actor) + isNew + '" style="left:' + left + '%" title="' + esc(ev.action) + '"></span>';
                    } else {
                        var w = Math.max(1.5, dur / span * 100);
                        var cls = running ? 'is-running' : 'is-' + esc(ev.status || 'info');
                        seg = '<span class="nw-gantt__bar ' + cls + isNew + '" style="left:' + left + '%;width:' + w + '%" title="' + esc(ev.action) + ' — ' + fmtT(dur) + '">' + esc(ev.action) + '</span>';
                    }
                    body += '<div class="nw-gantt__lane" data-run="' + rid + '" role="button" tabindex="0">' +
                        '<div class="nw-gantt__label"><span class="nw-gantt__actor nw-actor--' + esc(actor) + '">' + esc(actor) + '</span>' +
                        '<span class="nw-gantt__label-txt">' + esc(ev.action) + '</span></div>' +
                        '<div class="nw-gantt__track">' + seg + '</div></div>';
                });
            });
            gMount.innerHTML = '<div class="nw-gantt">' + legend + '<div class="nw-gantt__scroll"><div class="nw-gantt__inner">' +
                rulerRow + body + '</div></div></div>';
            gFirstIds = {}; // clear one-shot "new" markers after first paint
        }

        function pollActivity() {
            get('activity?since=' + gLastTs).then(function (d) {
                gLoaded = true;
                if (!d || !d.ok) { renderGantt(); return; }
                if (d.now && !gNowOffset) gNowOffset = d.now - Math.floor(Date.now() / 1000);
                (d.events || []).forEach(function (ev) {
                    var k = evKey(ev);
                    if (gSeen[k]) return;
                    gSeen[k] = 1; gFirstIds[k] = gLastTs ? 1 : 0; // animate only after first load
                    gEvents.push(ev);
                    if (ev.ts > gLastTs) gLastTs = ev.ts;
                });
                renderGantt();
            }).catch(function () {});
        }
        function startActivity() {
            if (gInterval) return;
            if (gMount && !gLoaded) gMount.innerHTML = GANTT_LOADING;
            pollActivity();
            gInterval = setInterval(function () {
                gTick++;
                if (gTick % 3 === 0) pollActivity(); // poll server ~every 4.5s
                else renderGantt();                   // grow running bars in between
            }, 1500);
        }
        function stopActivity() { if (gInterval) { clearInterval(gInterval); gInterval = null; } }
        // delegated click on a Gantt lane → open run
        if (gMount) gMount.addEventListener('click', function (e) {
            var lane = e.target.closest('.nw-gantt__lane[data-run]');
            if (lane) openRunModal(parseInt(lane.getAttribute('data-run'), 10));
        });

        /* ---- Run detail modal (from timeline item or report card) ---- */
        function openRunModal(runId) {
            openModal('<?php echo esc_js(__('Run activity', 'nibwp')); ?>', '<p class="nw-modal-loading"><span class="nw-spin"></span></p>');
            get('run/' + runId).then(function (d) {
                if (!d || !d.ok || !d.run) { qs('#nw-jobs-modal-body').innerHTML = '<p>' + esc('<?php echo esc_js(__('Could not load this run.', 'nibwp')); ?>') + '</p>'; return; }
                var run = d.run, rep = run.report || {};
                var html = '<div class="nw-modal-run"><div class="nw-modal-run__title">' + esc(run.job_name) +
                    ' <span class="nw-pill ' + statusClass(run.status) + '">' + esc(statusLabel(run.status)) + '</span></div>';
                if (rep.summary) html += '<p class="nw-report__summary">' + esc(rep.summary) + '</p>';
                var evs = (run.events || []).slice().reverse();
                html += '<ol class="nw-tl nw-tl--modal">';
                html += evs.length ? evs.map(tlItem).join('') : '<li class="nw-tl-empty">' + esc('<?php echo esc_js(__('No steps recorded yet.', 'nibwp')); ?>') + '</li>';
                html += '</ol></div>';
                qs('#nw-jobs-modal-body').innerHTML = html;
            }).catch(function () {});
        }
        function statusLabel(s) { return ({ queued: 'Queued', running: 'Running', awaiting_approval: 'Waiting on you', done: 'Done', failed: 'Failed', approved: 'Approved', denied: 'Denied' })[s] || s; }
        function statusClass(s) { return s === 'done' || s === 'approved' ? 'nw-pill--ok' : (s === 'failed' || s === 'denied' ? 'nw-pill--danger' : (s === 'awaiting_approval' ? 'nw-pill--warn' : 'nw-pill--muted')); }

        document.addEventListener('click', function (e) {
            if (e.target.closest('.nw-report__ctl')) return; // control buttons handle themselves
            var item = e.target.closest('.nw-tl-item, .nw-report');
            if (item && item.getAttribute('data-run')) openRunModal(parseInt(item.getAttribute('data-run'), 10));
        });

        /* ---- Reports: stop / delete a run ---- */
        var reports = qs('#nw-jobs-reports');
        if (reports) reports.addEventListener('click', function (e) {
            var card = e.target.closest('.nw-report'); if (!card) return;
            var rid = parseInt(card.getAttribute('data-run'), 10);
            if (e.target.closest('.nw-run-stop')) {
                var sb = e.target.closest('.nw-run-stop'); busy(sb, true);
                post('run/pause', { run_id: rid }).then(function (res) {
                    busy(sb, false);
                    if (!res.ok || !res.d.ok) { toast('Failed.', true); return; }
                    toast('<?php echo esc_js(__('Run stopped.', 'nibwp')); ?>');
                    setTimeout(function () { location.reload(); }, 500);
                }).catch(function () { busy(sb, false); toast('Network error.', true); });
            } else if (e.target.closest('.nw-run-del')) {
                var xb = e.target.closest('.nw-run-del');
                jobConfirm('<?php echo esc_js(__('This will permanently delete this run and its timeline. This cannot be undone.', 'nibwp')); ?>', '<?php echo esc_js(__('Delete run', 'nibwp')); ?>', function () {
                    busy(xb, true);
                    post('run/delete', { run_id: rid }).then(function (res) {
                        if (!res.ok || !res.d.ok) { busy(xb, false); toast('Failed.', true); return; }
                        card.style.transition = 'opacity .2s, transform .2s'; card.style.opacity = '0'; card.style.transform = 'scale(.97)';
                        setTimeout(function () { card.remove(); if (!qs('.nw-report', reports)) location.reload(); }, 200);
                        toast('<?php echo esc_js(__('Run deleted.', 'nibwp')); ?>');
                    }).catch(function () { busy(xb, false); toast('Network error.', true); });
                });
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var item = e.target.closest && e.target.closest('.nw-tl-item');
            if (item && item.getAttribute('data-run')) openRunModal(parseInt(item.getAttribute('data-run'), 10));
        });
    })();
    </script>
    <?php
}
