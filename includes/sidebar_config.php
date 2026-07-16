<?php
/**
 * Sidebar configuration helpers.
 */

function instructor_class_views(): array
{
    return [
        'overview' => [
            'label' => 'Overview',
            'route' => 'class.overview',
            'icon' => 'bi-layout-text-sidebar-reverse',
            'description' => 'Review class details, enrollment, schedule, and quick access items.',
        ],
        'attendance' => [
            'label' => 'Attendance',
            'route' => 'class.attendance',
            'icon' => 'bi-calendar-check',
            'description' => 'Track attendance records for the selected class.',
        ],
        'activities' => [
            'label' => 'Activities',
            'route' => 'class.activities',
            'icon' => 'bi-journal-check',
            'description' => 'Manage individual activity scores and completion status.',
        ],
        'group-activities' => [
            'label' => 'Group Activities',
            'route' => 'class.group-activities',
            'icon' => 'bi-diagram-3',
            'description' => 'Manage collaborative activity scores for the selected class.',
        ],
        'quizzes' => [
            'label' => 'Quizzes',
            'route' => 'class.quizzes',
            'icon' => 'bi-patch-question',
            'description' => 'Manage quiz records and score summaries.',
        ],
        'midterm' => [
            'label' => 'Midterm',
            'route' => 'class.midterm',
            'icon' => 'bi-clipboard-data',
            'description' => 'Prepare and monitor midterm grade components.',
        ],
        'finals' => [
            'label' => 'Finals',
            'route' => 'class.finals',
            'icon' => 'bi-clipboard2-check',
            'description' => 'Prepare and monitor finals grade components.',
        ],
        'participation' => [
            'label' => 'Participation',
            'route' => 'class.participation',
            'icon' => 'bi-chat-square-text',
            'description' => 'Track participation records for class engagement.',
        ],
        'groupings' => [
            'label' => 'Groupings',
            'route' => 'class.groupings',
            'icon' => 'bi-diagram-3',
            'description' => 'Create and manage reusable student groupings for this class.',
        ],
        'dashboard' => [
            'label' => 'Insights Dashboard',
            'route' => 'class.dashboard',
            'icon' => 'bi-speedometer2',
            'description' => 'At-a-glance summary of class performance, attendance, and risk.',
        ],
        'analytics' => [
            'label' => 'Analytics',
            'route' => 'class.analytics',
            'icon' => 'bi-graph-up',
            'description' => 'Review class-level performance analytics and trends.',
        ],
        'predictions' => [
            'label' => 'Predictions',
            'route' => 'class.predictions',
            'icon' => 'bi-stars',
            'description' => 'Review predictive academic risk signals for this class.',
        ],
        'recommendations' => [
            'label' => 'Recommendations',
            'route' => 'class.recommendations',
            'icon' => 'bi-lightbulb',
            'description' => 'Prescriptive interventions, assessment insights, and teaching tips.',
        ],
    ];
}

function instructor_normalize_class_view(string $view): string
{
    $view = strtolower(trim($view));
    $views = instructor_class_views();

    return isset($views[$view]) ? $view : 'overview';
}

function instructor_class_view_meta(string $view): array
{
    $views = instructor_class_views();
    $view = instructor_normalize_class_view($view);

    return $views[$view];
}

function instructor_class_route(int $classId, string $view = 'overview'): string
{
    $path = 'classes/' . $classId;
    $view = instructor_normalize_class_view($view);

    if ($view !== 'overview') {
        $path .= '/' . $view;
    }

    return url_for($path);
}

function instructor_sidebar_classes(PDO $pdo, int $instructorId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, class_name, section
         FROM classes
         WHERE instructor_id = :instructor_id AND status = "active"
         ORDER BY created_at DESC'
    );
    $stmt->execute([':instructor_id' => $instructorId]);

    return $stmt->fetchAll();
}

function instructor_class_label(array $class): string
{
    $label = trim((string) ($class['class_name'] ?? 'Class'));
    $section = trim((string) ($class['section'] ?? ''));

    return $section !== '' ? $label . ' ' . $section : $label;
}

