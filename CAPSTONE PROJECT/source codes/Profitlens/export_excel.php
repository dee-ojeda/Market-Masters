<?php
require_once 'includes/config.php';
requireAdmin();
 
$type = $_GET['type'] ?? 'profit';
$year = intval($_GET['year'] ?? date('Y'));
$month = $_GET['month'] ?? date('Y-m');
 
$db = getDB();
 
// ── Helper: send headers and build a simple XLSX via CSV-in-Excel workaround
// We use PHP's built-in output to stream a proper XLSX via PhpSpreadsheet-free method.
// We output an HTML table with Excel-compatible MIME type for maximum compatibility.
 
function xlsHeader($filename) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    // BOM for UTF-8
    echo "\xEF\xBB\xBF";
}
 
function xlsCell($val) {
    $val = str_replace('"', '""', $val);
    return '"' . $val . '"';
}
 
$month_labels = ['January','February','March','April','May','June',
                 'July','August','September','October','November','December'];
 
// ══════════════════════════════════════════
// PROFIT & LOSS REPORT
// ══════════════════════════════════════════
if ($type === 'profit_loss') {
    $monthly = [];
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', $year, $m);
        $s  = $db->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym'")->fetch_assoc();
        $e  = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$ym'")->fetch_assoc();
        $sv = floatval($s['t']); $ev = floatval($e['t']);
        $monthly[] = ['month'=>$month_labels[$m-1],'sales'=>$sv,'expenses'=>$ev,'profit'=>$sv-$ev];
    }
    $ts = array_sum(array_column($monthly,'sales'));
    $te = array_sum(array_column($monthly,'expenses'));
    $tp = $ts - $te;
 
    xlsHeader("ProfitLens_Profit_Loss_$year");
    echo "<table border='1'>";
    echo "<tr><td colspan='4' style='font-size:16pt;font-weight:bold;background:#1a6b35;color:white;'>PROFIT &amp; LOSS STATEMENT — $year</td></tr>";
    echo "<tr><td colspan='4' style='background:#f8f9fa;'>Generated: " . date('F d, Y H:i') . "</td></tr>";
    echo "<tr></tr>";
    echo "<tr style='background:#1a6b35;color:white;font-weight:bold;'>";
    echo "<td>Month</td><td>Revenue (₱)</td><td>Expenses (₱)</td><td>Net Profit (₱)</td>";
    echo "</tr>";
    foreach ($monthly as $r) {
        $pColor = $r['profit'] >= 0 ? 'color:green;' : 'color:red;';
        echo "<tr>";
        echo "<td>" . $r['month'] . "</td>";
        echo "<td style='text-align:right;'>" . number_format($r['sales'],2) . "</td>";
        echo "<td style='text-align:right;color:red;'>" . number_format($r['expenses'],2) . "</td>";
        echo "<td style='text-align:right;$pColor font-weight:bold;'>" . number_format($r['profit'],2) . "</td>";
        echo "</tr>";
    }
    echo "<tr style='font-weight:bold;background:#e9ecef;'>";
    echo "<td>TOTAL</td>";
    echo "<td style='text-align:right;'>" . number_format($ts,2) . "</td>";
    echo "<td style='text-align:right;color:red;'>" . number_format($te,2) . "</td>";
    $tc = $tp >= 0 ? 'color:green;' : 'color:red;';
    echo "<td style='text-align:right;$tc font-weight:bold;font-size:13pt;'>" . number_format($tp,2) . "</td>";
    echo "</tr>";
    echo "<tr></tr>";
    $margin = $ts > 0 ? number_format(($tp/$ts)*100,1).'%' : '0%';
    echo "<tr><td colspan='3'>Profit Margin</td><td style='text-align:right;font-weight:bold;'>$margin</td></tr>";
    echo "</table>";
}
 
