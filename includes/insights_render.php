<?php
/**
 * Insights presentation layer — Dashboard, Analytics, Predictions, Recommendations
 * workspaces for the instructor class page, plus the shared per-student insights
 * page used by both the instructor drill-down and the student's own portal.
 *
 * All computation lives in insights_management.php; this file is markup only.
 * Charts are emitted as <canvas data-chart="...json..."> and drawn by js/insights.js.
 */

require_once __DIR__ . '/insights_management.php';

/* ------------------------------------------------------------------ *
 * Small presentation helpers
 * ------------------------------------------------------------------ */

/** Formats a nullable numeric metric; returns an em dash when null. */
function insights_num(?float $v, int $decimals = 0, string $suffix = '%'): string
{
    if ($v === null) {
        return '&mdash;';
    }
    return number_format($v, $decimals) . $suffix;
}

/** A colored risk chip with icon + label (never color-alone). */
function insights_risk_pill(string $level): string
{
    $map = [
        'low' => ['tone-emerald', 'bi-shield-check', 'Low risk'],
        'medium' => ['tone-amber', 'bi-exclamation-triangle', 'Medium risk'],
        'high' => ['tone-rose', 'bi-exclamation-octagon', 'High risk'],
    ];
    [$tone, $icon, $label] = $map[$level] ?? $map['low'];
    return '<span class="status-pill ' . $tone . '"><i class="bi ' . $icon . '"></i> ' . $label . '</span>';
}

/** A colored trend chip with directional icon + label. */
function insights_trend_pill(string $trend): string
{
    $map = [
        'improving' => ['tone-emerald', 'bi-arrow-up-right', 'Improving'],
        'declining' => ['tone-rose', 'bi-arrow-down-right', 'Declining'],
        'stable' => ['tone-slate', 'bi-arrow-right', 'Stable'],
        'none' => ['tone-slate', 'bi-dash', 'Not enough data'],
    ];
    [$tone, $icon, $label] = $map[$trend] ?? $map['none'];
    return '<span class="status-pill ' . $tone . '"><i class="bi ' . $icon . '"></i> ' . $label . '</span>';
}

/** Human confidence band from a 0-100 completeness figure. */
function insights_confidence_label(float $confidence): string
{
    if ($confidence >= 66) {
        return 'High';
    }
    if ($confidence >= 33) {
        return 'Moderate';
    }
    return 'Low';
}

/** Emits a chart canvas that js/insights.js will pick up and draw. */
function insights_chart_tag(array $config, string $ariaLabel, int $height = 260): void
{
    echo '<div class="insights-chart-wrap" style="height:' . (int) $height . 'px">'
        . '<canvas class="insights-chart" role="img" aria-label="' . e($ariaLabel) . '" '
        . 'data-chart="' . e(json_encode($config)) . '"></canvas></div>';
}

/** Renders an empty-state panel body (shared across insights tabs). */
function insights_empty(string $icon, string $message): void
{
    echo '<div class="empty-state"><i class="bi ' . e($icon) . '"></i><span>' . e($message) . '</span></div>';
}

/**
 * Loads weights + dataset + metrics for a class once. Reused by every workspace.
 * Returns ['weights'=>..., 'dataset'=>..., 'metrics'=>...].
 */
function insights_bootstrap(PDO $pdo, int $classId): array
{
    ensure_insights_schema($pdo);
    $weights = get_class_grading_weights($pdo, $classId);
    $dataset = build_class_insights_dataset($pdo, $classId);
    $metrics = get_all_student_metrics($pdo, $classId, $dataset, $weights);
    return ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics];
}

/* ================================================================== *
 * DASHBOARD
 * ================================================================== */

function render_insights_dashboard_workspace(PDO $pdo, array $class): void
{
    $classId = (int) $class['id'];
    ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics] = insights_bootstrap($pdo, $classId);
    $summary = get_class_dashboard_summary($pdo, $classId, $dataset, $metrics, $weights);

    if (empty($dataset['students'])) {
        echo '<article class="widget-panel class-workspace-panel mt-4">';
        insights_empty('bi-people', 'No students are enrolled yet. Share the class code so students can join, then insights will populate here.');
        echo '</article>';
        return;
    }
    ?>
    <div class="metric-grid insights-summary-grid mt-4">
        <article class="metric-card tone-indigo">
            <div class="metric-icon"><i class="bi bi-mortarboard"></i></div>
            <div>
                <div class="metric-label">Class average</div>
                <div class="metric-value"><?php echo insights_num($summary['class_average'], 1); ?></div>
                <div class="metric-note">Weighted, completed components</div>
            </div>
        </article>
        <article class="metric-card tone-emerald">
            <div class="metric-icon"><i class="bi bi-calendar-check"></i></div>
            <div>
                <div class="metric-label">Attendance rate</div>
                <div class="metric-value"><?php echo insights_num($summary['attendance_rate'], 1); ?></div>
                <div class="metric-note">Across regular meetings</div>
            </div>
        </article>
        <article class="metric-card tone-amber">
            <div class="metric-icon"><i class="bi bi-chat-square-text"></i></div>
            <div>
                <div class="metric-label">Participation rate</div>
                <div class="metric-value"><?php echo insights_num($summary['participation_rate'], 1); ?></div>
                <div class="metric-note">Average of scored sessions</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-clipboard-check"></i></div>
            <div>
                <div class="metric-label">Assessments completed</div>
                <div class="metric-value"><?php echo (int) $summary['assessments_completed']; ?><span class="metric-value-sub">/ <?php echo (int) $summary['assessments_total']; ?></span></div>
                <div class="metric-note">Fully graded gradeable items</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="metric-label">Pending grading</div>
                <div class="metric-value"><?php echo (int) $summary['pending_grading']; ?></div>
                <div class="metric-note">Items with missing scores</div>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-calendar-plus"></i></div>
            <div>
                <div class="metric-label">Upcoming assessments</div>
                <div class="metric-value"><?php echo (int) $summary['upcoming_assessments']; ?></div>
                <div class="metric-note">Scheduled from today</div>
            </div>
        </article>
        <article class="metric-card tone-rose">
            <div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div>
            <div>
                <div class="metric-label">Forecast at-risk</div>
                <div class="metric-value"><?php echo (int) $summary['at_risk_count']; ?><span class="metric-value-sub">/ <?php echo (int) $summary['student_count']; ?></span></div>
                <div class="metric-note">Projected below <?php echo insights_num($summary['passing_grade'], 0); ?></div>
            </div>
        </article>
    </div>

    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Attendance Trend</h2><span>By week</span></div>
            <?php if (!empty($summary['attendance_trend']['values'])): ?>
                <?php insights_chart_tag([
                    'type' => 'line',
                    'labels' => $summary['attendance_trend']['labels'],
                    'series' => [['label' => 'Attendance %', 'data' => $summary['attendance_trend']['values'], 'color' => 'indigo']],
                    'yMax' => 100,
                ], 'Weekly attendance rate line chart'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'No attendance has been recorded yet.'); ?>
            <?php endif; ?>
        </article>

        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Distribution</h2><span>Current grades</span></div>
            <?php if (array_sum($summary['grade_distribution']['values']) > 0): ?>
                <?php insights_chart_tag([
                    'type' => 'bar',
                    'labels' => $summary['grade_distribution']['labels'],
                    'series' => [['label' => 'Students', 'data' => $summary['grade_distribution']['values'], 'color' => 'indigo']],
                ], 'Grade distribution bar chart'); ?>
            <?php else: ?>
                <?php insights_empty('bi-bar-chart', 'No graded components yet.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Assessment Completion</h2><span>Progress</span></div>
            <?php insights_chart_tag([
                'type' => 'hbar',
                'labels' => $summary['completion_progress']['labels'],
                'series' => [['label' => 'Complete %', 'data' => $summary['completion_progress']['values'], 'color' => 'emerald']],
                'yMax' => 100,
            ], 'Assessment completion progress by component', 220); ?>
        </article>

        <article class="widget-panel">
            <div class="section-heading"><h2>Quick Insights</h2><span>This week</span></div>
            <div class="insights-quicklist">
                <div class="insights-quick-row">
                    <span class="insights-quick-icon tone-emerald"><i class="bi bi-calendar-week"></i></span>
                    <div>
                        <strong>This week's attendance</strong>
                        <p><?php echo $summary['this_week_attendance'] !== null ? insights_num($summary['this_week_attendance'], 0) . ' of students present' : 'No meetings recorded this week.'; ?></p>
                    </div>
                </div>
                <div class="insights-quick-row">
                    <span class="insights-quick-icon tone-rose"><i class="bi bi-arrow-down-circle"></i></span>
                    <div>
                        <strong>Lowest-performing assessment</strong>
                        <p><?php echo $summary['lowest_assessment'] ? e($summary['lowest_assessment']['title']) . ' — ' . insights_num($summary['lowest_assessment']['avg_pct'], 0) . ' avg' : 'No graded assessments yet.'; ?></p>
                    </div>
                </div>
                <div class="insights-quick-row">
                    <span class="insights-quick-icon tone-emerald"><i class="bi bi-arrow-up-circle"></i></span>
                    <div>
                        <strong>Highest-performing assessment</strong>
                        <p><?php echo $summary['highest_assessment'] ? e($summary['highest_assessment']['title']) . ' — ' . insights_num($summary['highest_assessment']['avg_pct'], 0) . ' avg' : 'No graded assessments yet.'; ?></p>
                    </div>
                </div>
                <div class="insights-quick-row">
                    <span class="insights-quick-icon tone-amber"><i class="bi bi-clipboard-x"></i></span>
                    <div>
                        <strong>Students with missing grades</strong>
                        <p>
                            <?php if (!empty($summary['missing_students'])): ?>
                                <?php echo (int) count($summary['missing_students']); ?> student(s):
                                <?php echo e(implode(', ', array_map(static fn ($s) => $s['name'] . ' (' . $s['count'] . ')', $summary['missing_students']))); ?>
                            <?php else: ?>
                                No missing past-due grades. 🎉
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="insights-quick-row">
                    <span class="insights-quick-icon tone-indigo"><i class="bi bi-calendar-event"></i></span>
                    <div>
                        <strong>Upcoming meetings</strong>
                        <p>
                            <?php if (!empty($summary['upcoming_meetings'])): ?>
                                <?php echo e(implode(', ', array_map(static fn ($m) => date('M j', strtotime((string) $m['meeting_date'])), $summary['upcoming_meetings']))); ?>
                            <?php else: ?>
                                No upcoming meetings scheduled.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </article>
    </section>
    <?php
}

