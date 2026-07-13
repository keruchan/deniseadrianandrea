<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => 'Administrator Dashboard',
    'eyebrow' => 'Institution command center',
    'description' => 'Manage users, classes, settings, and institution-wide academic performance oversight from one organized workspace.',
    'active' => 'Dashboard',
    'menu' => [
        ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => 'dashboard.php'],
        ['label' => 'User Management', 'icon' => 'bi-people', 'href' => '#'],
        ['label' => 'Instructors', 'icon' => 'bi-person-workspace', 'href' => '#'],
        ['label' => 'Students', 'icon' => 'bi-mortarboard', 'href' => '#'],
        ['label' => 'Classes', 'icon' => 'bi-easel2', 'href' => '#'],
        ['label' => 'Analytics', 'icon' => 'bi-graph-up-arrow', 'href' => '#'],
        ['label' => 'Announcements', 'icon' => 'bi-megaphone', 'href' => '#'],
        ['label' => 'System Settings', 'icon' => 'bi-sliders', 'href' => '#'],
    ],
    'cards' => [
        ['label' => 'Active classes', 'value' => '0', 'note' => 'Classes prepared for monitoring', 'icon' => 'bi-easel2', 'tone' => 'tone-blue'],
        ['label' => 'Instructors', 'value' => '0', 'note' => 'Instructor records ready', 'icon' => 'bi-person-workspace', 'tone' => 'tone-indigo'],
        ['label' => 'Students', 'value' => '0', 'note' => 'Student accounts pending growth', 'icon' => 'bi-mortarboard', 'tone' => 'tone-emerald'],
        ['label' => 'System alerts', 'value' => '0', 'note' => 'No active warnings configured', 'icon' => 'bi-bell', 'tone' => 'tone-amber'],
    ],
    'widgets' => [
        ['title' => 'Institution Analytics', 'tag' => 'Future', 'body' => 'A placeholder for institution-wide grade trends, performance distribution, and class progress summaries.', 'icon' => 'bi-bar-chart-line', 'empty' => 'Analytics module will be added later.'],
        ['title' => 'Active Class Monitor', 'tag' => 'Future', 'body' => 'A reserved panel for monitoring active classes, instructor activity, and enrollment movement.', 'icon' => 'bi-window-stack', 'empty' => 'Class monitoring placeholder.'],
        ['title' => 'Announcements', 'tag' => 'Future', 'body' => 'A prepared space for system-wide academic announcements and administrative notices.', 'icon' => 'bi-megaphone', 'empty' => 'Announcement tools are not implemented yet.'],
    ],
]);
