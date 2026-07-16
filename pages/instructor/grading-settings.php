<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';
require_once __DIR__ . '/../../includes/insights_management.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

ensure_insights_schema($pdo);

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);
$components = grading_weight_components();

// Resolve the class being edited: ?class_id=, else first active class.
$requestedClassId = (int) ($_GET['class_id'] ?? 0);
$selectedClassId = 0;
foreach ($sidebarClasses as $class) {
    if ($requestedClassId === (int) $class['id']) {
        $selectedClassId = (int) $class['id'];
        break;
    }
}
if ($selectedClassId === 0 && !empty($sidebarClasses)) {
    $selectedClassId = (int) $sidebarClasses[0]['id'];
}

$errors = [];
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['grading_action'] ?? '') === 'save_weights') {
    $postClassId = (int) ($_POST['class_id'] ?? 0);
    $ownsClass = false;
    foreach ($sidebarClasses as $class) {
        if ((int) $class['id'] === $postClassId) {
            $ownsClass = true;
            break;
        }
    }

    if (!csrf_is_valid('csrf_grading_settings_token', (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$ownsClass) {
        $errors[] = 'Select one of your classes before saving.';
    } else {
        [$clean, $fieldErrors] = validate_grading_weights($_POST);
        if (empty($fieldErrors)) {
            save_class_grading_weights($pdo, $postClassId, $clean);
            rotate_csrf_token('csrf_grading_settings_token');
            $savedLabel = 'class';
            foreach ($sidebarClasses as $class) {
                if ((int) $class['id'] === $postClassId) {
                    $savedLabel = instructor_class_label($class);
                    break;
                }
            }
            $_SESSION['grading_success'] = 'Grading weights saved for ' . $savedLabel . '.';
            redirect_to('settings/grading?class_id=' . $postClassId);
        }
        $selectedClassId = $postClassId;
    }
}

// Weights to show: repopulate from a failed POST, else the persisted/default values.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($fieldErrors)) {
    $weights = [];
    foreach ($components as $key => $label) {
        $weights[$key] = (string) ($_POST[$key . '_weight'] ?? '');
    }
    $weights['passing_grade'] = (string) ($_POST['passing_grade'] ?? '');
} elseif ($selectedClassId > 0) {
    $stored = get_class_grading_weights($pdo, $selectedClassId);
    $weights = [];
    foreach ($components as $key => $label) {
        $weights[$key] = rtrim(rtrim(number_format($stored[$key], 2), '0'), '.');
    }
    $weights['passing_grade'] = rtrim(rtrim(number_format($stored['passing_grade'], 2), '0'), '.');
} else {
    $defaults = grading_weight_defaults();
    $weights = [];
    foreach ($components as $key => $label) {
        $weights[$key] = rtrim(rtrim(number_format($defaults[$key], 2), '0'), '.');
    }
    $weights['passing_grade'] = rtrim(rtrim(number_format($defaults['passing_grade'], 2), '0'), '.');
}

$successFlash = $_SESSION['grading_success'] ?? null;
unset($_SESSION['grading_success']);
$csrfToken = csrf_token('csrf_grading_settings_token');

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Grading Settings',
    'eyebrow' => 'Settings',
    'description' => 'Set the grade-component weights that power grading, predictions, and insights for each class.',
    'active_route' => 'settings.grading',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($sidebarClasses, $selectedClassId, $components, $weights, $errors, $fieldErrors, $successFlash, $csrfToken) {
        ?>
        <section class="content-grid two-columns">
            <article class="form-panel grading-settings-panel">
                <div class="section-heading">
                    <h2>Assessment Weights</h2>
                    <span>Per class</span>
                </div>

                <?php if ($successFlash): ?>
                    <div class="alert alert-success" role="alert"><?php echo e($successFlash); ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (empty($sidebarClasses)): ?>
                    <div class="empty-state">
                        <i class="bi bi-easel2"></i>
                        <h3>No active classes yet</h3>
                        <p>Create and activate a class first, then set its grading weights here.</p>
                        <a class="btn btn-edupredict" href="<?php echo e(url_for('classes')); ?>">Go to Classes</a>
                    </div>
                <?php else: ?>
                    <form method="get" action="<?php echo e(url_for('settings/grading')); ?>" class="grading-class-picker">
                        <label class="form-label" for="grading-class-select">Class</label>
                        <select class="form-select" id="grading-class-select" name="class_id" onchange="this.form.submit()">
                            <?php foreach ($sidebarClasses as $class): ?>
                                <option value="<?php echo (int) $class['id']; ?>" <?php echo $selectedClassId === (int) $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo e(instructor_class_label($class)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <form method="post" action="<?php echo e(url_for('settings/grading')); ?>" data-grading-weights-form novalidate>
                        <input type="hidden" name="grading_action" value="save_weights">
                        <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                        <div class="grading-status" data-grading-status>
                            <div>
                                <span>Total weight</span>
                                <strong><span data-grading-total>0</span>%</strong>
                            </div>
                            <p data-grading-message>Component weights must total exactly 100%.</p>
                        </div>
                        <?php if (isset($fieldErrors['total'])): ?>
                            <p class="field-error d-block"><?php echo e($fieldErrors['total']); ?></p>
                        <?php endif; ?>

                        <div class="grading-weight-grid">
                            <?php foreach ($components as $key => $label): ?>
                                <div class="field">
                                    <label class="form-label" for="weight-<?php echo e($key); ?>"><?php echo e($label); ?></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control<?php echo isset($fieldErrors[$key]) ? ' is-invalid' : ''; ?>"
                                               id="weight-<?php echo e($key); ?>" name="<?php echo e($key); ?>_weight"
                                               value="<?php echo e($weights[$key]); ?>" min="0" max="100" step="0.5"
                                               data-grading-weight-input inputmode="decimal">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <?php if (isset($fieldErrors[$key])): ?>
                                        <p class="field-error d-block"><?php echo e($fieldErrors[$key]); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="field field-passing">
                            <label class="form-label" for="passing_grade">Passing grade</label>
                            <div class="input-group">
                                <input type="number" class="form-control<?php echo isset($fieldErrors['passing_grade']) ? ' is-invalid' : ''; ?>"
                                       id="passing_grade" name="passing_grade"
                                       value="<?php echo e($weights['passing_grade']); ?>" min="1" max="100" step="0.5" inputmode="decimal">
                                <span class="input-group-text">%</span>
                            </div>
                            <?php if (isset($fieldErrors['passing_grade'])): ?>
                                <p class="field-error d-block"><?php echo e($fieldErrors['passing_grade']); ?></p>
                            <?php endif; ?>
                            <small class="text-muted">Used as the fail/pass threshold for predictions and at-risk flags.</small>
                        </div>

                        <div class="grading-settings-actions">
                            <span class="grading-save-state" data-grading-dirty hidden><i class="bi bi-exclamation-circle-fill"></i> Unsaved changes</span>
                            <span class="grading-save-state is-saved" data-grading-clean hidden><i class="bi bi-check-circle-fill"></i> All changes saved</span>
                            <button class="btn btn-edupredict" type="submit" data-grading-save><i class="bi bi-check2-circle"></i> Save weights</button>
                        </div>
                    </form>
                <?php endif; ?>
            </article>

            <aside class="info-panel">
                <div class="section-heading">
                    <h2>How weights are used</h2>
                    <span>Reference</span>
                </div>
                <div class="steps-list">
                    <div><strong>1</strong><span>The six component weights must total exactly 100%.</span></div>
                    <div><strong>2</strong><span>Each student's current grade is the weighted average of completed components.</span></div>
                    <div><strong>3</strong><span>Predictions project the remaining components to forecast the final grade.</span></div>
                    <div><strong>4</strong><span>The passing grade sets the at-risk threshold across Insights.</span></div>
                </div>
            </aside>
        </section>
        <?php
    },
]);
