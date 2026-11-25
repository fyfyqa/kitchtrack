<?php
/**
 * KitchTrack - Owner Templates & Store Categories Management
 */

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Store Category Actions ---
    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            set_flash('danger', 'Store category name is required.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
                $stmt->execute([$name, $desc !== '' ? $desc : null]);
                set_flash('success', 'Store category "' . $name . '" added.');
            } catch (PDOException $e) {
                set_flash('danger', 'That store category name already exists.');
            }
        }
        header('Location: templates.php');
        exit;
    }

    if ($action === 'edit_category') {
        $id   = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($id > 0 && $name !== '') {
            try {
                $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $desc !== '' ? $desc : null, $id]);
                set_flash('success', 'Store category updated.');
            } catch (PDOException $e) {
                set_flash('danger', 'Another store category already uses that name.');
            }
        }
        header('Location: templates.php');
        exit;
    }

    if ($action === 'delete_category') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                set_flash('success', 'Store category deleted.');
            } catch (PDOException $e) {
                set_flash('danger', 'Cannot delete this category because it is linked to existing items or templates.');
            }
        }
        header('Location: templates.php');
        exit;
    }

    // --- Essential Item Template Actions ---
    if ($action === 'add_template') {
        $item_name   = trim($_POST['item_name'] ?? '');
        $default_qty = (float)($_POST['default_qty'] ?? 1);
        $default_unit = trim($_POST['default_unit'] ?? 'pcs');
        $category_id = (int)($_POST['category_id'] ?? 0);

        if ($item_name === '' || $default_qty <= 0 || $default_unit === '' || $category_id <= 0) {
            set_flash('danger', 'Please fill in all fields for the essential item.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO essential_items (item_name, default_qty, default_unit, category_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$item_name, $default_qty, $default_unit, $category_id]);
            set_flash('success', 'Essential item "' . $item_name . '" added to Quick-Restock list.');
        }
        header('Location: templates.php');
        exit;
    }

    if ($action === 'edit_template') {
        $id           = (int)($_POST['template_id'] ?? 0);
        $item_name    = trim($_POST['item_name'] ?? '');
        $default_qty  = (float)($_POST['default_qty'] ?? 1);
        $default_unit = trim($_POST['default_unit'] ?? 'pcs');
        $category_id  = (int)($_POST['category_id'] ?? 0);

        if ($id > 0 && $item_name !== '' && $default_qty > 0 && $category_id > 0) {
            $stmt = $pdo->prepare('UPDATE essential_items SET item_name = ?, default_qty = ?, default_unit = ?, category_id = ? WHERE id = ?');
            $stmt->execute([$item_name, $default_qty, $default_unit, $category_id, $id]);
            set_flash('success', 'Essential item updated.');
        }
        header('Location: templates.php');
        exit;
    }

    if ($action === 'delete_template') {
        $id = (int)($_POST['template_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM essential_items WHERE id = ?');
            $stmt->execute([$id]);
            set_flash('success', 'Essential item removed.');
        }
        header('Location: templates.php');
        exit;
    }
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
$templates = $pdo->query("
    SELECT e.*, c.name AS category_name
    FROM essential_items e
    JOIN categories c ON c.id = e.category_id
    ORDER BY c.name ASC, e.item_name ASC
")->fetchAll();

$page_title = 'Templates & Store Categories';
$active_nav = 'templates';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h1 class="h4 fw-bold text-brown-dark mb-1">Templates &amp; Store Categories</h1>
    <p class="small text-muted-warm mb-0">Manage Quick-Restock essential items and store groups used by staff.</p>
</div>

<div class="row g-4">
    <!-- Essential Items Column -->
    <div class="col-12 col-lg-7">
        <div class="kt-card mb-4">
            <div class="kt-card-header">
                <i class="bi bi-lightning-charge me-2 text-brown"></i>Add Essential Item (Quick-Restock)
            </div>
            <div class="p-3 p-md-4">
                <form method="post" action="templates.php" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="add_template">
                    <div class="col-12 col-sm-5">
                        <label class="form-label small fw-semibold">Item Name</label>
                        <input type="text" name="item_name" class="form-control" placeholder="e.g. Fresh Chicken" required maxlength="120">
                    </div>
                    <div class="col-6 col-sm-2">
                        <label class="form-label small fw-semibold">Qty</label>
                        <input type="number" step="0.5" min="0.5" name="default_qty" class="form-control" value="1" required>
                    </div>
                    <div class="col-6 col-sm-2">
                        <label class="form-label small fw-semibold">Unit</label>
                        <input type="text" name="default_unit" class="form-control" placeholder="kg" value="kg" required maxlength="30">
                    </div>
                    <div class="col-12 col-sm-3">
                        <label class="form-label small fw-semibold">Store</label>
                        <select name="category_id" class="form-select" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 mt-3">
                        <button type="submit" class="btn btn-brown btn-sm">
                            <i class="bi bi-plus-circle me-1"></i> Add Essential Item
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <i class="bi bi-card-checklist me-2 text-brown"></i>Essential Items List (<?= count($templates) ?>)
            </div>
            <div class="table-responsive">
                <table class="table kt-table mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Default Qty</th>
                            <th>Store</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($templates)): ?>
                            <tr><td colspan="4" class="text-center text-muted-warm py-4">No essential items yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($templates as $tpl): ?>
                                <tr>
                                    <td class="fw-semibold text-brown-dark"><?= e($tpl['item_name']) ?></td>
                                    <td><?= e(format_qty($tpl['default_qty'])) ?> <?= e($tpl['default_unit']) ?></td>
                                    <td><span class="badge badge-status-pending"><?= e($tpl['category_name']) ?></span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-brown"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editTemplateModal"
                                                    data-id="<?= (int)$tpl['id'] ?>"
                                                    data-name="<?= e($tpl['item_name']) ?>"
                                                    data-qty="<?= e(format_qty($tpl['default_qty'])) ?>"
                                                    data-unit="<?= e($tpl['default_unit']) ?>"
                                                    data-cat="<?= (int)$tpl['category_id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" action="templates.php" class="d-inline"
                                                  onsubmit="return confirm('Delete essential item <?= e($tpl['item_name']) ?>?');">
                                                <input type="hidden" name="action" value="delete_template">
                                                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Store Categories Column -->
    <div class="col-12 col-lg-5">
        <div class="kt-card mb-4">
            <div class="kt-card-header">
                <i class="bi bi-shop me-2 text-brown"></i>Add Store Category
            </div>
            <div class="p-3 p-md-4">
                <form method="post" action="templates.php">
                    <input type="hidden" name="action" value="add_category">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Store Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Wet Market" required maxlength="80">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description (Optional)</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Fresh meat & vegetables" maxlength="200">
                    </div>
                    <button type="submit" class="btn btn-brown btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Add Store Category
                    </button>
                </form>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <i class="bi bi-tags me-2 text-brown"></i>Store Categories (<?= count($categories) ?>)
            </div>
            <div class="table-responsive">
                <table class="table kt-table mb-0">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-brown-dark"><?= e($cat['name']) ?></div>
                                    <?php if (!empty($cat['description'])): ?>
                                        <div class="small text-muted-warm"><?= e($cat['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-brown"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editCategoryModal"
                                                data-id="<?= (int)$cat['id'] ?>"
                                                data-name="<?= e($cat['name']) ?>"
                                                data-desc="<?= e($cat['description'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" action="templates.php" class="d-inline"
                                              onsubmit="return confirm('Delete store category <?= e($cat['name']) ?>?');">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="templates.php">
                <input type="hidden" name="action" value="edit_template">
                <input type="hidden" name="template_id" id="edit_tpl_id">
                <div class="modal-header">
                    <h5 class="modal-title text-brown-dark">Edit Essential Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Item Name</label>
                        <input type="text" name="item_name" id="edit_tpl_name" class="form-control" required maxlength="120">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Default Qty</label>
                            <input type="number" step="0.5" min="0.5" name="default_qty" id="edit_tpl_qty" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Unit</label>
                            <input type="text" name="default_unit" id="edit_tpl_unit" class="form-control" required maxlength="30">
                        </div>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Store Category</label>
                        <select name="category_id" id="edit_tpl_cat" class="form-select" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-brown" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brown">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="templates.php">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="edit_cat_id">
                <div class="modal-header">
                    <h5 class="modal-title text-brown-dark">Edit Store Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Store Name</label>
                        <input type="text" name="name" id="edit_cat_name" class="form-control" required maxlength="80">
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Description</label>
                        <input type="text" name="description" id="edit_cat_desc" class="form-control" maxlength="200">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-brown" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brown">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<HTML
<script>
const tplModal = document.getElementById('editTemplateModal');
if (tplModal) {
    tplModal.addEventListener('show.bs.modal', function (event) {
        const b = event.relatedTarget;
        document.getElementById('edit_tpl_id').value = b.getAttribute('data-id');
        document.getElementById('edit_tpl_name').value = b.getAttribute('data-name');
        document.getElementById('edit_tpl_qty').value = b.getAttribute('data-qty');
        document.getElementById('edit_tpl_unit').value = b.getAttribute('data-unit');
        document.getElementById('edit_tpl_cat').value = b.getAttribute('data-cat');
    });
}
const catModal = document.getElementById('editCategoryModal');
if (catModal) {
    catModal.addEventListener('show.bs.modal', function (event) {
        const b = event.relatedTarget;
        document.getElementById('edit_cat_id').value = b.getAttribute('data-id');
        document.getElementById('edit_cat_name').value = b.getAttribute('data-name');
        document.getElementById('edit_cat_desc').value = b.getAttribute('data-desc');
    });
}
</script>
HTML;

require_once __DIR__ . '/../includes/footer.php';
?>
