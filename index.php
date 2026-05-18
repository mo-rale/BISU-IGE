<?php
// index.php - Professional Landing Page (NO LOGIN REQUIRED)
require_once 'includes/config.php';
require_once 'includes/session.php';

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();

// Initialize debug mode
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === 'true';

function getDebugInfo($db, $products, $announcements, $userId) {
    $debug_info = [
        'timestamp' => date('Y-m-d H:i:s'),
        'products_count' => count($products),
        'announcements_count' => count($announcements),
        'user_id' => $userId,
        'session_data' => $_SESSION,
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s')
    ];

    try {
        $connection = $db->getConnection();
        $debug_info['database_connection'] = 'Connected successfully';

        $tables = ['fish_products', 'announcements', 'users', 'orders'];
        foreach ($tables as $table) {
            try {
                $stmt = $connection->query("SELECT COUNT(*) FROM $table");
                $debug_info["table_$table"] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $debug_info["table_$table"] = 'Error: ' . $e->getMessage();
            }
        }

        $stmt = $connection->query("SELECT product_id, fish_name, available_quantity, price_per_kg FROM fish_products WHERE available_quantity > 0 LIMIT 3");
        $debug_info['sample_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $debug_info['database_connection'] = 'Failed: ' . $e->getMessage();
    }

    return $debug_info;
}

$products = [];
$allProducts = [];
$announcements = [];
$init_error = null;

try {
    $db = (new Database())->getConnection();

    $productSql = "SELECT 
                    product_id,
                    fish_name,
                    description,
                    price_per_kg,
                    available_quantity,
                    unit,
                    harvest_id,
                    created_at as harvest_date,
                    updated_at
                  FROM fish_products 
                  WHERE available_quantity > 0
                  ORDER BY created_at DESC";

    $productStmt = $db->query($productSql);
    $allProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    $products = array_slice($allProducts, 0, 6);

    try {
        $checkTable = $db->query("SELECT to_regclass('public.announcements')");
        $tableExists = $checkTable && $checkTable->fetchColumn();

        if ($tableExists) {
            $announcementSql = "SELECT 
                                announcement_id,
                                title,
                                content,
                                approximate_pieces,
                                harvest_date,
                                created_at,
                                is_active
                              FROM announcements 
                              WHERE is_active = true 
                              ORDER BY created_at DESC 
                              LIMIT 5";
            $announcementStmt = $db->query($announcementSql);
            $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Announcements error: " . $e->getMessage());
        $announcements = [];
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $init_error = "Unable to load products: " . $e->getMessage();
    $products = [];
    $allProducts = [];
}

$debug_info = $debug_mode ? getDebugInfo(new Database(), $products, $announcements, $userId) : [];

function getProductValue($product, $key, $default = '') {
    return isset($product[$key]) && !empty($product[$key]) ? $product[$key] : $default;
}

function getDefaultFishImage($fishName) {
    $name = strtolower($fishName ?? '');
    if (strpos($name, 'tilapia') !== false) return 'assets/images/fish/tilapia-default.jpg';
    if (strpos($name, 'bangus') !== false || strpos($name, 'milkfish') !== false) return 'assets/images/fish/bangus-default.jpg';
    if (strpos($name, 'shrimp') !== false) return 'assets/images/fish/shrimp-default.jpg';
    if (strpos($name, 'mud crab') !== false || strpos($name, 'alimango') !== false) return 'assets/images/fish/mudcrab-default.jpg';
    if (strpos($name, 'blue crab') !== false) return 'assets/images/fish/bluecrab-default.jpg';
    if (strpos($name, 'grouper') !== false) return 'assets/images/fish/grouper-default.jpg';
    if (strpos($name, 'catfish') !== false) return 'assets/images/fish/catfish-default.jpg';
    if (strpos($name, 'carp') !== false) return 'assets/images/fish/carp-default.jpg';
    if (strpos($name, 'mackerel') !== false) return 'assets/images/fish/mackerel-default.jpg';
    if (strpos($name, 'sardine') !== false) return 'assets/images/fish/sardine-default.jpg';
    if (strpos($name, 'trevally') !== false) return 'assets/images/fish/trevalley-default.jpg';
    if (strpos($name, 'tuna') !== false) return 'assets/images/fish/bullet-tuna-default.jpg';
    return 'assets/images/fish/default-fish.jpg';
}

function getFishImageUrl($fishName, $index = 0) {
    $imagePool = [
        'assets/images/fish/bangus-default.jpg',
        'assets/images/fish/bluecrab-default.jpg',
        'assets/images/fish/bullet-tuna-default.jpg',
        'assets/images/fish/carp-default.jpg',
        'assets/images/fish/catfish-default.jpg',
        'assets/images/fish/grouper-default.jpg',
        'assets/images/fish/mackerel-default.jpg',
        'assets/images/fish/mudcrab-default.jpg',
        'assets/images/fish/sardine-default.jpg',
        'assets/images/fish/shrimp-default.jpg',
        'assets/images/fish/tilapia-default.jpg',
        'assets/images/fish/trevalley-default.jpg'
    ];

    $specificImage = getDefaultFishImage($fishName);
    if ($specificImage != 'assets/images/fish/default-fish.jpg') {
        return $specificImage;
    }

    $imageIndex = $index % count($imagePool);
    return $imagePool[$imageIndex];
}

function getStockColorClass($percentage) {
    if ($percentage > 50) return 'bg-emerald-500';
    if ($percentage > 20) return 'bg-amber-500';
    return 'bg-red-500';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BISU IGE Aquaculture System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
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
        }

        body {
            background-color: var(--bg-primary);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* Smooth transitions */
        .transition-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Hero Section */
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

        /* Professional Card */
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

        /* Product Card */
        .product-card-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .product-card-pro:hover {
            box-shadow: 0 8px 30px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
            transform: translateY(-4px);
        }

        .product-card-pro:hover .product-img {
            transform: scale(1.06);
        }

        .product-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Buttons */
        .btn-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.2);
        }

        .btn-brand:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
            color: white;
        }

        .btn-ghost-light {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, rgba(45, 213, 246, 0.9), rgba(0, 153, 255, 0.7));
            backdrop-filter: blur(100px);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-ghost-light:hover {
            background: linear-gradient(135deg, rgba(125, 225, 245, 0.53), rgba(0, 153, 255, 0.7));
            transform: translateY(-3px);
            color: white;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        }

        .btn-outline-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: white;
            color: var(--brand);
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
            border: 1.5px solid var(--brand);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-brand:hover {
            background: var(--brand);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        }

        /* Badge */
        .badge-pro {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
        }

        /* Feature Card */
        .feature-card-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.75rem;
            text-align: center;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .feature-card-pro:hover {
            box-shadow: 0 8px 30px -4px rgba(0, 0, 0, 0.1);
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }

        .feature-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.25rem;
        }

        /* Announcement Card */
        .announcement-card-pro {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.2s ease;
            position: relative;
        }

        .announcement-card-pro::before {
            content: '';
            position: absolute;
            left: 0;
            top: 1rem;
            bottom: 1rem;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--brand);
        }

        .announcement-card-pro:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transform: translateX(3px);
        }

        /* Progress Bar */
        .progress-track {
            height: 5px;
            border-radius: 3px;
            background: #f1f5f9;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.4s ease;
        }

        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Flash Message */
        .flash-msg {
            padding: 1rem 1.25rem;
            border-radius: 10px;
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

        /* Empty State */
        .empty-state-pro {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-icon {
            width: 4rem;
            height: 4rem;
            background: #f1f5f9;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            color: #94a3b8;
            font-size: 1.5rem;
        }

        /* Wave decoration */
        .wave-decoration {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 120px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%23f8fafc' fill-opacity='1' d='M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E") no-repeat bottom;
            background-size: cover;
            pointer-events: none;
        }

        <?php if ($debug_mode): ?>
        #debugger-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            background: #ef4444;
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            font-weight: bold;
            transition: all 0.3s ease;
        }
        #debugger-toggle:hover { background: #dc2626; transform: scale(1.05); }
        #debugger-panel {
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 500px;
            max-height: 600px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            z-index: 9998;
            display: none;
            overflow: hidden;
            border: 2px solid #ef4444;
        }
        .debugger-header {
            background: #ef4444;
            color: white;
            padding: 15px 20px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
        }
        .debugger-content {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
            background: #f9fafb;
        }
        .debug-section { margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 15px; }
        .debug-json {
            background: #1f2937;
            color: #e5e7eb;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre-wrap;
            font-family: monospace;
            max-height: 200px;
            overflow-y: auto;
        }
        .quick-fix {
            background: #3b82f6;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            border: none;
            transition: all 0.3s ease;
        }
        .quick-fix:hover { background: #2563eb; }
        <?php endif; ?>
    </style>
</head>
<body class="antialiased">

    <?php include 'includes/navbar.php'; ?>

    <?php if (isset($init_error)): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
        <div class="flash-msg bg-white shadow-sm" style="border-color: #fee2e2;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-red-50 text-red-600">
                    <i class="fas fa-exclamation text-sm"></i>
                </div>
                <p class="text-sm font-medium text-gray-900">System Error: <?php echo htmlspecialchars($init_error); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 w-6 h-6 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($debug_mode): ?>
    <button id="debugger-toggle" onclick="toggleDebugger()">
        <i class="fas fa-bug mr-2"></i> Debug Mode
    </button>
    <div id="debugger-panel">
        <div class="debugger-header" id="debugger-header">
            <div><i class="fas fa-bug mr-2"></i> System Debugger</div>
            <div>
                <button onclick="refreshDebugger()" class="mr-2"><i class="fas fa-sync-alt"></i></button>
                <button onclick="toggleDebugger()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="debugger-content">
            <div class="debug-section">
                <h4 class="font-bold mb-2">Quick Actions</h4>
                <button class="quick-fix w-full mb-2" onclick="runDiagnostic()">Run Diagnostic</button>
                <button class="quick-fix w-full" style="background: #10b981;" onclick="clearCache()">Clear Cache</button>
            </div>
            <div class="debug-section">
                <h4 class="font-bold mb-2">System Status</h4>
                <div class="flex justify-between py-1"><span>Products (Showing):</span><span class="font-bold"><?php echo count($products); ?></span></div>
                <div class="flex justify-between py-1"><span>Products (Total):</span><span class="font-bold"><?php echo count($allProducts ?? []); ?></span></div>
                <div class="flex justify-between py-1"><span>Announcements:</span><span class="font-bold"><?php echo count($announcements); ?></span></div>
                <div class="flex justify-between py-1"><span>User Logged In:</span><span class="font-bold"><?php echo $userId ? 'Yes (ID: ' . $userId . ')' : 'No'; ?></span></div>
            </div>
            <div class="debug-section">
                <h4 class="font-bold mb-2">Database Tables</h4>
                <?php if (isset($debug_info['table_fish_products'])): ?>
                    <div class="flex justify-between py-1"><span>fish_products:</span><span><?php echo $debug_info['table_fish_products']; ?> records</span></div>
                    <div class="flex justify-between py-1"><span>announcements:</span><span><?php echo $debug_info['table_announcements']; ?> records</span></div>
                    <div class="flex justify-between py-1"><span>users:</span><span><?php echo $debug_info['table_users']; ?> records</span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="hero-section py-20 relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="max-w-3xl">
                <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-3">Bohol Island State University</p>
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white font-display leading-tight">
                    Fresh Aquaculture<br>Harvest
                </h1>
                <p class="mt-6 text-lg text-brand-200/80 max-w-xl leading-relaxed">
                    Reserve and purchase premium quality fresh fish harvested directly from BISU IGE aquaculture facilities.
                </p>
                <div class="mt-10 flex flex-wrap gap-3">
                    <?php if($userId && SessionManager::isLoggedIn()): ?>
                        <?php if(SessionManager::isManager()): ?>
                            <a href="manager/dashboard.php" class="btn-brand">
                                <i class="fas fa-tachometer-alt text-sm"></i> Manager Dashboard
                            </a>
                        <?php elseif(SessionManager::isCashier()): ?>
                            <a href="cashier/dashboard.php" class="btn-brand">
                                <i class="fas fa-cash-register text-sm"></i> Cashier Dashboard
                            </a>
                        <?php else: ?>
                            <a href="user/dashboard.php" class="btn-brand">
                                <i class="fas fa-tachometer-alt text-sm"></i> Go to Dashboard
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="products.php" class="btn-brand">
                            <i class="fas fa-fish text-sm"></i> View All Fish
                        </a>
                        <a href="register.php" class="btn-ghost-light">
                            <i class="fas fa-user-plus text-sm"></i> Register Now
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="wave-decoration"></div>
    </div>

    <!-- Announcements Section -->
    <?php if(!empty($announcements)): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="section-header mb-6">
            <div class="section-icon bg-amber-50 text-amber-600">
                <i class="fas fa-bullhorn"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900">Announcements</h2>
                <p class="text-sm text-gray-500">Latest updates from BISU IGE</p>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach($announcements as $announcement): ?>
            <div class="announcement-card-pro">
                <h3 class="font-semibold text-gray-900 text-sm mb-1">
                    <?php echo htmlspecialchars($announcement['title'] ?? 'Untitled Announcement'); ?>
                </h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?php 
                    $message = $announcement['approximate_pieces'] ?? $announcement['content'] ?? 'No content';
                    echo nl2br(htmlspecialchars(is_array($message) ? json_encode($message) : (string)$message));
                    ?>
                </p>
                <div class="flex items-center mt-3 text-xs text-gray-400 gap-3">
                    <span><i class="far fa-calendar mr-1"></i> <?php echo date('M d, Y', strtotime($announcement['created_at'] ?? 'now')); ?></span>
                    <?php if(!empty($announcement['harvest_date'])): ?>
                        <span><i class="fas fa-seedling mr-1"></i> Harvest: <?php echo date('M d, Y', strtotime($announcement['harvest_date'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Available Products Section -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-end justify-between mb-8">
            <div>
                <div class="section-header mb-2">
                    <div class="section-icon bg-brand-50 text-brand-600">
                        <i class="fas fa-fish"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Fresh from Our Harvest</h2>
                    </div>
                </div>
                <?php if(count($allProducts ?? []) > 6): ?>
                    <p class="text-sm text-gray-500 ml-12">Showing 6 of <?php echo count($allProducts); ?> available products</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php if(!empty($products)): ?>
                <?php foreach($products as $index => $product): 
                    if (!is_array($product)) continue;
                    $productId = $product['product_id'] ?? 0;
                    if (!$productId) continue;

                    $fishName = $product['fish_name'] ?? 'Fish';
                    $harvestDate = $product['harvest_date'] ?? date('Y-m-d');
                    $price = (float)($product['price_per_kg'] ?? 0);
                    $available = (float)($product['available_quantity'] ?? 0);
                    $total = (float)($product['available_quantity'] ?? $available);
                    $percentage = $total > 0 ? min(100, ($available / $total) * 100) : 0;
                    $stockColor = getStockColorClass($percentage);
                    $productImage = getFishImageUrl($fishName, $index);
                ?>
                    <div class="product-card-pro">
                        <div class="relative overflow-hidden">
                            <img src="<?php echo $productImage; ?>" 
                                 alt="<?php echo htmlspecialchars($fishName); ?>"
                                 class="product-img"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='assets/images/fish/default-fish.jpg';">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>

                            <div class="absolute top-3 right-3">
                                <span class="badge-pro bg-black/70 text-white backdrop-blur-sm">
                                    <?php echo number_format($available, 1); ?> kg left
                                </span>
                            </div>

                            <div class="absolute bottom-3 left-3">
                                <span class="badge-pro bg-white/95 text-gray-800 shadow-sm">
                                    <i class="fas fa-fish text-[10px]"></i> <?php echo htmlspecialchars($fishName); ?>
                                </span>
                            </div>
                        </div>

                        <div class="p-5">
                            <div class="flex items-baseline gap-1 mb-3">
                                <span class="text-xl font-bold text-brand-600">₱<?php echo number_format($price, 2); ?></span>
                                <span class="text-gray-400 text-xs">/kilogram</span>
                            </div>

                            <p class="text-sm text-gray-600 mb-1">
                                <i class="far fa-calendar-alt text-gray-400 text-xs mr-1"></i>
                                Harvested: <?php echo date('M d, Y', strtotime($harvestDate)); ?>
                            </p>

                            <?php if(!empty($product['description'])): ?>
                                <p class="text-xs text-gray-500 mb-3 line-clamp-2"><?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>...</p>
                            <?php endif; ?>

                            <div class="mb-4">
                                <div class="flex justify-between text-xs mb-1.5">
                                    <span class="text-gray-500 font-medium">Available Stock</span>
                                    <span class="font-semibold <?php echo $percentage <= 20 ? 'text-red-600' : ($percentage <= 50 ? 'text-amber-600' : 'text-emerald-600'); ?>">
                                        <?php echo number_format($available, 1); ?> kg
                                    </span>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill <?php echo $stockColor; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>

                            <div class="pt-3 border-t border-gray-100">
                                <?php if($userId && SessionManager::isLoggedIn()): ?>
                                    <?php if(SessionManager::isOfficeUser()): ?>
                                        <button class="w-full py-2.5 bg-gray-100 text-gray-400 rounded-lg font-semibold cursor-not-allowed text-sm flex items-center justify-center gap-2" disabled>
                                            <i class="fas fa-user-tie text-xs"></i> Office Users Cannot Reserve
                                        </button>
                                    <?php else: ?>
                                        <a href="user/products.php?product_id=<?php echo $productId; ?>" class="btn-outline-brand w-full py-2.5 text-sm">
                                            <i class="fas fa-calendar-plus text-xs"></i> Reserve Now
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="login.php?redirect=user/products.php?product_id=<?php echo $productId; ?>" class="btn-outline-brand w-full py-2.5 text-sm">
                                        <i class="fas fa-sign-in-alt text-xs"></i> Login to Reserve
                                    </a>
                                    <p class="mt-2 text-xs text-gray-400 text-center">
                                        <a href="login.php" class="text-brand-600 hover:text-brand-700 font-medium">Login</a> or 
                                        <a href="register.php" class="text-brand-600 hover:text-brand-700 font-medium">Register</a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if(count($allProducts ?? []) > 6): ?>
            <div class="text-center mt-10">
                <a href="products.php" class="btn-brand">
                    <i class="fas fa-fish text-sm"></i>
                    View All <?php echo count($allProducts); ?> Available Products
                    <i class="fas fa-arrow-right text-sm ml-1"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php if(empty($products)): ?>
            <div class="pro-card p-12 text-center">
                <div class="empty-icon">
                    <i class="fas fa-fish"></i>
                </div>
                <h3 class="text-base font-semibold text-gray-900 mb-1">No fish available at the moment</h3>
                <p class="text-sm text-gray-500">Check back later for new harvests.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Features Section -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="text-center mb-10">
            <p class="text-brand-600 text-sm font-medium tracking-wide uppercase mb-2">Why Choose Us</p>
            <h2 class="text-3xl font-bold text-gray-900 font-display">Everything You Need</h2>
            <p class="text-gray-500 mt-2 max-w-lg mx-auto">Our aquaculture management system provides a seamless experience from ordering to delivery.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php 
            $features = [
                ['icon' => 'fa-fish', 'color' => 'bg-brand-50 text-brand-600', 'title' => 'Fresh Harvest', 'desc' => 'Direct from BISU aquaculture facilities with guaranteed freshness.'],
                ['icon' => 'fa-calendar-check', 'color' => 'bg-emerald-50 text-emerald-600', 'title' => 'Easy Ordering', 'desc' => 'Place orders online in just a few clicks with our intuitive interface.'],
                ['icon' => 'fa-credit-card', 'color' => 'bg-violet-50 text-violet-600', 'title' => 'Salary Deduction', 'desc' => 'Payment via salary deduction for eligible employees.'],
                ['icon' => 'fa-bell', 'color' => 'bg-amber-50 text-amber-600', 'title' => 'Real-time Updates', 'desc' => 'Get instant notifications on your order status and harvest schedules.']
            ]; 
            ?>
            <?php foreach($features as $feature): ?>
                <div class="feature-card-pro">
                    <div class="feature-icon <?php echo $feature['color']; ?>">
                        <i class="fas <?php echo $feature['icon']; ?>"></i>
                    </div>
                    <h3 class="text-base font-bold text-gray-900 mb-2"><?php echo $feature['title']; ?></h3>
                    <p class="text-sm text-gray-500 leading-relaxed"><?php echo $feature['desc']; ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script>
        <?php if ($debug_mode): ?>
        let debuggerVisible = true;
        function toggleDebugger() {
            const panel = document.getElementById('debugger-panel');
            const button = document.getElementById('debugger-toggle');
            if (debuggerVisible) {
                panel.style.display = 'none';
                button.innerHTML = '<i class="fas fa-bug mr-2"></i> Show Debugger';
            } else {
                panel.style.display = 'block';
                button.innerHTML = '<i class="fas fa-bug mr-2"></i> Hide Debugger';
            }
            debuggerVisible = !debuggerVisible;
        }
        function refreshDebugger() { location.reload(); }
        function runDiagnostic() {
            const issues = [];
            <?php if(empty($products)): ?>
                issues.push('❌ No products found in database');
                issues.push('💡 Make sure fish_products table has data with available_quantity > 0');
            <?php else: ?>
                issues.push('✅ Products found: <?php echo count($products); ?>');
            <?php endif; ?>
            <?php if(empty($announcements)): ?>
                issues.push('⚠️ No announcements found (optional)');
            <?php else: ?>
                issues.push('✅ Announcements found: <?php echo count($announcements); ?>');
            <?php endif; ?>
            alert(issues.join('
'));
        }
        function clearCache() {
            sessionStorage.clear();
            localStorage.clear();
            alert('Cache cleared! Refreshing page...');
            location.reload();
        }
        <?php endif; ?>

        // Auto-hide flash messages
        setTimeout(() => {
            document.querySelectorAll('.flash-msg').forEach(msg => {
                msg.style.transition = 'all 0.4s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-8px)';
                setTimeout(() => msg.remove(), 400);
            });
        }, 5000);
    </script>
</body>
</html>