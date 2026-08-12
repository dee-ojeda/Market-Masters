<?php
require_once 'includes/config.php';
requireAdmin();
$db = getDB();
 
// Current month stats — using YEAR()+MONTH() for reliable matching
$cur_year  = intval(date('Y'));
$cur_month = intval(date('m'));
if ($cur_month == 1) { $last_year = $cur_year - 1; $last_mon = 12; }
else                 { $last_year = $cur_year;     $last_mon = $cur_month - 1; }

$sales    = $db->query("SELECT SUM(total) as total, COUNT(*) as cnt FROM sales WHERE YEAR(sale_date)=$cur_year AND MONTH(sale_date)=$cur_month")->fetch_assoc();
$expenses = $db->query("SELECT SUM(amount) as total FROM expenses WHERE YEAR(expense_date)=$cur_year AND MONTH(expense_date)=$cur_month")->fetch_assoc();
$total_sales    = floatval($sales['total'] ?? 0);
$total_expenses = floatval($expenses['total'] ?? 0);
$net_profit     = $total_sales - $total_expenses;

// Last month comparison — also using YEAR()+MONTH()
$last_sales = $db->query("SELECT SUM(total) as total FROM sales WHERE YEAR(sale_date)=$last_year AND MONTH(sale_date)=$last_mon")->fetch_assoc();
$last_total = floatval($last_sales['total'] ?? 0);
$growth = $last_total > 0 ? (($total_sales - $last_total) / $last_total) * 100 : ($total_sales > 0 ? 100 : 0);
 
// Monthly sales for chart (Jan - Dec current year)
$chart_data = [];
for ($i = 1; $i <= 12; $i++) {
    $label = date('M', mktime(0, 0, 0, $i, 1));
    $r = $db->query("SELECT SUM(total) as t FROM sales WHERE YEAR(sale_date)=$cur_year AND MONTH(sale_date)=$i")->fetch_assoc();
    $chart_data[] = ['label' => $label, 'value' => floatval($r['t'] ?? 0)];
}
 
// Financial summary (current year) — using YEAR() consistently
$year = $cur_year;
$year_sales   = $db->query("SELECT SUM(total) as total FROM sales WHERE YEAR(sale_date)=$year")->fetch_assoc();
$year_exp     = $db->query("SELECT SUM(amount) as total FROM expenses WHERE YEAR(expense_date)=$year")->fetch_assoc();
$year_total    = floatval($year_sales['total'] ?? 0);
$year_expenses = floatval($year_exp['total'] ?? 0);
$year_profit   = $year_total - $year_expenses;
 
// Best selling product — this month
$best = $db->query("SELECT p.name, SUM(s.total) as total FROM sales s JOIN products p ON s.product_id=p.id WHERE YEAR(s.sale_date)=$cur_year AND MONTH(s.sale_date)=$cur_month GROUP BY p.id ORDER BY total DESC LIMIT 1")->fetch_assoc();
// Fallback to year if no sales this month
if (!$best) {
    $best = $db->query("SELECT p.name, SUM(s.total) as total FROM sales s JOIN products p ON s.product_id=p.id WHERE YEAR(s.sale_date)=$cur_year GROUP BY p.id ORDER BY total DESC LIMIT 1")->fetch_assoc();
}

// Month revenue stats for Revenue Insights card
$month_revenue_sales = $db->query("SELECT SUM(total) as total FROM sales WHERE YEAR(sale_date)=$cur_year AND MONTH(sale_date)=$cur_month")->fetch_assoc();
$month_revenue_exp   = $db->query("SELECT SUM(amount) as total FROM expenses WHERE YEAR(expense_date)=$cur_year AND MONTH(expense_date)=$cur_month")->fetch_assoc();
$month_rev_sales  = floatval($month_revenue_sales['total'] ?? 0);
$month_rev_exp    = floatval($month_revenue_exp['total'] ?? 0);
$month_rev_profit = $month_rev_sales - $month_rev_exp;
 
