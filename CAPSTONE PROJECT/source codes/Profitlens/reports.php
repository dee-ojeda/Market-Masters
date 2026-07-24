<?php
require_once 'includes/config.php';
requireAdmin();
$db = getDB();

$type = $_GET['type'] ?? 'profit';
$year = intval($_GET['year'] ?? date('Y'));

// Available years
$years_res = $db->query("SELECT DISTINCT YEAR(sale_date) as y FROM sales UNION SELECT DISTINCT YEAR(expense_date) FROM expenses ORDER BY y DESC");
$available_years = [];
while ($yr = $years_res->fetch_assoc()) $available_years[] = $yr['y'];
if (!in_array(date('Y'), $available_years)) array_unshift($available_years, date('Y'));

// Full Jan–Dec P&L data
$monthly = [];
$month_labels = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$month_short  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
for ($m = 1; $m <= 12; $m++) {
    $ym   = sprintf('%04d-%02d', $year, $m);
    $s    = $db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym'")->fetch_assoc();
    $e    = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$ym'")->fetch_assoc();
    $sales_v    = floatval($s['t']);
    $expenses_v = floatval($e['t']);
    $monthly[]  = [
        'month'    => $month_labels[$m-1],
        'short'    => $month_short[$m-1],
        'sales'    => $sales_v,
        'expenses' => $expenses_v,
        'profit'   => $sales_v - $expenses_v
    ];
}

$year_total_sales    = array_sum(array_column($monthly, 'sales'));
$year_total_expenses = array_sum(array_column($monthly, 'expenses'));
$year_net_profit     = $year_total_sales - $year_total_expenses;

