<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);

$gradingCategories = [
    [
        'name' => 'Course Requirements',
        'weight' => 20,
        'children' => [
            ['name' => 'Attendance', 'weight' => 5],
            ['name' => 'Participation', 'weight' => 15],
        ],
    ],
    [
        'name' => 'Activities',
        'weight' => 20,
        'children' => [],
    ],
    [
        'name' => 'Quizzes',
        'weight' => 20,
        'children' => [],
    ],
    [
        'name' => 'Major Exams',
        'weight' => 40,
        'children' => [
            ['name' => 'Midterm', 'weight' => 20],
            ['name' => 'Finals', 'weight' => 20],
        ],
    ],
];

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Grading Settings',
    'eyebrow' => 'Settings',
    'description' => 'Design assessment categories, subcategories, and grading weights before connecting persistence.',
    'active_route' => 'settings.grading',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($gradingCategories) {
        ?>
        <section class="content-grid two-columns">
            <article class="form-panel grading-settings-panel">
                <div class="section-heading">
                    <h2>Assessment Weights</h2>
                    <span>UI draft</span>
                </div>

                <form data-grading-settings-form novalidate>
                    <div class="grading-status" data-grading-status>
                        <div>
                            <span>Total weight</span>
                            <strong><span data-grading-total>100</span>%</strong>
                        </div>
                        <p data-grading-message>Weights are valid. Total grading weight is 100%.</p>
                    </div>

                    <div class="grading-category-list" data-grading-category-list>
                        <?php foreach ($gradingCategories as $category): ?>
                            <div class="grading-category" data-grading-category>
                                <div class="grading-category-row">
                                    <div class="grading-name-field">
                                        <label class="form-label">Category</label>
                                        <input type="text" class="form-control" value="<?php echo e($category['name']); ?>" data-category-name>
                                    </div>
                                    <div class="grading-weight-field">
                                        <label class="form-label">Weight</label>
                                        <div>
                                            <input type="number" class="form-control" value="<?php echo e((string) $category['weight']); ?>" min="0" max="100" step="1" data-category-weight>
                                            <span>%</span>
                                        </div>
                                    </div>
                                    <div class="grading-row-actions">
                                        <button class="btn btn-copy" type="button" data-add-subcategory><i class="bi bi-plus-circle"></i> Subcategory</button>
                                        <button class="btn btn-copy btn-danger-soft" type="button" data-delete-category><i class="bi bi-trash"></i> Delete</button>
                                    </div>
                                </div>

                                <div class="grading-subcategory-list" data-grading-subcategory-list>
                                    <?php foreach ($category['children'] as $child): ?>
                                        <div class="grading-subcategory" data-grading-subcategory>
                                            <div class="grading-name-field">
                                                <label class="form-label">Subcategory</label>
                                                <input type="text" class="form-control" value="<?php echo e($child['name']); ?>" data-subcategory-name>
                                            </div>
                                            <div class="grading-weight-field">
                                                <label class="form-label">Weight</label>
                                                <div>
                                                    <input type="number" class="form-control" value="<?php echo e((string) $child['weight']); ?>" min="0" max="100" step="1" data-subcategory-weight>
                                                    <span>%</span>
                                                </div>
                                            </div>
                                            <button class="btn btn-copy btn-danger-soft" type="button" data-delete-subcategory aria-label="Delete subcategory"><i class="bi bi-trash"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="grading-category-error" data-category-error></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="grading-settings-actions">
                        <button class="btn btn-copy" type="button" data-add-category><i class="bi bi-plus-circle"></i> Add category</button>
                        <button class="btn btn-edupredict" type="submit" data-save-grading><i class="bi bi-check2-circle"></i> Save settings</button>
                    </div>
                </form>
            </article>

            <aside class="info-panel">
                <div class="section-heading">
                    <h2>Validation Rules</h2>
                    <span>Required</span>
                </div>
                <div class="steps-list">
                    <div><strong>1</strong><span>Top-level category weights must total exactly 100%.</span></div>
                    <div><strong>2</strong><span>Subcategory weights must match their parent category.</span></div>
                    <div><strong>3</strong><span>Saving stays disabled until the grading structure is valid.</span></div>
                </div>
            </aside>
        </section>
        <?php
    },
]);
