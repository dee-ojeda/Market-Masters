<?php
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-card" style="text-align:center;">
        <div style="font-size:64px; margin-bottom:18px;">🚫</div>
        <div style="font-size:22px; font-weight:800; color:#dc3545; margin-bottom:10px;">Access Denied</div>
        <p style="font-size:14px; color:#6c757d; margin-bottom:28px; line-height:1.7;">
            You do not have permission to view this page.<br>
            This area is restricted to <strong>Admin</strong> accounts only.
        </p>
        <a href="logout.php" class="btn-auth-primary" style="text-decoration:none; display:block;">← Back to Login</a>
    </div>
</body>
</html>