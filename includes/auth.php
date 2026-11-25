<?php
/**
 * KitchTrack - Session, Role Access Control & Helper Functions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/**
 * Determine base URL path relative to current script (e.g., '' from root, '..' from owner/ or staff/)
 */
function base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (str_contains($script, '/owner/') || str_contains($script, '/staff/')) {
        return '..';
    }
    return '.';
}

/**
 * HTML-escape output safely
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Flash message helpers
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type, // success, danger, warning, info
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Get current logged-in user array or null
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Ensure the user is logged in and their account is still Active in the database.
 */
function require_login(bool $is_api = false): void
{
    global $pdo;

    if (empty($_SESSION['user']['id'])) {
        if ($is_api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Session expired. Please log in again.']);
            exit;
        }
        header('Location: ' . base_path() . '/login.php');
        exit;
    }

    // Re-check user status from database so deactivated staff are immediately blocked
    $stmt = $pdo->prepare('SELECT id, full_name, username, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        session_start();
        if ($is_api) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Your account is inactive. Please contact the owner.']);
            exit;
        }
        set_flash('danger', 'Your account is inactive. Please contact the owner.');
        header('Location: ' . base_path() . '/login.php');
        exit;
    }

    // Refresh session fields in case full_name was edited
    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'role'      => $user['role'],
        'status'    => $user['status'],
    ];
}

/**
 * Enforce strict role separation between 'owner' and 'staff'.
 */
function require_role(string $expected_role, bool $is_api = false): void
{
    require_login($is_api);

    $user = current_user();
    if ($user['role'] !== $expected_role) {
        if ($is_api) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Access denied for your account role.']);
            exit;
        }

        if ($user['role'] === 'owner') {
            set_flash('warning', 'You are logged in as Owner. Redirected to the Owner Dashboard.');
            header('Location: ' . base_path() . '/owner/dashboard.php');
        } else {
            set_flash('warning', 'You do not have permission to access Owner pages.');
            header('Location: ' . base_path() . '/staff/shopping_list.php');
        }
        exit;
    }
}

/**
 * Format currency in RM
 */
function format_rm(float|int|string|null $amount): string
{
    if ($amount === null || $amount === '') {
        return 'RM 0.00';
    }
    return 'RM ' . number_format((float)$amount, 2);
}

/**
 * Format quantity neatly (e.g., 5.00 -> 5, 2.50 -> 2.5)
 */
function format_qty(float|int|string $qty): string
{
    $num = (float)$qty;
    if (floor($num) == $num) {
        return (string)(int)$num;
    }
    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

/**
 * Format time as e.g. "9:15 AM" or "Nov 25, 9:15 AM"
 */
function format_time(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '-';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }
    return date('d M, g:i A', $ts);
}

function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    return $ts ? date('d M Y, g:i A', $ts) : '-';
}
