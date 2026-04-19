<?php
session_start();
// Already logged in? Redirect to app
if (!empty($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

// Check for custom login background
$loginBg = null;
foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
    if (file_exists(__DIR__ . "/img/login-bg.$ext")) {
        $loginBg = "img/login-bg.$ext";
        break;
    }
}

// Check for reset token in URL
$resetToken = isset($_GET['reset']) ? htmlspecialchars($_GET['reset']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Jason AI</title>
    <link rel="icon" type="image/png" href="img/fav%20icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css">

<style id="loginAssemblyFx">
/* ========== Transformers-style assembly (4.5s total) ========== */
/* Initial hidden state for every piece we animate in. */
.login-card,
.login-logo,
.login-tagline,
.login-form .login-field:nth-of-type(1) > label,
.login-form .login-field:nth-of-type(1) > input,
.login-form .login-field:nth-of-type(2) > label,
.login-form .login-field:nth-of-type(2) .login-field-password > input,
.login-form .login-field:nth-of-type(2) .login-password-toggle,
.login-form .login-btn,
.login-form .login-links,
.login-footer {
    opacity: 0;
    will-change: opacity, transform, clip-path;
}

/* 1. Panel assembly — clip-path letterbox opens + scale + blur-in */
.login-card {
    transform: scale(0.92) translateY(-18px);
    clip-path: inset(50% 10% 50% 10% round 14px);
    filter: blur(8px);
    animation: loginCardAssemble 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0s forwards;
    position: relative;
}
/* Scanning light streak during assembly */
.login-card::before {
    content: '';
    position: absolute;
    top: 0; left: -30%;
    width: 30%; height: 100%;
    background: linear-gradient(90deg,
        transparent 0%,
        rgba(100, 180, 255, 0) 10%,
        rgba(140, 210, 255, 0.55) 50%,
        rgba(100, 180, 255, 0) 90%,
        transparent 100%);
    pointer-events: none;
    z-index: 4;
    opacity: 0;
    animation: loginCardScan 0.9s cubic-bezier(0.4, 0, 0.2, 1) 0s forwards;
    border-radius: 14px;
}
@keyframes loginCardAssemble {
    0%   { opacity: 0; transform: scale(0.92) translateY(-18px); clip-path: inset(50% 10% 50% 10% round 14px); filter: blur(8px); }
    45%  { opacity: 1; clip-path: inset(0 0 0 0 round 14px); filter: blur(0); }
    100% { opacity: 1; transform: scale(1) translateY(0); clip-path: inset(0 0 0 0 round 14px); filter: blur(0); }
}
@keyframes loginCardScan {
    0%   { opacity: 0; left: -40%; }
    50%  { opacity: 1; }
    100% { opacity: 0; left: 120%; }
}

/* 2. Logo drops from above with a tiny settle */
.login-logo {
    transform: translateY(-30px) scale(0.75) rotate(-4deg);
    animation: loginLogoDrop 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) 0.9s forwards;
    position: relative;
}
/* Shockwave ripple from logo impact */
.login-logo::after {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    width: 8px; height: 8px;
    border-radius: 50%;
    border: 2px solid rgba(140, 200, 255, 0.75);
    transform: translate(-50%, -50%) scale(0.1);
    opacity: 0;
    pointer-events: none;
    animation: loginLogoRipple 0.9s cubic-bezier(0.16, 1, 0.3, 1) 1.35s forwards;
}
@keyframes loginLogoDrop {
    0%   { opacity: 0; transform: translateY(-30px) scale(0.75) rotate(-4deg); }
    70%  { opacity: 1; transform: translateY(6px) scale(1.04) rotate(1deg); }
    100% { opacity: 1; transform: translateY(0) scale(1) rotate(0); }
}
@keyframes loginLogoRipple {
    0%   { opacity: 0.9; transform: translate(-50%, -50%) scale(0.2); border-width: 3px; }
    100% { opacity: 0; transform: translate(-50%, -50%) scale(22); border-width: 0.5px; }
}

/* 3. Tagline sweeps left-to-right */
.login-tagline {
    clip-path: inset(0 100% 0 0);
    animation: loginTaglineSweep 0.6s cubic-bezier(0.22, 1, 0.36, 1) 1.35s forwards;
}
@keyframes loginTaglineSweep {
    0%   { opacity: 0; clip-path: inset(0 100% 0 0); }
    20%  { opacity: 1; }
    100% { opacity: 1; clip-path: inset(0 0 0 0); }
}

/* 4. EMAIL label — fade + slide from left */
.login-form .login-field:nth-of-type(1) > label {
    transform: translateX(-14px);
    animation: loginFadeSlideX 0.4s ease 1.75s forwards;
}
/* 5. Email input — width grow + shine sweep */
.login-form .login-field:nth-of-type(1) > input {
    transform: scaleX(0.4);
    transform-origin: left center;
    animation: loginInputGrow 0.45s cubic-bezier(0.22, 1, 0.36, 1) 2.05s forwards;
}

/* 6. PASSWORD label */
.login-form .login-field:nth-of-type(2) > label {
    transform: translateX(-14px);
    animation: loginFadeSlideX 0.4s ease 2.5s forwards;
}
/* 7. Password input */
.login-form .login-field:nth-of-type(2) .login-field-password > input {
    transform: scaleX(0.4);
    transform-origin: left center;
    animation: loginInputGrow 0.45s cubic-bezier(0.22, 1, 0.36, 1) 2.8s forwards;
}
/* 8. Eyeball toggle pops in with bounce */
.login-form .login-field:nth-of-type(2) .login-password-toggle {
    transform: scale(0.1) rotate(-90deg);
    animation: loginEyePop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 3.1s forwards;
}

@keyframes loginFadeSlideX {
    to { opacity: 1; transform: translateX(0); }
}
@keyframes loginInputGrow {
    0%   { opacity: 0; transform: scaleX(0.4); }
    40%  { opacity: 1; }
    100% { opacity: 1; transform: scaleX(1); }
}
@keyframes loginEyePop {
    0%   { opacity: 0; transform: scale(0.1) rotate(-90deg); }
    70%  { opacity: 1; transform: scale(1.15) rotate(8deg); }
    100% { opacity: 1; transform: scale(1) rotate(0); }
}

/* 9. Sign In button — scales in + brand shine sweep */
.login-form .login-btn {
    transform: scale(0.5) translateY(14px);
    animation: loginBtnIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) 3.35s forwards;
    position: relative;
    overflow: hidden;
}
.login-form .login-btn::after {
    content: '';
    position: absolute;
    top: 0; left: -120%;
    width: 80%; height: 100%;
    background: linear-gradient(100deg,
        transparent 0%,
        rgba(255,255,255,0.35) 50%,
        transparent 100%);
    pointer-events: none;
    animation: loginBtnShine 0.8s ease 3.85s forwards;
}
@keyframes loginBtnIn {
    0%   { opacity: 0; transform: scale(0.5) translateY(14px); }
    70%  { opacity: 1; transform: scale(1.04) translateY(-2px); }
    100% { opacity: 1; transform: scale(1) translateY(0); }
}
@keyframes loginBtnShine {
    0%   { left: -120%; }
    100% { left: 120%; }
}

