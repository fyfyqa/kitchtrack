<?php
/**
 * KitchTrack - Owner Staff Management
 * Add new staff, edit details, and toggle Active / Inactive status
 * (Soft-deactivation keeps past purchase logs and receipts intact).
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_staff') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = strtolower(trim($_POST['username'] ?? ''));
        $password  = $_POST['password'] ?? '';

        if ($full_name === '' || $username === '' || strlen($password) < 6) {
            set_flash('danger', 'Please enter full name, username, and a password of at least 6 characters.');
            header('Location: staff.php');
            exit;
        }

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            set_flash('danger', 'Username must be 3-40 characters (letters, numbers, dot, underscore, or hyphen).');
            header('Location: staff.php');
            exit;
        }

        // Check uniqueness
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$username]);
        if ($check->fetch()) {
            set_flash('danger', 'That username is already taken. Please choose another one.');
            header('Location: staff.php');
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, status) VALUES (?, ?, ?, 'staff', 'active')");
        $ins->execute([$full_name, $username, $hash]);
        set_flash('success', 'Staff account created for ' . $full_name . '.');
        header('Location: staff.php');
        exit;
    }

    if ($action === 'edit_staff') {
        $staff_id  = (int)($_POST['staff_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = strtolower(trim($_POST['username'] ?? ''));
        $password  = $_POST['password'] ?? '';

        if ($staff_id <= 0 || $full_name === '' || $username === '') {
            set_flash('danger', 'Full name and username are required.');
            header('Location: staff.php');
            exit;
        }

        if ($password !== '' && strlen($password) < 6) {
            set_flash('danger', 'New password must be at least 6 characters.');
            header('Location: staff.php');
            exit;
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
        $check->execute([$username, $staff_id]);
        if ($check->fetch()) {
            set_flash('danger', 'Another account already uses that username.');
            header('Location: staff.php');
            exit;
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, password_hash = ? WHERE id = ? AND role = 'staff'");
            $upd->execute([$full_name, $username, $hash, $staff_id]);
        } else {
            $upd = $pdo->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ? AND role = 'staff'");
            $upd->execute([$full_name, $username, $staff_id]);
        }

        set_flash('success', 'Updated staff details for ' . $full_name . '.');
        header('Location: staff.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, full_name, status FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
        $stmt->execute([$staff_id]);
        $target = $stmt->fetch();

        if ($target) {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $upd = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
            $upd->execute([$newStatus, $staff_id]);
            $label = $newStatus === 'active' ? 'reactivated' : 'deactivated';
            set_flash('success', $target['full_name'] . ' has been ' . $label . '.');
        }
        header('Location: staff.php');
        exit;
    }
}

$staffList = $pdo->query("
    SELECT u.id, u.full_name, u.username, u.status, u.created_at,
           (SELECT COUNT(*) FROM shopping_trips t WHERE t.shopper_id = u.id) AS trip_count,
           (SELECT COUNT(*) FROM shopping_items i WHERE i.requested_by = u.id) AS request_count
    FROM users u
    WHERE u.role = 'staff'
    ORDER BY u.status ASC, u.full_name ASC
")->fetchAll();

$page_title = 'Manage Staff';
$active_nav = 'staff';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-1">Manage Staff</h1>
        <p class="small text-muted-warm mb-0">Add new staff or deactivate resigned staff without losing purchase history.</p>
    </div>
</div>

<!-- Add Staff Form -->
<div class="kt-card mb-4">
    <div class="kt-card-header">
        <i class="bi bi-person-plus me-2 text-brown"></i>Add New Staff Account
    </div>
    <div class="p-3 p-md-4">
        <form method="post" action="staff.php" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_staff">
            <div class="col-12 col-md-4">
                <label for="full_name" class="form-label small fw-semibold text-brown-dark">Full Name</label>
                <input type="text" name="full_name" id="full_name" class="form-control" placeholder="e.g. Ahmad Faiz" required maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label for="username" class="form-label small fw-semibold text-brown-dark">Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="e.g. faiz" required maxlength="40">
            </div>
            <div class="col-12 col-md-3">
                <label for="password" class="form-label small fw-semibold text-brown-dark">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Min. 6 characters" minlength="6" required>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-brown btn-tap w-100">
                    <i class="bi bi-plus-circle"></i> Add Staff
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Staff List Table -->
<div class="kt-card">
    <div class="kt-card-header">
        <i class="bi bi-people me-2 text-brown"></i>Staff Directory (<?= count($staffList) ?>)
    </div>
    <div class="table-responsive">
        <table class="table kt-table mb-0">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Activity</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr><td colspan="5" class="text-center text-muted-warm py-4">No staff accounts created yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($staffList as $s): ?>
                        <tr>
                            <td class="fw-semibold text-brown-dark"><?= e($s['full_name']) ?></td>
                            <td><code><?= e($s['username']) ?></code></td>
                            <td>
                                <?php if ($s['status'] === 'active'): ?>
                                    <span class="badge badge-status-received">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-status-out">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted-warm">
                                <?= (int)$s['trip_count'] ?> trip<?= ((int)$s['trip_count'] === 1 ? '' : 's') ?> &middot;
                                <?= (int)$s['request_count'] ?> request<?= ((int)$s['request_count'] === 1 ? '' : 's') ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-brown"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editStaffModal"
                                            data-id="<?= (int)$s['id'] ?>"
                                            data-name="<?= e($s['full_name']) ?>"
                                            data-username="<?= e($s['username']) ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <form method="post" action="staff.php" class="d-inline"
                                          onsubmit="return confirm('Are you sure you want to <?= $s['status'] === 'active' ? 'deactivate' : 'reactivate' ?> <?= e($s['full_name']) ?>?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                        <?php if ($s['status'] === 'active'): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-person-slash"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-person-check"></i> Activate
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Staff Modal -->
<div class="modal fade" id="editStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="staff.php">
                <input type="hidden" name="action" value="edit_staff">
                <input type="hidden" name="staff_id" id="edit_staff_id">
                <div class="modal-header">
                    <h5 class="modal-title text-brown-dark">Edit Staff Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label for="edit_username" class="form-label small fw-semibold">Username</label>
                        <input type="text" name="username" id="edit_username" class="form-control" required maxlength="40">
                    </div>
                    <div class="mb-0">
                        <label for="edit_password" class="form-label small fw-semibold">New Password (Optional)</label>
                        <input type="password" name="password" id="edit_password" class="form-control" placeholder="Leave blank to keep current password" minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-brown" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brown">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<HTML
<script>
const editModal = document.getElementById('editStaffModal');
if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        document.getElementById('edit_staff_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_full_name').value = btn.getAttribute('data-name');
        document.getElementById('edit_username').value = btn.getAttribute('data-username');
        document.getElementById('edit_password').value = '';
    });
}
</script>
HTML;

require_once __DIR__ . '/../includes/footer.php';
?>
