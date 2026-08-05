<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <a href="dashboard.php" class="sidebar-brand">
        <div class="sidebar-brand-logo">
            <!-- Profit Lens SVG Logo -->
            <svg width="30" height="30" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="28" cy="28" r="18" stroke="#1a6b35" stroke-width="4" fill="none"/>
                <circle cx="28" cy="28" r="18" stroke="#0a4a1e" stroke-width="4" fill="none" opacity="0.3"/>
                <line x1="41" y1="41" x2="52" y2="52" stroke="#e74c3c" stroke-width="4" stroke-linecap="round"/>
                <rect x="18" y="30" width="5" height="8" fill="#1a6b35" rx="1"/>
                <rect x="25" y="24" width="5" height="14" fill="#4caf50" rx="1"/>
                <rect x="32" y="19" width="5" height="19" fill="#81c784" rx="1"/>
            </svg>
        </div>
        <div class="sidebar-brand-text">
            <div class="brand-name">PROFIT LENS</div>
            <div class="brand-sub">SMART PROFIT MONITORING</div>
        </div>
    </a>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon">⊞</span>
            <span>Dashboard</span>
        </a>
        <a href="products.php" class="nav-item <?= $current_page == 'products.php' ? 'active' : '' ?>">
            <span class="nav-icon">📦</span>
            <span>Product Management</span>
        </a>
        <a href="profit.php" class="nav-item <?= $current_page == 'profit.php' ? 'active' : '' ?>">
            <span class="nav-icon">$</span>
            <span>Profit This Month</span>
        </a>
        <a href="reports.php" class="nav-item <?= $current_page == 'reports.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span>Financial Reports</span>
        </a>
        <a href="expenses.php" class="nav-item <?= $current_page == 'expenses.php' ? 'active' : '' ?>">
            <span class="nav-icon">%</span>
            <span>Expense Tracking</span>
        </a>
        <a href="revenue.php" class="nav-item <?= $current_page == 'revenue.php' ? 'active' : '' ?>">
            <span class="nav-icon">👁</span>
            <span>Revenue Insights</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <!-- Changed from <a href="logout.php"> to a button that triggers the modal -->
        <button onclick="showLogoutModal()" class="btn-logout">
            <span>⏻</span>
            <span>Logout</span>
        </button>
    </div>
</aside>

<!-- ── Logout Confirmation Modal (lives here so it works on ALL pages) ── -->

<!-- Overlay -->
<div id="logout-overlay" onclick="hideLogoutModal()"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);
           backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
           z-index:9998;opacity:0;transition:opacity 0.25s ease;"></div>

<!-- Modal -->
<div id="logout-modal"
    style="display:none;position:fixed;top:50%;left:50%;
           transform:translate(-50%,-60%);z-index:9999;
           width:100%;max-width:420px;padding:0 16px;
           opacity:0;transition:opacity 0.25s ease, transform 0.25s ease;">
    <div style="background:#fff;border-radius:20px;padding:40px 32px 32px;
                text-align:center;box-shadow:0 24px 60px rgba(0,0,0,0.2);">

        <!-- Wave icon -->
        <div style="width:74px;height:74px;background:#fff8e1;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;
                    font-size:38px;margin:0 auto 20px;
                    border:3px solid #fde68a;box-shadow:0 4px 14px rgba(253,230,138,0.5);">
            👋
        </div>

        <h2 style="font-size:21px;font-weight:800;color:#1a1a1a;margin:0 0 10px;
                   font-family:inherit;">Leaving so soon?</h2>
        <p style="font-size:13.5px;color:#666;margin:0 0 28px;line-height:1.7;
                  font-family:inherit;">
            Are you sure you want to log out of
            <strong style="color:#1a6b35;">Profit Lens</strong>?<br>
            You'll need to sign in again to access your dashboard.
        </p>

        <div style="display:flex;gap:12px;">
            <!-- Stay Logged In -->
            <button onclick="hideLogoutModal()"
                style="flex:1;padding:13px 20px;border-radius:12px;
                       border:2px solid #e0e0e0;background:#fff;color:#333;
                       font-size:14px;font-weight:700;cursor:pointer;
                       font-family:inherit;transition:background 0.15s,border-color 0.15s;"
                onmouseover="this.style.background='#f5f5f5';this.style.borderColor='#ccc';"
                onmouseout="this.style.background='#fff';this.style.borderColor='#e0e0e0';">
                Stay Logged In
            </button>
            <!-- Yes, Log Out -->
            <a href="logout.php"
                style="flex:1;padding:13px 20px;border-radius:12px;border:none;
                       background:linear-gradient(135deg,#e53e3e,#c53030);
                       color:#fff;font-size:14px;font-weight:700;cursor:pointer;
                       font-family:inherit;text-decoration:none;
                       display:flex;align-items:center;justify-content:center;gap:8px;
                       box-shadow:0 4px 14px rgba(197,48,48,0.35);
                       transition:opacity 0.15s,box-shadow 0.15s;"
                onmouseover="this.style.opacity='0.88';this.style.boxShadow='0 6px 18px rgba(197,48,48,0.45)';"
                onmouseout="this.style.opacity='1';this.style.boxShadow='0 4px 14px rgba(197,48,48,0.35)';">
                🚪 Yes, Log Out
            </a>
        </div>
    </div>
</div>

<style>
/* Override btn-logout to look like a button, not a link */
.btn-logout {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    /* inherits all existing .btn-logout styles from style.css */
}
</style>

<script>
function showLogoutModal() {
    const overlay = document.getElementById('logout-overlay');
    const modal   = document.getElementById('logout-modal');
    overlay.style.display = 'block';
    modal.style.display   = 'block';
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            overlay.style.opacity = '1';
            modal.style.opacity   = '1';
            modal.style.transform = 'translate(-50%, -50%)';
        });
    });
}

function hideLogoutModal() {
    const overlay = document.getElementById('logout-overlay');
    const modal   = document.getElementById('logout-modal');
    overlay.style.opacity = '0';
    modal.style.opacity   = '0';
    modal.style.transform = 'translate(-50%, -60%)';
    setTimeout(() => {
        overlay.style.display = 'none';
        modal.style.display   = 'none';
    }, 260);
}

// Close with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideLogoutModal();
});
</script>