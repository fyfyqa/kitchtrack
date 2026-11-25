<?php
/**
 * KitchTrack - Login Page
 * Verifies password with password_verify() and blocks Inactive accounts.
 */

require_once __DIR__ . '/includes/auth.php';

// Already logged in? Send straight to their role home page
$existing = current_user();
if ($existing) {
    header('Location: ' . ($existing['role'] === 'owner' ? 'owner/dashboard.php' : 'staff/shopping_list.php'));
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, full_name, username, password_hash, role, status FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect username or password. Please try again.';
        } elseif ($user['status'] !== 'active') {
            $error = 'Your account is inactive. Please contact the owner.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'        => (int)$user['id'],
                'full_name' => $user['full_name'],
                'username'  => $user['username'],
                'role'      => $user['role'],
                'status'    => $user['status'],
            ];

            if ($user['role'] === 'owner') {
                header('Location: owner/dashboard.php');
            } else {
                header('Location: staff/shopping_list.php');
            }
            exit;
        }
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6F4E37">
    <title>Log In | KitchTrack</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="assets/icons/icon-192.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="kt-login-wrap">
    <div class="kt-login-card">
        <div class="text-center mb-4">
            <div class="kt-brand-mark mx-auto mb-3" style="width:52px;height:52px;font-size:1.5rem;border-radius:14px;">
                <i class="bi bi-basket2-fill"></i>
            </div>
            <h1 class="h4 fw-bold text-brown-dark mb-1">KitchTrack</h1>
            <p class="small text-muted-warm mb-0">Restaurant Restock &amp; Petty Cash System</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> py-2 small" role="alert">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <div class="mb-3">
                <label for="username" class="form-label small fw-semibold text-brown-dark">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted-warm">
                        <i class="bi bi-person"></i>
                    </span>
                    <input type="text"
                           class="form-control border-start-0"
                           id="username"
                           name="username"
                           value="<?= e($username) ?>"
                           placeholder="Enter username"
                           autocomplete="username"
                           required
                           autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label small fw-semibold text-brown-dark">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted-warm">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input type="password"
                           class="form-control border-start-0"
                           id="password"
                           name="password"
                           placeholder="Enter password"
                           autocomplete="current-password"
                           required>
                </div>
            </div>

            <button type="submit" class="btn btn-brown btn-tap w-100">
                <i class="bi bi-box-arrow-in-right"></i>
                <span>Log In</span>
            </button>
        </form>

        <div class="text-center mt-4 pt-3 border-top">
            <small class="text-muted-warm">Internal restaurant use only. Contact the owner if you cannot log in.</small>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
