<?php
require_once 'includes/config.php';
requireAdmin();
$db = getDB();

$year = intval($_GET['year'] ?? date('Y'));

// Available years
$years_res = $db->query("SELECT DISTINCT YEAR(sale_date) as y FROM sales ORDER BY y DESC");
$available_years = [];
while ($yr = $years_res->fetch_assoc()) $available_years[] = $yr['y'];
if (!in_array(date('Y'), $available_years)) array_unshift($available_years, date('Y'));

// Full Jan–Dec for selected year
$monthly = [];
$month_short = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
for ($m = 1; $m <= 12; $m++) {
    $ym  = sprintf('%04d-%02d', $year, $m);
    $r   = $db->query("SELECT COALESCE(SUM(total),0) as t, COUNT(*) as c FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym'")->fetch_assoc();
    $monthly[] = ['short'=>$month_short[$m-1], 'value'=>floatval($r['t']), 'count'=>intval($r['c'])];
}

// Top products — ranked by units sold
$top_products = $db->query("SELECT p.name, p.category, SUM(s.total) as revenue, SUM(s.quantity) as units
    FROM sales s JOIN products p ON s.product_id=p.id
    WHERE YEAR(s.sale_date)='$year' GROUP BY p.id ORDER BY units DESC LIMIT 5");

// Annual totals
$annual_revenue  = floatval($db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE YEAR(sale_date)='$year'")->fetch_assoc()['t']);
$annual_expenses = floatval($db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE YEAR(expense_date)='$year'")->fetch_assoc()['t']);
$annual_profit   = $annual_revenue - $annual_expenses;

// This month
$month          = date('Y-m');
$this_month     = floatval($db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$month'")->fetch_assoc()['t']);
$last_month_val = floatval($db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='".date('Y-m',strtotime('-1 month'))."'")->fetch_assoc()['t']);
$growth         = $last_month_val > 0 ? (($this_month - $last_month_val) / $last_month_val) * 100 : 0;

$max_rev = max(array_column($monthly, 'value')) ?: 1;
$best    = $db->query("SELECT p.name, SUM(s.total) as total, SUM(s.quantity) as units FROM sales s JOIN products p ON s.product_id=p.id WHERE YEAR(s.sale_date)='$year' GROUP BY p.id ORDER BY units DESC LIMIT 1")->fetch_assoc();

// Past year comparison (current vs previous year, same months)
$prev_year     = $year - 1;
$compare = [];
for ($m = 1; $m <= 12; $m++) {
    $ym_cur  = sprintf('%04d-%02d', $year, $m);
    $ym_prev = sprintf('%04d-%02d', $prev_year, $m);
    $cur_v   = floatval($db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym_cur'")->fetch_assoc()['t']);
    $prev_v  = floatval($db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym_prev'")->fetch_assoc()['t']);
    $compare[] = ['short'=>$month_short[$m-1],'cur'=>$cur_v,'prev'=>$prev_v];
}
$cmp_max = max(array_merge(array_column($compare,'cur'), array_column($compare,'prev'))) ?: 1;

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Insights - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bar-chart-wrap { display:flex;align-items:flex-end;gap:5px;height:150px;padding:0 2px; }
        .bar-col { flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;justify-content:flex-end; }
        .bar-val { font-size:7.5px;color:var(--gray);font-weight:600;text-align:center;white-space:nowrap; }
        .bar-body { width:100%;border-radius:4px 4px 0 0;background:linear-gradient(to top,var(--green-dark),var(--green-bright));transition:height .3s; }
        .bar-lbl { font-size:8px;color:var(--gray);font-weight:600;margin-top:3px; }
        .cmp-col { flex:1;display:flex;flex-direction:column;align-items:center;gap:2px; }
        .cmp-bars { display:flex;align-items:flex-end;gap:1px;width:100%; }
        .bar-cur  { flex:1;border-radius:3px 3px 0 0;background:var(--green-main); }
        .bar-prev { flex:1;border-radius:3px 3px 0 0;background:#b0c4de; }
        .year-select { padding:6px 12px;border:2px solid var(--gray-mid);border-radius:8px;
            font-family:'Poppins',sans-serif;font-size:12px;font-weight:600;outline:none;cursor:pointer; }
        .year-select:focus { border-color:var(--green-main); }
        .admin-badge { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
            background:#fff3e0;color:#e67e22;border-radius:20px;font-size:10px;font-weight:700;
            letter-spacing:.4px;text-transform:uppercase; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title">
                <p>Analyze Trends</p>
                <h1>Revenue Insights</h1>
            </div>
            <div class="topbar-user">
                <div class="topbar-avatar">👤</div>
                <span class="admin-badge">🔐 Admin</span>
            </div>
        </div>

        <div class="page-content">
            <!-- Year selector -->
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-bottom:18px;">
                <a href="export_excel.php?type=revenue&year=<?= $year ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#217346;color:white;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;"
                   onmouseover="this.style.background='#185c38'" onmouseout="this.style.background='#217346'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    Export to Excel
                </a>
                <form method="GET" style="display:flex;align-items:center;gap:8px;">
                    <label style="font-size:12px;font-weight:600;color:var(--gray);">Year:</label>
                    <select name="year" class="year-select" onchange="this.form.submit()">
                        <?php foreach($available_years as $y): ?>
                        <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon purple">💰</div>
                    <div class="stat-info"><div class="stat-label">Total Revenue <?= $year ?></div>
                        <div class="stat-value"><?= formatMoney($annual_revenue) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">📈</div>
                    <div class="stat-info"><div class="stat-label">Net Profit</div>
                        <div class="stat-value"><?= formatMoney($annual_profit) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">📅</div>
                    <div class="stat-info"><div class="stat-label">This Month</div>
                        <div class="stat-value"><?= formatMoney($this_month) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">🚀</div>
                    <div class="stat-info"><div class="stat-label">MoM Growth</div>
                        <div class="stat-value" style="color:<?= $growth>=0?'var(--green-bright)':'#dc3545' ?>"><?= ($growth>=0?'+':'').number_format($growth,1) ?>%</div></div>
                </div>
            </div>

            <!-- Full Jan–Dec Revenue Chart -->
            <div class="table-card" style="margin-bottom:22px;">
                <div class="table-card-header">
                    <h3>📈 Revenue Trend — Jan to Dec <?= $year ?></h3>
                    <span style="font-size:12px;color:var(--gray);">Total: <strong style="color:var(--green-main)"><?= formatMoney(array_sum(array_column($monthly,'value'))) ?></strong></span>
                </div>
                <div style="padding:20px 24px 14px;">
                    <div class="bar-chart-wrap" style="align-items:flex-end;">
                        <?php
                        $cur_month_num = date('n');
                        $cur_year_num  = date('Y');
                        foreach ($monthly as $idx => $m):
                            $h     = max(5, round(($m['value'] / $max_rev) * 110));
                            $isCur = ($year==$cur_year_num && ($idx+1)==$cur_month_num);
                        ?>
                        <div class="bar-col" style="justify-content:flex-end;">
                            <div class="bar-val" style="margin-bottom:2px;"><?= $m['value']>0?'₱'.number_format($m['value']/1000,1).'k':'' ?></div>
                            <div class="bar-body" style="height:<?= $h ?>px;<?= $isCur?'background:linear-gradient(to top,#f39c12,#f1c40f);':'' ?>"
                                title="<?= $m['short'].' '.$year.': '.formatMoney($m['value']) ?>"></div>
                            <div class="bar-lbl"><?= $m['short'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:14px;font-size:11px;margin-top:10px;">
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:linear-gradient(to right,var(--green-dark),var(--green-bright));border-radius:2px;display:inline-block;"></span><?= $year ?> Revenue</span>
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:linear-gradient(to right,#f39c12,#f1c40f);border-radius:2px;display:inline-block;"></span>Current Month</span>
                    </div>
                </div>
            </div>

            <!-- Year-over-Year Comparison -->
            <div class="table-card" style="margin-bottom:22px;">
                <div class="table-card-header">
                    <h3>📊 Year-over-Year Comparison — <?= $prev_year ?> vs <?= $year ?></h3>
                    <div style="display:flex;gap:14px;font-size:11px;">
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:#b0c4de;border-radius:2px;display:inline-block;"></span><?= $prev_year ?></span>
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:var(--green-main);border-radius:2px;display:inline-block;"></span><?= $year ?></span>
                    </div>
                </div>
                <div style="padding:20px 24px 14px;">
                    <div style="display:flex;align-items:flex-end;gap:5px;height:130px;">
                        <?php foreach($compare as $c):
                            $ch_cur  = max(4, round(($c['cur']  / $cmp_max) * 120));
                            $ch_prev = $c['prev'] > 0 ? max(4, round(($c['prev'] / $cmp_max) * 120)) : 2;
                        ?>
                        <div class="cmp-col">
                            <div class="cmp-bars">
                                <div class="bar-prev" style="height:<?= $ch_prev ?>px;" title="<?= $c['short'].' '.$prev_year.': '.formatMoney($c['prev']) ?>"></div>
                                <div class="bar-cur"  style="height:<?= $ch_cur ?>px;"  title="<?= $c['short'].' '.$year.': '.formatMoney($c['cur']) ?>"></div>
                            </div>
                            <div class="bar-lbl"><?= $c['short'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:10px;color:var(--gray);">
                        <span><?= $prev_year ?> Total: <strong><?= formatMoney(array_sum(array_column($compare,'prev'))) ?></strong></span>
                        <span><?= $year ?> Total: <strong style="color:var(--green-main)"><?= formatMoney(array_sum(array_column($compare,'cur'))) ?></strong></span>
                        <?php
                        $prev_total = array_sum(array_column($compare,'prev'));
                        $cur_total  = array_sum(array_column($compare,'cur'));
                        $yoy = $prev_total > 0 ? (($cur_total - $prev_total) / $prev_total) * 100 : 0;
                        ?>
                        <span>YoY: <strong style="color:<?= $yoy>=0?'var(--green-bright)':'#dc3545' ?>"><?= ($yoy>=0?'+':'').number_format($yoy,1) ?>%</strong></span>
                    </div>
                </div>
            </div>

            <div class="two-col">
                <!-- Top Products -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3>🏆 Top Products by Units Sold — <?= $year ?></h3>
                        <?php if ($best): ?>
                        <span class="badge badge-green">Best: <?= htmlspecialchars($best['name']) ?> (<?= number_format($best['units']) ?> units)</span>
                        <?php endif; ?>
                    </div>
                    <table class="data-table">
                        <thead><tr><th>Rank</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th></tr></thead>
                        <tbody>
                            <?php if ($top_products): $rank=1; while($tp=$top_products->fetch_assoc()): ?>
                            <tr>
                                <td><?php $medals=['🥇','🥈','🥉','4️⃣','5️⃣']; echo $medals[$rank-1]??$rank; ?></td>
                                <td style="font-weight:600"><?= htmlspecialchars($tp['name']) ?></td>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($tp['category']) ?></span></td>
                                <td style="font-weight:700;color:var(--green-main)"><?= number_format($tp['units']) ?></td>
                                <td style="color:var(--gray)"><?= formatMoney($tp['revenue']) ?></td>
                            </tr>
                            <?php $rank++; endwhile; else: ?>
                            <tr><td colspan="5" style="text-align:center;color:var(--gray)">No data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Sales Highlights -->
                <div class="table-card" style="border-top:3px solid var(--purple);">
                    <div class="table-card-header"><h3>💎 Sales Highlights</h3></div>
                    <div style="padding:20px;">
                        <div class="stat-row" style="padding:12px 0;"><span class="label">Total Revenue</span><span class="value" style="font-size:18px;color:var(--purple)"><?= formatMoney($annual_revenue) ?></span></div>
                        <div class="stat-row" style="padding:12px 0;"><span class="label">Total Expenses</span><span class="value" style="color:#dc3545"><?= formatMoney($annual_expenses) ?></span></div>
                        <div class="stat-row" style="padding:12px 0;"><span class="label">Net Profit</span><span class="value" style="font-size:18px;color:var(--green-bright)"><?= formatMoney($annual_profit) ?></span></div>
                        <div class="stat-row" style="padding:12px 0;"><span class="label">Profit Margin</span><span class="value"><?= $annual_revenue>0?number_format(($annual_profit/$annual_revenue)*100,1):0 ?>%</span></div>
                        <div class="stat-row" style="padding:12px 0;"><span class="label">Best Selling Product</span><span class="value"><?= $best?htmlspecialchars($best['name']):'N/A' ?></span></div>
                        <?php if ($best): ?>
                        <div style="margin-top:14px;padding:14px;background:var(--purple-light);border-radius:8px;text-align:center;">
                            <div style="font-size:24px;margin-bottom:4px;">🏆</div>
                            <div style="font-size:14px;font-weight:700;color:var(--purple)"><?= htmlspecialchars($best['name']) ?></div>
                            <div style="font-size:11px;color:var(--gray)">Top Sold Product <?= $year ?></div>
                            <div style="font-size:18px;font-weight:800;color:var(--purple);margin-top:4px;"><?= number_format($best['units']) ?> units sold</div>
                        </div>
                        <?php endif; ?>
                        <a href="reports.php?year=<?= $year ?>" style="display:block;text-align:center;padding:11px;background:var(--purple);color:white;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;margin-top:14px;">View Full Report</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>