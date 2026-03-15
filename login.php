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
    <title>Sign In — Transcribe AI</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css">
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
                    <img src="img/logo.png" alt="Transcribe AI">
                </div>
                <div class="login-title">
                    <h1>Welcome Back</h1>
                    <p>Sign in to Transcribe AI</p>
                </div>
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
            Powered by Whisper AI & OpenRouter &middot; Transcribe AI by Botson
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
                    showAlert('Signed in! Redirecting...', 'success');
                    setTimeout(() => { window.location.href = 'index.html'; }, 500);
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
        //  LOGIN PAGE ANIMATIONS
        // ═══════════════════════════════════════════════

        const canvas = document.getElementById('loginAnimationCanvas');
        const ctx = canvas.getContext('2d');
        let animFrame = null;

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
                    p.x += p.vx;
                    p.y += p.vy;
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
                    p.x += p.vx;
                    p.y += p.vy;
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

            function draw() {
                ctx.fillStyle = 'rgba(10, 15, 30, 0.06)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.font = fontSize + 'px monospace';

                for (let i = 0; i < cols; i++) {
                    const char = chars[Math.floor(Math.random() * chars.length)];
                    const y = drops[i] * fontSize;
                    // Head char is brighter
                    ctx.fillStyle = `rgba(80,200,120,0.9)`;
                    ctx.fillText(char, i * fontSize, y);
                    // Trail chars are dimmer
                    if (drops[i] > 1) {
                        const prevChar = chars[Math.floor(Math.random() * chars.length)];
                        ctx.fillStyle = `rgba(0,180,80,0.25)`;
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
                time += 0.015;
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
                    f.x += f.vx + Math.sin(t * 0.005 + f.phase) * 0.3;
                    f.y += f.vy + Math.cos(t * 0.004 + f.phase) * 0.3;
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
                    f.wobble += f.wobbleSpeed;
                    f.x += f.vx + Math.sin(f.wobble) * 0.5;
                    f.y += f.vy;
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

    })();
    </script>
</body>
</html>
