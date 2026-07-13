<?php
/**
 * Shared dashboard shell renderer.
 * Each role dashboard passes menu items, cards, and placeholder widgets.
 */

function sidebar_item_is_visible(array $item): bool
{
    return !array_key_exists('visible', $item) || (bool) $item['visible'];
}

function sidebar_item_is_active(array $item, string $active): bool
{
    if (array_key_exists('active', $item)) {
        return (bool) $item['active'];
    }

    $route = (string) ($item['route'] ?? '');
    if ($route !== '' && $route === $active) {
        return true;
    }

    foreach (($item['active_routes'] ?? []) as $activeRoute) {
        if ((string) $activeRoute === $active) {
            return true;
        }
    }

    foreach (($item['active_prefixes'] ?? []) as $prefix) {
        $prefix = (string) $prefix;
        if ($prefix !== '' && strncmp($active, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }

    $label = (string) ($item['label'] ?? '');

    return $route === '' && $label !== '' && $label === $active;
}

function sidebar_item_has_active_child(array $item, string $active): bool
{
    foreach (($item['children'] ?? []) as $child) {
        if (sidebar_item_is_active($child, $active) || sidebar_item_has_active_child($child, $active)) {
            return true;
        }
    }

    foreach (($item['items'] ?? []) as $child) {
        if (sidebar_item_is_active($child, $active) || sidebar_item_has_active_child($child, $active)) {
            return true;
        }
    }

    return false;
}

function render_sidebar_icon(array $item): void
{
    $icon = (string) ($item['icon'] ?? '');

    if ($icon !== '') {
        ?>
        <i class="bi <?php echo e($icon); ?>"></i>
        <?php
        return;
    }
    ?>
    <span class="nav-bullet" aria-hidden="true"></span>
    <?php
}

function render_sidebar_link(array $item, string $active, string $extraClass = ''): void
{
    if (!sidebar_item_is_visible($item)) {
        return;
    }

    $classes = ['nav-link'];
    $customClass = trim((string) ($item['class'] ?? ''));

    if ($extraClass !== '') {
        $classes[] = $extraClass;
    }

    if ($customClass !== '') {
        $classes[] = $customClass;
    }

    if (sidebar_item_is_active($item, $active)) {
        $classes[] = 'active';
    } elseif (sidebar_item_has_active_child($item, $active)) {
        $classes[] = 'has-active-child';
    }
    ?>
    <a class="<?php echo e(implode(' ', $classes)); ?>" href="<?php echo e((string) ($item['href'] ?? '#')); ?>">
        <?php render_sidebar_icon($item); ?>
        <span><?php echo e((string) ($item['label'] ?? 'Menu item')); ?></span>
    </a>
    <?php if (!empty($item['children'])): ?>
        <div class="nav-child-list">
            <?php foreach ($item['children'] as $child): ?>
                <?php render_sidebar_link($child, $active, 'nav-child-link'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

function render_sidebar_text_item(array $item): void
{
    if (!sidebar_item_is_visible($item)) {
        return;
    }

    $classes = ['nav-text-item'];
    $customClass = trim((string) ($item['class'] ?? ''));

    if ($customClass !== '') {
        $classes[] = $customClass;
    }
    ?>
    <div class="<?php echo e(implode(' ', $classes)); ?>">
        <?php render_sidebar_icon($item); ?>
        <span><?php echo e((string) ($item['label'] ?? 'Context')); ?></span>
    </div>
    <?php
}

function render_sidebar_group(array $item, string $active): void
{
    if (!sidebar_item_is_visible($item)) {
        return;
    }

    $items = $item['items'] ?? [];
    $isActive = sidebar_item_is_active($item, $active) || sidebar_item_has_active_child($item, $active);
    $defaultExpanded = !empty($item['default_expanded']);
    $classes = ['nav-group'];

    if ($isActive) {
        $classes[] = 'has-active';
    }

    if ($defaultExpanded) {
        $classes[] = 'is-expanded';
    }
    ?>
    <div class="<?php echo e(implode(' ', $classes)); ?>"
        data-sidebar-group="<?php echo e((string) ($item['key'] ?? $item['label'] ?? 'group')); ?>"
        data-sidebar-storage-key="<?php echo e((string) ($item['storage_key'] ?? '')); ?>"
        data-default-expanded="<?php echo $defaultExpanded ? 'true' : 'false'; ?>">
        <a class="nav-link nav-group-trigger <?php echo $isActive ? 'active' : ''; ?>"
            href="<?php echo e((string) ($item['href'] ?? '#')); ?>"
            aria-expanded="<?php echo $defaultExpanded ? 'true' : 'false'; ?>">
            <?php render_sidebar_icon($item); ?>
            <span><?php echo e((string) ($item['label'] ?? 'Menu group')); ?></span>
            <i class="bi bi-chevron-down nav-caret" aria-hidden="true"></i>
        </a>
        <div class="nav-submenu">
            <?php if (empty($items)): ?>
                <span class="nav-empty"><?php echo e((string) ($item['empty_label'] ?? 'Nothing here yet')); ?></span>
            <?php else: ?>
                <?php foreach ($items as $child): ?>
                    <?php render_sidebar_link($child, $active, 'nav-submenu-link'); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function render_sidebar_section(array $item, string $active): void
{
    if (!sidebar_item_is_visible($item)) {
        return;
    }
    ?>
    <div class="nav-section">
        <div class="nav-section-title"><?php echo e((string) ($item['label'] ?? 'Section')); ?></div>
        <div class="nav-section-items">
            <?php foreach (($item['items'] ?? []) as $child): ?>
                <?php if ((string) ($child['type'] ?? 'link') === 'text'): ?>
                    <?php render_sidebar_text_item($child); ?>
                <?php else: ?>
                    <?php render_sidebar_link($child, $active); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function render_sidebar_nav_panel(array $menuItems, string $active): void
{
    ?>
    <nav class="nav-panel">
        <?php foreach ($menuItems as $item): ?>
            <?php
            $type = (string) ($item['type'] ?? 'link');

            if ($type === 'group') {
                render_sidebar_group($item, $active);
            } elseif ($type === 'section') {
                render_sidebar_section($item, $active);
            } elseif ($type === 'text') {
                render_sidebar_text_item($item);
            } else {
                render_sidebar_link($item, $active);
            }
            ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

function render_dashboard_page(array $page): void
{
    $displayName = current_display_name($page['fallback_name'] ?? 'User');
    $roleLabel = (string) ($page['role_label'] ?? 'User');
    $title = (string) ($page['title'] ?? 'Dashboard');
    $eyebrow = (string) ($page['eyebrow'] ?? 'EDUPREDICT');
    $description = (string) ($page['description'] ?? 'Monitor academic performance from one organized workspace.');
    $active = (string) ($page['active_route'] ?? $page['active'] ?? 'Dashboard');
    $menuItems = $page['menu'] ?? [];
    $cards = $page['cards'] ?? [];
    $widgets = $page['widgets'] ?? [];
    $contentCallback = $page['content'] ?? null;
    $todayLabel = date('l, F j, Y');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EDUPREDICT | <?php echo e($title); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(url_for('css/dashboard.css')); ?>">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="dashboard-shell">
        <aside class="sidebar" aria-label="<?php echo e($roleLabel); ?> navigation">
            <div class="brand-block">
                <span class="brand-mark" aria-hidden="true"><i class="bi bi-bar-chart-steps"></i></span>
                <div>
                    <div class="brand-word">EDUPREDICT</div>
                    <div class="brand-sub"><?php echo e($roleLabel); ?></div>
                </div>
            </div>

            <?php render_sidebar_nav_panel($menuItems, $active); ?>

            <div class="sidebar-footer">
                <a href="<?php echo e(url_for('pages/auth/logout.php')); ?>"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
            </div>
        </aside>

        <div class="mobile-topbar">
            <div class="d-flex align-items-center gap-2">
                <span class="brand-mark small" aria-hidden="true"><i class="bi bi-bar-chart-steps"></i></span>
                <span class="brand-word">EDUPREDICT</span>
            </div>
            <button class="btn-icon" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open navigation menu">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="offcanvas offcanvas-start dashboard-offcanvas" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
            <div class="offcanvas-header">
                <h2 id="mobileNavLabel" class="brand-word h6 mb-0">Navigation</h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <?php render_sidebar_nav_panel($menuItems, $active); ?>
                <div class="sidebar-footer mt-3">
                    <a href="<?php echo e(url_for('pages/auth/logout.php')); ?>"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
                </div>
            </div>
        </div>

        <main class="main" id="main-content">
            <header class="topbar" aria-label="Dashboard toolbar">
                <div>
                    <div class="breadcrumb-line">EDUPREDICT / <?php echo e($roleLabel); ?></div>
                    <div class="date-line"><?php echo e($todayLabel); ?></div>
                </div>
                <div class="topbar-actions">
                    <button class="btn-icon notification-button" type="button" aria-label="Notifications placeholder">
                        <i class="bi bi-bell"></i>
                        <span class="notification-dot" aria-hidden="true"></span>
                    </button>
                    <div class="dropdown">
                        <button class="profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="avatar-dot"><?php echo e(first_initial($displayName)); ?></span>
                            <span><?php echo e($displayName); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-secondary">Signed in as <?php echo e($roleLabel); ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo e(url_for('pages/auth/logout.php')); ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <section class="page-header mb-4">
                <div>
                    <div class="eyebrow"><?php echo e($eyebrow); ?></div>
                    <h1><?php echo e($title); ?></h1>
                    <p><?php echo e($description); ?></p>
                </div>
                <span class="status-pill"><i class="bi bi-shield-check"></i> Foundation ready</span>
            </section>

            <?php if (is_callable($contentCallback)): ?>
                <?php $contentCallback(); ?>
            <?php else: ?>
                <section class="metric-grid mb-4" aria-label="Dashboard cards">
                    <?php foreach ($cards as $card): ?>
                        <article class="metric-card <?php echo e((string) ($card['tone'] ?? '')); ?>">
                            <div class="metric-icon"><i class="bi <?php echo e((string) ($card['icon'] ?? 'bi-graph-up')); ?>"></i></div>
                            <div>
                                <div class="metric-label"><?php echo e((string) $card['label']); ?></div>
                                <div class="metric-value"><?php echo e((string) $card['value']); ?></div>
                                <div class="metric-note"><?php echo e((string) $card['note']); ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="widget-grid" aria-label="Dashboard placeholders">
                    <?php foreach ($widgets as $widget): ?>
                        <article class="widget-panel">
                            <div class="section-heading">
                                <h2><?php echo e((string) $widget['title']); ?></h2>
                                <span><?php echo e((string) ($widget['tag'] ?? 'Planned')); ?></span>
                            </div>
                            <p><?php echo e((string) $widget['body']); ?></p>
                            <div class="empty-state">
                                <i class="bi <?php echo e((string) ($widget['icon'] ?? 'bi-layout-text-window')); ?>"></i>
                                <span><?php echo e((string) ($widget['empty'] ?? 'Module placeholder')); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo e(url_for('js/dashboard.js')); ?>"></script>
</body>
</html>
    <?php
}
