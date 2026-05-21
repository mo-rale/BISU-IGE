<?php
// includes/navbar.php - PROFESSIONAL LIGHT MODE VERSION (ACCOUNTING SUPPORT ADDED)
require_once __DIR__ . '/config.php';

// Get user data including unread notifications
$userData = [];
$unreadCount = 0;

if (SessionManager::isLoggedIn()) {
    try {
        $db = (new Database())->getConnection();
        $functions = new SystemFunctions();
        
        $userId = SessionManager::getUserId();
        $userData = $functions->getUserById($userId);

        if (!is_array($userData) || empty($userData)) {
            $userStmt = $db->prepare("SELECT * FROM users WHERE user_id = :id");
            $userStmt->execute([':id' => $userId]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow && is_array($userRow)) {
                $userData = $userRow;
            } else {
                $userData = [];
            }
        }

        if (!empty($userData) && is_array($userData)) {
            $unreadCount = $functions->getUnreadCount($userId);
        }
        
        if (empty($userData['profile_picture'])) {
            $defaultSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd"/></svg>';
            $userData['profile_picture'] = 'data:image/svg+xml;base64,' . base64_encode($defaultSvg);
        }
    } catch (Exception $e) {
        error_log("Navbar error: " . $e->getMessage());
        $userData = [];
    }
}

if (!is_array($userData)) {
    $userData = [];
}

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'];
$base_url .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$base_url = rtrim($base_url, '/');

function url($path = '') {
    global $base_url;
    $path = ltrim($path, '/');
    return rtrim($base_url, '/') . '/' . $path;
}

function root_url($path = '') {
    global $base_url;
    $parsed_url = parse_url($base_url);
    $root_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    
    if (isset($parsed_url['port'])) {
        $root_url .= ':' . $parsed_url['port'];
    }
    
    $path_parts = explode('/', trim($parsed_url['path'] ?? '', '/'));
    $project_root = $path_parts[0] ?? '';
    
    if (!empty($project_root)) {
        $root_url .= '/' . $project_root;
    }
    
    $path = ltrim($path, '/');
    return $root_url . ($path ? '/' . $path : '');
}

$root_url = root_url();

$userRole = SessionManager::getUserRole();
if (empty($userRole) && !empty($userData['role'])) {
    $userRole = $userData['role'];
}

$isManager = ($userRole === 'manager');
$isStaff = ($userRole === 'staff');
$isAccounting = ($userRole === 'accounting');
$isCashier = ($userRole === 'staff' || $userRole === 'manager');
$isStandard = ($userRole === 'standard');
$isAdmin = ($isManager || $isStaff);
$isLoggedIn = SessionManager::isLoggedIn();

$displayName = '';
$initials = 'U';

if (!empty($userData['full_name']) && is_string($userData['full_name'])) {
    $displayName = $userData['full_name'];
    $nameParts = explode(' ', $userData['full_name']);
    $initialsArray = [];
    foreach ($nameParts as $part) {
        if (!empty($part) && is_string($part)) {
            $initialsArray[] = strtoupper(substr($part, 0, 1));
        }
    }
    if (!empty($initialsArray)) {
        $initials = implode('', array_slice($initialsArray, 0, 2));
    }
} elseif (!empty($userData['email']) && is_string($userData['email'])) {
    $displayName = explode('@', $userData['email'])[0];
    $initials = strtoupper(substr($displayName, 0, 2)) ?: 'U';
}

$displayRole = null;
if ($isManager) $displayRole = 'Manager';
elseif ($isAccounting) $displayRole = 'Accounting';
elseif ($isStaff) $displayRole = 'Staff';
elseif ($isCashier && !$isManager) $displayRole = 'Cashier';
elseif ($isStandard) $displayRole = $displayName ?: 'User';

$current_page = basename($_SERVER['SCRIPT_NAME']);
$current_path = $_SERVER['REQUEST_URI'];

$logo_path = $root_url . '/assets/bisu-logo.png';
$logo_fallback_path = $root_url . '/assets/bisu-logo.jpg';
$logo_default_svg = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\' viewBox=\'0 0 40 40\'%3E%3Crect width=\'40\' height=\'40\' fill=\'%231e3a8a\'/%3E%3Ctext x=\'8\' y=\'28\' font-family=\'Arial\' font-size=\'20\' fill=\'%23ffffff\'%3EBISU%3C/text%3E%3C/svg%3E';
?>

