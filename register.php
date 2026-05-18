<?php
// register.php — Professional UI + Improved Validation
require_once 'includes/config.php';
require_once 'includes/session.php';

error_reporting(0);
ini_set('display_errors', 0);

if (SessionManager::isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$errors  = [];
$success = false;

$departments = [
    'College of Engineering and Architecture',
    'College of Education',
    'College of Business Administration',
    'College of Arts and Sciences',
    'College of Technology',
    'College of Fisheries and Aquatic Sciences',
    'College of Agriculture',
    'College of Criminal Justice Education',
    'College of Sciences',
    'College of Nursing and Allied Health Sciences',
    'School of Advanced Studies',
    'Administrative Staff',
    'Faculty Member',
    'Others',
];
$positions = ['Faculty','Staff','Administrator','Researcher','Extension Staff','Others'];

$form = [
    'employee_id'      => '',
    'full_name'        => '',
    'email'            => '',
    'department'       => '',
    'other_department' => '',
    'position'         => '',
    'other_position'   => '',
    'contact_number'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = trim($_POST[$key] ?? '');
    }
    $form['email'] = strtolower($form['email']);
    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms            = isset($_POST['terms']);

    if (empty($form['full_name']) || strlen($form['full_name']) < 3)
        $errors[] = 'Full name must be at least 3 characters.';
    if (!empty($form['employee_id']) && !preg_match('/^[A-Z0-9\-]+$/i', $form['employee_id']))
        $errors[] = 'Employee ID may only contain letters, numbers, and hyphens.';
    if (empty($form['department']))
        $errors[] = 'Please select your department.';

    $final_department = $form['department'];
    if ($form['department'] === 'Others') {
        if (empty($form['other_department']) || strlen($form['other_department']) < 3)
            $errors[] = 'Please specify your department (min 3 chars).';
        else
            $final_department = $form['other_department'];
    }

    if (empty($form['position']))
        $errors[] = 'Please select your position.';

    $final_position = $form['position'];
    if ($form['position'] === 'Others') {
        if (empty($form['other_position']) || strlen($form['other_position']) < 3)
            $errors[] = 'Please specify your position (min 3 chars).';
        else
            $final_position = $form['other_position'];
    }

    if (empty($form['email']) || !filter_var($form['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Please enter a valid email address.';
    if (!empty($form['contact_number']) && !preg_match('/^[0-9+\-\s]{10,15}$/', $form['contact_number']))
        $errors[] = 'Contact number must be 10-15 digits.';
    if (empty($password) || strlen($password) < 6)
        $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm_password)
        $errors[] = 'Passwords do not match.';
    if (!$terms)
        $errors[] = 'You must agree to the Terms of Service and Privacy Policy.';

    if (empty($errors)) {
        try {
            $db = (new Database())->getConnection();

            $chk = $db->prepare("SELECT user_id FROM users WHERE email = :email");
            $chk->execute([':email' => $form['email']]);
            if ($chk->rowCount() > 0) {
                $errors[] = 'That email is already registered. Please sign in instead.';
            }

            if (empty($errors) && !empty($form['employee_id'])) {
                $chk2 = $db->prepare("SELECT user_id FROM users WHERE employee_id = :eid");
                $chk2->execute([':eid' => $form['employee_id']]);
                if ($chk2->rowCount() > 0)
                    $errors[] = 'That Employee ID is already registered.';
            }

            if (empty($errors)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $ins = $db->prepare("
                    INSERT INTO users
                        (employee_id, full_name, department, position, contact_number,
                         email, role, hashed_password, created_at, updated_at)
                    VALUES
                        (:employee_id, :full_name, :department, :position, :contact_number,
                         :email, 'standard', :hashed_password, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    RETURNING user_id
                ");
                $ins->execute([
                    ':employee_id'     => !empty($form['employee_id']) ? $form['employee_id'] : null,
                    ':full_name'       => $form['full_name'],
                    ':department'      => $final_department,
                    ':position'        => $final_position,
                    ':contact_number'  => !empty($form['contact_number']) ? $form['contact_number'] : null,
                    ':email'           => $form['email'],
                    ':hashed_password' => $hashed,
                ]);
                $result = $ins->fetch(PDO::FETCH_ASSOC);
                if ($result && isset($result['user_id'])) {
                    try {
                        $fn = new SystemFunctions();
                        if (method_exists($fn, 'createNotification')) {
                            $fn->createNotification($result['user_id'], 'welcome',
                                'Welcome to BISU IGE Aquaculture!',
                                'You can now browse and order fresh harvests from our aquaculture facility.', null);
                        }
                    } catch (Exception $e) { error_log("Welcome notif failed: " . $e->getMessage()); }
                    $success = true;
                    foreach ($form as $k => $v) $form[$k] = '';
                } else {
                    $errors[] = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            error_log("Registration PDO error: " . $e->getMessage());
            if (strpos($e->getMessage(), '23505') !== false || stripos($e->getMessage(), 'duplicate') !== false)
                $errors[] = 'Email address is already registered.';
            else
                $errors[] = 'A database error occurred. Please try again.';
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors[] = 'An unexpected error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account - BISU IGE Aquaculture</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
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
}
html,body{min-height:100%;font-family:'Sora',sans-serif;background:linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);}
.page{display:grid;grid-template-columns:1fr 1.4fr;min-height:100vh}

/* Brand panel - Dark accent on left */
.brand-panel{
  position:sticky;top:0;height:100vh;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
  display:flex;flex-direction:column;
  justify-content:space-between;padding:3rem;overflow:hidden;
}
.brand-panel::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 50% at 20% 80%,rgba(14,165,233,.15) 0%,transparent 70%);pointer-events:none}
.brand-panel::after{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:48px 48px;pointer-events:none}
.brand-logo{display:flex;align-items:center;gap:14px;position:relative;z-index:1}
.brand-logo-icon{width:48px;height:48px;background:var(--primary);border-radius:12px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.brand-logo-icon img{width:100%;height:100%;object-fit:cover}
.brand-logo-text strong{display:block;font-size:18px;font-weight:700;letter-spacing:.04em;color:white}
.brand-logo-text span{font-size:11px;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase}
.brand-headline{position:relative;z-index:1}
.brand-headline h1{font-family:'DM Serif Display',serif;font-size:clamp(2rem,3vw,3rem);line-height:1.12;margin-bottom:1.2rem;color:white}
.brand-headline h1 em{font-style:normal;color:var(--primary)}
.brand-headline p{font-size:15px;color:#94a3b8;line-height:1.7}
.steps{margin-top:2.5rem;display:flex;flex-direction:column;gap:18px;position:relative;z-index:1}
.step{display:flex;align-items:flex-start;gap:14px}
.step-num{width:28px;height:28px;border-radius:50%;background:rgba(14,165,233,.15);border:1.5px solid var(--primary);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:1px}
.step-text strong{display:block;font-size:13px;color:white;font-weight:600}
.step-text span{font-size:12px;color:#94a3b8}
.brand-footer{font-size:12px;color:#64748b;position:relative;z-index:1}
.deco-fish{position:absolute;right:-80px;bottom:5%;font-size:260px;color:rgba(14,165,233,.08);pointer-events:none;z-index:0;animation:sway 8s ease-in-out infinite}
@keyframes sway{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(2deg)}}

/* Form panel - Light mode */
.form-panel{background:white;display:flex;align-items:flex-start;justify-content:center;padding:3rem 2.5rem;position:relative}
.form-panel::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:linear-gradient(to bottom,transparent,var(--primary),transparent)}
.form-box{width:100%;max-width:540px;animation:fadeUp .45s ease-out both}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.form-heading{margin-bottom:2rem}
.eyebrow{font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--primary);font-weight:600;margin-bottom:10px}
.form-heading h2{font-family:'DM Serif Display',serif;font-size:2rem;line-height:1.1;color:var(--text-primary)}
.form-heading p{font-size:14px;color:var(--text-secondary);margin-top:8px}

.form-section{margin-bottom:1.8rem}
.section-label{font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);padding-bottom:10px;border-bottom:2px solid var(--primary-light);margin-bottom:1rem;display:flex;align-items:center;gap:8px}
.section-label i{font-size:12px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}

.alert{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-radius:var(--radius);font-size:13.5px;margin-bottom:1.4rem;border:1px solid transparent;animation:slideIn .3s ease-out}
@keyframes slideIn{from{opacity:0;transform:translateX(-10px)}to{opacity:1;transform:translateX(0)}}
.alert-error{background:var(--danger-light);border-color:#fecaca;color:#991b1b}
.alert-success{background:var(--success-light);border-color:#a7f3d0;color:#065f46}
.alert-list{margin-top:6px;padding-left:16px;font-size:13px}
.alert-list li{margin-bottom:3px}
.alert>i{margin-top:2px;flex-shrink:0}

.field{margin-bottom:1rem}
.field label{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--text-secondary);margin-bottom:7px}
.optional{font-size:10px;color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0}
.req{color:var(--danger)}
.input-wrap{position:relative}
.field-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;transition:color .2s}
.input-wrap:focus-within .field-icon{color:var(--primary)}
.input-wrap input,.input-wrap select{width:100%;background:var(--bg-light);border:1.5px solid var(--border-light);border-radius:var(--radius);color:var(--text-primary);font-family:'Sora',sans-serif;font-size:14px;padding:11px 13px 11px 40px;outline:none;transition:border-color .2s,box-shadow .2s;-webkit-appearance:none;appearance:none}
.input-wrap input::placeholder{color:#94a3b8}
.input-wrap input:focus,.input-wrap select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(14,165,233,.15);background:white}
.input-wrap select option{background:white;color:var(--text-primary)}
.is-select{position:relative}
.is-select::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none}
.other-field{overflow:hidden;max-height:0;opacity:0;transition:max-height .3s ease,opacity .3s ease,margin .3s ease}
.other-field.show{max-height:80px;opacity:1;margin-top:.6rem}
.hint{font-size:12px;color:var(--text-muted);margin-top:5px}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;padding:4px;transition:color .2s}
.toggle-pw:hover{color:var(--primary)}
.pw-match{font-size:12px;margin-top:5px}
.pw-match.ok{color:var(--success)}
.pw-match.fail{color:var(--danger)}
.pw-strength{margin-top:7px}
.pw-strength-bar{height:4px;border-radius:4px;background:var(--border-light);overflow:hidden}
.pw-strength-fill{height:100%;border-radius:4px;transition:width .3s,background .3s;width:0}
.pw-strength-label{font-size:11px;color:var(--text-muted);margin-top:4px}
.terms-box{background:var(--primary-light);border:1px solid #bae6fd;border-radius:var(--radius);padding:14px 16px;display:flex;align-items:flex-start;gap:12px;margin-bottom:1.4rem}
.terms-box input[type="checkbox"]{width:16px;height:16px;accent-color:var(--primary);flex-shrink:0;margin-top:2px;cursor:pointer}
.terms-box label{font-size:13px;color:var(--text-secondary);cursor:pointer;line-height:1.5}
.terms-box label a{color:var(--primary);text-decoration:none}
.terms-box label a:hover{color:var(--primary-dark);text-decoration:underline}
.btn-primary{width:100%;padding:13px;background:linear-gradient(135deg, var(--primary), var(--primary-dark));border:none;border-radius:var(--radius);color:white;font-family:'Sora',sans-serif;font-size:15px;font-weight:700;letter-spacing:.04em;cursor:pointer;transition:transform .15s,box-shadow .2s;box-shadow:0 4px 14px rgba(14,165,233,.3);display:flex;align-items:center;justify-content:center;gap:9px}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(14,165,233,.4)}
.btn-primary:active{transform:translateY(0)}
.signin-link{text-align:center;margin-top:1.4rem;font-size:14px;color:var(--text-secondary)}
.signin-link a{color:var(--primary);text-decoration:none;font-weight:600}
.signin-link a:hover{color:var(--primary-dark);text-decoration:underline}
.brand-logo-text a {text-decoration: none; color: inherit;}
/* Success */
.success-card{text-align:center;padding:3rem 1rem}
.success-icon{width:72px;height:72px;background:var(--success-light);border:2px solid var(--success);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;color:var(--success);font-size:28px}
.success-card h3{font-family:'DM Serif Display',serif;font-size:1.8rem;margin-bottom:.7rem;color:var(--text-primary)}
.success-card p{font-size:14px;color:var(--text-secondary);line-height:1.7;margin-bottom:2rem}
.progress-bar-wrap{background:var(--border-light);border-radius:4px;height:6px;overflow:hidden;margin-bottom:1rem}
.progress-bar-fill{height:100%;background:linear-gradient(90deg, var(--primary), var(--primary-dark));border-radius:4px;width:0;transition:width 1s linear}
.redirect-note{font-size:13px;color:var(--text-muted)}
.redirect-note a{color:var(--primary);text-decoration:none}

@media(max-width:960px){
  .page{grid-template-columns:1fr}
  .brand-panel{display:none}
  .form-panel::before{display:none}
  .form-panel{padding:2rem 1.25rem}
  .field-row{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="page">

<!-- Brand panel -->
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
    <h1>Join the<br><em>community.</em></h1>
    <p>Create your BISU IGE account and get access to fresh aquaculture harvests, order tracking, and real-time notifications.</p>
    <div class="steps">
      <div class="step"><div class="step-num">1</div><div class="step-text"><strong>Fill in your details</strong><span>Basic info to verify your BISU affiliation</span></div></div>
      <div class="step"><div class="step-num">2</div><div class="step-text"><strong>Start ordering</strong><span>Browse harvests and place orders immediately</span></div></div>
      <div class="step"><div class="step-num">3</div><div class="step-text"><strong>Track your orders</strong><span>Monitor the status of your deliveries</span></div></div>
    </div>
  </div>
  <div class="brand-footer">&copy; <?php echo date('Y'); ?> Bohol Island State University &mdash; IGE Aquaculture</div>
  <div class="deco-fish"><i class="fas fa-fish"></i></div>
</div>

<!-- Form panel -->
<div class="form-panel">
<div class="form-box">

<?php if ($success): ?>
<div class="success-card">
  <div class="success-icon"><i class="fas fa-check"></i></div>
  <h3>Account Created!</h3>
  <p>Your BISU IGE account has been successfully created. You'll be redirected to the sign-in page shortly.</p>
  <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progress-fill"></div></div>
  <div class="redirect-note">Redirecting in <strong id="countdown">4</strong>s &nbsp;&middot;&nbsp; <a href="login.php?registered=success">Sign in now</a></div>
</div>
<script>
let s=4;
const fill=document.getElementById('progress-fill');
const cd=document.getElementById('countdown');
setTimeout(()=>fill.style.width='100%',50);
const t=setInterval(()=>{s--;cd.textContent=s;if(s<=0){clearInterval(t);window.location.href='login.php?registered=success';}},1000);
</script>

<?php else: ?>

<div class="form-heading">
  <div class="eyebrow">New Account</div>
  <h2>Create your account</h2>
  <p>Register with your BISU credentials to get started.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
  <i class="fas fa-exclamation-triangle"></i>
  <div><strong>Please fix the following:</strong>
    <ul class="alert-list">
      <?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<form method="POST" action="" novalidate onsubmit="return clientValidate()">

  <!-- Personal Info -->
  <div class="form-section">
    <div class="section-label"><i class="fas fa-user"></i> Personal Information</div>
    <div class="field-row">
      <div class="field">
        <label for="full_name">Full Name <span class="req">*</span></label>
        <div class="input-wrap">
          <i class="fas fa-user field-icon"></i>
          <input id="full_name" name="full_name" type="text" required
                 value="<?php echo htmlspecialchars($form['full_name']); ?>"
                 placeholder="Juan Dela Cruz">
        </div>
      </div>
      <div class="field">
        <label for="employee_id">Employee ID <span class="optional">(optional)</span></label>
        <div class="input-wrap">
          <i class="fas fa-id-badge field-icon"></i>
          <input id="employee_id" name="employee_id" type="text"
                 value="<?php echo htmlspecialchars($form['employee_id']); ?>"
                 placeholder="BISU-2024-001">
        </div>
      </div>
    </div>
    <div class="field">
      <label for="contact_number">Contact Number <span class="optional">(optional)</span></label>
      <div class="input-wrap">
        <i class="fas fa-phone field-icon"></i>
        <input id="contact_number" name="contact_number" type="tel"
               value="<?php echo htmlspecialchars($form['contact_number']); ?>"
               placeholder="09123456789">
      </div>
    </div>
  </div>

  <!-- Affiliation -->
  <div class="form-section">
    <div class="section-label"><i class="fas fa-university"></i> BISU Affiliation</div>
    <div class="field-row">
      <div class="field">
        <label for="department">Department <span class="req">*</span></label>
        <div class="input-wrap is-select">
          <i class="fas fa-building field-icon"></i>
          <select id="department" name="department" required onchange="toggleOther('department')">
            <option value="" disabled <?php echo empty($form['department'])?'selected':''; ?>>Select department</option>
            <?php foreach($departments as $d): ?>
            <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $form['department']===$d?'selected':''; ?>><?php echo htmlspecialchars($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="otherDeptField" class="other-field <?php echo $form['department']==='Others'?'show':''; ?>">
          <div class="input-wrap" style="margin-top:.5rem">
            <i class="fas fa-pen field-icon"></i>
            <input id="other_department" name="other_department" type="text"
                   value="<?php echo htmlspecialchars($form['other_department']); ?>"
                   placeholder="Specify department" <?php echo $form['department']==='Others'?'required':''; ?>>
          </div>
        </div>
      </div>
      <div class="field">
        <label for="position">Position <span class="req">*</span></label>
        <div class="input-wrap is-select">
          <i class="fas fa-briefcase field-icon"></i>
          <select id="position" name="position" required onchange="toggleOther('position')">
            <option value="" disabled <?php echo empty($form['position'])?'selected':''; ?>>Select position</option>
            <?php foreach($positions as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $form['position']===$p?'selected':''; ?>><?php echo htmlspecialchars($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="otherPosField" class="other-field <?php echo $form['position']==='Others'?'show':''; ?>">
          <div class="input-wrap" style="margin-top:.5rem">
            <i class="fas fa-pen field-icon"></i>
            <input id="other_position" name="other_position" type="text"
                   value="<?php echo htmlspecialchars($form['other_position']); ?>"
                   placeholder="Specify position" <?php echo $form['position']==='Others'?'required':''; ?>>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Credentials -->
  <div class="form-section">
    <div class="section-label"><i class="fas fa-lock"></i> Account Credentials</div>
    <div class="field">
      <label for="email">Email Address <span class="req">*</span></label>
      <div class="input-wrap">
        <i class="fas fa-envelope field-icon"></i>
        <input id="email" name="email" type="email" required
               value="<?php echo htmlspecialchars($form['email']); ?>"
               placeholder="you@bisu.edu.ph" autocomplete="email">
      </div>
      <div class="hint">Order notifications are sent to this email.</div>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="password">Password <span class="req">*</span></label>
        <div class="input-wrap">
          <i class="fas fa-lock field-icon"></i>
          <input id="password" name="password" type="password" required placeholder="Min. 6 characters" minlength="6"
                 oninput="checkStrength(this.value);checkMatch()">
          <button type="button" class="toggle-pw" onclick="togglePw('password',this)"><i class="fas fa-eye"></i></button>
        </div>
        <div class="pw-strength">
          <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-fill"></div></div>
          <div class="pw-strength-label" id="pw-label"></div>
        </div>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm Password <span class="req">*</span></label>
        <div class="input-wrap">
          <i class="fas fa-lock field-icon"></i>
          <input id="confirm_password" name="confirm_password" type="password" required placeholder="Re-enter password" minlength="6"
                 oninput="checkMatch()">
          <button type="button" class="toggle-pw" onclick="togglePw('confirm_password',this)"><i class="fas fa-eye"></i></button>
        </div>
        <div class="pw-match" id="pw-match"></div>
      </div>
    </div>
  </div>

  <div class="terms-box">
    <input type="checkbox" id="terms" name="terms" required>
    <label for="terms">I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a> of BISU IGE Aquaculture System.</label>
  </div>

  <button type="submit" class="btn-primary"><i class="fas fa-user-check"></i> Create Account</button>
  <div class="signin-link">Already have an account? <a href="login.php">Sign in</a></div>
</form>
<?php endif; ?>
</div>
</div>
</div>

<script>
function toggleOther(type){
  const sel=document.getElementById(type);
  const box=document.getElementById(type==='department'?'otherDeptField':'otherPosField');
  const inp=box.querySelector('input');
  if(sel.value==='Others'){box.classList.add('show');inp.setAttribute('required','');}
  else{box.classList.remove('show');inp.removeAttribute('required');inp.value='';}
}
document.addEventListener('DOMContentLoaded',()=>{toggleOther('department');toggleOther('position');});

function togglePw(id,btn){
  const inp=document.getElementById(id);
  const icon=btn.querySelector('i');
  if(inp.type==='password'){inp.type='text';icon.classList.replace('fa-eye','fa-eye-slash');}
  else{inp.type='password';icon.classList.replace('fa-eye-slash','fa-eye');}
}

function checkStrength(val){
  const fill=document.getElementById('pw-fill');
  const lbl=document.getElementById('pw-label');
  if(!val){fill.style.width='0';lbl.textContent='';return;}
  let s=0;
  if(val.length>=6)s++;if(val.length>=10)s++;
  if(/[A-Z]/.test(val))s++;if(/[0-9]/.test(val))s++;if(/[^A-Za-z0-9]/.test(val))s++;
  const m=[{w:'20%',c:'#ef4444',t:'Very weak'},{w:'40%',c:'#f97316',t:'Weak'},{w:'60%',c:'#eab308',t:'Fair'},{w:'80%',c:'#10b981',t:'Good'},{w:'100%',c:'#059669',t:'Strong'}];
  const r=m[Math.min(s-1,4)];
  fill.style.width=r.w;fill.style.background=r.c;lbl.textContent=r.t;lbl.style.color=r.c;
}

function checkMatch(){
  const pw=document.getElementById('password').value;
  const cpw=document.getElementById('confirm_password').value;
  const el=document.getElementById('pw-match');
  if(!cpw){el.textContent='';return;}
  if(pw===cpw){el.textContent='✓ Passwords match';el.className='pw-match ok';}
  else{el.textContent='✗ Passwords do not match';el.className='pw-match fail';}
}

function clientValidate(){
  const pw=document.getElementById('password').value;
  const cpw=document.getElementById('confirm_password').value;
  if(pw.length<6){alert('Password must be at least 6 characters.');return false;}
  if(pw!==cpw){alert('Passwords do not match.');return false;}
  if(!document.getElementById('terms').checked){alert('You must agree to the Terms of Service.');return false;}
  return true;
}

document.getElementById('contact_number')?.addEventListener('input',function(){this.value=this.value.replace(/[^0-9+\-\s]/g,'');});
</script>
</body>
</html>