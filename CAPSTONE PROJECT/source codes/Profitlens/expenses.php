<?php
if (defined('EXPENSES_PAGE_LOADED')) return;
define('EXPENSES_PAGE_LOADED', true);

date_default_timezone_set('Asia/Manila'); // keep all timestamps consistent with local time, not the DB server's clock

require_once 'includes/config.php';
requireAdmin();
$db = getDB();

// Make sure supporting tables exist (safe to run every load)
$db->query("CREATE TABLE IF NOT EXISTS category_budgets (
    category VARCHAR(50) PRIMARY KEY,
    monthly_limit DECIMAL(12,2) NOT NULL DEFAULT 0
)");
$db->query("CREATE TABLE IF NOT EXISTS deleted_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_id INT,
    category VARCHAR(50),
    description TEXT,
    amount DECIMAL(12,2),
    expense_date DATE,
    deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
// Add a "recorded at" timestamp column to expenses if it isn't there yet
$col_check = $db->query("SHOW COLUMNS FROM expenses LIKE 'created_at'");
if ($col_check && $col_check->num_rows === 0) {
    $db->query("ALTER TABLE expenses ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}
// Backfill older rows that have no recorded timestamp (existed before this column was added)
$db->query("UPDATE expenses SET created_at = expense_date WHERE created_at IS NULL");

$success = $error = '';
$action = $_GET['action'] ?? 'list';
$edit_expense = null;

$categories = ['Product','Office Supplies','Advertising','Utilities','Salaries & Wages','Rent','Transportation','Marketing','Equipment','Other'];

// ---- Handle budget settings save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_budgets'])) {
    $stmt = $db->prepare("INSERT INTO category_budgets (category, monthly_limit) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE monthly_limit = VALUES(monthly_limit)");
    foreach ($categories as $cat) {
        $limit = floatval($_POST['budget'][$cat] ?? 0);
        $stmt->bind_param('sd', $cat, $limit);
        $stmt->execute();
    }
    $success = 'Category budgets updated!';
}
// ---- Handle expense add/edit ----
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $now_ts = date('Y-m-d H:i:s');
            $stmt = $db->prepare("INSERT INTO expenses (category, description, amount, expense_date, created_at) VALUES (?,?,?,?,?)");
            $stmt->bind_param("ssdss", $category, $description, $amount, $expense_date, $now_ts);
        }
        if ($stmt->execute()) { $success = 'Expense saved!'; $action = 'list'; }
        else                  { $error   = 'Error saving expense.'; }
    }
}

// ---- Delete an expense: archive it into deleted_expenses first ----
if ($action === 'delete' && isset($_GET['id'])) {
    $id  = intval($_GET['id']);
    $row = $db->query("SELECT * FROM expenses WHERE id=$id")->fetch_assoc();
    if ($row) {
        $stmt = $db->prepare("INSERT INTO deleted_expenses (original_id, category, description, amount, expense_date) VALUES (?,?,?,?,?)");
        $stmt->bind_param("issds", $row['id'], $row['category'], $row['description'], $row['amount'], $row['expense_date']);
        $stmt->execute();
        $db->query("DELETE FROM expenses WHERE id=$id");
        $success = 'Expense deleted.';
    }
    $action = 'list';
}

// ---- Restore a deleted expense back into the active list ----
if ($action === 'restore_deleted' && isset($_GET['id'])) {
    $id  = intval($_GET['id']);
    $row = $db->query("SELECT * FROM deleted_expenses WHERE id=$id")->fetch_assoc();
    if ($row) {
        $stmt = $db->prepare("INSERT INTO expenses (category, description, amount, expense_date) VALUES (?,?,?,?)");
        $stmt->bind_param("ssds", $row['category'], $row['description'], $row['amount'], $row['expense_date']);
        $stmt->execute();
        $db->query("DELETE FROM deleted_expenses WHERE id=$id");
        $success = 'Expense restored.';
    }
    $action = 'list';
}

