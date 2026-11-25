<?php
/**
 * KitchTrack - Owner Petty Cash Management
 * Record cash handed to staff before a trip, inspect receipt photos,
 * review auto-calculated change, and confirm change returned.
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

$owner = current_user();

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'give_cash') {
        $staff_id       = (int)($_POST['staff_id'] ?? 0);
        $cash_given     = (float)($_POST['cash_given'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $notes          = trim($_POST['notes'] ?? '');

        if (!in_array($payment_method, ['Cash', 'Online Transfer / QR'], true)) {
            $payment_method = 'Cash';
        }

        if ($staff_id <= 0 || $cash_given <= 0) {
            set_flash('danger', 'Please select a staff member and enter a valid amount.');
            header('Location: petty_cash.php');
            exit;
        }

        // Verify staff exists and is active
        $staffStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'staff' AND status = 'active'");
        $staffStmt->execute([$staff_id]);
        $staffUser = $staffStmt->fetch();

        if (!$staffUser) {
            set_flash('danger', 'Selected staff member is not active.');
            header('Location: petty_cash.php');
            exit;
        }

        // If this staff member already has an active shopping trip, attach budget to it
        $activeStmt = $pdo->prepare("SELECT id, cash_given FROM shopping_trips WHERE shopper_id = ? AND status = 'shopping' LIMIT 1");
        $activeStmt->execute([$staff_id]);
        $activeTrip = $activeStmt->fetch();

        if ($activeTrip) {
            $upd = $pdo->prepare("UPDATE shopping_trips SET cash_given = cash_given + ?, payment_method = ?, notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?");
            $upd->execute([$cash_given, $payment_method, $notes, $activeTrip['id']]);
            set_flash('success', 'Added ' . format_rm($cash_given) . ' (' . $payment_method . ') to ' . $staffUser['full_name'] . '\'s active trip.');
        } else {
            $ins = $pdo->prepare("
                INSERT INTO shopping_trips (shopper_id, status, cash_given, payment_method, notes, created_by)
                VALUES (?, 'open', ?, ?, ?, ?)
            ");
            $ins->execute([$staff_id, $cash_given, $payment_method, $notes !== '' ? $notes : null, $owner['id']]);
            set_flash('success', 'Recorded ' . format_rm($cash_given) . ' (' . $payment_method . ') for ' . $staffUser['full_name'] . '.');
        }

        header('Location: petty_cash.php');
        exit;
    }

    if ($action === 'confirm_change') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        if ($trip_id > 0) {
            $upd = $pdo->prepare("
                UPDATE shopping_trips
                SET change_returned = 1,
                    change_confirmed_by = ?,
                    change_confirmed_at = NOW()
                WHERE id = ? AND status IN ('completed', 'received')
            ");
            $upd->execute([$owner['id'], $trip_id]);
            set_flash('success', 'Change return confirmed. Trip #' . $trip_id . ' is now closed.');
        }
        header('Location: petty_cash.php');
        exit;
    }
}

// Active staff list for dropdown
$activeStaff = $pdo->query("
    SELECT id, full_name FROM users WHERE role = 'staff' AND status = 'active' ORDER BY full_name ASC
")->fetchAll();

// Status & Date Filters (Defaults to Today's records unless ?show=all or ?filter=pending is selected)
$filter = $_GET['filter'] ?? 'all';
$today  = date('Y-m-d');
$showAll = (isset($_GET['show']) && $_GET['show'] === 'all');
$selectedDate = $today;
if (!$showAll && !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $selectedDate = $_GET['date'];
}

$conditions = [];
$params = [];

if ($filter === 'pending') {
    $conditions[] = "(t.status IN ('open', 'shopping') OR (t.status IN ('completed', 'received') AND t.change_returned = 0 AND t.cash_given != t.total_spent))";
} elseif ($filter === 'closed') {
    $conditions[] = "(t.status IN ('completed', 'received') AND (t.change_returned = 1 OR t.cash_given = t.total_spent))";
}

if (!$showAll) {
    $conditions[] = "DATE(COALESCE(t.completed_at, t.created_at)) = ?";
    $params[] = $selectedDate;
}

$whereSql = !empty($conditions) ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$tripStmt = $pdo->prepare("
    SELECT t.*,
           u_shop.full_name AS shopper_name,
           u_rec.full_name AS receiver_name,
           u_conf.full_name AS confirmer_name
    FROM shopping_trips t
    JOIN users u_shop ON u_shop.id = t.shopper_id
    LEFT JOIN users u_rec ON u_rec.id = t.received_by
    LEFT JOIN users u_conf ON u_conf.id = t.change_confirmed_by
    {$whereSql}
    ORDER BY t.created_at DESC
");
$tripStmt->execute($params);
$trips = $tripStmt->fetchAll();

// Fetch all receipt photos grouped by trip_id
$receiptsByTrip = [];
if (!empty($trips)) {
    $tripIds = array_map(fn($t) => (int)$t['id'], $trips);
    $ph = implode(',', array_fill(0, count($tripIds), '?'));
    $recStmt = $pdo->prepare("SELECT trip_id, image_path, store_label FROM trip_receipts WHERE trip_id IN ({$ph}) ORDER BY id ASC");
    $recStmt->execute($tripIds);
    foreach ($recStmt->fetchAll() as $rec) {
        $receiptsByTrip[(int)$rec['trip_id']][] = [
            'url'   => '../uploads/' . $rec['image_path'],
            'label' => $rec['store_label'] ?: 'Receipt',
        ];
    }
    // Fallback for any legacy trip that only has shopping_trips.receipt_image
    foreach ($trips as $tr) {
        $tid = (int)$tr['id'];
        if (empty($receiptsByTrip[$tid]) && !empty($tr['receipt_image'])) {
            $receiptsByTrip[$tid][] = [
                'url'   => '../uploads/' . $tr['receipt_image'],
                'label' => 'Receipt',
            ];
        }
    }
}

$page_title = 'Petty Cash Management';
$active_nav = 'petty_cash';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-1">Petty Cash Management</h1>
        <p class="small text-muted-warm mb-0">Issue cash before a trip, review receipt photos, and confirm returned change.</p>
    </div>
</div>

<!-- Give Petty Cash Card -->
<div class="kt-card mb-4">
    <div class="kt-card-header">
        <i class="bi bi-cash-coin me-2 text-brown"></i>Record Cash / Budget Given to Staff
    </div>
    <div class="p-3 p-md-4">
        <form method="post" action="petty_cash.php" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="give_cash">
            <div class="col-12 col-md-3">
                <label for="staff_id" class="form-label small fw-semibold text-brown-dark">Staff Member</label>
                <select name="staff_id" id="staff_id" class="form-select" required>
                    <option value="">Select staff...</option>
                    <?php foreach ($activeStaff as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label for="cash_given" class="form-label small fw-semibold text-brown-dark">Amount (RM)</label>
                <input type="number" step="0.01" min="1" name="cash_given" id="cash_given" class="form-control" placeholder="e.g. 200.00" required>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="payment_method" class="form-label small fw-semibold text-brown-dark">Payment Method</label>
                <select name="payment_method" id="payment_method" class="form-select" required>
                    <option value="Cash">Cash</option>
                    <option value="Online Transfer / QR">Online Transfer / QR</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label for="notes" class="form-label small fw-semibold text-brown-dark">Notes (Optional)</label>
                <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Market run" maxlength="200">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-brown btn-tap w-100">
                    <i class="bi bi-plus-circle"></i> Give Budget
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Status & Date Filter Bar -->
<?php $dateQuery = $showAll ? '&show=all' : ('&date=' . urlencode($selectedDate)); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="petty_cash.php?filter=all<?= $dateQuery ?>" class="btn btn-sm <?= $filter === 'all' ? 'btn-brown' : 'btn-outline-brown' ?>">All Trips</a>
        <a href="petty_cash.php?filter=pending<?= $dateQuery ?>" class="btn btn-sm <?= $filter === 'pending' ? 'btn-brown' : 'btn-outline-brown' ?>">Open &amp; Pending Change</a>
        <a href="petty_cash.php?filter=closed<?= $dateQuery ?>" class="btn btn-sm <?= $filter === 'closed' ? 'btn-brown' : 'btn-outline-brown' ?>">Settled / Closed</a>
    </div>

    <form method="get" action="petty_cash.php" class="d-flex flex-wrap align-items-center gap-2">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input type="date" name="date" class="form-control form-control-sm" style="width: auto;" value="<?= e($selectedDate) ?>" aria-label="Filter by date">
        <button type="submit" class="btn btn-sm btn-brown">
            <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <a href="petty_cash.php?filter=<?= e($filter) ?>&show=all" class="btn btn-sm <?= $showAll ? 'btn-soft-cream' : 'btn-outline-brown' ?>">
            Show All
        </a>
    </form>
</div>

<!-- Trips & Petty Cash Table -->
<div class="kt-card">
    <div class="table-responsive">
        <table class="table kt-table mb-0">
            <thead>
                <tr>
                    <th>Trip</th>
                    <th>Staff</th>
                    <th>Status</th>
                    <th>Cash Given</th>
                    <th>Total Spent</th>
                    <th>Change / Balance</th>
                    <th>Receipt</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted-warm py-5">
                            <div class="fw-semibold text-brown-dark mb-1">No completed trips or petty cash records for this date.</div>
                            <?php if (!$showAll): ?>
                                <div class="small mb-2">Showing <?= e(date('d M Y', strtotime($selectedDate))) ?><?= $selectedDate === $today ? ' (Today)' : '' ?>.</div>
                                <a href="petty_cash.php?filter=<?= e($filter) ?>&show=all" class="btn btn-sm btn-outline-brown">Show All Records</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trips as $trip): ?>
                        <?php
                            $cashGiven  = (float)$trip['cash_given'];
                            $totalSpent = (float)$trip['total_spent'];
                            $diff       = $cashGiven - $totalSpent;
                            $isFinished = in_array($trip['status'], ['completed', 'received'], true);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-brown-dark">#<?= (int)$trip['id'] ?></div>
                                <div class="small text-muted-warm"><?= e(format_datetime($trip['completed_at'] ?? $trip['created_at'])) ?></div>
                                <?php if (!empty($trip['notes'])): ?>
                                    <div class="small text-muted-warm fst-italic"><?= e($trip['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= e($trip['shopper_name']) ?></td>
                            <td>
                                <?php if ($trip['status'] === 'open'): ?>
                                    <span class="badge badge-status-pending">Cash Issued</span>
                                <?php elseif ($trip['status'] === 'shopping'): ?>
                                    <span class="badge bg-warning text-dark">Shopping Now</span>
                                <?php elseif ($trip['status'] === 'completed'): ?>
                                    <span class="badge badge-status-pending">Checked Out</span>
                                <?php else: ?>
                                    <span class="badge badge-status-received">Received</span>
                                    <div class="small text-muted-warm mt-1">by <?= e($trip['receiver_name'] ?? 'Staff') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e(format_rm($cashGiven)) ?></div>
                                <?php if ($cashGiven > 0): ?>
                                    <?php $method = $trip['payment_method'] ?? 'Cash'; ?>
                                    <span class="badge badge-status-pending mt-1">
                                        <i class="bi <?= $method === 'Online Transfer / QR' ? 'bi-qr-code-scan' : 'bi-cash' ?> me-1"></i><?= e($method) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= $isFinished ? e(format_rm($totalSpent)) : '-' ?></td>
                            <td>
                                <?php if (!$isFinished): ?>
                                    <span class="text-muted-warm small">Waiting for checkout</span>
                                <?php elseif ($diff > 0.001): ?>
                                    <div class="fw-bold text-brown-dark"><?= e(format_rm($diff)) ?></div>
                                    <div class="small text-muted-warm">Change to return</div>
                                <?php elseif ($diff < -0.001): ?>
                                    <div class="fw-bold text-danger"><?= e(format_rm(abs($diff))) ?></div>
                                    <div class="small text-danger">Reimburse staff</div>
                                <?php else: ?>
                                    <span class="badge badge-status-received">Exact Amount</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $tripReceipts = $receiptsByTrip[(int)$trip['id']] ?? []; ?>
                                <?php if (!empty($tripReceipts)): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="<?= e($tripReceipts[0]['url']) ?>"
                                             alt="Receipt for Trip #<?= (int)$trip['id'] ?>"
                                             class="kt-receipt-thumb"
                                             data-bs-toggle="modal"
                                             data-bs-target="#receiptModal"
                                             data-receipts='<?= e(json_encode($tripReceipts)) ?>'
                                             data-trip="#<?= (int)$trip['id'] ?> - <?= e($trip['shopper_name']) ?>">
                                        <?php if (count($tripReceipts) > 1): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-brown py-0 px-2"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#receiptModal"
                                                    data-receipts='<?= e(json_encode($tripReceipts)) ?>'
                                                    data-trip="#<?= (int)$trip['id'] ?> - <?= e($trip['shopper_name']) ?>">
                                                +<?= count($tripReceipts) - 1 ?> more
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="small text-muted-warm">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($isFinished && !$trip['change_returned'] && abs($diff) > 0.001): ?>
                                    <form method="post" action="petty_cash.php" class="d-inline"
                                          onsubmit="return confirm('Confirm that petty cash balance for Trip #<?= (int)$trip['id'] ?> is settled?');">
                                        <input type="hidden" name="action" value="confirm_change">
                                        <input type="hidden" name="trip_id" value="<?= (int)$trip['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-brown">
                                            <i class="bi bi-check2-circle me-1"></i>
                                            <?= $diff > 0 ? 'Confirm Change Returned' : 'Confirm Reimbursed' ?>
                                        </button>
                                    </form>
                                <?php elseif ($isFinished): ?>
                                    <span class="badge badge-status-received">
                                        <i class="bi bi-check-circle me-1"></i>Closed
                                    </span>
                                    <?php if ($trip['change_confirmed_at']): ?>
                                        <div class="small text-muted-warm mt-1"><?= e(format_time($trip['change_confirmed_at'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="small text-muted-warm">In progress</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Multi-Receipt Gallery Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-brown-dark" id="receiptModalTitle">Receipt Photos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="receiptModalBody"></div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<HTML
<script>
const receiptModal = document.getElementById('receiptModal');
if (receiptModal) {
    receiptModal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        const receipts = JSON.parse(trigger.getAttribute('data-receipts') || '[]');
        document.getElementById('receiptModalTitle').textContent = 'Receipt Photos (' + trigger.getAttribute('data-trip') + ')';
        const body = document.getElementById('receiptModalBody');
        body.innerHTML = '';
        receipts.forEach((rec, idx) => {
            const card = document.createElement('div');
            card.className = 'mb-3 p-3 bg-white rounded border text-center';
            const caption = document.createElement('div');
            caption.className = 'small fw-semibold text-brown-dark mb-2';
            caption.textContent = 'Receipt ' + (idx + 1) + (rec.label && rec.label !== 'Receipt' ? ' — ' + rec.label : '');
            const img = document.createElement('img');
            img.src = rec.url;
            img.alt = caption.textContent;
            img.className = 'img-fluid rounded';
            img.style.maxHeight = '70vh';
            card.appendChild(caption);
            card.appendChild(img);
            body.appendChild(card);
        });
    });
}
</script>
HTML;

require_once __DIR__ . '/../includes/footer.php';
?>