$exp_by_cat = $db->query("SELECT category, SUM(amount) as total, COUNT(*) as count FROM expenses WHERE YEAR(expense_date)='$year' GROUP BY category ORDER BY total DESC");
// Scale against the highest value of EITHER sales or expenses — prevents overflow
$max_monthly = max(max(array_column($monthly, 'sales')), max(array_column($monthly, 'expenses'))) ?: 1;

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bar-chart-wrap { display:flex; align-items:flex-end; gap:4px; height:155px; padding:0 2px; overflow:hidden; }
        .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:2px; }
        .bar-col .bar-val { font-size:7.5px; color:var(--gray); font-weight:600; text-align:center; white-space:nowrap; }
        .bar-col .bar-rev { width:100%; border-radius:3px 3px 0 0; background:var(--green-main); transition:height .3s; }
        .bar-col .bar-exp { width:100%; border-radius:3px 3px 0 0; background:#fd7e14; transition:height .3s; }
        .bar-col .bar-lbl { font-size:8px; color:var(--gray); font-weight:600; margin-top:3px; }
        .year-select { padding:6px 12px; border:2px solid var(--gray-mid); border-radius:8px;
            font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; outline:none; cursor:pointer; }
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
                <p>View Statements</p>
                <h1>Financial Reports</h1>
            </div>
            <div class="topbar-user">
                <span class="admin-badge">🔐 Admin</span>
                <div class="topbar-avatar">👤</div>
            </div>
        </div>
        <div class="page-content">

            <!-- Tabs + Year selector -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;gap:10px;">
                    <a href="reports.php?type=profit&year=<?= $year ?>"
                       class="btn-view <?= $type==='profit'?'btn-view-green':'btn-view-outline btn-view-green-outline' ?>"
                       style="width:auto;padding:9px 20px;text-decoration:none;">Profit &amp; Loss</a>
                    <a href="reports.php?type=expense&year=<?= $year ?>"
                       class="btn-view <?= $type==='expense'?'':'btn-view-outline' ?>"
                       style="width:auto;padding:9px 20px;text-decoration:none;background:<?= $type==='expense'?'var(--orange)':'transparent' ?>;color:<?= $type==='expense'?'white':'var(--orange)' ?>;border:1.5px solid var(--orange);">Expense Report</a>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?php $export_type = $type === 'profit' ? 'profit_loss' : 'expense'; ?>
                    <a href="export_excel.php?type=<?= $export_type ?>&year=<?= $year ?>"
                       style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#217346;color:white;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;letter-spacing:.3px;"
                       onmouseover="this.style.background='#185c38'" onmouseout="this.style.background='#217346'">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Export to Excel
                    </a>
                    <form method="GET" style="display:flex;align-items:center;gap:8px;">
                        <input type="hidden" name="type" value="<?= $type ?>">
                        <label style="font-size:12px;font-weight:600;color:var(--gray);">Year:</label>
                        <select name="year" class="year-select" onchange="this.form.submit()">
                            <?php foreach($available_years as $y): ?>
                            <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon green">💵</div>
                    <div class="stat-info"><div class="stat-label">Total Income <?= $year ?></div>
                        <div class="stat-value"><?= formatMoney($year_total_sales) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">📉</div>
                    <div class="stat-info"><div class="stat-label">Operating Expenses</div>
                        <div class="stat-value"><?= formatMoney($year_total_expenses) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">📊</div>
                    <div class="stat-info"><div class="stat-label">Total Profit</div>
                        <div class="stat-value"><?= formatMoney($year_net_profit) ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">💎</div>
                    <div class="stat-info"><div class="stat-label">Profit Margin</div>
                        <div class="stat-value"><?= $year_total_sales>0?number_format(($year_net_profit/$year_total_sales)*100,1):0 ?>%</div></div>
                </div>
            </div>

            <?php if ($type === 'profit'): ?>

            <!-- Full Jan–Dec grouped bar chart -->
            <div class="table-card" style="margin-bottom:22px;">
                <div class="table-card-header">
                    <h3>📊 Revenue vs Expenses — Jan to Dec <?= $year ?></h3>
                    <div style="display:flex;gap:14px;font-size:11px;">
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:var(--green-main);border-radius:2px;display:inline-block;"></span>Revenue</span>
                        <span style="display:flex;align-items:center;gap:4px;"><span style="width:11px;height:7px;background:#fd7e14;border-radius:2px;display:inline-block;"></span>Expenses</span>
                    </div>
                </div>
                <div style="padding:20px 24px 14px;">
                    <div class="bar-chart-wrap">
                        <?php foreach($monthly as $m):
                            $rh = $m['sales']   > 0 ? min(120, max(4, round(($m['sales']   / $max_monthly) * 120))) : 0;
                            $eh = $m['expenses'] > 0 ? min(120, max(4, round(($m['expenses'] / $max_monthly) * 120))) : 0;
                        ?>
                        <div class="bar-col">
                            <div style="display:flex;align-items:flex-end;gap:1px;width:100%;height:120px;">
                                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                                    <div class="bar-val" style="color:var(--green-main);margin-bottom:2px;"><?= $m['sales']>0?'₱'.number_format($m['sales']/1000,1).'k':'' ?></div>
                                    <div class="bar-rev" style="height:<?= $rh ?>px;width:100%;" title="Revenue: <?= formatMoney($m['sales']) ?>"></div>
                                </div>
                                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                                    <div class="bar-val" style="color:#fd7e14;margin-bottom:2px;"><?= $m['expenses']>0?'₱'.number_format($m['expenses']/1000,1).'k':'' ?></div>
                                    <div class="bar-exp" style="height:<?= $eh ?>px;width:100%;" title="Expenses: <?= formatMoney($m['expenses']) ?>"></div>
                                </div>
                            </div>
                            <div class="bar-lbl"><?= $m['short'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="text-align:right;font-size:11px;margin-top:8px;color:var(--gray);">
                        Year Total: <strong style="color:var(--green-main)"><?= formatMoney($year_total_sales) ?></strong>
                        &nbsp;|&nbsp; Net Profit: <strong style="color:<?= $year_net_profit>=0?'var(--green-main)':'#dc3545' ?>"><?= formatMoney($year_net_profit) ?></strong>
                    </div>
                </div>
            </div>

            <!-- P&L Table -->
            <div class="two-col">
                <div class="table-card" style="display:flex;flex-direction:column;max-height:520px;">
                    <div class="table-card-header" style="flex-shrink:0;">
                        <h3>📋 Profit &amp; Loss Statement — <?= $year ?></h3>
                        <span class="badge badge-green"><?= $year ?></span>
                    </div>
                    <!-- Scrollable body only -->
                    <div style="overflow-y:auto;flex:1;">
                        <table class="data-table">
                            <thead style="position:sticky;top:0;z-index:1;"><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Net Profit</th></tr></thead>
                            <tbody>
                                <?php foreach($monthly as $m): ?>
                                <tr>
                                    <td style="font-weight:600"><?= $m['month'] ?></td>
                                    <td style="color:var(--green-bright)"><?= formatMoney($m['sales']) ?></td>
                                    <td style="color:#dc3545"><?= formatMoney($m['expenses']) ?></td>
                                    <td style="font-weight:700;color:<?= $m['profit']>=0?'var(--green-main)':'#dc3545' ?>"><?= formatMoney($m['profit']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Fixed TOTAL row always visible at bottom -->
                    <div style="flex-shrink:0;border-top:2px solid var(--gray-mid);">
                        <table class="data-table" style="margin:0;">
                            <tfoot>
                                <tr style="background:var(--gray-light);">
                                    <td style="font-weight:800">TOTAL</td>
                                    <td style="font-weight:700;color:var(--green-bright)"><?= formatMoney($year_total_sales) ?></td>
                                    <td style="font-weight:700;color:#dc3545"><?= formatMoney($year_total_expenses) ?></td>
                                    <td style="font-weight:800;font-size:15px;color:<?= $year_net_profit>=0?'var(--green-main)':'#dc3545' ?>"><?= formatMoney($year_net_profit) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Monthly Profit Improvement -->
                <div class="table-card" style="display:flex;flex-direction:column;max-height:520px;">
                    <div class="table-card-header" style="flex-shrink:0;">
                        <h3>📈 Monthly Profit Improvement</h3>
                        <div style="display:flex;gap:10px;font-size:10px;font-weight:700;">
                            <span style="display:flex;align-items:center;gap:3px;"><span style="width:9px;height:9px;background:var(--green-main);border-radius:2px;display:inline-block;"></span>Revenue</span>
                            <span style="display:flex;align-items:center;gap:3px;"><span style="width:9px;height:9px;background:#fd7e14;border-radius:2px;display:inline-block;"></span>Expenses</span>
                            <span style="display:flex;align-items:center;gap:3px;"><span style="width:9px;height:9px;background:#6f42c1;border-radius:2px;display:inline-block;"></span>Profit</span>
                        </div>
                    </div>
                    <!-- Scrollable month rows only -->
                    <div style="padding:16px 20px 8px;overflow-y:auto;flex:1;">
                        <?php
                        $max_pi = max(
                            max(array_column($monthly,'sales')),
                            max(array_column($monthly,'expenses')),
                            max(array_map('abs', array_column($monthly,'profit')))
                        ) ?: 1;
                        $prev_profit = null;
                        foreach ($monthly as $idx => $m):
                            $rev_pct   = $max_pi > 0 ? ($m['sales']    / $max_pi) * 100 : 0;
                            $exp_pct   = $max_pi > 0 ? ($m['expenses'] / $max_pi) * 100 : 0;
                            $prof_pct  = $max_pi > 0 ? (abs($m['profit']) / $max_pi) * 100 : 0;
                            $is_profit = $m['profit'] >= 0;
                            $mom_label = ''; $mom_color = '#888';
                            if ($prev_profit !== null && $m['sales'] > 0) {
                                $diff = $m['profit'] - $prev_profit;
                                if ($diff > 0)     { $mom_label = '▲ +'.formatMoney($diff); $mom_color = '#10b981'; }
                                elseif ($diff < 0) { $mom_label = '▼ '.formatMoney($diff);  $mom_color = '#dc3545'; }
                                else               { $mom_label = '→ No change';             $mom_color = '#888'; }
                            }
                            if ($m['sales'] > 0) $prev_profit = $m['profit'];
                        ?>
                        <div style="margin-bottom:13px;padding-bottom:13px;border-bottom:1px solid #f0f0f0;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                                <span style="font-size:12px;font-weight:700;color:#333;"><?= $m['short'] ?></span>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <?php if ($mom_label): ?>
                                    <span style="font-size:10px;font-weight:700;color:<?= $mom_color ?>"><?= $mom_label ?> vs prev</span>
                                    <?php endif; ?>
                                    <span style="font-size:11px;font-weight:800;color:<?= $is_profit?'#6f42c1':'#dc3545' ?>;">Net: <?= formatMoney($m['profit']) ?></span>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px;">
                                <span style="font-size:9px;color:#888;font-weight:600;width:22px;text-align:right;flex-shrink:0;">Rev</span>
                                <div style="flex:1;height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $rev_pct ?>%;background:var(--green-main);border-radius:4px;"></div>
                                </div>
                                <span style="font-size:9px;color:var(--green-main);font-weight:700;width:52px;text-align:right;flex-shrink:0;"><?= $m['sales']>0?'₱'.number_format($m['sales']/1000,1).'k':'-' ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px;">
                                <span style="font-size:9px;color:#888;font-weight:600;width:22px;text-align:right;flex-shrink:0;">Exp</span>
                                <div style="flex:1;height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $exp_pct ?>%;background:#fd7e14;border-radius:4px;"></div>
                                </div>
                                <span style="font-size:9px;color:#fd7e14;font-weight:700;width:52px;text-align:right;flex-shrink:0;"><?= $m['expenses']>0?'₱'.number_format($m['expenses']/1000,1).'k':'-' ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:7px;">
                                <span style="font-size:9px;color:#888;font-weight:600;width:22px;text-align:right;flex-shrink:0;">Pft</span>
                                <div style="flex:1;height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $prof_pct ?>%;background:<?= $is_profit?'#6f42c1':'#dc3545' ?>;border-radius:4px;"></div>
                                </div>
                                <span style="font-size:9px;color:<?= $is_profit?'#6f42c1':'#dc3545' ?>;font-weight:700;width:52px;text-align:right;flex-shrink:0;"><?= $m['profit']!=0?'₱'.number_format($m['profit']/1000,1).'k':'-' ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Fixed summary footer always visible at bottom -->
                    <div style="flex-shrink:0;border-top:2px solid var(--gray-mid);padding:10px 20px;background:var(--gray-light);">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <div style="text-align:center;">
                                <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;">Total Revenue</div>
                                <div style="font-size:12px;font-weight:800;color:var(--green-main);"><?= '₱'.number_format($year_total_sales/1000,1).'k' ?></div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;">Total Expenses</div>
                                <div style="font-size:12px;font-weight:800;color:#fd7e14;"><?= '₱'.number_format($year_total_expenses/1000,1).'k' ?></div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;">Net Profit</div>
                                <div style="font-size:12px;font-weight:800;color:<?= $year_net_profit>=0?'#6f42c1':'#dc3545' ?>;"><?= '₱'.number_format($year_net_profit/1000,1).'k' ?></div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;">Margin</div>
                                <div style="font-size:12px;font-weight:800;color:#333;"><?= $year_total_sales>0?number_format(($year_net_profit/$year_total_sales)*100,1):0 ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- Expense Report -->
            <div class="table-card" style="margin-bottom:22px;">
                <div class="table-card-header"><h3>📊 Monthly Expenses — Jan to Dec <?= $year ?></h3></div>
                <div style="padding:20px 24px 14px;">
                    <div class="bar-chart-wrap">
                        <?php
                        $max_exp = max(array_column($monthly,'expenses')) ?: 1;
                        foreach($monthly as $m):
                            $eh = max(4, round(($m['expenses']/$max_exp)*120));
                        ?>
                        <div class="bar-col">
                            <div class="bar-val"><?= $m['expenses']>0?'₱'.number_format($m['expenses']/1000,1).'k':'' ?></div>
                            <div class="bar-exp" style="height:<?= $eh ?>px;width:100%;" title="<?= $m['month'] ?>: <?= formatMoney($m['expenses']) ?>"></div>
                            <div class="bar-lbl"><?= $m['short'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="text-align:right;font-size:11px;margin-top:8px;color:var(--gray);">
                        Total Expenses <?= $year ?>: <strong style="color:#dc3545"><?= formatMoney($year_total_expenses) ?></strong>
                    </div>
                </div>
            </div>

            <div class="two-col">
                <div class="table-card">
                    <div class="table-card-header"><h3>💸 Expenses by Category — <?= $year ?></h3></div>
                    <table class="data-table">
                        <thead><tr><th>Category</th><th>Transactions</th><th>Total Amount</th><th>% of Total</th></tr></thead>
                        <tbody>
                            <?php if ($exp_by_cat): while($ec=$exp_by_cat->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight:600"><?= htmlspecialchars($ec['category']) ?></td>
                                <td><?= $ec['count'] ?></td>
                                <td style="font-weight:700;color:#dc3545"><?= formatMoney($ec['total']) ?></td>
                                <td>
                                    <?php $pct=$year_total_expenses>0?($ec['total']/$year_total_expenses)*100:0; ?>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <div style="flex:1;height:6px;background:var(--gray-light);border-radius:3px;overflow:hidden;">
                                            <div style="width:<?= $pct ?>%;height:100%;background:#fd7e14;border-radius:3px;"></div>
                                        </div>
                                        <span style="font-size:11px;font-weight:600"><?= number_format($pct,1) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align:center;color:var(--gray)">No expense data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-card">
                    <div class="table-card-header"><h3>📋 Monthly Expense Trend</h3></div>
                    <div style="padding:20px;">
                        <?php foreach($monthly as $m):
                            $max_e = max(array_column($monthly,'expenses')) ?: 1;
                            $pct   = ($m['expenses']/$max_e)*100;
                        ?>
                        <div style="margin-bottom:10px;">
                            <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:600;margin-bottom:3px;">
                                <span><?= $m['short'] ?></span>
                                <span style="color:#dc3545"><?= formatMoney($m['expenses']) ?></span>
                            </div>
                            <div style="height:8px;background:var(--gray-light);border-radius:4px;overflow:hidden;">
                                <div style="height:100%;width:<?= $pct ?>%;background:#fd7e14;border-radius:4px;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>