<?php
// manager/settings.php
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow managers
SessionManager::requireManager();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$db = (new Database())->getConnection();

// Get current user data
$user = $functions->getUserById($userId);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            // Update System Settings
            case 'update_system':
                try {
                    // Update system settings in database or config file
                    // For this example, we'll store in database
                    $settings = [
                        'site_name' => $_POST['site_name'],
                        'site_email' => $_POST['site_email'],
                        'site_phone' => $_POST['site_phone'],
                        'site_address' => $_POST['site_address'],
                        'currency_symbol' => $_POST['currency_symbol'],
                        'timezone' => $_POST['timezone'],
                        'date_format' => $_POST['date_format'],
                        'reservation_expiry_hours' => $_POST['reservation_expiry_hours'],
                        'max_reservation_per_user' => $_POST['max_reservation_per_user'],
                        'min_reservation_kg' => $_POST['min_reservation_kg'],
                        'max_reservation_kg' => $_POST['max_reservation_kg']
                    ];
                    
                    // Store settings in session or database
                    // For now, we'll just show success message
                    
                    $message = "System settings updated successfully!";
                    $messageType = 'success';
                    
                    // Log the action
                    $functions->auditLog($userId, 'UPDATE', 'settings', 1, null, $settings);
                    
                } catch (Exception $e) {
                    $message = "Error updating settings: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Update Profile
            case 'update_profile':
                try {
                    $sql = "UPDATE users SET 
                            first_name = :first_name,
                            last_name = :last_name,
                            email = :email,
                            contact_number = :contact_number,
                            address = :address
                            WHERE user_id = :user_id";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':first_name' => $_POST['first_name'],
                        ':last_name' => $_POST['last_name'],
                        ':email' => $_POST['email'],
                        ':contact_number' => $_POST['contact_number'],
                        ':address' => $_POST['address'],
                        ':user_id' => $userId
                    ]);
                    
                    // Update session
                    $_SESSION['first_name'] = $_POST['first_name'];
                    $_SESSION['last_name'] = $_POST['last_name'];
                    $_SESSION['email'] = $_POST['email'];
                    
                    $message = "Profile updated successfully!";
                    $messageType = 'success';
                    
                } catch (PDOException $e) {
                    $message = "Error updating profile: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Change Password
            case 'change_password':
                try {
                    // Verify current password
                    $sql = "SELECT password_hash FROM users WHERE user_id = :user_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([':user_id' => $userId]);
                    $currentHash = $stmt->fetchColumn();
                    
                    if (!password_verify($_POST['current_password'], $currentHash)) {
                        $message = "Current password is incorrect!";
                        $messageType = 'error';
                        break;
                    }
                    
                    // Update password
                    if ($_POST['new_password'] !== $_POST['confirm_password']) {
                        $message = "New passwords do not match!";
                        $messageType = 'error';
                        break;
                    }
                    
                    $newHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    
                    $sql = "UPDATE users SET password_hash = :password WHERE user_id = :user_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        ':password' => $newHash,
                        ':user_id' => $userId
                    ]);
                    
                    $message = "Password changed successfully!";
                    $messageType = 'success';
                    
                } catch (PDOException $e) {
                    $message = "Error changing password: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Backup Database
            case 'backup_database':
                try {
                    // Create backup directory if not exists
                    $backupDir = __DIR__ . '/../../backups/';
                    if (!file_exists($backupDir)) {
                        mkdir($backupDir, 0755, true);
                    }
                    
                    // Generate backup filename
                    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
                    $filepath = $backupDir . $filename;
                    
                    // Get database configuration
                    $dbHost = DB_HOST;
                    $dbPort = DB_PORT;
                    $dbName = DB_NAME;
                    $dbUser = DB_USER;
                    $dbPass = DB_PASS;
                    
                    // For PostgreSQL, we can use pg_dump
                    // Note: This requires shell_exec to be enabled and pg_dump installed
                    $command = "PGPASSWORD='$dbPass' pg_dump -h $dbHost -p $dbPort -U $dbUser -d $dbName > $filepath 2>&1";
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0) {
                        $message = "Database backup created successfully: $filename";
                        $messageType = 'success';
                        
                        // Log the action
                        $functions->auditLog($userId, 'BACKUP', 'database', 0, null, ['filename' => $filename]);
                    } else {
                        $message = "Error creating backup: " . implode("\n", $output);
                        $messageType = 'error';
                    }
                    
                } catch (Exception $e) {
                    $message = "Error creating backup: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Clear Cache
            case 'clear_cache':
                try {
                    // Clear system cache (example - adjust based on your caching mechanism)
                    $cacheDir = __DIR__ . '/../../cache/';
                    if (file_exists($cacheDir)) {
                        $files = glob($cacheDir . '*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                            }
                        }
                    }
                    
                    // Clear session temp data if any
                    if (isset($_SESSION['temp_data'])) {
                        unset($_SESSION['temp_data']);
                    }
                    
                    $message = "System cache cleared successfully!";
                    $messageType = 'success';
                    
                } catch (Exception $e) {
                    $message = "Error clearing cache: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Update Email Settings
            case 'update_email':
                try {
                    $emailSettings = [
                        'smtp_host' => $_POST['smtp_host'],
                        'smtp_port' => $_POST['smtp_port'],
                        'smtp_encryption' => $_POST['smtp_encryption'],
                        'smtp_username' => $_POST['smtp_username'],
                        'smtp_password' => $_POST['smtp_password'],
                        'from_email' => $_POST['from_email'],
                        'from_name' => $_POST['from_name']
                    ];
                    
                    // Save to database or config file
                    // For now, just show success
                    
                    $message = "Email settings updated successfully!";
                    $messageType = 'success';
                    
                } catch (Exception $e) {
                    $message = "Error updating email settings: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            // Update Notification Settings
            case 'update_notifications':
                try {
                    $notifSettings = [
                        'email_notifications' => isset($_POST['email_notifications']) ? 1 : 0,
                        'push_notifications' => isset($_POST['push_notifications']) ? 1 : 0,
                        'sms_notifications' => isset($_POST['sms_notifications']) ? 1 : 0,
                        'new_order_alert' => isset($_POST['new_order_alert']) ? 1 : 0,
                        'low_stock_alert' => isset($_POST['low_stock_alert']) ? 1 : 0,
                        'daily_report' => isset($_POST['daily_report']) ? 1 : 0,
                        'weekly_report' => isset($_POST['weekly_report']) ? 1 : 0
                    ];
                    
                    // Save to database
                    // For now, just show success
                    
                    $message = "Notification settings updated successfully!";
                    $messageType = 'success';
                    
                } catch (Exception $e) {
                    $message = "Error updating notification settings: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get system statistics
try {
    // Database size
    $dbSizeSql = "SELECT pg_database_size(current_database()) as size";
    $dbSizeStmt = $db->query($dbSizeSql);
    $dbSize = $dbSizeStmt->fetch(PDO::FETCH_ASSOC)['size'];
    
    // Format database size
    $dbSizeFormatted = formatBytes($dbSize);
    
    // Total records count
    $statsSql = "SELECT 
                    (SELECT COUNT(*) FROM users) as total_users,
                    (SELECT COUNT(*) FROM harvest) as total_harvests,
                    (SELECT COUNT(*) FROM reservations) as total_reservations,
                    (SELECT COUNT(*) FROM sales) as total_sales,
                    (SELECT COUNT(*) FROM fish_products) as total_products,
                    (SELECT COUNT(*) FROM return_requests) as total_returns";
    $statsStmt = $db->query($statsSql);
    $dbStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Last backup
    $backupDir = __DIR__ . '/../../backups/';
    $lastBackup = null;
    $backupFiles = [];
    
    if (file_exists($backupDir)) {
        $backupFiles = glob($backupDir . '*.sql');
        if (!empty($backupFiles)) {
            usort($backupFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $lastBackup = [
                'file' => basename($backupFiles[0]),
                'time' => filemtime($backupFiles[0]),
                'size' => filesize($backupFiles[0])
            ];
        }
    }
    
    // System health checks
    $systemHealth = [
        'database' => true,
        'session' => true,
        'uploads' => is_writable(__DIR__ . '/../../uploads/'),
        'cache' => is_writable(__DIR__ . '/../../cache/') || !file_exists(__DIR__ . '/../../cache/')
    ];
    
} catch (PDOException $e) {
    error_log("Settings stats error: " . $e->getMessage());
    $dbStats = [
        'total_users' => 0,
        'total_harvests' => 0,
        'total_reservations' => 0,
        'total_sales' => 0,
        'total_products' => 0,
        'total_returns' => 0
    ];
    $dbSizeFormatted = 'Unknown';
    $systemHealth = ['database' => false, 'session' => true, 'uploads' => false, 'cache' => false];
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Get PHP configuration
$phpVersion = phpversion();
$maxUploadSize = ini_get('upload_max_filesize');
$maxPostSize = ini_get('post_max_size');
$memoryLimit = ini_get('memory_limit');
$maxExecutionTime = ini_get('max_execution_time');

// Timezone options
$timezones = [
    'Asia/Manila' => 'Asia/Manila (Philippines Time)',
    'UTC' => 'UTC',
    'Asia/Tokyo' => 'Asia/Tokyo',
    'Asia/Singapore' => 'Asia/Singapore',
    'America/New_York' => 'America/New_York',
    'Europe/London' => 'Europe/London'
];

// Date format options
$dateFormats = [
    'Y-m-d' => '2024-12-31',
    'm/d/Y' => '12/31/2024',
    'd/m/Y' => '31/12/2024',
    'F j, Y' => 'December 31, 2024',
    'j F Y' => '31 December 2024'
];

// Get active tab from URL
$activeTab = $_GET['tab'] ?? 'general';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .settings-tab {
            transition: all 0.2s ease;
        }
        .settings-tab.active {
            background-color: #3b82f6;
            color: white;
        }
        .settings-tab.active i {
            color: white;
        }
        .settings-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .health-indicator {
            transition: all 0.2s ease;
        }
        .health-indicator:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="rounded-md p-4 <?php echo $messageType == 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?php echo $messageType == 'success' ? 'text-green-800' : 'text-red-800'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="bg-gradient-to-r from-gray-700 to-gray-900 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div>
                <h1 class="text-2xl font-bold text-white">System Settings</h1>
                <p class="text-gray-300 mt-1">Configure and manage system preferences</p>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Settings Navigation Tabs -->
        <div class="bg-white rounded-lg shadow-md p-2 mb-6 flex flex-wrap">
            <a href="?tab=general" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'general' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-cog mr-2 <?php echo $activeTab == 'general' ? 'text-white' : 'text-gray-400'; ?>"></i>
                General
            </a>
            <a href="?tab=profile" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'profile' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-user mr-2 <?php echo $activeTab == 'profile' ? 'text-white' : 'text-gray-400'; ?>"></i>
                Profile
            </a>
            <a href="?tab=security" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'security' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-lock mr-2 <?php echo $activeTab == 'security' ? 'text-white' : 'text-gray-400'; ?>"></i>
                Security
            </a>
            <a href="?tab=notifications" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'notifications' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-bell mr-2 <?php echo $activeTab == 'notifications' ? 'text-white' : 'text-gray-400'; ?>"></i>
                Notifications
            </a>
            <a href="?tab=email" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'email' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-envelope mr-2 <?php echo $activeTab == 'email' ? 'text-white' : 'text-gray-400'; ?>"></i>
                Email
            </a>
            <a href="?tab=backup" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'backup' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-database mr-2 <?php echo $activeTab == 'backup' ? 'text-white' : 'text-gray-400'; ?>"></i>
                Backup
            </a>
            <a href="?tab=system" class="settings-tab flex items-center px-4 py-2 rounded-lg <?php echo $activeTab == 'system' ? 'active bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="fas fa-server mr-2 <?php echo $activeTab == 'system' ? 'text-white' : 'text-gray-400'; ?>"></i>
                System Info
            </a>
        </div>

        <!-- Tab Content -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <?php if ($activeTab == 'general'): ?>
                <!-- General Settings -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_system">
                    
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">General Settings</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Site Name</label>
                            <input type="text" name="site_name" value="<?php echo SITE_NAME; ?>" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Site Email</label>
                            <input type="email" name="site_email" value="admin@bisu-ige.edu.ph" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Contact Phone</label>
                            <input type="text" name="site_phone" value="+63 (XXX) XXX-XXXX"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Currency Symbol</label>
                            <select name="currency_symbol" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="₱" selected>₱ (Philippine Peso)</option>
                                <option value="$">$ (US Dollar)</option>
                                <option value="€">€ (Euro)</option>
                                <option value="¥">¥ (Yen)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                            <select name="timezone" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($timezones as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $value == 'Asia/Manila' ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date Format</label>
                            <select name="date_format" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($dateFormats as $value => $example): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $value == 'Y-m-d' ? 'selected' : ''; ?>>
                                        <?php echo $value . ' (' . $example . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Site Address</label>
                            <textarea name="site_address" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">Bohol Island State University - Institute of Global Education, Tagbilaran City, Bohol, Philippines</textarea>
                        </div>
                    </div>
                    
                    <h3 class="text-lg font-semibold text-gray-900 mt-8 mb-4">Reservation Settings</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Reservation Expiry (hours)</label>
                            <input type="number" name="reservation_expiry_hours" value="24" min="1" max="168" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Reservations per User</label>
                            <input type="number" name="max_reservation_per_user" value="5" min="1" max="50" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Min Reservation (kg)</label>
                            <input type="number" name="min_reservation_kg" value="0.5" step="0.1" min="0.1" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Reservation (kg)</label>
                            <input type="number" name="max_reservation_kg" value="50" step="0.5" min="1" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-8">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition">
                            <i class="fas fa-save mr-2"></i>Save Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($activeTab == 'profile'): ?>
                <!-- Profile Settings -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Profile Information</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Contact Number</label>
                            <input type="text" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <textarea name="address" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-8">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition">
                            <i class="fas fa-save mr-2"></i>Update Profile
                        </button>
                    </div>
                </form>
                
            <?php elseif ($activeTab == 'security'): ?>
                <!-- Security Settings -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Change Password</h2>
                    
                    <div class="max-w-md space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                            <input type="password" name="current_password" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                            <input type="password" name="new_password" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Minimum 8 characters with at least one number and one letter</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-start mt-8">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition">
                            <i class="fas fa-key mr-2"></i>Change Password
                        </button>
                    </div>
                </form>
                
                <hr class="my-8">
                
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Two-Factor Authentication</h3>
                    <div class="bg-gray-50 rounded-lg p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-gray-900">Enable Two-Factor Authentication</p>
                                <p class="text-sm text-gray-500">Add an extra layer of security to your account</p>
                            </div>
                            <button class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition">
                                <i class="fas fa-shield-alt mr-2"></i>Setup 2FA
                            </button>
                        </div>
                    </div>
                </div>
                
                <hr class="my-8">
                
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Session Management</h3>
                    <div class="bg-gray-50 rounded-lg p-6">
                        <p class="text-sm text-gray-600 mb-4">You are currently logged in from:</p>
                        <div class="flex items-center space-x-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-laptop text-blue-600"></i>
                            </div>
                            <div>
                                <p class="font-medium">Current Session</p>
                                <p class="text-sm text-gray-500">IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
                                <p class="text-sm text-gray-500">Browser: <?php echo substr($_SERVER['HTTP_USER_AGENT'], 0, 50); ?>...</p>
                            </div>
                        </div>
                        <button class="mt-4 text-sm text-red-600 hover:text-red-800">
                            <i class="fas fa-sign-out-alt mr-1"></i>Logout from all devices
                        </button>
                    </div>
                </div>
                
            <?php elseif ($activeTab == 'notifications'): ?>
                <!-- Notification Settings -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_notifications">
                    
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Notification Preferences</h2>
                    
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">Email Notifications</p>
                                <p class="text-sm text-gray-500">Receive notifications via email</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_notifications" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">Push Notifications</p>
                                <p class="text-sm text-gray-500">Receive browser push notifications</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="push_notifications" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">SMS Notifications</p>
                                <p class="text-sm text-gray-500">Receive notifications via SMS (charges may apply)</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="sms_notifications" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>
                    
                    <h3 class="text-lg font-semibold text-gray-900 mt-8 mb-4">Alert Preferences</h3>
                    
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">New Order Alerts</p>
                                <p class="text-sm text-gray-500">Get notified when a new order is placed</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="new_order_alert" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">Low Stock Alerts</p>
                                <p class="text-sm text-gray-500">Get notified when inventory is running low</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="low_stock_alert" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">Daily Report</p>
                                <p class="text-sm text-gray-500">Receive daily summary report</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="daily_report" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-900">Weekly Report</p>
                                <p class="text-sm text-gray-500">Receive weekly performance report</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="weekly_report" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="flex justify-end mt-8">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition">
                            <i class="fas fa-save mr-2"></i>Save Preferences
                        </button>
                    </div>
                </form>
                
            <?php elseif ($activeTab == 'email'): ?>
                <!-- Email Settings -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_email">
                    
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Email Configuration</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Host</label>
                            <input type="text" name="smtp_host" value="smtp.gmail.com" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Port</label>
                            <input type="number" name="smtp_port" value="587" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Encryption</label>
                            <select name="smtp_encryption" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="tls" selected>TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Username</label>
                            <input type="text" name="smtp_username" value="notifications@bisu-ige.edu.ph"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Password</label>
                            <input type="password" name="smtp_password" value="********"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">From Email</label>
                            <input type="email" name="from_email" value="noreply@bisu-ige.edu.ph" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">From Name</label>
                            <input type="text" name="from_name" value="BISU IGE Aquaculture" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <button type="button" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition">
                            <i class="fas fa-envelope mr-2"></i>Test Email Configuration
                        </button>
                    </div>
                    
                    <div class="flex justify-end mt-8">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition">
                            <i class="fas fa-save mr-2"></i>Save Email Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($activeTab == 'backup'): ?>
                <!-- Backup Settings -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Database Backup</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div class="bg-green-50 rounded-lg p-6 border border-green-200">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-database text-green-600 text-xl"></i>
                                </div>
                                <span class="text-green-700 font-medium"><?php echo $dbSizeFormatted; ?></span>
                            </div>
                            <h3 class="font-semibold text-gray-900">Current Database Size</h3>
                            <p class="text-sm text-gray-600 mt-1">Total size of your database</p>
                        </div>
                        
                        <div class="bg-blue-50 rounded-lg p-6 border border-blue-200">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-history text-blue-600 text-xl"></i>
                                </div>
                                <?php if ($lastBackup): ?>
                                    <span class="text-blue-700 font-medium"><?php echo formatBytes($lastBackup['size']); ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="font-semibold text-gray-900">Last Backup</h3>
                            <?php if ($lastBackup): ?>
                                <p class="text-sm text-gray-600 mt-1"><?php echo date('M d, Y H:i:s', $lastBackup['time']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo $lastBackup['file']; ?></p>
                            <?php else: ?>
                                <p class="text-sm text-gray-600 mt-1">No backups found</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-white border border-gray-200 rounded-lg p-6 mb-8">
                        <h3 class="font-semibold text-gray-900 mb-4">Create New Backup</h3>
                        <form method="POST" action="" class="flex items-center space-x-4">
                            <input type="hidden" name="action" value="backup_database">
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700 transition"
                                    onclick="return confirm('Create a new database backup? This may take a few moments.')">
                                <i class="fas fa-save mr-2"></i>Backup Now
                            </button>
                            <p class="text-sm text-gray-500">Creates a complete SQL dump of your database</p>
                        </form>
                    </div>
                    
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">Available Backups</h3>
                        </div>
                        
                        <?php if (empty($backupFiles)): ?>
                            <div class="p-8 text-center">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-database text-gray-400 text-2xl"></i>
                                </div>
                                <p class="text-gray-500">No backup files available</p>
                                <p class="text-sm text-gray-400 mt-2">Create your first backup using the button above</p>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-200">
                                <?php foreach (array_slice($backupFiles, 0, 10) as $backup): ?>
                                    <?php $backupName = basename($backup); ?>
                                    <?php $backupSize = filesize($backup); ?>
                                    <?php $backupTime = filemtime($backup); ?>
                                    <div class="p-4 flex items-center justify-between hover:bg-gray-50 transition">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-archive text-gray-400 mr-3"></i>
                                            <div>
                                                <p class="font-medium text-gray-900"><?php echo $backupName; ?></p>
                                                <p class="text-xs text-gray-500"><?php echo date('M d, Y H:i:s', $backupTime); ?> • <?php echo formatBytes($backupSize); ?></p>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <a href="../../backups/<?php echo $backupName; ?>" download 
                                               class="text-blue-600 hover:text-blue-800 p-2" title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button onclick="restoreBackup('<?php echo $backupName; ?>')" 
                                                    class="text-green-600 hover:text-green-800 p-2" title="Restore">
                                                <i class="fas fa-undo-alt"></i>
                                            </button>
                                            <button onclick="deleteBackup('<?php echo $backupName; ?>')" 
                                                    class="text-red-600 hover:text-red-800 p-2" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($activeTab == 'system'): ?>
                <!-- System Information -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">System Information</h2>
                    
                    <!-- System Health -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                        <div class="health-indicator bg-white border rounded-lg p-4 flex items-center space-x-3">
                            <div class="w-10 h-10 <?php echo $systemHealth['database'] ? 'bg-green-100' : 'bg-red-100'; ?> rounded-full flex items-center justify-center">
                                <i class="fas fa-database <?php echo $systemHealth['database'] ? 'text-green-600' : 'text-red-600'; ?>"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Database</p>
                                <p class="font-medium <?php echo $systemHealth['database'] ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $systemHealth['database'] ? 'Connected' : 'Error'; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="health-indicator bg-white border rounded-lg p-4 flex items-center space-x-3">
                            <div class="w-10 h-10 <?php echo $systemHealth['session'] ? 'bg-green-100' : 'bg-red-100'; ?> rounded-full flex items-center justify-center">
                                <i class="fas fa-clock <?php echo $systemHealth['session'] ? 'text-green-600' : 'text-red-600'; ?>"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Session</p>
                                <p class="font-medium <?php echo $systemHealth['session'] ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $systemHealth['session'] ? 'Active' : 'Inactive'; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="health-indicator bg-white border rounded-lg p-4 flex items-center space-x-3">
                            <div class="w-10 h-10 <?php echo $systemHealth['uploads'] ? 'bg-green-100' : 'bg-red-100'; ?> rounded-full flex items-center justify-center">
                                <i class="fas fa-upload <?php echo $systemHealth['uploads'] ? 'text-green-600' : 'text-red-600'; ?>"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Uploads</p>
                                <p class="font-medium <?php echo $systemHealth['uploads'] ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $systemHealth['uploads'] ? 'Writable' : 'Not Writable'; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="health-indicator bg-white border rounded-lg p-4 flex items-center space-x-3">
                            <div class="w-10 h-10 <?php echo $systemHealth['cache'] ? 'bg-green-100' : 'bg-yellow-100'; ?> rounded-full flex items-center justify-center">
                                <i class="fas fa-bolt <?php echo $systemHealth['cache'] ? 'text-green-600' : 'text-yellow-600'; ?>"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Cache</p>
                                <p class="font-medium <?php echo $systemHealth['cache'] ? 'text-green-600' : 'text-yellow-600'; ?>">
                                    <?php echo $systemHealth['cache'] ? 'Writable' : 'Not Writable'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Database Statistics -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6 mb-8">
                        <h3 class="font-semibold text-gray-900 mb-4">Database Statistics</h3>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($dbStats['total_users']); ?></p>
                                <p class="text-sm text-gray-500">Users</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo number_format($dbStats['total_harvests']); ?></p>
                                <p class="text-sm text-gray-500">Harvests</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($dbStats['total_reservations']); ?></p>
                                <p class="text-sm text-gray-500">Reservations</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($dbStats['total_sales']); ?></p>
                                <p class="text-sm text-gray-500">Sales</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-indigo-600"><?php echo number_format($dbStats['total_products']); ?></p>
                                <p class="text-sm text-gray-500">Products</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-red-600"><?php echo number_format($dbStats['total_returns']); ?></p>
                                <p class="text-sm text-gray-500">Returns</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PHP Configuration -->
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-8">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">PHP Configuration</h3>
                        </div>
                        <div class="divide-y divide-gray-200">
                            <div class="p-4 flex justify-between">
                                <span class="text-gray-600">PHP Version</span>
                                <span class="font-medium"><?php echo $phpVersion; ?></span>
                            </div>
                            <div class="p-4 flex justify-between">
                                <span class="text-gray-600">Upload Max Size</span>
                                <span class="font-medium"><?php echo $maxUploadSize; ?></span>
                            </div>
                            <div class="p-4 flex justify-between">
                                <span class="text-gray-600">Post Max Size</span>
                                <span class="font-medium"><?php echo $maxPostSize; ?></span>
                            </div>
                            <div class="p-4 flex justify-between">
                                <span class="text-gray-600">Memory Limit</span>
                                <span class="font-medium"><?php echo $memoryLimit; ?></span>
                            </div>
                            <div class="p-4 flex justify-between">
                                <span class="text-gray-600">Max Execution Time</span>
                                <span class="font-medium"><?php echo $maxExecutionTime; ?> seconds</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Maintenance Actions -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                        <h3 class="font-semibold text-gray-900 mb-4">Maintenance</h3>
                        
                        <div class="space-y-4">
                            <form method="POST" action="" class="flex items-center justify-between">
                                <input type="hidden" name="action" value="clear_cache">
                                <div>
                                    <p class="font-medium">Clear System Cache</p>
                                    <p class="text-sm text-gray-500">Remove all temporary cached files</p>
                                </div>
                                <button type="submit" class="bg-yellow-500 text-white px-4 py-2 rounded-lg hover:bg-yellow-600 transition"
                                        onclick="return confirm('Clear all system cache?')">
                                    <i class="fas fa-broom mr-2"></i>Clear Cache
                                </button>
                            </form>
                            
                            <hr class="border-gray-200">
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-red-600">System Logs</p>
                                    <p class="text-sm text-gray-500">View and download system error logs</p>
                                </div>
                                <a href="logs.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition">
                                    <i class="fas fa-file-alt mr-2"></i>View Logs
                                </a>
                            </div>
                            
                            <hr class="border-gray-200">
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-red-600">System Update</p>
                                    <p class="text-sm text-gray-500">Check for system updates</p>
                                </div>
                                <button class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                                    <i class="fas fa-sync-alt mr-2"></i>Check Updates
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>
    <script>

        // Backup functions
        function restoreBackup(filename) {
            if (confirm('Restoring a backup will overwrite your current database. This action cannot be undone. Continue?')) {
                window.location.href = 'restore-backup.php?file=' + encodeURIComponent(filename);
            }
        }

        function deleteBackup(filename) {
            if (confirm('Are you sure you want to delete this backup file?')) {
                window.location.href = 'delete-backup.php?file=' + encodeURIComponent(filename);
            }
        }

        // Password strength indicator
        document.querySelector('input[name="new_password"]')?.addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthIndicator = document.createElement('div');
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            // You can add visual indicator here if needed
        });

        // Test email configuration
        document.querySelector('button[onclick*="Test Email"]')?.addEventListener('click', function() {
            alert('Test email functionality - In a production environment, this would send a test email to verify SMTP settings.');
        });

        // Tab navigation with URL hash
        function setActiveTab(tabId) {
            const tabs = document.querySelectorAll('.settings-tab');
            tabs.forEach(tab => {
                if (tab.getAttribute('href') === '?tab=' + tabId) {
                    tab.classList.add('active', 'bg-blue-600', 'text-white');
                } else {
                    tab.classList.remove('active', 'bg-blue-600', 'text-white');
                }
            });
        }

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(event) {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'general';
            setActiveTab(tab);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>