// Revenue last 6 months (dynamic: last 6 months from today)
$revenue_6 = [];
for ($i = 5; $i >= 0; $i--) {
    $ts    = mktime(0, 0, 0, $cur_month - $i, 1, $cur_year);
    $my    = intval(date('Y', $ts));
    $mm    = intval(date('m', $ts));
    $label = date('M', $ts);
    $r     = $db->query("SELECT SUM(total) as t FROM sales WHERE YEAR(sale_date)=$my AND MONTH(sale_date)=$mm")->fetch_assoc();
    $revenue_6[] = ['label' => $label, 'value' => floatval($r['t'] ?? 0)];
}
$max_rev = max(array_column($revenue_6, 'value')) ?: 1;
 
// Expense breakdown by category
$exp_breakdown = $db->query("SELECT category, SUM(amount) as total FROM expenses GROUP BY category ORDER BY total DESC");
$exp_cats = [];
while ($row = $exp_breakdown->fetch_assoc()) $exp_cats[] = $row;
$total_exp_cat = array_sum(array_column($exp_cats, 'total')) ?: 1;

// All months that have expenses (for month picker)
$exp_months_res = $db->query("SELECT DISTINCT DATE_FORMAT(expense_date,'%Y-%m') as ym, DATE_FORMAT(expense_date,'%M %Y') as label FROM expenses ORDER BY ym DESC");
$exp_months = [];
while ($row = $exp_months_res->fetch_assoc()) $exp_months[] = $row;

// Expense data grouped by month+category for JS
$exp_all_res = $db->query("SELECT DATE_FORMAT(expense_date,'%Y-%m') as ym, category, SUM(amount) as total FROM expenses GROUP BY ym, category ORDER BY ym DESC, total DESC");
$exp_all_data = [];
while ($row = $exp_all_res->fetch_assoc()) {
    $exp_all_data[$row['ym']][] = ['category' => $row['category'], 'total' => floatval($row['total'])];
}
 
$max_chart = max(array_column($chart_data, 'value')) ?: 1;
 
