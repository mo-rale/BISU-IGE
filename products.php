<?php
// root products.php - Public Product Catalog for Guests and Authenticated Users
require_once 'includes/config.php';
require_once 'includes/session.php';

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();

// Helper function to get default image based on fish name
function getProductImage($fishName) {
    $name = strtolower($fishName ?? '');
    if (strpos($name, 'tilapia') !== false) return 'assets/images/fish/tilapia-default.jpg';
    if (strpos($name, 'bangus') !== false || strpos($name, 'milkfish') !== false) return 'assets/images/fish/bangus-default.jpg';
    if (strpos($name, 'shrimp') !== false) return 'assets/images/fish/shrimp-default.jpg';
    if (strpos($name, 'mud crab') !== false || strpos($name, 'alimango') !== false) return 'assets/images/fish/mudcrab-default.jpg';
    if (strpos($name, 'blue crab') !== false) return 'assets/images/fish/bluecrab-default.jpg';
    if (strpos($name, 'grouper') !== false) return 'assets/images/fish/grouper-default.jpg';
    if (strpos($name, 'catfish') !== false) return 'assets/images/fish/catfish-default.jpg';
    if (strpos($name, 'carp') !== false) return 'assets/images/fish/carp-default.jpg';
    if (strpos($name, 'tamban') !== false) return 'assets/images/fish/sardine-default.jpg';
    if (strpos($name, 'mackerel') !== false) return 'assets/images/fish/mackerel-default.jpg';
    if (strpos($name, 'sardine') !== false) return 'assets/images/fish/freshwater_sardines.jpg';
    if (strpos($name, 'bullet tuna') !== false) return 'assets/images/fish/bullet-tuna-default.jpg';
    if (strpos($name, 'trevalley') !== false) return 'assets/images/fish/trevalley-default.jpg';
    return 'assets/images/fish/default-fish.jpg';
}

// Pagination and filtering setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;
$searchQuery = $_GET['search'] ?? '';
$fishTypeFilter = $_GET['fish_type'] ?? 'all';

// Get database connection
$db = (new Database())->getConnection();

// Build the base query using your actual fish_products table structure
$baseSql = "SELECT 
                fp.product_id,
                fp.fish_name,
                fp.description,
                fp.price_per_kg,
                COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity,
                fp.created_at,
                fp.updated_at
            FROM fish_products fp
            LEFT JOIN harvest h
                ON h.fish_product_id = fp.product_id
               AND h.status != 'depleted'
               AND h.remaining_quantity > 0
            GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg, fp.created_at, fp.updated_at
            HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0";

$countSql = "SELECT COUNT(*) as total FROM (
             SELECT fp.product_id
             FROM fish_products fp
             LEFT JOIN harvest h ON h.fish_product_id = fp.product_id
                AND h.status != 'depleted' AND h.remaining_quantity > 0
             GROUP BY fp.product_id
             HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
             ) AS sub";

$params = [];

// Apply search filter
if (!empty($searchQuery)) {
    $baseSql .= " AND fp.fish_name ILIKE :search";
    $countSql .= " AND fp.fish_name ILIKE :search";
    $params[':search'] = "%$searchQuery%";
}

// Apply fish type filter
if ($fishTypeFilter !== 'all') {
    $baseSql .= " AND LOWER(fp.fish_name) LIKE :fish_type";
    $countSql .= " AND LOWER(fp.fish_name) LIKE :fish_type";
    $params[':fish_type'] = "%$fishTypeFilter%";
}