// ══════════════════════════════════════════
// EXPENSE REPORT
// ══════════════════════════════════════════
elseif ($type === 'expense') {
    $expenses = $db->query("SELECT category, description, amount, expense_date FROM expenses WHERE YEAR(expense_date)='$year' ORDER BY expense_date DESC");
    $by_cat   = $db->query("SELECT category, SUM(amount) as total, COUNT(*) as cnt FROM expenses WHERE YEAR(expense_date)='$year' GROUP BY category ORDER BY total DESC");
    $total_exp = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE YEAR(expense_date)='$year'")->fetch_assoc()['t'];
 
    xlsHeader("ProfitLens_Expense_Report_$year");
    echo "<table border='1'>";
    echo "<tr><td colspan='4' style='font-size:16pt;font-weight:bold;background:#fd7e14;color:white;'>EXPENSE REPORT — $year</td></tr>";
    echo "<tr><td colspan='4' style='background:#f8f9fa;'>Generated: " . date('F d, Y H:i') . " &nbsp;|&nbsp; Total: ₱" . number_format($total_exp,2) . "</td></tr>";
    echo "<tr></tr>";
 
    // Summary by category
    echo "<tr style='background:#fd7e14;color:white;font-weight:bold;'>";
    echo "<td colspan='4'>EXPENSE SUMMARY BY CATEGORY</td></tr>";
    echo "<tr style='background:#fff3e0;font-weight:bold;'><td>Category</td><td>Transactions</td><td>Total (₱)</td><td>% of Total</td></tr>";
    if ($by_cat) while ($r = $by_cat->fetch_assoc()) {
        $pct = $total_exp > 0 ? number_format(($r['total']/$total_exp)*100,1).'%' : '0%';
        echo "<tr><td>".$r['category']."</td><td style='text-align:center;'>".$r['cnt']."</td><td style='text-align:right;color:red;'>".number_format($r['total'],2)."</td><td style='text-align:right;'>".$pct."</td></tr>";
    }
    echo "<tr style='font-weight:bold;background:#e9ecef;'><td>TOTAL</td><td></td><td style='text-align:right;color:red;'>".number_format($total_exp,2)."</td><td>100%</td></tr>";
 
    echo "<tr></tr><tr></tr>";
 
    // All transactions
    echo "<tr style='background:#fd7e14;color:white;font-weight:bold;'><td colspan='4'>ALL TRANSACTIONS</td></tr>";
    echo "<tr style='background:#fff3e0;font-weight:bold;'><td>Date</td><td>Category</td><td>Description</td><td>Amount (₱)</td></tr>";
    if ($expenses) while ($r = $expenses->fetch_assoc()) {
        echo "<tr>";
        echo "<td>".date('M d, Y', strtotime($r['expense_date']))."</td>";
        echo "<td>".$r['category']."</td>";
        echo "<td>".htmlspecialchars($r['description'])."</td>";
        echo "<td style='text-align:right;color:red;'>".number_format($r['amount'],2)."</td>";
        echo "</tr>";
    }
    echo "</table>";
}
 
