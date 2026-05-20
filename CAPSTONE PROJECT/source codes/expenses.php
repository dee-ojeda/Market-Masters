<?php
if (defined('EXPENSES_PAGE_LOADED')) return;
define('EXPENSES_PAGE_LOADED', true);

require_once 'includes/config.php';
requireAdmin();
$db = getDB();

$success = $error = '';
$action = $_GET['action'] ?? 'list';
$edit_expense = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category     = trim($_POST['category'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $amount       = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');

    if (empty($category) || $amount <= 0) {
        $error = 'Category and amount are required.';
    } else {
        if (isset($_POST['id']) && $_POST['id']) {
            $id   = intval($_POST['id']);
            $stmt = $db->prepare("UPDATE expenses SET category=?, description=?, amount=?, expense_date=? WHERE id=?");
            $stmt->bind_param("ssdsi", $category, $description, $amount, $expense_date, $id);
        } else {
            $stmt = $db->prepare("INSERT INTO expenses (category, description, amount, expense_date) VALUES (?,?,?,?)");
            $stmt->bind_param("ssds", $category, $description, $amount, $expense_date);
        }
        if ($stmt->execute()) { $success = 'Expense saved!'; $action = 'list'; }
        else                  { $error   = 'Error saving expense.'; }
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db->query("DELETE FROM expenses WHERE id=$id");
    $success = 'Expense deleted.';
    $action  = 'list';
}

if ($action === 'edit' && isset($_GET['id'])) {
    $id           = intval($_GET['id']);
    $edit_expense = $db->query("SELECT * FROM expenses WHERE id=$id")->fetch_assoc();
}

$month       = date('Y-m');
$total_month = $db->query("SELECT SUM(amount) as t FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$month'")->fetch_assoc()['t'] ?? 0;
$total_year  = $db->query("SELECT SUM(amount) as t FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE())")->fetch_assoc()['t'] ?? 0;
$count_month = $db->query("SELECT COUNT(*) as c FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$month'")->fetch_assoc()['c'] ?? 0;

$categories = ['Product','Office Supplies','Advertising','Utilities','Salaries & Wages','Rent','Transportation','Marketing','Equipment','Other'];

// Available years
$years_res       = $db->query("SELECT DISTINCT YEAR(expense_date) as y FROM expenses ORDER BY y DESC");
$available_years = [];
while ($yr = $years_res->fetch_assoc()) $available_years[] = intval($yr['y']);
if (!in_array(intval(date('Y')), $available_years)) array_unshift($available_years, intval(date('Y')));

// Available months (from actual data)
$months_res       = $db->query("SELECT DISTINCT MONTH(expense_date) as m FROM expenses ORDER BY m ASC");
$available_months = [];
while ($mo = $months_res->fetch_assoc()) $available_months[] = intval($mo['m']);

$month_names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$month_short = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
                7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

// Overview filter
$cat_year_param  = $_GET['cat_year']  ?? date('Y');
$cat_month_param = $_GET['cat_month'] ?? '';
$show_all_years  = ($cat_year_param === 'all');
$selected_cat_year  = $show_all_years ? null : intval($cat_year_param);
$selected_cat_month = ($cat_month_param !== '') ? intval($cat_month_param) : null;

// Category totals
$cat_totals  = [];
$grand_total = 0;
foreach ($categories as $cat) {
    $conds  = ['category = ?'];
    $types  = 's';
    $params = [$cat];
    if (!$show_all_years && $selected_cat_year)  { $conds[] = 'YEAR(expense_date) = ?';  $types .= 'i'; $params[] = $selected_cat_year; }
    if ($selected_cat_month)                      { $conds[] = 'MONTH(expense_date) = ?'; $types .= 'i'; $params[] = $selected_cat_month; }
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE " . implode(' AND ', $conds));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $val = floatval($stmt->get_result()->fetch_assoc()['t']);
    $cat_totals[$cat] = $val;
    $grand_total     += $val;
}

// All expenses for table
$all_expenses = $db->query("SELECT * FROM expenses ORDER BY expense_date DESC LIMIT 500");
$total_rows   = $all_expenses ? $all_expenses->num_rows : 0;

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Tracking - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .del-modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.45);
            backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
            z-index:9999; align-items:center; justify-content:center;
        }
        .del-modal-overlay.active { display:flex; animation:fadeOverlay .2s ease; }
        .del-modal {
            background:#fff; border-radius:18px;
            box-shadow:0 20px 60px rgba(0,0,0,.18);
            padding:36px 32px 28px; width:100%; max-width:380px;
            text-align:center; animation:popIn .25s cubic-bezier(.34,1.56,.64,1);
        }
        .del-modal-icon {
            width:64px; height:64px; background:#fff0f0; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:30px; margin:0 auto 18px; border:3px solid #ffd6d6;
        }
        .del-modal h3 { font-size:19px; font-weight:800; color:#1a1a2e; margin:0 0 8px; }
        .del-modal p  { font-size:13px; color:#888; margin:0 0 26px; line-height:1.6; }
        .del-modal p strong { color:#444; font-weight:700; }
        .del-modal-actions { display:flex; gap:10px; }
        .btn-modal-cancel {
            flex:1; padding:12px 0; border-radius:10px;
            border:1.5px solid #e0e0e0; background:#f7f7f7;
            color:#555; font-size:13px; font-weight:700; cursor:pointer;
            transition:background .18s; font-family:inherit;
        }
        .btn-modal-cancel:hover { background:#ebebeb; }
        .btn-modal-delete {
            flex:1; padding:12px 0; border-radius:10px; border:none;
            background:linear-gradient(135deg,#ff4d4d,#dc3545);
            color:#fff; font-size:13px; font-weight:700; cursor:pointer;
            text-decoration:none; display:flex; align-items:center; justify-content:center;
            box-shadow:0 4px 14px rgba(220,53,69,.35); transition:transform .15s,box-shadow .15s;
        }
        .btn-modal-delete:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(220,53,69,.45); }

        /* Overview card */
        .cat-overview-card {
            background:#fff; border-radius:12px;
            box-shadow:0 2px 8px rgba(0,0,0,.07);
            padding:18px 20px; margin-bottom:22px;
        }
        .cat-overview-header { display:flex; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
        .cat-overview-header h3 { font-size:14px; font-weight:700; margin:0; }
        .cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:10px; }
        .cat-item { border:1px solid #eee; border-radius:9px; padding:10px 13px; font-size:12px; }
        .cat-item-name { font-weight:700; color:#333; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; }
        .cat-pct-pill { font-size:10px; font-weight:700; padding:2px 7px; border-radius:12px; }
        .cat-bar-wrap { background:#f0f0f0; border-radius:4px; height:6px; overflow:hidden; margin-bottom:4px; }
        .cat-bar      { height:100%; border-radius:4px; }
        .cat-meta     { color:#888; font-size:10px; display:flex; justify-content:space-between; }

        /* Filter pills */
        .filter-pills-wrap { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
        .filter-pill {
            display:inline-flex; align-items:center;
            padding:4px 11px; border-radius:20px; font-size:11px; font-weight:700;
            border:1.5px solid #e0e0e0; background:#f8f9fa; color:#555;
            text-decoration:none; transition:all .15s ease;
        }
        .filter-pill:hover { border-color:var(--green-main,#2d7a4f); color:var(--green-main,#2d7a4f); background:#f0faf4; }
        .filter-pill.active { background:var(--green-main,#2d7a4f); color:#fff !important; border-color:var(--green-main,#2d7a4f); }
        .pill-group-label { font-size:10px; font-weight:700; color:#aaa; white-space:nowrap; }
        .pill-divider { width:1px; height:16px; background:#ddd; margin:0 3px; flex-shrink:0; }

        /* Table category filter */
        .cat-filter-bar {
            display:flex; align-items:center; gap:8px; flex-wrap:wrap;
            padding:10px 14px; background:#fafafa; border-bottom:1px solid #f0f0f0;
        }
        .cat-filter-select {
            padding:6px 12px; border-radius:8px;
            border:1.5px solid #e0e0e0; background:#fff;
            font-size:12px; font-family:inherit; color:#333;
            cursor:pointer; outline:none; transition:border-color .15s; min-width:170px;
        }
        .cat-filter-select:focus { border-color:var(--green-main,#2d7a4f); }
        .cat-clear-btn {
            padding:5px 11px; border-radius:8px; border:1.5px solid #e0e0e0;
            background:#fff; font-size:11px; font-weight:700; color:#888;
            cursor:pointer; font-family:inherit; transition:all .15s;
        }
        .cat-clear-btn:hover { border-color:#dc3545; color:#dc3545; background:#fff5f5; }
        #exp-count { font-size:10px; font-weight:700; padding:3px 9px; border-radius:12px; background:#e8f5e9; color:#2e7d32; }

        /* Scrollable table */
        .table-scroll-wrap {
            max-height:420px; overflow-y:auto; overflow-x:hidden;
            border-radius:0 0 10px 10px;
            scrollbar-width:thin; scrollbar-color:#c5e1c9 #f0f0f0;
        }
        .table-scroll-wrap::-webkit-scrollbar { width:6px; }
        .table-scroll-wrap::-webkit-scrollbar-track { background:#f0f0f0; border-radius:4px; }
        .table-scroll-wrap::-webkit-scrollbar-thumb { background:#a8d5b0; border-radius:4px; }
        .table-scroll-wrap::-webkit-scrollbar-thumb:hover { background:var(--green-main,#2d7a4f); }
        .table-scroll-wrap .data-table thead th {
            position:sticky; top:0; z-index:2;
            background:#f8f9fa; box-shadow:0 2px 4px rgba(0,0,0,.06);
        }

        @keyframes fadeOverlay { from{opacity:0}to{opacity:1} }
        @keyframes popIn { from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)} }
    </style>
</head>
<body>

<div class="del-modal-overlay" id="delModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon">🗑️</div>
        <h3>Delete Expense?</h3>
        <p>You're about to permanently delete<br><strong id="delModalDesc">this expense</strong>.<br>This action <strong>cannot be undone</strong>.</p>
        <div class="del-modal-actions">
            <button class="btn-modal-cancel" onclick="closeDelModal()">Cancel</button>
            <a id="delModalConfirmBtn" href="#" class="btn-modal-delete">Yes, Delete</a>
        </div>
    </div>
</div>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title"><p>Record Expenses</p><h1>Expense Tracking</h1></div>
            <div class="topbar-user">
                <div class="topbar-avatar">👤</div>
                <span class="admin-badge">🔐 Admin</span>
            </div>
        </div>

        <div class="page-content">
            <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

            <div class="stats-row" style="grid-template-columns:repeat(3,1fr);">
                <div class="stat-card">
                    <div class="stat-icon orange">💸</div>
                    <div class="stat-info"><div class="stat-label">This Month</div><div class="stat-value"><?= formatMoney($total_month) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">📅</div>
                    <div class="stat-info"><div class="stat-label">This Year</div><div class="stat-value"><?= formatMoney($total_year) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">🔢</div>
                    <div class="stat-info"><div class="stat-label">Transactions (Month)</div><div class="stat-value"><?= $count_month ?></div></div>
                </div>
            </div>

            <!-- CATEGORY OVERVIEW -->
            <div class="cat-overview-card">
                <div class="cat-overview-header">
                    <div>
                        <h3>
                            📊 Category Spending Overview
                            <span style="font-weight:400;color:#888;font-size:12px;">
                                —
                                <?php
                                    if ($show_all_years) echo 'All Time';
                                    elseif ($selected_cat_month) echo $month_names[$selected_cat_month] . ' ' . $selected_cat_year;
                                    else echo $selected_cat_year;
                                ?>
                                &nbsp;Total: <?= formatMoney($grand_total) ?>
                            </span>
                        </h3>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:7px;margin-left:auto;">
                        <!-- Year row -->
                        <div class="filter-pills-wrap">
                            <span class="pill-group-label">📅 YEAR</span>
                            <a href="expenses.php?cat_year=all&cat_month=<?= $cat_month_param ?>"
                               class="filter-pill <?= $show_all_years ? 'active' : '' ?>">All</a>
                            <?php foreach ($available_years as $yr): ?>
                            <a href="expenses.php?cat_year=<?= $yr ?>&cat_month=<?= $cat_month_param ?>"
                               class="filter-pill <?= (!$show_all_years && $selected_cat_year == $yr) ? 'active' : '' ?>">
                                <?= $yr ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <!-- Month row -->
                        <div class="filter-pills-wrap">
                            <span class="pill-group-label">🗓 MONTH</span>
                            <a href="expenses.php?cat_year=<?= $cat_year_param ?>&cat_month="
                               class="filter-pill <?= !$selected_cat_month ? 'active' : '' ?>">All</a>
                            <?php foreach ($available_months as $mn): ?>
                            <a href="expenses.php?cat_year=<?= $cat_year_param ?>&cat_month=<?= $mn ?>"
                               class="filter-pill <?= $selected_cat_month == $mn ? 'active' : '' ?>">
                                <?= $month_short[$mn] ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php if ($grand_total == 0): ?>
                <div style="text-align:center;padding:30px;color:#aaa;font-size:13px;">😕 No expense data for this period.</div>
                <?php else: ?>
                <div class="cat-grid">
                    <?php foreach ($categories as $cat):
                        $total = $cat_totals[$cat] ?? 0;
                        if ($total == 0) continue;
                        $pct     = $grand_total > 0 ? round(($total / $grand_total) * 100, 1) : 0;
                        $max_cat = max(array_values($cat_totals)) ?: 1;
                        $bar_w   = $max_cat > 0 ? round(($total / $max_cat) * 100) : 0;
                        $bar_color = $pct >= 30 ? '#dc3545' : ($pct >= 15 ? '#f59e0b' : '#10b981');
                        $pill_bg   = $pct >= 30 ? '#fde8e8' : ($pct >= 15 ? '#fff3cd' : '#e8f5e9');
                        $pill_clr  = $pct >= 30 ? '#dc3545' : ($pct >= 15 ? '#856404' : '#2e7d32');
                    ?>
                    <div class="cat-item">
                        <div class="cat-item-name">
                            <span><?= htmlspecialchars($cat) ?></span>
                            <span class="cat-pct-pill" style="background:<?= $pill_bg ?>;color:<?= $pill_clr ?>"><?= $pct ?>%</span>
                        </div>
                        <div class="cat-bar-wrap">
                            <div class="cat-bar" style="width:<?= $bar_w ?>%;background:<?= $bar_color ?>;"></div>
                        </div>
                        <div class="cat-meta">
                            <span style="font-weight:600;color:#333;"><?= formatMoney($total) ?></span>
                            <span>of <?= formatMoney($grand_total) ?> total</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="two-col">
                <!-- Form -->
                <div class="form-card">
                    <h3><?= $edit_expense ? '✏️ Edit Expense' : '➕ Add Expense' ?></h3>
                    <form method="POST">
                        <?php if ($edit_expense): ?>
                        <input type="hidden" name="id" value="<?= $edit_expense['id'] ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" class="form-control" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?= $cat ?>" <?= ($edit_expense['category'] ?? '') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Brief description" value="<?= htmlspecialchars($edit_expense['description'] ?? '') ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Amount (₱)</label>
                                <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" value="<?= $edit_expense['amount'] ?? '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Date</label>
                                <input type="date" name="expense_date" class="form-control" value="<?= $edit_expense['expense_date'] ?? date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="btn-submit"><?= $edit_expense ? 'Update Expense' : 'Add Expense' ?></button>
                            <?php if ($edit_expense): ?>
                            <a href="expenses.php" class="btn-submit" style="background:var(--gray);text-decoration:none;">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Expense List -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3>All Expenses</h3>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <a href="export_excel.php?type=expense&year=<?= date('Y') ?>"
                               style="display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:#217346;color:white;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;"
                               onmouseover="this.style.background='#185c38'" onmouseout="this.style.background='#217346'">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                Export Excel
                            </a>
                            <a href="reports.php?type=expense" class="btn-view btn-view-outline btn-view-orange-outline" style="width:auto;padding:7px 14px;font-size:11px;">Expense Report →</a>
                        </div>
                    </div>

                    <!-- Category filter bar -->
                    <div class="cat-filter-bar">
                        <span style="font-size:11px;font-weight:700;color:#666;">🔍 Category:</span>
                        <select class="cat-filter-select" id="catFilter" onchange="filterExpenses()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="cat-clear-btn" onclick="clearFilter()">✕ Clear</button>
                        <span id="exp-count"><?= $total_rows ?> entries</span>
                        <span id="exp-filter-label" style="font-size:11px;color:#888;"></span>
                    </div>

                    <div class="table-scroll-wrap">
                        <table class="data-table">
                            <thead>
                                <tr><th>Category</th><th>Description</th><th>Amount</th><th>Share</th><th>Date</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($all_expenses && $all_expenses->num_rows > 0):
                                    while($e = $all_expenses->fetch_assoc()):
                                    $cat_total = $cat_totals[$e['category']] ?? 0;
                                    $cat_pct   = $grand_total > 0 ? round(($cat_total / $grand_total) * 100, 1) : 0;
                                    $pill_bg   = $cat_pct >= 30 ? '#fde8e8' : ($cat_pct >= 15 ? '#fff3cd' : '#e8f5e9');
                                    $pill_clr  = $cat_pct >= 30 ? '#dc3545' : ($cat_pct >= 15 ? '#856404' : '#2e7d32');
                                    $desc_safe = addslashes(htmlspecialchars($e['description'] ?: $e['category']));
                                ?>
                                <tr class="exp-row" data-cat="<?= htmlspecialchars($e['category']) ?>">
                                    <td><span class="badge badge-orange"><?= htmlspecialchars($e['category']) ?></span></td>
                                    <td style="font-size:12px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($e['description']) ?></td>
                                    <td style="font-weight:700;color:#dc3545"><?= formatMoney($e['amount']) ?></td>
                                    <td>
                                        <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:12px;display:inline-block;background:<?= $pill_bg ?>;color:<?= $pill_clr ?>;"
                                              title="<?= htmlspecialchars($e['category']) ?> total: <?= formatMoney($cat_total) ?>">
                                            <?= $cat_pct ?>%
                                        </span>
                                    </td>
                                    <td style="font-size:11px;"><?= date('M d, Y', strtotime($e['expense_date'])) ?></td>
                                    <td style="white-space:nowrap;">
                                        <a href="expenses.php?action=edit&id=<?= $e['id'] ?>" class="btn-edit" style="padding:5px 10px;font-size:11px;">Edit</a>
                                        <button class="btn-danger" style="padding:5px 10px;font-size:11px;border:none;cursor:pointer;"
                                                onclick="openDelModal(<?= $e['id'] ?>, '<?= $desc_safe ?>')">Del</button>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--gray)">No expenses yet</td></tr>
                                <?php endif; ?>
                                <tr id="exp-no-results" style="display:none;">
                                    <td colspan="6" style="text-align:center;padding:28px;color:var(--gray);">
                                        <div style="font-size:22px;margin-bottom:6px;">🔍</div>
                                        <div style="font-weight:600;">No expenses found</div>
                                        <div style="font-size:11px;margin-top:3px;">Try a different category</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function filterExpenses() {
    const val   = document.getElementById('catFilter').value;
    const rows  = document.querySelectorAll('.exp-row');
    const count = document.getElementById('exp-count');
    const label = document.getElementById('exp-filter-label');
    const noRes = document.getElementById('exp-no-results');
    let n = 0;
    rows.forEach(row => {
        const match = !val || row.dataset.cat === val;
        row.style.display = match ? '' : 'none';
        if (match) n++;
    });
    count.textContent = n + ' entr' + (n !== 1 ? 'ies' : 'y');
    label.textContent = val ? '— ' + val : '';
    noRes.style.display = n === 0 ? '' : 'none';
}
function clearFilter() {
    document.getElementById('catFilter').value = '';
    filterExpenses();
}

function openDelModal(id, desc) {
    document.getElementById('delModalDesc').textContent = desc || 'this expense';
    document.getElementById('delModalConfirmBtn').href  = 'expenses.php?action=delete&id=' + id;
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
    if (e.key === 'Escape') closeDelModal();
});
</script>
</body>
</html>