function instructor_sidebar_menu(array $classes, ?int $selectedClassId = null): array
{
    $classItems = [];
    $selectedClassLabel = '';

    foreach ($classes as $class) {
        $classId = (int) $class['id'];

        if ($selectedClassId !== null && $selectedClassId !== $classId) {
            continue;
        }

        if ($selectedClassId === $classId) {
            $selectedClassLabel = instructor_class_label($class);
        }

        $classItems[] = [
            'label' => instructor_class_label($class),
            'href' => instructor_class_route($classId),
            'active' => $selectedClassId === $classId,
            'class' => 'nav-class-item',
        ];
    }

    $menu = [
        [
            'label' => 'Dashboard',
            'icon' => 'bi-grid-1x2-fill',
            'href' => url_for('pages/instructor/dashboard.php'),
            'route' => 'dashboard',
        ],
        [
            'label' => 'Students',
            'icon' => 'bi-people',
            'href' => url_for('pages/instructor/students.php'),
            'route' => 'students',
        ],
        [
            'label' => 'Assessments',
            'icon' => 'bi-journal-text',
            'href' => url_for('pages/instructor/assessments.php'),
            'route' => 'assessments',
        ],
        [
            'type' => 'group',
            'key' => 'classes',
            'label' => 'Classes',
            'icon' => 'bi-easel2',
            'href' => url_for('classes'),
            'route' => 'classes',
            'active_prefixes' => ['class.'],
            'active_routes' => ['classes.archived'],
            'items' => $classItems,
            'empty_label' => 'No classes yet',
            'storage_key' => 'edupredict.sidebar.classes',
            'default_expanded' => $selectedClassId !== null,
        ],
    ];

    if ($selectedClassId !== null) {
        $menu[] = [
            'type' => 'section',
            'label' => 'CLASS',
            'items' => [
                [
                    'type' => 'text',
                    'label' => $selectedClassLabel !== '' ? $selectedClassLabel : 'Selected class',
                    'icon' => 'bi-easel2',
                    'class' => 'nav-selected-class',
                ],
                [
                    'label' => 'Overview',
                    'icon' => 'bi-layout-text-sidebar-reverse',
                    'href' => instructor_class_route($selectedClassId),
                    'route' => 'class.overview',
                ],
            ],
        ];
        $menu[] = [
            'type' => 'section',
            'label' => 'ASSESSMENTS',
            'items' => [
                [
                    'label' => 'Course Requirements',
                    'icon' => 'bi-list-check',
                    'href' => '#',
                    'children' => [
                        [
                            'label' => 'Attendance',
                            'href' => instructor_class_route($selectedClassId, 'attendance'),
                            'route' => 'class.attendance',
                        ],
                        [
                            'label' => 'Participation',
                            'href' => instructor_class_route($selectedClassId, 'participation'),
                            'route' => 'class.participation',
                        ],
                    ],
                ],
                [
                    'label' => 'Activities',
                    'icon' => 'bi-journal-check',
                    'href' => instructor_class_route($selectedClassId, 'activities'),
                    'route' => 'class.activities',
                ],
                [
                    'label' => 'Quizzes',
                    'icon' => 'bi-patch-question',
                    'href' => instructor_class_route($selectedClassId, 'quizzes'),
                    'route' => 'class.quizzes',
                ],
                [
                    'label' => 'Groupings',
                    'icon' => 'bi-diagram-3',
                    'href' => instructor_class_route($selectedClassId, 'groupings'),
                    'route' => 'class.groupings',
                ],
                [
                    'label' => 'Major Exams',
                    'icon' => 'bi-clipboard-data',
                    'href' => '#',
                    'children' => [
                        [
                            'label' => 'Midterm',
                            'href' => instructor_class_route($selectedClassId, 'midterm'),
                            'route' => 'class.midterm',
                        ],
                        [
                            'label' => 'Finals',
                            'href' => instructor_class_route($selectedClassId, 'finals'),
                            'route' => 'class.finals',
                        ],
                    ],
                ],
            ],
        ];
        $menu[] = [
            'type' => 'section',
            'label' => 'INSIGHTS',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'icon' => 'bi-speedometer2',
                    'href' => instructor_class_route($selectedClassId, 'dashboard'),
                    'route' => 'class.dashboard',
                ],
                [
                    'label' => 'Analytics',
                    'icon' => 'bi-graph-up',
                    'href' => instructor_class_route($selectedClassId, 'analytics'),
                    'route' => 'class.analytics',
                ],
                [
                    'label' => 'Predictions',
                    'icon' => 'bi-stars',
                    'href' => instructor_class_route($selectedClassId, 'predictions'),
                    'route' => 'class.predictions',
                ],
                [
                    'label' => 'Recommendations',
                    'icon' => 'bi-lightbulb',
                    'href' => instructor_class_route($selectedClassId, 'recommendations'),
                    'route' => 'class.recommendations',
                ],
            ],
        ];
    }

    $menu[] = [
        'type' => 'section',
        'label' => 'Settings',
        'items' => [
            [
                'label' => 'Grading Settings',
                'icon' => 'bi-sliders',
                'href' => url_for('settings/grading'),
                'route' => 'settings.grading',
            ],
            [
                'label' => 'Account Settings',
                'icon' => 'bi-person-gear',
                'href' => url_for('settings/account'),
                'route' => 'settings.account',
            ],
        ],
    ];

    return $menu;
}

