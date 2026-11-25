<?php
/**
 * KitchTrack - Staff Live Shopping List (Mobile-First)
 * Auto-refreshes every 5 seconds via Vanilla JS fetch().
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('staff');

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$templates  = $pdo->query("
    SELECT e.id, e.item_name, e.default_qty, e.default_unit, c.name AS category_name
    FROM essential_items e
    JOIN categories c ON c.id = e.category_id
    ORDER BY c.name ASC, e.item_name ASC
")->fetchAll();

$page_title = 'Shopping List';
$active_nav = 'shopping_list';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 fw-bold text-brown-dark mb-0">Live Shopping List</h1>
        <small class="text-muted-warm" id="liveSyncStatus">
            <i class="bi bi-arrow-repeat me-1"></i>Syncing every 5 seconds...
        </small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-soft-cream btn-tap px-3" data-bs-toggle="modal" data-bs-target="#quickAddModal">
            <i class="bi bi-lightning-charge-fill text-brown"></i>
            <span>Quick Add</span>
        </button>
        <button type="button" class="btn btn-brown btn-tap px-3" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-lg"></i>
            <span>Add Item</span>
        </button>
    </div>
</div>

<!-- Inline JS Feedback Toast Banner -->
<div id="jsFeedback" class="alert d-none py-2 small" role="alert"></div>

<!-- Trip Lock Status Banner (Rendered live by JS) -->
<div id="tripBannerContainer" class="mb-3"></div>

<!-- Arriving / Awaiting Confirm Received Banner (Rendered live by JS) -->
<div id="pendingReceiveContainer" class="mb-3"></div>

<!-- Live Shopping List Grouped by Store Category -->
<div id="shoppingListContainer">
    <div class="kt-card p-4 text-center text-muted-warm">
        <div class="spinner-border spinner-border-sm text-brown me-2" role="status"></div>
        Loading shopping list...
    </div>
</div>

<!-- Add New Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="addItemForm">
                <div class="modal-header">
                    <h5 class="modal-title text-brown-dark">
                        <i class="bi bi-plus-circle me-2 text-brown"></i>Add Item to List
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="item_name" class="form-label small fw-semibold">Item Name</label>
                        <input type="text" id="item_name" name="item_name" class="form-control" placeholder="e.g. Fresh Chicken" required maxlength="120">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="quantity" class="form-label small fw-semibold">Quantity</label>
                            <input type="number" step="0.5" min="0.5" id="quantity" name="quantity" class="form-control" value="1" required>
                        </div>
                        <div class="col-6">
                            <label for="unit" class="form-label small fw-semibold">Unit</label>
                            <input type="text" id="unit" name="unit" class="form-control" placeholder="kg, pack, bottle" value="kg" required maxlength="30">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="category_id" class="form-label small fw-semibold">Store Category</label>
                        <select id="category_id" name="category_id" class="form-select" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch p-3 rounded" style="background-color: #FDEDEC; border: 1px solid #F5C6CB;">
                        <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="is_urgent" name="is_urgent" value="1">
                        <label class="form-check-label fw-semibold text-danger" for="is_urgent">
                            Mark as Urgent (Moves to top of list)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-brown btn-tap" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brown btn-tap">
                        <i class="bi bi-check-lg"></i> Add to List
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Add Essential Items Modal -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-brown-dark">
                    <i class="bi bi-lightning-charge-fill me-2 text-brown"></i>Quick Add Essential Items
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check form-switch mb-3 p-2 ps-5 rounded" style="background-color: #FAF7F2; border: 1px solid #E8DFD5;">
                    <input class="form-check-input" type="checkbox" role="switch" id="quickAddUrgent">
                    <label class="form-check-label small fw-semibold text-danger" for="quickAddUrgent">
                        Mark quick-added items as Urgent
                    </label>
                </div>

                <?php if (empty($templates)): ?>
                    <p class="text-muted-warm small text-center py-3">No essential item templates set up yet. Ask the owner to add some.</p>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($templates as $tpl): ?>
                            <div class="col-12 col-sm-6">
                                <button type="button"
                                        class="kt-quick-chip w-100 quick-add-btn"
                                        data-template-id="<?= (int)$tpl['id'] ?>"
                                        data-item-name="<?= e($tpl['item_name']) ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-semibold text-brown-dark"><?= e($tpl['item_name']) ?></span>
                                        <i class="bi bi-plus-circle text-brown"></i>
                                    </div>
                                    <div class="small text-muted-warm">
                                        <?= e(format_qty($tpl['default_qty'])) ?> <?= e($tpl['default_unit']) ?> &middot; <?= e($tpl['category_name']) ?>
                                    </div>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-brown btn-tap w-100" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = '<script src="../assets/js/shopping_list.js"></script>';
require_once __DIR__ . '/../includes/footer.php';
?>
