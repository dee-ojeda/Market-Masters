<?php
date_default_timezone_set('Asia/Manila'); // keep all timestamps consistent with local time, not the DB server's clock

require_once 'includes/config.php';
requireAdmin();
$db = getDB();

// Make sure the budgets table exists (safe to run every load — shared with expenses.php)
$db->query("CREATE TABLE IF NOT EXISTS category_budgets (
    category VARCHAR(50) PRIMARY KEY,
    monthly_limit DECIMAL(12,2) NOT NULL DEFAULT 0
)");

$action = $_GET['action'] ?? 'list';
$success = $error = '';

// Handle forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $price       = floatval($_POST['price'] ?? 0);
    $cost        = floatval($_POST['cost'] ?? 0);
    $stock       = intval($_POST['stock'] ?? 0);
    $expiry_date = trim($_POST['expiry_date'] ?? '') ?: null;

    if (empty($name)) {
        $error = 'Product name is required.';
    } else {
        if (isset($_POST['id']) && $_POST['id']) {
            $id   = intval($_POST['id']);
            $stmt = $db->prepare("UPDATE products SET name=?, category=?, price=?, cost=?, stock=?, expiry_date=? WHERE id=?");
            $stmt->bind_param("ssddisi", $name, $category, $price, $cost, $stock, $expiry_date, $id);
        } else {
            $now_ts = date('Y-m-d H:i:s');
            $stmt = $db->prepare("INSERT INTO products (name, category, price, cost, stock, expiry_date, created_at) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("ssddiss", $name, $category, $price, $cost, $stock, $expiry_date, $now_ts);
        }
        if ($stmt->execute()) {
            $success = 'Product saved successfully!';
            $action  = 'list';
            // Auto-record expense only when adding a NEW product (not editing)
            if (!isset($_POST['id']) || !$_POST['id']) {
                if ($cost > 0) {
                    $exp_desc = "Product added: " . $name;
                    $exp_date = date('Y-m-d');
                    $exp_now  = date('Y-m-d H:i:s');
                    $exp_stmt = $db->prepare("INSERT INTO expenses (category, description, amount, expense_date, created_at) VALUES ('Product', ?, ?, ?, ?)");
                    $exp_stmt->bind_param("sdss", $exp_desc, $cost, $exp_date, $exp_now);
                    $exp_stmt->execute();
                }
            }
        } else {
            $error = 'Error saving product.';
        }    }
}

// Delete
if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db->query("DELETE FROM products WHERE id=$id");
    $success = 'Product deleted.';
    $action  = 'list';
}

// Edit — load product
$edit_product = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id           = intval($_GET['id']);
    $edit_product = $db->query("SELECT * FROM products WHERE id=$id")->fetch_assoc();
}

// List products
$products       = $db->query("SELECT * FROM products ORDER BY created_at DESC");
$total_products = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
$total_stock    = $db->query("SELECT SUM(stock) as s FROM products")->fetch_assoc()['s'] ?? 0;
$avg_price      = $db->query("SELECT AVG(price) as a FROM products")->fetch_assoc()['a'] ?? 0;

// Pre-fetch notification data before closing DB
$expiring_result = $db->query("SELECT * FROM products WHERE expiry_date IS NOT NULL AND expiry_date != '' AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE() ORDER BY expiry_date ASC");
$expiring_rows   = [];
$expiring_count  = 0;
if ($expiring_result) {
    while ($row = $expiring_result->fetch_assoc()) $expiring_rows[] = $row;
    $expiring_count = count($expiring_rows);
}

