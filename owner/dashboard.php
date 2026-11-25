<?php
/**
 * KitchTrack - Owner Analytics Dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

// Handle CSV Export Report
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kitchtrack-spending-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Item', 'Category', 'Store Name', 'Qty', 'Unit', 'Price Paid (RM)', 'Unit Price (RM)', 'Price Alert', 'Requested By', 'Bought By', 'Received By']);

    $csvStmt = $pdo->query("
        SELECT si.bought_at, si.item_name, c.name AS category_name, si.store_name, si.quantity, si.unit,
               si.price_paid, si.unit_price, si.is_price_alert,
               u_req.full_name AS req_name, u_buy.full_name AS buy_name, u_rec.full_name AS rec_name
        FROM shopping_items si
        JOIN categories c ON c.id = si.category_id
        JOIN users u_req ON u_req.id = si.requested_by
        LEFT JOIN users u_buy ON u_buy.id = si.bought_by
        LEFT JOIN users u_rec ON u_rec.id = si.received_by
        WHERE si.status IN ('bought', 'received')
        ORDER BY si.bought_at DESC
    ");
    while ($row = $csvStmt->fetch()) {
        fputcsv($out, [
            $row['bought_at'],
            $row['item_name'],
            $row['category_name'],
            $row['store_name'] ?? '-',
            format_qty($row['quantity']),
            $row['unit'],
            number_format((float)$row['price_paid'], 2, '.', ''),
            number_format((float)$row['unit_price'], 2, '.', ''),
            $row['is_price_alert'] ? 'Yes' : 'No',
            $row['req_name'],
            $row['buy_name'] ?? '-',
            $row['rec_name'] ?? 'Pending',
        ]);
    }
    fclose($out);
    exit;
}

// 1. Check if someone is currently shopping
$activeTrip = $pdo->query("
    SELECT t.id, t.started_at, u.full_name AS shopper_name
    FROM shopping_trips t
    JOIN users u ON u.id = t.shopper_id
    WHERE t.status = 'shopping'
    LIMIT 1
")->fetch();

// 2. Summary Card: Total Spent This Month
$monthSummary = $pdo->query("
    SELECT COALESCE(SUM(total_spent), 0) AS total_spent, COUNT(*) AS trip_count
    FROM shopping_trips
    WHERE status IN ('completed', 'received')
      AND YEAR(completed_at) = YEAR(CURDATE())
      AND MONTH(completed_at) = MONTH(CURDATE())
")->fetch();

// 3. Summary Card: Pending Change to be returned
$pendingChange = $pdo->query("
    SELECT COALESCE(SUM(change_amount), 0) AS total_change, COUNT(*) AS pending_trips
    FROM shopping_trips
    WHERE status IN ('completed', 'received')
      AND change_returned = 0
      AND change_amount > 0
")->fetch();

// 4. Price Alerts (recent 30 days) — includes current & previous store names for comparison
$priceAlerts = $pdo->query("
    SELECT si.id, si.item_name, si.unit, si.unit_price, si.previous_unit_price,
           si.store_name, si.previous_store_name,
           si.price_paid, si.bought_at, u.full_name AS buyer_name, c.name AS category_name
    FROM shopping_items si
    JOIN categories c ON c.id = si.category_id
    LEFT JOIN users u ON u.id = si.bought_by
    WHERE si.is_price_alert = 1
      AND si.status IN ('bought', 'received')
      AND si.bought_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY si.bought_at DESC
    LIMIT 10
")->fetchAll();

// 5. Monthly Spending (Last 6 Months) for Chart.js
$monthlyRows = $pdo->query("
    SELECT DATE_FORMAT(completed_at, '%Y-%m') AS ym,
           COALESCE(SUM(total_spent), 0) AS total
    FROM shopping_trips
    WHERE status IN ('completed', 'received')
      AND completed_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
")->fetchAll();

$monthlyMap = [];
foreach ($monthlyRows as $r) {
    $monthlyMap[$r['ym']] = (float)$r['total'];
}

$chartLabels = [];
$chartValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("first day of -{$i} months");
    $ym = date('Y-m', $ts);
    $chartLabels[] = date('M Y', $ts);
    $chartValues[] = round($monthlyMap[$ym] ?? 0, 2);
}

// 6. Top 5 Highest-Cost Items (All Time / Recent)
$topItems = $pdo->query("
    SELECT item_name,
           SUM(price_paid) AS total_cost,
           COUNT(*) AS times_bought,
           MAX(unit) AS unit
    FROM shopping_items
    WHERE status IN ('bought', 'received') AND price_paid IS NOT NULL
    GROUP BY item_name
    ORDER BY total_cost DESC
    LIMIT 5
")->fetchAll();
$maxItemCost = !empty($topItems) ? max(array_column($topItems, 'total_cost')) : 1;

// 7. Recent Completed Trips
$recentTrips = $pdo->query("
    SELECT t.id, t.status, t.cash_given, t.total_spent, t.change_amount, t.change_returned,
           t.completed_at, t.received_at,
           u_shop.full_name AS shopper_name,
           u_rec.full_name AS receiver_name
    FROM shopping_trips t
    JOIN users u_shop ON u_shop.id = t.shopper_id
    LEFT JOIN users u_rec ON u_rec.id = t.received_by
    WHERE t.status IN ('completed', 'received')
    ORDER BY t.completed_at DESC
    LIMIT 6
")->fetchAll();

$page_title = 'Owner Dashboard';
$active_nav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-1">Owner Dashboard</h1>
        <p class="small text-muted-warm mb-0">Monthly spending overview, petty cash status, and price alerts.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="dashboard.php?export=csv" class="btn btn-outline-brown btn-sm">
            <i class="bi bi-download me-1"></i> Export CSV
        </a>
        <button type="button" class="btn btn-brown btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Report
        </button>
    </div>
</div>

<?php if ($activeTrip): ?>
    <div class="kt-trip-banner active-other d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-cart-check-fill fs-5 text-warning"></i>
            <div>
                <strong class="text-brown-dark">Currently shopping: <?= e($activeTrip['shopper_name']) ?></strong>
                <span class="small text-muted-warm ms-2">Started at <?= e(format_time($activeTrip['started_at'])) ?></span>
            </div>
        </div>
        <a href="petty_cash.php" class="btn btn-sm btn-outline-brown no-print">View Petty Cash</a>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="kt-stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small text-muted-warm mb-1">Total Spent This Month</div>
                    <div class="kt-stat-value"><?= e(format_rm($monthSummary['total_spent'])) ?></div>
                    <div class="small text-muted-warm mt-2">
                        <?= (int)$monthSummary['trip_count'] ?> completed trip<?= ((int)$monthSummary['trip_count'] === 1 ? '' : 's') ?> in <?= date('F Y') ?>
                    </div>
                </div>
                <div class="kt-stat-icon">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="kt-stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small text-muted-warm mb-1">Pending Change</div>
                    <div class="kt-stat-value"><?= e(format_rm($pendingChange['total_change'])) ?></div>
                    <div class="small text-muted-warm mt-2">
                        <?php if ((int)$pendingChange['pending_trips'] > 0): ?>
                            <a href="petty_cash.php?filter=pending&show=all" class="text-brown fw-semibold text-decoration-none">
                                <?= (int)$pendingChange['pending_trips'] ?> trip<?= ((int)$pendingChange['pending_trips'] === 1 ? '' : 's') ?> awaiting return <i class="bi bi-arrow-right-short"></i>
                            </a>
                        <?php else: ?>
                            All petty cash change settled
                        <?php endif; ?>
                    </div>
                </div>
                <div class="kt-stat-icon warn-icon">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="kt-stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small text-muted-warm mb-1">Price Alerts (30 Days)</div>
                    <div class="kt-stat-value"><?= count($priceAlerts) ?></div>
                    <div class="small text-muted-warm mt-2">
                        <?= count($priceAlerts) > 0 ? 'Items bought above previous unit price' : 'No price increases detected' ?>
                    </div>
                </div>
                <div class="kt-stat-icon <?= count($priceAlerts) > 0 ? 'alert-icon' : '' ?>">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Price Alerts Section -->
<?php if (!empty($priceAlerts)): ?>
    <div class="kt-card mb-4">
        <div class="kt-card-header d-flex justify-content-between align-items-center">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                <span>Price Increase Alerts</span>
            </span>
            <span class="badge badge-urgent"><?= count($priceAlerts) ?> Alert<?= count($priceAlerts) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table kt-table mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Previous Price &amp; Store</th>
                        <th>New Price &amp; Store</th>
                        <th>Increase</th>
                        <th>Bought By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($priceAlerts as $alert): ?>
                        <?php
                            $prev = (float)$alert['previous_unit_price'];
                            $curr = (float)$alert['unit_price'];
                            $diff = $curr - $prev;
                            $pct  = $prev > 0 ? round(($diff / $prev) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td class="fw-semibold text-brown-dark"><?= e($alert['item_name']) ?></td>
                            <td><span class="badge badge-status-pending"><?= e($alert['category_name']) ?></span></td>
                            <td>
                                <span class="text-muted-warm"><?= e(format_rm($prev)) ?> / <?= e($alert['unit']) ?></span>
                                <?php if (!empty($alert['previous_store_name'])): ?>
                                    <span class="badge badge-status-pending ms-1">
                                        <i class="bi bi-shop me-1"></i><?= e($alert['previous_store_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-semibold text-danger"><?= e(format_rm($curr)) ?> / <?= e($alert['unit']) ?></span>
                                <?php if (!empty($alert['store_name'])): ?>
                                    <span class="badge badge-status-out ms-1">
                                        <i class="bi bi-shop me-1"></i><?= e($alert['store_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-status-out">
                                    +<?= e(format_rm($diff)) ?> (+<?= $pct ?>%)
                                </span>
                            </td>
                            <td><?= e($alert['buyer_name'] ?? '-') ?></td>
                            <td class="small text-muted-warm"><?= e(format_datetime($alert['bought_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Charts & Top 5 Items -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-7">
        <div class="kt-card h-100">
            <div class="kt-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart-fill me-2 text-brown"></i>Monthly Spending (Last 6 Months)</span>
            </div>
            <div class="p-3 p-md-4">
                <canvas id="monthlySpendingChart" height="220"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="kt-card h-100">
            <div class="kt-card-header">
                <i class="bi bi-trophy me-2 text-brown"></i>Top 5 Highest-Cost Items
            </div>
            <div class="p-3 p-md-4">
                <?php if (empty($topItems)): ?>
                    <p class="text-muted-warm small mb-0">No purchase data recorded yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($topItems as $index => $item): ?>
                            <?php $barPct = $maxItemCost > 0 ? round(((float)$item['total_cost'] / $maxItemCost) * 100) : 0; ?>
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-brown-dark">
                                        <?= ($index + 1) ?>. <?= e($item['item_name']) ?>
                                        <span class="small fw-normal text-muted-warm">(<?= (int)$item['times_bought'] ?>x)</span>
                                    </span>
                                    <span class="fw-bold text-brown"><?= e(format_rm($item['total_cost'])) ?></span>
                                </div>
                                <div class="progress" style="height: 8px; background-color: #F3ECE4;">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: <?= $barPct ?>%; background-color: #6F4E37;"
                                         aria-valuenow="<?= $barPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Trips Table -->
<div class="kt-card">
    <div class="kt-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2 text-brown"></i>Recent Shopping Trips</span>
        <a href="petty_cash.php" class="btn btn-sm btn-outline-brown no-print">Manage Petty Cash</a>
    </div>
    <div class="table-responsive">
        <table class="table kt-table mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bought By</th>
                    <th>Cash Given</th>
                    <th>Total Spent</th>
                    <th>Change</th>
                    <th>Kitchen Check</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTrips)): ?>
                    <tr><td colspan="6" class="text-center text-muted-warm py-4">No completed shopping trips yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentTrips as $trip): ?>
                        <tr>
                            <td class="small text-muted-warm"><?= e(format_datetime($trip['completed_at'])) ?></td>
                            <td class="fw-semibold"><?= e($trip['shopper_name']) ?></td>
                            <td><?= e(format_rm($trip['cash_given'])) ?></td>
                            <td class="fw-semibold text-brown-dark"><?= e(format_rm($trip['total_spent'])) ?></td>
                            <td>
                                <?php if ((float)$trip['change_amount'] > 0): ?>
                                    <?= e(format_rm($trip['change_amount'])) ?>
                                    <?php if ($trip['change_returned']): ?>
                                        <span class="badge badge-status-received ms-1">Returned</span>
                                    <?php else: ?>
                                        <span class="badge badge-status-out ms-1">Pending</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted-warm small">Settled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($trip['status'] === 'received'): ?>
                                    <span class="badge badge-status-received">Received by <?= e($trip['receiver_name'] ?? 'Staff') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-status-pending">Awaiting Kitchen</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$chartLabelsJson = json_encode($chartLabels);
$chartValuesJson = json_encode($chartValues);
$extra_js = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('monthlySpendingChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$chartLabelsJson},
            datasets: [{
                label: 'Total Spent (RM)',
                data: {$chartValuesJson},
                backgroundColor: '#6F4E37',
                hoverBackgroundColor: '#4A3525',
                borderRadius: 8,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (c) => 'RM ' + Number(c.parsed.y).toFixed(2)
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#F1EAE2' },
                    ticks: {
                        callback: (v) => 'RM ' + v
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}
</script>
HTML;

require_once __DIR__ . '/../includes/footer.php';
?>
