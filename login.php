<?php
// login.php — Light Mode Enterprise UI + Fixed Role Redirects
require_once 'includes/config.php';
require_once 'includes/session.php';

error_reporting(0);
ini_set('display_errors', 0);

// ── FIX: Consistent role-based redirect via SessionManager ───────────────────
if (SessionManager::isLoggedIn()) {
    if (SessionManager::isManager() || SessionManager::isStaff()) {
        header('Location: manager/dashboard.php');
    } elseif (SessionManager::isAccounting()) {
        header('Location: accounting/dashboard.php');
    } else {
        header('Location: user/dashboard.php');
    }
    exit();
}

$error   = '';
$success = '';
$email   = '';

// ── FIX: Show flash messages set by other pages (e.g. "please login") ────────
$flash = SessionManager::getMessage();
if ($flash) {
    if ($flash['type'] === 'success') $success = $flash['message'];
    else $error = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // ── FIX: Validate fields individually with clear messages ─────────────────
    if (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } else {
        try {
            $db = (new Database())->getConnection();

            // ── FIX: Also check is_active so deactivated accounts cannot log in ──
            $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !isset($user['hashed_password']) || !password_verify($password, $user['hashed_password'])) {
                // Generic message prevents user enumeration
                $error = 'Invalid email or password.';
            } elseif (isset($user['is_active']) && !$user['is_active']) {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } else {
                SessionManager::login($user);

                $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE user_id = :id")
                   ->execute([':id' => $user['user_id']]);

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                }

                // ── FIX: Use SessionManager methods for redirect (not raw role string) ──
                if (SessionManager::isManager() || SessionManager::isStaff()) {
                    header('Location: manager/dashboard.php');
                } elseif (SessionManager::isAccounting()) {
                    header('Location: accounting/dashboard.php');
                } else {
                    header('Location: user/dashboard.php');
                }
                exit();
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}

$registered = isset($_GET['registered']) && $_GET['registered'] === 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — BISU IGE Aquaculture</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-light: #f8fafc;
            --card-light: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --primary-light: #e0f2fe;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --radius: 12px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        html, body { height: 100%; font-family: 'Sora', sans-serif; background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); }

        .page { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; }

        /* ── Left: Brand Panel (Updated Light Mode) ── */
        .brand-panel {
            position: relative;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem;
            overflow: hidden;
        }
        .brand-logo-text a {
            text-decoration: none;
            color: inherit;
        }
        .brand-panel::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse 60% 50% at 20% 80%, rgba(14,165,233,.15) 0%, transparent 70%);
            pointer-events: none;
        }
        .brand-panel::after {
            content: '';
            position: absolute; inset: 0;
            background-image: linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        .brand-logo { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
        .brand-logo-icon {
            width: 48px; height: 48px; background: var(--primary); border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .brand-logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .brand-logo-text strong { display: block; font-size: 18px; font-weight: 700; letter-spacing: .04em; color: white; }
        .brand-logo-text span   { font-size: 11px; font-weight: 400; color: #94a3b8; letter-spacing: .1em; text-transform: uppercase; }

        .brand-headline { position: relative; z-index: 1; }
        .brand-headline h1 {
            font-family: 'DM Serif Display', serif;
            font-size: clamp(2.2rem, 3.5vw, 3.4rem);
            line-height: 1.12; color: white; margin-bottom: 1.2rem;
        }
        .brand-headline h1 em { font-style: normal; color: var(--primary); }
        .brand-headline p { font-size: 15px; color: #94a3b8; line-height: 1.7; max-width: 380px; }

        .brand-stats { display: flex; gap: 2.5rem; position: relative; z-index: 1; margin-top: 2.5rem; }
        .brand-stat-value { font-family: 'DM Serif Display', serif; font-size: 2rem; color: var(--primary); display: block; }
        .brand-stat-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: .1em; }

        .brand-footer { font-size: 12px; color: #64748b; position: relative; z-index: 1; }

        .deco-fish {
            position: absolute; right: -80px; top: 50%; transform: translateY(-50%);
            font-size: 300px; color: rgba(14,165,233,.08); pointer-events: none; z-index: 0;
            animation: sway 8s ease-in-out infinite;
        }
        @keyframes sway {
            0%, 100% { transform: translateY(-50%) rotate(-3deg); }
            50%       { transform: translateY(-52%) rotate(2deg); }
        }

        /* ── Right: Form Panel (Light Mode) ── */
        .form-panel {
            background: white;
            display: flex; align-items: center; justify-content: center;
            padding: 3rem 2rem; position: relative;
        }
        .form-panel::before {
            content: ''; position: absolute;
            top: 0; left: 0; width: 4px; height: 100%;
            background: linear-gradient(to bottom, transparent, var(--primary), transparent);
        }

        .form-box { width: 100%; max-width: 420px; animation: fadeUp .45s ease-out both; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }

        .form-heading { margin-bottom: 2rem; }
        .eyebrow { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; color: var(--primary); font-weight: 600; margin-bottom: 10px; }
        .form-heading h2 { font-family: 'DM Serif Display', serif; font-size: 2.1rem; color: var(--text-primary); line-height: 1.1; }
        .form-heading p  { font-size: 14px; color: var(--text-secondary); margin-top: 8px; }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 13px 16px; border-radius: var(--radius);
            font-size: 13.5px; margin-bottom: 1.4rem;
            border: 1px solid transparent;
            animation: slideIn .3s ease-out;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
        .alert-error   { background: var(--danger-light); border-color: #fecaca; color: #991b1b; }
        .alert-success { background: var(--success-light); border-color: #a7f3d0; color: #065f46; }
        .alert i { margin-top: 2px; flex-shrink: 0; }

        /* Fields */
        .field { margin-bottom: 1.1rem; }
        .field label {
            display: block; font-size: 12px; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase;
            color: var(--text-secondary); margin-bottom: 7px;
        }
        .input-wrap { position: relative; }
        .input-wrap .field-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 14px; pointer-events: none; transition: color .2s;
        }
        .input-wrap:focus-within .field-icon { color: var(--primary); }
        .input-wrap input {
            width: 100%; background: var(--bg-light); border: 1.5px solid var(--border-light);
            border-radius: var(--radius); color: var(--text-primary); font-family: 'Sora', sans-serif;
            font-size: 14.5px; padding: 12px 14px 12px 42px; outline: none;
            transition: border-color .2s, box-shadow .2s; -webkit-appearance: none;
        }
        .input-wrap input::placeholder { color: #94a3b8; }
        .input-wrap input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(14,165,233,.15); background: white; }
        .toggle-pw {
            position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #94a3b8; cursor: pointer;
            font-size: 14px; padding: 4px; transition: color .2s;
        }
        .toggle-pw:hover { color: var(--primary); }

        .row-options { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.4rem; font-size: 13px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-secondary); user-select: none; }
        .checkbox-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }
        .forgot-link { color: var(--primary); text-decoration: none; font-weight: 500; font-size: 13px; transition: color .2s; }
        .forgot-link:hover { color: var(--primary-dark); text-decoration: underline; }

        .btn-primary {
            width: 100%; padding: 13px; background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none; border-radius: var(--radius); color: white; font-family: 'Sora', sans-serif;
            font-size: 15px; font-weight: 700; letter-spacing: .04em; cursor: pointer;
            transition: transform .15s, box-shadow .2s;
            box-shadow: 0 4px 14px rgba(14,165,233,.3);
            display: flex; align-items: center; justify-content: center; gap: 9px;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(14,165,233,.4); }
        .btn-primary:active { transform: translateY(0); }

        .divider { display: flex; align-items: center; gap: 12px; margin: 1.5rem 0; color: #94a3b8; font-size: 12px; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border-light); }

        .btn-secondary {
            width: 100%; padding: 12px; background: transparent;
            border: 1.5px solid var(--border-light); border-radius: var(--radius);
            color: var(--text-secondary); font-family: 'Sora', sans-serif;
            font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            transition: border-color .2s, color .2s, background .2s;
        }
        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); background: rgba(14,165,233,.05); }

        @media (max-width: 900px) {
            .page { grid-template-columns: 1fr; }
            .brand-panel { display: none; }
            .form-panel::before { display: none; }
            .form-panel { padding: 2rem 1.25rem; min-height: 100vh; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- ── Left: Brand Panel ── -->
    <div class="brand-panel">
        <div class="brand-logo">
            <div class="brand-logo-icon">
                <img src="assets/bisu-logo.png" alt="BISU Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-fish\'></i>';">
            </div>
            <div class="brand-logo-text">
                <a href="<?php echo $root_url; ?>index.php" class="flex items-center gap-3 group">
                <strong>BISU IGE</strong>
                <span>Aquaculture System</span>
                </a>
            </div>
        </div>

        <div class="brand-headline">
            <h1>Fresh from the<br><em>source.</em></h1>
            <p>The official platform for BISU's Institute of Graduate Education — harvest tracking, ordering, and fulfillment all in one place.</p>
            <div class="brand-stats">
                <div>
                    <span class="brand-stat-value">5+</span>
                    <span class="brand-stat-label">Fish Species</span>
                </div>
                <div>
                    <span class="brand-stat-value">100%</span>
                    <span class="brand-stat-label">Fresh Harvest</span>
                </div>
                <div>
                    <span class="brand-stat-value">BISU</span>
                    <span class="brand-stat-label">Verified</span>
                </div>
            </div>
        </div>

        <div class="brand-footer">
            &copy; <?php echo date('Y'); ?> Bohol Island State University &mdash; IGE Aquaculture
        </div>

        <div class="deco-fish"><i class="fas fa-fish"></i></div>
    </div>

    <!-- ── Right: Form Panel ── -->
    <div class="form-panel">
        <div class="form-box">
            <div class="form-heading">
                <div class="eyebrow">Secure Access</div>
                <h2>Welcome back</h2>
                <p>Sign in to your BISU IGE account to continue.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" id="flash-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success || $registered): ?>
                <div class="alert alert-success" id="flash-alert">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success ?: 'Registration successful! You can now sign in.'); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect'] ?? ''); ?>">

                <div class="field">
                    <label for="email">Email Address</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope field-icon"></i>
                        <input id="email" name="email" type="email"
                               value="<?php echo htmlspecialchars($email); ?>"
                               placeholder="you@bisu.edu.ph"
                               autocomplete="email" required>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock field-icon"></i>
                        <input id="password" name="password" type="password"
                               placeholder="Enter your password"
                               autocomplete="current-password" required>
                        <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="row-options">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember"> Keep me signed in
                    </label>
                    <a href="pass.php" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                </button>
            </form>

            <div class="divider">or</div>

            <a href="register.php" class="btn-secondary">
                <i class="fas fa-user-plus"></i> Create a new account
            </a>
        </div>
    </div>

</div>
<script>
    function togglePw(fieldId, btn) {
        const input = document.getElementById(fieldId);
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    const flash = document.getElementById('flash-alert');
    if (flash) {
        setTimeout(() => {
            flash.style.transition = 'opacity .5s, transform .5s';
            flash.style.opacity = '0';
            flash.style.transform = 'translateX(-10px)';
            setTimeout(() => flash.remove(), 500);
        }, 6000);
    }
</script>
</body>
</html>