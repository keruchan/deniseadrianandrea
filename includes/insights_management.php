<?php
/**
 * Insights engine — descriptive/diagnostic analytics, grade prediction, goal
 * analysis, and prescriptive recommendations, all computed live from the
 * existing LMS tables (attendance, participation, assessments, groupings,
 * meetings, enrollment). No caching, no persisted results: everything is
 * recomputed on each page load so it stays in sync with grade/attendance edits.
 *
 * The prediction model is a transparent weighted-heuristic (no ML libraries):
 * a current-standing weighted average, a remaining-work projection, a
 * linear-trend read, and a blended risk score. It is intentionally explainable
 * rather than a black box — UI copy calls it a "weighted performance model".
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/attendance_management.php';
require_once __DIR__ . '/participation_management.php';
require_once __DIR__ . '/assessment_management.php';
require_once __DIR__ . '/grouping_management.php';

/* ------------------------------------------------------------------ *
 * Grading weights (persisted; feed the whole prediction engine)
 * ------------------------------------------------------------------ */

/** component key => human label. Order defines display order everywhere. */
function grading_weight_components(): array
{
    return [
        'attendance' => 'Attendance',
        'participation' => 'Participation',
        'activities' => 'Activities',
        'quizzes' => 'Quizzes',
        'midterm' => 'Midterm',
        'finals' => 'Finals',
    ];
}

/** Fallback weights used when a class has never saved Grading Settings. */
function grading_weight_defaults(): array
{
    return [
        'attendance' => 5.0,
        'participation' => 15.0,
        'activities' => 20.0,
        'quizzes' => 20.0,
        'midterm' => 20.0,
        'finals' => 20.0,
        'passing_grade' => 75.0,
    ];
}

function attendance_drop_absence_threshold(): int
{
    return 3;
}

function attendance_warning_absence_threshold(): int
{
    return 2;
}

/** Maps a grade component to its assessment `type` (attendance/participation have none). */
function insights_component_assessment_type(string $component): ?string
{
    return [
        'activities' => 'activity',
        'quizzes' => 'quiz',
        'midterm' => 'midterm',
        'finals' => 'finals',
    ][$component] ?? null;
}

function ensure_insights_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_grading_weights (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            attendance_weight DECIMAL(5,2) NOT NULL DEFAULT 5,
            participation_weight DECIMAL(5,2) NOT NULL DEFAULT 15,
            activities_weight DECIMAL(5,2) NOT NULL DEFAULT 20,
            quizzes_weight DECIMAL(5,2) NOT NULL DEFAULT 20,
            midterm_weight DECIMAL(5,2) NOT NULL DEFAULT 20,
            finals_weight DECIMAL(5,2) NOT NULL DEFAULT 20,
            passing_grade DECIMAL(5,2) NOT NULL DEFAULT 75,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_grading_weights_class_id (class_id),
            CONSTRAINT fk_class_grading_weights_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // A student's own saved target grade for a class. Set/persisted by the student;
    // the instructor's Goal Analysis input is temporary (session-only) and never
    // written here.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_student_goals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            target_grade DECIMAL(5,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_student_goals (class_id, student_id),
            CONSTRAINT fk_class_student_goals_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** A student's saved target grade for a class, or null when they haven't set one. */
function get_student_goal(PDO $pdo, int $classId, int $studentId): ?float
{
    $stmt = $pdo->prepare('SELECT target_grade FROM class_student_goals WHERE class_id = :c AND student_id = :s LIMIT 1');
    $stmt->execute([':c' => $classId, ':s' => $studentId]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (float) $val;
}

/** Persists a student's target grade (upsert). Clamped 1-100 by the caller. */
function save_student_goal(PDO $pdo, int $classId, int $studentId, float $target): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO class_student_goals (class_id, student_id, target_grade)
         VALUES (:c, :s, :t)
         ON DUPLICATE KEY UPDATE target_grade = VALUES(target_grade)'
    );
    $stmt->execute([':c' => $classId, ':s' => $studentId, ':t' => $target]);
}

/**
 * Persisted weights for a class, or the shared defaults. Always returns the
 * six component weights + `passing_grade` + a derived `total`.
 */
function get_class_grading_weights(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT attendance_weight, participation_weight, activities_weight,
                quizzes_weight, midterm_weight, finals_weight, passing_grade
         FROM class_grading_weights WHERE class_id = :class_id LIMIT 1'
    );
    $stmt->execute([':class_id' => $classId]);
    $row = $stmt->fetch();

    if (!$row) {
        $weights = grading_weight_defaults();
    } else {
        $weights = [
            'attendance' => (float) $row['attendance_weight'],
            'participation' => (float) $row['participation_weight'],
            'activities' => (float) $row['activities_weight'],
            'quizzes' => (float) $row['quizzes_weight'],
            'midterm' => (float) $row['midterm_weight'],
            'finals' => (float) $row['finals_weight'],
            'passing_grade' => (float) $row['passing_grade'],
        ];
    }

    $weights['total'] = 0.0;
    foreach (grading_weight_components() as $key => $label) {
        $weights['total'] += $weights[$key];
    }

    return $weights;
}

/**
 * Validates a posted weights array. Returns [cleaned floats, field-keyed errors].
 * Six weights each 0-100 summing to exactly 100; passing_grade 1-100.
 */
function validate_grading_weights(array $source): array
{
    $errors = [];
    $clean = [];
    $sum = 0.0;

    foreach (grading_weight_components() as $key => $label) {
        $raw = trim((string) ($source[$key . '_weight'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            $errors[$key] = $label . ' weight is required.';
            $clean[$key] = 0.0;
            continue;
        }
        $val = (float) $raw;
        if ($val < 0 || $val > 100) {
            $errors[$key] = $label . ' weight must be between 0 and 100.';
        }
        $clean[$key] = $val;
        $sum += $val;
    }

    if (empty($errors) && round($sum, 2) !== 100.0) {
        $errors['total'] = 'Component weights must total exactly 100% (currently ' . rtrim(rtrim(number_format($sum, 2), '0'), '.') . '%).';
    }

    $pass = trim((string) ($source['passing_grade'] ?? ''));
    if ($pass === '' || !is_numeric($pass) || (float) $pass < 1 || (float) $pass > 100) {
        $errors['passing_grade'] = 'Passing grade must be between 1 and 100.';
        $clean['passing_grade'] = 75.0;
    } else {
        $clean['passing_grade'] = (float) $pass;
    }

    return [$clean, $errors];
}

function save_class_grading_weights(PDO $pdo, int $classId, array $weights): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO class_grading_weights
            (class_id, attendance_weight, participation_weight, activities_weight, quizzes_weight, midterm_weight, finals_weight, passing_grade)
         VALUES (:class_id, :attendance, :participation, :activities, :quizzes, :midterm, :finals, :passing)
         ON DUPLICATE KEY UPDATE
            attendance_weight = VALUES(attendance_weight),
            participation_weight = VALUES(participation_weight),
            activities_weight = VALUES(activities_weight),
            quizzes_weight = VALUES(quizzes_weight),
            midterm_weight = VALUES(midterm_weight),
            finals_weight = VALUES(finals_weight),
            passing_grade = VALUES(passing_grade)'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':attendance' => $weights['attendance'],
        ':participation' => $weights['participation'],
        ':activities' => $weights['activities'],
        ':quizzes' => $weights['quizzes'],
        ':midterm' => $weights['midterm'],
        ':finals' => $weights['finals'],
        ':passing' => $weights['passing_grade'],
    ]);
}

/* ------------------------------------------------------------------ *
 * Class dataset — one batch load reused by every insights function
 * ------------------------------------------------------------------ */

/**
 * Loads all raw data for a class in a handful of queries (no N+1), returning
 * in-memory structures the per-student and class-level computations read from.
 */
function build_class_insights_dataset(PDO $pdo, int $classId): array
{
    $students = get_class_enrolled_students($pdo, $classId);          // [{id, student_no, student_name}]
    $meetings = get_class_meetings($pdo, $classId);                   // meetings w/ status, week_number, meeting_date, record_count
    $attMatrix = get_class_attendance_matrix($pdo, $classId);         // [sid][mid] = status
    $partMatrix = get_class_participation_matrix($pdo, $classId);     // [sid][mid] = ['score','remarks']
    $partMax = (float) participation_max_score();
    $settings = get_assessment_settings($pdo, $classId);             // ['activity'=>?int, ...]

    $itemsByType = [];
    foreach (array_keys(assessment_types()) as $type) {
        $itemsByType[$type] = get_assessment_items($pdo, $classId, $type);
    }

    // All assessment scores for the class in one query -> [itemId][studentId] = score
    $scoresByItem = [];
    $scoreStmt = $pdo->prepare(
        'SELECT cas.item_id, cas.student_id, cas.score
         FROM class_assessment_scores cas
         INNER JOIN class_assessment_items cai ON cai.id = cas.item_id
         WHERE cai.class_id = :class_id'
    );
    $scoreStmt->execute([':class_id' => $classId]);
    foreach ($scoreStmt->fetchAll() as $row) {
        $scoresByItem[(int) $row['item_id']][(int) $row['student_id']] = (float) $row['score'];
    }

    return [
        'students' => $students,
        'meetings' => $meetings,
        'att_matrix' => $attMatrix,
        'part_matrix' => $partMatrix,
        'part_max' => $partMax,
        'settings' => $settings,
        'items_by_type' => $itemsByType,
        'scores_by_item' => $scoresByItem,
    ];
}

/** Regular, materialized meetings only (the ones attendance/participation math counts). */
function insights_regular_meetings(array $meetings): array
{
    return array_values(array_filter($meetings, static fn ($m) => (string) $m['status'] === 'regular'));
}

/** Gradeable items of a type (title+date+max, plus grouping when in group mode). */
function insights_gradeable_items(array $items): array
{
    return array_values(array_filter($items, 'is_assessment_item_gradeable'));
}

/* ------------------------------------------------------------------ *
 * Per-student metrics + prediction engine
 * ------------------------------------------------------------------ */