/* ================================================================== *
 * ANALYTICS
 * ================================================================== */

function render_insights_analytics_workspace(PDO $pdo, array $class): void
{
    $classId = (int) $class['id'];
    ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics] = insights_bootstrap($pdo, $classId);

    if (empty($dataset['students'])) {
        echo '<article class="widget-panel class-workspace-panel mt-4">';
        insights_empty('bi-people', 'Enroll students to unlock analytics for this class.');
        echo '</article>';
        return;
    }

    $filters = insights_resolve_filters($_GET);
    $scopeIds = insights_scope_ids($pdo, $filters);
    $descriptive = get_class_descriptive_analytics($pdo, $classId, $dataset, $metrics, $weights, $filters, $scopeIds);
    $diagnostic = get_class_diagnostic_analytics($pdo, $classId, $dataset, $metrics, $weights, $filters, $scopeIds);

    // Filter option lists.
    $groupOptions = [];
    $gs = $pdo->prepare(
        'SELECT g.id, g.name, gr.name AS grouping_name
         FROM class_grouping_groups g
         INNER JOIN class_groupings gr ON gr.id = g.grouping_id
         WHERE gr.class_id = :c AND gr.scope = "class"
         ORDER BY gr.created_at DESC, g.id'
    );
    $gs->execute([':c' => $classId]);
    foreach ($gs->fetchAll() as $row) {
        $groupOptions[(string) $row['grouping_name']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    $actionUrl = instructor_class_route($classId, 'analytics');
    ?>
    <form method="get" action="<?php echo e($actionUrl); ?>" class="insights-filter-bar mt-4">
        <div class="insights-filter-fields">
            <div class="field">
                <label class="form-label">From</label>
                <input type="date" class="form-control" name="fdate_from" value="<?php echo e($filters['date_from']); ?>">
            </div>
            <div class="field">
                <label class="form-label">To</label>
                <input type="date" class="form-control" name="fdate_to" value="<?php echo e($filters['date_to']); ?>">
            </div>
            <div class="field">
                <label class="form-label">Student</label>
                <select class="form-select" name="fstudent">
                    <option value="0">All students</option>
                    <?php foreach ($dataset['students'] as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $filters['student'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($s['student_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="form-label">Group</label>
                <select class="form-select" name="fgroup">
                    <option value="0">All groups</option>
                    <?php foreach ($groupOptions as $groupingName => $groups): ?>
                        <optgroup label="<?php echo e($groupingName); ?>">
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo (int) $g['id']; ?>" <?php echo $filters['group'] === (int) $g['id'] ? 'selected' : ''; ?>><?php echo e($g['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="form-label">Type</label>
                <select class="form-select" name="ftype">
                    <option value="">All types</option>
                    <?php foreach (assessment_types() as $key => $meta): ?>
                        <option value="<?php echo e($key); ?>" <?php echo $filters['type'] === $key ? 'selected' : ''; ?>><?php echo e($meta['label'] ?? ucfirst($key)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="form-label">Meeting</label>
                <select class="form-select" name="fmeeting">
                    <option value="0">All meetings</option>
                    <?php foreach ($dataset['meetings'] as $m): ?>
                        <option value="<?php echo (int) $m['id']; ?>" <?php echo $filters['meeting'] === (int) $m['id'] ? 'selected' : ''; ?>>
                            <?php echo e(date('M j', strtotime((string) $m['meeting_date'])) . ' (Wk ' . (int) $m['week_number'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="insights-filter-actions">
            <button class="btn btn-edupredict" type="submit"><i class="bi bi-funnel"></i> Apply</button>
            <a class="btn btn-copy" href="<?php echo e($actionUrl); ?>"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
    </form>

    <ul class="nav nav-tabs insights-tabs mt-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-descriptive" type="button" role="tab">Descriptive</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-diagnostic" type="button" role="tab">Diagnostic</button>
        </li>
    </ul>

    <div class="tab-content insights-tab-content">
        <div class="tab-pane fade show active" id="tab-descriptive" role="tabpanel">
            <?php render_insights_descriptive($descriptive, $weights); ?>
        </div>
        <div class="tab-pane fade" id="tab-diagnostic" role="tabpanel">
            <?php render_insights_diagnostic($diagnostic); ?>
        </div>
    </div>
    <?php
}

function render_insights_descriptive(array $d, array $weights): void
{
    ?>
    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Distribution</h2><span>Current grades</span></div>
            <?php if (array_sum($d['grade_distribution']['values']) > 0): ?>
                <?php insights_chart_tag(['type' => 'bar', 'labels' => $d['grade_distribution']['labels'], 'series' => [['label' => 'Students', 'data' => $d['grade_distribution']['values'], 'color' => 'indigo']]], 'Grade distribution'); ?>
            <?php else: ?>
                <?php insights_empty('bi-bar-chart', 'No graded components in range.'); ?>
            <?php endif; ?>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Component Breakdown</h2><span>Class avg per component</span></div>
            <?php
            $compLabels = array_column($d['component_breakdown'], 'component');
            $compVals = array_map(static fn ($c) => $c['avg_pct'], $d['component_breakdown']);
            $hasComp = count(array_filter($compVals, static fn ($v) => $v !== null)) > 0;
            ?>
            <?php if ($hasComp): ?>
                <?php insights_chart_tag(['type' => 'bar', 'labels' => $compLabels, 'series' => [['label' => 'Class avg %', 'data' => array_map(static fn ($v) => $v ?? 0, $compVals), 'color' => 'blue']], 'yMax' => 100], 'Grade component averages'); ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm insights-table">
                        <thead><tr><th>Component</th><th>Weight</th><th>Class avg</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['component_breakdown'] as $c): ?>
                            <tr>
                                <td><?php echo e($c['component']); ?></td>
                                <td><?php echo e(rtrim(rtrim(number_format($c['weight'], 1), '0'), '.')); ?>%</td>
                                <td><?php echo insights_num($c['avg_pct'], 1); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php insights_empty('bi-pie-chart', 'No component data yet.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Attendance Trend</h2><span>By week</span></div>
            <?php if (!empty($d['attendance_trend']['values'])): ?>
                <?php insights_chart_tag(['type' => 'line', 'labels' => $d['attendance_trend']['labels'], 'series' => [['label' => 'Attendance %', 'data' => $d['attendance_trend']['values'], 'color' => 'indigo']], 'yMax' => 100], 'Attendance trend'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'No attendance recorded.'); ?>
            <?php endif; ?>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Participation Trend</h2><span>By week</span></div>
            <?php if (!empty($d['participation_trend']['values'])): ?>
                <?php insights_chart_tag(['type' => 'line', 'labels' => $d['participation_trend']['labels'], 'series' => [['label' => 'Participation %', 'data' => $d['participation_trend']['values'], 'color' => 'emerald']], 'yMax' => 100], 'Participation trend'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'No participation recorded.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-grid two-columns mt-4">
        <?php
        render_insights_item_perf_panel('Quiz Performance', $d['quiz_performance'], 'indigo');
        render_insights_item_perf_panel('Activity Performance', $d['activity_performance'], 'blue');
        ?>
    </section>

    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Midterm vs Finals</h2><span>Class averages</span></div>
            <?php if ($d['midterm_avg'] !== null || $d['finals_avg'] !== null): ?>
                <?php insights_chart_tag(['type' => 'bar', 'labels' => ['Midterm', 'Finals'], 'series' => [['label' => 'Class avg %', 'data' => [$d['midterm_avg'] ?? 0, $d['finals_avg'] ?? 0], 'color' => 'amber']], 'yMax' => 100], 'Midterm versus finals average'); ?>
                <p class="insights-note mt-2">Midterm avg <strong><?php echo insights_num($d['midterm_avg'], 1); ?></strong> · Finals avg <strong><?php echo insights_num($d['finals_avg'], 1); ?></strong></p>
            <?php else: ?>
                <?php insights_empty('bi-clipboard-data', 'No midterm or finals scores yet.'); ?>
            <?php endif; ?>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Group Performance</h2><span><?php echo e($d['group_performance']['grouping_name'] ?: 'No grouping'); ?></span></div>
            <?php
            $groups = array_filter($d['group_performance']['groups'], static fn ($g) => $g['avg_grade'] !== null);
            ?>
            <?php if (!empty($groups)): ?>
                <?php insights_chart_tag([
                    'type' => 'hbar',
                    'labels' => array_map(static fn ($g) => $g['name'], $groups),
                    'series' => [['label' => 'Avg grade', 'data' => array_map(static fn ($g) => $g['avg_grade'], $groups), 'color' => 'indigo']],
                    'yMax' => 100,
                ], 'Average grade per group'); ?>
            <?php else: ?>
                <?php insights_empty('bi-diagram-3', 'No class grouping with grades to compare. Create a grouping under Groupings.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <article class="widget-panel mt-4">
        <div class="section-heading"><h2>Student Rankings</h2><span><?php echo (int) count($d['rankings']); ?> students</span></div>
        <div class="table-responsive">
            <table class="table table-sm insights-table">
                <thead><tr><th>#</th><th>Student</th><th>Current grade</th><th>Predicted final</th><th>Attendance</th></tr></thead>
                <tbody>
                <?php foreach ($d['rankings'] as $i => $r): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo e($r['name']); ?><span class="insights-sub"><?php echo e($r['student_no']); ?></span></td>
                        <td><strong><?php echo insights_num($r['current_grade'], 1); ?></strong></td>
                        <td><?php echo insights_num($r['predicted_final'], 1); ?></td>
                        <td><?php echo insights_num($r['attendance_rate'], 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php
}

function render_insights_item_perf_panel(string $title, array $items, string $color): void
{
    ?>
    <article class="widget-panel">
        <div class="section-heading"><h2><?php echo e($title); ?></h2><span>Per item</span></div>
        <?php
        $graded = array_filter($items, static fn ($it) => $it['avg_pct'] !== null);
        ?>
        <?php if (!empty($graded)): ?>
            <?php insights_chart_tag([
                'type' => 'bar',
                'labels' => array_map(static fn ($it) => $it['title'], $items),
                'series' => [['label' => 'Class avg %', 'data' => array_map(static fn ($it) => $it['avg_pct'] ?? 0, $items), 'color' => $color]],
                'yMax' => 100,
            ], $title . ' per item'); ?>
        <?php else: ?>
            <?php insights_empty('bi-bar-chart', 'No graded items in range.'); ?>
        <?php endif; ?>
    </article>
    <?php
}

function render_insights_diagnostic(array $d): void
{
    ?>
    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Attendance vs Grade</h2><span>Correlation</span></div>
            <?php if (!empty($d['attendance_vs_grade'])): ?>
                <?php insights_chart_tag(['type' => 'scatter', 'series' => [['label' => 'Students', 'data' => $d['attendance_vs_grade'], 'color' => 'indigo']], 'xMax' => 100, 'yMax' => 100, 'xLabel' => 'Attendance %', 'yLabel' => 'Current grade'], 'Attendance versus grade scatter'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'Not enough data to correlate.'); ?>
            <?php endif; ?>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Participation vs Grade</h2><span>Correlation</span></div>
            <?php if (!empty($d['participation_vs_grade'])): ?>
                <?php insights_chart_tag(['type' => 'scatter', 'series' => [['label' => 'Students', 'data' => $d['participation_vs_grade'], 'color' => 'emerald']], 'xMax' => 100, 'yMax' => 100, 'xLabel' => 'Participation %', 'yLabel' => 'Current grade'], 'Participation versus grade scatter'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'Not enough data to correlate.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-grid two-columns mt-4">
        <?php
        render_insights_difficulty_panel('Quiz Difficulty', $d['quiz_difficulty']);
        render_insights_difficulty_panel('Activity Difficulty', $d['activity_difficulty']);
        ?>
    </section>

    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Component Loss</h2><span>Avg points lost</span></div>
            <?php
            $loss = array_filter($d['component_loss'], static fn ($c) => $c['avg_loss'] !== null);
            ?>
            <?php if (!empty($loss)): ?>
                <?php insights_chart_tag([
                    'type' => 'hbar',
                    'labels' => array_map(static fn ($c) => $c['component'], $d['component_loss']),
                    'series' => [['label' => 'Avg points lost', 'data' => array_map(static fn ($c) => $c['avg_loss'] ?? 0, $d['component_loss']), 'color' => 'rose']],
                ], 'Average grade points lost per component'); ?>
                <p class="insights-note mt-2">Points lost = component weight × (1 − class average). Higher bars are where the class bleeds the most grade.</p>
            <?php else: ?>
                <?php insights_empty('bi-graph-down', 'No graded components yet.'); ?>
            <?php endif; ?>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Missing Assessments</h2><span>Past-due, ungraded</span></div>
            <?php if (!empty($d['missing_assessments'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm insights-table">
                        <thead><tr><th>Student</th><th>Missing</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['missing_assessments'] as $m): ?>
                            <tr><td><?php echo e($m['name']); ?></td><td><span class="status-pill tone-rose"><?php echo (int) $m['count']; ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php insights_empty('bi-clipboard-check', 'No missing past-due assessments. 🎉'); ?>
            <?php endif; ?>
        </article>
    </section>

    <article class="widget-panel mt-4">
        <div class="section-heading"><h2>Consistency</h2><span>Score volatility — higher is less consistent</span></div>
        <?php if (!empty($d['consistency'])): ?>
            <div class="table-responsive">
                <table class="table table-sm insights-table">
                    <thead><tr><th>Student</th><th>Volatility (±pts)</th><th>Current grade</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['consistency'] as $c): ?>
                        <tr>
                            <td><?php echo e($c['name']); ?></td>
                            <td><?php echo number_format($c['volatility'], 1); ?></td>
                            <td><?php echo insights_num($c['grade'], 1); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?php insights_empty('bi-activity', 'Need at least two graded items per student to gauge consistency.'); ?>
        <?php endif; ?>
    </article>
    <?php
}

function render_insights_difficulty_panel(string $title, array $rows): void
{
    ?>
    <article class="widget-panel">
        <div class="section-heading"><h2><?php echo e($title); ?></h2><span>Hardest first</span></div>
        <?php if (!empty($rows)): ?>
            <div class="table-responsive">
                <table class="table table-sm insights-table">
                    <thead><tr><th>Item</th><th>Class avg</th><th>Difficulty</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo e($r['title']); ?></td>
                            <td><?php echo insights_num($r['avg_pct'], 0); ?></td>
                            <td>
                                <?php
                                $tone = $r['difficulty'] >= 50 ? 'tone-rose' : ($r['difficulty'] >= 30 ? 'tone-amber' : 'tone-emerald');
                                ?>
                                <span class="status-pill <?php echo $tone; ?>"><?php echo insights_num($r['difficulty'], 0); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?php insights_empty('bi-bar-chart', 'No graded items yet.'); ?>
        <?php endif; ?>
    </article>
    <?php
}

/* ================================================================== *
 * PREDICTIONS
 * ================================================================== */

function render_insights_predictions_workspace(PDO $pdo, array $class): void
{
    $classId = (int) $class['id'];
    $studentId = (int) ($_GET['student'] ?? 0);

    // Drill-down: a specific student's full insights page (instructor view).
    if ($studentId > 0) {
        $tab = (string) ($_GET['tab'] ?? 'overview');
        render_student_class_insights_page($pdo, $class, $studentId, $tab, false, 'instructor');
        return;
    }

    ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics] = insights_bootstrap($pdo, $classId);

    if (empty($dataset['students'])) {
        echo '<article class="widget-panel class-workspace-panel mt-4">';
        insights_empty('bi-people', 'Enroll students to generate predictions.');
        echo '</article>';
        return;
    }

    // Order by predicted final ascending (weakest first) for the forecast + at-risk views.
    $rows = array_values($metrics);
    usort($rows, static fn ($a, $b) => ($a['predicted_final'] ?? 999) <=> ($b['predicted_final'] ?? 999));

    $atRisk = array_values(array_filter($rows, static fn ($m) => $m['at_risk']));
    $trendCounts = ['improving' => 0, 'stable' => 0, 'declining' => 0, 'none' => 0];
    foreach ($rows as $m) {
        $trendCounts[$m['trend']] = ($trendCounts[$m['trend']] ?? 0) + 1;
    }
    ?>
    <div class="insights-model-note mt-4">
        <i class="bi bi-info-circle"></i>
        <span>Forecasts use a transparent weighted-performance model (current standing, remaining-work projection, trend, attendance and missing work) — not a black-box AI. Confidence reflects how much of the term is already graded.</span>
    </div>

    <ul class="nav nav-tabs insights-tabs mt-4" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-forecast" type="button" role="tab">Grade Forecast</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-atrisk" type="button" role="tab">At-Risk (<?php echo (int) count($atRisk); ?>)</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-trend" type="button" role="tab">Performance Trend</button></li>
    </ul>

    <div class="tab-content insights-tab-content">
        <div class="tab-pane fade show active" id="tab-forecast" role="tabpanel">
            <article class="widget-panel mt-4">
                <div class="section-heading"><h2>Predicted Final Grades</h2><span>Click a student for full insights</span></div>
                <div class="table-responsive">
                    <table class="table table-sm insights-table">
                        <thead><tr><th>Student</th><th>Current</th><th>Predicted final</th><th>Risk</th><th>Trend</th><th>Confidence</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $m): ?>
                            <tr>
                                <td><?php echo e($m['student_name']); ?><span class="insights-sub"><?php echo e($m['student_no']); ?></span></td>
                                <td><?php echo insights_num($m['current_grade'], 1); ?></td>
                                <td><strong><?php echo insights_num($m['predicted_final'], 1); ?></strong></td>
                                <td><?php echo insights_risk_pill($m['risk_level']); ?></td>
                                <td><?php echo insights_trend_pill($m['trend']); ?></td>
                                <td><?php echo insights_confidence_label($m['confidence']); ?> (<?php echo insights_num($m['confidence'], 0); ?>)</td>
                                <td><a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route($classId, 'predictions') . '?student=' . (int) $m['id']); ?>"><i class="bi bi-arrow-right-circle"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>

        <div class="tab-pane fade" id="tab-atrisk" role="tabpanel">
            <article class="widget-panel mt-4">
                <div class="section-heading"><h2>Forecast At-Risk Students</h2><span><?php echo (int) count($atRisk); ?> flagged</span></div>
                <?php if (!empty($atRisk)): ?>
                    <div class="insights-risk-list">
                        <?php foreach ($atRisk as $m): ?>
                            <div class="insights-risk-card">
                                <div class="insights-risk-head">
                                    <div>
                                        <strong><?php echo e($m['student_name']); ?></strong>
                                        <span class="insights-sub"><?php echo e($m['student_no']); ?></span>
                                    </div>
                                    <?php echo insights_risk_pill($m['risk_level']); ?>
                                </div>
                                <div class="insights-risk-meta">
                                    <span>Predicted <strong><?php echo insights_num($m['predicted_final'], 1); ?></strong></span>
                                    <span>Attendance <?php echo insights_num($m['attendance_rate'], 0); ?></span>
                                    <span>Missing <?php echo (int) $m['missing_count']; ?></span>
                                    <span><?php echo insights_trend_pill($m['trend']); ?></span>
                                </div>
                                <p class="insights-risk-reason"><i class="bi bi-info-circle"></i> <?php echo e(insights_risk_reason($m)); ?></p>
                                <a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route($classId, 'predictions') . '?student=' . (int) $m['id']); ?>">View insights</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-shield-check', 'No students are currently forecast at risk.'); ?>
                <?php endif; ?>
            </article>
        </div>

        <div class="tab-pane fade" id="tab-trend" role="tabpanel">
            <section class="content-grid two-columns mt-4">
                <article class="widget-panel">
                    <div class="section-heading"><h2>Trend Breakdown</h2><span>Direction of recent scores</span></div>
                    <div class="metric-grid insights-trend-grid">
                        <article class="metric-card tone-emerald"><div><div class="metric-label">Improving</div><div class="metric-value"><?php echo (int) $trendCounts['improving']; ?></div></div></article>
                        <article class="metric-card tone-slate"><div><div class="metric-label">Stable</div><div class="metric-value"><?php echo (int) $trendCounts['stable']; ?></div></div></article>
                        <article class="metric-card tone-rose"><div><div class="metric-label">Declining</div><div class="metric-value"><?php echo (int) $trendCounts['declining']; ?></div></div></article>
                        <article class="metric-card"><div><div class="metric-label">Not enough data</div><div class="metric-value"><?php echo (int) $trendCounts['none']; ?></div></div></article>
                    </div>
                </article>
                <article class="widget-panel">
                    <div class="section-heading"><h2>Declining Students</h2><span>Prioritize outreach</span></div>
                    <?php $declining = array_filter($rows, static fn ($m) => $m['trend'] === 'declining'); ?>
                    <?php if (!empty($declining)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm insights-table">
                                <thead><tr><th>Student</th><th>Current</th><th>Predicted</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($declining as $m): ?>
                                    <tr>
                                        <td><?php echo e($m['student_name']); ?></td>
                                        <td><?php echo insights_num($m['current_grade'], 1); ?></td>
                                        <td><?php echo insights_num($m['predicted_final'], 1); ?></td>
                                        <td><a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route($classId, 'predictions') . '?student=' . (int) $m['id']); ?>"><i class="bi bi-arrow-right-circle"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <?php insights_empty('bi-emoji-smile', 'No students are trending downward.'); ?>
                    <?php endif; ?>
                </article>
            </section>
        </div>
    </div>
    <?php
}

/* ================================================================== *
 * SHARED STUDENT INSIGHTS PAGE (instructor drill-down + student portal)
 * ================================================================== */

/**
 * The per-student insights page. Backs both the instructor drill-down
 * (Predictions ?student=) and the student's own portal (read-only).
 *
 * @param array  $class      class row (needs id; label fields for headings)
 * @param int    $studentId  target student
 * @param string $tab        overview|attendance|participation|predictions|goal
 * @param bool   $readOnly   student view hides instructor-only links
 * @param string $viewerRole 'instructor' | 'student'
 */
function render_student_class_insights_page(PDO $pdo, array $class, int $studentId, string $tab, bool $readOnly, string $viewerRole): void
{
    $classId = (int) $class['id'];
    ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics] = insights_bootstrap($pdo, $classId);
    $bundle = $metrics[$studentId] ?? null;

    if ($bundle === null) {
        echo '<article class="widget-panel class-workspace-panel mt-4">';
        insights_empty('bi-person-x', 'This student is not enrolled in the class.');
        echo '</article>';
        return;
    }

    $validTabs = ['overview', 'attendance', 'participation', 'predictions', 'goal'];
    $tab = in_array($tab, $validTabs, true) ? $tab : 'overview';
    $recommendations = generate_student_recommendations($bundle, $weights);
    $savedGoal = get_student_goal($pdo, $classId, $studentId);
    $goalCsrf = csrf_token('csrf_student_goal_token');
    ?>
    <div class="insights-student-header mt-4">
        <div>
            <div class="eyebrow"><?php echo $readOnly ? 'My insights' : 'Student insights'; ?></div>
            <h2><?php echo e($bundle['student_name']); ?></h2>
            <p class="insights-sub"><?php echo e($bundle['student_no']); ?></p>
        </div>
        <?php if (!$readOnly): ?>
            <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'predictions')); ?>"><i class="bi bi-arrow-left"></i> All predictions</a>
        <?php endif; ?>
    </div>

    <div class="metric-grid insights-summary-grid mt-3">
        <article class="metric-card tone-indigo"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Current grade</div><div class="metric-value"><?php echo insights_num($bundle['current_grade'], 1); ?></div></div></article>
        <article class="metric-card tone-amber"><div class="metric-icon"><i class="bi bi-graph-up-arrow"></i></div><div><div class="metric-label">Predicted final</div><div class="metric-value"><?php echo insights_num($bundle['predicted_final'], 1); ?></div><div class="metric-note">Passing <?php echo insights_num($bundle['passing_grade'], 0); ?></div></div></article>
        <article class="metric-card"><div class="metric-icon"><i class="bi bi-shield"></i></div><div><div class="metric-label">Risk level</div><div class="metric-value-pill"><?php echo insights_risk_pill($bundle['risk_level']); ?></div></div></article>
        <article class="metric-card"><div class="metric-icon"><i class="bi bi-patch-check"></i></div><div><div class="metric-label">Confidence</div><div class="metric-value"><?php echo insights_confidence_label($bundle['confidence']); ?></div><div class="metric-note"><?php echo insights_num($bundle['confidence'], 0); ?> of term graded</div></div></article>
        <article class="metric-card tone-emerald"><div class="metric-icon"><i class="bi bi-calendar-check"></i></div><div><div class="metric-label">Attendance</div><div class="metric-value"><?php echo insights_num($bundle['attendance_rate'], 0); ?></div></div></article>
        <article class="metric-card"><div class="metric-icon"><i class="bi bi-chat-square-text"></i></div><div><div class="metric-label">Participation</div><div class="metric-value"><?php echo insights_num($bundle['participation_rate'], 0); ?></div></div></article>
    </div>

    <?php
    // Client-side Bootstrap tabs: all panes render at once and switch without a
    // page reload, so navigating Overview/Attendance/Participation/Predictions never
    // jumps to the top. The `?tab=` deep-link only sets which pane starts active.
    $tabLabels = ['overview' => 'Overview', 'attendance' => 'Attendance', 'participation' => 'Participation', 'predictions' => 'Predictions', 'goal' => 'Goal Analysis'];
    $paneId = static fn (string $k): string => 'stins-' . $k;
    ?>
    <ul class="nav nav-tabs insights-tabs mt-4" role="tablist" data-insights-tabset>
        <?php foreach ($tabLabels as $key => $label): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $tab === $key ? 'active' : ''; ?>" id="<?php echo e($paneId($key)); ?>-btn" data-bs-toggle="tab" data-bs-target="#<?php echo e($paneId($key)); ?>" data-tab-key="<?php echo e($key); ?>" type="button" role="tab" aria-controls="<?php echo e($paneId($key)); ?>" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>"><?php echo e($label); ?></button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content insights-tab-content">
        <div class="tab-pane fade <?php echo $tab === 'overview' ? 'show active' : ''; ?>" id="<?php echo e($paneId('overview')); ?>" role="tabpanel">
            <?php render_student_overview_tab($bundle, $weights, $recommendations, $readOnly); ?>
        </div>
        <div class="tab-pane fade <?php echo $tab === 'attendance' ? 'show active' : ''; ?>" id="<?php echo e($paneId('attendance')); ?>" role="tabpanel">
            <?php render_student_attendance_tab($dataset, $studentId, $bundle); ?>
        </div>
        <div class="tab-pane fade <?php echo $tab === 'participation' ? 'show active' : ''; ?>" id="<?php echo e($paneId('participation')); ?>" role="tabpanel">
            <?php render_student_participation_tab($dataset, $studentId, $bundle); ?>
        </div>
        <div class="tab-pane fade <?php echo $tab === 'predictions' ? 'show active' : ''; ?>" id="<?php echo e($paneId('predictions')); ?>" role="tabpanel">
            <?php render_student_predictions_tab($bundle); ?>
        </div>
        <div class="tab-pane fade <?php echo $tab === 'goal' ? 'show active' : ''; ?>" id="<?php echo e($paneId('goal')); ?>" role="tabpanel">
            <?php render_student_goal_tab($bundle, $readOnly, $savedGoal, $classId, $studentId, $goalCsrf); ?>
        </div>
    </div>
    <?php
}

function render_student_overview_tab(array $bundle, array $weights, array $recommendations, bool $readOnly): void
{
    $labels = grading_weight_components();
    ?>
    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Components</h2><span>Standing per component</span></div>
            <div class="table-responsive">
                <table class="table table-sm insights-table">
                    <thead><tr><th>Component</th><th>Weight</th><th>Score</th><th>Completed</th></tr></thead>
                    <tbody>
                    <?php foreach ($labels as $key => $label): $c = $bundle['components'][$key]; ?>
                        <tr>
                            <td><?php echo e($label); ?></td>
                            <td><?php echo e(rtrim(rtrim(number_format($c['weight'], 1), '0'), '.')); ?>%</td>
                            <td><?php echo $c['has_data'] ? insights_num($c['pct'], 1) : '<span class="insights-sub">No data</span>'; ?></td>
                            <td><?php echo insights_num($c['completion'] * 100, 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Grade Trend</h2><span>Chronological scores</span></div>
            <?php if (count($bundle['series']) >= 2): ?>
                <?php insights_chart_tag([
                    'type' => 'line',
                    'labels' => array_map(static fn ($s) => $s['label'], $bundle['series']),
                    'series' => [['label' => 'Score %', 'data' => array_map(static fn ($s) => round($s['pct'], 1), $bundle['series']), 'color' => 'indigo']],
                    'yMax' => 100,
                ], 'Student score trend'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'Not enough graded items to chart a trend yet.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <article class="widget-panel mt-4">
        <div class="section-heading"><h2>Recommendations</h2><span>Personalized</span></div>
        <?php render_recommendation_cards($recommendations); ?>
    </article>
    <?php
}

function render_student_attendance_tab(array $dataset, int $studentId, array $bundle): void
{
    $attRow = $dataset['att_matrix'][$studentId] ?? [];
    ?>
    <article class="widget-panel mt-4">
        <div class="section-heading"><h2>Attendance Record</h2><span><?php echo insights_num($bundle['attendance_rate'], 1); ?> present</span></div>
        <?php if (empty($attRow)): ?>
            <?php insights_empty('bi-calendar-x', 'No attendance recorded yet.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm insights-table">
                    <thead><tr><th>Date</th><th>Week</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($dataset['meetings'] as $m):
                        $status = $attRow[(int) $m['id']] ?? null;
                        if ($status === null) { continue; }
                        $tone = ['present' => 'tone-emerald', 'late' => 'tone-amber', 'excused' => 'tone-slate', 'absent' => 'tone-rose'][$status] ?? 'tone-slate';
                    ?>
                        <tr>
                            <td><?php echo e(date('M j, Y', strtotime((string) $m['meeting_date']))); ?></td>
                            <td><?php echo (int) $m['week_number']; ?></td>
                            <td><span class="status-pill <?php echo $tone; ?>"><?php echo e(ucfirst($status)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

function render_student_participation_tab(array $dataset, int $studentId, array $bundle): void
{
    $partRow = $dataset['part_matrix'][$studentId] ?? [];
    $max = $dataset['part_max'];
    ?>
    <article class="widget-panel mt-4">
        <div class="section-heading"><h2>Participation Record</h2><span><?php echo insights_num($bundle['participation_rate'], 1); ?> average</span></div>
        <?php if (empty($partRow)): ?>
            <?php insights_empty('bi-chat-square', 'No participation recorded yet.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm insights-table">
                    <thead><tr><th>Date</th><th>Week</th><th>Score</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php foreach ($dataset['meetings'] as $m):
                        $entry = $partRow[(int) $m['id']] ?? null;
                        if ($entry === null) { continue; }
                    ?>
                        <tr>
                            <td><?php echo e(date('M j, Y', strtotime((string) $m['meeting_date']))); ?></td>
                            <td><?php echo (int) $m['week_number']; ?></td>
                            <td><?php echo e(number_format((float) $entry['score'], 1)); ?> / <?php echo e(number_format($max, 0)); ?></td>
                            <td><?php echo e((string) ($entry['remarks'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

function render_student_predictions_tab(array $bundle): void
{
    ?>
    <div class="insights-model-note mt-4">
        <i class="bi bi-info-circle"></i>
        <span>These figures come from a transparent weighted-performance model based on your current standing, remaining work, attendance, and recent trend — not a guaranteed outcome.</span>
    </div>
    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Forecast</h2><span>Where you're headed</span></div>
            <div class="insights-forecast-list">
                <div class="insights-forecast-row"><span>Current grade</span><strong><?php echo insights_num($bundle['current_grade'], 1); ?></strong></div>
                <div class="insights-forecast-row"><span>Predicted final</span><strong><?php echo insights_num($bundle['predicted_final'], 1); ?></strong></div>
                <div class="insights-forecast-row"><span>Passing grade</span><strong><?php echo insights_num($bundle['passing_grade'], 0); ?></strong></div>
                <div class="insights-forecast-row"><span>Risk level</span><span><?php echo insights_risk_pill($bundle['risk_level']); ?></span></div>
                <div class="insights-forecast-row"><span>Trend</span><span><?php echo insights_trend_pill($bundle['trend']); ?></span></div>
                <div class="insights-forecast-row"><span>Confidence</span><strong><?php echo insights_confidence_label($bundle['confidence']); ?> (<?php echo insights_num($bundle['confidence'], 0); ?>)</strong></div>
                <div class="insights-forecast-row"><span>Missing assessments</span><strong><?php echo (int) $bundle['missing_count']; ?></strong></div>
            </div>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Score Trend</h2><span>Chronological</span></div>
            <?php if (count($bundle['series']) >= 2): ?>
                <?php insights_chart_tag([
                    'type' => 'line',
                    'labels' => array_map(static fn ($s) => $s['label'], $bundle['series']),
                    'series' => [['label' => 'Score %', 'data' => array_map(static fn ($s) => round($s['pct'], 1), $bundle['series']), 'color' => 'indigo']],
                    'yMax' => 100,
                ], 'Score trend'); ?>
            <?php else: ?>
                <?php insights_empty('bi-graph-up', 'Not enough graded items yet.'); ?>
            <?php endif; ?>
        </article>
    </section>
    <?php
}

/**
 * Goal Analysis tab.
 * - Student view ($readOnly): the entered target is their PERMANENT saved goal —
 *   the form POSTs to persist it (see pages/student/class-insights.php).
 * - Instructor view: the target is TEMPORARY (GET ?goal=), only for this session's
 *   analysis; it is never saved. The student's own saved goal is shown as the
 *   default/reference. If the student saved none, the instructor may enter a temp one.
 */
function render_student_goal_tab(array $bundle, bool $readOnly, ?float $savedGoal, int $classId, int $studentId, string $csrfToken): void
{
    $goalInput = isset($_GET['goal']) && is_numeric($_GET['goal']) ? (float) $_GET['goal'] : null;
    // Effective goal to analyze: explicit temp override, else the saved target, else passing.
    $default = $savedGoal ?? max($bundle['passing_grade'], 75.0);
    $goal = $goalInput !== null ? max(1.0, min(100.0, $goalInput)) : $default;
    $analysis = compute_goal_analysis($bundle, $goal);
    $goalValue = rtrim(rtrim(number_format($goal, 1), '0'), '.');

    $statusMap = [
        'on_track' => ['tone-indigo', 'Reachable — here\'s what it takes'],
        'secured' => ['tone-emerald', 'Already secured 🎉'],
        'infeasible' => ['tone-rose', 'Not reachable with remaining work'],
        'locked_short' => ['tone-rose', 'Grade is locked below this goal'],
    ];
    [$statusTone, $statusText] = $statusMap[$analysis['status']] ?? $statusMap['on_track'];
    ?>
    <section class="content-grid two-columns mt-4">
        <article class="widget-panel">
            <div class="section-heading"><h2>Goal Analysis</h2><span>What grade do I need?</span></div>

            <?php if ($readOnly): ?>
                <?php if ($savedGoal !== null): ?>
                    <p class="insights-note mb-2"><i class="bi bi-bookmark-star"></i> Your saved target grade is <strong><?php echo insights_num($savedGoal, 1); ?></strong>. Update it below anytime.</p>
                <?php else: ?>
                    <p class="insights-note mb-2"><i class="bi bi-info-circle"></i> You haven't set a target grade yet. Enter one below and save it.</p>
                <?php endif; ?>
                <form method="post" action="<?php echo e(url_for('pages/student/class-insights.php')); ?>" class="insights-goal-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="insights_action" value="save_goal">
                    <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
                    <div class="field">
                        <label class="form-label" for="goal-input">My target final grade</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="goal-input" name="goal" min="1" max="100" step="0.5" value="<?php echo e($goalValue); ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <button class="btn btn-edupredict" type="submit"><i class="bi bi-bookmark-check"></i> Save my target grade</button>
                </form>
            <?php else: ?>
                <p class="insights-note mb-2">
                    <i class="bi bi-info-circle"></i>
                    <?php if ($savedGoal !== null): ?>
                        This student's saved target is <strong><?php echo insights_num($savedGoal, 1); ?></strong>. You can try a different target below &mdash; it only affects this view and is <strong>not saved</strong>.
                    <?php else: ?>
                        This student hasn't set a target grade. Enter a temporary target below to explore &mdash; it only affects this view and is <strong>not saved</strong>.
                    <?php endif; ?>
                </p>
                <form method="get" action="<?php echo e(instructor_class_route($classId, 'predictions')); ?>" class="insights-goal-form">
                    <input type="hidden" name="student" value="<?php echo (int) $studentId; ?>">
                    <input type="hidden" name="tab" value="goal">
                    <div class="field">
                        <label class="form-label" for="goal-input">Target final grade (temporary)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="goal-input" name="goal" min="1" max="100" step="0.5" value="<?php echo e($goalValue); ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <button class="btn btn-edupredict" type="submit"><i class="bi bi-calculator"></i> Analyze</button>
                </form>
            <?php endif; ?>

            <div class="insights-goal-status <?php echo $statusTone; ?> mt-3">
                <strong><?php echo e($statusText); ?></strong>
            </div>
            <div class="insights-forecast-list mt-3">
                <div class="insights-forecast-row"><span>Locked-in points so far</span><strong><?php echo insights_num($analysis['current_locked'], 1); ?></strong></div>
                <div class="insights-forecast-row"><span>Maximum still achievable</span><strong><?php echo insights_num($analysis['max_achievable'], 1); ?></strong></div>
                <div class="insights-forecast-row"><span>Remaining weight</span><strong><?php echo insights_num($analysis['remaining_weight'], 1); ?></strong></div>
                <?php if ($analysis['required_avg'] !== null): ?>
                    <div class="insights-forecast-row"><span>Needed average on remaining work</span><strong><?php echo insights_num(max(0.0, $analysis['required_avg']), 1); ?></strong></div>
                <?php endif; ?>
            </div>
        </article>
        <article class="widget-panel">
            <div class="section-heading"><h2>Where to Focus</h2><span>Remaining weight by component</span></div>
            <?php if (!empty($analysis['remaining_components'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm insights-table">
                        <thead><tr><th>Component</th><th>Weight still open</th></tr></thead>
                        <tbody>
                        <?php foreach ($analysis['remaining_components'] as $rc): ?>
                            <tr><td><?php echo e($rc['label']); ?></td><td><?php echo insights_num($rc['remaining_weight'], 1); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="insights-note mt-2">
                    <?php if ($analysis['required_avg'] !== null && $analysis['status'] === 'on_track'): ?>
                        Average about <strong><?php echo insights_num(max(0.0, $analysis['required_avg']), 0); ?></strong> across these to hit <?php echo insights_num($goal, 0); ?>.
                    <?php elseif ($analysis['status'] === 'infeasible'): ?>
                        Even perfect scores on all remaining work fall short of this goal.
                    <?php else: ?>
                        This goal is already locked in.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php insights_empty('bi-check2-circle', 'All components are fully graded — the final grade is fixed.'); ?>
            <?php endif; ?>
        </article>
    </section>
    <?php
}

/* ================================================================== *
 * RECOMMENDATIONS
 * ================================================================== */

function render_insights_recommendations_workspace(PDO $pdo, array $class): void
{
    $classId = (int) $class['id'];
    ['weights' => $weights, 'dataset' => $dataset, 'metrics' => $metrics] = insights_bootstrap($pdo, $classId);

    if (empty($dataset['students'])) {
        echo '<article class="widget-panel class-workspace-panel mt-4">';
        insights_empty('bi-people', 'Enroll students to generate recommendations.');
        echo '</article>';
        return;
    }

    $assessment = generate_assessment_recommendations($dataset);
    $group = generate_group_recommendations($pdo, $classId, $metrics);
    $instructor = generate_instructor_recommendations($pdo, $classId, $dataset, $metrics, $weights);
    ?>
    <ul class="nav nav-tabs insights-tabs mt-4" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-interventions" type="button" role="tab">Student Interventions</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-assessment" type="button" role="tab">Assessment Insights</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-teaching" type="button" role="tab">Teaching &amp; Groups</button></li>
    </ul>

    <div class="tab-content insights-tab-content">
        <div class="tab-pane fade show active" id="tab-interventions" role="tabpanel">
            <article class="widget-panel mt-4">
                <div class="section-heading"><h2>Immediate Attention</h2><span>Highest-risk students</span></div>
                <?php if (!empty($instructor['immediate_attention'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm insights-table">
                            <thead><tr><th>Student</th><th>Risk</th><th>Predicted</th><th>Why</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($instructor['immediate_attention'] as $p): ?>
                                <tr>
                                    <td><?php echo e($p['name']); ?></td>
                                    <td><?php echo insights_risk_pill($p['risk_level']); ?></td>
                                    <td><?php echo insights_num($p['predicted_final'], 1); ?></td>
                                    <td><?php echo e($p['reason']); ?></td>
                                    <td><a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route($classId, 'predictions') . '?student=' . (int) $p['id']); ?>"><i class="bi bi-arrow-right-circle"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-shield-check', 'No students need immediate attention right now.'); ?>
                <?php endif; ?>
            </article>

            <article class="widget-panel mt-4">
                <div class="section-heading"><h2>Intervention Priorities</h2><span>All flagged students, ranked</span></div>
                <?php if (!empty($instructor['intervention_priorities'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm insights-table">
                            <thead><tr><th>#</th><th>Student</th><th>Risk score</th><th>Predicted</th><th>Reason</th></tr></thead>
                            <tbody>
                            <?php foreach ($instructor['intervention_priorities'] as $i => $p): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo e($p['name']); ?></td>
                                    <td><?php echo (int) $p['risk_score']; ?></td>
                                    <td><?php echo insights_num($p['predicted_final'], 1); ?></td>
                                    <td><?php echo e($p['reason']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-shield-check', 'No intervention needed — the class is on track.'); ?>
                <?php endif; ?>
            </article>
        </div>

        <div class="tab-pane fade" id="tab-assessment" role="tabpanel">
            <section class="content-grid two-columns mt-4">
                <?php render_assessment_rec_panel('Difficult Quizzes', $assessment['difficult_quizzes'], 'bi-patch-question'); ?>
                <?php render_assessment_rec_panel('Difficult Activities', $assessment['difficult_activities'], 'bi-journal-check'); ?>
            </section>
            <article class="widget-panel mt-4">
                <div class="section-heading"><h2>Low-Performing Topics</h2><span>Class avg under 65%</span></div>
                <?php if (!empty($assessment['low_topics'])): ?>
                    <div class="insights-rec-list">
                        <?php foreach ($assessment['low_topics'] as $t): ?>
                            <div class="insights-rec-item">
                                <span class="insights-rec-icon tone-rose"><i class="bi bi-exclamation-triangle"></i></span>
                                <div>
                                    <strong><?php echo e($t['title']); ?> <span class="insights-sub">(<?php echo e(ucfirst($t['type'])); ?>)</span></strong>
                                    <p>Class average <?php echo insights_num($t['avg_pct'], 0); ?> — consider a targeted review or re-teach.</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-emoji-smile', 'No low-performing topics — nice work.'); ?>
                <?php endif; ?>
            </article>
        </div>

        <div class="tab-pane fade" id="tab-teaching" role="tabpanel">
            <section class="content-grid two-columns mt-4">
                <article class="widget-panel">
                    <div class="section-heading"><h2>Topics to Review</h2><span>Lowest-scoring items</span></div>
                    <?php if (!empty($instructor['review_topics'])): ?>
                        <div class="insights-rec-list">
                            <?php foreach ($instructor['review_topics'] as $t): ?>
                                <div class="insights-rec-item">
                                    <span class="insights-rec-icon tone-amber"><i class="bi bi-book"></i></span>
                                    <div><strong><?php echo e($t['title']); ?></strong><p>Class average <?php echo insights_num($t['avg_pct'], 0); ?>.</p></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <?php insights_empty('bi-emoji-smile', 'No weak topics to review.'); ?>
                    <?php endif; ?>
                    <?php if ($instructor['quiz_practice_needed']): ?>
                        <div class="insights-rec-item mt-3">
                            <span class="insights-rec-icon tone-indigo"><i class="bi bi-lightning"></i></span>
                            <div><strong>Add quiz practice</strong><p>Overall quiz average is <?php echo insights_num($instructor['quiz_avg'], 0); ?>. Short low-stakes practice quizzes could lift retention.</p></div>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="widget-panel">
                    <div class="section-heading"><h2>Group Recommendations</h2><span><?php echo e($group['grouping_name'] ?: 'No grouping'); ?></span></div>
                    <?php if ($group['grouping_name'] === null): ?>
                        <?php insights_empty('bi-diagram-3', 'Create a class grouping to unlock group recommendations.'); ?>
                    <?php else: ?>
                        <?php if (!empty($group['weak_groups'])): ?>
                            <div class="insights-rec-list">
                                <?php foreach ($group['weak_groups'] as $g): ?>
                                    <div class="insights-rec-item">
                                        <span class="insights-rec-icon tone-rose"><i class="bi bi-people"></i></span>
                                        <div><strong><?php echo e($g['name']); ?></strong><p>Group average <?php echo insights_num($g['avg_grade'], 0); ?> vs class <?php echo insights_num($group['class_avg'], 0); ?>. Consider regrouping or extra support.</p></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="insights-note">All groups are performing near or above the class average.</p>
                        <?php endif; ?>
                        <?php if (!empty($group['mentoring_pairs'])): ?>
                            <h3 class="insights-subhead mt-3">Suggested peer mentoring</h3>
                            <div class="insights-rec-list">
                                <?php foreach ($group['mentoring_pairs'] as $pair): ?>
                                    <div class="insights-rec-item">
                                        <span class="insights-rec-icon tone-emerald"><i class="bi bi-person-hearts"></i></span>
                                        <div><strong><?php echo e($pair['mentor']); ?> → <?php echo e($pair['mentee']); ?></strong><p>Pair a strong performer with a struggling peer.</p></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            </section>
        </div>
    </div>
    <?php
}

function render_assessment_rec_panel(string $title, array $items, string $icon): void
{
    ?>
    <article class="widget-panel">
        <div class="section-heading"><h2><?php echo e($title); ?></h2><span>Under 75% avg</span></div>
        <?php if (!empty($items)): ?>
            <div class="insights-rec-list">
                <?php foreach ($items as $it): ?>
                    <div class="insights-rec-item">
                        <span class="insights-rec-icon tone-amber"><i class="bi <?php echo e($icon); ?>"></i></span>
                        <div><strong><?php echo e($it['title']); ?></strong><p>Class average <?php echo insights_num($it['avg_pct'], 0); ?>.</p></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php insights_empty('bi-emoji-smile', 'No difficult items in this category.'); ?>
        <?php endif; ?>
    </article>
    <?php
}

/** Renders a list of {icon, severity, title, text} recommendation cards. */
function render_recommendation_cards(array $recs): void
{
    echo '<div class="insights-rec-list">';
    foreach ($recs as $r) {
        $tone = ['high' => 'tone-rose', 'medium' => 'tone-amber', 'low' => 'tone-emerald'][$r['severity']] ?? 'tone-slate';
        echo '<div class="insights-rec-item">'
            . '<span class="insights-rec-icon ' . $tone . '"><i class="bi ' . e($r['icon']) . '"></i></span>'
            . '<div><strong>' . e($r['title']) . '</strong><p>' . e($r['text']) . '</p></div>'
            . '</div>';
    }
    echo '</div>';
}
