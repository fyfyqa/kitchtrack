<?php
/**
 * KitchTrack - Staff Checkout & Multi-Receipt Upload
 * Records Store Name and price per item, calculates total spent & price alerts,
 * saves multiple receipt photos to uploads/ & trip_receipts, and closes the trip.
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('staff');

$user = current_user();
$userId = (int)$user['id'];

// Verify current staff member holds the active shopping trip
$tripStmt = $pdo->prepare("SELECT * FROM shopping_trips WHERE shopper_id = ? AND status = 'shopping' LIMIT 1");
$tripStmt->execute([$userId]);
$trip = $tripStmt->fetch();

if (!$trip) {
    set_flash('warning', 'You do not have an active shopping trip. Tap "Start Shopping" first.');
    header('Location: shopping_list.php');
    exit;
}

// Fetch items that can be checked out (in_cart first, plus pending in case they forgot to tap In Cart)
$items = $pdo->query("
    SELECT si.*, c.name AS category_name
    FROM shopping_items si
    JOIN categories c ON c.id = si.category_id
    WHERE si.status IN ('in_cart', 'pending')
    ORDER BY (si.status = 'in_cart') DESC, si.is_urgent DESC, c.name ASC, si.id ASC
")->fetchAll();

// Fetch distinct previously used store names + defaults for autocomplete datalist
$dbStores = $pdo->query("
    SELECT DISTINCT store_name
    FROM shopping_items
    WHERE store_name IS NOT NULL AND store_name != ''
    ORDER BY store_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$defaultStores = ['Pasar Borong Selayang', 'NSK Trade City', '99 Speedmart', 'Mydin Wholesale', "Lotus's Market"];
$storeSuggestions = array_values(array_unique(array_merge($dbStores, $defaultStores)));
sort($storeSuggestions);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIds   = $_POST['include_item'] ?? [];
    $prices        = $_POST['price_paid'] ?? [];
    $storeNames    = $_POST['store_name'] ?? [];
    $receiptLabels = $_POST['receipt_label'] ?? [];
    $totalSpent    = (float)($_POST['total_spent'] ?? 0);
    $notes         = trim($_POST['notes'] ?? '');

    if (empty($selectedIds)) {
        $error = 'Please select at least one item you bought.';
    } elseif ($totalSpent <= 0) {
        $error = 'Please enter a valid Total Spent amount greater than RM 0.00.';
    } else {
        // Handle Multiple Receipt Photo Uploads
        $uploadedReceipts = [];
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        if (!empty($_FILES['receipt_photos']['name']) && is_array($_FILES['receipt_photos']['name'])) {
            $fileCount = count($_FILES['receipt_photos']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $origName = $_FILES['receipt_photos']['name'][$i] ?? '';
                if ($origName === '') {
                    continue;
                }
                $errCode = $_FILES['receipt_photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                $size    = $_FILES['receipt_photos']['size'][$i] ?? 0;
                $tmpName = $_FILES['receipt_photos']['tmp_name'][$i] ?? '';

                if ($errCode !== UPLOAD_ERR_OK) {
                    $error = 'One of the receipt photos failed to upload. Please try again.';
                    break;
                }
                if ($size > 5 * 1024 * 1024) {
                    $error = 'Each receipt photo must be smaller than 5 MB.';
                    break;
                }

                $mime = $finfo->file($tmpName);
                if (!isset($allowedMimes[$mime])) {
                    $error = 'All receipt photos must be JPG, PNG, or WEBP images.';
                    break;
                }

                $ext = $allowedMimes[$mime];
                $filename = 'receipt_trip' . $trip['id'] . '_' . date('Ymd_His') . '_' . ($i + 1) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $dest = __DIR__ . '/../uploads/' . $filename;
                if (!move_uploaded_file($tmpName, $dest)) {
                    $error = 'Could not save one of the receipt photos on the server.';
                    break;
                }

                $label = trim($receiptLabels[$i] ?? '');
                $uploadedReceipts[] = [
                    'image_path'  => $filename,
                    'store_label' => $label !== '' ? $label : null,
                ];
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $getItemStmt = $pdo->prepare("SELECT id, item_name, quantity FROM shopping_items WHERE id = ? AND status IN ('in_cart', 'pending') LIMIT 1");
                $prevPriceStmt = $pdo->prepare("
                    SELECT unit_price, store_name
                    FROM shopping_items
                    WHERE LOWER(item_name) = LOWER(?)
                      AND status IN ('bought', 'received')
                      AND unit_price IS NOT NULL
                    ORDER BY bought_at DESC, id DESC
                    LIMIT 1
                ");
                $updItemStmt = $pdo->prepare("
                    UPDATE shopping_items
                    SET status = 'bought',
                        trip_id = ?,
                        store_name = ?,
                        price_paid = ?,
                        unit_price = ?,
                        previous_unit_price = ?,
                        previous_store_name = ?,
                        is_price_alert = ?,
                        bought_by = ?,
                        bought_at = NOW()
                    WHERE id = ?
                ");

                $boughtCount = 0;
                foreach ($selectedIds as $rawId) {
                    $itemId = (int)$rawId;
                    $getItemStmt->execute([$itemId]);
                    $row = $getItemStmt->fetch();
                    if (!$row) {
                        continue;
                    }

                    $pricePaid = max(0, (float)($prices[$itemId] ?? 0));
                    $storeName = trim($storeNames[$itemId] ?? '');
                    $storeNameVal = $storeName !== '' ? $storeName : null;

                    $qty = max(0.01, (float)$row['quantity']);
                    $unitPrice = round($pricePaid / $qty, 2);

                    $prevPriceStmt->execute([$row['item_name']]);
                    $prevRow = $prevPriceStmt->fetch();
                    $prevUnitPrice = $prevRow ? (float)$prevRow['unit_price'] : null;
                    $prevStoreName = $prevRow ? ($prevRow['store_name'] ?: null) : null;

                    // Trigger Price Alert if newly recorded unit price is higher than the last recorded price
                    $isAlert = ($prevUnitPrice !== null && $unitPrice > $prevUnitPrice + 0.001) ? 1 : 0;

                    $updItemStmt->execute([
                        $trip['id'],
                        $storeNameVal,
                        $pricePaid,
                        $unitPrice,
                        $prevUnitPrice,
                        $prevStoreName,
                        $isAlert,
                        $userId,
                        $itemId,
                    ]);
                    $boughtCount++;
                }

                if ($boughtCount === 0) {
                    throw new RuntimeException('No valid items were checked out.');
                }

                // Save all uploaded receipt photos into trip_receipts
                $firstReceipt = null;
                if (!empty($uploadedReceipts)) {
                    $firstReceipt = $uploadedReceipts[0]['image_path'];
                    $insReceipt = $pdo->prepare("INSERT INTO trip_receipts (trip_id, image_path, store_label) VALUES (?, ?, ?)");
                    foreach ($uploadedReceipts as $rec) {
                        $insReceipt->execute([$trip['id'], $rec['image_path'], $rec['store_label']]);
                    }
                }

                // Auto-calculate change to return
                $cashGiven = (float)$trip['cash_given'];
                $changeAmount = $cashGiven > $totalSpent ? round($cashGiven - $totalSpent, 2) : 0.00;
                $changeReturned = ($cashGiven == 0 && $totalSpent > 0) ? 0 : ($changeAmount == 0.00 && $cashGiven == $totalSpent ? 1 : 0);

                $updTrip = $pdo->prepare("
                    UPDATE shopping_trips
                    SET status = 'completed',
                        total_spent = ?,
                        change_amount = ?,
                        change_returned = ?,
                        receipt_image = COALESCE(?, receipt_image),
                        notes = COALESCE(NULLIF(?, ''), notes),
                        completed_at = NOW()
                    WHERE id = ?
                ");
                $updTrip->execute([
                    $totalSpent,
                    $changeAmount,
                    $changeReturned,
                    $firstReceipt,
                    $notes,
                    $trip['id'],
                ]);

                $pdo->commit();
                $receiptMsg = count($uploadedReceipts) > 0
                    ? ' with ' . count($uploadedReceipts) . ' receipt photo' . (count($uploadedReceipts) === 1 ? '' : 's')
                    : '';
                set_flash('success', 'Checkout complete for Trip #' . $trip['id'] . ' (' . format_rm($totalSpent) . ')' . $receiptMsg . '.');
                header('Location: history.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Clean up uploaded files if DB transaction failed
                foreach ($uploadedReceipts as $rec) {
                    $p = __DIR__ . '/../uploads/' . $rec['image_path'];
                    if (is_file($p)) {
                        @unlink($p);
                    }
                }
                $error = 'Could not complete checkout. Please check your entries and try again.';
            }
        }
    }
}

$page_title = 'Checkout Trip #' . (int)$trip['id'];
$active_nav = 'shopping_list';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-0">Checkout Trip #<?= (int)$trip['id'] ?></h1>
        <small class="text-muted-warm">Enter store name and price for each item, then upload one or more receipt photos.</small>
    </div>
    <a href="shopping_list.php" class="btn btn-outline-brown btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to List
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger py-2 small" role="alert">
        <i class="bi bi-exclamation-circle-fill me-1"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (empty($items)): ?>
    <div class="kt-card p-4 text-center">
        <p class="text-muted-warm mb-3">There are no items ready to check out. Go back and add items to your list first.</p>
        <a href="shopping_list.php" class="btn btn-brown btn-tap">Back to Shopping List</a>
    </div>
<?php else: ?>
    <!-- Shared Store Name Suggestions Datalist -->
    <datalist id="storeNameList">
        <?php foreach ($storeSuggestions as $st): ?>
            <option value="<?= e($st) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <form method="post" action="checkout.php" enctype="multipart/form-data">
        <!-- Items Price & Store Name Entry -->
        <div class="kt-card mb-3">
            <div class="kt-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-cart-check me-2 text-brown"></i>Items Bought</span>
                <span class="small text-muted-warm">Select or type Store Name &amp; Price Paid</span>
            </div>
            <div class="p-3">
                <?php foreach ($items as $item): ?>
                    <?php $checked = ($item['status'] === 'in_cart') ? 'checked' : ''; ?>
                    <div class="kt-item-card mb-2">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-5">
                                <div class="form-check d-flex align-items-start gap-2 mb-0">
                                    <input class="form-check-input mt-1 item-check"
                                           type="checkbox"
                                           name="include_item[]"
                                           value="<?= (int)$item['id'] ?>"
                                           id="chk_<?= (int)$item['id'] ?>"
                                           <?= $checked ?>>
                                    <label class="form-check-label" for="chk_<?= (int)$item['id'] ?>">
                                        <span class="fw-semibold text-brown-dark d-block">
                                            <?= e($item['item_name']) ?>
                                            <?php if ($item['status'] === 'in_cart'): ?>
                                                <span class="badge badge-status-in-cart ms-1">In Cart</span>
                                            <?php else: ?>
                                                <span class="badge badge-status-pending ms-1">Pending</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="small text-muted-warm">
                                            <?= e(format_qty($item['quantity'])) ?> <?= e($item['unit']) ?> &middot; <?= e($item['category_name']) ?>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-12 col-sm-7 col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted-warm small" title="Store Name">
                                        <i class="bi bi-shop"></i>
                                    </span>
                                    <input type="text"
                                           name="store_name[<?= (int)$item['id'] ?>]"
                                           list="storeNameList"
                                           class="form-control"
                                           placeholder="Store name (e.g. NSK)"
                                           maxlength="100">
                                </div>
                            </div>
                            <div class="col-12 col-sm-5 col-md-3">
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted-warm small">RM</span>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           name="price_paid[<?= (int)$item['id'] ?>]"
                                           class="form-control item-price"
                                           data-item-id="<?= (int)$item['id'] ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Totals & Multiple Receipt Photos Upload -->
        <div class="kt-card mb-4">
            <div class="kt-card-header">
                <i class="bi bi-receipt me-2 text-brown"></i>Total &amp; Receipt Photos
            </div>
            <div class="p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded" style="background-color:#FAF7F2;border:1px solid #E8DFD5;">
                            <div class="small text-muted-warm">Petty Cash Given</div>
                            <div class="fs-5 fw-bold text-brown-dark" id="cashGivenDisplay" data-cash="<?= (float)$trip['cash_given'] ?>">
                                <?= e(format_rm($trip['cash_given'])) ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="total_spent" class="form-label small fw-semibold text-brown-dark">Total Spent (RM)</label>
                        <input type="number" step="0.01" min="0.01" id="total_spent" name="total_spent" class="form-control fw-bold" placeholder="0.00" required>
                        <div class="form-text small">Auto-calculated from item prices above.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded" style="background-color:#FAF7F2;border:1px solid #E8DFD5;">
                            <div class="small text-muted-warm" id="changeLabel">Change to Return</div>
                            <div class="fs-5 fw-bold text-brown" id="changeDisplay">RM 0.00</div>
                        </div>
                    </div>
                </div>

                <!-- Multiple Receipt Photos Section -->
                <div class="mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label small fw-semibold text-brown-dark mb-0">
                            Upload Receipt Photos (Multiple allowed &middot; JPG / PNG / WEBP, max 5MB each)
                        </label>
                        <button type="button" class="btn btn-sm btn-outline-brown" id="btnAddReceiptRow">
                            <i class="bi bi-plus-circle me-1"></i> Add Another Receipt
                        </button>
                    </div>

                    <div id="receiptRowsContainer" class="d-flex flex-column gap-2">
                        <div class="receipt-upload-row p-2 rounded" style="background-color:#FAF7F2;border:1px solid #E8DFD5;">
                            <div class="row g-2 align-items-center">
                                <div class="col-12 col-md-7">
                                    <input type="file"
                                           class="form-control"
                                           name="receipt_photos[]"
                                           accept="image/*"
                                           capture="environment">
                                </div>
                                <div class="col-12 col-md-5">
                                    <input type="text"
                                           class="form-control"
                                           name="receipt_label[]"
                                           list="storeNameList"
                                           placeholder="Store label (optional, e.g. NSK)"
                                           maxlength="100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label small fw-semibold text-brown-dark">Notes (Optional)</label>
                    <input type="text" class="form-control" id="notes" name="notes" placeholder="e.g. Bought from 2 stores today" maxlength="200">
                </div>

                <button type="submit" class="btn btn-brown btn-tap w-100 fs-6">
                    <i class="bi bi-check2-circle"></i> Complete Checkout &amp; Submit Receipts
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php
$extra_js = <<<HTML
<script>
(function () {
    const cashGiven = parseFloat(document.getElementById('cashGivenDisplay')?.dataset.cash || '0');
    const totalInput = document.getElementById('total_spent');
    const changeDisplay = document.getElementById('changeDisplay');
    const changeLabel = document.getElementById('changeLabel');

    function updateChange() {
        const spent = parseFloat(totalInput?.value || '0') || 0;
        const diff = cashGiven - spent;
        if (diff >= 0) {
            changeLabel.textContent = 'Change to Return';
            changeDisplay.textContent = 'RM ' + diff.toFixed(2);
            changeDisplay.className = 'fs-5 fw-bold text-brown';
        } else {
            changeLabel.textContent = 'To Reimburse You';
            changeDisplay.textContent = 'RM ' + Math.abs(diff).toFixed(2);
            changeDisplay.className = 'fs-5 fw-bold text-danger';
        }
    }

    function recalculateTotal() {
        let sum = 0;
        document.querySelectorAll('.item-price').forEach(inp => {
            const id = inp.dataset.itemId;
            const chk = document.getElementById('chk_' + id);
            if (chk && chk.checked) {
                sum += parseFloat(inp.value || '0') || 0;
            }
        });
        if (totalInput) {
            totalInput.value = sum > 0 ? sum.toFixed(2) : '';
        }
        updateChange();
    }

    document.querySelectorAll('.item-price').forEach(inp => {
        inp.addEventListener('input', () => {
            const id = inp.dataset.itemId;
            const chk = document.getElementById('chk_' + id);
            if (chk && parseFloat(inp.value || '0') > 0) {
                chk.checked = true;
            }
            recalculateTotal();
        });
    });

    document.querySelectorAll('.item-check').forEach(chk => {
        chk.addEventListener('change', recalculateTotal);
    });

    totalInput?.addEventListener('input', updateChange);
    updateChange();

    // Dynamic "Add Another Receipt" rows
    const receiptContainer = document.getElementById('receiptRowsContainer');
    const btnAddRow = document.getElementById('btnAddReceiptRow');
    if (btnAddRow && receiptContainer) {
        btnAddRow.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'receipt-upload-row p-2 rounded';
            row.style.backgroundColor = '#FAF7F2';
            row.style.border = '1px solid #E8DFD5';
            row.innerHTML = `
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-6">
                        <input type="file" class="form-control" name="receipt_photos[]" accept="image/*" capture="environment">
                    </div>
                    <div class="col-10 col-md-5">
                        <input type="text" class="form-control" name="receipt_label[]" list="storeNameList" placeholder="Store label (optional)" maxlength="100">
                    </div>
                    <div class="col-2 col-md-1 text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-receipt" title="Remove">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            `;
            row.querySelector('.btn-remove-receipt').addEventListener('click', () => row.remove());
            receiptContainer.appendChild(row);
        });
    }
})();
</script>
HTML;

require_once __DIR__ . '/../includes/footer.php';
?>
