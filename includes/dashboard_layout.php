<?php
/**
 * Shared dashboard shell renderer.
 * Each role dashboard passes menu items, cards, and placeholder widgets.
 */

function render_dashboard_page(array $page): void
{
    $displayName = current_display_name($page['fallback_name'] ?? 'User');
    $roleLabel = (string) ($page['role_label'] ?? 'User');
    $title = (string) ($page['title'] ?? 'Dashboard');
    $eyebrow = (string) ($page['eyebrow'] ?? 'EDUPREDICT');
    $description = (string) ($page['description'] ?? 'Monitor academic performance from one organized workspace.');
    $active = (string) ($page['active'] ?? 'Dashboard');
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

            <nav class="nav-panel">
                <?php foreach ($menuItems as $item): ?>
                    <?php $isActive = ((string) $item['label'] === $active); ?>
                    <a class="<?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e((string) ($item['href'] ?? '#')); ?>">
                        <i class="bi <?php echo e((string) ($item['icon'] ?? 'bi-circle')); ?>"></i>
                        <span><?php echo e((string) $item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

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
                <nav class="nav-panel">
                    <?php foreach ($menuItems as $item): ?>
                        <?php $isActive = ((string) $item['label'] === $active); ?>
                        <a class="<?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e((string) ($item['href'] ?? '#')); ?>">
                            <i class="bi <?php echo e((string) ($item['icon'] ?? 'bi-circle')); ?>"></i>
                            <span><?php echo e((string) $item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
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