/**
 * Full metric bundle for every enrolled student, computed from a prebuilt
 * dataset (pass one in to avoid re-querying across dashboard/analytics).
 * Returns [studentId => bundle]; see compute_student_bundle() for the shape.
 */
function get_all_student_metrics(PDO $pdo, int $classId, ?array $dataset = null, ?array $weights = null): array
{
    $dataset = $dataset ?? build_class_insights_dataset($pdo, $classId);
    $weights = $weights ?? get_class_grading_weights($pdo, $classId);
    $today = date('Y-m-d');

    // Pre-derive class-wide constants used by every student.
    $regular = insights_regular_meetings($dataset['meetings']);
    $totalRegular = count($regular);
    $takenRegular = 0;
    foreach ($regular as $m) {
        if ((int) $m['record_count'] > 0) {
            $takenRegular++;
        }
    }
    $partSessions = [];
    foreach ($dataset['part_matrix'] as $sid => $perMeeting) {
        foreach ($perMeeting as $mid => $_) {
            $partSessions[$mid] = true;
        }
    }
    $partSessionCount = count($partSessions);

    // Gradeable items per assessment component, with a planned total.
    $componentItems = [];
    foreach (['activities', 'quizzes', 'midterm', 'finals'] as $component) {
        $type = insights_component_assessment_type($component);
        $items = insights_gradeable_items($dataset['items_by_type'][$type] ?? []);
        $configured = $dataset['settings'][$type] ?? null;
        $planned = max((int) ($configured ?? 0), count($dataset['items_by_type'][$type] ?? []));
        $componentItems[$component] = ['items' => $items, 'planned' => $planned];
    }

    $out = [];
    foreach ($dataset['students'] as $student) {
        $out[(int) $student['id']] = compute_student_bundle(
            $student,
            $dataset,
            $weights,
            $componentItems,
            $totalRegular,
            $takenRegular,
            $partSessionCount,
            $today
        );
    }

    return $out;
}

/** Convenience wrapper for a single student. */
function get_student_metrics(PDO $pdo, int $classId, int $studentId, ?array $dataset = null, ?array $weights = null): ?array
{
    $all = get_all_student_metrics($pdo, $classId, $dataset, $weights);
    return $all[$studentId] ?? null;
}

/**
 * The heart of the engine: turns one student's raw records into component
 * standings, a current grade, a projected final, trend, risk, and confidence.
 */
function compute_student_bundle(array $student, array $dataset, array $weights, array $componentItems, int $totalRegular, int $takenRegular, int $partSessionCount, string $today): array
{
    $studentId = (int) $student['id'];
    $components = [];

    // --- Attendance component ---
    $attRow = $dataset['att_matrix'][$studentId] ?? [];
    $attAttended = 0;
    $attTaken = 0;
    $unexcusedAbsences = 0;
    foreach (insights_regular_meetings($dataset['meetings']) as $m) {
        $status = $attRow[(int) $m['id']] ?? null;
        if ($status === null) {
            continue;
        }
        $attTaken++;
        if ($status === 'absent') {
            $unexcusedAbsences++;
        }
        if (in_array($status, ['present', 'late', 'excused'], true)) {
            $attAttended++;
        }
    }
    $attPct = $attTaken > 0 ? ($attAttended / $attTaken) * 100 : null;
    $components['attendance'] = [
        'pct' => $attPct,
        'has_data' => $attPct !== null,
        'completion' => $totalRegular > 0 ? $takenRegular / $totalRegular : 0.0,
        'weight' => (float) $weights['attendance'],
    ];

    // --- Participation component ---
    $partRow = $dataset['part_matrix'][$studentId] ?? [];
    $partSum = 0.0;
    $partCount = 0;
    foreach ($partRow as $entry) {
        $partSum += (float) $entry['score'];
        $partCount++;
    }
    $partPct = ($partCount > 0 && $dataset['part_max'] > 0) ? ($partSum / $partCount / $dataset['part_max']) * 100 : null;
    $components['participation'] = [
        'pct' => $partPct,
        'has_data' => $partPct !== null,
        'completion' => $totalRegular > 0 ? $partSessionCount / $totalRegular : 0.0,
        'weight' => (float) $weights['participation'],
    ];

    // --- Assessment components + chronological pct series ---
    $series = [];   // chronological per-item pct for trend + charts
    $missingCount = 0;
    foreach (['activities', 'quizzes', 'midterm', 'finals'] as $component) {
        $items = $componentItems[$component]['items'];
        $planned = $componentItems[$component]['planned'];
        $sumPct = 0.0;
        $graded = 0;
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $max = (float) $item['max_score'];
            $score = $dataset['scores_by_item'][$itemId][$studentId] ?? null;
            if ($score !== null && $max > 0) {
                $pct = max(0.0, min(100.0, ($score / $max) * 100));
                $sumPct += $pct;
                $graded++;
                $series[] = [
                    'label' => (string) $item['title'],
                    'type' => (string) $item['type'],
                    'pct' => $pct,
                    'date' => (string) ($item['scheduled_date'] ?? ''),
                    'position' => (int) $item['position'],
                ];
            } elseif ($score === null && !empty($item['scheduled_date']) && (string) $item['scheduled_date'] < $today) {
                $missingCount++;   // past-due, not submitted
            }
        }
        $pct = $graded > 0 ? $sumPct / $graded : null;
        $components[$component] = [
            'pct' => $pct,
            'has_data' => $pct !== null,
            'completion' => $planned > 0 ? min(1.0, $graded / $planned) : 0.0,
            'weight' => (float) $weights[$component],
        ];
    }

    // Order the series chronologically (by date, then a stable type/position order).
    $typeOrder = ['activity' => 0, 'quiz' => 1, 'midterm' => 2, 'finals' => 3];
    usort($series, static function ($a, $b) use ($typeOrder) {
        $da = $a['date'] !== '' ? $a['date'] : '9999-12-31';
        $db = $b['date'] !== '' ? $b['date'] : '9999-12-31';
        return [$da, $typeOrder[$a['type']] ?? 9, $a['position']] <=> [$db, $typeOrder[$b['type']] ?? 9, $b['position']];
    });

    // --- Current grade: weighted average of components that have data ---
    $curNum = 0.0;
    $curDen = 0.0;
    foreach ($components as $c) {
        if ($c['has_data'] && $c['weight'] > 0) {
            $curNum += $c['weight'] * $c['pct'];
            $curDen += $c['weight'];
        }
    }
    $currentGrade = $curDen > 0 ? $curNum / $curDen : null;
    $baseline = $currentGrade ?? 50.0;   // best estimate for components with no data yet

    // --- Predicted final: each component estimated at its own standing (or baseline) ---
    $predNum = 0.0;
    $predDen = 0.0;
    $lockedPoints = 0.0;    // grade points already determinable (for goal analysis)
    $remainingWeight = 0.0; // weight still up for grabs
    foreach ($components as $c) {
        if ($c['weight'] <= 0) {
            continue;
        }
        $estPct = $c['has_data'] ? $c['pct'] : $baseline;
        $predNum += $c['weight'] * $estPct;
        $predDen += $c['weight'];

        $done = $c['completion'];
        $componentScore = $c['has_data'] ? $c['pct'] : 0.0;
        $lockedPoints += $c['weight'] * $done * ($componentScore / 100);
        $remainingWeight += $c['weight'] * (1 - $done);
    }
    $predictedFinal = $predDen > 0 ? max(0.0, min(100.0, $predNum / $predDen)) : null;

    // --- Trend from the chronological pct series (linear-ish read) ---
    [$trend, $slope, $volatility] = insights_trend_and_volatility(array_column($series, 'pct'));

    // --- Confidence: how much of the weighted term is actually locked in ---
    $confWeight = 0.0;
    $confDone = 0.0;
    foreach ($components as $c) {
        if ($c['weight'] > 0) {
            $confWeight += $c['weight'];
            $confDone += $c['weight'] * $c['completion'];
        }
    }
    $confidence = $confWeight > 0 ? ($confDone / $confWeight) * 100 : 0.0;

    // --- Risk: blended, higher = more at risk (0-100) ---
    $passing = (float) $weights['passing_grade'];
    $gradeGap = ($predictedFinal !== null && $passing > 0) ? max(0.0, ($passing - $predictedFinal) / $passing) : 0.3;
    $attRisk = $attPct !== null ? max(0.0, 1 - $attPct / 100) : 0.3;
    $trendRisk = $trend === 'declining' ? 1.0 : ($trend === 'improving' ? 0.0 : 0.4);
    $totalGradeableSoFar = 0;
    foreach ($componentItems as $ci) {
        foreach ($ci['items'] as $it) {
            if (!empty($it['scheduled_date']) && (string) $it['scheduled_date'] < $today) {
                $totalGradeableSoFar++;
            }
        }
    }
    $missingRisk = $totalGradeableSoFar > 0 ? min(1.0, $missingCount / $totalGradeableSoFar) : 0.0;
    $volRisk = min(1.0, $volatility / 30);   // stdev of 30+ pct pts => max volatility signal
    $riskScore = 100 * (0.45 * $gradeGap + 0.20 * $attRisk + 0.15 * $trendRisk + 0.15 * $missingRisk + 0.05 * $volRisk);
    $riskScore = max(0.0, min(100.0, $riskScore));
    // A projected fail is at least Medium regardless of the blended number.
    if ($predictedFinal !== null && $predictedFinal < $passing) {
        $riskScore = max($riskScore, 55.0);
    }
    if ($unexcusedAbsences >= attendance_drop_absence_threshold()) {
        $riskScore = max($riskScore, 80.0);
    } elseif ($unexcusedAbsences >= attendance_warning_absence_threshold()) {
        $riskScore = max($riskScore, 50.0);
    }
    $riskLevel = $riskScore >= 67 ? 'high' : ($riskScore >= 34 ? 'medium' : 'low');

    return [
        'id' => $studentId,
        'student_no' => (string) $student['student_no'],
        'student_name' => (string) $student['student_name'],
        'components' => $components,
        'current_grade' => $currentGrade,
        'predicted_final' => $predictedFinal,
        'baseline' => $baseline,
        'trend' => $trend,
        'trend_slope' => $slope,
        'volatility' => $volatility,
        'risk_score' => $riskScore,
        'risk_level' => $riskLevel,
        'confidence' => $confidence,
        'attendance_rate' => $attPct,
        'unexcused_absences' => $unexcusedAbsences,
        'absence_warning' => $unexcusedAbsences >= attendance_warning_absence_threshold(),
        'drop_warning' => $unexcusedAbsences >= attendance_drop_absence_threshold(),
        'participation_rate' => $partPct,
        'missing_count' => $missingCount,
        'locked_points' => $lockedPoints,
        'remaining_weight' => $remainingWeight,
        'passing_grade' => $passing,
        'series' => $series,
        'at_risk' => $riskLevel === 'high' || ($predictedFinal !== null && $predictedFinal < $passing),
    ];
}

