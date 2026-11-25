<?php
/**
 * KitchTrack - Shared Footer & Mobile Bottom Navigation (Staff)
 */
$base = base_path();
$user = current_user();
$active_nav = $active_nav ?? '';
?>
</main>

<?php if ($user && $user['role'] === 'staff'): ?>
    <!-- Mobile Bottom Navigation Bar for Staff (3 Clean Tabs Only) -->
    <nav class="kt-bottom-nav d-md-none no-print" aria-label="Staff navigation">
        <a href="<?= $base ?>/staff/shopping_list.php" class="kt-bottom-nav-item <?= $active_nav === 'shopping_list' ? 'active' : '' ?>">
            <i class="bi bi-cart3"></i>
            <span>List</span>
        </a>
        <a href="<?= $base ?>/staff/receive.php" class="kt-bottom-nav-item position-relative <?= $active_nav === 'receive' ? 'active' : '' ?>">
            <span class="position-relative d-inline-block">
                <i class="bi bi-box-seam"></i>
                <span class="kt-nav-badge js-receive-badge <?= $pending_delivery_count > 0 ? '' : 'd-none' ?>"><?= $pending_delivery_count ?></span>
            </span>
            <span>Receive</span>
        </a>
        <a href="<?= $base ?>/staff/history.php" class="kt-bottom-nav-item <?= $active_nav === 'history' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i>
            <span>History</span>
        </a>
    </nav>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($extra_js)): ?>
    <?= $extra_js ?>
<?php endif; ?>
</body>
</html>
