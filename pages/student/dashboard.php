<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('student');

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'Student Dashboard',
    'eyebrow' => 'Learner progress hub',
    'description' => 'Track grades, attendance, progress, target outcomes, and future academic prediction insights from a focused student workspace.',
    'active' => 'Dashboard',
    'menu' => [
        ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => 'dashboard.php'],
        ['label' => 'My Classes', 'icon' => 'bi-easel2', 'href' => '#'],
        ['label' => 'Grades', 'icon' => 'bi-clipboard-data', 'href' => '#'],
        ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'href' => '#'],
        ['label' => 'Progress', 'icon' => 'bi-activity', 'href' => '#'],
        ['label' => 'Target Grade', 'icon' => 'bi-bullseye', 'href' => '#'],
        ['label' => 'Predictions', 'icon' => 'bi-stars', 'href' => '#'],
        ['label' => 'Warnings', 'icon' => 'bi-exclamation-triangle', 'href' => '#'],
        ['label' => 'Settings', 'icon' => 'bi-gear', 'href' => '#'],
    ],
    'cards' => [
        ['label' => 'Enrolled classes', 'value' => '0', 'note' => 'Classes joined by invitation', 'icon' => 'bi-easel2', 'tone' => 'tone-blue'],
        ['label' => 'Current average', 'value' => '0%', 'note' => 'Grade summary placeholder', 'icon' => 'bi-graph-up', 'tone' => 'tone-emerald'],
        ['label' => 'Attendance', 'value' => '0%', 'note' => 'Attendance summary placeholder', 'icon' => 'bi-calendar2-check', 'tone' => 'tone-indigo'],
        ['label' => 'Warnings', 'value' => '0', 'note' => 'No warnings configured yet', 'icon' => 'bi-exclamation-triangle', 'tone' => 'tone-amber'],
    ],
    'widgets' => [
        ['title' => 'Performance Timeline', 'tag' => 'Future', 'body' => 'Reserved for grade progress, activity completion, and academic movement over time.', 'icon' => 'bi-graph-up-arrow', 'empty' => 'Timeline module will be added later.'],
        ['title' => 'Target Grade Calculator', 'tag' => 'Future', 'body' => 'A placeholder for setting target outcomes and estimating required future scores.', 'icon' => 'bi-bullseye', 'empty' => 'Target calculator placeholder.'],
        ['title' => 'Academic Prediction', 'tag' => 'Future', 'body' => 'Prepared space for predictive insights once attendance and grade data become available.', 'icon' => 'bi-stars', 'empty' => 'Prediction results are not implemented yet.'],
    ],
]);
