<?php
if (isLoggedIn()) {
    redirect('/?page=dashboard');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = Security::getInstance()->sanitizeEmail(post('email'));
    $password = post('password');
    $remember = post('remember') === '1';

    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }

    if (empty($errors)) {
        $auth = new Auth();
        $result = $auth->attempt($email, $password, $remember);
        if ($result['success']) {
            $userName = $_SESSION['user_name'] ?? 'User';
            redirectWith('/?page=dashboard', 'success', 'Welcome back, ' . e($userName) . '!');
        } else {
            $errors[] = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LOKA Fleet Management System — DICT Region II</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Rajdhani:wght@300;400;500;600&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

  :root {
    --navy: #0a0e1a;
    --navy2: #0d1526;
    --blue-dark: #0c1f4a;
    --blue: #1a3a7e;
    --blue-mid: #1e4db7;
    --blue-bright: #2e6af6;
    --cyan: #00d4ff;
    --cyan2: #00f5e4;
    --gold: #f5c518;
    --gold2: #ffdc6b;
    --red: #e82333;
    --white: #eef4ff;
    --gray: #8899bb;
    --grid: rgba(46, 106, 246, 0.07);
  }

  body {
    font-family: 'Rajdhani', sans-serif;
    background: var(--navy);
    color: var(--white);
    min-height: 100vh;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
  }

  /* Animated background */
  .bg-grid {
    position: fixed; inset: 0; z-index: 0;
    background-image:
      linear-gradient(var(--grid) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size: 48px 48px;
    animation: gridMove 20s linear infinite;
  }
  @keyframes gridMove {
    0% { transform: translateY(0); }
    100% { transform: translateY(48px); }
  }

  .bg-glow-1 {
    position: fixed; top: -200px; left: -200px; width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(30,77,183,0.25) 0%, transparent 70%);
    animation: pulse 6s ease-in-out infinite alternate;
    z-index: 0;
  }
  .bg-glow-2 {
    position: fixed; bottom: -150px; right: -150px; width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(0,212,255,0.15) 0%, transparent 70%);
    animation: pulse 8s ease-in-out infinite alternate-reverse;
    z-index: 0;
  }
  .bg-glow-3 {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%);
    width: 800px; height: 400px;
    background: radial-gradient(ellipse, rgba(10,20,60,0.6) 0%, transparent 70%);
    z-index: 0;
  }
  @keyframes pulse { from { opacity: 0.6; } to { opacity: 1; } }

  /* Binary rain */
  .binary-strip {
    position: fixed; top: 0; font-family: 'Share Tech Mono', monospace;
    font-size: 11px; color: rgba(0,212,255,0.12); line-height: 1.6;
    animation: fall linear infinite; z-index: 0; user-select: none;
  }
  @keyframes fall {
    0% { transform: translateY(-100%); }
    100% { transform: translateY(100vh); }
  }

  /* Scanning line */
  .scan-line {
    position: fixed; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, rgba(0,212,255,0.5), transparent);
    z-index: 1;
    animation: scan 4s linear infinite;
  }
  @keyframes scan {
    0% { top: -2px; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { top: 100vh; opacity: 0; }
  }

  /* Main layout */
  .wrapper {
    position: relative; z-index: 10;
    display: grid;
    grid-template-columns: 1fr 480px;
    gap: 0;
    width: 100%;
    max-width: 1100px;
    min-height: 580px;
    padding: 24px;
    animation: wrapperIn 1s ease forwards;
    opacity: 0;
  }
  @keyframes wrapperIn {
    from { opacity: 0; transform: translateY(24px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Left panel — branding */
  .brand-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 48px 48px 48px 24px;
    border-right: 1px solid rgba(46,106,246,0.2);
    position: relative;
    overflow: hidden;
  }

  .brand-panel::before {
    content: '';
    position: absolute; right: -1px; top: 20%; bottom: 20%; width: 1px;
    background: linear-gradient(to bottom, transparent, var(--cyan), var(--blue-bright), transparent);
    animation: linePulse 3s ease-in-out infinite;
  }
  @keyframes linePulse { 50% { opacity: 0.3; } }

  .dict-logo-wrap {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 40px;
    animation: fadeUp 0.8s 0.3s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .logo-ring {
    width: 72px; height: 72px;
    border-radius: 50%;
    border: 2px solid var(--blue-mid);
    position: relative;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 35% 35%, #1a3a7e, #0a0e1a);
    box-shadow: 0 0 20px rgba(30,77,183,0.4), inset 0 0 15px rgba(0,212,255,0.1);
    flex-shrink: 0;
  }
  .logo-ring::after {
    content: '';
    position: absolute; inset: -4px;
    border-radius: 50%;
    border: 1px solid rgba(0,212,255,0.3);
    animation: ringRotate 8s linear infinite;
  }
  @keyframes ringRotate { to { transform: rotate(360deg); } }

  .logo-inner {
    font-family: 'Orbitron', monospace;
    font-size: 14px; font-weight: 900;
    color: var(--gold);
    letter-spacing: 1px;
  }

  .org-name {
    font-family: 'Rajdhani', sans-serif;
  }
  .org-name .line1 {
    font-size: 10px; font-weight: 500;
    color: var(--gray); letter-spacing: 2px;
    text-transform: uppercase;
  }
  .org-name .line2 {
    font-size: 16px; font-weight: 600;
    color: var(--cyan); letter-spacing: 1px;
  }

  .system-title {
    animation: fadeUp 0.8s 0.5s ease both;
  }
  .system-title .loka {
    font-family: 'Orbitron', monospace;
    font-size: 72px; font-weight: 900;
    line-height: 1;
    letter-spacing: -2px;
    background: linear-gradient(135deg, #fff 0%, var(--cyan) 50%, var(--blue-bright) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    position: relative;
    display: inline-block;
  }
  .system-title .loka::after {
    content: 'LOKA';
    position: absolute; left: 3px; top: 3px;
    font-family: 'Orbitron', monospace;
    font-size: 72px; font-weight: 900;
    background: linear-gradient(135deg, transparent, rgba(0,212,255,0.08));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    z-index: -1;
  }

  .system-title .subtitle {
    font-family: 'Rajdhani', sans-serif;
    font-size: 14px; font-weight: 500;
    color: var(--gold);
    letter-spacing: 5px;
    text-transform: uppercase;
    margin-top: 4px;
  }

  .system-desc {
    margin-top: 28px;
    font-size: 15px;
    color: var(--gray);
    line-height: 1.7;
    max-width: 360px;
    animation: fadeUp 0.8s 0.7s ease both;
  }

  .stats-row {
    display: flex; gap: 24px;
    margin-top: 40px;
    animation: fadeUp 0.8s 0.9s ease both;
  }
  .stat-item {
    display: flex; flex-direction: column;
  }
  .stat-value {
    font-family: 'Orbitron', monospace;
    font-size: 24px; font-weight: 700;
    color: var(--cyan);
  }
  .stat-label {
    font-size: 10px; font-weight: 500;
    color: var(--gray); letter-spacing: 2px;
    text-transform: uppercase;
  }
  .stat-divider {
    width: 1px; background: rgba(46,106,246,0.3);
  }

  /* Right panel — login form */
  .login-panel {
    background: rgba(10, 20, 50, 0.7);
    backdrop-filter: blur(24px);
    border: 1px solid rgba(46, 106, 246, 0.25);
    border-radius: 0 16px 16px 0;
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    overflow: hidden;
    animation: panelIn 0.8s 0.2s ease both;
  }
  @keyframes panelIn {
    from { opacity: 0; transform: translateX(30px); }
    to { opacity: 1; transform: translateX(0); }
  }

  .login-panel::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, var(--blue-bright), var(--cyan), var(--blue-bright), transparent);
    animation: shimmer 3s ease-in-out infinite;
  }
  @keyframes shimmer {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 1; }
  }

  .corner-deco {
    position: absolute;
    width: 20px; height: 20px;
    border-color: var(--cyan);
    border-style: solid;
    opacity: 0.5;
  }
  .corner-deco.tl { top: 12px; left: 12px; border-width: 1px 0 0 1px; }
  .corner-deco.br { bottom: 12px; right: 12px; border-width: 0 1px 1px 0; }

  .login-header {
    margin-bottom: 36px;
  }
  .login-header .hello {
    font-size: 11px; font-weight: 500;
    letter-spacing: 4px; text-transform: uppercase;
    color: var(--cyan); margin-bottom: 8px;
  }
  .login-header h2 {
    font-family: 'Orbitron', monospace;
    font-size: 22px; font-weight: 700;
    color: var(--white);
    letter-spacing: 1px;
  }
  .login-header p {
    font-size: 13px; color: var(--gray);
    margin-top: 6px;
  }

  .form-group {
    margin-bottom: 20px;
    position: relative;
  }
  label {
    display: block;
    font-size: 10px; font-weight: 600;
    letter-spacing: 2px; text-transform: uppercase;
    color: var(--gray);
    margin-bottom: 8px;
  }

  .input-wrap {
    position: relative;
    display: flex; align-items: center;
  }
  .input-icon {
    position: absolute; left: 14px;
    width: 16px; height: 16px;
    color: var(--gray);
    transition: color 0.3s;
  }
  input[type="text"], input[type="email"], input[type="password"] {
    width: 100%;
    background: rgba(10, 18, 40, 0.8);
    border: 1px solid rgba(46, 106, 246, 0.25);
    border-radius: 6px;
    color: var(--white);
    font-family: 'Rajdhani', sans-serif;
    font-size: 15px; font-weight: 500;
    padding: 13px 14px 13px 42px;
    outline: none;
    transition: all 0.3s;
    letter-spacing: 0.5px;
  }
  input::placeholder { color: rgba(136, 153, 187, 0.45); }
  input:focus {
    border-color: var(--blue-bright);
    background: rgba(14, 26, 60, 0.9);
    box-shadow: 0 0 0 3px rgba(46,106,246,0.1), 0 0 20px rgba(0,212,255,0.06);
  }
  input:focus + .focus-line { width: 100%; }
  .input-wrap:focus-within .input-icon { color: var(--cyan); }

  .focus-line {
    position: absolute; bottom: -1px; left: 0;
    height: 2px; width: 0;
    background: linear-gradient(90deg, var(--blue-bright), var(--cyan));
    transition: width 0.4s ease;
    border-radius: 2px;
  }

  .eye-toggle {
    position: absolute; right: 14px;
    background: none; border: none; cursor: pointer;
    color: var(--gray); transition: color 0.3s;
    padding: 0; display: flex;
  }
  .eye-toggle:hover { color: var(--cyan); }

  .form-footer {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 28px; margin-top: -4px;
  }
  .remember-wrap {
    display: flex; align-items: center; gap: 8px; cursor: pointer;
  }
  .remember-wrap input[type="checkbox"] { display: none; }
  .check-box {
    width: 16px; height: 16px;
    border: 1px solid rgba(46,106,246,0.4);
    border-radius: 3px;
    background: rgba(10,18,40,0.8);
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
    position: relative;
  }
  .remember-wrap input:checked ~ .check-box {
    background: var(--blue-bright);
    border-color: var(--blue-bright);
  }
  .check-box svg { display: none; }
  .remember-wrap input:checked ~ .check-box svg { display: block; }
  .remember-label { font-size: 12px; color: var(--gray); letter-spacing: 0.5px; }

  .forgot-link {
    font-size: 12px; color: var(--cyan);
    text-decoration: none; letter-spacing: 0.5px;
    transition: opacity 0.2s;
  }
  .forgot-link:hover { opacity: 0.7; }

  .btn-login {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg, var(--blue-mid), var(--blue-bright));
    border: none; border-radius: 6px;
    color: #fff;
    font-family: 'Orbitron', monospace;
    font-size: 13px; font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
    cursor: pointer;
    position: relative; overflow: hidden;
    transition: all 0.3s;
    box-shadow: 0 4px 24px rgba(30,77,183,0.4);
  }
  .btn-login::before {
    content: '';
    position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent);
    transition: left 0.5s;
  }
  .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 32px rgba(30,77,183,0.5); }
  .btn-login:hover::before { left: 100%; }
  .btn-login:active { transform: translateY(0); }

  .divider {
    display: flex; align-items: center; gap: 12px;
    margin: 20px 0;
  }
  .divider-line { flex: 1; height: 1px; background: rgba(46,106,246,0.15); }
  .divider-text { font-size: 10px; color: var(--gray); letter-spacing: 2px; }

  .system-info {
    display: flex; justify-content: space-between;
    margin-top: 20px;
  }
  .sys-badge {
    display: flex; align-items: center; gap: 6px;
    font-family: 'Share Tech Mono', monospace;
    font-size: 10px; color: var(--gray);
  }
  .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--cyan);
    box-shadow: 0 0 6px var(--cyan);
    animation: blink 2s ease-in-out infinite;
  }
  .dot.red { background: var(--red); box-shadow: 0 0 6px var(--red); animation-delay: 1s; }
  @keyframes blink { 50% { opacity: 0.3; } }

  /* Status bar bottom */
  .status-bar {
    position: fixed; bottom: 0; left: 0; right: 0;
    padding: 8px 24px;
    background: rgba(5, 10, 24, 0.9);
    border-top: 1px solid rgba(46,106,246,0.15);
    display: flex; justify-content: space-between; align-items: center;
    z-index: 20;
    font-family: 'Share Tech Mono', monospace; font-size: 10px;
    color: rgba(136,153,187,0.6);
    letter-spacing: 1px;
  }
  .status-bar .status-left { display: flex; gap: 24px; }
  .status-highlight { color: var(--cyan); }

  /* Error state */
  .error-msg {
    display: none;
    font-size: 11px; color: #ff6b7a;
    margin-top: 6px; letter-spacing: 0.5px;
    padding: 8px 12px;
    background: rgba(232,35,51,0.1);
    border: 1px solid rgba(232,35,51,0.2);
    border-radius: 4px;
    font-family: 'Share Tech Mono', monospace;
  }

  /* Responsive */
  @media (max-width: 768px) {
    .wrapper { grid-template-columns: 1fr; max-width: 480px; }
    .brand-panel { display: none; }
    .login-panel { border-radius: 16px; }
  }