/** Shared administrator portal sidebar. */
function admin_sidebar_menu(): array
{
    $users = url_for('pages/administrator/users.php');
    return [
        ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => url_for('pages/administrator/dashboard.php'), 'route' => 'admin.dashboard'],
        [
            'type' => 'section',
            'label' => 'MANAGEMENT',
            'items' => [
                ['label' => 'User Management', 'icon' => 'bi-people', 'href' => $users, 'route' => 'admin.users'],
                ['label' => 'Instructors', 'icon' => 'bi-person-workspace', 'href' => $users . '?role=instructor', 'route' => 'admin.instructors'],
                ['label' => 'Students', 'icon' => 'bi-mortarboard', 'href' => $users . '?role=student', 'route' => 'admin.students'],
                ['label' => 'Classes', 'icon' => 'bi-easel2', 'href' => url_for('pages/administrator/classes.php'), 'route' => 'admin.classes'],
            ],
        ],
        [
            'type' => 'section',
            'label' => 'SYSTEM',
            'items' => [
                ['label' => 'Announcements', 'icon' => 'bi-megaphone', 'href' => url_for('pages/administrator/announcements.php'), 'route' => 'admin.announcements'],
                ['label' => 'System Settings', 'icon' => 'bi-sliders', 'href' => url_for('pages/administrator/settings.php'), 'route' => 'admin.settings'],
            ],
        ],
    ];
}

/**
 * Shared student portal sidebar. The Grades/Attendance/Progress/Target/Predictions
 * links point at class-insights.php (the page resolves 0/1/2+ enrollments); when a
 * class is already selected, pass its id so those links stay on that class.
 */
function student_sidebar_menu(?int $classId = null): array
{
    $insights = url_for('pages/student/class-insights.php');
    $suffix = $classId ? '?class_id=' . (int) $classId . '&tab=' : '?tab=';

    return [
        ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => url_for('pages/student/dashboard.php'), 'route' => 'student.dashboard'],
        ['label' => 'My Classes', 'icon' => 'bi-easel2', 'href' => url_for('pages/student/classes.php'), 'route' => 'student.classes'],
        [
            'type' => 'section',
            'label' => 'MY PROGRESS',
            'items' => [
                ['label' => 'Grades', 'icon' => 'bi-clipboard-data', 'href' => $insights . $suffix . 'grades', 'route' => 'student.grades'],
                ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'href' => $insights . $suffix . 'attendance', 'route' => 'student.attendance'],
                ['label' => 'Progress', 'icon' => 'bi-activity', 'href' => $insights . $suffix . 'participation', 'route' => 'student.progress'],
                ['label' => 'Target Grade', 'icon' => 'bi-bullseye', 'href' => $insights . $suffix . 'goal', 'route' => 'student.goal'],
                ['label' => 'Predictions', 'icon' => 'bi-stars', 'href' => $insights . $suffix . 'predictions', 'route' => 'student.predictions'],
                ['label' => 'Warnings', 'icon' => 'bi-exclamation-triangle', 'href' => url_for('pages/student/warnings.php'), 'route' => 'student.warnings'],
            ],
        ],
        [
            'type' => 'section',
            'label' => 'Account',
            'items' => [
                ['label' => 'Settings', 'icon' => 'bi-gear', 'href' => url_for('pages/student/settings.php'), 'route' => 'student.settings'],
            ],
        ],
    ];
}