// ══════════════════════════════════════════
// SALES / PROFIT THIS MONTH REPORT
// ══════════════════════════════════════════
elseif ($type === 'sales') {
    $sales = $db->query("SELECT s.*, p.name as product_name FROM sales s LEFT JOIN products p ON s.product_id=p.id WHERE YEAR(s.sale_date)='$year' ORDER BY s.sale_date DESC");
    $total_sales = $db->query("SELECT COALESCE(SUM(total),0) as t, COUNT(*) as c FROM sales WHERE YEAR(sale_date)='$year'")->fetch_assoc();
    $total_exp   = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE YEAR(expense_date)='$year'")->fetch_assoc()['t'];
    $net         = floatval($total_sales['t']) - floatval($total_exp);
 
    xlsHeader("ProfitLens_Sales_Report_$year");
    echo "<table border='1'>";
    echo "<tr><td colspan='6' style='font-size:16pt;font-weight:bold;background:#1a6b35;color:white;'>SALES REPORT — $year</td></tr>";
    echo "<tr><td colspan='6' style='background:#f8f9fa;'>Generated: ".date('F d, Y H:i')." &nbsp;|&nbsp; Total Sales: ₱".number_format($total_sales['t'],2)." &nbsp;|&nbsp; Transactions: ".$total_sales['c']."</td></tr>";
    echo "<tr></tr>";
 
    // Summary
    echo "<tr style='background:#d4edda;font-weight:bold;'><td colspan='2'>Total Revenue</td><td colspan='4' style='color:green;'>₱".number_format($total_sales['t'],2)."</td></tr>";
    echo "<tr style='background:#f8d7da;font-weight:bold;'><td colspan='2'>Total Expenses</td><td colspan='4' style='color:red;'>₱".number_format($total_exp,2)."</td></tr>";
    $nc = $net >= 0 ? 'color:green;' : 'color:red;';
    echo "<tr style='background:#e9ecef;font-weight:bold;'><td colspan='2'>Net Profit</td><td colspan='4' style='$nc font-size:13pt;'>₱".number_format($net,2)."</td></tr>";
    echo "<tr></tr>";
 
    // Transactions
    echo "<tr style='background:#1a6b35;color:white;font-weight:bold;'>";
    echo "<td>#</td><td>Date</td><td>Product</td><td>Qty</td><td>Unit Price (₱)</td><td>Total (₱)</td></tr>";
    $i = 1;
    if ($sales) while ($r = $sales->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='text-align:center;'>".$i++."</td>";
        echo "<td>".date('M d, Y', strtotime($r['sale_date']))."</td>";
        echo "<td style='font-weight:bold;'>".htmlspecialchars($r['product_name']??'N/A')."</td>";
        echo "<td style='text-align:center;'>".$r['quantity']."</td>";
        echo "<td style='text-align:right;'>".number_format($r['unit_price'],2)."</td>";
        echo "<td style='text-align:right;color:green;font-weight:bold;'>".number_format($r['total'],2)."</td>";
        echo "</tr>";
    }
    echo "</table>";
}
 