</style>
</head>
<body>

<!-- Background elements -->
<div class="bg-grid"></div>
<div class="bg-glow-1"></div>
<div class="bg-glow-2"></div>
<div class="bg-glow-3"></div>
<div class="scan-line"></div>

<!-- Binary strips -->
<script>
  const strips = ['01001001','01000011','01010100','01000100','01001001','01000011','01010100'];
  strips.forEach((txt, i) => {
    const el = document.createElement('div');
    el.className = 'binary-strip';
    el.style.left = (5 + i * 13) + '%';
    el.style.animationDuration = (15 + i * 3) + 's';
    el.style.animationDelay = (-i * 2) + 's';
    el.textContent = Array(40).fill(txt).join('\n');
    document.body.appendChild(el);
  });
</script>

<div class="wrapper">
  <!-- Brand Panel -->
  <div class="brand-panel">
    <div class="dict-logo-wrap">
      <div class="logo-ring">
        <div class="logo-inner">DICT</div>
      </div>
      <div class="org-name">
        <div class="line1">Department of Information &</div>
        <div class="line2">Communications Technology</div>
        <div class="line1" style="color: var(--gold); margin-top:3px;">Regional Office II • Cagayan Valley</div>
      </div>
    </div>

    <div class="system-title">
      <div class="loka">LOKA</div>
      <div class="subtitle">Fleet Management System</div>
    </div>

    <div class="system-desc">
      Centralized vehicle tracking and fleet operations platform for government mobility management across Region II.
    </div>

    <div class="stats-row">
      <div class="stat-item">
        <span class="stat-value">24/7</span>
        <span class="stat-label">Support</span>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <span class="stat-value">256-bit</span>
        <span class="stat-label">Encryption</span>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <span class="stat-value">99.9%</span>
        <span class="stat-label">Uptime</span>
      </div>
    </div>
  </div>

  <!-- Login Panel -->
  <div class="login-panel">
    <div class="corner-deco tl"></div>
    <div class="corner-deco br"></div>

    <div class="login-header">
      <div class="hello">Secure Access Portal</div>
      <h2>SYSTEM LOGIN</h2>
      <p>Enter your credentials to access LOKA FMS</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="error-msg" style="display: block;">
      <?php foreach ($errors as $error): ?>
      ⚠ <?= e($error) ?><br>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form id="loginForm" method="POST" novalidate>
      <?= csrfField() ?>
      <div class="form-group">
        <label>Email Address</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <path d="M22 4l-10 8L2 4"/>
          </svg>
          <input type="email" name="email" id="email" placeholder="name@dict.gov.ph" autocomplete="email" value="<?= e(post('email', '')) ?>" <?= empty($errors) ? 'autofocus' : '' ?>>
          <div class="focus-line"></div>
        </div>
      </div>

      <div class="form-group">
        <label>Password</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          <input type="password" name="password" id="password" placeholder="Enter your password" autocomplete="current-password" <?= !empty($errors) ? 'autofocus' : '' ?>>
          <div class="focus-line"></div>
          <button type="button" class="eye-toggle" onclick="togglePwd()" id="eyeBtn">
            <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="form-footer">
        <label class="remember-wrap">
          <input type="checkbox" name="remember" value="1" id="remember">
          <div class="check-box">
            <svg width="10" height="8" viewBox="0 0 10 8" fill="none">
              <path d="M1 4l3 3 5-6" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <span class="remember-label">Remember me</span>
        </label>
        <a href="?page=forgot-password" class="forgot-link">Forgot password?</a>
      </div>

      <button class="btn-login" type="submit" id="loginBtn">
        <span id="btnText">ACCESS SYSTEM</span>
      </button>
    </form>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">SYSTEM STATUS</div>
      <div class="divider-line"></div>
    </div>

    <div class="system-info">
      <div class="sys-badge"><div class="dot"></div> SERVER ONLINE</div>
      <div class="sys-badge"><div class="dot"></div> ENCRYPTED</div>
      <div class="sys-badge"><div class="dot red"></div> v<?= APP_VERSION ?></div>
    </div>
  </div>
</div>

<!-- Status Bar -->
<div class="status-bar">
  <div class="status-left">
    <span>LOKA FMS <span class="status-highlight">v<?= APP_VERSION ?></span></span>
    <span>DICT-RO2 <span class="status-highlight">REGION II</span></span>
  </div>
  <span id="clock" class="status-highlight"></span>
</div>

<script>
  // Clock
  function updateClock() {
    const now = new Date();
    document.getElementById('clock').textContent =
      now.toLocaleDateString('en-PH', { weekday:'short', year:'numeric', month:'short', day:'numeric' }) +
      '  ' + now.toLocaleTimeString('en-PH');
  }
  updateClock(); setInterval(updateClock, 1000);

  // Password toggle
  function togglePwd() {
    const pwd = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
      pwd.type = 'text';
      icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2"/>';
    } else {
      pwd.type = 'password';
      icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
  }

  // Form submit - show loading
  document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('loginBtn');
    const btnText = document.getElementById('btnText');
    btn.disabled = true;
    btnText.textContent = 'AUTHENTICATING...';
  });
</script>
</body>
</html>
