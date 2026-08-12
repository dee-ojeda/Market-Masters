<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $success = 'If this email exists, a password reset link has been sent. (Feature requires email configuration)';
        } else {
            $success = 'If this email exists, a password reset link has been sent.';
        }
        $db->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-logo">
            <svg width="70" height="70" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="28" cy="28" r="18" stroke="#1a6b35" stroke-width="4" fill="none"/>
                <line x1="41" y1="41" x2="52" y2="52" stroke="#e74c3c" stroke-width="4" stroke-linecap="round"/>
                <rect x="18" y="30" width="5" height="8" fill="#1a6b35" rx="1"/>
                <rect x="25" y="24" width="5" height="14" fill="#4caf50" rx="1"/>
                <rect x="32" y="19" width="5" height="19" fill="#81c784" rx="1"/>
            </svg>
            <div class="auth-logo-text">
                <span class="profit">Profit</span><br>
                <span class="lens">Lens</span>
            </div>
        </div>
        <h3 style="text-align:center;margin-bottom:8px;font-size:16px;">Forgot Password?</h3>
        <p style="text-align:center;color:var(--gray);font-size:12px;margin-bottom:20px;">Enter your email to receive reset instructions.</p>

        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

        <form method="POST">
            <div class="auth-field">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="ENTER YOUR EMAIL" required>
            </div>
            <button type="submit" class="btn-auth-primary">Send Reset Link</button>
        </form>
        <a href="index.php" class="auth-link">← Back to Login</a>
    </div>
</body>
</html>