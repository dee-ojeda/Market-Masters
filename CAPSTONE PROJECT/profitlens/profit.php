<?php
require_once 'includes/config.php';
requireAdmin();
$db = getDB();

$success = $error = '';
$action  = $_GET['action'] ?? 'list';
$view_year = intval($_GET['year'] ?? date('Y'));

// Handle add sale
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity   = intval($_POST['quantity'] ?? 1);
    $sale_date  = $_POST['sale_date'] ?? date('Y-m-d');
    if ($product_id && $quantity > 0) {
        $prod = $db->query("SELECT price, stock, name FROM products WHERE id=$product_id")->fetch_assoc();
        if ($prod) {
            if ($prod['stock'] <= 0) {
                $error = '⚠️ Out of stock! <strong>' . htmlspecialchars($prod['name']) . '</strong> has no available stock. Sale was not recorded.';
            } elseif ($quantity > $prod['stock']) {
                $error = '⚠️ Not enough stock! <strong>' . htmlspecialchars($prod['name']) . '</strong> only has <strong>' . $prod['stock'] . '</strong> unit(s) available. Sale was not recorded.';
            } else {
                $unit_price = $prod['price'];
                $total      = $unit_price * $quantity;
                $stmt = $db->prepare("INSERT INTO sales (product_id, quantity, unit_price, total, sale_date) VALUES (?,?,?,?,?)");
                $stmt->bind_param("iidds", $product_id, $quantity, $unit_price, $total, $sale_date);
                if ($stmt->execute()) {
                    $db->query("UPDATE products SET stock = stock - $quantity WHERE id=$product_id");
                    $success = 'Sale recorded successfully!';
                } else { $error = 'Error recording sale.'; }
            }
        }
    } else { $error = 'Please fill all required fields.'; }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db->query("DELETE FROM sales WHERE id=$id");
    $success = 'Sale deleted.';
}

$cur_year   = intval(date('Y'));
$cur_month  = intval(date('m'));

// Compute last month and year correctly
if ($cur_month == 1) {
    $last_year  = $cur_year - 1;
    $last_mon   = 12;
} else {
    $last_year  = $cur_year;
    $last_mon   = $cur_month - 1;
}

$month      = date('Y-m');
$last_month = sprintf('%04d-%02d', $last_year, $last_mon);

// Use YEAR() + MONTH() for reliable matching — avoids DATE_FORMAT string issues
$cur  = $db->query("SELECT SUM(total) as sales, COUNT(*) as count FROM sales WHERE YEAR(sale_date)=$cur_year AND MONTH(sale_date)=$cur_month")->fetch_assoc();
$exp  = $db->query("SELECT SUM(amount) as total FROM expenses WHERE YEAR(expense_date)=$cur_year AND MONTH(expense_date)=$cur_month")->fetch_assoc();
$last = $db->query("SELECT SUM(total) as sales FROM sales WHERE YEAR(sale_date)=$last_year AND MONTH(sale_date)=$last_mon")->fetch_assoc();

$total_sales    = floatval($cur['sales'] ?? 0);
$total_expenses = floatval($exp['total'] ?? 0);
$net_profit     = $total_sales - $total_expenses;
$last_sales     = floatval($last['sales'] ?? 0);
$growth = $last_sales > 0 ? (($total_sales - $last_sales) / $last_sales) * 100 : ($total_sales > 0 ? 100 : 0);

// Full Jan-Dec chart for selected year
$chart_months  = [];
$chart_labels  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
for ($m = 1; $m <= 12; $m++) {
    $ym  = sprintf('%04d-%02d', $view_year, $m);
    $row = $db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym'")->fetch_assoc();
    $chart_months[] = floatval($row['t']);
}
$chart_max = max($chart_months) ?: 1;

// Available years for selector
$years_res = $db->query("SELECT DISTINCT YEAR(sale_date) as y FROM sales ORDER BY y DESC");
$available_years = [];
while ($yr = $years_res->fetch_assoc()) $available_years[] = $yr['y'];
if (!in_array(date('Y'), $available_years)) array_unshift($available_years, date('Y'));