/**
 * Returns [trend label, slope, volatility(stdev)] for a chronological pct series.
 * Trend uses total projected change across the series (slope × span); needs >= 3
 * points to call a direction, otherwise 'none'.
 */
function insights_trend_and_volatility(array $pcts): array
{
    $n = count($pcts);
    if ($n === 0) {
        return ['none', 0.0, 0.0];
    }

    // Volatility = population stdev.
    $mean = array_sum($pcts) / $n;
    $var = 0.0;
    foreach ($pcts as $p) {
        $var += ($p - $mean) ** 2;
    }
    $stdev = sqrt($var / $n);

    if ($n < 3) {
        return ['none', 0.0, $stdev];
    }

    // Least-squares slope of pct over index 0..n-1.
    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumXX = 0.0;
    foreach ($pcts as $i => $y) {
        $sumX += $i;
        $sumY += $y;
        $sumXY += $i * $y;
        $sumXX += $i * $i;
    }
    $denom = ($n * $sumXX - $sumX * $sumX);
    $slope = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0.0;
    $totalChange = $slope * ($n - 1);

    $trend = $totalChange > 5 ? 'improving' : ($totalChange < -5 ? 'declining' : 'stable');
    return [$trend, $slope, $stdev];
}

/* ------------------------------------------------------------------ *
 * Goal analysis
 * ------------------------------------------------------------------ */

/**
 * Given a student's metric bundle and a desired final grade, computes current
 * standing, the ceiling, and the average needed on remaining weighted work,
 * broken down by which components still carry unearned weight.
 */
function compute_goal_analysis(array $bundle, float $goal): array
{
    $locked = $bundle['locked_points'];
    $remaining = $bundle['remaining_weight'];
    $maxAchievable = min(100.0, $locked + $remaining);

    $requiredAvg = null;
    $status = 'on_track';
    if ($remaining <= 0.001) {
        // Nothing left to grade — the grade is effectively fixed.
        $status = $locked >= $goal ? 'secured' : 'locked_short';
    } else {
        $requiredAvg = (($goal - $locked) / $remaining) * 100;
        if ($requiredAvg <= 0) {
            $status = 'secured';       // goal already guaranteed
        } elseif ($requiredAvg > 100) {
            $status = 'infeasible';    // cannot reach even with perfect remaining work
        }
    }

    // Which components still have unearned weight (where the effort must go).
    $remainingComponents = [];
    $labels = grading_weight_components();
    foreach ($bundle['components'] as $key => $c) {
        $unearned = $c['weight'] * (1 - $c['completion']);
        if ($unearned > 0.001) {
            $remainingComponents[$key] = [
                'label' => $labels[$key] ?? ucfirst($key),
                'remaining_weight' => $unearned,
            ];
        }
    }

    return [
        'goal' => $goal,
        'current_locked' => $locked,
        'max_achievable' => $maxAchievable,
        'remaining_weight' => $remaining,
        'required_avg' => $requiredAvg,
        'status' => $status,
        'remaining_components' => $remainingComponents,
    ];
}

/* ------------------------------------------------------------------ *
 * Class-level aggregates shared by Dashboard / Analytics
 * ------------------------------------------------------------------ */

/** Class-average %, graded-count, and difficulty for one assessment item. */
function insights_item_class_stats(array $dataset, array $item): array
{
    $itemId = (int) $item['id'];
    $max = (float) $item['max_score'];
    $scores = $dataset['scores_by_item'][$itemId] ?? [];
    $sum = 0.0;
    $count = 0;
    foreach ($scores as $score) {
        if ($max > 0) {
            $sum += max(0.0, min(100.0, ($score / $max) * 100));
            $count++;
        }
    }
    $avg = $count > 0 ? $sum / $count : null;
    return [
        'id' => $itemId,
        'title' => (string) $item['title'],
        'type' => (string) $item['type'],
        'avg_pct' => $avg,
        'graded_count' => $count,
        'difficulty' => $avg !== null ? 100 - $avg : null,
        'date' => (string) ($item['scheduled_date'] ?? ''),
    ];
}

/** Meetings grouped by week_number (only weeks that have meetings), sorted. */
function insights_meetings_by_week(array $meetings): array
{
    $byWeek = [];
    foreach ($meetings as $m) {
        $byWeek[(int) $m['week_number']][] = $m;
    }
    ksort($byWeek);
    return $byWeek;
}

/**
 * Top-line numbers + quick insights + compact chart series for the Dashboard.
 */
function get_class_dashboard_summary(PDO $pdo, int $classId, array $dataset, array $metrics, array $weights): array
{
    $today = date('Y-m-d');

    // Class average of current grades (students with any data).
    $gradeVals = [];
    foreach ($metrics as $m) {
        if ($m['current_grade'] !== null) {
            $gradeVals[] = $m['current_grade'];
        }
    }
    $classAverage = $gradeVals ? array_sum($gradeVals) / count($gradeVals) : null;

    $attSummary = get_attendance_summary($pdo, $classId);

    $partVals = [];
    foreach ($metrics as $m) {
        if ($m['participation_rate'] !== null) {
            $partVals[] = $m['participation_rate'];
        }
    }
    $participationRate = $partVals ? array_sum($partVals) / count($partVals) : null;

    // Assessment completion + pending grading + upcoming.
    $enrolled = count($dataset['students']);
    $totalGradeable = 0;
    $fullyGraded = 0;
    $pendingGrading = 0;
    $upcoming = 0;
    $itemStats = [];
    foreach ($dataset['items_by_type'] as $items) {
        foreach ($items as $item) {
            if (!is_assessment_item_gradeable($item)) {
                continue;
            }
            $totalGradeable++;
            $stat = insights_item_class_stats($dataset, $item);
            $itemStats[] = $stat;
            if ((string) $item['scheduled_date'] >= $today) {
                $upcoming++;
            }
            if ($stat['graded_count'] >= $enrolled && $enrolled > 0) {
                $fullyGraded++;
            } else {
                $pendingGrading++;
            }
        }
    }

    $atRisk = 0;
    foreach ($metrics as $m) {
        if ($m['at_risk']) {
            $atRisk++;
        }
    }

    // Lowest / highest performing assessment (among graded).
    $graded = array_values(array_filter($itemStats, static fn ($s) => $s['avg_pct'] !== null));
    usort($graded, static fn ($a, $b) => $a['avg_pct'] <=> $b['avg_pct']);
    $lowest = $graded[0] ?? null;
    $highest = $graded ? end($graded) : null;

    // Students with missing grades.
    $missing = [];
    foreach ($metrics as $m) {
        if ($m['missing_count'] > 0) {
            $missing[] = ['name' => $m['student_name'], 'count' => $m['missing_count']];
        }
    }
    usort($missing, static fn ($a, $b) => $b['count'] <=> $a['count']);

    // This week's attendance rate.
    $currentWeek = attendance_current_week_number($dataset['meetings']);
    $weekAttended = 0;
    $weekTaken = 0;
    foreach (insights_regular_meetings($dataset['meetings']) as $mtg) {
        if ((int) $mtg['week_number'] !== $currentWeek) {
            continue;
        }
        foreach ($dataset['att_matrix'] as $perMeeting) {
            $status = $perMeeting[(int) $mtg['id']] ?? null;
            if ($status === null) {
                continue;
            }
            $weekTaken++;
            if (in_array($status, ['present', 'late', 'excused'], true)) {
                $weekAttended++;
            }
        }
    }
    $thisWeekAttendance = $weekTaken > 0 ? ($weekAttended / $weekTaken) * 100 : null;

    // Upcoming meetings (next few, not past, not cancelled).
    $upcomingMeetings = [];
    foreach ($dataset['meetings'] as $mtg) {
        if ((string) $mtg['meeting_date'] >= $today && (string) $mtg['status'] !== 'cancelled') {
            $upcomingMeetings[] = $mtg;
        }
    }
    usort($upcomingMeetings, static fn ($a, $b) => strcmp((string) $a['meeting_date'], (string) $b['meeting_date']));
    $upcomingMeetings = array_slice($upcomingMeetings, 0, 5);

    return [
        'class_average' => $classAverage,
        'attendance_rate' => $attSummary['attendance_rate'],
        'participation_rate' => $participationRate,
        'assessments_completed' => $fullyGraded,
        'assessments_total' => $totalGradeable,
        'pending_grading' => $pendingGrading,
        'upcoming_assessments' => $upcoming,
        'at_risk_count' => $atRisk,
        'student_count' => $enrolled,
        'passing_grade' => (float) $weights['passing_grade'],
        'this_week_attendance' => $thisWeekAttendance,
        'lowest_assessment' => $lowest,
        'highest_assessment' => $highest,
        'missing_students' => array_slice($missing, 0, 6),
        'upcoming_meetings' => $upcomingMeetings,
        'attendance_trend' => get_attendance_trend_series($dataset),
        'grade_distribution' => get_grade_distribution($metrics),
        'completion_progress' => get_completion_progress($dataset),
    ];
}

