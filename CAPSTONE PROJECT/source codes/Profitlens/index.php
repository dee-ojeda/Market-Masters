<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, email, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = 'Invalid email or password.';
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
    <title>Login - Profit Lens System</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0d2414;
            font-family: 'DM Sans', sans-serif;
            overflow: hidden;
            position: relative;
        }

        #doodle-canvas {
            position: fixed;
            inset: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .auth-card {
            position: relative;
            z-index: 10;
            width: 420px;
            background: rgba(255,255,255,0.96);
            border-radius: 24px;
            padding: 48px 44px 40px;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.12),
                0 40px 80px rgba(0,0,0,0.55),
                0 0 120px rgba(34,139,34,0.1);
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 40px; right: 40px;
            height: 3px;
            background: linear-gradient(90deg, #1b5e20, #e53935, #1b5e20);
            border-radius: 0 0 4px 4px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(28px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .auth-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 10px;
            animation: fadeUp 0.7s 0.1s cubic-bezier(0.16,1,0.3,1) both;
        }

        .logo-icon-wrap {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 8px 24px rgba(27,94,32,0.38);
            position: relative; overflow: hidden;
        }
        .logo-icon-wrap::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.18) 0%, transparent 60%);
        }

        .auth-logo-text { text-align: center; line-height: 1; }
        .profit {
            font-family: 'Playfair Display', serif;
            font-size: 26px; color: #e53935;
            display: block; letter-spacing: -0.5px;
        }
        .lens {
            font-family: 'Playfair Display', serif;
            font-size: 26px; color: #1b5e20;
            display: block; letter-spacing: -0.5px; margin-top: -2px;
        }

        .auth-tagline {
            font-size: 12px; color: #9e9e9e;
            letter-spacing: 0.06em; text-align: center;
            margin-bottom: 28px; font-weight: 400;
            animation: fadeUp 0.7s 0.15s cubic-bezier(0.16,1,0.3,1) both;
        }

        .alert {
            border-radius: 10px; padding: 11px 16px;
            font-size: 13.5px; margin-bottom: 18px;
        }
        .alert-danger {
            background: #fff5f5; color: #c62828;
            border: 1.5px solid #ffcdd2;
        }

        .auth-field { margin-bottom: 16px; animation: fadeUp 0.7s 0.2s cubic-bezier(0.16,1,0.3,1) both; }
        .auth-field + .auth-field { animation-delay: 0.25s; }

        .auth-field label {
            display: block; font-size: 10.5px; font-weight: 500;
            letter-spacing: 0.1em; color: #616161;
            margin-bottom: 7px; text-transform: uppercase;
        }

        .input-wrap { position: relative; }

        .input-wrap .field-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px; stroke: #bdbdbd; stroke-width: 2;
            stroke-linecap: round; stroke-linejoin: round; fill: none;
            pointer-events: none; transition: stroke 0.2s; z-index: 1;
        }

        .auth-field input {
            width: 100%; padding: 13px 14px 13px 42px;
            border: 1.5px solid #e0e0e0; border-radius: 12px;
            font-family: 'DM Sans', sans-serif; font-size: 14px;
            color: #212121; background: #fafafa; outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }
        .auth-field input::placeholder { color: #bdbdbd; }
        .auth-field input:focus {
            border-color: #2e7d32; background: #fff;
            box-shadow: 0 0 0 4px rgba(46,125,50,0.08);
        }
        .auth-field input:focus ~ .field-icon { stroke: #2e7d32; }

        .eye-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 0;
            color: #bdbdbd; display: flex; align-items: center; transition: color 0.2s;
        }
        .eye-toggle:hover { color: #2e7d32; }
        .eye-toggle svg { width: 16px; height: 16px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }

        .auth-forgot-wrap {
            text-align: right; margin-top: 6px; margin-bottom: 22px;
            animation: fadeUp 0.7s 0.3s cubic-bezier(0.16,1,0.3,1) both;
        }
        .auth-link {
            font-size: 12.5px; color: #9e9e9e; text-decoration: none;
            font-weight: 400; transition: color 0.2s;
        }
        .auth-link:hover { color: #e53935; }

        .btn-auth-primary {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            color: #fff; border: none; border-radius: 12px;
            font-family: 'DM Sans', sans-serif; font-size: 15px; font-weight: 500;
            letter-spacing: 0.03em; cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 6px 20px rgba(27,94,32,0.38);
            margin-bottom: 12px;
            animation: fadeUp 0.7s 0.35s cubic-bezier(0.16,1,0.3,1) both;
        }
        .btn-auth-primary::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%);
            pointer-events: none;
        }
        .btn-auth-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(27,94,32,0.42); }
        .btn-auth-primary:active { transform: translateY(0); }

        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 4px 0 12px;
            animation: fadeUp 0.7s 0.38s cubic-bezier(0.16,1,0.3,1) both;
        }
        .divider span { font-size: 12px; color: #bdbdbd; white-space: nowrap; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e0e0e0; }

        .btn-auth-secondary {
            display: block; width: 100%; padding: 13px; background: transparent;
            color: #2e7d32; border: 1.5px solid #2e7d32; border-radius: 12px;
            font-family: 'DM Sans', sans-serif; font-size: 15px; font-weight: 500;
            letter-spacing: 0.03em; cursor: pointer; text-align: center;
            text-decoration: none; transition: background 0.2s, transform 0.15s;
            animation: fadeUp 0.7s 0.4s cubic-bezier(0.16,1,0.3,1) both;
        }
        .btn-auth-secondary:hover { background: #f1f8e9; transform: translateY(-1px); }
    </style>
</head>
<body>

    <canvas id="doodle-canvas"></canvas>

    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon-wrap">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="7" stroke="white" stroke-width="2.2" stroke-linecap="round"/>
                    <line x1="16.5" y1="16.5" x2="21" y2="21" stroke="white" stroke-width="2.2" stroke-linecap="round"/>
                    <line x1="8" y1="14" x2="8" y2="11" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    <line x1="11" y1="14" x2="11" y2="8" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    <line x1="14" y1="14" x2="14" y2="10" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="auth-logo-text">
                <span class="profit">Profit</span>
                <span class="lens">Lens</span>
            </div>
        </div>

        <p class="auth-tagline">Clarity for your bottom line</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="auth-field">
                <label for="email">Username</label>
                <div class="input-wrap">
                    <input type="email" id="email" name="email"
                           placeholder="Enter your email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    <svg class="field-icon" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="m2 7 10 7 10-7"/></svg>
                </div>
            </div>

            <div class="auth-field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Enter your password" required>
                    <svg class="field-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <button type="button" class="eye-toggle" onclick="togglePwd(this)" aria-label="Toggle password">
                        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div class="auth-forgot-wrap">
                <a href="forgot_password.php" class="auth-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn-auth-primary">Login</button>
        </form>

        <div class="divider"><span>or</span></div>
        <a href="register.php" class="btn-auth-secondary">Create an account</a>
    </div>

    <script>
    function togglePwd(btn) {
        const input = btn.closest('.input-wrap').querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
    }

    (function () {
        const canvas = document.getElementById('doodle-canvas');
        const ctx    = canvas.getContext('2d');

        const G  = 'rgba(100,200,120,0.20)';
        const GM = 'rgba(100,200,120,0.30)';
        const R  = 'rgba(229,57,53,0.16)';
        const W  = 'rgba(255,255,255,0.11)';
        const DASH = [6,6];

        function rnd(a,b){ return Math.random()*(b-a)+a; }

        function style(color, lw, dash){
            ctx.strokeStyle = color;
            ctx.lineWidth   = lw || 1.5;
            ctx.setLineDash(dash || []);
            ctx.lineCap = ctx.lineJoin = 'round';
        }

        function barChart(x, y, w, h, vals, col){
            ctx.save(); style(col, 1.5);
            ctx.beginPath();
            ctx.moveTo(x, y); ctx.lineTo(x, y+h); ctx.lineTo(x+w, y+h);
            ctx.stroke();
            const bw = (w-10)/vals.length - 4;
            vals.forEach((v,i)=>{
                const bx = x+8+i*((w-10)/vals.length);
                const bh = v*(h-10);
                ctx.beginPath(); ctx.rect(bx, y+h-bh-2, bw, bh); ctx.stroke();
            });
            ctx.restore();
        }

        function lineChart(x, y, w, h, pts, col){
            ctx.save(); style(col, 1.8);
            ctx.beginPath();
            ctx.moveTo(x, y); ctx.lineTo(x, y+h); ctx.lineTo(x+w, y+h);
            ctx.stroke();
            const xs = w/(pts.length-1);
            ctx.beginPath();
            pts.forEach((v,i)=>{
                const px=x+i*xs, py=y+h-v*(h-8)-4;
                i===0?ctx.moveTo(px,py):ctx.lineTo(px,py);
            });
            ctx.stroke();
            pts.forEach((v,i)=>{
                ctx.beginPath();
                ctx.arc(x+i*xs, y+h-v*(h-8)-4, 3, 0, Math.PI*2);
                ctx.fillStyle=col; ctx.fill();
            });
            ctx.restore();
        }

        function donut(x, y, r, col){
            ctx.save(); style(col, 1.5);
            let a = -Math.PI/2;
            [0.35,0.25,0.2,0.2].forEach(s=>{
                const e=a+s*Math.PI*2;
                ctx.beginPath();
                ctx.arc(x,y,r,a,e);
                ctx.arc(x,y,r*0.55,e,a,true);
                ctx.closePath(); ctx.stroke();
                a=e+0.06;
            });
            ctx.restore();
        }

        function trendArrow(x, y, len, up, col){
            ctx.save(); style(col, 1.8);
            const ang = up ? -Math.PI/4 : Math.PI/4;
            const ex=x+len*Math.cos(ang), ey=y+len*Math.sin(ang);
            ctx.beginPath();
            ctx.moveTo(x,y); ctx.lineTo(ex,ey);
            ctx.lineTo(ex-8*Math.cos(ang-0.45), ey-8*Math.sin(ang-0.45));
            ctx.moveTo(ex,ey);
            ctx.lineTo(ex-8*Math.cos(ang+0.45), ey-8*Math.sin(ang+0.45));
            ctx.stroke(); ctx.restore();
        }

        function kpiBox(x, y, w, h, col){
            ctx.save(); style(col, 1.3, DASH);
            ctx.beginPath(); ctx.roundRect(x,y,w,h,6); ctx.stroke();
            style(col, 1.3, []);
            [0.4,0.7,0.55,0.9,0.65].forEach((v,i)=>{
                const bx=x+8+i*(w-16)/5, bh=v*(h*0.5);
                ctx.beginPath(); ctx.rect(bx, y+h-bh-8, (w-16)/5-3, bh); ctx.stroke();
            });
            ctx.restore();
        }

        function scatter(x, y, w, h, col){
            ctx.save(); style(col, 1.3);
            ctx.beginPath();
            ctx.moveTo(x,y+h); ctx.lineTo(x,y);
            ctx.moveTo(x,y+h); ctx.lineTo(x+w,y+h);
            ctx.stroke();
            for(let i=0;i<12;i++){
                ctx.beginPath();
                ctx.arc(x+rnd(6,w-4), y+rnd(4,h-6), rnd(2,4), 0, Math.PI*2);
                ctx.stroke();
            }
            ctx.restore();
        }

        function radar(cx, cy, r, col){
            ctx.save();
            const axes=6;
            [0.4,0.7,1].forEach(sc=>{
                style(col,1.2,DASH); ctx.beginPath();
                for(let i=0;i<=axes;i++){
                    const a=(i/axes)*Math.PI*2-Math.PI/2;
                    const px=cx+r*sc*Math.cos(a), py=cy+r*sc*Math.sin(a);
                    i===0?ctx.moveTo(px,py):ctx.lineTo(px,py);
                }
                ctx.stroke();
            });
            style(col,1,[]);
            for(let i=0;i<axes;i++){
                const a=(i/axes)*Math.PI*2-Math.PI/2;
                ctx.beginPath(); ctx.moveTo(cx,cy);
                ctx.lineTo(cx+r*Math.cos(a),cy+r*Math.sin(a)); ctx.stroke();
            }
            style(col,1.5,[]);
            const vals=[0.8,0.6,0.9,0.5,0.75,0.65];
            ctx.beginPath();
            vals.forEach((v,i)=>{
                const a=(i/axes)*Math.PI*2-Math.PI/2;
                const px=cx+r*v*Math.cos(a), py=cy+r*v*Math.sin(a);
                i===0?ctx.moveTo(px,py):ctx.lineTo(px,py);
            });
            ctx.closePath(); ctx.stroke();
            ctx.restore();
        }

        function candlestick(x, y, w, h, col){
            ctx.save();
            const cols=7, cw=(w-10)/cols;
            for(let i=0;i<cols;i++){
                const cx=x+5+i*cw+cw/2;
                const open=rnd(0.2,0.8), close=rnd(0.2,0.8);
                const high=Math.max(open,close)+rnd(0.05,0.15);
                const low=Math.min(open,close)-rnd(0.05,0.15);
                const toY=v=>y+h-v*h;
                const c=close>=open?GM:'rgba(229,57,53,0.25)';
                style(c,1.2);
                ctx.beginPath(); ctx.moveTo(cx,toY(high)); ctx.lineTo(cx,toY(low)); ctx.stroke();
                const bh=Math.abs(close-open)*h;
                ctx.strokeRect(cx-cw*0.3, toY(Math.max(open,close)), cw*0.6, bh||2);
            }
            ctx.restore();
        }

        function funnel(x, y, maxW, h, steps, col){
            ctx.save(); style(col,1.5);
            const sh=h/steps;
            for(let i=0;i<steps;i++){
                const tw=maxW*(1-i*0.18), tx=x+(maxW-tw)/2;
                ctx.beginPath(); ctx.rect(tx, y+i*sh, tw, sh-4); ctx.stroke();
            }
            ctx.restore();
        }

        function heatmap(x, y, cols, rows, size, baseCol){
            ctx.save();
            for(let r=0;r<rows;r++) for(let c=0;c<cols;c++){
                ctx.strokeStyle=`rgba(100,200,120,${rnd(0.05,0.22)})`;
                ctx.lineWidth=1; ctx.setLineDash([]);
                ctx.strokeRect(x+c*(size+3), y+r*(size+3), size, size);
            }
            ctx.restore();
        }

        function draw(){
            ctx.clearRect(0,0,canvas.width,canvas.height);
            const W=canvas.width, H=canvas.height;

            barChart(55, 75, 95, 75, [0.5,0.8,0.6,0.95,0.7], G);
            lineChart(W-165, 55, 125, 85, [0.3,0.6,0.4,0.8,0.55,0.9,0.7], G);
            donut(85, H-105, 58, G);
            donut(W-92, H-92, 48, R);
            trendArrow(W-85, 195, 65, true, GM);
            trendArrow(50, H/2+65, 58, false, R);
            kpiBox(W-205, H/2-65, 135, 72, G);
            kpiBox(38, H/2-105, 115, 62, W);
            scatter(W-175, H-165, 115, 95, G);
            heatmap(W-145, 195, 5, 4, 15, '');
            funnel(28, 295, 95, 105, 4, G);
            radar(W/2+285, H/2, 72, G);
            candlestick(W/2-360, H-135, 145, 72, G);
            barChart(W/2-105, 28, 72, 52, [0.4,0.75,0.55,0.85], R);
            lineChart(W/2+58, H-115, 105, 62, [0.5,0.3,0.7,0.4,0.8], G);
            radar(115, H/2+185, 52, W);
            scatter(W/2+205, H-135, 82, 72, R);
            kpiBox(W/2-335, 98, 105, 56, R);
            trendArrow(W/2+8, 28, 52, true, W);
            /* extra filler */
            barChart(W-280, H-80, 70, 50, [0.6,0.4,0.8,0.5], W);
            lineChart(180, H-90, 90, 55, [0.7,0.4,0.6,0.9,0.5], W);
            donut(W/2-260, 70, 38, W);
        }

        function resize(){
            canvas.width=window.innerWidth;
            canvas.height=window.innerHeight;
            draw();
        }

        window.addEventListener('resize', resize);
        resize();
    })();
    </script>
</body>
</html>