<?php
/**
 * KitchTrack - Entry Point
 * Redirects users to their role-specific home page, or to login.php.
 */

require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user) {
    if ($user['role'] === 'owner') {
        header('Location: owner/dashboard.php');
    } else {
        header('Location: staff/shopping_list.php');
    }
    exit;
}

header('Location: login.php');
exit;
