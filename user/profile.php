<?php
// user/profile.php
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow standard users
SessionManager::requireStandard();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();

// Get user data
$user = $functions->getUserById($userId);

if (!$user) {
    SessionManager::setMessage('User not found.', 'error');
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Update Profile Information
    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $department = trim($_POST['department'] ?? '');

        // Validation
        if (empty($firstName)) {
            $error = 'First name is required.';
        } elseif (empty($lastName)) {
            $error = 'Last name is required.';
        } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $firstName)) {
            $error = 'First name can only contain letters, spaces, hyphens, and apostrophes.';
        } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $lastName)) {
            $error = 'Last name can only contain letters, spaces, hyphens, and apostrophes.';
        } elseif (!empty($contactNumber) && !preg_match("/^[0-9+\-\s]{10,15}$/", $contactNumber)) {
            $error = 'Please enter a valid contact number (10-15 digits).';
        } else {
            try {
                $db = (new Database())->getConnection();
                $sql = "UPDATE users SET 
                            first_name = :first_name,
                            last_name = :last_name,
                            contact_number = :contact_number,
                            department = :department,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE user_id = :user_id";

                $stmt = $db->prepare($sql);
                $result = $stmt->execute([
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':contact_number' => !empty($contactNumber) ? $contactNumber : null,
                    ':department' => !empty($department) ? $department : null,
                    ':user_id' => $userId
                ]);

                if ($result) {
                    // Update session data
                    $_SESSION['user']['first_name'] = $firstName;
                    $_SESSION['user']['last_name'] = $lastName;

                    // Create notification
                    $functions->createNotification(
                        $userId,
                        'profile',
                        'Profile Updated',
                        'Your profile information has been successfully updated.',
                        null
                    );

                    $success = 'Profile updated successfully!';

                    // Refresh user data
                    $user = $functions->getUserById($userId);
                } else {
                    $error = 'Failed to update profile. Please try again.';
                }
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error = 'An error occurred while updating your profile.';
            }
        }
    }

    // Change Password
    elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($currentPassword)) {
            $error = 'Current password is required.';
        } elseif (empty($newPassword)) {
            $error = 'New password is required.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } else {
            try {
                $db = (new Database())->getConnection();

                // Verify current password
                $sql = "SELECT password_hash FROM users WHERE user_id = :user_id";
                $stmt = $db->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userData || !password_verify($currentPassword, $userData['password_hash'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    // Update password
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateSql = "UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
                    $updateStmt = $db->prepare($updateSql);
                    $result = $updateStmt->execute([
                        ':password_hash' => $newHash,
                        ':user_id' => $userId
                    ]);

                    if ($result) {
                        // Create notification
                        $functions->createNotification(
                            $userId,
                            'password',
                            'Password Changed',
                            'Your password has been successfully changed.',
                            null
                        );

                        $success = 'Password changed successfully!';
                    } else {
                        $error = 'Failed to change password. Please try again.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Password change error: " . $e->getMessage());
                $error = 'An error occurred while changing your password.';
            }
        }
    }

    // Delete Account
    elseif ($action === 'delete_account') {
        $deletePassword = $_POST['delete_password'] ?? '';
        $confirmDelete = $_POST['confirm_delete'] ?? '';

        if (empty($deletePassword)) {
            $error = 'Please enter your password to confirm account deletion.';
        } elseif ($confirmDelete !== 'DELETE') {
            $error = 'Please type DELETE in the confirmation field to proceed.';
        } else {
            try {
                $db = (new Database())->getConnection();

                // Verify password
                $sql = "SELECT password_hash FROM users WHERE user_id = :user_id";
                $stmt = $db->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userData || !password_verify($deletePassword, $userData['password_hash'])) {
                    $error = 'Password is incorrect. Account deletion cancelled.';
                } else {
                    // Anonymize user data instead of hard delete
                    $anonymizeSql = "UPDATE users SET 
                        email = CONCAT('deleted_', user_id, '_', UNIX_TIMESTAMP(), '@deleted.local'),
                        first_name = 'Deleted',
                        last_name = 'User',
                        contact_number = NULL,
                        department = NULL,
                        profile_picture = NULL,
                        status = 'deleted',
                        password_hash = '',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = :user_id";

                    $anonymizeStmt = $db->prepare($anonymizeSql);
                    $result = $anonymizeStmt->execute([':user_id' => $userId]);

                    if ($result) {
                        // Log deletion
                        $logSql = "INSERT INTO deletion_logs (user_id, deleted_at, reason) VALUES (:user_id, CURRENT_TIMESTAMP, 'User requested')";
                        $logStmt = $db->prepare($logSql);
                        $logStmt->execute([':user_id' => $userId]);

                        // Destroy session
                        SessionManager::destroy();

                        header('Location: ../index.php?deleted=1');
                        exit();
                    } else {
                        $error = 'Failed to delete account. Please try again.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Account deletion error: " . $e->getMessage());
                $error = 'An error occurred while deleting your account.';
            }
        }
    }
}

// Get user statistics
try {
    $db = (new Database())->getConnection();

    // Total reservations
    $reservationsSql = "SELECT COUNT(*) as total FROM reservations WHERE user_id = :user_id";
    $reservationsStmt = $db->prepare($reservationsSql);
    $reservationsStmt->execute([':user_id' => $userId]);
    $totalReservations = $reservationsStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total spent
    $spentSql = "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE user_id = :user_id AND payment_status = 'paid'";
    $spentStmt = $db->prepare($spentSql);
    $spentStmt->execute([':user_id' => $userId]);
    $totalSpent = $spentStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Member since
    $memberSince = date('F Y', strtotime($user['created_at']));

} catch (PDOException $e) {
    error_log("Profile stats error: " . $e->getMessage());
    $totalReservations = 0;
    $totalSpent = 0;
    $memberSince = 'Unknown';
}

// Default profile picture
$profilePicture = $user['profile_picture'] ?? '';
if (empty($profilePicture)) {
    $defaultSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd"/></svg>';
    $profilePicture = 'data:image/svg+xml;base64,' . base64_encode($defaultSvg);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                        'playfair': ['Playfair Display', 'serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
        }
        .font-playfair { font-family: 'Playfair Display', serif; }

        .profile-card {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .profile-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(2, 132, 199, 0.15);
        }

        .tab-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .tab-btn::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: #0284c7;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform: translateX(-50%);
        }
        .tab-btn.active::after {
            width: 60%;
        }
        .tab-btn.active {
            background-color: #f0f9ff;
            color: #0284c7;
        }

        .form-input {
            transition: all 0.2s ease;
        }
        .form-input:focus {
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        .password-strength-meter {
            height: 4px;
            transition: all 0.3s ease;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .btn-primary {
            background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%);
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            transition: all 0.3s ease;
        }
        .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%);
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -5px rgba(220, 38, 38, 0.3);
        }

        .quick-link {
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .quick-link:hover {
            background: #f0f9ff;
            border-color: #bae6fd;
            transform: translateX(4px);
        }

        .delete-section {
            background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
            border: 1px solid #fecaca;
        }

        .modal-overlay {
            backdrop-filter: blur(4px);
        }

        .section-header {
            position: relative;
            padding-left: 1rem;
        }
        .section-header::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: linear-gradient(180deg, #0284c7 0%, #0ea5e9 100%);
            border-radius: 2px;
        }

        .section-header-danger::before {
            background: linear-gradient(180deg, #dc2626 0%, #ef4444 100%);
        }
    </style>
</head>
<body class="bg-slate-50 font-inter">
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message = SessionManager::getMessage()): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="rounded-lg p-4 <?php echo $message['type'] == 'success' ? 'bg-emerald-50 border border-emerald-200' : ($message['type'] == 'error' ? 'bg-red-50 border border-red-200' : 'bg-brand-50 border border-brand-200'); ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas <?php echo $message['type'] == 'success' ? 'fa-check-circle text-emerald-500' : ($message['type'] == 'error' ? 'fa-exclamation-circle text-red-500' : 'fa-info-circle text-brand-500'); ?>"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?php echo $message['type'] == 'success' ? 'text-emerald-800' : ($message['type'] == 'error' ? 'text-red-800' : 'text-brand-800'); ?>">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </p>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="rounded-lg p-4 bg-red-50 border border-red-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="rounded-lg p-4 bg-emerald-50 border border-emerald-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-emerald-800"><?php echo htmlspecialchars($success); ?></p>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="relative bg-gradient-to-br from-brand-800 via-brand-700 to-brand-600 py-12 overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <svg class="h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                <path d="M0 100 C 20 0 50 0 100 100 Z" fill="white"/>
            </svg>
        </div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-brand-200 text-sm font-medium tracking-wider uppercase mb-1">Account Management</p>
                    <h1 class="text-3xl font-playfair font-bold text-white">My Profile</h1>
                    <p class="text-brand-100 mt-2 text-sm">Manage your account information, security settings, and preferences</p>
                </div>
                <div class="mt-6 md:mt-0">
                    <a href="dashboard.php" class="inline-flex items-center px-5 py-2.5 bg-white/10 backdrop-blur-sm text-white rounded-lg font-medium hover:bg-white/20 transition border border-white/20">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

            <!-- Left Sidebar - Profile Summary -->
            <div class="lg:col-span-4 space-y-6">
                <!-- Profile Card -->
                <div class="profile-card bg-white rounded-xl shadow-sm overflow-hidden">
                    <div class="bg-gradient-to-br from-brand-600 to-brand-800 h-28 relative">
                        <div class="absolute inset-0 opacity-20">
                            <svg class="h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                                <path d="M0 100 C 30 20 70 20 100 100 Z" fill="white"/>
                            </svg>
                        </div>
                    </div>
                    <div class="px-6 pb-6">
                        <div class="relative -mt-14 mb-5">
                            <div class="w-28 h-28 rounded-full bg-white p-1.5 shadow-xl mx-auto ring-4 ring-white">
                                <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="w-full h-full rounded-full object-cover bg-slate-100">
                            </div>
                        </div>
                        <div class="text-center">
                            <h2 class="text-xl font-bold text-slate-900 font-playfair">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </h2>
                            <p class="text-sm text-slate-500 mt-1"><?php echo htmlspecialchars($user['email']); ?></p>
                            <span class="inline-flex items-center px-3 py-1 mt-3 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">
                                <i class="fas fa-user mr-1.5 text-xs"></i>Standard User
                            </span>
                        </div>

                        <div class="mt-6 pt-6 border-t border-slate-100">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="stat-card rounded-lg p-4 text-center">
                                    <p class="text-2xl font-bold text-slate-900"><?php echo $totalReservations; ?></p>
                                    <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mt-1">Reservations</p>
                                </div>
                                <div class="stat-card rounded-lg p-4 text-center">
                                    <p class="text-2xl font-bold text-slate-900">₱<?php echo number_format($totalSpent, 0); ?></p>
                                    <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mt-1">Total Spent</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 pt-6 border-t border-slate-100">
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-slate-500 flex items-center">
                                        <i class="fas fa-calendar-alt mr-2 text-slate-400 w-4"></i>Member Since
                                    </span>
                                    <span class="font-semibold text-slate-800"><?php echo $memberSince; ?></span>
                                </div>
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-slate-500 flex items-center">
                                        <i class="fas fa-building mr-2 text-slate-400 w-4"></i>Department
                                    </span>
                                    <span class="font-semibold text-slate-800"><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></span>
                                </div>
                                <?php if (!empty($user['last_login'])): ?>
                                <div class="flex justify-between items-center py-1">
                                    <span class="text-slate-500 flex items-center">
                                        <i class="fas fa-clock mr-2 text-slate-400 w-4"></i>Last Login
                                    </span>
                                    <span class="font-semibold text-slate-800"><?php echo date('M d, Y', strtotime($user['last_login'])); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <h3 class="text-base font-semibold text-slate-900 mb-4 flex items-center">
                        <i class="fas fa-link text-brand-500 mr-2 text-sm"></i>Quick Links
                    </h3>
                    <div class="space-y-2">
                        <a href="reservations.php" class="quick-link flex items-center p-3 rounded-lg text-slate-700 hover:text-brand-700">
                            <div class="w-9 h-9 rounded-lg bg-brand-50 flex items-center justify-center mr-3">
                                <i class="fas fa-calendar-check text-brand-600 text-sm"></i>
                            </div>
                            <span class="text-sm font-medium">My Reservations</span>
                            <i class="fas fa-chevron-right ml-auto text-xs text-slate-400"></i>
                        </a>
                        <a href="products.php" class="quick-link flex items-center p-3 rounded-lg text-slate-700 hover:text-brand-700">
                            <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center mr-3">
                                <i class="fas fa-fish text-emerald-600 text-sm"></i>
                            </div>
                            <span class="text-sm font-medium">Browse Fish</span>
                            <i class="fas fa-chevron-right ml-auto text-xs text-slate-400"></i>
                        </a>
                        <a href="returns.php" class="quick-link flex items-center p-3 rounded-lg text-slate-700 hover:text-brand-700">
                            <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center mr-3">
                                <i class="fas fa-undo-alt text-amber-600 text-sm"></i>
                            </div>
                            <span class="text-sm font-medium">Return Requests</span>
                            <i class="fas fa-chevron-right ml-auto text-xs text-slate-400"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Content - Forms -->
            <div class="lg:col-span-8 space-y-6">

                <!-- Tab Navigation -->
                <div class="bg-white rounded-xl shadow-sm p-2">
                    <div class="flex space-x-1">
                        <button onclick="switchTab('profile')" id="tab-profile" class="tab-btn active flex-1 px-4 py-3 rounded-lg text-sm font-semibold transition text-center">
                            <i class="fas fa-user-edit mr-2"></i>Edit Profile
                        </button>
                        <button onclick="switchTab('password')" id="tab-password" class="tab-btn flex-1 px-4 py-3 rounded-lg text-sm font-semibold transition text-center bg-slate-50 text-slate-600 hover:bg-slate-100">
                            <i class="fas fa-lock mr-2"></i>Change Password
                        </button>
                        <button onclick="switchTab('delete')" id="tab-delete" class="tab-btn flex-1 px-4 py-3 rounded-lg text-sm font-semibold transition text-center bg-slate-50 text-slate-600 hover:bg-slate-100">
                            <i class="fas fa-trash-alt mr-2"></i>Delete Account
                        </button>
                    </div>
                </div>

                <!-- Edit Profile Tab -->
                <div id="content-profile" class="bg-white rounded-xl shadow-sm p-8">
                    <h2 class="text-lg font-semibold text-slate-900 mb-6 flex items-center section-header">
                        <i class="fas fa-user-edit text-brand-500 mr-3"></i>
                        Edit Profile Information
                    </h2>

                    <form method="POST" action="" class="space-y-6">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- First Name -->
                            <div>
                                <label for="first_name" class="block text-sm font-semibold text-slate-700 mb-2">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-slate-400 text-sm"></i>
                                    </div>
                                    <input type="text" id="first_name" name="first_name" required
                                           value="<?php echo htmlspecialchars($user['first_name']); ?>"
                                           class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                           pattern="[A-Za-z\s'-]+"
                                           title="First name can only contain letters, spaces, hyphens, and apostrophes">
                                </div>
                            </div>

                            <!-- Last Name -->
                            <div>
                                <label for="last_name" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-slate-400 text-sm"></i>
                                    </div>
                                    <input type="text" id="last_name" name="last_name" required
                                           value="<?php echo htmlspecialchars($user['last_name']); ?>"
                                           class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                           pattern="[A-Za-z\s'-]+"
                                           title="Last name can only contain letters, spaces, hyphens, and apostrophes">
                                </div>
                            </div>
                        </div>

                        <!-- Email (Read-only) -->
                        <div>
                            <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">
                                Email Address
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-envelope text-slate-400 text-sm"></i>
                                </div>
                                <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                                       class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg bg-slate-50 text-slate-500 cursor-not-allowed"
                                       readonly>
                            </div>
                            <p class="text-xs text-slate-500 mt-1.5 flex items-center">
                                <i class="fas fa-info-circle mr-1"></i>Email address cannot be changed. Contact support if needed.
                            </p>
                        </div>

                        <!-- Department -->
                        <div>
                            <label for="department" class="block text-sm font-semibold text-slate-700 mb-2">
                                Department / College
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-university text-slate-400 text-sm"></i>
                                </div>
                                <input type="text" id="department" name="department"
                                       value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>"
                                       class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                       placeholder="e.g. College of Fisheries">
                            </div>
                        </div>

                        <!-- Contact Number -->
                        <div>
                            <label for="contact_number" class="block text-sm font-semibold text-slate-700 mb-2">
                                Contact Number
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-phone text-slate-400 text-sm"></i>
                                </div>
                                <input type="tel" id="contact_number" name="contact_number"
                                       value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>"
                                       class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                       placeholder="09123456789"
                                       pattern="[0-9+\-\s]{10,15}"
                                       title="Please enter a valid contact number (10-15 digits)">
                            </div>
                            <p class="text-xs text-slate-500 mt-1.5">Optional, but recommended for order notifications</p>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-slate-100">
                            <button type="submit" 
                                    class="btn-primary flex items-center px-8 py-3 text-white rounded-lg font-semibold shadow-lg shadow-brand-500/20">
                                <i class="fas fa-save mr-2"></i>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Tab (Hidden by default) -->
                <div id="content-password" class="hidden bg-white rounded-xl shadow-sm p-8">
                    <h2 class="text-lg font-semibold text-slate-900 mb-6 flex items-center section-header">
                        <i class="fas fa-lock text-brand-500 mr-3"></i>
                        Change Password
                    </h2>

                    <form method="POST" action="" class="space-y-6" onsubmit="return validatePasswordForm()">
                        <input type="hidden" name="action" value="change_password">

                        <!-- Current Password -->
                        <div>
                            <label for="current_password" class="block text-sm font-semibold text-slate-700 mb-2">
                                Current Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-slate-400 text-sm"></i>
                                </div>
                                <input type="password" id="current_password" name="current_password" required
                                       class="form-input w-full pl-10 pr-10 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                       placeholder="Enter your current password">
                                <button type="button" onclick="togglePassword('current_password')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center focus:outline-none">
                                    <i class="fas fa-eye text-slate-400 hover:text-slate-600 cursor-pointer transition" id="toggle-current_password"></i>
                                </button>
                            </div>
                        </div>

                        <!-- New Password -->
                        <div>
                            <label for="new_password" class="block text-sm font-semibold text-slate-700 mb-2">
                                New Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-key text-slate-400 text-sm"></i>
                                </div>
                                <input type="password" id="new_password" name="new_password" required
                                       class="form-input w-full pl-10 pr-10 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                       placeholder="Enter new password (min 6 characters)"
                                       minlength="6"
                                       onkeyup="checkPasswordStrength()">
                                <button type="button" onclick="togglePassword('new_password')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center focus:outline-none">
                                    <i class="fas fa-eye text-slate-400 hover:text-slate-600 cursor-pointer transition" id="toggle-new_password"></i>
                                </button>
                            </div>

                            <!-- Password Strength Meter -->
                            <div class="mt-3">
                                <div class="flex justify-between mb-1.5">
                                    <span class="text-xs font-medium text-slate-600">Password Strength</span>
                                    <span id="strengthLabel" class="text-xs font-semibold text-slate-500">Not entered</span>
                                </div>
                                <div class="password-strength-meter flex space-x-1.5">
                                    <div id="strength-1" class="h-1.5 flex-1 bg-slate-200 rounded-full"></div>
                                    <div id="strength-2" class="h-1.5 flex-1 bg-slate-200 rounded-full"></div>
                                    <div id="strength-3" class="h-1.5 flex-1 bg-slate-200 rounded-full"></div>
                                </div>
                                <p id="strengthText" class="text-xs mt-2 text-slate-500">Use at least 6 characters with letters and numbers</p>
                            </div>
                        </div>

                        <!-- Confirm New Password -->
                        <div>
                            <label for="confirm_password" class="block text-sm font-semibold text-slate-700 mb-2">
                                Confirm New Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                    <i class="fas fa-check-circle text-slate-400 text-sm"></i>
                                </div>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       class="form-input w-full pl-10 pr-10 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-slate-800 bg-white"
                                       placeholder="Confirm new password"
                                       onkeyup="checkPasswordMatch()">
                                <button type="button" onclick="togglePassword('confirm_password')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center focus:outline-none">
                                    <i class="fas fa-eye text-slate-400 hover:text-slate-600 cursor-pointer transition" id="toggle-confirm_password"></i>
                                </button>
                            </div>
                            <p id="matchText" class="text-xs mt-1.5 h-4"></p>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-slate-100">
                            <button type="submit" 
                                    class="btn-primary flex items-center px-8 py-3 text-white rounded-lg font-semibold shadow-lg shadow-brand-500/20">
                                <i class="fas fa-key mr-2"></i>
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Delete Account Tab (Hidden by default) -->
                <div id="content-delete" class="hidden">
                    <div class="bg-white rounded-xl shadow-sm p-8">
                        <h2 class="text-lg font-semibold text-red-700 mb-6 flex items-center section-header section-header-danger">
                            <i class="fas fa-trash-alt text-red-500 mr-3"></i>
                            Delete Account
                        </h2>

                        <div class="delete-section rounded-xl p-6 mb-6">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                                        <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <h3 class="text-base font-bold text-red-800 mb-1">Warning: This action cannot be undone</h3>
                                    <p class="text-sm text-red-700 leading-relaxed">
                                        Deleting your account will permanently remove your profile information and disable access to the system. 
                                        Your reservation and purchase history will be anonymized for record-keeping purposes.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4 mb-6">
                            <div class="flex items-start p-3 rounded-lg bg-slate-50">
                                <i class="fas fa-times-circle text-red-400 mt-0.5 mr-3"></i>
                                <div>
                                    <p class="text-sm font-semibold text-slate-700">You will lose access to:</p>
                                    <ul class="text-sm text-slate-600 mt-1 space-y-1 ml-1">
                                        <li>• Your active reservations and pending orders</li>
                                        <li>• Your account dashboard and history</li>
                                        <li>• Any saved preferences or settings</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex items-start p-3 rounded-lg bg-slate-50">
                                <i class="fas fa-check-circle text-emerald-400 mt-0.5 mr-3"></i>
                                <div>
                                    <p class="text-sm font-semibold text-slate-700">What will be kept (anonymized):</p>
                                    <ul class="text-sm text-slate-600 mt-1 space-y-1 ml-1">
                                        <li>• Completed sales records for audit purposes</li>
                                        <li>• Return request history</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <form method="POST" action="" class="space-y-5" onsubmit="return validateDeleteForm()">
                            <input type="hidden" name="action" value="delete_account">

                            <!-- Password Confirmation -->
                            <div>
                                <label for="delete_password" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Enter Your Password <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <i class="fas fa-lock text-slate-400 text-sm"></i>
                                    </div>
                                    <input type="password" id="delete_password" name="delete_password" required
                                           class="form-input w-full pl-10 pr-10 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 text-slate-800 bg-white"
                                           placeholder="Enter your current password">
                                    <button type="button" onclick="togglePassword('delete_password')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center focus:outline-none">
                                        <i class="fas fa-eye text-slate-400 hover:text-slate-600 cursor-pointer transition" id="toggle-delete_password"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Type DELETE Confirmation -->
                            <div>
                                <label for="confirm_delete" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Type <span class="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-red-600 font-bold">DELETE</span> to confirm <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <i class="fas fa-keyboard text-slate-400 text-sm"></i>
                                    </div>
                                    <input type="text" id="confirm_delete" name="confirm_delete" required
                                           class="form-input w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 text-slate-800 bg-white font-mono tracking-wider uppercase"
                                           placeholder="DELETE"
                                           autocomplete="off">
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="flex justify-end pt-6 border-t border-slate-100">
                                <button type="submit" 
                                        class="btn-danger flex items-center px-8 py-3 text-white rounded-lg font-semibold shadow-lg shadow-red-500/20">
                                    <i class="fas fa-trash-alt mr-2"></i>
                                    Permanently Delete Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>

    <script>
        // Tab switching
        function switchTab(tab) {
            const contents = ['profile', 'password', 'delete'];
            const tabs = ['profile', 'password', 'delete'];

            contents.forEach(c => {
                const el = document.getElementById('content-' + c);
                if (el) el.classList.add('hidden');
            });

            tabs.forEach(t => {
                const btn = document.getElementById('tab-' + t);
                btn.classList.remove('active');
                btn.classList.add('bg-slate-50', 'text-slate-600');
            });

            const selectedContent = document.getElementById('content-' + tab);
            if (selectedContent) selectedContent.classList.remove('hidden');

            const selectedTab = document.getElementById('tab-' + tab);
            selectedTab.classList.add('active');
            selectedTab.classList.remove('bg-slate-50', 'text-slate-600');
        }

        // Toggle password visibility
        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = document.getElementById('toggle-' + fieldId);

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Check password strength
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBars = [
                document.getElementById('strength-1'),
                document.getElementById('strength-2'),
                document.getElementById('strength-3')
            ];
            const strengthLabel = document.getElementById('strengthLabel');
            const strengthText = document.getElementById('strengthText');

            strengthBars.forEach(bar => {
                bar.className = 'h-1.5 flex-1 bg-slate-200 rounded-full';
            });

            if (password.length === 0) {
                strengthLabel.textContent = 'Not entered';
                strengthLabel.className = 'text-xs font-semibold text-slate-500';
                strengthText.textContent = 'Use at least 6 characters with letters and numbers';
                strengthText.className = 'text-xs mt-2 text-slate-500';
                return;
            }

            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 0.5;
            if (/[0-9]/.test(password)) strength += 0.5;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 0.5;

            strength = Math.min(3, Math.floor(strength));

            const colors = ['bg-red-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-600'];
            const labels = ['Weak', 'Fair', 'Strong', 'Very Strong'];
            const messages = [
                'Too weak - add more characters',
                'Could be stronger - mix uppercase, numbers',
                'Strong password',
                'Very strong password'
            ];
            const labelColors = ['text-red-600', 'text-amber-600', 'text-emerald-600', 'text-emerald-700'];

            for (let i = 0; i < strength; i++) {
                strengthBars[i].className = `h-1.5 flex-1 ${colors[strength - 1]} rounded-full`;
            }

            strengthLabel.textContent = labels[strength] || 'Weak';
            strengthLabel.className = 'text-xs font-semibold ' + (labelColors[strength] || labelColors[0]);
            strengthText.textContent = messages[strength] || messages[0];
            strengthText.className = 'text-xs mt-2 ' + (labelColors[strength] || labelColors[0]);
        }

        // Check password match
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('matchText');

            if (confirm.length === 0) {
                matchText.textContent = '';
                return;
            }

            if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.className = 'text-xs mt-1.5 text-emerald-600 font-medium';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.className = 'text-xs mt-1.5 text-red-600 font-medium';
            }
        }

        // Validate password form
        function validatePasswordForm() {
            const current = document.getElementById('current_password').value;
            const newPass = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;

            if (current.length < 1) {
                alert('Please enter your current password.');
                return false;
            }

            if (newPass.length < 6) {
                alert('New password must be at least 6 characters long.');
                return false;
            }

            if (newPass !== confirm) {
                alert('New passwords do not match.');
                return false;
            }

            return true;
        }

        // Validate delete form
        function validateDeleteForm() {
            const password = document.getElementById('delete_password').value;
            const confirm = document.getElementById('confirm_delete').value;

            if (password.length < 1) {
                alert('Please enter your password to confirm account deletion.');
                return false;
            }

            if (confirm !== 'DELETE') {
                alert('Please type DELETE (in uppercase) in the confirmation field to proceed.');
                return false;
            }

            return confirm('Are you absolutely sure you want to delete your account? This action is permanent and cannot be reversed.');
        }

        // Auto-hide flash messages
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const messages = document.querySelectorAll('.bg-emerald-50, .bg-red-50, .bg-brand-50');
                messages.forEach(msg => {
                    msg.style.opacity = '0';
                    msg.style.transition = 'opacity 0.5s ease';
                    msg.style.transform = 'translateY(-10px)';
                    msg.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => msg.remove(), 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>