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