// Get total count for pagination
$countStmt = $db->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get products with pagination
$sql = $baseSql . " ORDER BY fp.created_at DESC LIMIT :limit OFFSET :offset";
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
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique fish types for filter dropdown
$fishTypesStmt = $db->query("
    SELECT DISTINCT LOWER(TRIM(SPLIT_PART(fp.fish_name, ' ', 1))) as type, 
           COUNT(DISTINCT fp.product_id) as count
    FROM fish_products fp
    INNER JOIN harvest h ON h.fish_product_id = fp.product_id
        AND h.status != 'depleted' AND h.remaining_quantity > 0
    GROUP BY type
    ORDER BY type
");
$fishTypes = $fishTypesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get announcements
$announcements = $functions->getActiveAnnouncements() ?: [];

// Helper function to check if user already has an order for this product
function hasUserOrderedProduct($userId, $productId, $db) {
    if (!$userId) return false;
    $stmt = $db->prepare("SELECT 1 FROM order_items oi 
                          JOIN orders o ON oi.order_id = o.order_id 
                          WHERE o.user_id = ? AND oi.product_id = ? 
                          AND o.order_status NOT IN ('cancelled')
                          LIMIT 1");
    $stmt->execute([$userId, $productId]);
    return $stmt->fetch() !== false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fresh Fish Products - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --secondary: #10b981;
            --accent: #8b5cf6;
            --surface: #ffffff;
            --background: #f8fafc;
        }

        body {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
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
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .product-card {
            background: var(--surface);
            border-radius: 1.5rem;
            border: 1px solid rgba(203, 213, 225, 0.2);
            transition: all 0.3s ease;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1);
            border-color: var(--primary);
        }

        .product-image-container {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.05);
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.3) 100%);
            pointer-events: none;
        }

        .stock-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.75);
            backdrop-filter: blur(4px);
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            z-index: 2;
        }

        .fish-type-badge {
            position: absolute;
            bottom: 10px;
            left: 10px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(4px);
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--primary);
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border-radius: 1rem;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(14, 165, 233, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid white;
            color: white;
            border-radius: 1rem;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .filter-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 1.25rem;
            border: 1px solid rgba(203, 213, 225, 0.2);
        }

        .filter-input, .filter-select {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 0.625rem 1rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        .announcement-card {
            background: rgba(255, 255, 255, 0.98);
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }

        .announcement-card:hover {
            transform: translateX(5px);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 2rem;
            backdrop-filter: blur(10px);
        }

        .pagination-link {
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
            transition: all 0.3s ease;
        }

        .pagination-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .flash-message {
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            background: white;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <!-- Hero Section -->
    <div class="hero-section py-16">
        <div class="hero-content max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
                Fresh from Our Harvest
            </h1>
            <p class="text-xl text-blue-100 max-w-2xl mx-auto">
                Sustainably farmed, freshly harvested fish from BISU IGE aquaculture facilities.
                Reserve your share today!
            </p>
            <?php if (!SessionManager::isLoggedIn()): ?>
                <div class="mt-8 flex justify-center gap-4">
                    <a href="login.php" class="btn-outline">
                        <i class="fas fa-sign-in-alt"></i> Login to Reserve
                    </a>
                    <a href="register.php" class="btn-primary">
                        <i class="fas fa-user-plus"></i> Create Account
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Flash Messages -->
        <?php if ($message = SessionManager::getMessage()): ?>
        <div class="mb-6 fade-in">
            <div class="flash-message" style="border-left-color: <?php echo $message['type'] == 'success' ? '#10b981' : '#ef4444'; ?>">
                <div class="flex items-center gap-3">
                    <i class="fas <?php echo $message['type'] == 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'; ?>"></i>
                    <p class="text-sm text-gray-700"><?php echo htmlspecialchars($message['message']); ?></p>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Announcements Section -->
        <?php if (!empty($announcements)): ?>
        <div class="mb-8 fade-in">
            <div class="bg-white/90 backdrop-blur-sm rounded-2xl overflow-hidden border border-blue-100">
                <div class="px-5 py-3 bg-gradient-to-r from-blue-50 to-cyan-50 border-b border-blue-100">
                    <h2 class="text-base font-semibold text-gray-800">
                        <i class="fas fa-bullhorn text-blue-500 mr-2"></i> Announcements
                    </h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
                    <div class="px-5 py-3 announcement-card">
                        <h3 class="font-medium text-gray-800 text-sm">
                            <?php echo htmlspecialchars($announcement['title'] ?? 'Announcement'); ?>
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">
                            <?php 
                            $content = $announcement['approximate_pieces'] ?? $announcement['content'] ?? '';
                            echo nl2br(htmlspecialchars(substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '')));
                            ?>
                        </p>
                        <div class="text-xs text-gray-400 mt-1">
                            <i class="far fa-calendar-alt mr-1"></i> <?php echo date('M d, Y', strtotime($announcement['created_at'] ?? 'now')); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section mb-8 fade-in">
            <form method="GET" class="flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <div class="relative">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search fish by name..." 
                               class="filter-input pl-10">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div class="w-full md:w-48">
                    <select name="fish_type" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Fish Types</option>
                        <?php foreach ($fishTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['type']); ?>" 
                                    <?php echo $fishTypeFilter == $type['type'] ? 'selected' : ''; ?>>
                                <?php echo ucfirst($type['type']); ?> (<?php echo $type['count']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="products.php" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-600 hover:bg-gray-200 transition flex items-center gap-2">
                        <i class="fas fa-redo-alt"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Info -->
        <div class="flex justify-between items-center mb-6">
            <p class="text-sm text-gray-600">
                <i class="fas fa-fish mr-1"></i> 
                Showing <span class="font-medium"><?php echo count($products); ?></span> of <span class="font-medium"><?php echo number_format($totalRecords); ?></span> available products
            </p>
            <?php if (SessionManager::isLoggedIn() && SessionManager::getUserRole() === 'standard'): ?>
                <a href="user/orders.php" class="text-sm text-primary hover:text-primary-dark flex items-center gap-1">
                    <i class="fas fa-shopping-bag"></i> My Orders
                </a>
            <?php endif; ?>
        </div>

        <!-- Products Grid -->
        <?php if (empty($products)): ?>
            <div class="empty-state fade-in">
                <div class="w-20 h-20 mx-auto mb-4 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-fish text-blue-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-800 mb-2">No products found</h3>
                <p class="text-gray-500 mb-6">No fish products are currently available. Check back later for new harvests!</p>
                <a href="products.php" class="btn-primary inline-flex">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 fade-in">
                <?php foreach ($products as $product): 
                    $productId = $product['product_id'];
                    $fishName = $product['fish_name'];
                    $price = $product['price_per_kg'];
                    $availableQty = $product['available_quantity'];
                    $percentage = $availableQty > 0 ? min(100, ($availableQty / 100) * 100) : 0;
                    $stockColor = $availableQty > 50 ? 'bg-green-500' : ($availableQty > 20 ? 'bg-yellow-500' : 'bg-red-500');
                    $productImage = getProductImage($fishName);
                    $alreadyOrdered = hasUserOrderedProduct($userId, $productId, $db);
                ?>
                    <div class="product-card <?php echo $alreadyOrdered ? 'border-2 border-yellow-400' : ''; ?>">
                        <div class="product-image-container">
                            <img src="<?php echo $productImage; ?>" 
                                 alt="<?php echo htmlspecialchars($fishName); ?>"
                                 class="product-image"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='assets/images/fish/default-fish.jpg';">
                            <div class="image-overlay"></div>
                            <div class="stock-badge <?php echo $stockColor; ?>">
                                <i class="fas fa-<?php echo $availableQty <= 20 ? 'exclamation-triangle' : ($availableQty <= 50 ? 'chart-line' : 'check-circle'); ?> mr-1"></i>
                                <?php echo number_format($availableQty, 1); ?> kg left
                            </div>
                            <div class="fish-type-badge">
                                <i class="fas fa-fish mr-1"></i>
                                <?php echo htmlspecialchars($fishName); ?>
                            </div>
                        </div>
                        
                        <div class="p-4 flex-1 flex flex-col">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <p class="text-xs text-gray-500">
                                        <i class="far fa-calendar-alt mr-1"></i>
                                        Added: <?php echo date('M d, Y', strtotime($product['created_at'])); ?>
                                    </p>
                                </div>
                                <span class="text-lg font-bold text-primary">₱<?php echo number_format($price, 2); ?>/kg</span>
                            </div>
                            
                            <?php if (!empty($product['description'])): ?>
                                <p class="text-xs text-gray-500 mb-2 line-clamp-2">
                                    <?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-gray-500">Available</span>
                                    <span class="font-medium"><?php echo number_format($availableQty, 2); ?> kg</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                    <div class="h-full <?php echo $stockColor; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mt-auto pt-3">
                                <?php if (SessionManager::isLoggedIn()): ?>
                                    <?php if (SessionManager::isOfficeUser()): ?>
                                        <div class="text-center py-2 px-3 bg-gray-100 rounded-xl text-sm text-gray-500">
                                            <i class="fas fa-user-tie mr-1"></i> Office users cannot order
                                        </div>
                                    <?php elseif ($alreadyOrdered): ?>
                                        <div class="text-center py-2 px-3 bg-yellow-50 rounded-xl">
                                            <p class="text-sm text-yellow-700">
                                                <i class="fas fa-check-circle mr-1"></i> Already ordered
                                            </p>
                                            <a href="user/orders.php" class="text-xs text-yellow-600 hover:text-yellow-800 mt-1 inline-block">
                                                View my orders →
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <a href="user/products.php" 
                                           class="w-full flex items-center justify-center py-2 px-3 rounded-xl text-sm font-medium text-white btn-primary">
                                            <i class="fas fa-shopping-cart mr-2"></i> Order Now
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="login.php?redirect=products.php" 
                                       class="w-full flex items-center justify-center py-2 px-3 rounded-xl text-sm font-medium text-center bg-gray-100 text-gray-600 hover:bg-gray-200 transition">
                                        <i class="fas fa-lock mr-2"></i> Login to Order
                                    </a>
                                    <p class="text-xs text-center text-gray-400 mt-2">
                                        <a href="register.php" class="text-primary hover:underline">Create account</a> to start ordering
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-10 flex justify-center gap-2 flex-wrap">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-link">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php else: ?>
                    <span class="pagination-link opacity-50 cursor-not-allowed"><i class="fas fa-chevron-left"></i> Prev</span>
                <?php endif; ?>
                
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                       class="pagination-link <?php echo $i == $page ? 'pagination-active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="pagination-link opacity-50 cursor-not-allowed">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
            <div class="text-center text-sm text-gray-500 mt-4">
                Page <?php echo $page; ?> of <?php echo $totalPages; ?> • <?php echo number_format($totalRecords); ?> total products
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="bg-white/80 backdrop-blur-sm border-t border-gray-200 mt-12 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <p class="text-sm text-gray-500">
                <i class="fas fa-fish mr-1"></i>
                &copy; <?php echo date('Y'); ?> Bohol Island State University - Institute of Global Education. 
                Fresh from our aquaculture facilities.
            </p>
        </div>
    </footer>

    <script>
        // Auto-hide flash messages
        setTimeout(() => {
            document.querySelectorAll('.flash-message').forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>