/* 10. Forgot password link */
.login-form .login-links {
    transform: translateY(8px);
    animation: loginFadeUp 0.45s ease 3.85s forwards;
}
/* 11. Developed by Jason Hogan — LAST */
.login-footer {
    transform: translateY(8px);
    animation: loginFadeUp 0.55s ease 4.1s forwards;
}
@keyframes loginFadeUp {
    to { opacity: 1; transform: translateY(0); }
}

/* If the user has a slow machine / prefers reduced motion, skip all of this */
@media (prefers-reduced-motion: reduce) {
    .login-card, .login-logo, .login-tagline,
    .login-form .login-field > label,
    .login-form .login-field > input,
    .login-form .login-field-password > input,
    .login-form .login-password-toggle,
    .login-form .login-btn,
    .login-form .login-links,
    .login-footer {
        opacity: 1 !important;
        transform: none !important;
        clip-path: none !important;
        animation: none !important;
        filter: none !important;
    }
    .login-card::before, .login-logo::after, .login-form .login-btn::after {
        display: none !important;
    }
}
</style>
</head>
<body class="login-page">

    <!-- Background -->
    <div class="login-bg">
        <?php if ($loginBg): ?>
            <div class="login-bg-image" style="background-image: url('<?= $loginBg ?>?t=<?= filemtime(__DIR__ . '/' . $loginBg) ?>')"></div>
            <div class="login-bg-overlay"></div>
        <?php else: ?>
            <div class="login-bg-gradient"></div>
        <?php endif; ?>
        <div class="login-bg-orb"></div>
        <div class="login-bg-orb"></div>
        <div class="login-bg-orb"></div>
    </div>

    <!-- Animation Layer (between bg and card) -->
    <canvas id="loginAnimationCanvas"></canvas>

    <!-- Login Card -->
    <div class="login-container">
        <div class="login-card">
            <div class="login-card-header">
                <div class="login-logo">
                    <?php $loginLogo = file_exists(__DIR__ . '/img/custom-logo.png') ? 'img/custom-logo.png' : 'img/logo.png'; ?>
                    <img id="loginLogo" src="<?= $loginLogo ?>?t=<?= time() ?>" alt="Jason AI">
                </div>
                <p class="login-tagline">Transcription &amp; Learning Tool</p>
            </div>
            <div class="login-card-body">

            <!-- Alert -->
            <div id="loginAlert" class="login-alert"></div>

            <!-- ─── Login Form ─── -->
            <form id="loginForm" class="login-form" autocomplete="on" novalidate>
                <div class="login-field">
                    <label for="loginEmail">Email Address</label>
                    <input type="email" id="loginEmail" name="email" placeholder="you@company.com" autocomplete="email" required>
                </div>
                <div class="login-field">
                    <label for="loginPassword">Password</label>
                    <div class="login-field-password">
                        <input type="password" id="loginPassword" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="login-password-toggle" id="togglePassword" tabindex="-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Honeypot — invisible to humans, bots fill it -->
                <div class="login-hp" aria-hidden="true">
                    <input type="text" id="loginWebsite" name="website" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">Sign In</span>
                </button>

                <div class="login-links">
                    <a href="#" id="showResetLink">Forgot your password?</a>
                </div>
            </form>

            <!-- ─── Forgot Password Form ─── -->
            <div id="resetForm" class="login-reset-form">
                <a href="#" class="login-back-link" id="backToLogin">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    Back to sign in
                </a>

                <div class="login-title" style="margin-bottom:16px">
                    <h1 style="font-size:20px">Reset Password</h1>
                    <p>Enter your email and we'll send you a reset link</p>
                </div>

                <div class="login-field">
                    <label for="resetEmail">Email Address</label>
                    <input type="email" id="resetEmail" placeholder="you@company.com" required>
                </div>

                <button type="button" class="login-btn" id="resetBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">Send Reset Link</span>
                </button>
            </div>

            <!-- ─── New Password Form (from token link) ─── -->
            <div id="newPwForm" class="login-new-pw-form">
                <div class="login-title" style="margin-bottom:16px">
                    <h1 style="font-size:20px">Set New Password</h1>
                    <p>Choose a strong password (min 8 characters)</p>
                </div>

                <div class="login-field">
                    <label for="newPassword">New Password</label>
                    <div class="login-field-password">
                        <input type="password" id="newPassword" placeholder="Min 8 characters" required minlength="8">
                        <button type="button" class="login-password-toggle" id="toggleNewPw" tabindex="-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <div class="login-field">
                    <label for="confirmPassword">Confirm Password</label>
                    <input type="password" id="confirmPassword" placeholder="Re-enter password" required>
                </div>

                <button type="button" class="login-btn" id="newPwBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">Reset Password</span>
                </button>

                <div class="login-links">
                    <a href="login.php">Back to sign in</a>
                </div>
            </div>  <!-- close newPwForm -->
        </div>  <!-- close login-card-body -->
    </div>  <!-- close login-card -->

        <div class="login-footer">
            Developed by <a href="https://www.linkedin.com/in/jasonhogan333/" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;text-underline-offset:2px">Jason Hogan</a>
        </div>
    </div>

    <!-- Login Success Transition Overlay -->
    <div id="loginTransition" class="login-transition">
        <div class="login-transition-box">
            <canvas id="loginTransitionCanvas" width="420" height="420"></canvas>
            <div class="login-transition-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h2 class="login-transition-title" id="loginTransitionTitle">Authenticating...</h2>
            <p class="login-transition-subtitle" id="loginTransitionSubtitle">Verifying your credentials</p>
        </div>
    </div>

    <script>
    (() => {
        const resetToken = '<?= $resetToken ?>';
        const $alert     = document.getElementById('loginAlert');
        const $loginForm = document.getElementById('loginForm');
        const $resetForm = document.getElementById('resetForm');
        const $newPwForm = document.getElementById('newPwForm');

        // ─── Show correct form based on URL ───
        if (resetToken) {
            $loginForm.classList.add('hidden');
            $newPwForm.classList.add('active');
        }

        // ─── Helpers ───
        function showAlert(msg, type = 'error') {
            $alert.textContent = msg;
            $alert.className = 'login-alert show ' + type;
        }
        function hideAlert() {
            $alert.className = 'login-alert';
        }
        function setLoading(btn, loading) {
            btn.disabled = loading;
            btn.classList.toggle('loading', loading);
        }

        // ─── Password toggle ───
        function bindToggle(toggleBtn, inputEl) {
            toggleBtn.addEventListener('click', () => {
                const isHidden = inputEl.type === 'password';
                inputEl.type = isHidden ? 'text' : 'password';
                toggleBtn.innerHTML = isHidden
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            });
        }
        bindToggle(document.getElementById('togglePassword'), document.getElementById('loginPassword'));
        const toggleNewPw = document.getElementById('toggleNewPw');
        if (toggleNewPw) bindToggle(toggleNewPw, document.getElementById('newPassword'));

        // ─── Login ───
        $loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            const email    = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;
            const honeypot = document.getElementById('loginWebsite').value;

            if (!email || !password) {
                showAlert('Please enter your email and password.');
                return;
            }

            const btn = document.getElementById('loginBtn');
            setLoading(btn, true);

            try {
                const res = await fetch('api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password, honeypot })
                });
                const data = await res.json();

                if (data.success) {
                    showLoginTransition();
                } else {
                    showAlert(data.error || 'Login failed.');
                    setLoading(btn, false);
                }
            } catch (err) {
                showAlert('Network error. Please try again.');
                setLoading(btn, false);
            }
        });

        // ─── Show/hide reset form ───
        document.getElementById('showResetLink').addEventListener('click', (e) => {
            e.preventDefault();
            hideAlert();
            $loginForm.classList.add('hidden');
            $resetForm.classList.add('active');
        });
        document.getElementById('backToLogin').addEventListener('click', (e) => {
            e.preventDefault();
            hideAlert();
            $resetForm.classList.remove('active');
            $loginForm.classList.remove('hidden');
        });

        // ─── Request reset ───
        document.getElementById('resetBtn').addEventListener('click', async () => {
            hideAlert();
            const email = document.getElementById('resetEmail').value.trim();
            if (!email) { showAlert('Please enter your email.'); return; }

            const btn = document.getElementById('resetBtn');
            setLoading(btn, true);

            try {
                const res = await fetch('api/auth.php?action=request_reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email })
                });
                const data = await res.json();
                showAlert(data.message || 'If that email exists, a reset link has been sent.', 'success');
            } catch (err) {
                showAlert('Network error. Please try again.');
            }
            setLoading(btn, false);
        });

        // ─── Set new password (from token) ───
        document.getElementById('newPwBtn').addEventListener('click', async () => {
            hideAlert();
            const pw1 = document.getElementById('newPassword').value;
            const pw2 = document.getElementById('confirmPassword').value;

            if (!pw1 || pw1.length < 8) { showAlert('Password must be at least 8 characters.'); return; }
            if (pw1 !== pw2) { showAlert('Passwords do not match.'); return; }

            const btn = document.getElementById('newPwBtn');
            setLoading(btn, true);

            try {
                const res = await fetch('api/auth.php?action=reset_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: resetToken, new_password: pw1 })
                });
                const data = await res.json();

                if (data.success) {
                    showAlert(data.message || 'Password reset! Redirecting to sign in...', 'success');
                    setTimeout(() => { window.location.href = 'login.php'; }, 2000);
                } else {
                    showAlert(data.error || 'Reset failed.');
                }
            } catch (err) {
                showAlert('Network error. Please try again.');
            }
            setLoading(btn, false);
        });

        // ═══════════════════════════════════════════════
        //  LOGIN SUCCESS TRANSITION
        // ═══════════════════════════════════════════════
        function showLoginTransition() {
            const overlay = document.getElementById('loginTransition');
            const titleEl = document.getElementById('loginTransitionTitle');
            const subtitleEl = document.getElementById('loginTransitionSubtitle');
            overlay.classList.add('active');

            // Start plasma particles on the transition canvas
            const tc = document.getElementById('loginTransitionCanvas');
            const tctx = tc.getContext('2d');
            const tw = tc.width, th = tc.height;
            const colors = [
                [59,130,246],[96,165,250],[139,92,246],[168,85,247],
                [236,72,153],[244,114,182],[6,182,212],[20,184,166],
                [99,102,241],[245,158,11],[16,185,129],[251,113,133]
            ];
            const particles = [];
            for (let i = 0; i < 100; i++) {
                const angle = (i / 100) * Math.PI * 2;
                const r = 20 + Math.random() * 180;
                const col = colors[Math.floor(Math.random() * colors.length)];
                particles.push({
                    x: tw/2, y: th/2, angle, radius: r, speed: 0.008 + Math.random() * 0.022,
                    size: 1.5 + Math.random() * 4, r: col[0], g: col[1], b: col[2],
                    alpha: 0.4 + Math.random() * 0.6, phase: Math.random() * Math.PI * 2,
                    phaseSpeed: 0.02 + Math.random() * 0.04,
                    trail: [], trailMax: 3 + Math.floor(Math.random() * 5),
                    sparkle: Math.random() > 0.65, sparklePhase: Math.random() * Math.PI * 2,
                });
            }
            let time = 0;
            let tAnimFrame;
            function drawTransition() {
                tctx.clearRect(0, 0, tw, th);
                time += 0.016;
                const cx = tw/2, cy = th/2;
                // Soft glow
                const bg = tctx.createRadialGradient(cx, cy, 0, cx, cy, 190);
                bg.addColorStop(0, `rgba(99,102,241,${0.06 + 0.03 * Math.sin(time)})`);
                bg.addColorStop(1, 'rgba(0,0,0,0)');
                tctx.fillStyle = bg;
                tctx.fillRect(0, 0, tw, th);
                // Lines
                for (let i = 0; i < particles.length; i++) {
                    for (let j = i+1; j < particles.length; j++) {
                        const dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                        const d = Math.sqrt(dx*dx+dy*dy);
                        if (d < 80) {
                            tctx.save(); tctx.globalAlpha = (1 - d/50) * 0.1;
                            tctx.strokeStyle = `rgb(${particles[i].r},${particles[i].g},${particles[i].b})`;
                            tctx.lineWidth = 0.5;
                            tctx.beginPath(); tctx.moveTo(particles[i].x, particles[i].y); tctx.lineTo(particles[j].x, particles[j].y); tctx.stroke();
                            tctx.restore();
                        }
                    }
                }
                particles.forEach(p => {
                    p.angle += p.speed; p.phase += p.phaseSpeed; p.sparklePhase += 0.08;
                    const wobble = Math.sin(time * 1.5 + p.phase) * 6;
                    const breathe = 1 + 0.15 * Math.sin(time * 0.4 + p.phase * 2);
                    p.x = cx + Math.cos(p.angle) * (p.radius * breathe + wobble);
                    p.y = cy + Math.sin(p.angle) * (p.radius * breathe + wobble);
                    p.trail.push({x: p.x, y: p.y}); if (p.trail.length > p.trailMax) p.trail.shift();
                    const alpha = p.alpha * (0.5 + 0.5 * Math.sin(p.phase));
                    const col = `${p.r},${p.g},${p.b}`;
                    tctx.save();
                    // Glow
                    tctx.globalAlpha = alpha * 0.15;
                    const glow = tctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * 5);
                    glow.addColorStop(0, `rgba(${col},0.4)`); glow.addColorStop(1, `rgba(${col},0)`);
                    tctx.fillStyle = glow; tctx.beginPath(); tctx.arc(p.x, p.y, p.size * 5, 0, Math.PI * 2); tctx.fill();
                    // Core
                    tctx.globalAlpha = alpha; tctx.fillStyle = `rgb(${col})`; tctx.beginPath(); tctx.arc(p.x, p.y, p.size, 0, Math.PI * 2); tctx.fill();
                    // Bright center
                    tctx.globalAlpha = alpha * 0.7; tctx.fillStyle = 'rgba(255,255,255,0.7)'; tctx.beginPath(); tctx.arc(p.x, p.y, p.size * 0.3, 0, Math.PI * 2); tctx.fill();
                    // Sparkle
                    if (p.sparkle && Math.sin(p.sparklePhase) > 0.3) {
                        tctx.globalAlpha = Math.sin(p.sparklePhase) * alpha * 0.6;
                        tctx.fillStyle = '#fff';
                        const s = p.size * 2;
                        tctx.beginPath(); tctx.moveTo(p.x-s,p.y); tctx.lineTo(p.x,p.y-1); tctx.lineTo(p.x+s,p.y); tctx.lineTo(p.x,p.y+1); tctx.closePath(); tctx.fill();
                        tctx.beginPath(); tctx.moveTo(p.x,p.y-s); tctx.lineTo(p.x-1,p.y); tctx.lineTo(p.x,p.y+s); tctx.lineTo(p.x+1,p.y); tctx.closePath(); tctx.fill();
                    }
                    tctx.restore();
                });
                tAnimFrame = requestAnimationFrame(drawTransition);
            }
            drawTransition();

            // Cycling text messages
            const messages = [
                'Verifying your credentials...',
                'Securing your session...',
                'Loading your workspace...',
                'Preparing your dashboard...',
                'Initializing transcription engine...',
                'Almost there...',
                'Setting up your environment...',
                'Connecting to AI services...',
            ];
            let msgIdx = 0;
            const msgInterval = setInterval(() => {
                subtitleEl.style.opacity = '0';
                setTimeout(() => {
                    msgIdx = (msgIdx + 1) % messages.length;
                    subtitleEl.textContent = messages[msgIdx];
                    subtitleEl.style.opacity = '1';
                }, 300);
            }, 1800);

            // After ~3 seconds, animate lock icon change and exit
            setTimeout(() => {
                titleEl.textContent = 'Welcome!';
                subtitleEl.textContent = 'Launching Jason AI...';
                // Change lock to unlocked
                document.querySelector('.login-transition-icon').innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';
            }, 2500);

            setTimeout(() => {
                clearInterval(msgInterval);
                cancelAnimationFrame(tAnimFrame);
                overlay.classList.add('exit');
                setTimeout(() => { window.location.href = 'index.html'; }, 700);
            }, 3500);
        }

        // ═══════════════════════════════════════════════
        //  LOGIN PAGE ANIMATIONS
        // ═══════════════════════════════════════════════

        const canvas = document.getElementById('loginAnimationCanvas');
        const ctx = canvas.getContext('2d');
        let animFrame = null;
        let animOpacity = 0.5;
        let animSpeed = 1.0;

        function resizeCanvas() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        // ─── Fetch animation setting from DB ───
        fetch('api/login-settings.php')
            .then(r => r.json())
            .then(data => {
                const s = data.settings || {};
                // Apply opacity (0-100 → 0-1)
                animOpacity = Math.max(0.1, Math.min(1, (parseInt(s.loginAnimationOpacity) || 50) / 100));
                canvas.style.opacity = animOpacity;
                // Apply speed (10-200 → 0.2-4.0 multiplier)
                animSpeed = Math.max(0.2, Math.min(4, (parseInt(s.loginAnimationSpeed) || 50) / 50));

                // Apply brand color — full palette
                if (s.brandColor) {
                    const hex = s.brandColor;
                    const ri = parseInt(hex.slice(1,3),16)/255;
                    const gi = parseInt(hex.slice(3,5),16)/255;
                    const bi = parseInt(hex.slice(5,7),16)/255;
                    const mx = Math.max(ri,gi,bi), mn = Math.min(ri,gi,bi);
                    let h, sat, l = (mx+mn)/2;
                    if (mx === mn) { h = sat = 0; }
                    else {
                        const d = mx - mn;
                        sat = l > 0.5 ? d/(2-mx-mn) : d/(mx+mn);
                        switch(mx){
                            case ri: h = ((gi-bi)/d + (gi<bi?6:0))/6; break;
                            case gi: h = ((bi-ri)/d + 2)/6; break;
                            case bi: h = ((ri-gi)/d + 4)/6; break;
                        }
                    }
                    h = Math.round(h*360);
                    const sc = Math.min(Math.round(sat*100), 85);
                    const hsl = (ss, ll) => `hsl(${h}, ${ss}%, ${ll}%)`;
                    const root = document.documentElement;
                    root.style.setProperty('--brand-grad-light', hsl(Math.min(sc, 70), 35));
                    root.style.setProperty('--brand-grad-mid', hsl(Math.min(sc, 80), 25));
                    root.style.setProperty('--brand-grad-dark', hsl(Math.min(sc, 75), 17));
                    // Full brand scale for accent colors
                    root.style.setProperty('--brand-500', hsl(sc, 40));
                    root.style.setProperty('--brand-600', hsl(sc, 33));
                    // RGB for rgba() usage
                    const hslToRgb = (hh,ss2,ll2) => {
                        hh/=360; ss2/=100; ll2/=100;
                        let rr,gg,bb;
                        if(ss2===0){rr=gg=bb=ll2;}else{
                            const q=ll2<0.5?ll2*(1+ss2):ll2+ss2-ll2*ss2, p=2*ll2-q;
                            const h2r=(pp,qq,t)=>{if(t<0)t+=1;if(t>1)t-=1;if(t<1/6)return pp+(qq-pp)*6*t;if(t<1/2)return qq;if(t<2/3)return pp+(qq-pp)*(2/3-t)*6;return pp;};
                            rr=h2r(p,q,hh+1/3);gg=h2r(p,q,hh);bb=h2r(p,q,hh-1/3);
                        }
                        return [Math.round(rr*255),Math.round(gg*255),Math.round(bb*255)];
                    };
                    const [r5,g5,b5] = hslToRgb(h,sc,40);
                    const [r6,g6,b6] = hslToRgb(h,sc,33);
                    root.style.setProperty('--brand-500-rgb', `${r5},${g5},${b5}`);
                    root.style.setProperty('--brand-600-rgb', `${r6},${g6},${b6}`);
                }

                if (s.loginAnimationEnabled === '1' || s.loginAnimationEnabled === 'true') {
                    startAnimation(s.loginAnimation || 'constellations');
                }
            })
            .catch(() => {}); // silently fail

        function startAnimation(type) {
            if (animFrame) cancelAnimationFrame(animFrame);
            const runners = {
                constellations: animConstellations,
                particles: animParticles,
                matrix: animMatrix,
                waves: animWaves,
                fireflies: animFireflies,
                snow: animSnow,
                audiospectrum: animAudioSpectrum,
            };
            const runner = runners[type];
            if (runner) runner();
        }

        // ─── 1. CONSTELLATIONS ───
        function animConstellations() {
            const pts = [];
            const count = Math.floor((canvas.width * canvas.height) / 12000);
            for (let i = 0; i < count; i++) {
                pts.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    r: Math.random() * 2 + 1,
                });
            }
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                // Lines
                for (let i = 0; i < pts.length; i++) {
                    for (let j = i + 1; j < pts.length; j++) {
                        const dx = pts[i].x - pts[j].x;
                        const dy = pts[i].y - pts[j].y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 150) {
                            ctx.beginPath();
                            ctx.moveTo(pts[i].x, pts[i].y);
                            ctx.lineTo(pts[j].x, pts[j].y);
                            ctx.strokeStyle = `rgba(100,180,255,${0.3 * (1 - dist / 150)})`;
                            ctx.lineWidth = 0.6;
                            ctx.stroke();
                        }
                    }
                }
                // Dots
                for (const p of pts) {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(140,200,255,0.7)';
                    ctx.fill();
                    p.x += p.vx * animSpeed;
                    p.y += p.vy * animSpeed;
                    if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                    if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                }
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 2. RISING PARTICLES ───
        function animParticles() {
            const pts = [];
            const count = 80;
            function spawn() {
                return {
                    x: Math.random() * canvas.width,
                    y: canvas.height + Math.random() * 20,
                    vy: -(Math.random() * 1.5 + 0.4),
                    vx: (Math.random() - 0.5) * 0.3,
                    r: Math.random() * 3 + 1,
                    alpha: Math.random() * 0.5 + 0.3,
                    life: 0,
                };
            }
            for (let i = 0; i < count; i++) {
                const p = spawn();
                p.y = Math.random() * canvas.height; // spread initially
                pts.push(p);
            }
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (let i = pts.length - 1; i >= 0; i--) {
                    const p = pts[i];
                    p.x += p.vx * animSpeed;
                    p.y += p.vy * animSpeed;
                    p.life++;
                    const fade = Math.max(0, p.alpha * (1 - p.y / -200));
                    if (p.y < -10) {
                        pts[i] = spawn();
                        continue;
                    }
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(160,200,255,${Math.min(p.alpha, fade)})`;
                    ctx.fill();
                }
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 3. MATRIX RAIN ───
        function animMatrix() {
            const fontSize = 14;
            const cols = Math.ceil(canvas.width / fontSize);
            const drops = new Array(cols).fill(0).map(() => Math.random() * -100);
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$%^&*()アイウエオカキクケコサシスセソ';
            let acc = 0;

            function draw() {
                acc += animSpeed;
                if (acc < 1) { animFrame = requestAnimationFrame(draw); return; }
                acc = 0;
                ctx.fillStyle = 'rgba(10, 15, 30, 0.06)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.font = fontSize + 'px monospace';

                for (let i = 0; i < cols; i++) {
                    const char = chars[Math.floor(Math.random() * chars.length)];
                    const y = drops[i] * fontSize;
                    ctx.fillStyle = `rgba(100,180,255,0.9)`;
                    ctx.fillText(char, i * fontSize, y);
                    if (drops[i] > 1) {
                        const prevChar = chars[Math.floor(Math.random() * chars.length)];
                        ctx.fillStyle = `rgba(59,130,246,0.25)`;
                        ctx.fillText(prevChar, i * fontSize, y - fontSize);
                    }

                    drops[i]++;
                    if (y > canvas.height && Math.random() > 0.975) {
                        drops[i] = 0;
                    }
                }
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 4. WAVE PULSE ───
        function animWaves() {
            let time = 0;
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                const waveCount = 5;
                for (let w = 0; w < waveCount; w++) {
                    ctx.beginPath();
                    const yBase = canvas.height * (0.3 + w * 0.12);
                    const amp = 30 + w * 8;
                    const freq = 0.003 - w * 0.0003;
                    const speed = time * (0.8 + w * 0.2);
                    for (let x = 0; x <= canvas.width; x += 3) {
                        const y = yBase + Math.sin(x * freq + speed) * amp + Math.sin(x * freq * 2.3 + speed * 0.7) * amp * 0.3;
                        if (x === 0) ctx.moveTo(x, y);
                        else ctx.lineTo(x, y);
                    }
                    const alpha = 0.15 + w * 0.04;
                    const hue = 200 + w * 20;
                    ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${alpha})`;
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }
                time += 0.015 * animSpeed;
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 5. FIREFLIES ───
        function animFireflies() {
            const flies = [];
            const count = 40;
            for (let i = 0; i < count; i++) {
                flies.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.7,
                    vy: (Math.random() - 0.5) * 0.7,
                    r: Math.random() * 4 + 2,
                    phase: Math.random() * Math.PI * 2,
                    speed: Math.random() * 0.02 + 0.01,
                    hue: Math.random() * 60 + 30, // warm yellows/ambers
                });
            }
            let t = 0;
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                t += 1;
                for (const f of flies) {
                    const glow = (Math.sin(t * f.speed + f.phase) + 1) / 2;
                    const alpha = 0.2 + glow * 0.6;
                    const r = f.r + glow * 3;

                    // Outer glow
                    const grad = ctx.createRadialGradient(f.x, f.y, 0, f.x, f.y, r * 3);
                    grad.addColorStop(0, `hsla(${f.hue}, 100%, 70%, ${alpha * 0.6})`);
                    grad.addColorStop(1, `hsla(${f.hue}, 100%, 70%, 0)`);
                    ctx.beginPath();
                    ctx.arc(f.x, f.y, r * 3, 0, Math.PI * 2);
                    ctx.fillStyle = grad;
                    ctx.fill();

                    // Core
                    ctx.beginPath();
                    ctx.arc(f.x, f.y, r * 0.5, 0, Math.PI * 2);
                    ctx.fillStyle = `hsla(${f.hue}, 100%, 85%, ${alpha})`;
                    ctx.fill();

                    // Drift
                    f.x += (f.vx + Math.sin(t * 0.005 + f.phase) * 0.3) * animSpeed;
                    f.y += (f.vy + Math.cos(t * 0.004 + f.phase) * 0.3) * animSpeed;
                    if (f.x < -20) f.x = canvas.width + 20;
                    if (f.x > canvas.width + 20) f.x = -20;
                    if (f.y < -20) f.y = canvas.height + 20;
                    if (f.y > canvas.height + 20) f.y = -20;
                }
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 6. SNOWFALL ───
        function animSnow() {
            const flakes = [];
            const count = 120;
            for (let i = 0; i < count; i++) {
                flakes.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    r: Math.random() * 3 + 1,
                    vy: Math.random() * 1 + 0.3,
                    vx: (Math.random() - 0.5) * 0.5,
                    wobble: Math.random() * Math.PI * 2,
                    wobbleSpeed: Math.random() * 0.02 + 0.005,
                    alpha: Math.random() * 0.4 + 0.2,
                });
            }
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (const f of flakes) {
                    f.wobble += f.wobbleSpeed * animSpeed;
                    f.x += (f.vx + Math.sin(f.wobble) * 0.5) * animSpeed;
                    f.y += f.vy * animSpeed;
                    if (f.y > canvas.height + 10) {
                        f.y = -10;
                        f.x = Math.random() * canvas.width;
                    }
                    if (f.x < -10) f.x = canvas.width + 10;
                    if (f.x > canvas.width + 10) f.x = -10;

                    ctx.beginPath();
                    ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(220, 230, 255, ${f.alpha})`;
                    ctx.fill();
                }
                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

        // ─── 7. AUDIO SPECTRUM ───
        function animAudioSpectrum() {
            const w = () => canvas.width;
            const h = () => canvas.height;

            function buildBars() {
                const n = Math.max(48, Math.min(160, Math.round(w() / 18)));
                const bars = new Array(n);
                for (let i = 0; i < n; i++) {
                    bars[i] = {
                        current: 0,
                        target:  0,
                        peak:    0,
                        peakVel: 0,
                        p1: Math.random() * Math.PI * 2,
                        p2: Math.random() * Math.PI * 2,
                        p3: Math.random() * Math.PI * 2,
                        f1: 0.7 + Math.random() * 1.3,
                        f2: 1.3 + Math.random() * 2.2,
                        f3: 0.25 + Math.random() * 0.6,
                    };
                }
                return bars;
            }

            let bars = buildBars();
            let t = 0;
            let hueBase = 0;

            let lastW = w();
            window.addEventListener('resize', () => {
                if (Math.abs(w() - lastW) > 40) {
                    bars = buildBars();
                    lastW = w();
                }
            });

            function draw() {
                const W = w(), H = h();
                ctx.clearRect(0, 0, W, H);
                const n = bars.length;
                const barWidth = (W / n) * 0.72;
                const stride   =  W / n;
                const centerY  = H / 2;
                const maxHeight = H * 0.42;
                t += 0.016 * animSpeed;
                hueBase = (hueBase + 0.18 * animSpeed) % 360;

                const sweepPhase = t * 1.2;

                for (let i = 0; i < n; i++) {
                    const b = bars[i];
                    const x = i * stride + (stride - barWidth) / 2;

                    const bias = 0.55 + 0.45 * Math.cos((i / n) * Math.PI);
                    const a1 = Math.sin(t * b.f1 + b.p1);
                    const a2 = Math.sin(t * b.f2 + b.p2) * 0.55;
                    const a3 = Math.sin(t * b.f3 + b.p3) * 0.35;
                    const sweep = Math.max(0, Math.sin(sweepPhase - i * 0.15)) * 0.3;
                    b.target = Math.max(0, Math.min(1,
                        0.18 + 0.5 * (a1 + a2 + a3) * 0.45 * bias + sweep
                    ));

                    const lerp = b.target > b.current ? 0.35 : 0.12;
                    b.current += (b.target - b.current) * lerp;

                    if (b.current > b.peak) {
                        b.peak = b.current;
                        b.peakVel = 0;
                    } else {
                        b.peakVel += 0.0008 * animSpeed;
                        b.peak = Math.max(b.current, b.peak - b.peakVel);
                    }

                    const hue = (hueBase + (i / n) * 260) % 360;
                    const barH = b.current * maxHeight;

                    const gTop = ctx.createLinearGradient(0, centerY, 0, centerY - barH);
                    gTop.addColorStop(0, 'hsla(' + hue + ', 95%, 62%, 0.95)');
                    gTop.addColorStop(1, 'hsla(' + ((hue + 45) % 360) + ', 95%, 72%, 0.35)');
                    ctx.fillStyle = gTop;
                    ctx.fillRect(x, centerY - barH, barWidth, barH);

                    const gBot = ctx.createLinearGradient(0, centerY, 0, centerY + barH);
                    gBot.addColorStop(0, 'hsla(' + hue + ', 95%, 62%, 0.95)');
                    gBot.addColorStop(1, 'hsla(' + ((hue + 45) % 360) + ', 95%, 72%, 0.35)');
                    ctx.fillStyle = gBot;
                    ctx.fillRect(x, centerY, barWidth, barH);

                    ctx.fillStyle = 'hsla(' + hue + ', 95%, 68%, 0.10)';
                    ctx.fillRect(x - barWidth * 0.3, centerY - barH * 1.08, barWidth * 1.6, barH * 2.16);

                    const peakH = b.peak * maxHeight;
                    ctx.fillStyle = 'hsla(' + ((hue + 30) % 360) + ', 100%, 82%, 0.95)';
                    ctx.fillRect(x, centerY - peakH - 2, barWidth, 2);
                    ctx.fillRect(x, centerY + peakH, barWidth, 2);
                }

                ctx.fillStyle = 'rgba(255,255,255,0.08)';
                ctx.fillRect(0, centerY - 0.5, W, 1);

                animFrame = requestAnimationFrame(draw);
            }
            draw();
        }

    })();
    </script>
</body>
</html>
