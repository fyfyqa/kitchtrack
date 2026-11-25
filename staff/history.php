<?php
/**
 * KitchTrack - Staff Trip History & Receipts
 * Shows Today's completed/past trips by default, with Date Picker & Show All filter.
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('staff');

$user = current_user();
$userId = (int)$user['id'];

// Date filter: default to Today (CURDATE()) unless ?show=all or a specific ?date=YYYY-MM-DD is chosen
$showAll = (isset($_GET['show']) && $_GET['show'] === 'all');
$today = date('Y-m-d');
$selectedDate = $today;
if (!$showAll && !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $selectedDate = $_GET['date'];
}

if ($showAll) {
    $tripStmt = $pdo->query("
        SELECT t.*,
               u_shop.full_name AS shopper_name,
               u_rec.full_name AS receiver_name
        FROM shopping_trips t
        JOIN users u_shop ON u_shop.id = t.shopper_id
        LEFT JOIN users u_rec ON u_rec.id = t.received_by
        WHERE t.status IN ('completed', 'received')
        ORDER BY t.completed_at DESC
        LIMIT 50
    ");
    $trips = $tripStmt->fetchAll();
} else {
    $tripStmt = $pdo->prepare("
        SELECT t.*,
               u_shop.full_name AS shopper_name,
               u_rec.full_name AS receiver_name
        FROM shopping_trips t
        JOIN users u_shop ON u_shop.id = t.shopper_id
        LEFT JOIN users u_rec ON u_rec.id = t.received_by
        WHERE t.status IN ('completed', 'received')
          AND DATE(t.completed_at) = ?
        ORDER BY t.completed_at DESC
    ");
    $tripStmt->execute([$selectedDate]);
    $trips = $tripStmt->fetchAll();
}

// Fetch items & receipt photos for these trips
$tripItemsMap = [];
$receiptsByTrip = [];
if (!empty($trips)) {
    $tripIds = array_map(fn($t) => (int)$t['id'], $trips);
    $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT si.*,
               c.name AS category_name,
               u_req.full_name AS requester_name,
               u_buy.full_name AS buyer_name,
               u_rec.full_name AS receiver_name
        FROM shopping_items si
        JOIN categories c ON c.id = si.category_id
        JOIN users u_req ON u_req.id = si.requested_by
        LEFT JOIN users u_buy ON u_buy.id = si.bought_by
        LEFT JOIN users u_rec ON u_rec.id = si.received_by
        WHERE si.trip_id IN ({$placeholders})
        ORDER BY c.name ASC, si.id ASC
    ");
    $itemStmt->execute($tripIds);
    foreach ($itemStmt->fetchAll() as $row) {
        $tripItemsMap[(int)$row['trip_id']][] = $row;
    }

    $recStmt = $pdo->prepare("SELECT trip_id, image_path, store_label FROM trip_receipts WHERE trip_id IN ({$placeholders}) ORDER BY id ASC");
    $recStmt->execute($tripIds);
    foreach ($recStmt->fetchAll() as $rec) {
        $receiptsByTrip[(int)$rec['trip_id']][] = [
            'url'   => '../uploads/' . $rec['image_path'],
            'label' => $rec['store_label'] ?: 'Receipt',
        ];
    }
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

$page_title = 'Trip History';
$active_nav = 'history';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-0">Trip History</h1>
        <small class="text-muted-warm">
            <?php if ($showAll): ?>
                Showing all past completed trips
            <?php else: ?>
                Showing trips for <?= e(date('d M Y', strtotime($selectedDate))) ?><?= $selectedDate === $today ? ' (Today)' : '' ?>
            <?php endif; ?>
        </small>
    </div>
</div>

<!-- Date Filter Bar -->
<div class="kt-card p-3 mb-3">
    <form method="get" action="history.php" class="row g-2 align-items-center">
        <div class="col-12 col-sm-auto flex-grow-1">
            <input type="date" name="date" class="form-control" value="<?= e($selectedDate) ?>" aria-label="Filter by date">
        </div>
        <div class="col-6 col-sm-auto">
            <button type="submit" class="btn btn-brown btn-tap w-100 px-3">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
        <div class="col-6 col-sm-auto">
            <a href="history.php?show=all" class="btn <?= $showAll ? 'btn-soft-cream' : 'btn-outline-brown' ?> btn-tap w-100 px-3">
                Show All
            </a>
        </div>
    </form>
</div>

<?php if (empty($trips)): ?>
    <div class="kt-card p-5 text-center">
        <i class="bi bi-calendar-x fs-1 text-muted-warm mb-2 d-block"></i>
        <h2 class="h6 fw-bold text-brown-dark mb-1">No completed trips for this date.</h2>
        <p class="small text-muted-warm mb-3">Pick another date above or view all past trips.</p>
        <?php if (!$showAll): ?>
            <a href="history.php?show=all" class="btn btn-outline-brown btn-sm">Show All Trips</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($trips as $trip): ?>
            <?php
                $tid = (int)$trip['id'];
                $items = $tripItemsMap[$tid] ?? [];
                $tripReceipts = $receiptsByTrip[$tid] ?? [];
            ?>
            <div class="kt-card">
                <div class="kt-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-bold text-brown-dark">Trip #<?= $tid ?></span>
                        <span class="small text-muted-warm ms-2"><?= e(format_datetime($trip['completed_at'])) ?></span>
                    </div>
                    <div>
                        <?php if ($trip['status'] === 'completed'): ?>
                            <a href="receive.php" class="badge bg-warning text-dark text-decoration-none">
                                Waiting for Delivery Check <i class="bi bi-arrow-right-short"></i>
                            </a>
                        <?php else: ?>
                            <span class="badge badge-status-received">
                                <i class="bi bi-check-circle me-1"></i>
                                Received by <?= e($trip['receiver_name'] ?? 'Staff') ?> at <?= e(format_time($trip['received_at'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-3">
                    <!-- Trip Summary Strip -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom small">
                        <div class="d-flex flex-wrap gap-3">
                            <span><i class="bi bi-bag-check text-brown me-1"></i>Bought by: <strong><?= e($trip['shopper_name']) ?></strong></span>
                            <span><i class="bi bi-cash text-brown me-1"></i>Total Spent: <strong><?= e(format_rm($trip['total_spent'])) ?></strong></span>
                            <?php if ((float)$trip['cash_given'] > 0): ?>
                                <span>Budget: <strong><?= e(format_rm($trip['cash_given'])) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($tripReceipts)): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-brown"
                                    data-bs-toggle="modal"
                                    data-bs-target="#staffReceiptModal"
                                    data-receipts='<?= e(json_encode($tripReceipts)) ?>'
                                    data-trip="Trip #<?= $tid ?>">
                                <i class="bi bi-images me-1"></i> View Receipts (<?= count($tripReceipts) ?>)
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Items & Automatic Accountability Log -->
                    <?php if (empty($items)): ?>
                        <div class="small text-muted-warm">No individual items linked to this trip.</div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($items as $item): ?>
                                <div class="p-2 px-3 rounded" style="background-color:#FAF7F2;border:1px solid #EFE7DE;">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-1">
                                        <div>
                                            <span class="fw-semibold text-brown-dark"><?= e($item['item_name']) ?></span>
                                            <span class="small text-muted-warm ms-1">(<?= e(format_qty($item['quantity'])) ?> <?= e($item['unit']) ?> &middot; <?= e($item['category_name']) ?>)</span>
                                            <?php if (!empty($item['store_name'])): ?>
                                                <span class="badge badge-status-pending ms-1">
                                                    <i class="bi bi-shop me-1"></i><?= e($item['store_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="fw-semibold text-brown"><?= e(format_rm($item['price_paid'])) ?></span>
                                    </div>
                                    <div class="small text-muted-warm mt-1 d-flex flex-wrap gap-3">
                                        <span>Requested by: <strong><?= e($item['requester_name']) ?></strong> at <?= e(format_time($item['requested_at'])) ?></span>
                                        <span>Bought by: <strong><?= e($item['buyer_name'] ?? $trip['shopper_name']) ?></strong></span>
                                        <?php if ($item['received_at']): ?>
                                            <span class="text-success">Received by: <strong><?= e($item['receiver_name'] ?? 'Staff') ?></strong> at <?= e(format_time($item['received_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Multi-Receipt Photo Modal -->
<div class="modal fade" id="staffReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-brown-dark" id="staffReceiptTitle">Receipt Photos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="staffReceiptBody"></div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<HTML
<script>
const srModal = document.getElementById('staffReceiptModal');
if (srModal) {
    srModal.addEventListener('show.bs.modal', function (event) {
        const b = event.relatedTarget;
        const receipts = JSON.parse(b.getAttribute('data-receipts') || '[]');
        document.getElementById('staffReceiptTitle').textContent = 'Receipt Photos (' + b.getAttribute('data-trip') + ')';
        const body = document.getElementById('staffReceiptBody');
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