// ══════════════════════════════════════════
// REVENUE INSIGHTS REPORT
// ══════════════════════════════════════════
elseif ($type === 'revenue') {
    $monthly = [];
    $month_short = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    for ($m = 1; $m <= 12; $m++) {
        $ym  = sprintf('%04d-%02d', $year, $m);
        $r   = $db->query("SELECT COALESCE(SUM(total),0) as t, COUNT(*) as c FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')='$ym'")->fetch_assoc();
        $monthly[] = ['month'=>$month_labels[$m-1],'short'=>$month_short[$m-1],'revenue'=>floatval($r['t']),'txn'=>intval($r['c'])];
    }
    $top = $db->query("SELECT p.name, p.category, SUM(s.total) as revenue, SUM(s.quantity) as units FROM sales s JOIN products p ON s.product_id=p.id WHERE YEAR(s.sale_date)='$year' GROUP BY p.id ORDER BY revenue DESC LIMIT 10");
    $annual = array_sum(array_column($monthly,'revenue'));
    $ann_exp = floatval($db->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE YEAR(expense_date)='$year'")->fetch_assoc()['t']);
    $ann_profit = $annual - $ann_exp;
 
    xlsHeader("ProfitLens_Revenue_Insights_$year");
    echo "<table border='1'>";
    echo "<tr><td colspan='4' style='font-size:16pt;font-weight:bold;background:#9c27b0;color:white;'>REVENUE INSIGHTS — $year</td></tr>";
    echo "<tr><td colspan='4' style='background:#f8f9fa;'>Generated: ".date('F d, Y H:i')."</td></tr>";
    echo "<tr></tr>";
 
    // KPIs
    echo "<tr style='background:#f3e5f5;font-weight:bold;'><td colspan='4'>KEY PERFORMANCE INDICATORS</td></tr>";
    echo "<tr><td>Total Revenue</td><td style='color:purple;font-weight:bold;'>₱".number_format($annual,2)."</td><td>Total Expenses</td><td style='color:red;'>₱".number_format($ann_exp,2)."</td></tr>";
    $pc = $ann_profit>=0?'color:green;':'color:red;';
    echo "<tr><td>Net Profit</td><td style='$pc font-weight:bold;'>₱".number_format($ann_profit,2)."</td><td>Profit Margin</td><td style='font-weight:bold;'>".($annual>0?number_format(($ann_profit/$annual)*100,1).'%':'0%')."</td></tr>";
    echo "<tr></tr>";
 
    // Monthly breakdown
    echo "<tr style='background:#9c27b0;color:white;font-weight:bold;'><td colspan='4'>MONTHLY REVENUE BREAKDOWN</td></tr>";
    echo "<tr style='background:#f3e5f5;font-weight:bold;'><td>Month</td><td>Revenue (₱)</td><td>Transactions</td><td>Avg per Sale (₱)</td></tr>";
    foreach ($monthly as $r) {
        $avg = $r['txn'] > 0 ? number_format($r['revenue']/$r['txn'],2) : '—';
        echo "<tr><td>".$r['month']."</td><td style='text-align:right;color:purple;'>".number_format($r['revenue'],2)."</td><td style='text-align:center;'>".$r['txn']."</td><td style='text-align:right;'>".$avg."</td></tr>";
    }
    echo "<tr style='font-weight:bold;background:#e9ecef;'><td>TOTAL</td><td style='text-align:right;color:purple;'>".number_format($annual,2)."</td><td style='text-align:center;'>".array_sum(array_column($monthly,'txn'))."</td><td></td></tr>";
 
    echo "<tr></tr><tr></tr>";
 
    // Top products
    echo "<tr style='background:#9c27b0;color:white;font-weight:bold;'><td colspan='4'>TOP PRODUCTS BY REVENUE</td></tr>";
    echo "<tr style='background:#f3e5f5;font-weight:bold;'><td>Rank</td><td>Product</td><td>Revenue (₱)</td><td>Units Sold</td></tr>";
    $rank = 1;
    if ($top) while ($r = $top->fetch_assoc()) {
        echo "<tr><td style='text-align:center;'>".$rank++."</td><td style='font-weight:bold;'>".htmlspecialchars($r['name'])."</td><td style='text-align:right;color:purple;'>".number_format($r['revenue'],2)."</td><td style='text-align:center;'>".number_format($r['units'])."</td></tr>";
    }
    echo "</table>";
}
 
// ══════════════════════════════════════════
// PRODUCTS REPORT
// ══════════════════════════════════════════
elseif ($type === 'products') {
    $products = $db->query("SELECT * FROM products ORDER BY category, name");
    $stats    = $db->query("SELECT COUNT(*) as total, SUM(stock) as stock, AVG(price) as avg_price, SUM(stock*price) as inventory_val FROM products")->fetch_assoc();
 
    xlsHeader("ProfitLens_Products_Report");
    echo "<table border='1'>";
    echo "<tr><td colspan='6' style='font-size:16pt;font-weight:bold;background:#4285f4;color:white;'>PRODUCT INVENTORY REPORT</td></tr>";
    echo "<tr><td colspan='6' style='background:#f8f9fa;'>Generated: ".date('F d, Y H:i')."</td></tr>";
    echo "<tr></tr>";
    echo "<tr style='background:#e8f0fe;font-weight:bold;'><td>Total Products</td><td>".$stats['total']."</td><td>Total Stock</td><td>".number_format($stats['stock'])."</td><td>Inventory Value</td><td style='color:#4285f4;'>₱".number_format($stats['inventory_val'],2)."</td></tr>";
    echo "<tr></tr>";
    echo "<tr style='background:#4285f4;color:white;font-weight:bold;'><td>Product Name</td><td>Category</td><td>Selling Price (₱)</td><td>Cost Price (₱)</td><td>Stock</td><td>Stock Status</td></tr>";
    if ($products) while ($r = $products->fetch_assoc()) {
        $status = $r['stock'] < 10 ? 'Low Stock' : 'In Stock';
        $sc     = $r['stock'] < 10 ? 'color:red;' : 'color:green;';
        echo "<tr>";
        echo "<td style='font-weight:bold;'>".htmlspecialchars($r['name'])."</td>";
        echo "<td>".htmlspecialchars($r['category'])."</td>";
        echo "<td style='text-align:right;'>".number_format($r['price'],2)."</td>";
        echo "<td style='text-align:right;'>".number_format($r['cost'],2)."</td>";
        echo "<td style='text-align:center;'>".$r['stock']."</td>";
        echo "<td style='$sc font-weight:bold;'>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}
 
$db->close();
?>