// Sales history list
$sales    = $db->query("SELECT s.*, p.name as product_name FROM sales s LEFT JOIN products p ON s.product_id=p.id ORDER BY s.sale_date DESC, s.id DESC LIMIT 500");
$products = $db->query("SELECT id, name, price, stock, category FROM products ORDER BY name");
$products_arr = [];
if ($products) { $products->data_seek(0); while($pr = $products->fetch_assoc()) $products_arr[] = $pr; }

// Count rows for JS
$sales_arr = [];
if ($sales) while($r = $sales->fetch_assoc()) $sales_arr[] = $r;

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit This Month - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Chart */
        .bar-chart-wrap { display:flex; align-items:flex-end; gap:5px; height:130px; padding:0 4px; }
        .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; }
        .bar-col .bar-val { font-size:8px; color:var(--gray); font-weight:600; text-align:center; white-space:nowrap; }
        .bar-col .bar-body { width:100%; border-radius:4px 4px 0 0; background:linear-gradient(to top,var(--green-dark),var(--green-bright)); transition:height .3s; }
        .bar-col .bar-lbl { font-size:8.5px; color:var(--gray); font-weight:600; margin-top:3px; }
        .bar-col.current-month .bar-body { background:linear-gradient(to top,#f39c12,#f1c40f); }
        /* Search */
        .sw { position:relative; display:flex; align-items:center; }
        .sw .si { position:absolute; left:10px; font-size:13px; color:var(--gray); pointer-events:none; }
        .sw input { padding:8px 32px 8px 30px; border:2px solid var(--gray-mid); border-radius:8px;
            font-size:12px; font-family:'Poppins',sans-serif; outline:none; transition:border-color .2s; width:240px; }
        .sw input:focus { border-color:var(--green-main); }
        .sw .sc { position:absolute; right:8px; background:none; border:none; cursor:pointer; font-size:12px;
            color:var(--gray); display:none; padding:2px 4px; border-radius:3px; }
        .sw .sc:hover { background:var(--gray-mid); }
        /* Pills */
        .pills { display:flex; gap:5px; }
        .pill { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600; cursor:pointer;
            border:1.5px solid var(--gray-mid); background:white; color:var(--gray); transition:all .2s;
            font-family:'Poppins',sans-serif; }
        .pill.on { background:var(--green-main); color:white; border-color:var(--green-main); }
        .sinfo { font-size:11px; color:var(--gray); min-height:16px; }
        .sinfo span { font-weight:700; color:var(--green-main); }
        /* Year selector */
        .year-select { padding:6px 12px; border:2px solid var(--gray-mid); border-radius:8px;
            font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; outline:none;
            cursor:pointer; transition:border-color .2s; }
        .year-select:focus { border-color:var(--green-main); }
        /* Admin badge */
        .admin-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px;
            background:#fff3e0; color:#e67e22; border-radius:20px; font-size:10px; font-weight:700;
            letter-spacing:.4px; text-transform:uppercase; }
        /* Custom searchable product dropdown */
        .prod-dropdown { position:relative; width:100%; }
        .prod-search-input {
            width:100%; box-sizing:border-box;
            padding:10px 36px 10px 14px;
            border:2px solid var(--gray-mid); border-radius:8px;
            font-size:13px; font-family:'Poppins',sans-serif;
            outline:none; transition:border-color .2s; cursor:pointer;
            background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 12px center;
            padding-left:34px;
        }
        .prod-search-input:focus { border-color:var(--green-main); box-shadow:0 0 0 3px rgba(39,174,96,.12); }
        .prod-list {
            display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:999;
            background:white; border:2px solid var(--green-main); border-radius:8px;
            box-shadow:0 8px 24px rgba(0,0,0,.13); max-height:230px; overflow-y:auto;
        }
        .prod-list.open { display:block; }
        .prod-item {
            padding:9px 14px; font-size:12.5px; font-family:'Poppins',sans-serif;
            cursor:pointer; transition:background .12s; border-bottom:1px solid #f0f0f0;
        }
        .prod-item:last-child { border-bottom:none; }
        .prod-item:hover, .prod-item.focused { background:var(--green-light,#e8f5e9); color:var(--green-dark,#1b5e20); }
        .prod-item.selected { background:var(--green-main,#27ae60); color:white; }
        .prod-item.out { color:#dc3545; font-weight:600; }
        .prod-no-results { padding:14px; text-align:center; color:#aaa; font-size:12px; font-family:'Poppins',sans-serif; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">
                <p>View Sales &amp; Profit</p>
                <h1>Profit This Month</h1>
            </div>
            <div class="topbar-user">
                <span class="admin-badge">🔐 Admin</span>
                <div class="topbar-avatar">👤</div>
            </div>
        </div>

        <div class="page-content">
            <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon green">💰</div>
                    <div class="stat-info"><div class="stat-label">Total Sales (This Month)</div>
                        <div class="stat-value"><?= formatMoney($total_sales) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">📉</div>
                    <div class="stat-info"><div class="stat-label">Total Expenses</div>
                        <div class="stat-value"><?= formatMoney($total_expenses) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">📈</div>
                    <div class="stat-info"><div class="stat-label">Net Profit</div>
                        <div class="stat-value" style="color:<?= $net_profit>=0?'var(--green-bright)':'#dc3545' ?>"><?= formatMoney($net_profit) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">📊</div>
                    <div class="stat-info"><div class="stat-label">Growth vs Last Month</div>
                        <div class="stat-value" style="color:<?= $growth>=0?'var(--green-bright)':'#dc3545' ?>"><?= $last_sales <= 0 && $total_sales > 0 ? 'NEW +100%' : (($growth>=0?'+':'').number_format($growth,1).'%') ?></div></div>
                </div>
            </div>

            <!-- Jan–Dec Bar Chart -->
            <div class="table-card" style="margin-bottom:22px;">
                <div class="table-card-header">
                    <h3>📊 Monthly Sales — Jan to Dec</h3>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <a href="export_excel.php?type=sales&year=<?= $view_year ?>"
                           style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#217346;color:white;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;"
                           onmouseover="this.style.background='#185c38'" onmouseout="this.style.background='#217346'">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Export to Excel
                        </a>
                        <form method="GET" style="display:flex;align-items:center;gap:8px;">
                            <label style="font-size:12px;font-weight:600;color:var(--gray);">Year:</label>
                            <select name="year" class="year-select" onchange="this.form.submit()">
                                <?php foreach($available_years as $y): ?>
                                <option value="<?= $y ?>" <?= $y==$view_year?'selected':'' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <div style="padding:20px 24px 14px;">
                    <div class="bar-chart-wrap">
                        <?php
                        $cur_month_num = date('n');
                        $cur_year      = date('Y');
                        foreach ($chart_months as $idx => $val):
                            $h    = max(6, round(($val / $chart_max) * 120));
                            $lbl  = $chart_labels[$idx];
                            $isCur = ($view_year == $cur_year && ($idx+1) == $cur_month_num);
                        ?>
                        <div class="bar-col <?= $isCur ? 'current-month' : '' ?>">
                            <div class="bar-val"><?= $val>0 ? '₱'.number_format($val/1000,1).'k' : '' ?></div>
                            <div class="bar-body" style="height:<?= $h ?>px;" title="<?= $lbl.' '.$view_year.': '.formatMoney($val) ?>"></div>
                            <div class="bar-lbl"><?= $lbl ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:14px;margin-top:12px;font-size:11px;flex-wrap:wrap;">
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:12px;height:8px;background:linear-gradient(to right,var(--green-dark),var(--green-bright));border-radius:2px;display:inline-block;"></span>Sales Revenue
                        </span>
                        <?php if ($view_year == $cur_year): ?>
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:12px;height:8px;background:linear-gradient(to right,#f39c12,#f1c40f);border-radius:2px;display:inline-block;"></span>Current Month
                        </span>
                        <?php endif; ?>
                        <span style="color:var(--gray);margin-left:auto;">
                            Total <?= $view_year ?>: <strong style="color:var(--green-main)"><?= formatMoney(array_sum($chart_months)) ?></strong>
                        </span>
                    </div>
                </div>
            </div>

            <div class="two-col">
                <!-- Record Sale Form -->
                <div class="form-card">
                    <h3>📦 Record New Sale</h3>
                    <form method="POST" id="sale-form" onsubmit="return checkStock()">
                        <div class="form-group">
                            <label>Product</label>
                            <!-- Hidden real input for form submission -->
                            <input type="hidden" name="product_id" id="product-select" required>
                            <div class="prod-dropdown" id="prod-dropdown">
                                <input type="text" id="prod-search" class="prod-search-input"
                                    placeholder="🔍 Search product…" autocomplete="off"
                                    onfocus="openProdList()" oninput="filterProdList()">
                                <div class="prod-list" id="prod-list">
                                    <?php foreach($products_arr as $p): ?>
                                    <div class="prod-item <?= $p['stock'] <= 0 ? 'out' : '' ?>"
                                        data-id="<?= $p['id'] ?>"
                                        data-stock="<?= $p['stock'] ?>"
                                        data-price="<?= $p['price'] ?>"
                                        data-label="<?= htmlspecialchars($p['name']) ?> — <?= formatMoney($p['price']) ?> <?= $p['stock'] <= 0 ? '⛔ Out of Stock' : '(Stock: '.$p['stock'].')' ?>"
                                        onclick="selectProd(this)">
                                        <?= htmlspecialchars($p['name']) ?> — <?= formatMoney($p['price']) ?>
                                        <?= $p['stock'] <= 0 ? ' ⛔ Out of Stock' : ' <span style="color:#888">(Stock: '.$p['stock'].')</span>' ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="prod-no-results" id="prod-no-results" style="display:none;">No products found</div>
                                </div>
                            </div>
                            <!-- Stock indicator -->
                            <div id="stock-indicator" style="margin-top:8px;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;display:none;"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" id="qty-input" class="form-control" min="1" value="1" required oninput="updateStock()">
                            </div>
                            <div class="form-group">
                                <label>Sale Date</label>
                                <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <button type="submit" id="submit-btn" class="btn-submit">Record Sale</button>
                    </form>
                </div>
                <script>
                function updateStock() {
                    const hiddenInput = document.getElementById('product-select');
                    const ind = document.getElementById('stock-indicator');
                    const btn = document.getElementById('submit-btn');
                    const qty = parseInt(document.getElementById('qty-input').value) || 1;
                    const selected = document.querySelector('.prod-item.selected');

                    if (!hiddenInput.value || !selected) { ind.style.display='none'; btn.disabled=false; btn.style.opacity='1'; return; }

                    const stock = parseInt(selected.dataset.stock);
                    ind.style.display = 'block';

                    if (stock <= 0) {
                        ind.style.background = '#f8d7da'; ind.style.color = '#721c24'; ind.style.border = '1px solid #f5c6cb';
                        ind.innerHTML = '⛔ Out of Stock — This product cannot be sold.';
                        btn.disabled = true; btn.style.opacity = '0.5';
                    } else if (qty > stock) {
                        ind.style.background = '#fff3cd'; ind.style.color = '#856404'; ind.style.border = '1px solid #ffc107';
                        ind.innerHTML = '⚠️ Only <strong>' + stock + '</strong> unit(s) available. Reduce quantity.';
                        btn.disabled = true; btn.style.opacity = '0.5';
                    } else {
                        ind.style.background = '#d4edda'; ind.style.color = '#155724'; ind.style.border = '1px solid #c3e6cb';
                        ind.innerHTML = '✅ In Stock: <strong>' + stock + '</strong> unit(s) available.';
                        btn.disabled = false; btn.style.opacity = '1';
                    }
                }

                function checkStock() {
                    const selected = document.querySelector('.prod-item.selected');
                    if (!selected) { alert('⛔ Please select a product.'); return false; }
                    const qty = parseInt(document.getElementById('qty-input').value) || 1;
                    const stock = parseInt(selected.dataset.stock || 0);
                    if (stock <= 0) { alert('⛔ Out of Stock! This product has no available stock.'); return false; }
                    if (qty > stock) { alert('⚠️ Not enough stock! Only ' + stock + ' unit(s) available.'); return false; }
                    return true;
                }

                function openProdList() {
                    document.getElementById('prod-list').classList.add('open');
                }
                function closeProdList() {
                    document.getElementById('prod-list').classList.remove('open');
                }
                function filterProdList() {
                    const q = document.getElementById('prod-search').value.toLowerCase();
                    const items = document.querySelectorAll('.prod-item');
                    let any = false;
                    items.forEach(item => {
                        const match = item.dataset.label.toLowerCase().includes(q);
                        item.style.display = match ? '' : 'none';
                        if (match) any = true;
                    });
                    document.getElementById('prod-no-results').style.display = any ? 'none' : '';
                    openProdList();
                }
                function selectProd(el) {
                    document.querySelectorAll('.prod-item').forEach(i => i.classList.remove('selected'));
                    el.classList.add('selected');
                    document.getElementById('product-select').value = el.dataset.id;
                    document.getElementById('prod-search').value = el.dataset.label;
                    closeProdList();
                    updateStock();
                }
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!document.getElementById('prod-dropdown').contains(e.target)) closeProdList();
                });
                </script>

                <!-- Profit Summary -->
                <div class="table-card" style="border-top:3px solid var(--green-main);">
                    <div class="table-card-header"><h3>📋 Profit Overview — <?= date('F Y') ?></h3></div>
                    <div style="padding:20px;">
                        <div class="stat-row"><span class="label">Total Sales Revenue</span><span class="value" style="color:var(--green-bright)"><?= formatMoney($total_sales) ?></span></div>
                        <div class="stat-row"><span class="label">Total Expenses</span><span class="value" style="color:#dc3545"><?= formatMoney($total_expenses) ?></span></div>
                        <div class="stat-row" style="border-top:2px solid var(--gray-mid);padding-top:10px;">
                            <span class="label" style="font-weight:700;">Net Profit</span>
                            <span class="value" style="font-size:18px;color:<?= $net_profit>=0?'var(--green-bright)':'#dc3545' ?>"><?= formatMoney($net_profit) ?></span>
                        </div>
                        <div class="stat-row"><span class="label">Number of Sales</span><span class="value"><?= $cur['count']??0 ?></span></div>
                        <div class="stat-row"><span class="label">Last Month Sales</span><span class="value"><?= formatMoney($last_sales) ?></span></div>
                        <div class="stat-row"><span class="label">Month-over-Month</span>
                            <span class="value" style="color:<?= $growth>=0?'var(--green-bright)':'#dc3545' ?>">
                                <?= $last_sales <= 0 && $total_sales > 0 ? '🆕 NEW +100%' : (($growth>=0?'▲ +':'▼ ').number_format($growth,1).'%') ?>
                            </span>
                        </div>
                        <div style="margin-top:14px;padding:14px;background:<?= $net_profit>=0?'var(--green-light)':'#f8d7da' ?>;border-radius:8px;">
                            <div style="font-size:11px;font-weight:700;color:<?= $net_profit>=0?'var(--green-main)':'#721c24' ?>">
                                <?= $net_profit>=0?'✅ PROFITABLE MONTH':'⚠️ LOSS THIS MONTH' ?>
                            </div>
                            <div style="font-size:20px;font-weight:800;color:<?= $net_profit>=0?'var(--green-dark)':'#721c24' ?>"><?= formatMoney($net_profit) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales History with Search -->
            <div class="table-card" style="margin-top:22px;">
                <div class="table-card-header" style="flex-direction:column;align-items:stretch;gap:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <h3>Sales History</h3>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <div class="pills">
                                <button class="pill on"  onclick="setSaleFilter(this,'all')">All</button>
                                <button class="pill"     onclick="setSaleFilter(this,'today')">Today</button>
                                <button class="pill"     onclick="setSaleFilter(this,'week')">This Week</button>
                                <button class="pill"     onclick="setSaleFilter(this,'month')">This Month</button>
                            </div>
                            <div class="sw">
                                <span class="si">🔍</span>
                                <input type="text" id="sale-q" placeholder="Search product or date…" oninput="filterSales()">
                                <button class="sc" id="sale-clr" onclick="clearSales()">✕</button>
                            </div>
                        </div>
                    </div>
                    <!-- Month/Year Filter -->
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:11px;font-weight:700;color:var(--gray);">📅 Filter by Month:</span>
                        <select id="filter-year" onchange="filterByMonthYear()" style="padding:5px 10px;border:2px solid var(--gray-mid);border-radius:8px;font-family:'Poppins',sans-serif;font-size:12px;font-weight:600;outline:none;cursor:pointer;">
                            <option value="">All Years</option>
                            <?php
                            $uyears = array_unique(array_map(fn($r) => date('Y', strtotime($r['sale_date'])), $sales_arr));
                            rsort($uyears);
                            foreach($uyears as $uy): ?>
                            <option value="<?= $uy ?>" <?= $uy == date('Y') ? 'selected' : '' ?>><?= $uy ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filter-month" onchange="filterByMonthYear()" style="padding:5px 10px;border:2px solid var(--gray-mid);border-radius:8px;font-family:'Poppins',sans-serif;font-size:12px;font-weight:600;outline:none;cursor:pointer;">
                            <option value="">All Months</option>
                            <option value="01">January</option>
                            <option value="02">February</option>
                            <option value="03" <?= date('m') == '03' ? 'selected' : '' ?>>March</option>
                            <option value="04">April</option>
                            <option value="05">May</option>
                            <option value="06">June</option>
                            <option value="07">July</option>
                            <option value="08">August</option>
                            <option value="09">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                        <button onclick="clearMonthFilter()" style="padding:5px 12px;border-radius:8px;border:1.5px solid var(--gray-mid);background:#f8f9fa;color:var(--gray);font-size:11px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">✕ Clear</button>
                        <span id="month-total-badge" style="font-size:11px;font-weight:700;color:var(--green-main);"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div class="sinfo" id="sale-info"></div>
                        <span class="badge badge-green" id="sale-badge"><?= count($sales_arr) ?> transactions</span>
                    </div>
                </div>

                <div style="max-height:480px;overflow-y:auto;border-radius:0 0 12px 12px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">#</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Product</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Qty</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Unit Price</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Total</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Date</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">🕒 Recorded</th>
                            <th style="position:sticky;top:0;z-index:1;background:#f8f9fa;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="sale-tbody">
                        <?php if ($sales_arr): $i=1; foreach($sales_arr as $s):
                            $rowDate   = date('Y-m-d', strtotime($s['sale_date']));
                            $dispDate  = date('M d, Y', strtotime($s['sale_date']));
                            $recTime   = !empty($s['created_at']) ? date('M d, Y h:i A', strtotime($s['created_at'])) : '—';
                        ?>
                        <tr class="sale-row"
                            data-product="<?= strtolower(htmlspecialchars($s['product_name']??'')) ?>"
                            data-date="<?= $rowDate ?>"
                            data-disp="<?= strtolower($dispDate) ?>"
                            data-year="<?= date('Y', strtotime($s['sale_date'])) ?>"
                            data-month="<?= date('m', strtotime($s['sale_date'])) ?>">
                            <td><?= $i++ ?></td>
                            <td style="font-weight:600"><?= htmlspecialchars($s['product_name']??'N/A') ?></td>
                            <td><?= $s['quantity'] ?></td>
                            <td><?= formatMoney($s['unit_price']) ?></td>
                            <td style="font-weight:700;color:var(--green-main)"><?= formatMoney($s['total']) ?></td>
                            <td><?= $dispDate ?></td>
                            <td style="font-size:11px;color:var(--gray);white-space:nowrap;"><?= $recTime ?></td>
                            <td><a href="profit.php?action=delete&id=<?= $s['id'] ?>&year=<?= $view_year ?>" class="btn-danger" onclick="return confirm('Delete this sale?')">Delete</a></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--gray);padding:30px;">No sales recorded yet</td></tr>
                        <?php endif; ?>
                        <tr id="sale-nr" style="display:none;">
                            <td colspan="8" style="text-align:center;padding:28px;">
                                <div style="font-size:26px;margin-bottom:6px;">🔍</div>
                                <div style="font-weight:600;margin-bottom:3px;">No matching sales</div>
                                <div style="font-size:12px;color:var(--gray);">Try a different search or filter</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const TOTAL_SALES = <?= count($sales_arr) ?>;
let saleFil = 'all';

function todayStr() {
    const d = new Date();
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}
function weekStartStr() {
    const d = new Date(); d.setDate(d.getDate()-d.getDay());
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}
function monthStr() {
    const d = new Date();
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
}

let monthFil = '<?= date('m') ?>';
let yearFil  = '<?= date('Y') ?>';

function setSaleFilter(el, f) {
    saleFil = f;
    document.querySelectorAll('.pill').forEach(p => p.classList.remove('on'));
    el.classList.add('on');
    filterSales();
}

function filterByMonthYear() {
    yearFil  = document.getElementById('filter-year').value;
    monthFil = document.getElementById('filter-month').value;
    // Reset pill filter when using month/year dropdown
    saleFil = 'all';
    document.querySelectorAll('.pill').forEach(p => p.classList.remove('on'));
    document.querySelector('.pill').classList.add('on');
    filterSales();
}

function clearMonthFilter() {
    document.getElementById('filter-year').value  = '';
    document.getElementById('filter-month').value = '';
    yearFil  = '';
    monthFil = '';
    filterSales();
}

function filterSales() {
    const q     = document.getElementById('sale-q').value.trim().toLowerCase();
    const clr   = document.getElementById('sale-clr');
    const info  = document.getElementById('sale-info');
    const badge = document.getElementById('sale-badge');
    const nr    = document.getElementById('sale-nr');
    const mtot  = document.getElementById('month-total-badge');
    clr.style.display = q ? 'block' : 'none';
    let n = 0, total = 0;
    document.querySelectorAll('.sale-row').forEach(row => {
        let dm = true;
        if      (saleFil==='today') dm = row.dataset.date === todayStr();
        else if (saleFil==='week')  dm = row.dataset.date >= weekStartStr();
        else if (saleFil==='month') dm = row.dataset.date.startsWith(monthStr());
        // Month/year dropdown filter
        let mf = true;
        if (yearFil)  mf = mf && row.dataset.year  === yearFil;
        if (monthFil) mf = mf && row.dataset.month === monthFil;
        const tm = !q || row.dataset.product.includes(q) || row.dataset.disp.includes(q);
        const show = dm && tm && mf;
        row.style.display = show ? '' : 'none';
        if (show) {
            n++;
            // Sum totals for filtered rows
            const amt = parseFloat(row.querySelector('td:nth-child(5)')?.innerText.replace(/[^0-9.]/g,'') || 0);
            total += amt;
        }
    });
    nr.style.display = n===0 ? '' : 'none';
    badge.textContent = n + (n===1?' transaction':' transactions');
    // Show total of filtered results
    if (monthFil || yearFil) {
        const months = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const lbl = (monthFil ? months[parseInt(monthFil)]+' ' : '') + (yearFil || '');
        mtot.textContent = lbl + ' Total: ₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});
    } else {
        mtot.textContent = '';
    }
    const lbl = saleFil!=='all' ? saleFil.charAt(0).toUpperCase()+saleFil.slice(1) : '';
    info.innerHTML = (q||saleFil!=='all'||monthFil||yearFil)
        ? 'Showing <span>'+n+'</span> result'+(n!==1?'s':'') + (lbl?' &bull; '+lbl:'') + (q?' &bull; &ldquo;<strong>'+q+'</strong>&rdquo;':'')
        : '';
}

function clearSales() {
    const i = document.getElementById('sale-q');
    i.value = ''; filterSales(); i.focus();
}

// Auto-filter to current month on load
document.addEventListener('DOMContentLoaded', function() {
    filterSales();
});
</script>
</body>
</html>