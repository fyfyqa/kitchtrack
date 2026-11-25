<?php
/**
 * KitchTrack - Staff Receive Deliveries Page
 * Dedicated page showing ONLY trips currently "Waiting for Delivery Check"
 * (regardless of date so overnight deliveries are never missed).
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('staff');

$user = current_user();
$userId = (int)$user['id'];

// Handle Confirm Received POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_received') {
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    if ($trip_id > 0) {
        try {
            $checkStmt = $pdo->prepare('SELECT shopper_id, status FROM shopping_trips WHERE id = ? LIMIT 1');
            $checkStmt->execute([$trip_id]);
            $tripCheck = $checkStmt->fetch();

            if (!$tripCheck || $tripCheck['status'] !== 'completed') {
                set_flash('warning', 'This delivery has already been confirmed.');
            } elseif ((int)$tripCheck['shopper_id'] === $userId) {
                set_flash('danger', 'Another staff member at the shop must confirm delivery of items you bought.');
            } else {
                $pdo->beginTransaction();
                $updTrip = $pdo->prepare("
                    UPDATE shopping_trips
                    SET status = 'received', received_by = ?, received_at = NOW()
                    WHERE id = ? AND status = 'completed' AND shopper_id != ?
                ");
                $updTrip->execute([$userId, $trip_id, $userId]);

                if ($updTrip->rowCount() > 0) {
                    $updItems = $pdo->prepare("
                        UPDATE shopping_items
                        SET status = 'received', received_by = ?, received_at = NOW()
                        WHERE trip_id = ? AND status = 'bought'
                    ");
                    $updItems->execute([$userId, $trip_id]);
                    set_flash('success', 'Trip #' . $trip_id . ' confirmed as received by ' . $user['full_name'] . '.');
                }
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'Could not confirm delivery. Please try again.');
        }
    }
    header('Location: receive.php');
    exit;
}

// Fetch ONLY trips waiting for delivery check (all dates)
$pendingTrips = $pdo->query("
    SELECT t.*, u_shop.full_name AS shopper_name
    FROM shopping_trips t
    JOIN users u_shop ON u_shop.id = t.shopper_id
    WHERE t.status = 'completed'
    ORDER BY t.completed_at DESC
")->fetchAll();

// Fetch items & receipts for pending trips
$tripItemsMap = [];
$receiptsByTrip = [];
if (!empty($pendingTrips)) {
    $tripIds = array_map(fn($t) => (int)$t['id'], $pendingTrips);
    $ph = implode(',', array_fill(0, count($tripIds), '?'));

    $itemStmt = $pdo->prepare("
        SELECT si.*, c.name AS category_name, u_req.full_name AS requester_name
        FROM shopping_items si
        JOIN categories c ON c.id = si.category_id
        JOIN users u_req ON u_req.id = si.requested_by
        WHERE si.trip_id IN ({$ph})
        ORDER BY c.name ASC, si.id ASC
    ");
    $itemStmt->execute($tripIds);
    foreach ($itemStmt->fetchAll() as $row) {
        $tripItemsMap[(int)$row['trip_id']][] = $row;
    }

    $recStmt = $pdo->prepare("SELECT trip_id, image_path, store_label FROM trip_receipts WHERE trip_id IN ({$ph}) ORDER BY id ASC");
    $recStmt->execute($tripIds);
    foreach ($recStmt->fetchAll() as $rec) {
        $receiptsByTrip[(int)$rec['trip_id']][] = [
            'url'   => '../uploads/' . $rec['image_path'],
            'label' => $rec['store_label'] ?: 'Receipt',
        ];
    }
    foreach ($pendingTrips as $tr) {
        $tid = (int)$tr['id'];
        if (empty($receiptsByTrip[$tid]) && !empty($tr['receipt_image'])) {
            $receiptsByTrip[$tid][] = [
                'url'   => '../uploads/' . $tr['receipt_image'],
                'label' => 'Receipt',
            ];
        }
    }
}

$page_title = 'Receive Deliveries';
$active_nav = 'receive';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <h1 class="h4 fw-bold text-brown-dark mb-1">Waiting for Delivery Check</h1>
    <p class="small text-muted-warm mb-0">Check all purchased items and quantities below before confirming receipt.</p>
</div>

<?php if (empty($pendingTrips)): ?>
    <div class="kt-card p-5 text-center">
        <i class="bi bi-check2-circle fs-1 text-success mb-2 d-block"></i>
        <h2 class="h6 fw-bold text-brown-dark mb-1">All deliveries checked</h2>
        <p class="small text-muted-warm mb-3">There are no pending deliveries waiting for verification right now.</p>
        <a href="history.php" class="btn btn-outline-brown btn-sm">View Trip History</a>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($pendingTrips as $trip): ?>
            <?php
                $tid = (int)$trip['id'];
                $items = $tripItemsMap[$tid] ?? [];
                $tripReceipts = $receiptsByTrip[$tid] ?? [];
                $isBuyer = ((int)$trip['shopper_id'] === $userId);
            ?>
            <div class="kt-card" style="border-left: 4px solid #2E7D32;">
                <div class="kt-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-bold text-brown-dark">
                            Trip #<?= $tid ?> &middot; Bought by <?= e($trip['shopper_name']) ?>
                        </div>
                        <div class="small text-muted-warm">
                            Checked out <?= e(format_datetime($trip['completed_at'])) ?> &middot; Total: <strong><?= e(format_rm($trip['total_spent'])) ?></strong>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($tripReceipts)): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-brown"
                                    data-bs-toggle="modal"
                                    data-bs-target="#receiveReceiptModal"
                                    data-receipts='<?= e(json_encode($tripReceipts)) ?>'
                                    data-trip="Trip #<?= $tid ?>">
                                <i class="bi bi-images me-1"></i> Receipts (<?= count($tripReceipts) ?>)
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-3">
                    <div class="small fw-semibold text-uppercase text-muted-warm mb-2">
                        Purchased Items (<?= count($items) ?>)
                    </div>
                    <?php if (empty($items)): ?>
                        <p class="small text-muted-warm mb-3">No items linked to this trip.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <?php foreach ($items as $item): ?>
                                <div class="d-flex justify-content-between align-items-center p-2 px-3 rounded"
                                     style="background-color:#FAF7F2;border:1px solid #EFE7DE;">
                                    <div>
                                        <div class="fw-semibold text-brown-dark">
                                            <?= e($item['item_name']) ?>
                                            <?php if (!empty($item['store_name'])): ?>
                                                <span class="badge badge-status-pending ms-1">
                                                    <i class="bi bi-shop me-1"></i><?= e($item['store_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted-warm">
                                            <?= e($item['category_name']) ?> &middot; Requested by <?= e($item['requester_name']) ?>
                                        </div>
                                    </div>
                                    <div class="text-end flex-shrink-0 ms-2">
                                        <div class="fw-bold text-brown"><?= e(format_qty($item['quantity'])) ?> <?= e($item['unit']) ?></div>
                                        <div class="small text-muted-warm"><?= e(format_rm($item['price_paid'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="pt-2 border-top d-flex justify-content-end">
                        <?php if (!$isBuyer): ?>
                            <form method="post" action="receive.php" class="w-100 w-sm-auto"
                                  onsubmit="return confirm('Confirm all <?= count($items) ?> items from Trip #<?= $tid ?> have been physically checked and received?');">
                                <input type="hidden" name="action" value="confirm_received">
                                <input type="hidden" name="trip_id" value="<?= $tid ?>">
                                <button type="submit" class="btn btn-success btn-tap w-100 px-4">
                                    <i class="bi bi-check2-circle"></i> Confirm Received
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-status-pending py-2 px-3">
                                <i class="bi bi-hourglass-split me-1"></i>Waiting for another staff member to confirm delivery
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Multi-Receipt Photo Modal -->
<div class="modal fade" id="receiveReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-brown-dark" id="receiveReceiptTitle">Receipt Photos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="receiveReceiptBody"></div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<HTML
<script>
const rrModal = document.getElementById('receiveReceiptModal');
if (rrModal) {
    rrModal.addEventListener('show.bs.modal', function (event) {
        const b = event.relatedTarget;
        const receipts = JSON.parse(b.getAttribute('data-receipts') || '[]');
        document.getElementById('receiveReceiptTitle').textContent = 'Receipt Photos (' + b.getAttribute('data-trip') + ')';
        const body = document.getElementById('receiveReceiptBody');
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