/** Per-week class attendance rate series (labels + values) for line charts. */
function get_attendance_trend_series(array $dataset): array
{
    $labels = [];
    $values = [];
    foreach (insights_meetings_by_week($dataset['meetings']) as $week => $meetings) {
        $attended = 0;
        $taken = 0;
        foreach ($meetings as $m) {
            if ((string) $m['status'] !== 'regular') {
                continue;
            }
            foreach ($dataset['att_matrix'] as $perMeeting) {
                $status = $perMeeting[(int) $m['id']] ?? null;
                if ($status === null) {
                    continue;
                }
                $taken++;
                if (in_array($status, ['present', 'late', 'excused'], true)) {
                    $attended++;
                }
            }
        }
        if ($taken > 0) {
            $labels[] = 'Wk ' . $week;
            $values[] = round(($attended / $taken) * 100, 1);
        }
    }
    return ['labels' => $labels, 'values' => $values];
}

/** Per-week class participation-% series. */
function get_participation_trend_series(array $dataset): array
{
    $labels = [];
    $values = [];
    $max = $dataset['part_max'] > 0 ? $dataset['part_max'] : 1;
    foreach (insights_meetings_by_week($dataset['meetings']) as $week => $meetings) {
        $sum = 0.0;
        $count = 0;
        foreach ($meetings as $m) {
            foreach ($dataset['part_matrix'] as $perMeeting) {
                $entry = $perMeeting[(int) $m['id']] ?? null;
                if ($entry === null) {
                    continue;
                }
                $sum += ($entry['score'] / $max) * 100;
                $count++;
            }
        }
        if ($count > 0) {
            $labels[] = 'Wk ' . $week;
            $values[] = round($sum / $count, 1);
        }
    }
    return ['labels' => $labels, 'values' => $values];
}

/** Histogram of current grades into fixed bands. */
function get_grade_distribution(array $metrics): array
{
    $bands = [
        '0-59' => 0,
        '60-69' => 0,
        '70-79' => 0,
        '80-89' => 0,
        '90-100' => 0,
    ];
    foreach ($metrics as $m) {
        $g = $m['current_grade'];
        if ($g === null) {
            continue;
        }
        if ($g < 60) {
            $bands['0-59']++;
        } elseif ($g < 70) {
            $bands['60-69']++;
        } elseif ($g < 80) {
            $bands['70-79']++;
        } elseif ($g < 90) {
            $bands['80-89']++;
        } else {
            $bands['90-100']++;
        }
    }
    return ['labels' => array_keys($bands), 'values' => array_values($bands)];
}

/** Per-component graded-item completion %, for a progress bar chart. */
function get_completion_progress(array $dataset): array
{
    $labels = [];
    $values = [];
    foreach (['activities' => 'Activities', 'quizzes' => 'Quizzes', 'midterm' => 'Midterm', 'finals' => 'Finals'] as $component => $label) {
        $type = insights_component_assessment_type($component);
        $items = $dataset['items_by_type'][$type] ?? [];
        $total = count($items);
        $ready = count(insights_gradeable_items($items));
        // "Complete" = gradeable items that have at least one score.
        $graded = 0;
        foreach (insights_gradeable_items($items) as $item) {
            if (!empty($dataset['scores_by_item'][(int) $item['id']])) {
                $graded++;
            }
        }
        $labels[] = $label;
        $values[] = $ready > 0 ? round(($graded / $ready) * 100, 1) : 0;
    }
    return ['labels' => $labels, 'values' => $values];
}

/**
 * Normalizes the analytics filter bar ($get is typically $_GET).
 * Keys read: ftype, fstudent, fgroup, fdate_from, fdate_to, fmeeting.
 */
function insights_resolve_filters(array $get): array
{
    $types = array_keys(assessment_types());
    $type = in_array((string) ($get['ftype'] ?? ''), $types, true) ? (string) $get['ftype'] : '';
    $studentId = (int) ($get['fstudent'] ?? 0);
    $groupId = (int) ($get['fgroup'] ?? 0);
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['fdate_from'] ?? '')) ? (string) $get['fdate_from'] : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['fdate_to'] ?? '')) ? (string) $get['fdate_to'] : '';
    $meetingId = (int) ($get['fmeeting'] ?? 0);

    return [
        'type' => $type,
        'student' => $studentId,
        'group' => $groupId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'meeting' => $meetingId,
        'active' => $type !== '' || $studentId > 0 || $groupId > 0 || $dateFrom !== '' || $dateTo !== '' || $meetingId > 0,
    ];
}

