<?php
/**
 * KitchTrack - Staff Live Shopping List JSON API
 * Powers 5-second auto-refresh and interactive shopping actions via fetch().
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('staff', true);

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$userId = (int)$user['id'];

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    try {
        if ($action === 'add_item') {
            $item_name   = trim($input['item_name'] ?? '');
            $quantity    = (float)($input['quantity'] ?? 0);
            $unit        = trim($input['unit'] ?? 'pcs');
            $category_id = (int)($input['category_id'] ?? 0);
            $is_urgent   = !empty($input['is_urgent']) ? 1 : 0;

            if ($item_name === '' || $quantity <= 0 || $unit === '' || $category_id <= 0) {
                json_response(['ok' => false, 'error' => 'Please enter item name, valid quantity, unit, and store category.'], 400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO shopping_items (item_name, quantity, unit, category_id, is_urgent, status, requested_by, requested_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            $stmt->execute([$item_name, $quantity, $unit, $category_id, $is_urgent, $userId]);
            json_response(['ok' => true, 'message' => 'Item added to shopping list.']);
        }

        if ($action === 'quick_add') {
            $template_id = (int)($input['template_id'] ?? 0);
            $is_urgent   = !empty($input['is_urgent']) ? 1 : 0;

            $tplStmt = $pdo->prepare('SELECT * FROM essential_items WHERE id = ? LIMIT 1');
            $tplStmt->execute([$template_id]);
            $tpl = $tplStmt->fetch();

            if (!$tpl) {
                json_response(['ok' => false, 'error' => 'Essential item template not found.'], 404);
            }

            $stmt = $pdo->prepare("
                INSERT INTO shopping_items (item_name, quantity, unit, category_id, is_urgent, status, requested_by, requested_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            $stmt->execute([
                $tpl['item_name'],
                $tpl['default_qty'],
                $tpl['default_unit'],
                $tpl['category_id'],
                $is_urgent,
                $userId,
            ]);
            json_response(['ok' => true, 'message' => $tpl['item_name'] . ' added to shopping list.']);
        }

        if ($action === 'delete_item') {
            $item_id = (int)($_POST['item_id'] ?? $input['item_id'] ?? 0);
            if ($item_id <= 0) {
                json_response(['ok' => false, 'error' => 'Invalid item ID.'], 400);
            }

            $checkStmt = $pdo->prepare('SELECT id, requested_by, status FROM shopping_items WHERE id = ? LIMIT 1');
            $checkStmt->execute([$item_id]);
            $targetItem = $checkStmt->fetch();

            if (!$targetItem) {
                json_response(['ok' => false, 'error' => 'Item not found.'], 404);
            }

            // Block deletion once an item is already In Cart or already bought/received
            if ($targetItem['status'] === 'in_cart' || !in_array($targetItem['status'], ['pending', 'out_of_stock'], true)) {
                json_response(['ok' => false, 'error' => 'Items already in the cart cannot be removed.'], 400);
            }

            // Only the original requester or the Owner may remove this item
            if ((int)$targetItem['requested_by'] !== $userId && $user['role'] !== 'owner') {
                json_response(['ok' => false, 'error' => 'Permission denied. You can only remove items that you requested.'], 403);
            }

            $stmt = $pdo->prepare("DELETE FROM shopping_items WHERE id = ? AND status IN ('pending', 'out_of_stock')");
            $stmt->execute([$item_id]);
            json_response(['ok' => true, 'message' => 'Item removed.']);
        }

        if ($action === 'start_trip') {
            // Check if anyone already holds the trip lock
            $lockStmt = $pdo->query("
                SELECT t.id, u.full_name AS shopper_name
                FROM shopping_trips t
                JOIN users u ON u.id = t.shopper_id
                WHERE t.status = 'shopping'
                LIMIT 1
            ");
            $existing = $lockStmt->fetch();

            if ($existing) {
                json_response([
                    'ok' => false,
                    'error' => 'Trip is already claimed by ' . $existing['shopper_name'] . '.',
                ], 409);
            }

            // If owner already handed petty cash ('open') to this staff member, activate that trip
            $openStmt = $pdo->prepare("
                SELECT id FROM shopping_trips
                WHERE shopper_id = ? AND status = 'open'
                ORDER BY created_at ASC
                LIMIT 1
            ");
            $openStmt->execute([$userId]);
            $openTrip = $openStmt->fetch();

            if ($openTrip) {
                $upd = $pdo->prepare("UPDATE shopping_trips SET status = 'shopping', started_at = NOW() WHERE id = ?");
                $upd->execute([$openTrip['id']]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO shopping_trips (shopper_id, status, cash_given, started_at, created_by)
                    VALUES (?, 'shopping', 0.00, NOW(), ?)
                ");
                $ins->execute([$userId, $userId]);
            }

            json_response(['ok' => true, 'message' => 'Shopping trip started. Other staff can now see you are shopping.']);
        }

        if ($action === 'cancel_trip') {
            $tripStmt = $pdo->prepare("SELECT id, cash_given FROM shopping_trips WHERE shopper_id = ? AND status = 'shopping' LIMIT 1");
            $tripStmt->execute([$userId]);
            $myTrip = $tripStmt->fetch();

            if (!$myTrip) {
                json_response(['ok' => false, 'error' => 'No active shopping trip found for your account.'], 404);
            }

            $pdo->beginTransaction();
            // Reset any in_cart items back to pending
            $pdo->exec("UPDATE shopping_items SET status = 'pending' WHERE status = 'in_cart'");

            if ((float)$myTrip['cash_given'] > 0) {
                // Keep petty cash record open
                $upd = $pdo->prepare("UPDATE shopping_trips SET status = 'open', started_at = NULL WHERE id = ?");
                $upd->execute([$myTrip['id']]);
            } else {
                $del = $pdo->prepare('DELETE FROM shopping_trips WHERE id = ?');
                $del->execute([$myTrip['id']]);
            }
            $pdo->commit();

            json_response(['ok' => true, 'message' => 'Shopping trip released.']);
        }

        if ($action === 'update_status') {
            $item_id = (int)($input['item_id'] ?? 0);
            $status  = $input['status'] ?? '';

            if (!in_array($status, ['pending', 'in_cart', 'out_of_stock'], true)) {
                json_response(['ok' => false, 'error' => 'Invalid item status.'], 400);
            }

            // Only the active shopper can update shopping statuses
            $tripStmt = $pdo->prepare("SELECT id FROM shopping_trips WHERE shopper_id = ? AND status = 'shopping' LIMIT 1");
            $tripStmt->execute([$userId]);
            if (!$tripStmt->fetch()) {
                json_response(['ok' => false, 'error' => 'Please tap "Start Shopping" first to update item statuses.'], 403);
            }

            $upd = $pdo->prepare("UPDATE shopping_items SET status = ? WHERE id = ? AND status IN ('pending', 'in_cart', 'out_of_stock')");
            $upd->execute([$status, $item_id]);
            json_response(['ok' => true]);
        }

        if ($action === 'confirm_received') {
            $trip_id = (int)($input['trip_id'] ?? 0);
            if ($trip_id <= 0) {
                json_response(['ok' => false, 'error' => 'Invalid trip ID.'], 400);
            }

            $tripCheck = $pdo->prepare('SELECT id, shopper_id, status FROM shopping_trips WHERE id = ? LIMIT 1');
            $tripCheck->execute([$trip_id]);
            $tripRow = $tripCheck->fetch();

            if (!$tripRow || $tripRow['status'] !== 'completed') {
                json_response(['ok' => false, 'error' => 'This trip was already confirmed as received.'], 409);
            }

            // The staff member who bought the items cannot confirm delivery themselves
            if ((int)$tripRow['shopper_id'] === $userId) {
                json_response(['ok' => false, 'error' => 'Another staff member at the shop must confirm delivery of items you bought.'], 403);
            }

            $pdo->beginTransaction();
            $updTrip = $pdo->prepare("
                UPDATE shopping_trips
                SET status = 'received', received_by = ?, received_at = NOW()
                WHERE id = ? AND status = 'completed' AND shopper_id != ?
            ");
            $updTrip->execute([$userId, $trip_id, $userId]);

            if ($updTrip->rowCount() === 0) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'Unable to confirm delivery for this trip.'], 409);
            }

            $updItems = $pdo->prepare("
                UPDATE shopping_items
                SET status = 'received', received_by = ?, received_at = NOW()
                WHERE trip_id = ? AND status = 'bought'
            ");
            $updItems->execute([$userId, $trip_id]);
            $pdo->commit();

            json_response(['ok' => true, 'message' => 'Delivery confirmed received at the shop.']);
        }

        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Action failed. Please try again.'], 500);
    }
}

// ============================================================
// GET: Return current live shopping list state
// ============================================================

// 1. Active shopping trip (Trip Lock)
$activeTripRow = $pdo->query("
    SELECT t.id, t.shopper_id, t.cash_given, t.started_at, u.full_name AS shopper_name
    FROM shopping_trips t
    JOIN users u ON u.id = t.shopper_id
    WHERE t.status = 'shopping'
    LIMIT 1
")->fetch();

$activeTrip = null;
if ($activeTripRow) {
    $activeTrip = [
        'id'           => (int)$activeTripRow['id'],
        'shopper_id'   => (int)$activeTripRow['shopper_id'],
        'shopper_name' => $activeTripRow['shopper_name'],
        'cash_given'   => (float)$activeTripRow['cash_given'],
        'started_at'   => format_time($activeTripRow['started_at']),
        'is_mine'      => ((int)$activeTripRow['shopper_id'] === $userId),
    ];
}

// 2. Does current staff have pre-issued petty cash waiting?
$openCashStmt = $pdo->prepare("
    SELECT id, cash_given, notes
    FROM shopping_trips
    WHERE shopper_id = ? AND status = 'open'
    ORDER BY created_at ASC
    LIMIT 1
");
$openCashStmt->execute([$userId]);
$myOpenCash = $openCashStmt->fetch() ?: null;

// 3. Active shopping list items
$itemsStmt = $pdo->query("
    SELECT si.id, si.item_name, si.quantity, si.unit, si.category_id,
           si.is_urgent, si.status, si.requested_by, si.requested_at,
           c.name AS category_name,
           u.full_name AS requested_by_name
    FROM shopping_items si
    JOIN categories c ON c.id = si.category_id
    JOIN users u ON u.id = si.requested_by
    WHERE si.status IN ('pending', 'in_cart', 'out_of_stock')
    ORDER BY si.is_urgent DESC, c.name ASC, si.requested_at ASC
");

$items = [];
foreach ($itemsStmt->fetchAll() as $row) {
    $isOwnerOrRequester = ((int)$row['requested_by'] === $userId || $user['role'] === 'owner');
    $items[] = [
        'id'                => (int)$row['id'],
        'item_name'         => $row['item_name'],
        'quantity'          => format_qty($row['quantity']),
        'unit'              => $row['unit'],
        'category_id'       => (int)$row['category_id'],
        'category_name'     => $row['category_name'],
        'is_urgent'         => (bool)$row['is_urgent'],
        'status'            => $row['status'],
        'requested_by'      => (int)$row['requested_by'],
        'can_delete'        => ($isOwnerOrRequester && $row['status'] !== 'in_cart'),
        'requested_by_name' => $row['requested_by_name'],
        'requested_time'    => format_time($row['requested_at']),
    ];
}

// 4. Completed trips awaiting "Confirm Received" (Waiting for Delivery Check)
$pendingReceiveStmt = $pdo->query("
    SELECT t.id, t.shopper_id, t.total_spent, t.completed_at, u.full_name AS shopper_name
    FROM shopping_trips t
    JOIN users u ON u.id = t.shopper_id
    WHERE t.status = 'completed'
    ORDER BY t.completed_at DESC
");

$pendingRows = $pendingReceiveStmt->fetchAll();
$pendingItemsByTrip = [];
if (!empty($pendingRows)) {
    $pIds = array_map(fn($r) => (int)$r['id'], $pendingRows);
    $ph = implode(',', array_fill(0, count($pIds), '?'));
    $pItemStmt = $pdo->prepare("
        SELECT trip_id, item_name, quantity, unit, store_name, price_paid
        FROM shopping_items
        WHERE trip_id IN ({$ph})
        ORDER BY id ASC
    ");
    $pItemStmt->execute($pIds);
    foreach ($pItemStmt->fetchAll() as $pi) {
        $pendingItemsByTrip[(int)$pi['trip_id']][] = [
            'item_name'  => $pi['item_name'],
            'quantity'   => format_qty($pi['quantity']),
            'unit'       => $pi['unit'],
            'store_name' => $pi['store_name'],
            'price_paid' => format_rm($pi['price_paid']),
        ];
    }
}

$pendingReceive = [];
foreach ($pendingRows as $tr) {
    $tid = (int)$tr['id'];
    $tItems = $pendingItemsByTrip[$tid] ?? [];
    $pendingReceive[] = [
        'id'                   => $tid,
        'shopper_id'           => (int)$tr['shopper_id'],
        'shopper_name'         => $tr['shopper_name'],
        'can_confirm_received' => ((int)$tr['shopper_id'] !== $userId),
        'total_spent'          => format_rm($tr['total_spent']),
        'completed_time'       => format_time($tr['completed_at']),
        'item_count'           => count($tItems),
        'items'                => $tItems,
    ];
}

json_response([
    'ok'                    => true,
    'current_user'          => $user,
    'active_trip'           => $activeTrip,
    'my_open_cash'          => $myOpenCash,
    'items'                 => $items,
    'pending_receive_trips' => $pendingReceive,
    'server_time'           => date('g:i:s A'),
]);
