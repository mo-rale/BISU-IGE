<?php
// manager/products.php - Professional UI
require_once '../includes/config.php';
require_once '../includes/session.php';

// Only allow managers
SessionManager::requireManagerOrStaff();

$functions = new SystemFunctions();
$userId = SessionManager::getUserId();
$db = (new Database())->getConnection();

// Helper function to get default image based on fish name
function getProductImage($fishName) {
    $name = strtolower($fishName ?? '');
    if (strpos($name, 'tilapia') !== false) return '../assets/images/fish/tilapia-default.jpg';
    if (strpos($name, 'bangus') !== false || strpos($name, 'milkfish') !== false) return '../assets/images/fish/bangus-default.jpg';
    if (strpos($name, 'shrimp') !== false) return '../assets/images/fish/shrimp-default.jpg';
    if (strpos($name, 'mud crab') !== false || strpos($name, 'alimango') !== false) return '../assets/images/fish/mudcrab-default.jpg';
    if (strpos($name, 'blue crab') !== false) return '../assets/images/fish/bluecrab-default.jpg';
    if (strpos($name, 'grouper') !== false) return '../assets/images/fish/grouper-default.jpg';
    if (strpos($name, 'catfish') !== false) return '../assets/images/fish/catfish-default.jpg';
    if (strpos($name, 'carp') !== false) return '../assets/images/fish/carp-default.jpg';
    if (strpos($name, 'tamban') !== false) return '../assets/images/fish/sardine-default.jpg';
    if (strpos($name, 'mackerel') !== false) return '../assets/images/fish/mackerel-default.jpg';
    if (strpos($name, 'sardine') !== false) return '../assets/images/fish/freshwater_sardines.jpg';
    if (strpos($name, 'bullet tuna') !== false) return '../assets/images/fish/bullet-tuna-default.jpg';
    if (strpos($name, 'trevalley') !== false) return '../assets/images/fish/trevalley-default.jpg';
    return '../assets/images/fish/default-fish.jpg';
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_product') {
        try {
            $db->beginTransaction();
            
            $pricePerKg = floatval($_POST['price_per_kg'] ?? 0);
            
            if ($pricePerKg <= 0) {
                throw new Exception("Price must be greater than 0.");
            }
            
            // Insert into fish_products table
            $sql = "INSERT INTO fish_products (
                fish_name, description, price_per_kg, created_at, updated_at
            ) VALUES (
                :fish_name, :description, :price_per_kg, NOW(), NOW()
            ) RETURNING product_id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':fish_name'   => trim($_POST['fish_name']),
                ':description' => $_POST['description'] ?? null,
                ':price_per_kg' => $pricePerKg,
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $productId = $result['product_id'];
            
            // If a harvest ID was provided, link this product to that harvest
            if (!empty($_POST['harvest_id'])) {
                $updateHarvest = $db->prepare("UPDATE harvest SET fish_product_id = :product_id WHERE harvest_id = :harvest_id");
                $updateHarvest->execute([
                    ':product_id' => $productId,
                    ':harvest_id' => $_POST['harvest_id']
                ]);
                $message = "Product added and linked to harvest successfully!";
            } else {
                $message = "Product added successfully! (Not linked to any harvest)";
            }
            
            $db->commit();
            $messageType = 'success';
            
        } catch (Exception $e) {
            $db->rollBack();
            $message = "Error adding product: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_price') {
        try {
            $pricePerKg = floatval($_POST['price_per_kg'] ?? 0);
            if ($pricePerKg <= 0) {
                throw new Exception("Price must be greater than 0.");
            }
            
            $sql = "UPDATE fish_products SET price_per_kg = :price, updated_at = NOW() WHERE product_id = :product_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':price' => $pricePerKg,
                ':product_id' => $_POST['product_id']
            ]);
            
            $message = "Product price updated successfully!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Error updating price: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'link_harvest') {
        try {
            $harvestId = $_POST['harvest_id'];
            $productId = $_POST['product_id'];
            
            // Check if harvest already has a product linked
            $checkStmt = $db->prepare("SELECT fish_product_id FROM harvest WHERE harvest_id = :harvest_id");
            $checkStmt->execute([':harvest_id' => $harvestId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing['fish_product_id']) {
                throw new Exception("This harvest is already linked to a product.");
            }
            
            $updateStmt = $db->prepare("UPDATE harvest SET fish_product_id = :product_id WHERE harvest_id = :harvest_id");
            $updateStmt->execute([
                ':product_id' => $productId,
                ':harvest_id' => $harvestId
            ]);
            
            $message = "Harvest linked to product successfully!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Error linking harvest: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get filter parameters
$harvestFilter = $_GET['harvest_id'] ?? null;
$searchQuery = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 8;
$offset = ($page - 1) * $perPage;

// Get all harvests for filter dropdown (including those without products)
try {
    $harvestStmt = $db->prepare("
        SELECT h.*, 
               fp.fish_name as product_name,
               fp.product_id as linked_product_id
        FROM harvest h
        LEFT JOIN fish_products fp ON fp.product_id = h.fish_product_id
        ORDER BY h.created_at DESC
    ");
    $harvestStmt->execute();
    $allHarvests = $harvestStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate harvests with and without products for the filter dropdown
    $harvestsWithProducts = array_filter($allHarvests, function($h) {
        return !is_null($h['fish_product_id']);
    });
    $harvestsWithoutProducts = array_filter($allHarvests, function($h) {
        return is_null($h['fish_product_id']);
    });
    
    $harvests = $allHarvests;
    
} catch (PDOException $e) {
    error_log("Harvests fetch error: " . $e->getMessage());
    $harvests = [];
    $harvestsWithProducts = [];
    $harvestsWithoutProducts = [];
}

// Get total count for pagination
try {
    $countSql = "SELECT COUNT(DISTINCT fp.product_id) as total
                 FROM fish_products fp
                 WHERE 1=1";
    
    $countParams = [];
    
    if ($harvestFilter) {
        $countSql .= " AND fp.product_id IN (SELECT fish_product_id FROM harvest WHERE harvest_id = :harvest_id)";
        $countParams[':harvest_id'] = $harvestFilter;
    }
    
    if ($searchQuery) {
        $countSql .= " AND (fp.fish_name ILIKE :search)";
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

// Get products with stock info from harvest table
try {
    $sql = "SELECT 
                fp.product_id, 
                fp.fish_name, 
                fp.description, 
                fp.price_per_kg,
                fp.created_at, 
                fp.updated_at,
                COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity,
                COALESCE(SUM(h.total_quantity), 0) AS harvest_total_quantity,
                COUNT(DISTINCT h.harvest_id) AS harvest_batch_count,
                (SELECT COUNT(*) FROM order_items oi 
                 JOIN orders o ON oi.order_id = o.order_id 
                 WHERE oi.product_id = fp.product_id AND o.order_status != 'cancelled') as total_orders,
                (SELECT COUNT(*) FROM order_items oi 
                 JOIN orders o ON oi.order_id = o.order_id 
                 WHERE oi.product_id = fp.product_id AND o.order_status = 'pending') as pending_orders,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi 
                 JOIN orders o ON oi.order_id = o.order_id 
                 WHERE oi.product_id = fp.product_id AND o.order_status IN ('pending', 'confirmed')) as ordered_quantity
            FROM fish_products fp
            LEFT JOIN harvest h ON h.fish_product_id = fp.product_id
                AND h.remaining_quantity > 0
            WHERE 1=1";
    
    $params = [];
    
    if ($harvestFilter) {
        $sql .= " AND fp.product_id IN (SELECT fish_product_id FROM harvest WHERE harvest_id = :harvest_id)";
        $params[':harvest_id'] = $harvestFilter;
    }
    
    if ($searchQuery) {
        $sql .= " AND (fp.fish_name ILIKE :search)";
        $params[':search'] = "%$searchQuery%";
    }
    
    $sql .= " GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg, fp.created_at, fp.updated_at";
    $sql .= " ORDER BY fp.fish_name ASC LIMIT :limit OFFSET :offset";
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
    
} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
    error_log("SQL: " . $sql);
    $products = [];
    $message = "Database Error: " . $e->getMessage();
    $messageType = 'error';
}

// Get unlinked harvests for the link modal
try {
    $unlinkedHarvestsStmt = $db->prepare("
        SELECT h.* FROM harvest h 
        WHERE h.fish_product_id IS NULL
        ORDER BY h.created_at DESC
    ");
    $unlinkedHarvestsStmt->execute();
    $unlinkedHarvests = $unlinkedHarvestsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $unlinkedHarvests = [];
}

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
    <title>Manage Products - BISU IGE Aquaculture</title>
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

        .product-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -8px rgba(0, 0, 0, 0.12);
            border-color: #cbd5e1;
        }

        .product-image-container {
            position: relative;
            height: 170px;
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
            padding: 0.25rem 0.7rem;
            border-radius: 20px;
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
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--brand);
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
            padding: 0.5rem 0.875rem;
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

        .filter-input, .filter-select, .form-input, .form-select, .form-textarea {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
            width: 100%;
        }

        .filter-input:focus, .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
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
            max-width: 600px;
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

        .info-banner {
            background: linear-gradient(135deg, #f0f9ff, #ffffff);
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 1rem;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        <div class="flash-msg bg-white shadow-sm" style="border-left: 4px solid <?php echo $messageType == 'success' ? '#10b981' : '#ef4444'; ?>">
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
    <div class="hero-section py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <p class="text-brand-300 text-sm font-medium tracking-wide uppercase mb-2">Product Management</p>
                    <h1 class="text-3xl md:text-4xl font-bold text-white font-display">
                        Manage Products
                    </h1>
                    <p class="text-brand-200/80 mt-2 text-sm max-w-md">View and manage fish products linked to harvest batches.</p>
                </div>
                <button onclick="openAddProductModal()" class="btn-outline-brand">
                    <i class="fas fa-plus text-sm"></i>
                    Add New Product
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Filters Section -->
        <div class="pro-card p-5 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Search</label>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search fish name..." class="filter-input pl-10">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Filter by Harvest</label>
                    <select name="harvest_id" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Harvests</option>
                        <?php foreach ($harvestsWithProducts as $harvest): ?>
                            <option value="<?php echo $harvest['harvest_id']; ?>" <?php echo $harvestFilter == $harvest['harvest_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($harvest['batch_no']) . ' - ' . date('M d, Y', strtotime($harvest['created_at'])) . ' → ' . htmlspecialchars($harvest['product_name'] ?? 'Unnamed'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn-brand w-full justify-center">
                        <i class="fas fa-filter text-sm"></i> Apply Filters
                    </button>
                    <a href="products.php" class="btn-secondary">
                        <i class="fas fa-redo-alt"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Unlinked Harvests Warning -->
        <?php if (count($harvestsWithoutProducts) > 0): ?>
        <div class="mb-6 bg-amber-50 border-l-4 border-amber-500 rounded-xl p-4">
            <div class="flex items-start gap-3">
                <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-amber-800">Unlinked Harvests Detected</p>
                    <p class="text-xs text-amber-700 mt-1">
                        There are <?php echo count($harvestsWithoutProducts); ?> harvest batches that are not linked to any product.
                        <button onclick="openBulkLinkModal()" class="underline font-medium ml-1">Link them now →</button>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Products Grid -->
        <?php if (empty($products)): ?>
            <div class="pro-card p-12 text-center">
                <div class="empty-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No products found</h3>
                <p class="text-sm text-gray-500 mb-6">There are no products matching your criteria.</p>
                <button onclick="openAddProductModal()" class="btn-brand">
                    <i class="fas fa-plus text-sm"></i>
                    Add New Product
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($products as $product): 
                    $availableQty = floatval($product['available_quantity'] ?? 0);
                    $harvestTotal = floatval($product['harvest_total_quantity'] ?? 0);
                    $stockPercentage = ($harvestTotal > 0) ? ($availableQty / $harvestTotal) * 100 : 0;
                    $stockColor = $stockPercentage > 50 ? 'bg-emerald-500' : ($stockPercentage > 20 ? 'bg-amber-500' : 'bg-red-500');
                    $stockText = $stockPercentage > 50 ? 'Good Stock' : ($stockPercentage > 20 ? 'Low Stock' : 'Critical Stock');
                    $productImage = getProductImage($product['fish_name'] ?? '');
                ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <img src="<?php echo $productImage; ?>" alt="<?php echo htmlspecialchars($product['fish_name'] ?? ''); ?>" 
                                 class="product-image" onerror="this.onerror=null; this.src='../assets/images/fish/default-fish.jpg';">
                            <div class="image-overlay"></div>
                            <div class="stock-badge" style="background: <?php echo $stockPercentage <= 15 ? '#dc2626' : ($stockPercentage <= 40 ? '#f59e0b' : '#059669'); ?>">
                                <i class="fas fa-<?php echo $stockPercentage <= 15 ? 'exclamation-triangle' : ($stockPercentage <= 40 ? 'chart-line' : 'check-circle'); ?> mr-1"></i>
                                <?php echo number_format($availableQty, 1); ?> kg
                            </div>
                            <div class="fish-type-badge">
                                <i class="fas fa-fish mr-1"></i><?php echo htmlspecialchars($product['fish_name'] ?? ''); ?>
                            </div>
                        </div>
                        
                        <div class="p-4 flex flex-col flex-grow">
                            <!-- Price and Quantity -->
                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <div class="bg-gray-50 rounded-lg p-2 text-center">
                                    <p class="text-[10px] text-gray-500">Price/kg</p>
                                    <p class="text-base font-bold text-brand-600">₱<?php echo number_format(floatval($product['price_per_kg'] ?? 0), 2); ?></p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-2 text-center">
                                    <p class="text-[10px] text-gray-500">Available</p>
                                    <p class="text-base font-bold text-gray-900"><?php echo number_format($availableQty, 2); ?> kg</p>
                                </div>
                            </div>
                            
                            <!-- Stock Progress Bar -->
                            <?php if ($harvestTotal > 0): ?>
                            <div class="mb-3">
                                <div class="flex justify-between text-[10px] mb-1">
                                    <span class="text-gray-500">Stock Level</span>
                                    <span class="font-medium <?php echo $stockPercentage <= 15 ? 'text-red-600' : ($stockPercentage <= 40 ? 'text-amber-600' : 'text-emerald-600'); ?>">
                                        <?php echo number_format($stockPercentage, 1); ?>%
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                    <div class="h-full <?php echo $stockColor; ?>" style="width: <?php echo $stockPercentage; ?>%"></div>
                                </div>
                                <p class="text-[10px] text-gray-400 mt-1"><?php echo $stockText; ?></p>
                            </div>
                            <?php else: ?>
                            <div class="mb-3 p-2 bg-amber-50 rounded-lg text-center">
                                <p class="text-[10px] text-amber-600">No harvest batches linked yet</p>
                                <button onclick="openLinkHarvestModal(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['fish_name']); ?>')" 
                                        class="text-[10px] text-brand-600 hover:underline mt-1">
                                    + Link a harvest
                                </button>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Orders Info -->
                            <div class="grid grid-cols-2 gap-2 text-xs mb-3">
                                <div class="bg-gray-50 rounded-lg p-1.5 text-center">
                                    <p class="text-[10px] text-gray-500">Total Orders</p>
                                    <p class="font-medium text-gray-900"><?php echo intval($product['total_orders'] ?? 0); ?></p>
                                    <?php if (intval($product['pending_orders'] ?? 0) > 0): ?>
                                        <p class="text-[9px] text-amber-600"><?php echo $product['pending_orders']; ?> pending</p>
                                    <?php endif; ?>
                                </div>
                                <?php if (floatval($product['ordered_quantity'] ?? 0) > 0): ?>
                                <div class="bg-blue-50 rounded-lg p-1.5 text-center">
                                    <p class="text-[10px] text-gray-500">Ordered Qty</p>
                                    <p class="font-medium text-blue-600"><?php echo number_format(floatval($product['ordered_quantity']), 2); ?> kg</p>
                                </div>
                                <?php else: ?>
                                <div class="bg-gray-50 rounded-lg p-1.5 text-center">
                                    <p class="text-[10px] text-gray-500">Orders</p>
                                    <p class="font-medium text-gray-400">No orders yet</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="mt-auto pt-2 flex gap-2">
                                <button onclick="openPriceModal(<?php echo $product['product_id']; ?>, <?php echo floatval($product['price_per_kg'] ?? 0); ?>)" 
                                        class="btn-secondary text-xs py-1.5 flex-1">
                                    <i class="fas fa-tag"></i> Price
                                </button>
                                <?php if ($harvestTotal == 0): ?>
                                <button onclick="openLinkHarvestModal(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['fish_name']); ?>')" 
                                        class="btn-secondary text-xs py-1.5 flex-1 bg-amber-50 border-amber-200 text-amber-700 hover:bg-amber-100">
                                    <i class="fas fa-link"></i> Link
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-8">
                <?php 
                $queryParams = $_GET;
                unset($queryParams['page']);
                echo buildPaginationLinks($page, $totalPages, $queryParams); 
                ?>
                <div class="text-center text-xs text-gray-400 mt-3">
                    Showing <?php echo count($products); ?> of <?php echo number_format($totalRecords); ?> products
                    <span class="mx-2">•</span>
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-brand-500"></i>
                    Add New Product
                </h3>
                <button onclick="closeAddProductModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="" id="addProductForm">
                <input type="hidden" name="action" value="add_product">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Fish Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="fish_name" required class="form-input" placeholder="e.g., Tilapia, Bangus, Shrimp">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Price per kg (₱) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="price_per_kg" step="0.01" min="0.01" required class="form-input" placeholder="0.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Description <span class="text-gray-400">(Optional)</span>
                        </label>
                        <textarea name="description" rows="3" class="form-textarea" placeholder="Product description, quality notes, etc."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Link to Harvest <span class="text-gray-400">(Optional)</span>
                        </label>
                        <select name="harvest_id" class="form-select">
                            <option value="">None - Create product only</option>
                            <?php foreach ($unlinkedHarvests as $harvest): ?>
                                <option value="<?php echo $harvest['harvest_id']; ?>">
                                    <?php echo htmlspecialchars($harvest['batch_no']) . ' - ' . date('M d, Y', strtotime($harvest['created_at'])) . ' (' . number_format($harvest['total_quantity'], 2) . ' kg)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">Optionally link this product to an existing harvest batch</p>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeAddProductModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Link Harvest Modal -->
    <div id="linkHarvestModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-link text-amber-500"></i>
                    Link Harvest to Product
                </h3>
                <button onclick="closeLinkHarvestModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="link_harvest">
                <input type="hidden" name="product_id" id="link_product_id">
                
                <div class="space-y-4">
                    <div class="bg-blue-50 rounded-lg p-3 text-sm text-blue-700" id="productInfoDisplay">
                        <i class="fas fa-info-circle mr-1"></i>
                        Linking a harvest to <strong id="productNameDisplay">this product</strong> will make its stock available for ordering.
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Select Harvest Batch <span class="text-red-500">*</span>
                        </label>
                        <select name="harvest_id" id="link_harvest_select" required class="form-select">
                            <option value="">-- Select a harvest batch --</option>
                            <?php foreach ($unlinkedHarvests as $harvest): ?>
                                <option value="<?php echo $harvest['harvest_id']; ?>" 
                                        data-quantity="<?php echo $harvest['total_quantity']; ?>"
                                        data-batch="<?php echo htmlspecialchars($harvest['batch_no']); ?>">
                                    <?php echo htmlspecialchars($harvest['batch_no']) . ' - ' . date('M d, Y', strtotime($harvest['created_at'])) . ' (' . number_format($harvest['total_quantity'], 2) . ' kg)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">Only unlinked harvests are shown here</p>
                    </div>
                    
                    <div id="selectedHarvestInfo" class="hidden p-3 bg-green-50 rounded-lg text-sm text-green-700"></div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closeLinkHarvestModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-link"></i> Link Harvest</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Link Modal -->
    <div id="bulkLinkModal" class="modal">
        <div class="modal-content max-w-2xl">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-link text-amber-500"></i>
                    Link Unlinked Harvests
                </h3>
                <button onclick="closeBulkLinkModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <div class="space-y-4">
                <p class="text-sm text-gray-600">The following harvest batches are not linked to any product. Select a product for each harvest:</p>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="link_harvest">
                    
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach ($harvestsWithoutProducts as $harvest): ?>
                        <div class="border rounded-lg p-3 bg-gray-50">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($harvest['batch_no']); ?></p>
                                    <p class="text-xs text-gray-500">
                                        <?php echo date('M d, Y', strtotime($harvest['created_at'])); ?> • 
                                        <?php echo number_format($harvest['total_quantity'], 2); ?> kg total
                                    </p>
                                </div>
                                <div class="flex-1">
                                    <select name="link_data[<?php echo $harvest['harvest_id']; ?>]" class="form-select text-sm" required>
                                        <option value="">-- Select product --</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['product_id']; ?>">
                                                <?php echo htmlspecialchars($product['fish_name']); ?> (₱<?php echo number_format($product['price_per_kg'], 2); ?>/kg)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                        <button type="button" onclick="closeBulkLinkModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-brand"><i class="fas fa-link"></i> Link Selected</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Price Modal -->
    <div id="priceModal" class="modal">
        <div class="modal-content max-w-md">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-tag text-amber-500"></i>
                    Update Product Price
                </h3>
                <button onclick="closePriceModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_price">
                <input type="hidden" name="product_id" id="price_product_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Price per kg (₱)
                    </label>
                    <input type="number" name="price_per_kg" id="price_per_kg" step="0.01" min="0" required class="form-input">
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                    <button type="button" onclick="closePriceModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-brand"><i class="fas fa-save"></i> Update Price</button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function openAddProductModal() { 
            document.getElementById('addProductModal').classList.add('show'); 
            document.body.style.overflow = 'hidden'; 
        }
        
        function closeAddProductModal() { 
            document.getElementById('addProductModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function openPriceModal(id, price) { 
            document.getElementById('price_product_id').value = id; 
            document.getElementById('price_per_kg').value = price; 
            document.getElementById('priceModal').classList.add('show'); 
            document.body.style.overflow = 'hidden'; 
        }
        
        function closePriceModal() { 
            document.getElementById('priceModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function openLinkHarvestModal(productId = null, productName = null) {
            if (productId) {
                document.getElementById('link_product_id').value = productId;
                document.getElementById('productNameDisplay').textContent = productName;
            }
            document.getElementById('linkHarvestModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLinkHarvestModal() { 
            document.getElementById('linkHarvestModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        function openBulkLinkModal() {
            document.getElementById('bulkLinkModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeBulkLinkModal() { 
            document.getElementById('bulkLinkModal').classList.remove('show'); 
            document.body.style.overflow = 'auto'; 
        }
        
        // Show harvest info when selected
        const harvestSelect = document.getElementById('link_harvest_select');
        if (harvestSelect) {
            harvestSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const infoDiv = document.getElementById('selectedHarvestInfo');
                
                if (this.value) {
                    const quantity = selectedOption.dataset.quantity || '0';
                    const batchNo = selectedOption.dataset.batch || '';
                    infoDiv.innerHTML = `<i class="fas fa-check-circle mr-1 text-green-500"></i> 
                        Selected: <strong>${batchNo}</strong> (${parseFloat(quantity).toFixed(2)} kg available)`;
                    infoDiv.classList.remove('hidden');
                } else {
                    infoDiv.classList.add('hidden');
                }
            });
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
                closeAddProductModal();
                closePriceModal();
                closeLinkHarvestModal();
                closeBulkLinkModal();
            }
        });
    </script>
</body>
</html>