$zero_result = $db->query("
    SELECT p.* FROM products p
    LEFT JOIN sales s ON s.product_id = p.id
        AND MONTH(s.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
        AND YEAR(s.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
    WHERE s.id IS NULL
    ORDER BY p.name ASC
");
$zero_rows  = [];
$zero_count = 0;
if ($zero_result) {
    while ($row = $zero_result->fetch_assoc()) $zero_rows[] = $row;
    $zero_count = count($zero_rows);
}
$month_name = date('F', strtotime('first day of last month'));

// Out of Stock notification data
$outofstock_result = $db->query("SELECT * FROM products WHERE stock = 0 ORDER BY name ASC");
$outofstock_rows  = [];
$outofstock_count = 0;
if ($outofstock_result) {
    while ($row = $outofstock_result->fetch_assoc()) $outofstock_rows[] = $row;
    $outofstock_count = count($outofstock_rows);
}

// High Demand / Low Stock notification data
// Flags products that generated strong revenue in the last 30 days relative to how much
// stock is left, so the admin can consider raising the minimum stock / restocking sooner.
$highdemand_result = $db->query("
    SELECT p.*, SUM(s.quantity) as qty_sold, SUM(s.total) as revenue
    FROM products p
    JOIN sales s ON s.product_id = p.id AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id
    HAVING revenue > 0 AND p.stock <= qty_sold
    ORDER BY revenue DESC
");
$highdemand_rows  = [];
$highdemand_count = 0;
if ($highdemand_result) {
    while ($row = $highdemand_result->fetch_assoc()) $highdemand_rows[] = $row;
    $highdemand_count = count($highdemand_rows);
}

// ---- Budget limit for the "Product" category (used for the exceed-limit warning when adding a product) ----
$product_budget = 0;
$pb_res = $db->query("SELECT monthly_limit FROM category_budgets WHERE category = 'Product'");
if ($pb_res && $pb_row = $pb_res->fetch_assoc()) $product_budget = floatval($pb_row['monthly_limit']);

$product_month_spent = 0;
$cur_month = date('Y-m');
$pm_res = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE category='Product' AND DATE_FORMAT(expense_date,'%Y-%m')='$cur_month'");
if ($pm_res && $pm_row = $pm_res->fetch_assoc()) $product_month_spent = floatval($pm_row['t']);

$db->close();

// Helper: expiry badge info
function expiryBadge($expiry_date) {
    if (!$expiry_date || $expiry_date === '0000-00-00') {
        return ['label' => '—', 'class' => '', 'date' => ''];
    }
    $today     = new DateTime('today');
    $expiry    = new DateTime($expiry_date);
    $diff      = (int)$today->diff($expiry)->format('%r%a');
    $formatted = date('M d, Y', strtotime($expiry_date));
    if ($diff < 0) {
        return ['label' => '⚠ Expired', 'class' => 'badge-red', 'date' => $formatted];
    } elseif ($diff <= 30) {
        return ['label' => '⏳ ' . $diff . 'd left', 'class' => 'badge-orange', 'date' => $formatted];
    } else {
        return ['label' => $formatted, 'class' => 'badge-green', 'date' => $formatted];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<!-- DELETE / ACTION MODAL -->
<div class="del-modal-overlay" id="delModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon" id="delModalIcon">🗑️</div>
        <h3 id="delModalTitle">Delete Product?</h3>
        <p>You're about to permanently delete<br><strong id="delModalName">this product</strong>.<br>This action <strong>cannot be undone</strong>.</p>
        <div class="del-modal-actions">
            <button class="btn-modal-cancel" onclick="closeDelModal()">Cancel</button>
            <a id="delModalConfirmBtn" href="#" class="btn-modal-delete">Yes, Delete</a>
        </div>
    </div>
</div>

<!-- BUDGET EXCEED WARNING MODAL -->
<div class="del-modal-overlay" id="exceedModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon exceed-modal-icon">⚠️</div>
        <h3>Budget Limit Exceeded</h3>
        <p>Adding this product will auto-record a <strong>Product</strong> expense (its cost price) and push that category's spending for the month past its budget limit.</p>
        <div class="exceed-detail-box">
            <div><span class="exceed-label">Budget limit</span><span class="exceed-val" id="exceedLimit">—</span></div>
            <div><span class="exceed-label">Already spent this month</span><span class="exceed-val" id="exceedCurrent">—</span></div>
            <div><span class="exceed-label">This product's cost</span><span class="exceed-val" id="exceedThis">—</span></div>
            <div><span class="exceed-label">New total</span><span class="exceed-val exceed-over" id="exceedNewTotal">—</span></div>
            <div><span class="exceed-label">Over by</span><span class="exceed-val exceed-over" id="exceedOverBy">—</span></div>
        </div>
        <div class="del-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeExceedModal()">Cancel</button>
            <button type="button" class="btn-modal-proceed" onclick="proceedAnyway()">Add Anyway</button>
        </div>
    </div>
</div>

<!-- INCREASE STOCK MODAL (for High Demand / Low Stock notification) -->
<div class="del-modal-overlay" id="stockModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon stock-modal-icon">📈</div>
        <h3>Increase Stock?</h3>
        <p>
            <strong id="stockModalName">This product</strong> is selling fast and running low.
            Sold <strong id="stockModalSold">0</strong> in the last 30 days, but only
            <strong id="stockModalCurrent">0</strong> left in stock.
        </p>
        <form id="stockUpdateForm" method="POST" style="text-align:left;margin-bottom:18px;">
            <input type="hidden" name="id" id="stockModalId" value="">
            <input type="hidden" name="name" id="stockModalNameField" value="">
            <input type="hidden" name="category" id="stockModalCategoryField" value="">
            <input type="hidden" name="price" id="stockModalPriceField" value="">
            <input type="hidden" name="cost" id="stockModalCostField" value="">
            <input type="hidden" name="expiry_date" id="stockModalExpiryField" value="">
            <label style="font-size:12.5px;font-weight:700;color:#333;display:block;margin-bottom:6px;">New Stock Quantity</label>
            <input type="number" name="stock" id="stockModalNewStock" class="form-control" min="0" required
                   style="width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:inherit;outline:none;">
        </form>
        <div class="del-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeStockModal()">Cancel</button>
            <button type="button" class="btn-modal-proceed" onclick="submitStockUpdate()" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 4px 14px rgba(109,40,217,.35);">Update Stock</button>
        </div>
    </div>
</div>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">
                <p>Manage your products</p>
                <h1>Product Management</h1>
            </div>
            <div class="topbar-user">
                <div class="topbar-avatar">👤</div>
                <span class="admin-badge">🔐 Admin</span>
            </div>
        </div>

        <div class="page-content">
            <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

            <!-- Expiry Notification Banner -->
            <?php if ($expiring_count > 0): ?>
            <div class="notif-banner notif-expiry" id="notif-expiry">
                <div class="notif-header" onclick="toggleNotif('notif-expiry')" style="cursor:pointer;">
                    <div class="notif-title">
                        <span class="notif-dot dot-orange"></span>
                        <span>⏳ <strong><?= $expiring_count ?> product<?= $expiring_count > 1 ? 's' : '' ?> expiring within 30 days!</strong></span>
                    </div>
                    <div class="notif-actions" onclick="event.stopPropagation()">
                        <button class="notif-minimize-btn" onclick="toggleNotif('notif-expiry')" id="notif-expiry-btn">
                            <span class="notif-chevron" id="notif-expiry-chevron">▼</span>
                            <span id="notif-expiry-label">Minimize</span>
                        </button>
                        <button class="notif-action-btn btn-later" onclick="dismissNotifSession('notif-expiry')">🕒 I'll manage this later</button>
                    </div>
                </div>
                <div class="notif-body" id="notif-expiry-body">
                    <?php foreach($expiring_rows as $ep): ?>
                    <div class="notif-item">
                        <span class="notif-dot dot-orange" style="flex-shrink:0;margin-top:3px;"></span>
                        <div>
                            <strong><?= htmlspecialchars($ep['name']) ?></strong>
                            <div style="font-size:11px;color:#b45309;">
                                Expires <?= date('M d, Y', strtotime($ep['expiry_date'])) ?>
                                • <?= max(0,(int)((strtotime($ep['expiry_date']) - time()) / 86400)) ?> days left
                                • Stock: <?= $ep['stock'] ?>
                            </div>
                        </div>
                        <a href="#" class="notif-action-btn btn-dispose"
                           onclick="openDelModal('dispose', <?= $ep['id'] ?>, '<?= htmlspecialchars(addslashes($ep['name'])) ?>')" style="margin-left:auto;">🗑 Dispose?</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Zero Sales Notification Banner -->
            <?php if ($zero_count > 0): ?>
            <div class="notif-banner notif-zerosales" id="notif-zerosales">
                <div class="notif-header" onclick="toggleNotif('notif-zerosales')" style="cursor:pointer;">
                    <div class="notif-title">
                        <span class="notif-dot dot-blue"></span>
                        <span>📉 <strong><?= $zero_count ?> products had zero sales in <?= $month_name ?> — you wanna keep selling these products?</strong></span>
                    </div>
                    <div class="notif-actions" onclick="event.stopPropagation()">
                        <button class="notif-minimize-btn" onclick="toggleNotif('notif-zerosales')" id="notif-zerosales-btn">
                            <span class="notif-chevron" id="notif-zerosales-chevron">▼</span>
                            <span id="notif-zerosales-label">Minimize</span>
                        </button>
                        <button class="notif-action-btn btn-later" onclick="dismissNotifSession('notif-zerosales')">🕒 I'll manage this later</button>
                    </div>
                </div>
                <div class="notif-body" id="notif-zerosales-body">
                    <?php foreach($zero_rows as $zp): ?>
                    <div class="notif-item">
                        <span class="notif-dot dot-blue" style="flex-shrink:0;margin-top:3px;"></span>
                        <div>
                            <strong><?= htmlspecialchars($zp['name']) ?></strong>
                            <div style="font-size:11px;color:#1d4ed8;"><?= htmlspecialchars($zp['category']) ?> • Stock: <?= $zp['stock'] ?></div>
                        </div>
                        <div style="margin-left:auto;display:flex;gap:6px;">
                            <button class="notif-action-btn btn-keep">✔ Keep</button>
                            <button class="notif-action-btn btn-stop"
                                onclick="openDelModal('stop', <?= $zp['id'] ?>, '<?= htmlspecialchars(addslashes($zp['name'])) ?>')">
                                🚫 Stop
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Out of Stock Notification Banner -->
            <?php if ($outofstock_count > 0): ?>
            <div class="notif-banner notif-outofstock" id="notif-outofstock">
                <div class="notif-header" onclick="toggleNotif('notif-outofstock')" style="cursor:pointer;">
                    <div class="notif-title">
                        <span class="notif-dot dot-red"></span>
                        <span>🚫 <strong><?= $outofstock_count ?> product<?= $outofstock_count > 1 ? 's' : '' ?> out of stock!</strong></span>
                    </div>
                    <div class="notif-actions" onclick="event.stopPropagation()">
                        <button class="notif-minimize-btn" onclick="toggleNotif('notif-outofstock')" id="notif-outofstock-btn">
                            <span class="notif-chevron" id="notif-outofstock-chevron">▼</span>
                            <span id="notif-outofstock-label">Minimize</span>
                        </button>
                        <button class="notif-action-btn btn-later" onclick="dismissNotifSession('notif-outofstock')">🕒 I'll manage this later</button>
                    </div>
                </div>
                <div class="notif-body" id="notif-outofstock-body">
                    <?php foreach($outofstock_rows as $op): ?>
                    <div class="notif-item">
                        <span class="notif-dot dot-red" style="flex-shrink:0;margin-top:3px;"></span>
                        <div>
                            <strong><?= htmlspecialchars($op['name']) ?></strong>
                            <div style="font-size:11px;color:#b91c1c;"><?= htmlspecialchars($op['category']) ?> • Stock: 0</div>
                        </div>
                        <a href="products.php?action=edit&id=<?= $op['id'] ?>" class="notif-action-btn btn-restock" style="margin-left:auto;">✏️ Restock</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- High Demand / Low Stock Notification Banner -->
            <?php if ($highdemand_count > 0): ?>
            <div class="notif-banner notif-highdemand" id="notif-highdemand">
                <div class="notif-header" onclick="toggleNotif('notif-highdemand')" style="cursor:pointer;">
                    <div class="notif-title">
                        <span class="notif-dot dot-purple"></span>
                        <span>📈 <strong><?= $highdemand_count ?> product<?= $highdemand_count > 1 ? 's are' : ' is' ?> in high demand — consider increasing minimum stock!</strong></span>
                    </div>
                    <div class="notif-actions" onclick="event.stopPropagation()">
                        <button class="notif-minimize-btn" onclick="toggleNotif('notif-highdemand')" id="notif-highdemand-btn">
                            <span class="notif-chevron" id="notif-highdemand-chevron">▼</span>
                            <span id="notif-highdemand-label">Minimize</span>
                        </button>
                        <button class="notif-action-btn btn-later" onclick="dismissNotifSession('notif-highdemand')">🕒 I'll manage this later</button>
                    </div>
                </div>
                <div class="notif-body" id="notif-highdemand-body">
                    <?php foreach($highdemand_rows as $hp): ?>
                    <div class="notif-item">
                        <span class="notif-dot dot-purple" style="flex-shrink:0;margin-top:3px;"></span>
                        <div>
                            <strong><?= htmlspecialchars($hp['name']) ?></strong>
                            <div style="font-size:11px;color:#6d28d9;">
                                <?= htmlspecialchars($hp['category']) ?>
                                • <?= formatMoney($hp['revenue']) ?> revenue in last 30 days
                                • Sold <?= (int)$hp['qty_sold'] ?> units
                                • Only <?= (int)$hp['stock'] ?> left in stock
                            </div>
                        </div>
                        <a href="#" class="notif-action-btn btn-increase" style="margin-left:auto;"
                           onclick="openStockModal(<?= (int)$hp['id'] ?>, '<?= htmlspecialchars(addslashes($hp['name'])) ?>', '<?= htmlspecialchars(addslashes($hp['category'])) ?>', <?= (float)$hp['price'] ?>, <?= (float)$hp['cost'] ?>, '<?= htmlspecialchars($hp['expiry_date'] ?? '') ?>', <?= (int)$hp['stock'] ?>, <?= (int)$hp['qty_sold'] ?>); return false;">
                            📈 Increase Stock
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:22px;">
                <div class="stat-card">
                    <div class="stat-icon green">📦</div>
                    <div class="stat-info">
                        <div class="stat-label">Total Products</div>
                        <div class="stat-value"><?= $total_products ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">📊</div>
                    <div class="stat-info">
                        <div class="stat-label">Total Stock</div>
                        <div class="stat-value"><?= number_format($total_stock) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">💰</div>
                    <div class="stat-info">
                        <div class="stat-label">Average Price</div>
                        <div class="stat-value"><?= formatMoney($avg_price) ?></div>
                    </div>
                </div>
            </div>

            <div class="two-col">
                <!-- Form -->
                <div class="form-card">
                    <h3><?= $edit_product ? '✏️ Edit Product' : '➕ Add New Product' ?></h3>
                    <form method="POST" id="productForm">
                        <?php if ($edit_product): ?>
                            <input type="hidden" name="id" value="<?= $edit_product['id'] ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Product Name</label>
                            <input type="text" name="name" class="form-control" placeholder="Enter product name"
                                value="<?= htmlspecialchars($edit_product['name'] ?? $_POST['name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" class="form-control" placeholder="Enter category name"
                                value="<?= htmlspecialchars($edit_product['category'] ?? $_POST['category'] ?? '') ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Selling Price (₱)</label>
                                <input type="number" name="price" class="form-control" step="0.01" min="0" placeholder="0.00"
                                    value="<?= $edit_product['price'] ?? '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Cost Price (₱)</label>
                                <input type="number" name="cost" id="prodCost" class="form-control" step="0.01" min="0" placeholder="0.00"
                                    value="<?= $edit_product['cost'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Stock Quantity</label>
                            <input type="number" name="stock" class="form-control" min="0" placeholder="0"
                                value="<?= $edit_product['stock'] ?? '' ?>">
                        </div>
                        <!-- EXPIRY DATE FIELD -->
                        <div class="form-group">
                            <label>Expiry Date <span style="font-size:10px;color:var(--gray);font-weight:400;"></span></label>
                            <input type="date" name="expiry_date" class="form-control"
                                value="<?= htmlspecialchars($edit_product['expiry_date'] ?? $_POST['expiry_date'] ?? '') ?>">
                        </div>
                        <?php if ($product_budget > 0): ?>
                        <div style="font-size:10.5px;color:#aaa;margin:-8px 0 14px;">💡 "Product" category monthly budget: <?= formatMoney($product_budget) ?> (₱<?= number_format($product_month_spent, 2) ?> already recorded this month)</div>
                        <?php endif; ?>
                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="btn-submit"><?= $edit_product ? 'Update Product' : 'Add Product' ?></button>
                            <?php if ($edit_product): ?>
                            <a href="products.php" class="btn-submit" style="background:var(--gray);text-decoration:none;">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Product List -->
                <div class="table-card">
                    <div class="table-card-header" style="flex-direction:column;align-items:stretch;gap:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <h3>All Products</h3>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <a href="export_excel.php?type=products"
                                   style="display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:#217346;color:white;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;"
                                   onmouseover="this.style.background='#185c38'" onmouseout="this.style.background='#217346'">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                    Export Excel
                                </a>
                                <span class="badge badge-green" id="prod-count"><?= $total_products ?> items</span>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:13px;color:#aaa;pointer-events:none;">🔍</span>
                            <input type="text" id="prod-search-bar" placeholder="Search by name or category…"
                                oninput="filterProducts()"
                                style="width:100%;box-sizing:border-box;padding:9px 34px 9px 34px;border:2px solid var(--gray-mid);border-radius:8px;font-size:12.5px;font-family:'Poppins',sans-serif;outline:none;transition:border-color .2s;"
                                onfocus="this.style.borderColor='var(--green-main)'"
                                onblur="this.style.borderColor='var(--gray-mid)'">
                            <button onclick="clearProdSearch()" id="prod-search-clr"
                                style="display:none;position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:13px;color:#aaa;padding:2px 5px;border-radius:4px;"
                                onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='none'">✕</button>
                        </div>
                        <div id="prod-search-info" style="font-size:11px;color:var(--gray);min-height:14px;"></div>
                        <!-- Sort Buttons -->
                        <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
                            <span style="font-size:11px;font-weight:600;color:var(--gray);margin-right:2px;">Sort by:</span>
                            <button class="sort-btn active" id="sort-date" onclick="sortProducts('date')" title="Pinakabago">
                                🕒 Date Recorded
                            </button>
                            <button class="sort-btn" id="sort-alpha" onclick="sortProducts('alpha')" title="A hanggang Z">
                                🔤 A–Z
                            </button>
                            <button class="sort-btn" id="sort-price-desc" onclick="sortProducts('price-desc')" title="Pinakamahal">
                                💰 Highest Price
                            </button>
                            <button class="sort-btn" id="sort-price-asc" onclick="sortProducts('price-asc')" title="Pinakamura">
                                💸 Lowest Price
                            </button>
                            <button class="sort-btn" id="sort-expiry" onclick="sortProducts('expiry')" title="Pinaka-malapit mag-expire">
                                📅 Expiry Date
                            </button>
                        </div>
                    </div>
                    <div style="max-height:420px;overflow-y:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Product</th>
                                <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Price</th>
                                <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Stock</th>
                                <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Expiry</th>
                                <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products): while($p = $products->fetch_assoc()):
                                $badge = expiryBadge($p['expiry_date'] ?? null);
                            ?>
                            <tr class="prod-row"
                                data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                                data-cat="<?= strtolower(htmlspecialchars($p['category'])) ?>"
                                data-price="<?= $p['price'] ?>"
                                data-date="<?= $p['created_at'] ?? '' ?>"
                                data-expiry="<?= htmlspecialchars($p['expiry_date'] ?? '') ?>">
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?= htmlspecialchars($p['category']) ?></div>
                                    <?php if (!empty($p['created_at'])): ?>
                                    <div style="font-size:10px;color:#b0b0b0;margin-top:2px;">🕒 Recorded: <?= date('M d, Y h:i A', strtotime($p['created_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatMoney($p['price']) ?></td>
                                <td>
                                    <span class="badge <?= $p['stock'] < 10 ? 'badge-red' : 'badge-green' ?>"><?= $p['stock'] ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($badge['class'])): ?>
                                        <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                        <?php if ($badge['class'] !== 'badge-green' && !empty($badge['date'])): ?>
                                            <div style="font-size:10px;color:var(--gray);margin-top:2px;"><?= $badge['date'] ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#aaa;font-size:11px;"><?= $badge['label'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="products.php?action=edit&id=<?= $p['id'] ?>" class="btn-edit">Edit</a>
                                    <button class="btn-danger" onclick="openDelModal('delete', <?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')" style="border:none;cursor:pointer;">Del</button>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="5" style="text-align:center;color:var(--gray);">No products yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
/* ── Delete Modal ── */
.del-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
    z-index: 9999; align-items: center; justify-content: center;
}
.del-modal-overlay.active { display: flex; animation: fadeOverlay .2s ease; }
.del-modal {
    background: #fff; border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    padding: 36px 32px 28px; width: 100%; max-width: 380px;
    text-align: center; animation: popIn .25s cubic-bezier(.34,1.56,.64,1);
}
.del-modal-icon {
    width: 64px; height: 64px; background: #fff0f0; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; margin: 0 auto 18px; border: 3px solid #ffd6d6;
}
.del-modal h3 { font-size: 19px; font-weight: 800; color: #1a1a2e; margin: 0 0 8px; }
.del-modal p  { font-size: 13px; color: #888; margin: 0 0 26px; line-height: 1.6; }
.del-modal p strong { color: #444; font-weight: 700; }
.del-modal-actions { display: flex; gap: 10px; }
.btn-modal-cancel {
    flex: 1; padding: 12px 0; border-radius: 10px;
    border: 1.5px solid #e0e0e0; background: #f7f7f7;
    color: #555; font-size: 13px; font-weight: 700; cursor: pointer;
    transition: background .18s; font-family: inherit;
}
.btn-modal-cancel:hover { background: #ebebeb; }
.btn-modal-delete {
    flex: 1; padding: 12px 0; border-radius: 10px; border: none;
    background: linear-gradient(135deg, #ff4d4d, #dc3545);
    color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;
    text-decoration: none; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(220,53,69,.35); transition: transform .15s, box-shadow .15s;
}
.btn-modal-delete:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(220,53,69,.45); }
@keyframes fadeOverlay { from { opacity:0; } to { opacity:1; } }
@keyframes popIn { from { opacity:0; transform:scale(.88); } to { opacity:1; transform:scale(1); } }

/* ── Budget Exceed Modal ── */
.exceed-modal-icon { background:#fff8e1; border-color:#ffe0a3; }
.exceed-detail-box {
    background:#fafafa; border-radius:10px; padding:12px 16px; margin:0 0 22px;
    text-align:left; font-size:12px;
}
.exceed-detail-box div { display:flex; justify-content:space-between; padding:3px 0; }
.exceed-detail-box .exceed-label { color:#888; }
.exceed-detail-box .exceed-val { font-weight:700; color:#333; }
.exceed-detail-box .exceed-over { color:#dc3545; }
.btn-modal-proceed {
    flex: 1; padding: 12px 0; border-radius: 10px; border: none;
    background: linear-gradient(135deg,#ffb74d,#f59e0b);
    color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 14px rgba(245,158,11,.35); transition: transform .15s, box-shadow .15s;
}
.btn-modal-proceed:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(245,158,11,.45); }

/* ── Increase Stock Modal ── */
.stock-modal-icon { background:#f5f3ff; border-color:#ddd6fe; }

.notif-banner {
    border-radius: 12px;
    margin-bottom: 14px;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s;
}
.notif-expiry    { background: #fffbeb; border-color: #fde68a; }
.notif-zerosales { background: #eff6ff; border-color: #bfdbfe; }
.notif-outofstock { background: #fef2f2; border-color: #fecaca; }
.notif-highdemand { background: #f5f3ff; border-color: #ddd6fe; }

.notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px;
    gap: 10px;
    user-select: none;
}
.notif-expiry .notif-header:hover    { background: rgba(251,191,36,0.08); }
.notif-zerosales .notif-header:hover { background: rgba(59,130,246,0.06); }
.notif-outofstock .notif-header:hover { background: rgba(239,68,68,0.06); }
.notif-highdemand .notif-header:hover { background: rgba(139,92,246,0.08); }

.notif-title {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 13px;
    color: #78350f;
    flex: 1;
    min-width: 0;
}
.notif-zerosales .notif-title { color: #1e3a8a; }
.notif-outofstock .notif-title { color: #991b1b; }
.notif-highdemand .notif-title { color: #5b21b6; }

.notif-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.dot-orange { background: #f59e0b; }
.dot-blue   { background: #3b82f6; }
.dot-red    { background: #ef4444; }
.dot-purple { background: #8b5cf6; }

.notif-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-shrink: 0;
}

.notif-minimize-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 8px;
    border: 1.5px solid #f59e0b;
    background: #fef3c7;
    color: #92400e;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, transform 0.1s;
    font-family: inherit;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.notif-zerosales .notif-minimize-btn {
    border-color: #3b82f6;
    background: #dbeafe;
    color: #1e40af;
}
.notif-outofstock .notif-minimize-btn {
    border-color: #ef4444;
    background: #fee2e2;
    color: #991b1b;
}
.notif-highdemand .notif-minimize-btn {
    border-color: #8b5cf6;
    background: #ede9fe;
    color: #5b21b6;
}
.notif-minimize-btn:hover { background: #fde68a; border-color: #d97706; }
.notif-zerosales .notif-minimize-btn:hover { background: #bfdbfe; border-color: #2563eb; }
.notif-outofstock .notif-minimize-btn:hover { background: #fecaca; border-color: #dc2626; }
.notif-highdemand .notif-minimize-btn:hover { background: #ddd6fe; border-color: #7c3aed; }
.notif-minimize-btn:active { transform: scale(0.97); }

.notif-chevron {
    display: inline-block;
    font-size: 9px;
    transition: transform 0.25s ease;
}
.notif-chevron.collapsed { transform: rotate(180deg); }

.notif-dismiss-btn {
    padding: 5px 11px;
    border-radius: 7px;
    border: 1.5px solid #fca5a5;
    background: white;
    color: #b91c1c;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    font-family: inherit;
}
.notif-dismiss-btn:hover { background: #fee2e2; }

.notif-body {
    padding: 0 16px 13px;
    max-height: 260px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: max-height 0.3s ease, opacity 0.25s ease, padding 0.25s ease;
}
.notif-body.minimized {
    max-height: 0 !important;
    opacity: 0;
    padding-bottom: 0;
    overflow: hidden;
}

.notif-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    border-radius: 8px;
    padding: 9px 12px;
    border: 1px solid rgba(0,0,0,0.06);
    font-size: 12.5px;
}

.notif-action-btn {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    font-family: inherit;
    white-space: nowrap;
}
.btn-dispose { background: #fee2e2; color: #b91c1c; text-decoration: none; }
.btn-dispose:hover { background: #fca5a5; }
.btn-keep    { background: #d1fae5; color: #065f46; }
.btn-keep:hover { background: #6ee7b7; }
.btn-stop    { background: #fee2e2; color: #b91c1c; }
.btn-stop:hover { background: #fca5a5; }
.btn-later   { background: #f3f4f6; color: #444; }
.btn-later:hover { background: #e5e7eb; }
.btn-restock { background: #dbeafe; color: #1d4ed8; text-decoration: none; }
.btn-restock:hover { background: #bfdbfe; }
.btn-increase { background: #ede9fe; color: #5b21b6; text-decoration: none; }
.btn-increase:hover { background: #ddd6fe; }

.notif-banner.is-minimized .notif-header { border-radius: 10px; }

/* ── Sort Buttons ── */
.sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1.5px solid #e0e0e0;
    background: #f8f9fa;
    color: #555;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    font-family: inherit;
    white-space: nowrap;
}
.sort-btn:hover {
    border-color: var(--green-main, #2d7a4f);
    color: var(--green-main, #2d7a4f);
    background: #f0faf4;
}
.sort-btn.active {
    background: var(--green-main, #2d7a4f);
    color: white;
    border-color: var(--green-main, #2d7a4f);
}
.sort-btn.active:hover { background: #1e5c3a; color: white; }

/* ── Expiry Badge ── */
.badge-orange {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffc107;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 700;
}
</style>

<script>
// ── Delete Modal ──────────────────────────────────────────────────
function openDelModal(type, id, name) {
    const title   = document.getElementById('delModalTitle');
    const icon    = document.getElementById('delModalIcon');
    const nameEl  = document.getElementById('delModalName');
    const btn     = document.getElementById('delModalConfirmBtn');
    const p       = document.querySelector('#delModalOverlay .del-modal p');

    nameEl.textContent = name || 'this product';

    if (type === 'dispose') {
        icon.textContent  = '🗑️';
        title.textContent = 'Dispose Product?';
        p.innerHTML       = "You're about to dispose<br><strong>" + (name || 'this product') + "</strong>.<br>This action <strong>cannot be undone</strong>.";
        btn.textContent   = 'Yes, Dispose';
        btn.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
        btn.style.boxShadow  = '0 4px 14px rgba(217,119,6,.35)';
    } else if (type === 'stop') {
        icon.textContent  = '🚫';
        title.textContent = 'Stop Selling?';
        p.innerHTML       = "You're about to stop selling<br><strong>" + (name || 'this product') + "</strong>.<br>This action <strong>cannot be undone</strong>.";
        btn.textContent   = 'Yes, Stop';
        btn.style.background = 'linear-gradient(135deg, #ff4d4d, #dc3545)';
        btn.style.boxShadow  = '0 4px 14px rgba(220,53,69,.35)';
    } else {
        icon.textContent  = '🗑️';
        title.textContent = 'Delete Product?';
        p.innerHTML       = "You're about to permanently delete<br><strong>" + (name || 'this product') + "</strong>.<br>This action <strong>cannot be undone</strong>.";
        btn.textContent   = 'Yes, Delete';
        btn.style.background = 'linear-gradient(135deg, #ff4d4d, #dc3545)';
        btn.style.boxShadow  = '0 4px 14px rgba(220,53,69,.35)';
    }

    btn.href = 'products.php?action=delete&id=' + id;
    document.getElementById('delModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeDelModal() {
    document.getElementById('delModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('delModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeDelModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeDelModal(); closeExceedModal(); closeStockModal(); }
});

// ── Increase Stock Modal (High Demand notification) ───────────────
function openStockModal(id, name, category, price, cost, expiry, currentStock, soldCount) {
    document.getElementById('stockModalName').textContent      = name || 'This product';
    document.getElementById('stockModalSold').textContent      = soldCount;
    document.getElementById('stockModalCurrent').textContent   = currentStock;

    document.getElementById('stockModalId').value              = id;
    document.getElementById('stockModalNameField').value       = name;
    document.getElementById('stockModalCategoryField').value   = category;
    document.getElementById('stockModalPriceField').value      = price;
    document.getElementById('stockModalCostField').value       = cost;
    document.getElementById('stockModalExpiryField').value     = expiry;

    // Suggest a sensible bump: current stock + amount sold in the last 30 days
    document.getElementById('stockModalNewStock').value = currentStock + soldCount;

    document.getElementById('stockModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeStockModal() {
    document.getElementById('stockModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
function submitStockUpdate() {
    const newStock = parseInt(document.getElementById('stockModalNewStock').value, 10);
    if (isNaN(newStock) || newStock < 0) return;
    document.getElementById('stockUpdateForm').submit();
}
document.getElementById('stockModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeStockModal();
});

// ── Budget exceed check before adding a new product ─────────────────
// Only applies when ADDING a new product (editing doesn't record an expense).
const productBudget      = <?= json_encode($product_budget) ?>;
const productMonthSpent  = <?= json_encode($product_month_spent) ?>;
const productForm        = document.getElementById('productForm');
const isEditingProduct   = !!productForm.querySelector('input[name="id"]');
let forceProductSubmit   = false;

function formatPeso(n) {
    return '₱' + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function closeExceedModal() {
    document.getElementById('exceedModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
function proceedAnyway() {
    forceProductSubmit = true;
    closeExceedModal();
    productForm.submit();
}

if (!isEditingProduct) {
    productForm.addEventListener('submit', function(e) {
        if (forceProductSubmit) return; // user already confirmed

        if (!productBudget) return; // no budget set for "Product" category

        const costInput = document.getElementById('prodCost');
        const cost = parseFloat(costInput.value) || 0;
        if (cost <= 0) return; // no expense will be recorded

        const newTotal = productMonthSpent + cost;
        if (newTotal > productBudget) {
            e.preventDefault();
            document.getElementById('exceedLimit').textContent    = formatPeso(productBudget);
            document.getElementById('exceedCurrent').textContent  = formatPeso(productMonthSpent);
            document.getElementById('exceedThis').textContent     = formatPeso(cost);
            document.getElementById('exceedNewTotal').textContent = formatPeso(newTotal);
            document.getElementById('exceedOverBy').textContent   = formatPeso(newTotal - productBudget);
            document.getElementById('exceedModalOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    });
}

// ── Notification minimize / dismiss ──────────────────────────────
function toggleNotif(id) {
    const body    = document.getElementById(id + '-body');
    const chevron = document.getElementById(id + '-chevron');
    const label   = document.getElementById(id + '-label');
    const banner  = document.getElementById(id);
    const isMin   = body.classList.toggle('minimized');
    chevron.textContent = isMin ? '▲' : '▼';
    if (label) label.textContent = isMin ? 'Expand' : 'Minimize';
    banner.classList.toggle('is-minimized', isMin);
    try { localStorage.setItem('notif_' + id, isMin ? '1' : '0'); } catch(e) {}
}

function dismissNotif(id) {
    const banner = document.getElementById(id);
    if (!banner) return;
    banner.style.transition   = 'opacity 0.3s, max-height 0.35s, margin 0.3s';
    banner.style.opacity      = '0';
    banner.style.maxHeight    = '0';
    banner.style.marginBottom = '0';
    banner.style.overflow     = 'hidden';
    setTimeout(() => banner.remove(), 370);
    try { localStorage.setItem('notif_dismissed_' + id, '1'); } catch(e) {}
}

// Hides the banner for THIS page view only — nothing is saved,
// so it will show up again next time the page is opened (as long
// as the underlying condition, e.g. out-of-stock products, still applies).
function dismissNotifSession(id) {
    const banner = document.getElementById(id);
    if (!banner) return;
    banner.style.transition   = 'opacity 0.3s, max-height 0.35s, margin 0.3s';
    banner.style.opacity      = '0';
    banner.style.maxHeight    = '0';
    banner.style.marginBottom = '0';
    banner.style.overflow     = 'hidden';
    setTimeout(() => banner.remove(), 370);
}

// Restore state on load
document.addEventListener('DOMContentLoaded', function () {
    try {
        const savedSort = localStorage.getItem('prod_sort');
        if (savedSort) sortProducts(savedSort);
    } catch(e) {}

    // All notification banners (expiry, zero sales, out of stock, high demand)
    // should NEVER be permanently dismissed via the "later" button —
    // clear any leftover flags saved during earlier testing, just in case.
    try {
        localStorage.removeItem('notif_dismissed_notif-expiry');
        localStorage.removeItem('notif_dismissed_notif-zerosales');
        localStorage.removeItem('notif_dismissed_notif-outofstock');
        localStorage.removeItem('notif_dismissed_notif-highdemand');
    } catch(e) {}

    ['notif-expiry', 'notif-zerosales', 'notif-outofstock', 'notif-highdemand'].forEach(function(id) {
        try {
            if (localStorage.getItem('notif_dismissed_' + id) === '1') {
                const b = document.getElementById(id);
                if (b) b.remove();
                return;
            }
            if (localStorage.getItem('notif_' + id) === '1') {
                const body    = document.getElementById(id + '-body');
                const chevron = document.getElementById(id + '-chevron');
                const label   = document.getElementById(id + '-label');
                const banner  = document.getElementById(id);
                if (body)    body.classList.add('minimized');
                if (chevron) chevron.textContent = '▲';
                if (label)   label.textContent = 'Expand';
                if (banner)  banner.classList.add('is-minimized');
            }
        } catch(e) {}
    });
});

// ── Sort ─────────────────────────────────────────────────────────
function sortProducts(mode) {
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
    const btnMap = { 'date': 'sort-date', 'alpha': 'sort-alpha', 'price-desc': 'sort-price-desc', 'price-asc': 'sort-price-asc', 'expiry': 'sort-expiry' };
    const activeBtn = document.getElementById(btnMap[mode]);
    if (activeBtn) activeBtn.classList.add('active');

    const tbody = document.querySelector('.data-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr.prod-row'));

    rows.sort((a, b) => {
        if (mode === 'date') {
            return (b.dataset.date || '').localeCompare(a.dataset.date || '');
        } else if (mode === 'alpha') {
            return a.dataset.name.localeCompare(b.dataset.name);
        } else if (mode === 'price-desc') {
            return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        } else if (mode === 'price-asc') {
            return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        } else if (mode === 'expiry') {
            const aVal = a.dataset.expiry || '9999-99-99';
            const bVal = b.dataset.expiry || '9999-99-99';
            return aVal.localeCompare(bVal);
        }
        return 0;
    });

    const nr = document.getElementById('prod-no-results');
    if (nr) nr.remove();
    rows.forEach(r => tbody.appendChild(r));
    filterProducts();

    try { localStorage.setItem('prod_sort', mode); } catch(e) {}
}

// ── Search / Filter ───────────────────────────────────────────────
function filterProducts() {
    const q     = document.getElementById('prod-search-bar').value.trim().toLowerCase();
    const clr   = document.getElementById('prod-search-clr');
    const info  = document.getElementById('prod-search-info');
    const badge = document.getElementById('prod-count');
    clr.style.display = q ? 'inline-block' : 'none';
    const rows = document.querySelectorAll('.prod-row');
    let n = 0;
    rows.forEach(row => {
        const match = !q || row.dataset.name.includes(q) || row.dataset.cat.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) n++;
    });
    badge.textContent = n + ' item' + (n !== 1 ? 's' : '');
    info.innerHTML = q ? 'Showing <strong style="color:var(--green-main)">' + n + '</strong> result' + (n !== 1 ? 's' : '') + ' for &ldquo;<strong>' + q + '</strong>&rdquo;' : '';
    let nr = document.getElementById('prod-no-results');
    if (!nr) {
        nr = document.createElement('tr');
        nr.id = 'prod-no-results';
        nr.innerHTML = '<td colspan="5" style="text-align:center;padding:28px;color:var(--gray);"><div style="font-size:22px;margin-bottom:6px;">🔍</div><div style="font-weight:600;">No products found</div><div style="font-size:11px;margin-top:3px;">Try a different search term</div></td>';
        document.querySelector('.prod-row')?.parentNode.appendChild(nr);
    }
    nr.style.display = n === 0 ? '' : 'none';
}
function clearProdSearch() {
    document.getElementById('prod-search-bar').value = '';
    filterProducts();
    document.getElementById('prod-search-bar').focus();
}
</script>
</body>
</html>