<!-- Professional Navbar Styles -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');
    
    :root {
        --nav-bg: #ffffff;
        --nav-text: #0f172a;
        --nav-text-secondary: #64748b;
        --nav-border: #e2e8f0;
        --nav-hover: #f8fafc;
        --nav-active: #0f172a;
        --nav-accent: #0ea5e9;
        --nav-accent-light: #e0f2fe;
        --nav-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.03);
        --nav-dropdown-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
        --nav-radius: 12px;
        --nav-transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    body {
        padding-top: 0;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    
    .navbar-professional {
        background: var(--nav-bg);
        border-bottom: 1px solid var(--nav-border);
        box-shadow: var(--nav-shadow);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }
    
    .nav-brand-text {
        font-family: 'Playfair Display', serif;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    
    .nav-link-professional {
        position: relative;
        color: var(--nav-text-secondary);
        font-weight: 500;
        font-size: 0.875rem;
        letter-spacing: -0.01em;
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
        transition: var(--nav-transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .nav-link-professional:hover {
        color: var(--nav-text);
        background-color: var(--nav-hover);
    }
    
    .nav-link-professional.active {
        color: var(--nav-text);
        background-color: var(--nav-accent-light);
    }
    
    .nav-link-professional.active::after {
        content: '';
        position: absolute;
        bottom: -17px;
        left: 50%;
        transform: translateX(-50%);
        width: 20px;
        height: 3px;
        background: var(--nav-accent);
        border-radius: 3px 3px 0 0;
    }
    
    .nav-avatar {
        background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
        box-shadow: 0 2px 8px rgba(14, 165, 233, 0.25);
    }
    
    .nav-dropdown-professional {
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--nav-border);
        box-shadow: var(--nav-dropdown-shadow);
        border-radius: var(--nav-radius);
        overflow: hidden;
    }
    
    .nav-dropdown-item-professional {
        display: flex;
        align-items: center;
        padding: 0.625rem 1rem;
        color: var(--nav-text);
        font-size: 0.875rem;
        font-weight: 500;
        transition: var(--nav-transition);
        border-radius: 8px;
        margin: 0 0.5rem;
    }
    
    .nav-dropdown-item-professional:hover {
        background-color: var(--nav-hover);
        color: var(--nav-accent);
    }
    
    .nav-notification-badge {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
    }
    
    .nav-mobile-item {
        display: flex;
        align-items: center;
        padding: 0.875rem 1.25rem;
        color: var(--nav-text-secondary);
        font-weight: 500;
        font-size: 0.9375rem;
        border-radius: 10px;
        margin: 0.25rem 0.75rem;
        transition: var(--nav-transition);
    }
    
    .nav-mobile-item:hover {
        background-color: var(--nav-hover);
        color: var(--nav-text);
    }
    
    .nav-mobile-item.active {
        background-color: var(--nav-accent-light);
        color: var(--nav-accent);
    }
    
    .nav-divider {
        height: 1px;
        background: linear-gradient(to right, transparent, var(--nav-border), transparent);
        margin: 0.5rem 0;
    }
    
    html {
        scroll-behavior: smooth;
    }
    
    .nav-scroll::-webkit-scrollbar {
        width: 5px;
    }
    
    .nav-scroll::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .nav-scroll::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    
    @keyframes navDropdownIn {
        from {
            opacity: 0;
            transform: translateY(-8px) scale(0.96);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    .nav-dropdown-animate {
        animation: navDropdownIn 0.2s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }
    
    @keyframes modalFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes modalSlideUp {
        from {
            transform: translateY(20px) scale(0.95);
            opacity: 0;
        }
        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }
    
    .modal-backdrop {
        animation: modalFadeIn 0.2s ease forwards;
    }
    
    .modal-content {
        animation: modalSlideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }
</style>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden overflow-y-auto h-full w-full z-50 modal-backdrop">
    <div class="relative top-24 mx-auto p-6 border-0 w-full max-w-sm shadow-2xl rounded-2xl bg-white modal-content">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-50 mb-5 ring-4 ring-red-50">
                <i class="fas fa-sign-out-alt text-red-500 text-xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2 font-['Playfair_Display']">Confirm Logout</h3>
            <p class="text-sm text-gray-500 mb-6 leading-relaxed">
                Are you sure you want to end your session? You'll need to sign in again to access your account.
            </p>
            <div class="flex justify-center space-x-3">
                <button onclick="closeLogoutModal()" 
                        class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-300 transition-all duration-200">
                    Cancel
                </button>
                <a href="javascript:void(0)" onclick="confirmLogout()" 
                   class="flex-1 px-4 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-xl hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-all duration-200 shadow-lg shadow-red-200">
                    Logout
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Sticky Professional Navbar -->
<nav class="sticky top-0 z-40 navbar-professional">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
            <!-- Logo Section -->
            <div class="flex items-center gap-8">
                <div class="flex-shrink-0 flex items-center">
                    <a href="<?php echo $root_url; ?>/index.php" class="flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center overflow-hidden border border-gray-200 shadow-sm group-hover:shadow-md transition-shadow duration-300">
                            <img src="<?php echo $logo_path; ?>" 
                                 alt="BISU Logo" 
                                 class="w-full h-full object-contain"
                                 id="main-logo"
                                 onerror="this.onerror=null; this.src='<?php echo $logo_fallback_path; ?>'; 
                                          this.onerror=function(){
                                              this.onerror=null; 
                                              this.src='<?php echo $logo_default_svg; ?>';
                                          };">
                        </div>
                        <div class="flex flex-col">
                            <span class="nav-brand-text text-lg text-gray-900 leading-tight">
                                BISU IGE
                            </span>
                            <span class="text-[10px] font-semibold text-gray-400 tracking-widest uppercase leading-tight">Aquaculture</span>
                        </div>
                    </a>
                </div>
                
                <!-- DESKTOP NAVIGATION -->
                <div class="hidden md:flex md:items-center md:gap-1">
                    
                    <?php if(!$isAdmin && !$isAccounting && !$isLoggedIn): ?>
                        <a href="<?php echo $root_url; ?>/index.php" 
                           class="nav-link-professional <?php echo $current_page == 'index.php' && !isset($_GET['view']) ? 'active' : ''; ?>">
                            <i class="fas fa-home text-sm"></i>
                            Home
                        </a>
                    <?php endif; ?>
                    
                    <?php if($isManager || $isStaff): ?>
                        <a href="<?php echo $root_url; ?>/manager/dashboard.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/dashboard.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt text-sm"></i>
                            Dashboard
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/harvest.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/harvest.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt text-sm"></i>
                            Harvest
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/products.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/products.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-fish text-sm"></i>
                            Products
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/orders.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/orders.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check text-sm"></i>
                            Orders
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/returns.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/returns.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-undo-alt text-sm"></i>
                            Returns
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/process_deduction.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/process_deduction.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calculator text-sm"></i>
                            Deductions
                        </a>
                        <a href="<?php echo $root_url; ?>/manager/reports.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/manager/reports.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line text-sm"></i>
                            Reports
                        </a>
                    
                    <?php elseif($isAccounting): ?>
                        <a href="<?php echo $root_url; ?>/accounting/dashboard.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/accounting/dashboard.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt text-sm"></i>
                            Dashboard
                        </a>
                        <a href="<?php echo $root_url; ?>/accounting/unpaid-orders.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/accounting/unpaid-orders.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clock text-sm"></i>
                            Unpaid Orders
                        </a>
                        <a href="<?php echo $root_url; ?>/accounting/payment-history.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/accounting/payment-history.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-history text-sm"></i>
                            Payment History
                        </a>
                        <a href="<?php echo $root_url; ?>/accounting/reports.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/accounting/reports.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar text-sm"></i>
                            Financial Reports
                        </a>
                    
                    <?php elseif($isStandard): ?>
                        <a href="<?php echo $root_url; ?>/user/dashboard.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/user/dashboard.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt text-sm"></i>
                            Dashboard
                        </a>
                        <a href="<?php echo $root_url; ?>/user/products.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/user/products.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-fish text-sm"></i>
                            Available Fish
                        </a>
                        <a href="<?php echo $root_url; ?>/user/orders.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/user/orders.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check text-sm"></i>
                            My Orders
                        </a>
                        <a href="<?php echo $root_url; ?>/user/deduction_history.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/user/deduction_history.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave text-sm"></i>
                            Deductions
                        </a>
                        <a href="<?php echo $root_url; ?>/user/returns.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/user/returns.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-undo-alt text-sm"></i>
                            Returns
                        </a>
                    <?php else: ?>
                        <a href="<?php echo $root_url; ?>/products.php" 
                           class="nav-link-professional <?php echo strpos($current_path, '/products.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-fish text-sm"></i>
                            Available Fish
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right side navigation -->
            <div class="flex items-center gap-2">
                <?php if($isLoggedIn): ?>
                    <!-- Notification Bell - Only for standard users -->
                    <?php if($isStandard): ?>
                    <div class="relative mr-1" id="notification-container">
                        <button type="button" onclick="toggleNotifications()" 
                                class="relative p-2.5 text-gray-500 hover:text-gray-900 focus:outline-none transition-all duration-200 rounded-xl hover:bg-gray-100 z-10">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center transition-colors">
                                <i class="fas fa-bell text-lg <?php echo $unreadCount > 0 ? 'text-sky-600' : 'text-gray-400'; ?>"></i>
                            </div>
                            <?php if($unreadCount > 0): ?>
                                <span class="absolute top-1.5 right-1.5 nav-notification-badge text-white text-[10px] font-bold rounded-full h-5 w-5 flex items-center justify-center ring-2 ring-white pointer-events-none">
                                    <?php echo min($unreadCount, 9); ?><?php echo $unreadCount > 9 ? '+' : ''; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        
                        <div id="notification-dropdown" 
                             class="hidden absolute right-0 mt-3 w-80 bg-white rounded-2xl shadow-2xl z-50 border border-gray-100 nav-dropdown-professional nav-dropdown-animate">
                            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                                <h3 class="font-bold text-gray-900 text-sm">Notifications</h3>
                                <button type="button" onclick="markAllAsRead()" class="text-xs font-semibold text-sky-600 hover:text-sky-700 transition-colors">
                                    Mark all read
                                </button>
                            </div>
                            <div class="max-h-80 overflow-y-auto nav-scroll" id="notification-list">
                                <div class="p-8 text-center text-gray-400">
                                    <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-3">
                                        <i class="fas fa-bell-slash text-gray-300 text-xl"></i>
                                    </div>
                                    <p class="text-sm font-medium">Loading notifications...</p>
                                </div>
                            </div>
                            <div class="p-3 border-t border-gray-100 text-center bg-gray-50/30">
                                <a href="<?php echo $root_url; ?>/user/notifications.php" class="text-sm font-semibold text-sky-600 hover:text-sky-700 transition-colors">
                                    View all notifications
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Menu -->
                    <div class="relative" id="user-menu-container">
                        <button type="button" onclick="toggleUserMenu()" 
                                class="flex items-center gap-2.5 pl-2 pr-3 py-1.5 text-gray-700 hover:text-gray-900 focus:outline-none rounded-xl hover:bg-gray-100 transition-all duration-200">
                            <div class="w-9 h-9 rounded-full nav-avatar flex items-center justify-center">
                                <span class="font-bold text-white text-sm tracking-wide">
                                    <?php echo htmlspecialchars($initials); ?>
                                </span>
                            </div>
                            <div class="hidden sm:flex flex-col items-start leading-tight">
                                <span class="text-sm font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($displayRole ?? ($displayName ?: 'User')); ?>
                                </span>
                                <span class="text-[11px] text-gray-400 font-medium"><?php echo $isManager ? 'Admin' : ($isAccounting ? 'Finance' : ($isStaff ? 'Staff' : 'Member')); ?></span>
                            </div>
                            <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform duration-200" id="user-chevron"></i>
                        </button>
                        
                        <!-- User Dropdown -->
                        <div id="user-dropdown" 
                             class="hidden absolute right-0 mt-3 w-64 bg-white rounded-2xl shadow-2xl z-50 border border-gray-100 nav-dropdown-professional nav-dropdown-animate">
                            <div class="p-4 border-b border-gray-100 bg-gray-50/50">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 rounded-full nav-avatar flex items-center justify-center flex-shrink-0">
                                        <span class="font-bold text-white text-sm">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-gray-900 truncate">
                                            <?php echo htmlspecialchars($displayName ?: 'User'); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate font-medium">
                                            <?php echo htmlspecialchars($userData['email'] ?? ''); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="py-2 px-1">
                                <?php if($isManager || $isStaff): ?>
                                    <a href="<?php echo $root_url; ?>/manager/inventory_report.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-tachometer-alt text-gray-400 w-5 text-center mr-2 text-sm"></i> Inventory Report
                                    </a>
                                    <a href="<?php echo $root_url; ?>/manager/process_returns.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-calendar-check text-gray-400 w-5 text-center mr-2 text-sm"></i> Returns
                                    </a>
                                    <a href="<?php echo $root_url; ?>/manager/reports.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-chart-bar text-gray-400 w-5 text-center mr-2 text-sm"></i> Reports
                                    </a>
                                    <?php if($isManager): ?>
                                    <a href="<?php echo $root_url; ?>/manager/users.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-users text-gray-400 w-5 text-center mr-2 text-sm"></i> Users
                                    </a>
                                    <?php endif; ?>
                                
                                <?php elseif($isAccounting): ?>
                                    <a href="<?php echo $root_url; ?>/accounting/dashboard.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-tachometer-alt text-gray-400 w-5 text-center mr-2 text-sm"></i> Dashboard
                                    </a>
                                    <a href="<?php echo $root_url; ?>/accounting/unpaid-orders.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-clock text-gray-400 w-5 text-center mr-2 text-sm"></i> Unpaid Orders
                                    </a>
                                    <a href="<?php echo $root_url; ?>/accounting/payment-history.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-history text-gray-400 w-5 text-center mr-2 text-sm"></i> Payment History
                                    </a>
                                    <a href="<?php echo $root_url; ?>/accounting/reports.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-file-invoice-dollar text-gray-400 w-5 text-center mr-2 text-sm"></i> Financial Reports
                                    </a>
                                
                                <?php elseif($isCashier && !$isManager): ?>
                                    <a href="<?php echo $root_url; ?>/cashier/dashboard.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-tachometer-alt text-gray-400 w-5 text-center mr-2 text-sm"></i> Dashboard
                                    </a>
                                    <a href="<?php echo $root_url; ?>/cashier/sales.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-receipt text-gray-400 w-5 text-center mr-2 text-sm"></i> Transactions
                                    </a>
                                    <a href="<?php echo $root_url; ?>/cashier/daily-report.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-chart-bar text-gray-400 w-5 text-center mr-2 text-sm"></i> Daily Report
                                    </a>
                                    
                                <?php else: ?>
                                    <a href="<?php echo $root_url; ?>/user/dashboard.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-tachometer-alt text-gray-400 w-5 text-center mr-2 text-sm"></i> Dashboard
                                    </a>
                                    <a href="<?php echo $root_url; ?>/user/profile.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-user-circle text-gray-400 w-5 text-center mr-2 text-sm"></i> Profile
                                    </a>
                                    <a href="<?php echo $root_url; ?>/user/return_request.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-shopping-bag text-gray-400 w-5 text-center mr-2 text-sm"></i> My Returns
                                    </a>
                                    <a href="<?php echo $root_url; ?>/user/deduction_history.php" class="nav-dropdown-item-professional">
                                        <i class="fas fa-money-bill-wave text-gray-400 w-5 text-center mr-2 text-sm"></i> Salary Deductions
                                    </a>
                                <?php endif; ?>
                                
                                <div class="nav-divider"></div>
                                
                                <!-- Customer Service Link -->
                                <a href="<?php echo $root_url; ?>/customer-service.php" 
                                   class="nav-dropdown-item-professional">
                                    <i class="fas fa-headset text-gray-400 w-5 text-center mr-2 text-sm"></i>
                                    <span class="font-medium">Customer Service</span>
                                </a>
                                
                                <div class="nav-divider"></div>
                                
                                <a href="javascript:void(0)" onclick="showLogoutModal()" 
                                   class="nav-dropdown-item-professional text-red-600 hover:text-red-700 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt text-red-400 w-5 text-center mr-2 text-sm"></i>
                                    <span class="font-semibold">Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-3">
                        <a href="<?php echo $root_url; ?>/login.php" 
                           class="text-gray-600 hover:text-gray-900 font-semibold text-sm transition-colors duration-200 px-3 py-2 rounded-xl hover:bg-gray-100">
                            Sign In
                        </a>
                        <a href="<?php echo $root_url; ?>/register.php" 
                           class="bg-slate-900 text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-slate-800 transition-all duration-200 shadow-lg shadow-slate-900/20">
                            Get Started
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Mobile Menu Button -->
                <button type="button" onclick="toggleMobileMenu()" 
                        class="md:hidden ml-2 inline-flex items-center justify-center p-2.5 rounded-xl text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition-all duration-200">
                    <i class="fas fa-bars text-lg"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- MOBILE MENU -->
    <div class="md:hidden hidden bg-white border-t border-gray-100" id="mobile-menu">
        <div class="py-3 px-2 space-y-1 max-h-[80vh] overflow-y-auto nav-scroll">
            <?php if(!$isAdmin && !$isAccounting && !$isLoggedIn): ?>
                <a href="<?php echo $root_url; ?>/index.php" class="nav-mobile-item <?php echo $current_page == 'index.php' && !isset($_GET['view']) ? 'active' : ''; ?>">
                    <i class="fas fa-home w-6 text-center mr-2"></i>Home
                </a>
            <?php endif; ?>
            
            <?php if($isManager || $isStaff): ?>
                <a href="<?php echo $root_url; ?>/manager/dashboard.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/dashboard.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt w-6 text-center mr-2"></i>Dashboard
                </a>
                <a href="<?php echo $root_url; ?>/manager/harvest.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/harvest.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt w-6 text-center mr-2"></i>Harvest
                </a>
                <a href="<?php echo $root_url; ?>/manager/products.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/products.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-fish w-6 text-center mr-2"></i>Products
                </a>
                <a href="<?php echo $root_url; ?>/manager/orders.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/orders.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check w-6 text-center mr-2"></i>Orders
                </a>
                <a href="<?php echo $root_url; ?>/manager/process_deduction.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/process_deduction.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-calculator w-6 text-center mr-2"></i>Process Deductions
                </a>
                <a href="<?php echo $root_url; ?>/manager/returns.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/returns.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-undo-alt w-6 text-center mr-2"></i>Returns
                </a>
                <?php if($isManager): ?>
                <a href="<?php echo $root_url; ?>/manager/users.php" class="nav-mobile-item <?php echo strpos($current_path, '/manager/users.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-users w-6 text-center mr-2"></i>Users
                </a>
                <?php endif; ?>
                
                <div class="nav-divider"></div>
                
                <a href="<?php echo $root_url; ?>/customer-service.php" class="nav-mobile-item">
                    <i class="fas fa-headset w-6 text-center mr-2"></i>Customer Service
                </a>
                
                <a href="javascript:void(0)" onclick="showLogoutModal()" class="nav-mobile-item text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-6 text-center mr-2"></i>Logout
                </a>
                
            <?php elseif($isAccounting): ?>
                <a href="<?php echo $root_url; ?>/accounting/dashboard.php" class="nav-mobile-item <?php echo strpos($current_path, '/accounting/dashboard.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt w-6 text-center mr-2"></i>Dashboard
                </a>
                <a href="<?php echo $root_url; ?>/accounting/unpaid-orders.php" class="nav-mobile-item <?php echo strpos($current_path, '/accounting/unpaid-orders.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-clock w-6 text-center mr-2"></i>Unpaid Orders
                </a>
                <a href="<?php echo $root_url; ?>/accounting/payment-history.php" class="nav-mobile-item <?php echo strpos($current_path, '/accounting/payment-history.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-history w-6 text-center mr-2"></i>Payment History
                </a>
                <a href="<?php echo $root_url; ?>/accounting/reports.php" class="nav-mobile-item <?php echo strpos($current_path, '/accounting/reports.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar w-6 text-center mr-2"></i>Financial Reports
                </a>
                
                <div class="nav-divider"></div>
                
                <a href="<?php echo $root_url; ?>/customer-service.php" class="nav-mobile-item">
                    <i class="fas fa-headset w-6 text-center mr-2"></i>Customer Service
                </a>
                
                <a href="javascript:void(0)" onclick="showLogoutModal()" class="nav-mobile-item text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-6 text-center mr-2"></i>Logout
                </a>
                
            <?php elseif($isCashier && !$isManager): ?>
                <a href="<?php echo $root_url; ?>/cashier/dashboard.php" class="nav-mobile-item <?php echo strpos($current_path, '/cashier/dashboard.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt w-6 text-center mr-2"></i>Dashboard
                </a>
                <a href="<?php echo $root_url; ?>/cashier/sales.php" class="nav-mobile-item <?php echo strpos($current_path, '/cashier/sales.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-cash-register w-6 text-center mr-2"></i>Sales
                </a>
                <a href="<?php echo $root_url; ?>/cashier/payments.php" class="nav-mobile-item <?php echo strpos($current_path, '/cashier/payments.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card w-6 text-center mr-2"></i>Payments
                </a>
                <a href="<?php echo $root_url; ?>/cashier/daily-report.php" class="nav-mobile-item <?php echo strpos($current_path, '/cashier/daily-report.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar w-6 text-center mr-2"></i>Reports
                </a>
                
                <div class="nav-divider"></div>
                
                <a href="<?php echo $root_url; ?>/customer-service.php" class="nav-mobile-item">
                    <i class="fas fa-headset w-6 text-center mr-2"></i>Customer Service
                </a>
                
                <a href="javascript:void(0)" onclick="showLogoutModal()" class="nav-mobile-item text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-6 text-center mr-2"></i>Logout
                </a>
                
            <?php elseif($isStandard): ?>
                <a href="<?php echo $root_url; ?>/user/dashboard.php" class="nav-mobile-item <?php echo strpos($current_path, '/user/dashboard.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt w-6 text-center mr-2"></i>Dashboard
                </a>
                <a href="<?php echo $root_url; ?>/user/products.php" class="nav-mobile-item <?php echo strpos($current_path, '/user/products.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-fish w-6 text-center mr-2"></i>Available Fish
                </a>
                <a href="<?php echo $root_url; ?>/user/orders.php" class="nav-mobile-item <?php echo strpos($current_path, '/user/orders.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-bag w-6 text-center mr-2"></i>My Orders
                </a>
                <a href="<?php echo $root_url; ?>/user/deduction_history.php" class="nav-mobile-item <?php echo strpos($current_path, '/user/deduction_history.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave w-6 text-center mr-2"></i>Salary Deductions
                </a>
                
                <div class="nav-divider"></div>
                
                <a href="<?php echo $root_url; ?>/customer-service.php" class="nav-mobile-item">
                    <i class="fas fa-headset w-6 text-center mr-2"></i>Customer Service
                </a>
                
                <a href="javascript:void(0)" onclick="showLogoutModal()" class="nav-mobile-item text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-6 text-center mr-2"></i>Logout
                </a>
                
            <?php else: ?>
                <a href="<?php echo $root_url; ?>/login.php" class="nav-mobile-item">
                    <i class="fas fa-sign-in-alt w-6 text-center mr-2"></i>Login
                </a>
                <a href="<?php echo $root_url; ?>/register.php" class="nav-mobile-item">
                    <i class="fas fa-user-plus w-6 text-center mr-2"></i>Register
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- JavaScript -->
<script>
// ========== CONFIGURATION ==========
const BASE_URL = (() => {
    const path = window.location.pathname;
    const match = path.match(/^(\/BISU[_-]IGE)/i);
    if (match) return match[1];
    const parts = path.split('/').filter(p => p);
    return parts.length > 0 ? '/' + parts[0] : '';
})();

const API_URL = BASE_URL + '/api/notifications.php';

console.log('BASE_URL:', BASE_URL);
console.log('API_URL:', API_URL);

// Global state
let notificationDropdownOpen = false;
let userMenuOpen = false;
let mobileMenuOpen = false;

// ========== MENU TOGGLES ==========
function toggleNotifications() {
    const dropdown = document.getElementById('notification-dropdown');
    if (!dropdown) return;
    
    notificationDropdownOpen = !notificationDropdownOpen;
    dropdown.classList.toggle('hidden', !notificationDropdownOpen);
    
    if (notificationDropdownOpen) {
        if (userMenuOpen) {
            document.getElementById('user-dropdown')?.classList.add('hidden');
            userMenuOpen = false;
            const chevron = document.getElementById('user-chevron');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
        if (mobileMenuOpen) {
            document.getElementById('mobile-menu')?.classList.add('hidden');
            mobileMenuOpen = false;
        }
        <?php if($isStandard): ?>
        loadNotifications();
        <?php endif; ?>
    }
}

function toggleUserMenu() {
    const dropdown = document.getElementById('user-dropdown');
    const chevron = document.getElementById('user-chevron');
    if (!dropdown) return;
    
    userMenuOpen = !userMenuOpen;
    dropdown.classList.toggle('hidden', !userMenuOpen);
    if (chevron) chevron.style.transform = userMenuOpen ? 'rotate(180deg)' : 'rotate(0deg)';
    
    if (userMenuOpen) {
        if (notificationDropdownOpen) {
            document.getElementById('notification-dropdown')?.classList.add('hidden');
            notificationDropdownOpen = false;
        }
        if (mobileMenuOpen) {
            document.getElementById('mobile-menu')?.classList.add('hidden');
            mobileMenuOpen = false;
        }
    }
}

function toggleMobileMenu() {
    const menu = document.getElementById('mobile-menu');
    if (!menu) return;
    
    mobileMenuOpen = !mobileMenuOpen;
    menu.classList.toggle('hidden', !mobileMenuOpen);
    
    if (mobileMenuOpen) {
        if (notificationDropdownOpen) {
            document.getElementById('notification-dropdown')?.classList.add('hidden');
            notificationDropdownOpen = false;
        }
        if (userMenuOpen) {
            document.getElementById('user-dropdown')?.classList.add('hidden');
            userMenuOpen = false;
            const chevron = document.getElementById('user-chevron');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    }
}

// ========== LOGOUT ==========
function showLogoutModal() {
    document.getElementById('logoutModal')?.classList.remove('hidden');
    if (notificationDropdownOpen) {
        document.getElementById('notification-dropdown')?.classList.add('hidden');
        notificationDropdownOpen = false;
    }
    if (userMenuOpen) {
        document.getElementById('user-dropdown')?.classList.add('hidden');
        userMenuOpen = false;
        const chevron = document.getElementById('user-chevron');
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
    if (mobileMenuOpen) {
        document.getElementById('mobile-menu')?.classList.add('hidden');
        mobileMenuOpen = false;
    }
}

function closeLogoutModal() {
    document.getElementById('logoutModal')?.classList.add('hidden');
}

function confirmLogout() {
    window.location.href = BASE_URL + '/logout.php';
}

// ========== NOTIFICATIONS ==========
<?php if($isStandard): ?>
async function loadNotifications() {
    const container = document.getElementById('notification-list');
    if (!container) return;
    
    container.innerHTML = `
        <div class="p-8 text-center">
            <div class="inline-block w-8 h-8 border-2 border-gray-300 border-t-sky-600 rounded-full animate-spin"></div>
            <p class="text-sm text-gray-400 mt-2">Loading...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`${API_URL}?limit=5`, {
            method: 'GET',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.message || 'API error');
        
        const notifications = result.data?.notifications || [];
        const unreadCount = result.data?.unread_count || 0;
        
        const badge = document.querySelector('#notification-container .nav-notification-badge');
        if (badge) {
            if (unreadCount > 0) {
                badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        
        if (notifications.length === 0) {
            container.innerHTML = `
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-bell-slash text-3xl mb-2"></i>
                    <p class="text-sm">No notifications</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        for (const n of notifications) {
            const isUnread = !n.is_read;
            html += `
                <div class="p-3 border-b hover:bg-gray-50 transition ${isUnread ? 'bg-sky-50' : ''}">
                    <div class="flex gap-2">
                        <div class="flex-shrink-0">
                            <i class="${getNotificationIcon(n.type)} text-gray-400 w-5"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold ${isUnread ? 'text-sky-700' : 'text-gray-800'}">${escapeHtml(n.title)}</p>
                            <p class="text-xs text-gray-500 mt-1">${escapeHtml(n.message)}</p>
                            <p class="text-xs text-gray-400 mt-1">${getTimeAgo(n.created_at)}</p>
                        </div>
                        ${isUnread ? `
                        <button onclick="markAsRead(${n.notification_id})" class="text-xs text-sky-600 hover:text-sky-700">
                            Mark read
                        </button>
                        ` : ''}
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Load error:', error);
        container.innerHTML = `
            <div class="p-8 text-center text-red-500">
                <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                <p class="text-sm">${escapeHtml(error.message)}</p>
                <button onclick="loadNotifications()" class="mt-2 text-xs text-sky-600 underline">Retry</button>
            </div>
        `;
    }
}

async function markAsRead(notificationId) {
    try {
        const response = await fetch(API_URL, {
            method: 'PUT',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: notificationId })
        });
        const result = await response.json();
        if (result.success) {
            await loadNotifications();
            location.reload();
        }
    } catch (error) {
        console.error('Mark as read error:', error);
    }
}

async function markAllAsRead() {
    try {
        const response = await fetch(`${API_URL}?action=mark_all_read`, {
            method: 'POST',
            credentials: 'include'
        });
        const result = await response.json();
        if (result.success) {
            await loadNotifications();
            location.reload();
        }
    } catch (error) {
        console.error('Mark all error:', error);
    }
}
<?php endif; ?>

// ========== HELPERS ==========
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function getTimeAgo(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function getNotificationIcon(type) {
    const icons = {
        'welcome': 'fas fa-hand-peace',
        'order_confirmation': 'fas fa-check-circle',
        'harvest_announcement': 'fas fa-fish',
        'pickup_reminder': 'fas fa-clock',
        'return_update': 'fas fa-undo-alt',
        'profile': 'fas fa-user',
        'system': 'fas fa-info-circle'
    };
    return icons[type] || 'fas fa-bell';
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', function() {
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        const notifContainer = document.getElementById('notification-container');
        const userContainer = document.getElementById('user-menu-container');
        
        if (notifContainer && !notifContainer.contains(e.target) && notificationDropdownOpen) {
            document.getElementById('notification-dropdown')?.classList.add('hidden');
            notificationDropdownOpen = false;
        }
        if (userContainer && !userContainer.contains(e.target) && userMenuOpen) {
            document.getElementById('user-dropdown')?.classList.add('hidden');
            userMenuOpen = false;
            const chevron = document.getElementById('user-chevron');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    });
    
    // Escape key closes modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogoutModal();
    });
});
</script>