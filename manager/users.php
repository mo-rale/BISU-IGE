<?php
// manager/users.php - Professional UI
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow managers
SessionManager::requireManager();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$db = (new Database())->getConnection();

// Handle user actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                try {
                    // Check if email already exists
                    $checkSql = "SELECT user_id FROM users WHERE email = :email";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':email' => $_POST['email']]);
                    
                    if ($checkStmt->fetch()) {
                        $message = "Email address already exists!";
                        $messageType = 'error';
                        break;
                    }
                    
                    $sql = "INSERT INTO users 
                            (employee_id, full_name, department, position, contact_number, email, role, hashed_password)
                            VALUES 
                            (:employee_id, :full_name, :department, :position, :contact_number, :email, :role, :password)
                            RETURNING user_id";

                    $stmt = $db->prepare($sql);

                    $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);

                    $stmt->execute([
                        ':employee_id' => $_POST['employee_id'] ?? null,
                        ':full_name' => $_POST['full_name'],
                        ':department' => $_POST['department'] ?? null,
                        ':position' => $_POST['position'] ?? null,
                        ':contact_number' => $_POST['contact_number'] ?? null,
                        ':email' => $_POST['email'],
                        ':role' => $_POST['role'],
                        ':password' => $hashedPassword
                    ]);
                    
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_id = $result['user_id'] ?? $db->lastInsertId();
                    
                    // Create welcome notification
                    $notifySql = "INSERT INTO notifications (user_id, title, message, is_read, created_at) 
                                 VALUES (:user_id, 'Welcome to BISU IGE Aquaculture', 
                                         'Your account has been created by a manager. You can now log in using your email and password.', 
                                         false, CURRENT_TIMESTAMP)";
                    $notifyStmt = $db->prepare($notifySql);
                    $notifyStmt->execute([':user_id' => $user_id]);
                    
                    $message = "User added successfully!";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error adding user: " . $e->getMessage();
                    $messageType = 'error';
                    error_log("Add user error: " . $e->getMessage());
                }
                break;
                
            case 'edit_user':
                try {
                   $sql = "UPDATE users SET 
                            employee_id = :employee_id,
                            full_name = :full_name,
                            department = :department,
                            position = :position,
                            contact_number = :contact_number,
                            role = :role,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE user_id = :user_id";

                    $stmt = $db->prepare($sql);

                    $stmt->execute([
                        ':employee_id' => $_POST['employee_id'] ?? null,
                        ':full_name' => $_POST['full_name'],
                        ':department' => $_POST['department'] ?? null,
                        ':position' => $_POST['position'] ?? null,
                        ':contact_number' => $_POST['contact_number'] ?? null,
                        ':role' => $_POST['role'],
                        ':user_id' => $_POST['user_id']
                    ]);
                    
                    // If password is provided, update it
                    if (!empty($_POST['password'])) {
                        $passSql = "UPDATE users SET hashed_password = :password WHERE user_id = :user_id";
                        $passStmt = $db->prepare($passSql);
                        $passStmt->execute([
                            ':password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
                            ':user_id' => $_POST['user_id']
                        ]);
                        
                        // Notify user of password change
                        $notifySql = "INSERT INTO notifications (user_id, title, message, is_read, created_at) 
                                     VALUES (:user_id, 'Password Updated', 
                                             'Your password has been updated by a manager.', 
                                             false, CURRENT_TIMESTAMP)";
                        $notifyStmt = $db->prepare($notifySql);
                        $notifyStmt->execute([':user_id' => $_POST['user_id']]);
                    }
                    
                    $message = "User updated successfully!";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error updating user: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'reset_password':
                try {
                    $tempPassword = bin2hex(random_bytes(4)); // 8 character temporary password
                    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
                    
                    $sql = "UPDATE users SET hashed_password = :password WHERE user_id = :user_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':password' => $hashedPassword,
                        ':user_id' => $_POST['user_id']
                    ]);
                    
                    // Notify user
                    $notifySql = "INSERT INTO notifications (user_id, title, message, is_read, created_at) 
                                 VALUES (:user_id, 'Password Reset', 
                                         'Your password has been reset by a manager. Please check with your manager for the new password.', 
                                         false, CURRENT_TIMESTAMP)";
                    $notifyStmt = $db->prepare($notifySql);
                    $notifyStmt->execute([':user_id' => $_POST['user_id']]);
                    
                    $message = "Password reset successfully! Temporary password: " . $tempPassword;
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error resetting password: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get filter parameters
$roleFilter = $_GET['role'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;


// Get total count first
try {
    $countSql = "SELECT COUNT(*) as total FROM users WHERE 1=1";
    $countParams = [];
    
    if ($roleFilter !== 'all') {
        $countSql .= " AND role = :role";
        $countParams[':role'] = $roleFilter;
    }
    
    if ($searchQuery) {
        $countSql .= " AND (full_name ILIKE :search OR email ILIKE :search OR contact_number ILIKE :search OR employee_id ILIKE :search)";
        $countParams[':search'] = "%$searchQuery%";
    }
    
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $perPage);
    
} catch (PDOException $e) {
    error_log("Count error: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 0;
}

// Get users
try {
    $sql = "SELECT 
                u.user_id,
                u.employee_id,
                u.full_name,
                u.department,
                u.position,
                u.contact_number,
                u.email,
                u.role,
                u.created_at,
                u.updated_at,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.user_id) as total_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.user_id AND order_status IN ('claimed', 'confirmed')) as total_spent,
                (SELECT COUNT(*) FROM notifications WHERE user_id = u.user_id AND is_read = false) as unread_notifications
            FROM users u
            WHERE 1=1";
    
    $params = [];
    
    if ($roleFilter !== 'all') {
        $sql .= " AND u.role = :role";
        $params[':role'] = $roleFilter;
    }
    
    if ($searchQuery) {
        $sql .= " AND (u.full_name ILIKE :search OR u.email ILIKE :search OR u.contact_number ILIKE :search OR u.employee_id ILIKE :search)";
        $params[':search'] = "%$searchQuery%";
    }
    
    $sql .= " ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    
    $stmt = $db->prepare($sql);
    
    foreach ($params as $key => $value) {
        if ($key == ':limit' || $key == ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $statsSql = "SELECT 
                    COUNT(*) as total_users,
                    COUNT(CASE WHEN role = 'manager' THEN 1 END) as total_managers,
                    COUNT(CASE WHEN role = 'standard' THEN 1 END) as total_standard,
                    COUNT(CASE WHEN role = 'staff' THEN 1 END) as total_staff
                 FROM users";
    $statsStmt = $db->query($statsSql);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Users fetch error: " . $e->getMessage());
    $users = [];
    $stats = [
        'total_users' => 0,
        'total_managers' => 0,
        'total_standard' => 0,
        'total_staff' => 0
    ];
}

// Role options
$roleOptions = [
    'all' => 'All Roles',
    'standard' => 'Standard Users',
    'staff' => 'Staff',
    'manager' => 'Managers'
];

// Role colors and icons
$roleConfig = [
    'manager' => [
        'color' => 'bg-purple-50 text-purple-700 border-purple-200',
        'icon' => 'fa-crown',
        'label' => 'Manager'
    ],
    'staff' => [
        'color' => 'bg-teal-50 text-teal-700 border-teal-200',
        'icon' => 'fa-user-tie',
        'label' => 'Staff'
    ],
    'standard' => [
        'color' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'icon' => 'fa-user',
        'label' => 'Standard'
    ]
];

// Counts for filter badges
$counts = [
    'all' => $stats['total_users'] ?? 0,
    'standard' => $stats['total_standard'] ?? 0,
    'staff' => $stats['total_staff'] ?? 0,
    'manager' => $stats['total_managers'] ?? 0
];

function buildPaginationLinks($currentPage, $totalPages, $queryParams = []) {
    if ($totalPages <= 1) return '';
    
    $links = '<div class="flex items-center gap-2 flex-wrap justify-center">';
    
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">« Prev</a>';
    } else {
        $links .= '<span class="px-3 py-2 rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed text-sm font-medium">« Prev</span>';
    }
    
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $queryParams['page'] = 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">1</a>';
        if ($startPage > 2) $links .= '<span class="px-2 text-gray-500 text-sm">...</span>';
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $currentPage) {
            $links .= '<span class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium shadow-sm">' . $i . '</span>';
        } else {
            $queryParams['page'] = $i;
            $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">' . $i . '</a>';
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) $links .= '<span class="px-2 text-gray-500 text-sm">...</span>';
        $queryParams['page'] = $totalPages;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-smooth">Next »</a>';
    } else {
        $links .= '<span class="px-3 py-2 rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed text-sm font-medium">Next »</span>';
    }
    
    $links .= '</div>';
    return $links;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        display: ['Playfair Display', 'serif'],
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
                        },
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --brand: #0ea5e9;
            --brand-dark: #0284c7;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
        }

        body {
            background-color: var(--bg-primary);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }

        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pro-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .pro-card:hover {
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .stat-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 1rem;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
        }

        .hero-section {
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
            border-radius: 50%;
        }

        .btn-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .btn-brand:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        .btn-outline-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: transparent;
            border: 1.5px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-brand:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: white;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        .filter-input, .filter-select {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
            width: 100%;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .filter-tab-pro {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            background: white;
            color: #475569;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tab-pro:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        .filter-tab-pro.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .filter-count {
            background: #f1f5f9;
            border-radius: 9999px;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
            min-width: 1.5rem;
            text-align: center;
        }

        .filter-tab-pro.active .filter-count {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .badge-pro {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            border: 1px solid;
        }

        .modal {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            position: fixed;
            inset: 0;
            z-index: 50;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 700px;
            width: 90%;
            padding: 1.5rem;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .flash-msg {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            animation: slideDown 0.3s ease;
            border: 1px solid;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .empty-icon {
            width: 5rem;
            height: 5rem;
            background: #f1f5f9;
            border-radius: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #94a3b8;
            font-size: 2rem;
        }

        .data-table {
            width: 100%;
        }
        
        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 600;
            color: #64748b;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .data-table tr:hover {
            background: #fafcff;
        }

        .user-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
        }

        .action-btn {
            width: 2rem;
            height: 2rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            background: #f8fafc;
            color: #64748b;
            border: 1px solid #e2e8f0;
            cursor: pointer;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .action-btn.view:hover { background: #f0f9ff; color: #0284c7; border-color: #bae6fd; }
        .action-btn.edit:hover { background: #fffbeb; color: #d97706; border-color: #fde68a; }
        .action-btn.reset:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.875rem 1rem;
            transition: all 0.2s ease;
        }

        .info-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg bg-white shadow-sm" style="border-color: <?php echo $messageType == 'success' ? '#d1fae5' : '#fee2e2'; ?>">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center <?php echo $messageType == 'success' ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'; ?>">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check' : 'fa-exclamation'; ?> text-sm"></i>
                </div>
                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="hero-section py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">User Management</p>
                    <h1 class="text-2xl md:text-3xl font-bold text-white font-display">
                        Manage Users
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">View and manage system users, roles, and permissions.</p>
                </div>
                <button onclick="openAddUserModal()" class="btn-outline-brand">
                    <i class="fas fa-user-plus text-sm"></i>
                    Add New User
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-brand-50 flex items-center justify-center text-brand-600">
                        <i class="fas fa-users text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Total Users</p>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_users'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fas fa-user text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Standard</p>
                </div>
                <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['total_standard'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center text-teal-600">
                        <i class="fas fa-user-tie text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Staff</p>
                </div>
                <p class="text-2xl font-bold text-teal-600"><?php echo number_format($stats['total_staff'] ?? 0); ?></p>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                        <i class="fas fa-crown text-xs"></i>
                    </div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Managers</p>
                </div>
                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['total_managers'] ?? 0); ?></p>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2">
                <a href="?role=all&search=<?php echo urlencode($searchQuery); ?>" 
                   class="filter-tab-pro <?php echo $roleFilter == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list text-[10px]"></i>
                    All Users
                    <span class="filter-count ml-1.5"><?php echo $counts['all']; ?></span>
                </a>
                <a href="?role=standard&search=<?php echo urlencode($searchQuery); ?>" 
                   class="filter-tab-pro <?php echo $roleFilter == 'standard' ? 'active' : ''; ?>">
                    <i class="fas fa-user text-[10px]"></i>
                    Standard
                    <span class="filter-count ml-1.5"><?php echo $counts['standard']; ?></span>
                </a>
                <a href="?role=staff&search=<?php echo urlencode($searchQuery); ?>" 
                   class="filter-tab-pro <?php echo $roleFilter == 'staff' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie text-[10px]"></i>
                    Staff
                    <span class="filter-count ml-1.5"><?php echo $counts['staff']; ?></span>
                </a>
                <a href="?role=manager&search=<?php echo urlencode($searchQuery); ?>" 
                   class="filter-tab-pro <?php echo $roleFilter == 'manager' ? 'active' : ''; ?>">
                    <i class="fas fa-crown text-[10px]"></i>
                    Managers
                    <span class="filter-count ml-1.5"><?php echo $counts['manager']; ?></span>
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="pro-card p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php if ($roleFilter !== 'all'): ?>
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($roleFilter); ?>">
                <?php endif; ?>
                
                <div class="md:col-span-2">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search by name, email, employee ID or contact number..." 
                               class="filter-input pl-10">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-brand flex-1 justify-center">
                        <i class="fas fa-filter text-sm"></i> Filter
                    </button>
                    <a href="users.php" class="btn-secondary justify-center" title="Reset filters">
                        <i class="fas fa-redo-alt text-xs"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <?php if (empty($users)): ?>
                <div class="text-center py-12">
                    <div class="empty-icon mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No users found</h3>
                    <p class="text-sm text-gray-500 mb-6">No users match your current filters.</p>
                    <button onclick="openAddUserModal()" class="btn-brand">
                        <i class="fas fa-user-plus text-sm"></i>
                        Add New User
                    </button>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="px-4 py-3">User</th>
                                <th class="px-4 py-3">Employee ID</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3">Contact</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3 text-right">Stats</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                $roleInfo = $roleConfig[$user['role']] ?? ['color' => 'bg-gray-50 text-gray-700 border-gray-200', 'icon' => 'fa-user', 'label' => ucfirst($user['role'])];
                                $isCurrentUser = ($user['user_id'] == $userId);
                                $initial = strtoupper(substr($user['full_name'], 0, 1)) ?: '?';
                            ?>
                                <tr class="hover:bg-gray-50 transition-smooth">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="user-avatar"><?php echo $initial; ?></div>
                                            <div>
                                                <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></p>
                                                <?php if ($user['unread_notifications'] > 0): ?>
                                                    <span class="text-[10px] text-brand-600 mt-0.5 inline-flex items-center gap-1">
                                                        <i class="fas fa-bell"></i> <?php echo $user['unread_notifications']; ?> unread
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($isCurrentUser): ?>
                                                    <span class="text-[10px] text-emerald-600 ml-2 inline-flex items-center gap-1">
                                                        <i class="fas fa-user-check"></i> Current
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($user['employee_id'] ?? '—'); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($user['department'] ?? '—'); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo !empty($user['contact_number']) ? htmlspecialchars($user['contact_number']) : '<span class="text-gray-400 italic">—</span>'; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="badge-pro <?php echo $roleInfo['color']; ?>">
                                            <i class="fas <?php echo $roleInfo['icon']; ?> text-[8px]"></i>
                                            <?php echo $roleInfo['label']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="text-sm">
                                            <div class="text-gray-700">Orders: <span class="font-semibold"><?php echo $user['total_orders'] ?? 0; ?></span></div>
                                            <div class="font-semibold text-brand-600">₱<?php echo number_format($user['total_spent'] ?? 0, 2); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button onclick='viewUser(<?php echo json_encode($user); ?>)' 
                                                    class="action-btn view" title="View Details">
                                                <i class="fas fa-eye text-xs"></i>
                                            </button>
                                            <button onclick='editUser(<?php echo json_encode($user); ?>)' 
                                                    class="action-btn edit" title="Edit User">
                                                <i class="fas fa-edit text-xs"></i>
                                            </button>
                                            <?php if (!$isCurrentUser): ?>
                                                <button onclick="resetPassword(<?php echo $user['user_id']; ?>)" 
                                                        class="action-btn reset" title="Reset Password">
                                                    <i class="fas fa-key text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($totalPages > 1): ?>
                <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/50">
                    <?php 
                    $queryParams = $_GET; 
                    unset($queryParams['page']); 
                    echo buildPaginationLinks($page, $totalPages, $queryParams); 
                    ?>
                    <div class="text-center text-xs text-gray-400 mt-2">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRecords); ?> total users)
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-user-plus text-brand-500"></i>
                    Add New User
                </h3>
                <button onclick="closeAddUserModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_user">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Full Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="full_name" required class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Email <span class="text-red-500">*</span>
                        </label>
                        <input type="email" name="email" required class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Employee ID
                        </label>
                        <input type="text" name="employee_id" class="filter-input" placeholder="e.g., EMP-001">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Department
                        </label>
                        <input type="text" name="department" class="filter-input" placeholder="e.g., College of Fisheries">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Position
                        </label>
                        <input type="text" name="position" class="filter-input" placeholder="e.g., Instructor">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Contact Number
                        </label>
                        <input type="text" name="contact_number" class="filter-input" placeholder="e.g., 09123456789">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Password <span class="text-red-500">*</span>
                        </label>
                        <input type="password" name="password" required minlength="6" class="filter-input">
                        <p class="text-[10px] text-gray-400 mt-1">Minimum 6 characters</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Role <span class="text-red-500">*</span>
                        </label>
                        <select name="role" required class="filter-select">
                            <option value="standard">Standard User</option>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeAddUserModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-edit text-amber-500"></i>
                    Edit User
                </h3>
                <button onclick="closeEditUserModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Full Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="full_name" id="edit_full_name" required class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Email
                        </label>
                        <input type="email" id="edit_email" disabled class="filter-input bg-gray-50 text-gray-500">
                        <p class="text-[10px] text-gray-400 mt-1">Email cannot be changed</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Employee ID
                        </label>
                        <input type="text" name="employee_id" id="edit_employee_id" class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Department
                        </label>
                        <input type="text" name="department" id="edit_department" class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Position
                        </label>
                        <input type="text" name="position" id="edit_position" class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Contact Number
                        </label>
                        <input type="text" name="contact_number" id="edit_contact" class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            New Password <span class="text-gray-400 font-normal">(leave blank to keep current)</span>
                        </label>
                        <input type="password" name="password" minlength="6" class="filter-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Role <span class="text-red-500">*</span>
                        </label>
                        <select name="role" id="edit_role" required class="filter-select">
                            <option value="standard">Standard User</option>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeEditUserModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Update User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View User Modal -->
    <div id="viewUserModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-user-circle text-brand-500"></i>
                    User Details
                </h3>
                <button onclick="closeViewUserModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div id="userDetails" class="space-y-4 max-h-[60vh] overflow-y-auto"></div>
            <div class="mt-5 pt-3 border-t border-gray-100 flex justify-end">
                <button onclick="closeViewUserModal()" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content max-w-md">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-key text-red-500"></i>
                    Reset Password
                </h3>
                <button onclick="closeResetPasswordModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                
                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-3">Are you sure you want to reset this user's password? A temporary password will be generated.</p>
                    <div class="bg-amber-50 rounded-xl p-3 border border-amber-100">
                        <p class="text-xs text-amber-700 flex items-start gap-2">
                            <i class="fas fa-exclamation-triangle mt-0.5"></i>
                            <span>The user will receive a notification about the password reset.</span>
                        </p>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-3">
                    <button type="button" onclick="closeResetPasswordModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-key"></i> Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function openAddUserModal() { 
            document.getElementById('addUserModal').classList.add('show'); 
            document.body.style.overflow = 'hidden'; 
        }
        
        function closeAddUserModal() { 
            document.getElementById('addUserModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function editUser(user) {
            document.getElementById('edit_user_id').value = user.user_id;
            document.getElementById('edit_full_name').value = user.full_name || '';
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_employee_id').value = user.employee_id || '';
            document.getElementById('edit_department').value = user.department || '';
            document.getElementById('edit_position').value = user.position || '';
            document.getElementById('edit_contact').value = user.contact_number || '';
            document.getElementById('edit_role').value = user.role || 'standard';
            document.getElementById('editUserModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditUserModal() { 
            document.getElementById('editUserModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function viewUser(user) {
            const statusColor = user.role === 'manager' ? 'bg-purple-50 text-purple-700 border-purple-200' :
                               user.role === 'staff' ? 'bg-teal-50 text-teal-700 border-teal-200' :
                               'bg-emerald-50 text-emerald-700 border-emerald-200';
            const statusIcon = user.role === 'manager' ? 'fa-crown' : (user.role === 'staff' ? 'fa-user-tie' : 'fa-user');
            const statusLabel = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'Unknown';
            const initial = user.full_name ? user.full_name.charAt(0).toUpperCase() : '?';
            
            const details = document.getElementById('userDetails');
            details.innerHTML = `
                <div class="flex items-center gap-4 mb-6">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-brand-500 to-brand-700 flex items-center justify-center shadow-md">
                        <span class="text-2xl font-bold text-white">${escapeHtml(initial)}</span>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">${escapeHtml(user.full_name || '')}</h2>
                        <p class="text-sm text-gray-500">${escapeHtml(user.email || '')}</p>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="badge-pro ${statusColor}">
                                <i class="fas ${statusIcon} text-[8px]"></i>
                                ${statusLabel}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Employee ID</p>
                        <p class="font-medium text-gray-900 text-sm">${user.employee_id ? escapeHtml(user.employee_id) : '<span class="text-gray-400 italic">Not assigned</span>'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Department</p>
                        <p class="font-medium text-gray-900 text-sm">${user.department ? escapeHtml(user.department) : '<span class="text-gray-400 italic">Not specified</span>'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Position</p>
                        <p class="font-medium text-gray-900 text-sm">${user.position ? escapeHtml(user.position) : '<span class="text-gray-400 italic">Not specified</span>'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Contact Number</p>
                        <p class="font-medium text-gray-900 text-sm">${user.contact_number ? escapeHtml(user.contact_number) : '<span class="text-gray-400 italic">Not provided</span>'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Member Since</p>
                        <p class="font-medium text-gray-900 text-sm">${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A'}</p>
                    </div>
                    <div class="info-card p-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Total Spent</p>
                        <p class="font-bold text-brand-600 text-base">₱${Number(user.total_spent || 0).toFixed(2)}</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div class="bg-gradient-to-br from-brand-50 to-blue-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-bold text-brand-600">${user.total_orders || 0}</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Total Orders</p>
                    </div>
                    <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-xl p-3 text-center">
                        <p class="text-2xl font-bold text-emerald-600">${user.unread_notifications || 0}</p>
                        <p class="text-[10px] text-gray-500 mt-0.5">Unread Notifications</p>
                    </div>
                </div>
            `;
            document.getElementById('viewUserModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeViewUserModal() { 
            document.getElementById('viewUserModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function resetPassword(userId) { 
            document.getElementById('reset_user_id').value = userId; 
            document.getElementById('resetPasswordModal').classList.add('show'); 
            document.body.style.overflow = 'hidden'; 
        }
        
        function closeResetPasswordModal() { 
            document.getElementById('resetPasswordModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function escapeHtml(str) { 
            if (!str) return ''; 
            return String(str).replace(/[&<>]/g, m => m==='&'?'&amp;':m==='<'?'&lt;':'&gt;'); 
        }
        
        // Auto-dismiss flash messages
        setTimeout(() => {
            document.querySelectorAll('.flash-msg').forEach(msg => {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 400);
            });
        }, 5000);
        
        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddUserModal();
                closeEditUserModal();
                closeViewUserModal();
                closeResetPasswordModal();
            }
        });
    </script>
</body>
</html>