/** Student ids for a class_grouping_groups.id (active enrollment members). */
function insights_group_member_ids(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare('SELECT student_id FROM class_grouping_members WHERE group_id = :g');
    $stmt->execute([':g' => $groupId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Resolves normalized $filters to a student-scope set (assoc [id=>true]) or null
 * (whole class) for the descriptive/diagnostic aggregators.
 */
function insights_scope_ids(PDO $pdo, array $filters): ?array
{
    if (($filters['student'] ?? 0) > 0) {
        return [(int) $filters['student'] => true];
    }
    if (($filters['group'] ?? 0) > 0) {
        $ids = insights_group_member_ids($pdo, (int) $filters['group']);
        $scope = [];
        foreach ($ids as $id) {
            $scope[$id] = true;
        }
        return $scope ?: [-1 => true]; // empty group -> match nothing, not everything
    }
    return null;
}

/**
 * Descriptive analytics bundle (chart/table-ready). $scopeIds (assoc set) and
 * $filters narrow the data; pass empty/[] for the whole class.
 */
function get_class_descriptive_analytics(PDO $pdo, int $classId, array $dataset, array $metrics, array $weights, array $filters, ?array $scopeIds = null): array
{
    $inScope = static function (int $studentId) use ($scopeIds): bool {
        return $scopeIds === null || isset($scopeIds[$studentId]);
    };
    $scopedMetrics = array_filter($metrics, static fn ($m) => $inScope((int) $m['id']));

    $typeFilter = $filters['type'] ?? '';
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo = $filters['date_to'] ?? '';
    $itemInWindow = static function (array $item) use ($dateFrom, $dateTo): bool {
        $d = (string) ($item['scheduled_date'] ?? '');
        if ($d === '') {
            return $dateFrom === '' && $dateTo === '';
        }
        if ($dateFrom !== '' && $d < $dateFrom) {
            return false;
        }
        if ($dateTo !== '' && $d > $dateTo) {
            return false;
        }
        return true;
    };

    // Per-item performance for a type (respects date window + scope).
    $itemPerformance = function (string $type) use ($dataset, $itemInWindow, $scopeIds) {
        $rows = [];
        foreach ($dataset['items_by_type'][$type] ?? [] as $item) {
            if (!is_assessment_item_gradeable($item) || !$itemInWindow($item)) {
                continue;
            }
            $max = (float) $item['max_score'];
            $sum = 0.0;
            $count = 0;
            foreach ($dataset['scores_by_item'][(int) $item['id']] ?? [] as $sid => $score) {
                if ($scopeIds !== null && !isset($scopeIds[$sid])) {
                    continue;
                }
                if ($max > 0) {
                    $sum += max(0.0, min(100.0, ($score / $max) * 100));
                    $count++;
                }
            }
            $rows[] = [
                'title' => (string) $item['title'],
                'avg_pct' => $count > 0 ? round($sum / $count, 1) : null,
                'graded_count' => $count,
            ];
        }
        return $rows;
    };

    // Grade component breakdown (class avg % per component + weight).
    $componentBreakdown = [];
    foreach (grading_weight_components() as $key => $label) {
        $vals = [];
        foreach ($scopedMetrics as $m) {
            if ($m['components'][$key]['has_data']) {
                $vals[] = $m['components'][$key]['pct'];
            }
        }
        $componentBreakdown[] = [
            'component' => $label,
            'avg_pct' => $vals ? round(array_sum($vals) / count($vals), 1) : null,
            'weight' => (float) $weights[$key],
        ];
    }

    // Midterm vs finals (class averages + per-student pairs for scatter).
    $midStats = $itemPerformance('midterm');
    $finStats = $itemPerformance('finals');
    $midAvg = insights_avg_of(array_column($midStats, 'avg_pct'));
    $finAvg = insights_avg_of(array_column($finStats, 'avg_pct'));

    // Group performance (uses the group filter's grouping, else most recent class grouping).
    $groupPerformance = get_group_performance($pdo, $classId, $metrics, (int) ($filters['group'] ?? 0));

    // Rankings (scoped, sorted by current grade desc).
    $rankings = [];
    foreach ($scopedMetrics as $m) {
        $rankings[] = [
            'name' => $m['student_name'],
            'student_no' => $m['student_no'],
            'id' => $m['id'],
            'current_grade' => $m['current_grade'],
            'predicted_final' => $m['predicted_final'],
            'attendance_rate' => $m['attendance_rate'],
        ];
    }
    usort($rankings, static fn ($a, $b) => ($b['current_grade'] ?? -1) <=> ($a['current_grade'] ?? -1));

    return [
        'grade_distribution' => get_grade_distribution($scopedMetrics),
        'attendance_trend' => get_attendance_trend_series($dataset),
        'participation_trend' => get_participation_trend_series($dataset),
        'quiz_performance' => $itemPerformance('quiz'),
        'activity_performance' => $itemPerformance('activity'),
        'midterm_stats' => $midStats,
        'finals_stats' => $finStats,
        'midterm_avg' => $midAvg,
        'finals_avg' => $finAvg,
        'component_breakdown' => $componentBreakdown,
        'group_performance' => $groupPerformance,
        'rankings' => $rankings,
        'type_filter' => $typeFilter,
    ];
}

/** Diagnostic analytics bundle: correlations, difficulty, loss, gaps, consistency. */
function get_class_diagnostic_analytics(PDO $pdo, int $classId, array $dataset, array $metrics, array $weights, array $filters, ?array $scopeIds = null): array
{
    $scopedMetrics = array_values(array_filter($metrics, static fn ($m) => $scopeIds === null || isset($scopeIds[(int) $m['id']])));

    // Correlation scatter points.
    $attVsGrade = [];
    $partVsGrade = [];
    foreach ($scopedMetrics as $m) {
        if ($m['current_grade'] !== null && $m['attendance_rate'] !== null) {
            $attVsGrade[] = ['x' => round($m['attendance_rate'], 1), 'y' => round($m['current_grade'], 1), 'label' => $m['student_name']];
        }
        if ($m['current_grade'] !== null && $m['participation_rate'] !== null) {
            $partVsGrade[] = ['x' => round($m['participation_rate'], 1), 'y' => round($m['current_grade'], 1), 'label' => $m['student_name']];
        }
    }

    // Difficulty = 100 - class avg %, per item (harder items surface first).
    $difficulty = function (string $type) use ($dataset, $scopeIds) {
        $rows = [];
        foreach ($dataset['items_by_type'][$type] ?? [] as $item) {
            if (!is_assessment_item_gradeable($item)) {
                continue;
            }
            $stat = insights_item_class_stats($dataset, $item);
            if ($stat['avg_pct'] !== null) {
                $rows[] = ['title' => $stat['title'], 'difficulty' => round($stat['difficulty'], 1), 'avg_pct' => round($stat['avg_pct'], 1)];
            }
        }
        usort($rows, static fn ($a, $b) => $b['difficulty'] <=> $a['difficulty']);
        return $rows;
    };

    // Grade component loss: avg points lost per component = weight × (1 − avgPct/100).
    $componentLoss = [];
    foreach (grading_weight_components() as $key => $label) {
        $vals = [];
        foreach ($scopedMetrics as $m) {
            if ($m['components'][$key]['has_data']) {
                $vals[] = $m['components'][$key]['pct'];
            }
        }
        $avgPct = $vals ? array_sum($vals) / count($vals) : null;
        $componentLoss[] = [
            'component' => $label,
            'avg_loss' => $avgPct !== null ? round((float) $weights[$key] * (1 - $avgPct / 100), 2) : null,
            'weight' => (float) $weights[$key],
        ];
    }

    // Missing assessments per student.
    $missing = [];
    foreach ($scopedMetrics as $m) {
        if ($m['missing_count'] > 0) {
            $missing[] = ['name' => $m['student_name'], 'id' => $m['id'], 'count' => $m['missing_count']];
        }
    }
    usort($missing, static fn ($a, $b) => $b['count'] <=> $a['count']);

    // Consistency (lower volatility = more consistent).
    $consistency = [];
    foreach ($scopedMetrics as $m) {
        if (count($m['series']) >= 2) {
            $consistency[] = ['name' => $m['student_name'], 'id' => $m['id'], 'volatility' => round($m['volatility'], 1), 'grade' => $m['current_grade']];
        }
    }
    usort($consistency, static fn ($a, $b) => $b['volatility'] <=> $a['volatility']);

    return [
        'attendance_vs_grade' => $attVsGrade,
        'participation_vs_grade' => $partVsGrade,
        'quiz_difficulty' => $difficulty('quiz'),
        'activity_difficulty' => $difficulty('activity'),
        'component_loss' => $componentLoss,
        'missing_assessments' => $missing,
        'consistency' => $consistency,
    ];
}

function insights_avg_of(array $vals): ?float
{
    $clean = array_values(array_filter($vals, static fn ($v) => $v !== null));
    return $clean ? round(array_sum($clean) / count($clean), 1) : null;
}

/**
 * Per-group average current grade, for the chosen grouping (a class_grouping_groups.id
 * within it) or the most recent class grouping. Returns groups sorted weakest-first.
 */
function get_group_performance(PDO $pdo, int $classId, array $metrics, int $focusGroupId = 0): array
{
    $classGroupings = get_class_groupings($pdo, $classId, 'class');
    if (empty($classGroupings)) {
        return ['grouping_name' => null, 'groups' => []];
    }

    // If a specific group was chosen, find which grouping it belongs to; else newest.
    $groupingId = (int) $classGroupings[0]['id'];
    if ($focusGroupId > 0) {
        $owner = $pdo->prepare('SELECT grouping_id FROM class_grouping_groups WHERE id = :id LIMIT 1');
        $owner->execute([':id' => $focusGroupId]);
        $found = $owner->fetchColumn();
        if ($found !== false) {
            $groupingId = (int) $found;
        }
    }

    $groups = get_grouping_with_groups($pdo, $groupingId);
    $groupingName = '';
    foreach ($classGroupings as $g) {
        if ((int) $g['id'] === $groupingId) {
            $groupingName = (string) $g['name'];
        }
    }

    $rows = [];
    foreach ($groups as $group) {
        $vals = [];
        foreach ($group['members'] as $member) {
            $sid = (int) $member['student_id'];
            if (isset($metrics[$sid]) && $metrics[$sid]['current_grade'] !== null) {
                $vals[] = $metrics[$sid]['current_grade'];
            }
        }
        $rows[] = [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'member_count' => count($group['members']),
            'avg_grade' => $vals ? round(array_sum($vals) / count($vals), 1) : null,
        ];
    }
    usort($rows, static fn ($a, $b) => ($a['avg_grade'] ?? 999) <=> ($b['avg_grade'] ?? 999));

    return ['grouping_id' => $groupingId, 'grouping_name' => $groupingName, 'groups' => $rows];
}

/* ------------------------------------------------------------------ *
 * Prescriptive recommendations
 * ------------------------------------------------------------------ */

/** Personalized recommendations for one student bundle. */
function generate_student_recommendations(array $bundle, array $weights): array
{
    $recs = [];
    $passing = (float) $weights['passing_grade'];

    if (!empty($bundle['drop_warning'])) {
        $recs[] = [
            'icon' => 'bi-exclamation-octagon',
            'severity' => 'high',
            'title' => 'Needs immediate attention: ' . (int) $bundle['unexcused_absences'] . ' unexcused absences',
            'text' => 'Three unexcused absences are subject for dropping review. This is a warning only; removal must be done manually by the instructor.',
        ];
    } elseif (!empty($bundle['absence_warning'])) {
        $recs[] = [
            'icon' => 'bi-exclamation-triangle',
            'severity' => 'medium',
            'title' => 'Attendance warning: 2 unexcused absences',
            'text' => 'Two unexcused absences is an early warning. Three unexcused absences are subject for dropping review.',
        ];
    }

    if ($bundle['missing_count'] > 0) {
        $recs[] = [
            'icon' => 'bi-clipboard-x',
            'severity' => 'high',
            'title' => 'Submit ' . $bundle['missing_count'] . ' missing assessment' . ($bundle['missing_count'] === 1 ? '' : 's'),
            'text' => 'Past-due items with no recorded score are pulling the grade down the most. Completing them is the fastest recovery.',
        ];
    }

    if ($bundle['attendance_rate'] !== null && $bundle['attendance_rate'] < 80) {
        $recs[] = [
            'icon' => 'bi-calendar-x',
            'severity' => $bundle['attendance_rate'] < 60 ? 'high' : 'medium',
            'title' => 'Improve attendance (currently ' . round($bundle['attendance_rate']) . '%)',
            'text' => 'Attendance contributes ' . rtrim(rtrim(number_format((float) $weights['attendance'], 1), '0'), '.') . '% of the final grade and correlates with performance across the class.',
        ];
    }

    if ($bundle['predicted_final'] !== null && $bundle['predicted_final'] < $passing) {
        $goal = compute_goal_analysis($bundle, $passing);
        if ($goal['required_avg'] !== null && $goal['status'] === 'on_track') {
            $recs[] = [
                'icon' => 'bi-graph-up-arrow',
                'severity' => 'high',
                'title' => 'Average ' . round($goal['required_avg']) . '% on remaining work to reach passing',
                'text' => 'Projected final is ' . round($bundle['predicted_final']) . '%. Focus remaining effort on the components below to clear ' . round($passing) . '%.',
            ];
        } elseif ($goal['status'] === 'infeasible') {
            $recs[] = [
                'icon' => 'bi-exclamation-octagon',
                'severity' => 'high',
                'title' => 'Passing is no longer mathematically reachable',
                'text' => 'Even perfect scores on all remaining work fall short of ' . round($passing) . '%. Escalate for an intervention or remediation plan.',
            ];
        }
    }

    if ($bundle['trend'] === 'declining') {
        $recs[] = [
            'icon' => 'bi-arrow-down-right',
            'severity' => 'medium',
            'title' => 'Declining performance trend',
            'text' => 'Recent scores are trending downward. A review session or consultation now can reverse it before major exams.',
        ];
    }

    if ($bundle['risk_level'] === 'high') {
        $recs[] = [
            'icon' => 'bi-person-raised-hand',
            'severity' => 'high',
            'title' => 'Book a consultation',
            'text' => 'This student is flagged high-risk. A one-on-one check-in is recommended to identify blockers early.',
        ];
    }

    // Weakest graded component -> targeted review.
    $weakest = null;
    foreach ($bundle['components'] as $key => $c) {
        if ($c['has_data'] && $c['weight'] > 0) {
            if ($weakest === null || $c['pct'] < $weakest['pct']) {
                $weakest = ['key' => $key, 'pct' => $c['pct']];
            }
        }
    }
    if ($weakest !== null && $weakest['pct'] < 70) {
        $labels = grading_weight_components();
        $recs[] = [
            'icon' => 'bi-book',
            'severity' => 'medium',
            'title' => 'Review ' . strtolower($labels[$weakest['key']] ?? $weakest['key']) . ' (currently ' . round($weakest['pct']) . '%)',
            'text' => 'This is the student\'s weakest graded component. Targeted practice here yields the biggest grade improvement per hour.',
        ];
    }

    if (empty($recs)) {
        $recs[] = [
            'icon' => 'bi-check-circle',
            'severity' => 'low',
            'title' => 'On track — keep it up',
            'text' => 'No risk signals detected. Maintain current attendance and submission consistency.',
        ];
    }

    return $recs;
}

/** Assessment-level insights: difficult quizzes/activities and low-performing topics. */
function generate_assessment_recommendations(array $dataset): array
{
    $collect = function (string $type) use ($dataset) {
        $rows = [];
        foreach ($dataset['items_by_type'][$type] ?? [] as $item) {
            if (!is_assessment_item_gradeable($item)) {
                continue;
            }
            $stat = insights_item_class_stats($dataset, $item);
            if ($stat['avg_pct'] !== null) {
                $rows[] = $stat;
            }
        }
        usort($rows, static fn ($a, $b) => $a['avg_pct'] <=> $b['avg_pct']);
        return $rows;
    };

    $quizzes = $collect('quiz');
    $activities = $collect('activity');

    // Low-performing "topics" = any gradeable item under 65% class avg, across all types.
    $lowTopics = [];
    foreach ($dataset['items_by_type'] as $items) {
        foreach ($items as $item) {
            if (!is_assessment_item_gradeable($item)) {
                continue;
            }
            $stat = insights_item_class_stats($dataset, $item);
            if ($stat['avg_pct'] !== null && $stat['avg_pct'] < 65) {
                $lowTopics[] = $stat;
            }
        }
    }
    usort($lowTopics, static fn ($a, $b) => $a['avg_pct'] <=> $b['avg_pct']);

    return [
        'difficult_quizzes' => array_slice(array_filter($quizzes, static fn ($q) => $q['avg_pct'] < 75), 0, 5),
        'difficult_activities' => array_slice(array_filter($activities, static fn ($a) => $a['avg_pct'] < 75), 0, 5),
        'low_topics' => array_slice($lowTopics, 0, 8),
    ];
}

/** Group-level prescriptions: weak groups, regrouping, peer mentoring pairs. */
function generate_group_recommendations(PDO $pdo, int $classId, array $metrics): array
{
    $perf = get_group_performance($pdo, $classId, $metrics);
    $groups = $perf['groups'];
    $graded = array_values(array_filter($groups, static fn ($g) => $g['avg_grade'] !== null));

    $classAvg = null;
    if ($graded) {
        $classAvg = array_sum(array_column($graded, 'avg_grade')) / count($graded);
    }

    $weakGroups = [];
    foreach ($graded as $g) {
        if ($classAvg !== null && $g['avg_grade'] < $classAvg - 5) {
            $weakGroups[] = $g;
        }
    }

    // Peer mentoring: pair the strongest and weakest students by blended performance.
    $performanceMap = get_student_performance_map($pdo, $classId);
    $ranked = [];
    foreach ($metrics as $sid => $m) {
        $ranked[] = ['id' => $sid, 'name' => $m['student_name'], 'score' => $performanceMap[$sid] ?? ($m['current_grade'] !== null ? $m['current_grade'] / 100 : 0.5)];
    }
    usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);
    $pairs = [];
    $n = count($ranked);
    $pairCount = min(3, intdiv($n, 2));
    for ($i = 0; $i < $pairCount; $i++) {
        $mentor = $ranked[$i];
        $mentee = $ranked[$n - 1 - $i];
        if ($mentor['id'] === $mentee['id']) {
            break;
        }
        $pairs[] = ['mentor' => $mentor['name'], 'mentee' => $mentee['name']];
    }

    return [
        'grouping_name' => $perf['grouping_name'],
        'weak_groups' => $weakGroups,
        'class_avg' => $classAvg !== null ? round($classAvg, 1) : null,
        'regroup_suggested' => count($weakGroups) > 0,
        'mentoring_pairs' => $pairs,
    ];
}

/** Teaching-level prescriptions: topics to review, practice needs, intervention priorities. */
function generate_instructor_recommendations(PDO $pdo, int $classId, array $dataset, array $metrics, array $weights): array
{
    $assessment = generate_assessment_recommendations($dataset);

    // Topics needing review = lowest-scoring items (already computed as low_topics).
    $reviewTopics = array_slice($assessment['low_topics'], 0, 5);

    // Quiz practice signal: overall quiz average low.
    $quizAvgVals = [];
    foreach ($dataset['items_by_type']['quiz'] ?? [] as $item) {
        if (!is_assessment_item_gradeable($item)) {
            continue;
        }
        $stat = insights_item_class_stats($dataset, $item);
        if ($stat['avg_pct'] !== null) {
            $quizAvgVals[] = $stat['avg_pct'];
        }
    }
    $quizAvg = $quizAvgVals ? array_sum($quizAvgVals) / count($quizAvgVals) : null;

    // Intervention priorities = at-risk students ranked by risk score.
    $priorities = [];
    foreach ($metrics as $m) {
        if ($m['at_risk']) {
            $priorities[] = [
                'id' => $m['id'],
                'name' => $m['student_name'],
                'risk_score' => round($m['risk_score']),
                'risk_level' => $m['risk_level'],
                'predicted_final' => $m['predicted_final'],
                'reason' => insights_risk_reason($m),
            ];
        }
    }
    usort($priorities, static fn ($a, $b) => $b['risk_score'] <=> $a['risk_score']);

    return [
        'review_topics' => $reviewTopics,
        'quiz_practice_needed' => $quizAvg !== null && $quizAvg < 70,
        'quiz_avg' => $quizAvg !== null ? round($quizAvg, 1) : null,
        'intervention_priorities' => $priorities,
        'immediate_attention' => array_slice(array_filter($priorities, static fn ($p) => $p['risk_level'] === 'high'), 0, 8),
    ];
}

/** One-line human explanation of why a student is flagged. */
function insights_risk_reason(array $bundle): string
{
    $reasons = [];
    if ($bundle['predicted_final'] !== null && $bundle['predicted_final'] < $bundle['passing_grade']) {
        $reasons[] = 'projected below passing';
    }
    if ($bundle['attendance_rate'] !== null && $bundle['attendance_rate'] < 70) {
        $reasons[] = 'low attendance';
    }
    if (!empty($bundle['drop_warning'])) {
        $reasons[] = (int) $bundle['unexcused_absences'] . ' unexcused absences';
    } elseif (!empty($bundle['absence_warning'])) {
        $reasons[] = '2 unexcused absences';
    }
    if ($bundle['missing_count'] > 0) {
        $reasons[] = $bundle['missing_count'] . ' missing';
    }
    if ($bundle['trend'] === 'declining') {
        $reasons[] = 'declining trend';
    }
    return $reasons ? implode(', ', $reasons) : 'multiple weak signals';
}

/* ------------------------------------------------------------------ *
 * Instructor home dashboard — aggregates across ALL active classes
 * ------------------------------------------------------------------ */

/**
 * Cross-class rollup for the instructor's main dashboard: summary cards,
 * chart series, and quick-insight lists, all computed live from the same engine
 * the per-class Insights Dashboard uses.
 */
function get_instructor_dashboard(PDO $pdo, int $instructorId): array
{
    ensure_insights_schema($pdo);
    $today = date('Y-m-d');

    $classesStmt = $pdo->prepare('SELECT id, class_name, section FROM classes WHERE instructor_id = :i AND status = "active" ORDER BY class_name ASC');
    $classesStmt->execute([':i' => $instructorId]);
    $classes = $classesStmt->fetchAll();

    $allMetrics = [];
    $studentIds = [];
    $totalAtRisk = 0;
    $totalMissingStudents = 0;
    $totalAbsenceWarnings = 0;
    $totalDropWarnings = 0;
    $perClass = [];        // one summary row per class (for routing cards)
    $totalPending = 0;
    $totalUpcomingAssess = 0;
    $attWeek = [];         // week => [attended, taken]
    $completionAgg = [];   // component label => [sum, count]

    foreach ($classes as $cls) {
        $classId = (int) $cls['id'];
        $label = trim((string) $cls['class_name'] . ' ' . (string) ($cls['section'] ?? ''));
        $weights = get_class_grading_weights($pdo, $classId);
        $dataset = build_class_insights_dataset($pdo, $classId);
        $metrics = get_all_student_metrics($pdo, $classId, $dataset, $weights);
        $summary = get_class_dashboard_summary($pdo, $classId, $dataset, $metrics, $weights);

        $totalPending += (int) $summary['pending_grading'];
        $totalUpcomingAssess += (int) $summary['upcoming_assessments'];

        // Per-class rollup (summary + at-risk count only — never student names).
        $classGradeVals = [];
        $classAttVals = [];
        $classAtRisk = 0;
        $classMissing = 0;
        $classAbsenceWarnings = 0;
        $classDropWarnings = 0;
        foreach ($metrics as $sid => $m) {
            $studentIds[(int) $sid] = true;
            $allMetrics[] = $m;
            if ($m['current_grade'] !== null) {
                $classGradeVals[] = $m['current_grade'];
            }
            if ($m['attendance_rate'] !== null) {
                $classAttVals[] = $m['attendance_rate'];
            }
            if ($m['at_risk']) {
                $classAtRisk++;
                $totalAtRisk++;
            }
            if ($m['missing_count'] > 0) {
                $classMissing++;
                $totalMissingStudents++;
            }
            if (!empty($m['absence_warning'])) {
                $classAbsenceWarnings++;
                $totalAbsenceWarnings++;
            }
            if (!empty($m['drop_warning'])) {
                $classDropWarnings++;
                $totalDropWarnings++;
            }
        }

        $perClass[] = [
            'id' => $classId,
            'label' => $label !== '' ? $label : (string) $cls['class_name'],
            'student_count' => count($metrics),
            'class_average' => $classGradeVals ? array_sum($classGradeVals) / count($classGradeVals) : null,
            'attendance_rate' => $classAttVals ? array_sum($classAttVals) / count($classAttVals) : null,
            'at_risk' => $classAtRisk,
            'missing_students' => $classMissing,
            'absence_warnings' => $classAbsenceWarnings,
            'drop_warnings' => $classDropWarnings,
            'pending' => (int) $summary['pending_grading'],
            'upcoming' => (int) $summary['upcoming_assessments'],
        ];

        foreach (insights_meetings_by_week($dataset['meetings']) as $week => $meetings) {
            foreach ($meetings as $mtg) {
                if ((string) $mtg['status'] !== 'regular') {
                    continue;
                }
                foreach ($dataset['att_matrix'] as $perMeeting) {
                    $st = $perMeeting[(int) $mtg['id']] ?? null;
                    if ($st === null) {
                        continue;
                    }
                    $attWeek[$week][1] = ($attWeek[$week][1] ?? 0) + 1;
                    if (in_array($st, ['present', 'late', 'excused'], true)) {
                        $attWeek[$week][0] = ($attWeek[$week][0] ?? 0) + 1;
                    }
                }
            }
        }

        $comp = get_completion_progress($dataset);
        foreach ($comp['labels'] as $i => $lab) {
            $completionAgg[$lab][0] = ($completionAgg[$lab][0] ?? 0) + $comp['values'][$i];
            $completionAgg[$lab][1] = ($completionAgg[$lab][1] ?? 0) + 1;
        }
    }

    $avgOf = static function (string $key) use ($allMetrics): ?float {
        $vals = [];
        foreach ($allMetrics as $m) {
            if ($m[$key] !== null) {
                $vals[] = $m[$key];
            }
        }
        return $vals ? array_sum($vals) / count($vals) : null;
    };

    ksort($attWeek);
    $attLabels = [];
    $attData = [];
    foreach ($attWeek as $week => $pair) {
        if (($pair[1] ?? 0) > 0) {
            $attLabels[] = 'Wk ' . $week;
            $attData[] = round(($pair[0] ?? 0) / $pair[1] * 100, 1);
        }
    }

    $compLabels = [];
    $compData = [];
    foreach ($completionAgg as $lab => $pair) {
        $compLabels[] = $lab;
        $compData[] = ($pair[1] ?? 0) > 0 ? round($pair[0] / $pair[1], 1) : 0;
    }

    // Order class cards: those needing attention (at-risk, then pending) first.
    usort($perClass, static function ($a, $b) {
        return [$b['at_risk'], $b['pending']] <=> [$a['at_risk'], $a['pending']];
    });

    // Recently graded assessments across all the instructor's classes.
    $recent = $pdo->prepare(
        'SELECT cai.title, cai.type, c.class_name, c.section, COUNT(*) AS n,
                MAX(COALESCE(cas.updated_at, cas.saved_at)) AS graded_at
         FROM class_assessment_scores cas
         INNER JOIN class_assessment_items cai ON cai.id = cas.item_id
         INNER JOIN classes c ON c.id = cai.class_id
         WHERE c.instructor_id = :i
         GROUP BY cas.item_id
         ORDER BY graded_at DESC
         LIMIT 8'
    );
    $recent->execute([':i' => $instructorId]);
    $recentlyGraded = $recent->fetchAll();

    // Upcoming assessment deadlines.
    $deadlines = $pdo->prepare(
        'SELECT cai.title, cai.type, cai.scheduled_date, c.class_name, c.section
         FROM class_assessment_items cai
         INNER JOIN classes c ON c.id = cai.class_id
         WHERE c.instructor_id = :i AND cai.scheduled_date IS NOT NULL AND cai.scheduled_date >= :today
         ORDER BY cai.scheduled_date ASC
         LIMIT 8'
    );
    $deadlines->execute([':i' => $instructorId, ':today' => $today]);
    $upcomingDeadlines = $deadlines->fetchAll();

    // Upcoming meetings across classes.
    $mtgs = $pdo->prepare(
        'SELECT cm.meeting_date, cm.week_number, cm.status, c.class_name, c.section
         FROM class_meetings cm
         INNER JOIN classes c ON c.id = cm.class_id
         WHERE c.instructor_id = :i AND cm.meeting_date >= :today AND cm.status <> "cancelled"
         ORDER BY cm.meeting_date ASC
         LIMIT 8'
    );
    $mtgs->execute([':i' => $instructorId, ':today' => $today]);
    $upcomingMeetings = $mtgs->fetchAll();

    return [
        'class_count' => count($classes),
        'student_count' => count($studentIds),
        'class_average' => $avgOf('current_grade'),
        'attendance_rate' => $avgOf('attendance_rate'),
        'participation_rate' => $avgOf('participation_rate'),
        'pending_grading' => $totalPending,
        'upcoming_assessments' => $totalUpcomingAssess,
        'at_risk_count' => $totalAtRisk,
        'missing_students' => $totalMissingStudents,
        'absence_warnings' => $totalAbsenceWarnings,
        'drop_warnings' => $totalDropWarnings,
        'attendance_trend' => ['labels' => $attLabels, 'values' => $attData],
        'grade_distribution' => get_grade_distribution($allMetrics),
        'completion_progress' => ['labels' => $compLabels, 'values' => $compData],
        'classes' => $perClass,
        'recently_graded' => $recentlyGraded,
        'upcoming_deadlines' => $upcomingDeadlines,
        'upcoming_meetings' => $upcomingMeetings,
    ];
}

/**
 * Every enrolled student across the instructor's active classes, one row per
 * (student × class), with quick performance metrics + account status. Backs the
 * cross-class Students page (search/filter/sort happen in the page).
 */
function get_instructor_students_overview(PDO $pdo, int $instructorId): array
{
    ensure_insights_schema($pdo);

    $classesStmt = $pdo->prepare('SELECT id, class_name, section FROM classes WHERE instructor_id = :i AND status = "active" ORDER BY class_name ASC');
    $classesStmt->execute([':i' => $instructorId]);
    $classes = $classesStmt->fetchAll();

    // Account status (users.status) for each enrolled student, one query.
    $statusStmt = $pdo->prepare(
        'SELECT s.id, u.status
         FROM students s
         INNER JOIN users u ON u.id = s.user_id
         INNER JOIN class_enrollments ce ON ce.student_id = s.id
         INNER JOIN classes c ON c.id = ce.class_id
         WHERE c.instructor_id = :i AND ce.status = "active"
         GROUP BY s.id, u.status'
    );
    $statusStmt->execute([':i' => $instructorId]);
    $statusMap = [];
    foreach ($statusStmt->fetchAll() as $r) {
        $statusMap[(int) $r['id']] = (string) $r['status'];
    }

    $rows = [];
    $classList = [];
    $distinct = [];
    $atRisk = 0;
    $absenceWarnings = 0;
    $dropWarnings = 0;

    foreach ($classes as $cls) {
        $classId = (int) $cls['id'];
        $label = trim((string) $cls['class_name'] . ' ' . (string) ($cls['section'] ?? ''));
        $label = $label !== '' ? $label : (string) $cls['class_name'];
        $classList[] = ['id' => $classId, 'label' => $label];

        $weights = get_class_grading_weights($pdo, $classId);
        $dataset = build_class_insights_dataset($pdo, $classId);
        $metrics = get_all_student_metrics($pdo, $classId, $dataset, $weights);

        foreach ($metrics as $sid => $m) {
            $distinct[(int) $sid] = true;
            if ($m['at_risk']) {
                $atRisk++;
            }
            if (!empty($m['absence_warning'])) {
                $absenceWarnings++;
            }
            if (!empty($m['drop_warning'])) {
                $dropWarnings++;
            }
            $rows[] = [
                'student_id' => $m['id'],
                'student_no' => $m['student_no'],
                'student_name' => $m['student_name'],
                'account_status' => $statusMap[(int) $sid] ?? 'active',
                'class_id' => $classId,
                'class_label' => $label,
                'current_grade' => $m['current_grade'],
                'attendance_rate' => $m['attendance_rate'],
                'unexcused_absences' => $m['unexcused_absences'],
                'absence_warning' => $m['absence_warning'],
                'drop_warning' => $m['drop_warning'],
                'participation_rate' => $m['participation_rate'],
                'predicted_final' => $m['predicted_final'],
                'risk_level' => $m['risk_level'],
                'at_risk' => $m['at_risk'],
                'missing_count' => $m['missing_count'],
            ];
        }
    }

    return [
        'rows' => $rows,
        'classes' => $classList,
        'total_rows' => count($rows),
        'distinct_students' => count($distinct),
        'at_risk' => $atRisk,
        'absence_warnings' => $absenceWarnings,
        'drop_warnings' => $dropWarnings,
    ];
}

/**
 * Every assessment item across the instructor's active classes with grading
 * progress + status flags. Backs the cross-class Assessments page (Pending
 * Grading / Upcoming Assessments cards route here).
 */
function get_instructor_assessments_overview(PDO $pdo, int $instructorId): array
{
    $today = date('Y-m-d');
    $types = assessment_types();

    $stmt = $pdo->prepare(
        'SELECT cai.id, cai.title, cai.type, cai.position, cai.max_score, cai.scheduled_date,
                cai.activity_mode, cai.grouping_id, c.id AS class_id, c.class_name, c.section,
                (SELECT COUNT(*) FROM class_assessment_scores cas WHERE cas.item_id = cai.id) AS graded_count,
                (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = c.id AND ce.status = "active") AS enrolled
         FROM class_assessment_items cai
         INNER JOIN classes c ON c.id = cai.class_id
         WHERE c.instructor_id = :i AND c.status = "active"
         ORDER BY (cai.scheduled_date IS NULL) ASC, cai.scheduled_date ASC, c.class_name ASC, cai.position ASC'
    );
    $stmt->execute([':i' => $instructorId]);
    $items = $stmt->fetchAll();

    $rows = [];
    $classList = [];
    $classSeen = [];
    $counts = ['pending' => 0, 'upcoming' => 0, 'graded' => 0, 'setup' => 0];

    foreach ($items as $it) {
        $classId = (int) $it['class_id'];
        $label = trim((string) $it['class_name'] . ' ' . (string) ($it['section'] ?? ''));
        $label = $label !== '' ? $label : (string) $it['class_name'];
        if (!isset($classSeen[$classId])) {
            $classSeen[$classId] = true;
            $classList[] = ['id' => $classId, 'label' => $label];
        }

        $gradeable = is_assessment_item_gradeable($it);
        $graded = (int) $it['graded_count'];
        $enrolled = (int) $it['enrolled'];
        $needsGrading = $gradeable && ($enrolled === 0 || $graded < $enrolled);
        $fullyGraded = $gradeable && $enrolled > 0 && $graded >= $enrolled;
        $isUpcoming = !empty($it['scheduled_date']) && (string) $it['scheduled_date'] >= $today;

        if (!$gradeable) {
            $status = 'setup';
        } elseif ($fullyGraded) {
            $status = 'graded';
        } else {
            $status = 'pending';
        }
        $counts[$status]++;
        if ($isUpcoming) {
            $counts['upcoming']++;
        }

        $rows[] = [
            'id' => (int) $it['id'],
            'title' => (string) $it['title'],
            'type' => (string) $it['type'],
            'type_label' => $types[(string) $it['type']]['label'] ?? ucfirst((string) $it['type']),
            'type_view' => $types[(string) $it['type']]['view'] ?? (string) $it['type'],
            'class_id' => $classId,
            'class_label' => $label,
            'max_score' => (float) $it['max_score'],
            'scheduled_date' => (string) ($it['scheduled_date'] ?? ''),
            'graded_count' => $graded,
            'enrolled' => $enrolled,
            'gradeable' => $gradeable,
            'needs_grading' => $needsGrading,
            'fully_graded' => $fullyGraded,
            'is_upcoming' => $isUpcoming,
            'status' => $status,
        ];
    }

    return ['rows' => $rows, 'classes' => $classList, 'counts' => $counts, 'total_rows' => count($rows)];
}

/* ------------------------------------------------------------------ *
 * Student home dashboard — the learner's own cross-class rollup
 * ------------------------------------------------------------------ */

/**
 * A student's personalized rollup across their active classes: per-class standing
 * (grade, attendance, predicted final, risk), overall summary, warnings, recent
 * grades, and upcoming meetings/deadlines. Backs the student dashboard + Warnings.
 */
function get_student_dashboard(PDO $pdo, int $studentId): array
{
    ensure_insights_schema($pdo);
    $today = date('Y-m-d');

    $classesStmt = $pdo->prepare(
        'SELECT c.id, c.class_name, c.section, c.school_year, c.term, CONCAT(i.first_name, " ", i.last_name) AS instructor
         FROM class_enrollments ce
         INNER JOIN classes c ON c.id = ce.class_id
         LEFT JOIN instructors i ON i.id = c.instructor_id
         WHERE ce.student_id = :s AND ce.status = "active" AND c.status = "active"
         ORDER BY c.class_name ASC'
    );
    $classesStmt->execute([':s' => $studentId]);
    $classes = $classesStmt->fetchAll();

    $perClass = [];
    $warnings = [];
    $gradeVals = [];
    $attVals = [];
    $atRiskClasses = 0;

    foreach ($classes as $cls) {
        $classId = (int) $cls['id'];
        $label = trim((string) $cls['class_name'] . ' ' . (string) ($cls['section'] ?? ''));
        $label = $label !== '' ? $label : (string) $cls['class_name'];
        $bundle = get_student_metrics($pdo, $classId, $studentId);
        if ($bundle === null) {
            continue;
        }

        if ($bundle['current_grade'] !== null) {
            $gradeVals[] = $bundle['current_grade'];
        }
        if ($bundle['attendance_rate'] !== null) {
            $attVals[] = $bundle['attendance_rate'];
        }
        if ($bundle['at_risk']) {
            $atRiskClasses++;
        }

        $perClass[] = [
            'id' => $classId,
            'label' => $label,
            'instructor' => (string) ($cls['instructor'] ?: 'Instructor'),
            'school_year' => (string) ($cls['school_year'] ?? ''),
            'term' => (string) ($cls['term'] ?? ''),
            'current_grade' => $bundle['current_grade'],
            'attendance_rate' => $bundle['attendance_rate'],
            'participation_rate' => $bundle['participation_rate'],
            'predicted_final' => $bundle['predicted_final'],
            'passing_grade' => $bundle['passing_grade'],
            'risk_level' => $bundle['risk_level'],
            'at_risk' => $bundle['at_risk'],
            'missing_count' => $bundle['missing_count'],
            'trend' => $bundle['trend'],
        ];

        // Per-class warnings (most actionable first).
        $insightsBase = url_for('pages/student/class-insights.php') . '?class_id=' . $classId . '&tab=';
        if ($bundle['predicted_final'] !== null && $bundle['predicted_final'] < $bundle['passing_grade']) {
            $warnings[] = [
                'severity' => 'high', 'icon' => 'bi-exclamation-octagon', 'class_id' => $classId, 'class_label' => $label,
                'title' => 'At risk of not passing ' . $label,
                'text' => 'Your projected final is ' . round($bundle['predicted_final']) . '% (passing is ' . round($bundle['passing_grade']) . '%). Review what you need on the Goal tab.',
                'link' => $insightsBase . 'goal',
            ];
        }
        if (!empty($bundle['drop_warning'])) {
            $warnings[] = [
                'severity' => 'high', 'icon' => 'bi-exclamation-octagon', 'class_id' => $classId, 'class_label' => $label,
                'title' => 'Immediate attendance warning in ' . $label,
                'text' => 'You have ' . (int) $bundle['unexcused_absences'] . ' unexcused absences. Three unexcused absences are subject for dropping review, but dropping is not automatic.',
                'link' => $insightsBase . 'attendance',
            ];
        } elseif (!empty($bundle['absence_warning'])) {
            $warnings[] = [
                'severity' => 'medium', 'icon' => 'bi-exclamation-triangle', 'class_id' => $classId, 'class_label' => $label,
                'title' => 'Attendance warning in ' . $label,
                'text' => 'You have 2 unexcused absences. Three unexcused absences are subject for dropping review.',
                'link' => $insightsBase . 'attendance',
            ];
        }
        if ($bundle['attendance_rate'] !== null && $bundle['attendance_rate'] < 75) {
            $warnings[] = [
                'severity' => $bundle['attendance_rate'] < 60 ? 'high' : 'medium', 'icon' => 'bi-calendar-x', 'class_id' => $classId, 'class_label' => $label,
                'title' => 'Low attendance in ' . $label,
                'text' => 'You are at ' . round($bundle['attendance_rate']) . '% attendance. Attendance affects your grade and standing.',
                'link' => $insightsBase . 'attendance',
            ];
        }
        if ($bundle['missing_count'] > 0) {
            $warnings[] = [
                'severity' => 'medium', 'icon' => 'bi-clipboard-x', 'class_id' => $classId, 'class_label' => $label,
                'title' => $bundle['missing_count'] . ' missing assessment' . ($bundle['missing_count'] === 1 ? '' : 's') . ' in ' . $label,
                'text' => 'Past-due work with no recorded score is pulling your grade down. Submit or follow up with your instructor.',
                'link' => $insightsBase . 'grades',
            ];
        }
        if ($bundle['trend'] === 'declining') {
            $warnings[] = [
                'severity' => 'low', 'icon' => 'bi-arrow-down-right', 'class_id' => $classId, 'class_label' => $label,
                'title' => 'Grades trending down in ' . $label,
                'text' => 'Your recent scores are sliding. A review or consultation now can turn it around.',
                'link' => $insightsBase . 'predictions',
            ];
        }
    }

    $sevRank = ['high' => 3, 'medium' => 2, 'low' => 1];
    usort($warnings, static fn ($a, $b) => ($sevRank[$b['severity']] ?? 0) <=> ($sevRank[$a['severity']] ?? 0));

    // Recent grades for this student.
    $recentStmt = $pdo->prepare(
        'SELECT cai.title, cai.type, cai.max_score, cas.score, c.class_name, c.section,
                COALESCE(cas.updated_at, cas.saved_at) AS graded_at
         FROM class_assessment_scores cas
         INNER JOIN class_assessment_items cai ON cai.id = cas.item_id
         INNER JOIN classes c ON c.id = cai.class_id
         INNER JOIN class_enrollments ce ON ce.class_id = c.id AND ce.student_id = cas.student_id AND ce.status = "active"
         WHERE cas.student_id = :s
         ORDER BY graded_at DESC
         LIMIT 6'
    );
    $recentStmt->execute([':s' => $studentId]);
    $recentGrades = $recentStmt->fetchAll();

    // Upcoming meetings across the student's classes.
    $mtgStmt = $pdo->prepare(
        'SELECT cm.meeting_date, cm.week_number, cm.status, c.class_name, c.section
         FROM class_meetings cm
         INNER JOIN classes c ON c.id = cm.class_id
         INNER JOIN class_enrollments ce ON ce.class_id = c.id AND ce.student_id = :s AND ce.status = "active"
         WHERE cm.meeting_date >= :today AND cm.status <> "cancelled"
         ORDER BY cm.meeting_date ASC
         LIMIT 6'
    );
    $mtgStmt->execute([':s' => $studentId, ':today' => $today]);
    $upcomingMeetings = $mtgStmt->fetchAll();

    // Upcoming assessment deadlines across the student's classes.
    $dlStmt = $pdo->prepare(
        'SELECT cai.title, cai.type, cai.scheduled_date, c.class_name, c.section
         FROM class_assessment_items cai
         INNER JOIN classes c ON c.id = cai.class_id
         INNER JOIN class_enrollments ce ON ce.class_id = c.id AND ce.student_id = :s AND ce.status = "active"
         WHERE cai.scheduled_date IS NOT NULL AND cai.scheduled_date >= :today
         ORDER BY cai.scheduled_date ASC
         LIMIT 6'
    );
    $dlStmt->execute([':s' => $studentId, ':today' => $today]);
    $upcomingDeadlines = $dlStmt->fetchAll();

    return [
        'class_count' => count($perClass),
        'overall_average' => $gradeVals ? array_sum($gradeVals) / count($gradeVals) : null,
        'overall_attendance' => $attVals ? array_sum($attVals) / count($attVals) : null,
        'at_risk_classes' => $atRiskClasses,
        'warning_count' => count($warnings),
        'classes' => $perClass,
        'warnings' => $warnings,
        'recent_grades' => $recentGrades,
        'upcoming_meetings' => $upcomingMeetings,
        'upcoming_deadlines' => $upcomingDeadlines,
    ];
}