// ---- Permanently remove a record from the deleted history ----
if ($action === 'purge_deleted' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db->query("DELETE FROM deleted_expenses WHERE id=$id");
    $success = 'Deleted record permanently removed.';
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

// Category totals (respecting overview filter)
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

// ---- Budget limits per category ----
$category_budgets = [];
foreach ($categories as $cat) $category_budgets[$cat] = 0;
$budget_res = $db->query("SELECT category, monthly_limit FROM category_budgets");
while ($b = $budget_res->fetch_assoc()) {
    if (isset($category_budgets[$b['category']])) $category_budgets[$b['category']] = floatval($b['monthly_limit']);
}

// ---- Category totals broken down by category + year-month, for client-side budget checking ----
$category_month_totals = [];
$cm_res = $db->query("SELECT category, DATE_FORMAT(expense_date,'%Y-%m') as ym, SUM(amount) as t
                       FROM expenses GROUP BY category, ym");
while ($row = $cm_res->fetch_assoc()) {
    $category_month_totals[$row['category'] . '|' . $row['ym']] = floatval($row['t']);
}

// ---- Deleted expenses history (grouped by year) ----
$deleted_expenses    = [];
$deleted_years       = [];
$deleted_year_totals = [];
$deleted_total_all   = 0;
$del_res = $db->query("SELECT * FROM deleted_expenses ORDER BY expense_date DESC, deleted_at DESC");
while ($d = $del_res->fetch_assoc()) {
    $deleted_expenses[] = $d;
    $yr = intval(date('Y', strtotime($d['expense_date'])));
    if (!isset($deleted_year_totals[$yr])) { $deleted_year_totals[$yr] = ['count' => 0, 'total' => 0]; $deleted_years[] = $yr; }
    $deleted_year_totals[$yr]['count']++;
    $deleted_year_totals[$yr]['total'] += floatval($d['amount']);
    $deleted_total_all += floatval($d['amount']);
}
rsort($deleted_years);

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

        /* Year/Month dropdown filter */
        .period-filter-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .period-filter-select {
            padding:6px 12px; border-radius:8px;
            border:1.5px solid #e0e0e0; background:#fff;
            font-size:12px; font-weight:700; font-family:inherit; color:#333;
            cursor:pointer; outline:none; transition:border-color .15s; min-width:120px;
        }
        .period-filter-select:focus { border-color:var(--green-main,#2d7a4f); }
        .period-filter-select:hover { border-color:var(--green-main,#2d7a4f); }
        .pill-group-label { font-size:10px; font-weight:700; color:#aaa; white-space:nowrap; }

        .btn-manage-budgets {
            display:inline-flex; align-items:center; gap:5px;
            padding:6px 12px; border-radius:8px; border:1.5px solid #e0e0e0;
            background:#fff; font-size:11px; font-weight:700; color:#555;
            cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap;
        }
        .btn-manage-budgets:hover { border-color:var(--green-main,#2d7a4f); color:var(--green-main,#2d7a4f); background:#f0faf4; }
        .btn-header-row { display:flex; gap:8px; }

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

        /* Sort buttons */
        .exp-sort-bar { display:flex; align-items:center; gap:7px; flex-wrap:wrap; padding:10px 14px; background:#fafafa; border-bottom:1px solid #f0f0f0; }
        .sort-btn {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 12px; border-radius:20px;
            border:1.5px solid #e0e0e0; background:#f8f9fa; color:#555;
            font-size:11px; font-weight:600; cursor:pointer;
            transition:all .15s ease; font-family:inherit; white-space:nowrap;
        }
        .sort-btn:hover { border-color:var(--green-main,#2d7a4f); color:var(--green-main,#2d7a4f); background:#f0faf4; }
        .sort-btn.active { background:var(--green-main,#2d7a4f); color:#fff; border-color:var(--green-main,#2d7a4f); }
        .sort-btn.active:hover { background:#1e5c3a; color:#fff; }

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

        /* Budget settings modal */
        .budget-modal { max-width:460px; text-align:left; padding:28px 26px 24px; }
        .budget-modal h3 { font-size:17px; font-weight:800; color:#1a1a2e; margin:0 0 4px; text-align:center; }
        .budget-modal .modal-sub { font-size:12px; color:#888; text-align:center; margin:0 0 20px; }
        .budget-rows { max-height:340px; overflow-y:auto; padding-right:4px; margin-bottom:18px; }
        .budget-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid #f2f2f2; }
        .budget-row label { font-size:12.5px; font-weight:700; color:#333; }
        .budget-row input {
            width:130px; padding:7px 10px; border-radius:8px;
            border:1.5px solid #e0e0e0; font-size:12.5px; font-family:inherit;
            text-align:right; outline:none;
        }
        .budget-row input:focus { border-color:var(--green-main,#2d7a4f); }

        /* Exceed-limit warning modal */
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
            flex:1; padding:12px 0; border-radius:10px; border:none;
            background:linear-gradient(135deg,#ffb74d,#f59e0b);
            color:#fff; font-size:13px; font-weight:700; cursor:pointer;
            box-shadow:0 4px 14px rgba(245,158,11,.35); transition:transform .15s,box-shadow .15s;
        }
        .btn-modal-proceed:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(245,158,11,.45); }

        /* Deleted History modal */
        .deleted-history-modal { max-width:740px; text-align:left; padding:28px 26px 24px; }
        .deleted-history-modal h3 { text-align:center; }
        .dh-icon { background:#f3f0ff; border-color:#dcd3ff; }
        .dh-year-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
        .dh-chip {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 13px; border-radius:20px; font-size:11.5px; font-weight:700;
            border:1.5px solid #e0e0e0; background:#f8f9fa; color:#555;
            cursor:pointer; font-family:inherit; transition:all .15s ease;
        }
        .dh-chip:hover { border-color:var(--green-main,#2d7a4f); color:var(--green-main,#2d7a4f); }
        .dh-chip.active { background:var(--green-main,#2d7a4f); color:#fff; border-color:var(--green-main,#2d7a4f); }
        .dh-chip-count { background:rgba(0,0,0,.12); padding:0 6px; border-radius:10px; font-size:10px; }
        .dh-chip.active .dh-chip-count { background:rgba(255,255,255,.25); }
        .dh-total-bar { font-size:12.5px; color:#666; background:#fafafa; border-radius:8px; padding:9px 14px; margin-bottom:12px; display:flex; justify-content:space-between; }
        .dh-total-bar strong { color:#dc3545; }
        .dh-table-wrap { max-height:320px; overflow-y:auto; border:1px solid #f0f0f0; border-radius:10px; }
        .dh-table-wrap table { width:100%; }
        .dh-table-wrap table thead th { position:sticky; top:0; background:#f8f9fa; z-index:1; }
        .btn-restore {
            display:inline-block; padding:5px 9px; font-size:10.5px; font-weight:700;
            border-radius:6px; border:1.5px solid #2d7a4f; color:#2d7a4f;
            background:#f0faf4; text-decoration:none; cursor:pointer;
        }
        .btn-restore:hover { background:#2d7a4f; color:#fff; }
        .btn-purge {
            display:inline-block; padding:5px 9px; font-size:10.5px; font-weight:700;
            border-radius:6px; border:1.5px solid #dc3545; color:#dc3545;
            background:#fff5f5; text-decoration:none; cursor:pointer;
        }
        .btn-purge:hover { background:#dc3545; color:#fff; }

        @keyframes fadeOverlay { from{opacity:0}to{opacity:1} }
        @keyframes popIn { from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)} }
    </style>
</head>
<body>

<div class="del-modal-overlay" id="delModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon">🗑️</div>
        <h3>Delete Expense?</h3>
        <p>You're about to delete<br><strong id="delModalDesc">this expense</strong>.<br>It will be moved to <strong>Deleted History</strong> and can be restored later.</p>
        <div class="del-modal-actions">
            <button class="btn-modal-cancel" onclick="closeDelModal()">Cancel</button>
            <a id="delModalConfirmBtn" href="#" class="btn-modal-delete">Yes, Delete</a>
        </div>
    </div>
</div>

<!-- Manage Budgets Modal -->
<div class="del-modal-overlay" id="budgetModalOverlay">
    <div class="del-modal budget-modal">
        <h3>💰 Category Monthly Budgets</h3>
        <p class="modal-sub">Set a monthly spending limit per category. Leave at 0 for no limit.</p>
        <form method="POST" id="budgetForm">
            <div class="budget-rows">
                <?php foreach ($categories as $cat): ?>
                <div class="budget-row">
                    <label><?= htmlspecialchars($cat) ?></label>
                    <input type="number" step="0.01" min="0" name="budget[<?= htmlspecialchars($cat) ?>]"
                           value="<?= $category_budgets[$cat] ?: '' ?>" placeholder="0.00">
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="save_budgets" value="1">
            <div class="del-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeBudgetModal()">Cancel</button>
                <button type="submit" class="btn-modal-delete" style="background:linear-gradient(135deg,#2d9d63,var(--green-main,#2d7a4f));box-shadow:0 4px 14px rgba(45,122,79,.35);">Save Budgets</button>
            </div>
        </form>
    </div>
</div>

<!-- Exceed Limit Warning Modal -->
<div class="del-modal-overlay" id="exceedModalOverlay">
    <div class="del-modal">
        <div class="del-modal-icon exceed-modal-icon">⚠️</div>
        <h3>Budget Limit Exceeded</h3>
        <p>Saving this expense will push <strong id="exceedCatName">this category</strong>'s spending for the month past its budget limit.</p>
        <div class="exceed-detail-box">
            <div><span class="exceed-label">Budget limit</span><span class="exceed-val" id="exceedLimit">—</span></div>
            <div><span class="exceed-label">Already spent this month</span><span class="exceed-val" id="exceedCurrent">—</span></div>
            <div><span class="exceed-label">This expense</span><span class="exceed-val" id="exceedThis">—</span></div>
            <div><span class="exceed-label">New total</span><span class="exceed-val exceed-over" id="exceedNewTotal">—</span></div>
            <div><span class="exceed-label">Over by</span><span class="exceed-val exceed-over" id="exceedOverBy">—</span></div>
        </div>
        <div class="del-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeExceedModal()">Cancel</button>
            <button type="button" class="btn-modal-proceed" onclick="proceedAnyway()">Add Anyway</button>
        </div>
    </div>
</div>

<!-- Deleted Expenses History Modal -->
<div class="del-modal-overlay" id="deletedHistoryModalOverlay">
    <div class="del-modal deleted-history-modal">
        <h3>🗑️ Deleted Expenses History</h3>
        <p class="modal-sub">Expenses that have been deleted, organized by year. Restore or permanently purge a record.</p>

        <div class="dh-year-chips">
            <button type="button" class="dh-chip active" data-year="all" onclick="filterDeletedHistory('all')">
                All Years <span class="dh-chip-count"><?= count($deleted_expenses) ?></span>
            </button>
            <?php foreach ($deleted_years as $yr): ?>
            <button type="button" class="dh-chip" data-year="<?= $yr ?>" onclick="filterDeletedHistory('<?= $yr ?>')">
                <?= $yr ?> <span class="dh-chip-count"><?= $deleted_year_totals[$yr]['count'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="dh-total-bar">
            <span>Total deleted (<span id="dhFilterLabel">All Years</span>)</span>
            <strong id="dhTotalAmount"><?= formatMoney($deleted_total_all) ?></strong>
        </div>

        <div class="dh-table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Category</th><th>Description</th><th>Amount</th><th>Original Date</th><th>Deleted On</th><th>Actions</th></tr>
                </thead>
                <tbody id="dhTableBody">
                    <?php if (empty($deleted_expenses)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--gray);padding:20px;">No deleted expenses yet.</td></tr>
                    <?php else: foreach ($deleted_expenses as $d):
                        $dyr = date('Y', strtotime($d['expense_date']));
                        $del_desc_safe = addslashes(htmlspecialchars($d['description'] ?: $d['category']));
                    ?>
                    <tr class="dh-row" data-year="<?= $dyr ?>" data-amount="<?= $d['amount'] ?>">
                        <td><span class="badge badge-orange"><?= htmlspecialchars($d['category']) ?></span></td>
                        <td style="font-size:12px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($d['description']) ?></td>
                        <td style="font-weight:700;color:#dc3545"><?= formatMoney($d['amount']) ?></td>
                        <td style="font-size:11px;"><?= date('M d, Y', strtotime($d['expense_date'])) ?></td>
                        <td style="font-size:11px;color:#888;"><?= date('M d, Y g:ia', strtotime($d['deleted_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <a href="expenses.php?action=restore_deleted&id=<?= $d['id'] ?>" class="btn-restore"
                               onclick="return confirm('Restore this expense back to the active list?')">Restore</a>
                            <a href="#" class="btn-purge"
                               onclick="openPurgeModal(<?= $d['id'] ?>, '<?= $del_desc_safe ?>', '<?= date('M d, Y', strtotime($d['expense_date'])) ?>'); return false;">Purge</a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="del-modal-actions" style="margin-top:16px;">
            <button type="button" class="btn-modal-cancel" onclick="closeDeletedHistoryModal()" style="flex:none;padding:12px 28px;margin:0 auto;">Close</button>
        </div>
    </div>
</div>

<!-- Permanent Purge Confirmation Modal (kept after Deleted History modal in the DOM + higher z-index so it always renders on top) -->
<div class="del-modal-overlay" id="purgeModalOverlay" style="z-index:10000;">
    <div class="del-modal">
        <div class="del-modal-icon" style="background:#fff0f0;border-color:#ffb3b3;">🔥</div>
        <h3>Delete Permanently?</h3>
        <p style="margin-bottom:6px;">You're about to permanently delete</p>
        <p style="margin-bottom:2px;"><strong id="purgeModalDesc">this record</strong></p>
        <p style="margin-bottom:14px;color:#aaa;font-size:12px;">Date: <span id="purgeModalDate">—</span></p>
        <p>This action <strong style="color:#dc3545;">cannot be undone</strong> — it will be gone for good.</p>
        <div class="del-modal-actions">
            <button class="btn-modal-cancel" onclick="closePurgeModal()">Cancel</button>
            <a id="purgeModalConfirmBtn" href="#" class="btn-modal-delete">Yes, Delete Forever</a>
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

                    <div style="display:flex;flex-direction:column;gap:7px;margin-left:auto;align-items:flex-end;">
                        <div class="btn-header-row">
                            <button type="button" class="btn-manage-budgets" onclick="openBudgetModal()">⚙️ Manage Budgets</button>
                            <button type="button" class="btn-manage-budgets" onclick="openDeletedHistoryModal()">🗑️ Deleted History</button>
                        </div>
                        <!-- Year dropdown -->
                        <div class="period-filter-row">
                            <span class="pill-group-label">📅 YEAR</span>
                            <select class="period-filter-select"
                                    onchange="window.location.href='expenses.php?cat_year='+this.value+'&cat_month=<?= $cat_month_param ?>'">
                                <option value="all" <?= $show_all_years ? 'selected' : '' ?>>All</option>
                                <?php foreach ($available_years as $yr): ?>
                                <option value="<?= $yr ?>" <?= (!$show_all_years && $selected_cat_year == $yr) ? 'selected' : '' ?>>
                                    <?= $yr ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Month dropdown -->
                        <div class="period-filter-row">
                            <span class="pill-group-label">🗓 MONTH</span>
                            <select class="period-filter-select"
                                    onchange="window.location.href='expenses.php?cat_year=<?= $cat_year_param ?>&cat_month='+this.value">
                                <option value="" <?= !$selected_cat_month ? 'selected' : '' ?>>All</option>
                                <?php foreach ($available_months as $mn): ?>
                                <option value="<?= $mn ?>" <?= $selected_cat_month == $mn ? 'selected' : '' ?>>
                                    <?= $month_names[$mn] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
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
                        <?php if ($category_budgets[$cat] > 0): ?>
                        <div style="font-size:9.5px;color:#aaa;margin-top:4px;">Monthly budget: <?= formatMoney($category_budgets[$cat]) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="two-col">
                <!-- Form -->
                <div class="form-card">
                    <h3><?= $edit_expense ? '✏️ Edit Expense' : '➕ Add Expense' ?></h3>
                    <form method="POST" id="expenseForm"
                          data-edit-id="<?= $edit_expense['id'] ?? '' ?>"
                          data-edit-category="<?= htmlspecialchars($edit_expense['category'] ?? '') ?>"
                          data-edit-amount="<?= $edit_expense['amount'] ?? 0 ?>"
                          data-edit-date="<?= $edit_expense['expense_date'] ?? '' ?>">
                        <?php if ($edit_expense): ?>
                        <input type="hidden" name="id" value="<?= $edit_expense['id'] ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" id="expCategory" class="form-control" required>
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
                                <input type="number" name="amount" id="expAmount" class="form-control" step="0.01" min="0" placeholder="0.00" value="<?= $edit_expense['amount'] ?? '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Date</label>
                                <input type="date" name="expense_date" id="expDate" class="form-control" value="<?= $edit_expense['expense_date'] ?? date('Y-m-d') ?>" required>
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

                    <!-- Sort bar -->
                    <div class="exp-sort-bar">
                        <span style="font-size:11px;font-weight:700;color:#666;">Sort by:</span>
                        <button class="sort-btn active" id="sort-datetime" onclick="sortExpenses('datetime')">🕒 Date &amp; Time</button>
                        <button class="sort-btn" id="sort-amount-desc" onclick="sortExpenses('amount-desc')">💰 Highest Amount</button>
                        <button class="sort-btn" id="sort-amount-asc" onclick="sortExpenses('amount-asc')">💸 Lowest Amount</button>
                    </div>

                    <div class="table-scroll-wrap">
                        <table class="data-table" id="expensesTable">
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
                                <tr class="exp-row" data-cat="<?= htmlspecialchars($e['category']) ?>"
                                    data-created="<?= htmlspecialchars($e['created_at'] ?? $e['expense_date']) ?>"
                                    data-amount="<?= $e['amount'] ?>">
                                    <td><span class="badge badge-orange"><?= htmlspecialchars($e['category']) ?></span></td>
                                    <td style="font-size:12px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($e['description']) ?></td>
                                    <td style="font-weight:700;color:#dc3545"><?= formatMoney($e['amount']) ?></td>
                                    <td>
                                        <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:12px;display:inline-block;background:<?= $pill_bg ?>;color:<?= $pill_clr ?>;"
                                              title="<?= htmlspecialchars($e['category']) ?> total: <?= formatMoney($cat_total) ?>">
                                            <?= $cat_pct ?>%
                                        </span>
                                    </td>
                                    <td style="font-size:11px;">
                                        <?= date('M d, Y', strtotime($e['expense_date'])) ?>
                                        <?php if (!empty($e['created_at'])): ?>
                                        <div style="font-size:10px;color:#b0b0b0;margin-top:2px;">🕒 Recorded: <?= date('M d, Y h:i A', strtotime($e['created_at'])) ?></div>
                                        <?php endif; ?>
                                    </td>
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
// Data needed for budget-limit checking, embedded from PHP
const categoryBudgets      = <?= json_encode($category_budgets) ?>;
const categoryMonthTotals  = <?= json_encode($category_month_totals) ?>;

function formatPeso(n) {
    return '₱' + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

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

function sortExpenses(mode) {
    document.querySelectorAll('#sort-datetime, #sort-amount-desc, #sort-amount-asc').forEach(b => b.classList.remove('active'));
    const btnMap = { 'datetime': 'sort-datetime', 'amount-desc': 'sort-amount-desc', 'amount-asc': 'sort-amount-asc' };
    const activeBtn = document.getElementById(btnMap[mode]);
    if (activeBtn) activeBtn.classList.add('active');

    const tbody = document.querySelector('#expensesTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr.exp-row'));

    rows.sort((a, b) => {
        if (mode === 'amount-desc') {
            return parseFloat(b.dataset.amount) - parseFloat(a.dataset.amount);
        } else if (mode === 'amount-asc') {
            return parseFloat(a.dataset.amount) - parseFloat(b.dataset.amount);
        }
        // datetime — newest first
        return (b.dataset.created || '').localeCompare(a.dataset.created || '');
    });

    rows.forEach(r => tbody.appendChild(r));

    // Keep the "no results" row pinned to the very end
    const noRes = document.getElementById('exp-no-results');
    if (noRes) tbody.appendChild(noRes);

    filterExpenses();
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

function openBudgetModal() {
    document.getElementById('budgetModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeBudgetModal() {
    document.getElementById('budgetModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('budgetModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeBudgetModal();
});

function openDeletedHistoryModal() {
    document.getElementById('deletedHistoryModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeDeletedHistoryModal() {
    document.getElementById('deletedHistoryModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('deletedHistoryModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeDeletedHistoryModal();
});

// ---- Permanent purge confirmation modal ----
function openPurgeModal(id, desc, dateStr) {
    document.getElementById('purgeModalDesc').textContent = desc || 'this record';
    document.getElementById('purgeModalDate').textContent = dateStr || '—';
    document.getElementById('purgeModalConfirmBtn').href  = 'expenses.php?action=purge_deleted&id=' + id;
    document.getElementById('purgeModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closePurgeModal() {
    document.getElementById('purgeModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('purgeModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePurgeModal();
});

function filterDeletedHistory(year) {
    document.querySelectorAll('.dh-chip').forEach(c => c.classList.toggle('active', c.dataset.year === year));
    const rows = document.querySelectorAll('.dh-row');
    let total = 0;
    rows.forEach(row => {
        const match = year === 'all' || row.dataset.year === year;
        row.style.display = match ? '' : 'none';
        if (match) total += parseFloat(row.dataset.amount) || 0;
    });
    document.getElementById('dhTotalAmount').textContent = formatPeso(total);
    document.getElementById('dhFilterLabel').textContent = year === 'all' ? 'All Years' : year;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeDelModal(); closeBudgetModal(); closeExceedModal(); closeDeletedHistoryModal(); closePurgeModal(); }
});

// ---- Budget exceed check before saving an expense ----
const expenseForm = document.getElementById('expenseForm');
let forceSubmit = false;

function closeExceedModal() {
    document.getElementById('exceedModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
function proceedAnyway() {
    forceSubmit = true;
    closeExceedModal();
    expenseForm.submit();
}

expenseForm.addEventListener('submit', function(e) {
    if (forceSubmit) return; // user already confirmed, let it go through

    const category = document.getElementById('expCategory').value;
    const amount   = parseFloat(document.getElementById('expAmount').value) || 0;
    const dateVal  = document.getElementById('expDate').value; // YYYY-MM-DD
    if (!category || !amount || !dateVal) return;

    const budget = parseFloat(categoryBudgets[category] || 0);
    if (!budget) return; // no limit set for this category

    const ym  = dateVal.substring(0, 7);
    const key = category + '|' + ym;
    let baseTotal = parseFloat(categoryMonthTotals[key] || 0);

    // If editing and the original expense falls in the same category+month bucket,
    // subtract it first so we don't double count it.
    const editCategory = expenseForm.dataset.editCategory;
    const editAmount   = parseFloat(expenseForm.dataset.editAmount || 0);
    const editDate     = expenseForm.dataset.editDate;
    if (editCategory && editDate) {
        const editYm  = editDate.substring(0, 7);
        const editKey = editCategory + '|' + editYm;
        if (editKey === key) baseTotal -= editAmount;
    }
    if (baseTotal < 0) baseTotal = 0;

    const newTotal = baseTotal + amount;

    if (newTotal > budget) {
        e.preventDefault();
        document.getElementById('exceedCatName').textContent = category;
        document.getElementById('exceedLimit').textContent      = formatPeso(budget);
        document.getElementById('exceedCurrent').textContent    = formatPeso(baseTotal);
        document.getElementById('exceedThis').textContent       = formatPeso(amount);
        document.getElementById('exceedNewTotal').textContent   = formatPeso(newTotal);
        document.getElementById('exceedOverBy').textContent     = formatPeso(newTotal - budget);
        document.getElementById('exceedModalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
});
</script>
</body>
</html>