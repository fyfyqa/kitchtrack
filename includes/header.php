<?php
/**
 * KitchTrack - Shared Page Header & Role-Based Navigation
 * Expected variables before include:
 *   $page_title (string)
 *   $active_nav (string) e.g. 'dashboard', 'petty_cash', 'staff', 'templates', 'shopping_list', 'history'
 */

$base = base_path();
$user = current_user();
$flash = get_flash();
$page_title = $page_title ?? 'KitchTrack';
$active_nav = $active_nav ?? '';

// Count pending deliveries for Staff Receive tab badge
$pending_delivery_count = 0;
if ($user && $user['role'] === 'staff') {
    $pending_delivery_count = (int)$pdo->query("SELECT COUNT(*) FROM shopping_trips WHERE status = 'completed'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6F4E37">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="KitchTrack">
    <title><?= e($page_title) ?> | KitchTrack</title>
    <link rel="manifest" href="<?= $base ?>/manifest.json">
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/icons/icon-192.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= $base ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="<?= ($user && $user['role'] === 'staff') ? 'staff-layout' : 'owner-layout' ?>">

<?php if ($user): ?>
    <?php if ($user['role'] === 'owner'): ?>
        <!-- Owner-Only Navigation Bar -->
        <nav class="navbar navbar-expand-lg kt-navbar sticky-top no-print">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $base ?>/owner/dashboard.php">
                    <span class="kt-brand-mark"><i class="bi bi-basket2-fill"></i></span>
                    <span>KitchTrack</span>
                    <span class="badge kt-role-badge">Owner</span>
                </a>
                <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#ownerNav" aria-label="Toggle navigation">
                    <i class="bi bi-list fs-2 text-brown"></i>
                </button>
                <div class="collapse navbar-collapse" id="ownerNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">
                        <li class="nav-item">
                            <a class="nav-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>" href="<?= $base ?>/owner/dashboard.php">
                                <i class="bi bi-bar-chart-line me-1"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_nav === 'petty_cash' ? 'active' : '' ?>" href="<?= $base ?>/owner/petty_cash.php">
                                <i class="bi bi-cash-coin me-1"></i> Petty Cash
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_nav === 'staff' ? 'active' : '' ?>" href="<?= $base ?>/owner/staff.php">
                                <i class="bi bi-people me-1"></i> Staff
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $active_nav === 'templates' ? 'active' : '' ?>" href="<?= $base ?>/owner/templates.php">
                                <i class="bi bi-card-checklist me-1"></i> Templates &amp; Stores
                            </a>
                        </li>
                    </ul>
                    <div class="d-flex align-items-center gap-3 pt-2 pt-lg-0 border-top border-lg-0">
                        <span class="small text-muted-warm">
                            <i class="bi bi-person-circle me-1"></i><?= e($user['full_name']) ?>
                        </span>
                        <a href="<?= $base ?>/logout.php" class="btn btn-sm btn-outline-brown">
                            <i class="bi bi-box-arrow-right me-1"></i> Log Out
                        </a>
                    </div>
                </div>
            </div>
        </nav>
    <?php else: ?>
        <!-- Staff-Only Simplified Top Bar (Mobile-First) -->
        <nav class="navbar kt-navbar sticky-top no-print">
            <div class="container d-flex flex-nowrap justify-content-between align-items-center gap-2">
                <a class="navbar-brand d-flex align-items-center gap-2 mb-0 flex-shrink-0" href="<?= $base ?>/staff/shopping_list.php">
                    <span class="kt-brand-mark"><i class="bi bi-basket2-fill"></i></span>
                    <span>KitchTrack</span>
                </a>
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <span class="small text-muted-warm d-inline-flex align-items-center text-truncate" style="max-width: 135px;">
                        <i class="bi bi-person-circle me-1 text-brown flex-shrink-0"></i>
                        <span class="text-truncate"><?= e($user['full_name']) ?></span>
                    </span>
                    <div class="d-none d-md-flex align-items-center gap-1 me-2">
                        <a href="<?= $base ?>/staff/shopping_list.php" class="btn btn-sm <?= $active_nav === 'shopping_list' ? 'btn-brown' : 'btn-outline-brown' ?>">
                            <i class="bi bi-cart3 me-1"></i> List
                        </a>
                        <a href="<?= $base ?>/staff/receive.php" class="btn btn-sm <?= $active_nav === 'receive' ? 'btn-brown' : 'btn-outline-brown' ?> position-relative">
                            <i class="bi bi-box-seam me-1"></i> Receive
                            <span class="badge rounded-pill bg-danger ms-1 js-receive-badge <?= $pending_delivery_count > 0 ? '' : 'd-none' ?>"><?= $pending_delivery_count ?></span>
                        </a>
                        <a href="<?= $base ?>/staff/history.php" class="btn btn-sm <?= $active_nav === 'history' ? 'btn-brown' : 'btn-outline-brown' ?>">
                            <i class="bi bi-clock-history me-1"></i> History
                        </a>
                    </div>
                    <a href="<?= $base ?>/logout.php" class="btn btn-sm btn-outline-brown flex-shrink-0" title="Log Out">
                        <i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline ms-1">Log Out</span>
                    </a>
                </div>
            </div>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<main class="container py-3 py-md-4 kt-main">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show kt-alert no-print" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