$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .logout-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.5);
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            z-index: 99999; align-items: center; justify-content: center;
        }
        .logout-modal-overlay.active { display: flex; animation: lmoFade .2s ease; }
        .logout-modal-box {
            background: #fff; border-radius: 20px;
            box-shadow: 0 24px 64px rgba(0,0,0,.22);
            padding: 40px 36px 32px; width: 100%; max-width: 380px;
            text-align: center; animation: lmoPop .25s cubic-bezier(.34,1.56,.64,1);
        }
        .logout-modal-icon {
            width: 70px; height: 70px; border-radius: 50%;
            background: #fff3e0; border: 3px solid #ffe0b2;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; margin: 0 auto 20px;
        }
        .logout-modal-box h3 { font-size: 20px; font-weight: 800; color: #1a1a2e; margin: 0 0 10px; }
        .logout-modal-box p  { font-size: 13px; color: #888; margin: 0 0 28px; line-height: 1.6; }
        .logout-modal-actions { display: flex; gap: 12px; }
        .btn-stay {
            flex: 1; padding: 13px 0; border-radius: 12px;
            border: 2px solid #e0e0e0; background: #f7f7f7;
            color: #555; font-size: 13px; font-weight: 700;
            cursor: pointer; font-family: inherit; transition: background .18s;
        }
        .btn-stay:hover { background: #ebebeb; }
        .btn-logout-confirm {
            flex: 1; padding: 13px 0; border-radius: 12px; border: none;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: #fff; font-size: 13px; font-weight: 700;
            cursor: pointer; font-family: inherit;
            box-shadow: 0 4px 14px rgba(192,57,43,.35);
            transition: transform .15s, box-shadow .15s;
            text-decoration: none; display: flex;
            align-items: center; justify-content: center; gap: 6px;
        }
        .btn-logout-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(192,57,43,.4); }
        @keyframes lmoFade { from{opacity:0} to{opacity:1} }
        @keyframes lmoPop  { from{opacity:0;transform:scale(.85)} to{opacity:1;transform:scale(1)} }
    </style>
</head>
<body>

<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal-box">
        <div class="logout-modal-icon">👋</div>
        <h3>Leaving so soon?</h3>
        <p>Are you sure you want to log out of <strong>Profit Lens</strong>?<br>You'll need to sign in again to access your dashboard.</p>
        <div class="logout-modal-actions">
            <button class="btn-stay" onclick="closeLogoutModal()">Stay Logged In</button>
            <a href="logout.php" class="btn-logout-confirm">🚪 Yes, Log Out</a>
        </div>
    </div>
</div>

<script>
function openLogoutModal() {
    document.getElementById('logoutModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('logoutModal').addEventListener('click', function(e) {
        if (e.target === this) closeLogoutModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogoutModal();
    });
    document.querySelectorAll('a[href="logout.php"]').forEach(function(link) {
        if (link.classList.contains('btn-logout-confirm')) return;
        link.addEventListener('click', function(e) {
            e.preventDefault();
            openLogoutModal();
        });
    });
});
</script>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">
                <p>Welcome, Admin!</p>
                <h1>Dashboard Overview!</h1>
            </div>
            <div class="topbar-user">
                <div class="topbar-avatar">👤</div>
                <span class="admin-badge">🔐 Admin</span>
            </div>
        </div>
 
        <div class="page-content">
            <div class="dashboard-grid">
 
                <!-- ── Profit This Month ── -->
                <div class="dash-card card-green">
                    <div class="dash-card-header">
                        <h3>Profit This Month</h3>
                        <a href="profit.php">View Sales &amp; Profit</a>
                    </div>
                    <div class="big-stat"><?= formatMoney($net_profit) ?></div>
                    <div class="stat-badge <?= $growth < 0 ? 'negative' : '' ?>">
                        <?= $last_total <= 0 && $total_sales > 0 ? '+100%' : (($growth >= 0 ? '+' : '') . number_format($growth, 1) . '%') ?>
                    </div>
                    <div class="mini-chart">
                        <?php foreach ($chart_data as $i => $d): 
                            $h = max(6, round(($d['value'] / $max_chart) * 50));
                            $cls = ($i + 1) == $cur_month ? 'green' : 'green-light';
                        ?>
                        <div class="mini-bar <?= $cls ?>" style="height:<?= $h ?>px" title="<?= $d['label'] ?>: <?= formatMoney($d['value']) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray);margin-bottom:12px;">
                        <?php foreach($chart_data as $d): ?><span><?= $d['label'] ?></span><?php endforeach; ?>
                    </div>
                    <div class="stat-row"><span class="label">Total Sales</span><span class="value"><?= formatMoney($total_sales) ?></span></div>
                    <div class="stat-row"><span class="label">Expenses</span><span class="value"><?= formatMoney($total_expenses) ?></span></div>
                    <div class="stat-row"><span class="label">Net Profit</span><span class="value"><?= formatMoney($net_profit) ?></span></div>
                    <div style="margin-top:10px;">
                        <div class="stat-row"><span class="label">Report Summary:</span></div>
                        <div class="stat-row"><span class="label"><?= date('F Y') ?></span><span class="value"><?= formatMoney($net_profit) ?></span></div>
                        <div class="stat-row"><span class="label">Last Month Sales</span><span class="value"><?= formatMoney($last_total) ?></span></div>
                    </div>
                    <a href="profit.php" class="btn-view btn-view-green">View Details</a>
                </div>
 
                <!-- ── Financial Reports ── -->
                <div class="dash-card card-orange">
                    <div class="dash-card-header">
                        <h3>Financial Reports</h3>
                        <a href="reports.php" style="color:var(--orange)">View Statements</a>
                    </div>
                    <div style="font-size:13px;font-weight:700;margin-bottom:12px;">Profit &amp; Loss — <?= $cur_year ?></div>
                    <div class="stat-row"><span class="label">Total Income</span><span class="value" style="color:var(--green-main)"><?= formatMoney($year_total) ?></span></div>
                    <div class="stat-row"><span class="label">Operating Expenses</span><span class="value" style="color:#dc3545"><?= formatMoney($year_expenses) ?></span></div>
                    <div class="stat-row"><span class="label">Total Profit</span><span class="value" style="font-weight:800"><?= formatMoney($year_profit) ?></span></div>
                    <div style="margin:14px 0 10px;padding:12px;background:<?= $year_profit >= 0 ? 'var(--gray-light)' : '#fde8e8' ?>;border-radius:8px;">
                        <div style="font-size:11px;color:var(--gray);font-weight:600;">Net Profit <?= $cur_year ?></div>
                        <div style="font-size:20px;font-weight:800;color:<?= $year_profit >= 0 ? 'var(--dark)' : '#dc3545' ?>;"><?= formatMoney($year_profit) ?></div>
                    </div>
                    <div style="font-size:11px;color:var(--gray);margin-bottom:6px;">
                        This Month (<?= date('M Y') ?>):
                        <strong style="color:var(--dark)"><?= formatMoney($total_sales) ?></strong> sales /
                        <strong style="color:#dc3545"><?= formatMoney($total_expenses) ?></strong> exp
                    </div>
                    <div class="mini-chart" style="height:60px;">
                        <?php foreach($chart_data as $i => $d):
                            $h = max(6, round(($d['value'] / $max_chart) * 55));
                            $colors = ['#fd7e14','#ff9500','#ffc107','#fd7e14','#ff9500','#ffc107','#fd7e14','#ff9500','#ffc107','#fd7e14','#ff9500','#ffc107'];
                        ?>
                        <div class="mini-bar" style="height:<?= $h ?>px;background:<?= ($i+1)==$cur_month ? '#e74c3c' : $colors[$i] ?>;" title="<?= $d['label'] ?>: <?= formatMoney($d['value']) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray);margin-bottom:10px;">
                        <?php foreach($chart_data as $d): ?><span><?= $d['label'] ?></span><?php endforeach; ?>
                    </div>
                    <a href="reports.php?type=expense" class="btn-view btn-view-outline btn-view-orange-outline">Expense Report</a>
                    <a href="reports.php?type=profit" class="btn-view btn-view-outline btn-view-orange-outline">Profit &amp; Loss</a>
                </div>
 
                <!-- ── Expense Tracking ── -->
                <div class="dash-card card-blue">
                    <div class="dash-card-header">
                        <h3>Expense Tracking</h3>
                        <a href="expenses.php" style="color:var(--blue)">Record Expenses</a>
                    </div>

                    <div style="margin-bottom:10px;">
                        <select id="exp-month-picker"
                            onchange="renderExpenses(this.value)"
                            style="width:100%;padding:8px 12px;border:2px solid var(--gray-mid);border-radius:8px;
                                   font-family:'Poppins',sans-serif;font-size:12px;font-weight:600;
                                   outline:none;cursor:pointer;appearance:none;background:white;
                                   background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E\");
                                   background-repeat:no-repeat;background-position:right 10px center;padding-right:28px;"
                            onfocus="this.style.borderColor='var(--blue)'"
                            onblur="this.style.borderColor='var(--gray-mid)'">
                            <option value="all">📊 All Time</option>
                            <?php foreach($exp_months as $em): ?>
                            <option value="<?= $em['ym'] ?>" <?= $em['ym'] === date('Y-m') ? 'selected' : '' ?>><?= $em['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="font-size:12px;font-weight:700;margin-bottom:8px;">Expenses by Category</div>
                    <div id="exp-cat-list" style="margin-bottom:4px;"></div>

                    <div style="margin-top:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <div style="font-size:12px;font-weight:700;">Breakdown</div>
                            <span class="badge badge-orange" id="exp-pct-badge">—</span>
                        </div>
                        <div style="text-align:center;margin:6px 0;">
                            <svg id="exp-donut" width="110" height="110" viewBox="0 0 110 110"></svg>
                        </div>
                        <div id="exp-legend" style="display:flex;flex-direction:column;gap:4px;font-size:10px;max-height:100px;overflow-y:auto;"></div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;">
                        <a href="expenses.php" class="btn-view btn-view-green" style="margin:0;font-size:11px;">Add Expense</a>
                        <a href="expenses.php" class="btn-view btn-view-outline" style="margin:0;font-size:11px;border-color:var(--gray-mid);">View All →</a>
                    </div>
                    <a href="expenses.php" class="btn-view btn-view-outline" style="border-color:var(--gray-mid);margin-top:8px;font-size:11px;">Expense Report →</a>
                </div>

                <script>
                const EXP_ALL = <?= json_encode($exp_cats) ?>;
                const EXP_BY_MONTH = <?= json_encode($exp_all_data) ?>;
                const TOTAL_SALES = <?= $total_sales ?>;
                const COLORS = ['#4285f4','#81c784','#ff9800','#9c27b0','#e53935','#00acc1','#f06292','#43a047','#fb8c00','#5c6bc0'];

                function fmt(n) {
                    return '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
                }

                function renderExpenses(ym) {
                    const cats = ym === 'all' ? EXP_ALL : (EXP_BY_MONTH[ym] || []);
                    const grandTotal = cats.reduce((s, c) => s + parseFloat(c.total), 0) || 1;
                    const list = document.getElementById('exp-cat-list');
                    if (cats.length === 0) {
                        list.innerHTML = '<div style="font-size:11px;color:#aaa;padding:4px 0;">No expenses for this period.</div>';
                    } else {
                        list.innerHTML = cats.map(c =>
                            `<div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 0;">
                                <span style="color:#555;">${c.category}</span>
                                <span style="font-weight:700;">${fmt(c.total)}</span>
                            </div>`
                        ).join('');
                    }
                    const pct = TOTAL_SALES > 0 ? ((grandTotal / TOTAL_SALES) * 100).toFixed(1) : '—';
                    document.getElementById('exp-pct-badge').textContent = pct + (TOTAL_SALES > 0 ? '%' : '');
                    const svg = document.getElementById('exp-donut');
                    if (cats.length === 0) {
                        svg.innerHTML = '<circle cx="55" cy="55" r="42" fill="#f0f0f0"/><circle cx="55" cy="55" r="24" fill="white"/><text x="55" y="59" text-anchor="middle" font-size="9" fill="#aaa">No data</text>';
                        document.getElementById('exp-legend').innerHTML = '';
                        return;
                    }
                    let startAngle = -90, paths = '', outerR = 42, innerR = 24;
                    cats.forEach((cat, ci) => {
                        const angle = (cat.total / grandTotal) * 360;
                        if (angle < 0.5) { startAngle += angle; return; }
                        const endAngle = startAngle + angle;
                        const toRad = a => a * Math.PI / 180;
                        const x1=55+outerR*Math.cos(toRad(startAngle)), y1=55+outerR*Math.sin(toRad(startAngle));
                        const x2=55+outerR*Math.cos(toRad(endAngle)),   y2=55+outerR*Math.sin(toRad(endAngle));
                        const xi1=55+innerR*Math.cos(toRad(startAngle)),yi1=55+innerR*Math.sin(toRad(startAngle));
                        const xi2=55+innerR*Math.cos(toRad(endAngle)),  yi2=55+innerR*Math.sin(toRad(endAngle));
                        const large = angle > 180 ? 1 : 0;
                        const color = COLORS[ci % COLORS.length];
                        paths += `<path d="M ${xi1} ${yi1} A ${innerR} ${innerR} 0 ${large} 1 ${xi2} ${yi2} L ${x2} ${y2} A ${outerR} ${outerR} 0 ${large} 0 ${x1} ${y1} Z" fill="${color}" opacity="0.92"/>`;
                        startAngle = endAngle;
                    });
                    svg.innerHTML = paths +
                        `<circle cx="55" cy="55" r="18" fill="white"/>` +
                        `<text x="55" y="58" text-anchor="middle" font-size="9" font-weight="700" fill="#555">${cats.length} cats</text>`;
                    document.getElementById('exp-legend').innerHTML = cats.map((cat, ci) =>
                        `<div style="display:flex;align-items:center;justify-content:space-between;gap:4px;">
                            <span style="display:flex;align-items:center;gap:4px;min-width:0;">
                                <span style="flex-shrink:0;width:9px;height:9px;border-radius:50%;background:${COLORS[ci%COLORS.length]};display:inline-block;"></span>
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${cat.category}</span>
                            </span>
                            <span style="flex-shrink:0;font-weight:700;color:#555;">${Math.round((cat.total/grandTotal)*100)}%</span>
                        </div>`
                    ).join('');
                }

                document.addEventListener('DOMContentLoaded', function() {
                    renderExpenses(document.getElementById('exp-month-picker').value);
                });
                </script>
 
                <!-- ── Revenue Insights ── -->
                <div class="dash-card card-purple">
                    <div class="dash-card-header">
                        <h3>Revenue Insights</h3>
                        <a href="revenue.php" style="color:var(--purple)">Analyze Trends</a>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-size:12px;font-weight:700;">Revenue Trend:</span>
                        <span style="font-size:10px;color:var(--gray);">Last 6 months</span>
                    </div>
                    <svg width="100%" height="60" viewBox="0 0 200 60" style="margin-bottom:4px;">
                        <?php
                        $points = '';
                        foreach($revenue_6 as $i => $rv) {
                            $x = 10 + ($i * 36);
                            $y = 55 - (($rv['value'] / $max_rev) * 50);
                            $points .= "$x,$y ";
                        }
                        ?>
                        <polyline points="<?= trim($points) ?>" fill="none" stroke="#9c27b0" stroke-width="2" stroke-linejoin="round"/>
                        <?php foreach($revenue_6 as $i => $rv):
                            $x = 10 + ($i * 36);
                            $y = 55 - (($rv['value'] / $max_rev) * 50);
                        ?>
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="3" fill="#9c27b0"/>
                        <?php endforeach; ?>
                    </svg>
                    <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--gray);margin-bottom:14px;">
                        <?php foreach($revenue_6 as $rv): ?><span><?= $rv['label'] ?></span><?php endforeach; ?>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div style="font-size:12px;font-weight:700;">Sales Highlights</div>
                        <span style="font-size:10px;color:var(--gray);"><?= date('M Y') ?></span>
                    </div>
                    <div class="stat-row"><span class="label">Total Revenue</span><span class="value" style="color:var(--green-main)"><?= formatMoney($month_rev_sales) ?></span></div>
                    <div class="stat-row"><span class="label">Total Expenses</span><span class="value" style="color:#dc3545"><?= formatMoney($month_rev_exp) ?></span></div>
                    <div class="stat-row"><span class="label">Net Profit</span><span class="value" style="font-weight:800;color:<?= $month_rev_profit>=0?'var(--green-main)':' #dc3545' ?>"><?= formatMoney($month_rev_profit) ?></span></div>
                    <div class="stat-row"><span class="label">Year Total Revenue</span><span class="value"><?= formatMoney($year_total) ?></span></div>
                    <div class="stat-row"><span class="label">Best Selling Product:</span><span class="value" style="font-size:10px;"><?= $best ? htmlspecialchars($best['name']) : 'N/A' ?></span></div>
                    <?php if ($best): ?>
                    <div style="padding:8px 10px;background:var(--purple-light);border-radius:8px;font-size:12px;font-weight:600;color:var(--purple);margin-top:8px;">
                        🏆 <?= htmlspecialchars($best['name']) ?>
                    </div>
                    <?php endif; ?>
                    <a href="revenue.php" class="btn-view" style="background:var(--purple);color:white;margin-top:12px;display:block;text-align:center;padding:10px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">View Analysis</a>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>