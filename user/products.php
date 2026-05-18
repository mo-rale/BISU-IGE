<?php
/**
 * user/products.php - WITH UI DEBUGGER (Works for all users)
 * Employee-facing product ordering page with debug mode.
 */

require_once '../includes/config.php';
require_once '../includes/session.php';
require_once '../includes/FifoStock.php';

SessionManager::requireLogin();

if (SessionManager::isOfficeUser()) {
    header('Location: ../products.php');
    exit;
}

$db     = (new Database())->getConnection();
$fifo   = new FifoStock($db);
$userId = SessionManager::getUserId();

$errors  = [];
$success = '';
$debug = []; // Store debug information

// Enable debug mode for ANY user (just add ?debug=1 to URL)
$isDebug = isset($_GET['debug']) && $_GET['debug'] == '1';

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $rawItems = $_POST['items'] ?? [];
    $remarks  = trim($_POST['remarks'] ?? '');

    $orderItems = [];
    foreach ($rawItems as $productId => $qty) {
        $qty = (float)$qty;
        if ($qty > 0) {
            $orderItems[] = ['product_id' => (int)$productId, 'quantity_kg' => $qty];
        }
    }

    if (empty($orderItems)) {
        $errors[] = 'Please enter a quantity for at least one fish product.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $totalAmount = 0;
            $productData = [];
            
            foreach ($orderItems as $item) {
                $pStmt = $db->prepare("SELECT product_id, fish_name, price_per_kg FROM fish_products WHERE product_id = :id");
                $pStmt->execute([':id' => $item['product_id']]);
                $product = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) { throw new Exception("Product not found: " . $item['product_id']); }

                $available = $fifo->getAvailableStock($item['product_id']);
                if ($available < $item['quantity_kg']) {
                    throw new Exception("Insufficient stock for {$product['fish_name']}. Available: {$available} kg, Requested: {$item['quantity_kg']} kg.");
                }

                $productData[$item['product_id']] = $product;
                $totalAmount += $item['quantity_kg'] * (float)$product['price_per_kg'];
            }

            $oStmt = $db->prepare("
                INSERT INTO orders (user_id, order_status, payment_method, total_amount, remarks)
                VALUES (:uid, 'pending', 'salary_deduction', :total, :remarks)
                RETURNING order_id
            ");
            $oStmt->execute([':uid' => $userId, ':total' => $totalAmount, ':remarks' => $remarks ?: null]);
            $orderId = (int)$oStmt->fetchColumn();

            foreach ($orderItems as $item) {
                $pid    = $item['product_id'];
                $qty    = $item['quantity_kg'];
                $ppkg   = (float)$productData[$pid]['price_per_kg'];
                $sub    = $qty * $ppkg;

                $iStmt = $db->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price_per_kg, subtotal)
                    VALUES (:oid, :pid, :qty, :ppkg, :sub)
                    RETURNING order_item_id
                ");
                $iStmt->execute([':oid'=>$orderId,':pid'=>$pid,':qty'=>$qty,':ppkg'=>$ppkg,':sub'=>$sub]);
                $orderItemId = (int)$iStmt->fetchColumn();

                $result = $fifo->deductStock($pid, $orderItemId, $qty);
                if (!$result['success']) throw new Exception($result['message']);
            }

            $db->prepare("
                INSERT INTO salary_deductions (order_id, user_id, total_amount, remaining_balance, deduction_status, deduction_start_date)
                VALUES (:oid, :uid, :amt, :bal, 'pending', CURRENT_DATE)
            ")->execute([':oid'=>$orderId,':uid'=>$userId,':amt'=>$totalAmount,':bal'=>$totalAmount]);

            $db->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:uid, 'Order Placed', :msg, 'order')
            ")->execute([':uid'=>$userId, ':msg'=>"Your order #{$orderId} has been placed. Total: ₱".number_format($totalAmount,2)]);

            $db->commit();
            $success = "Order #{$orderId} placed successfully! Total: ₱" . number_format($totalAmount, 2);

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// ============ DEBUG QUERIES (Only run if debug mode is on) ============
if ($isDebug) {
    // Debug 1: Check all fish products
    $allProducts = $db->query("SELECT * FROM fish_products ORDER BY product_id")->fetchAll(PDO::FETCH_ASSOC);
    $debug['all_products'] = $allProducts;
    $debug['total_products'] = count($allProducts);
    
    // Debug 2: Check all harvests
    $allHarvests = $db->query("
        SELECT h.*, fp.fish_name 
        FROM harvest h
        LEFT JOIN fish_products fp ON fp.product_id = h.fish_product_id
        ORDER BY h.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug['all_harvests'] = $allHarvests;
    $debug['total_harvests'] = count($allHarvests);
    
    // Debug 3: Check harvests that should be included
    $validHarvests = $db->query("
        SELECT h.*, fp.fish_name, fp.product_id, fp.price_per_kg
        FROM harvest h
        INNER JOIN fish_products fp ON fp.product_id = h.fish_product_id
        WHERE h.remaining_quantity > 0
          AND h.status = 'completed'
        ORDER BY h.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug['valid_harvests_for_products'] = $validHarvests;
    $debug['total_valid_harvests'] = count($validHarvests);
    
    // Debug 4: Check products that would appear
    $productsWithStock = $db->query("
        SELECT
            fp.product_id,
            fp.fish_name,
            fp.price_per_kg,
            COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity
        FROM fish_products fp
        INNER JOIN harvest h ON h.fish_product_id = fp.product_id
        WHERE h.remaining_quantity > 0
          AND h.status = 'completed'
        GROUP BY fp.product_id, fp.fish_name, fp.price_per_kg
        HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
        ORDER BY fp.fish_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug['products_that_will_appear'] = $productsWithStock;
    $debug['total_visible_products'] = count($productsWithStock);
    
    // Debug 5: Check status values in harvest table
    $statusCounts = $db->query("
        SELECT status, COUNT(*) as count, SUM(remaining_quantity) as total_kg
        FROM harvest
        GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug['harvest_status_summary'] = $statusCounts;
    
    // Debug 6: Check products with no linked harvests
    $productsWithoutHarvest = $db->query("
        SELECT fp.*
        FROM fish_products fp
        LEFT JOIN harvest h ON h.fish_product_id = fp.product_id
        WHERE h.harvest_id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug['products_without_harvest'] = $productsWithoutHarvest;
    
    // Debug 7: Check the actual query being used
    $debug['query_used'] = "
        SELECT fp.product_id, fp.fish_name, fp.description, fp.price_per_kg, COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity
        FROM fish_products fp
        INNER JOIN harvest h ON h.fish_product_id = fp.product_id AND h.remaining_quantity > 0 AND h.status = 'completed'
        GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg
        HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
        ORDER BY fp.fish_name ASC
    ";
}

// ============ MAIN PRODUCT QUERY ============
$products = $db->query("
    SELECT
        fp.product_id,
        fp.fish_name,
        fp.description,
        fp.price_per_kg,
        COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity
    FROM fish_products fp
    INNER JOIN harvest h
        ON h.fish_product_id = fp.product_id
       AND h.remaining_quantity > 0
       AND h.status = 'completed'
    GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg
    HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
    ORDER BY fp.fish_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get user's pending orders
$activeOrderProducts = $db->prepare("
    SELECT DISTINCT oi.product_id
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    WHERE o.user_id = :uid AND o.order_status NOT IN ('cancelled', 'claimed')
");
$activeOrderProducts->execute([':uid' => $userId]);
$orderedProductIds = array_column($activeOrderProducts->fetchAll(PDO::FETCH_ASSOC), 'product_id');

// User info
$userStmt = $db->prepare("SELECT full_name, department, position FROM users WHERE user_id = :uid");
$userStmt->execute([':uid' => $userId]);
$userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

// Helper function for images
function getFishImage($fishName) {
    $cleanName = strtolower(trim($fishName));
    $cleanName = preg_replace('/[^a-z]/', '', $cleanName);
    
    $imageMap = [
        'bangus' => 'bangus-default.jpg',
        'tilapia' => 'tilapia.jpg',
        'galunggong' => 'galunggong.jpg',
        'shrimp' => 'shrimp-default.jpg',
        'tanigue' => 'tanigue.jpg',
        'maya' => 'maya-maya.jpg',
        'lapulapu' => 'lapu-lapu.jpg',
        'sugpo' => 'sugpo.jpg',
        'tulingan' => 'tulingan.jpg',
        'salmon' => 'salmon.jpg',
        'tuna' => 'tuna.jpg',
    ];
    
    foreach ($imageMap as $key => $filename) {
        if (strpos($cleanName, $key) !== false) {
            $imagePath = '../assets/images/fish/' . $filename;
            if (file_exists($imagePath)) {
                return $imagePath;
            }
        }
    }
    return '../assets/images/default-fish.jpg';
}

foreach ($products as &$product) {
    $product['image_path'] = getFishImage($product['fish_name']);
    $avail = $product['available_quantity'];
    $product['stock_percentage'] = $avail > 0 ? min(100, ($avail / max($avail, 50)) * 100) : 0;
    $product['stock_color'] = $avail > 50 ? '#10b981' : ($avail > 20 ? '#f59e0b' : '#ef4444');
    $product['stock_text'] = $avail > 50 ? 'In Stock' : ($avail > 20 ? 'Limited' : 'Low Stock');
}

// Check if we need to show debug info to user
$showDebugNotice = $isDebug && empty($products) && $debug['total_products'] > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Fresh Fish - BISU IGE</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: linear-gradient(135deg, #f0f9ff 0%, #e6f2f9 100%); min-height: 100vh; }
.glass-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(2px); }
.product-card { transition: all 0.25s ease; cursor: pointer; }
.product-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -12px rgba(0,0,0,0.15); }
.qty-btn { transition: all 0.2s ease; }
.qty-btn:hover { transform: scale(1.05); }
.stock-bar { height: 5px; border-radius: 3px; background: #e2e8f0; overflow: hidden; }
.stock-fill { height: 100%; border-radius: 3px; transition: width 0.3s ease; }
.image-container { background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); }
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.debug-panel { background: #1e1e2e; color: #cdd6f4; font-family: 'Courier New', monospace; }
.debug-table { width: 100%; border-collapse: collapse; }
.debug-table th, .debug-table td { border: 1px solid #313244; padding: 8px; text-align: left; font-size: 12px; }
.debug-table th { background: #313244; color: #89b4fa; }
.debug-badge { background: #f38ba8; color: #1e1e2e; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
.debug-good { background: #a6e3a1; color: #1e1e2e; }
.debug-bad { background: #f38ba8; color: #1e1e2e; }
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 py-8">
  
  <!-- Debug Mode Banner -->
  <?php if ($isDebug): ?>
  <div class="mb-6 bg-purple-100 border-l-4 border-purple-500 rounded-xl p-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <i class="fas fa-bug text-purple-600 mr-2"></i>
        <span class="font-semibold text-purple-800">Debug Mode Active</span>
        <span class="text-sm text-purple-600 ml-2">Remove ?debug=1 from URL to disable</span>
      </div>
      <a href="?" class="text-sm text-purple-600 hover:underline">Turn Off Debug</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="bg-gradient-to-r from-blue-700 to-cyan-600 rounded-2xl p-6 mb-8 text-white shadow-lg">
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div>
        <h1 class="text-2xl font-bold mb-1"><i class="fas fa-shopping-cart mr-2"></i>Order Fresh Fish</h1>
        <p class="text-blue-100 text-sm">
          Hello, <strong><?= htmlspecialchars($userInfo['full_name'] ?? 'Employee') ?></strong>! 
          Select your fresh seafood. Payment via <i class="fas fa-id-card ml-1 mr-1"></i>salary deduction.
        </p>
      </div>
      <div class="bg-white/20 backdrop-blur-sm px-4 py-2 rounded-xl text-sm">
        <i class="fas fa-truck-fast mr-2"></i>Free delivery to campus
      </div>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-xl text-sm text-red-700 shadow-sm">
    <?php foreach ($errors as $e): ?><p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
  </div>
  <?php endif; ?>
  
  <?php if ($success): ?>
  <div class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-500 rounded-xl text-sm text-emerald-800 shadow-sm">
    <i class="fas fa-check-circle mr-2 text-emerald-500"></i><?= htmlspecialchars($success) ?>
    <div class="mt-2"><a href="orders.php" class="text-emerald-700 font-medium underline hover:text-emerald-800">View my orders →</a></div>
  </div>
  <?php endif; ?>

  <!-- DEBUG PANEL (Shows for ALL users when debug=1) -->
  <?php if ($isDebug && !empty($debug)): ?>
  <div class="debug-panel rounded-xl p-5 mb-8 overflow-x-auto">
    <h2 class="text-xl font-bold mb-4 text-white"><i class="fas fa-chart-line mr-2"></i>Debug Information</h2>
    
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-#313244 rounded-lg p-3">
        <div class="text-sm text-gray-400">Total Products</div>
        <div class="text-2xl font-bold text-white"><?= $debug['total_products'] ?? 0 ?></div>
      </div>
      <div class="bg-#313244 rounded-lg p-3">
        <div class="text-sm text-gray-400">Total Harvests</div>
        <div class="text-2xl font-bold text-white"><?= $debug['total_harvests'] ?? 0 ?></div>
      </div>
      <div class="bg-#313244 rounded-lg p-3">
        <div class="text-sm text-gray-400">Valid Harvests</div>
        <div class="text-2xl font-bold <?= ($debug['total_valid_harvests'] ?? 0) > 0 ? 'text-green-400' : 'text-red-400' ?>">
          <?= $debug['total_valid_harvests'] ?? 0 ?>
        </div>
      </div>
      <div class="bg-#313244 rounded-lg p-3">
        <div class="text-sm text-gray-400">Visible Products</div>
        <div class="text-2xl font-bold <?= ($debug['total_visible_products'] ?? 0) > 0 ? 'text-green-400' : 'text-red-400' ?>">
          <?= $debug['total_visible_products'] ?? 0 ?>
        </div>
      </div>
    </div>
    
    <!-- Harvest Status Summary -->
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-white"><i class="fas fa-tags mr-2"></i>Harvest Status Summary</h3>
      <table class="debug-table">
        <thead>
          <tr><th>Status</th><th>Count</th><th>Total Stock (kg)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($debug['harvest_status_summary'] ?? [] as $status): ?>
          <tr>
            <td><span class="debug-badge <?= $status['status'] == 'completed' ? 'debug-good' : '' ?>"><?= $status['status'] ?? 'NULL' ?></span></td>
            <td><?= $status['count'] ?></td>
            <td><?= number_format($status['total_kg'] ?? 0, 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <!-- All Harvests -->
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-white"><i class="fas fa-database mr-2"></i>All Harvests</h3>
      <table class="debug-table">
        <thead>
          <tr><th>ID</th><th>Batch #</th><th>Linked Product</th><th>Total (kg)</th><th>Remaining (kg)</th><th>Status</th><th>Created</th></tr>
        </thead>
        <tbody>
          <?php foreach ($debug['all_harvests'] ?? [] as $h): ?>
          <?php 
          $isValid = ($h['status'] == 'completed' && $h['remaining_quantity'] > 0 && $h['fish_product_id']);
          ?>
          <tr style="<?= $isValid ? 'background: #2a2a3e' : '' ?>">
            <td><?= $h['harvest_id'] ?></td>
            <td><?= htmlspecialchars($h['batch_no']) ?></td>
            <td>
              <?php if ($h['fish_product_id']): ?>
                <span class="text-green-400">✓ <?= htmlspecialchars($h['fish_name'] ?? 'ID: ' . $h['fish_product_id']) ?></span>
              <?php else: ?>
                <span class="text-red-400">✗ NOT LINKED</span>
              <?php endif; ?>
            </td>
            <td><?= number_format($h['total_quantity'], 2) ?></td>
            <td><?= number_format($h['remaining_quantity'], 2) ?></td>
            <td><span class="debug-badge <?= $h['status'] == 'completed' ? 'debug-good' : 'debug-bad' ?>"><?= $h['status'] ?></span></td>
            <td><?= date('Y-m-d', strtotime($h['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Valid Harvests -->
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-green-400"><i class="fas fa-check-circle mr-2"></i>Valid Harvests (Will contribute to stock)</h3>
      <p class="text-sm text-gray-400 mb-3">✅ Conditions: status = 'completed', remaining_quantity > 0, linked to product</p>
      <table class="debug-table">
        <thead>
          <tr><th>Batch #</th><th>Fish Name</th><th>Product ID</th><th>Remaining (kg)</th><th>Price/kg</th></tr>
        </thead>
        <tbody>
          <?php foreach ($debug['valid_harvests_for_products'] ?? [] as $h): ?>
          <tr class="bg-#2a2a3e">
            <td><?= htmlspecialchars($h['batch_no']) ?></td>
            <td class="text-green-400"><?= htmlspecialchars($h['fish_name']) ?></td>
            <td><?= $h['product_id'] ?></td>
            <td class="font-bold text-green-400"><?= number_format($h['remaining_quantity'], 2) ?></td>
            <td>₱<?= number_format($h['price_per_kg'] ?? 0, 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($debug['valid_harvests_for_products'])): ?>
          <tr><td colspan="5" class="text-center text-red-400">⚠ NO valid harvests found! Check conditions above.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Products Without Harvest Link -->
    <?php if (!empty($debug['products_without_harvest'])): ?>
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-yellow-400"><i class="fas fa-exclamation-triangle mr-2"></i>Products Without Linked Harvests</h3>
      <table class="debug-table">
        <thead>
          <tr><th>Product ID</th><th>Fish Name</th><th>Price/kg</th><th>Action Needed</th></tr>
        </thead>
        <tbody>
          <?php foreach ($debug['products_without_harvest'] as $p): ?>
          <tr>
            <td><?= $p['product_id'] ?></td>
            <td><?= htmlspecialchars($p['fish_name']) ?></td>
            <td>₱<?= number_format($p['price_per_kg'], 2) ?></td>
            <td class="text-yellow-400">Link a harvest batch to this product</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    
    <!-- Products That Will Appear -->
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-blue-400"><i class="fas fa-fish mr-2"></i>Products That Will Appear</h3>
      <table class="debug-table">
        <thead>
          <tr><th>Product ID</th><th>Fish Name</th><th>Available Stock (kg)</th><th>Price/kg</th></tr>
        </thead>
        <tbody>
          <?php foreach ($debug['products_that_will_appear'] ?? [] as $p): ?>
          <tr class="bg-#2a2a3e">
            <td><?= $p['product_id'] ?></td>
            <td class="text-blue-400"><?= htmlspecialchars($p['fish_name']) ?></td>
            <td class="font-bold text-green-400"><?= number_format($p['available_quantity'], 2) ?></td>
            <td>₱<?= number_format($p['price_per_kg'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($debug['products_that_will_appear'])): ?>
          <tr><td colspan="4" class="text-center text-red-400">⚠ No products will appear! See troubleshooting below.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Query Used -->
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-3 text-white"><i class="fas fa-code mr-2"></i>SQL Query Being Used</h3>
      <div class="bg-#181825 p-3 rounded-lg text-xs font-mono text-gray-300 overflow-x-auto">
        <?= nl2br(htmlspecialchars($debug['query_used'] ?? '')) ?>
      </div>
    </div>
    
    <!-- Troubleshooting Guide -->
    <div class="bg-#181825 rounded-lg p-4 mt-4">
      <h3 class="text-lg font-semibold mb-3 text-white"><i class="fas fa-wrench mr-2"></i>Troubleshooting Guide</h3>
      <div class="space-y-2 text-sm">
        <?php if (empty($debug['valid_harvests_for_products'])): ?>
        <div class="text-red-400">❌ <strong>No valid harvests found.</strong> Solutions:</div>
        <ul class="list-disc pl-5 text-gray-300 space-y-1">
          <li>Make sure harvests have <code class="bg-#313244 px-1">status = 'completed'</code></li>
          <li>Make sure harvests have <code class="bg-#313244 px-1">remaining_quantity > 0</code></li>
          <li>Make sure harvests have <code class="bg-#313244 px-1">fish_product_id</code> set (linked to a product)</li>
          <li>Run: <code class="bg-#313244 px-1">UPDATE harvest SET status = 'completed';</code></li>
        </ul>
        <?php endif; ?>
        
        <?php if (!empty($debug['products_without_harvest'])): ?>
        <div class="text-yellow-400">⚠ <strong><?= count($debug['products_without_harvest']) ?> products exist but have no harvest linked.</strong> Solutions:</div>
        <ul class="list-disc pl-5 text-gray-300 space-y-1">
          <li>Go to Manage Products and click "Link" on each product</li>
          <li>Or run SQL: <code class="bg-#313244 px-1">UPDATE harvest SET fish_product_id = [product_id] WHERE batch_no = '[batch_no]';</code></li>
        </ul>
        <?php endif; ?>
        
        <?php if ($debug['total_products'] == 0): ?>
        <div class="text-red-400">❌ <strong>No products in database.</strong> Solutions:</div>
        <ul class="list-disc pl-5 text-gray-300 space-y-1">
          <li>Go to Manage Products and add a product</li>
          <li>Make sure product has price_per_kg > 0</li>
        </ul>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Quick Fix SQL -->
    <div class="bg-#181825 rounded-lg p-4 mt-4">
      <h3 class="text-lg font-semibold mb-3 text-white"><i class="fas fa-database mr-2"></i>Quick Fix SQL Commands</h3>
      <div class="space-y-2 text-sm">
        <div class="bg-#1e1e2e p-3 rounded font-mono text-xs text-gray-300 overflow-x-auto">
          -- Fix 1: Update all harvests to 'completed' status<br>
          UPDATE harvest SET status = 'completed' WHERE status != 'depleted';<br><br>
          -- Fix 2: Check what harvests are linked to what<br>
          SELECT h.harvest_id, h.batch_no, h.status, h.remaining_quantity, fp.fish_name<br>
          FROM harvest h<br>
          LEFT JOIN fish_products fp ON fp.product_id = h.fish_product_id;<br><br>
          -- Fix 3: Link a specific harvest to a product<br>
          UPDATE harvest SET fish_product_id = [PRODUCT_ID] WHERE harvest_id = [HARVEST_ID];
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($products)): ?>
  <div class="glass-card rounded-2xl p-12 text-center shadow-md">
    <i class="fas fa-fish text-gray-300 text-6xl mb-4"></i>
    <h3 class="text-xl font-medium text-gray-500">No fresh catch available</h3>
    <p class="text-gray-400 mt-2">New harvest batches will appear here soon.</p>
    
    <!-- Show helpful message for users when debug is on -->
    <?php if ($isDebug && $showDebugNotice): ?>
    <div class="mt-4 p-3 bg-yellow-50 rounded-lg text-left text-sm text-yellow-700">
      <p class="font-medium mb-2"><i class="fas fa-info-circle mr-1"></i>Debug Mode - Why no products?</p>
      <ul class="list-disc pl-5 space-y-1 text-xs">
        <?php if (($debug['total_valid_harvests'] ?? 0) == 0): ?>
        <li>❌ No valid harvests found. Check the Debug Panel above.</li>
        <?php endif; ?>
        <?php if (!empty($debug['products_without_harvest'])): ?>
        <li>⚠ Products exist but no harvests linked to them.</li>
        <?php endif; ?>
        <?php if (($debug['total_products'] ?? 0) == 0): ?>
        <li>❌ No products in the database. Add products first.</li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <form method="POST" id="orderForm">
    <input type="hidden" name="action" value="place_order">

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
      <?php foreach ($products as $p):
        $pid = (int)$p['product_id'];
        $avail = (float)$p['available_quantity'];
        $price = (float)$p['price_per_kg'];
        $already = in_array($pid, $orderedProductIds);
        $imageUrl = $p['image_path'];
      ?>
      <div class="product-card glass-card rounded-xl overflow-hidden shadow-md border border-white/50 <?= $already ? 'opacity-60' : '' ?>" id="card-<?= $pid ?>">
        <div class="image-container h-44 relative overflow-hidden">
          <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($p['fish_name']) ?>" class="w-full h-full object-cover transition-transform duration-300 hover:scale-105" onerror="this.src='../assets/images/default-fish.jpg'">
          <?php if ($already): ?>
          <div class="absolute top-3 right-3 bg-yellow-500 text-white text-xs px-2 py-1 rounded-full shadow-md">
            <i class="fas fa-clock mr-1"></i>Pending Order
          </div>
          <?php endif; ?>
          <div class="absolute bottom-3 left-3 bg-black/60 text-white text-xs px-2 py-1 rounded-full backdrop-blur-sm">
            <i class="fas fa-weight-hanging mr-1"></i><?= number_format($avail, 1) ?> kg left
          </div>
        </div>
        
        <div class="p-4">
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($p['fish_name']) ?></h3>
            <span class="bg-blue-50 text-blue-700 font-bold px-2 py-1 rounded-lg text-sm">₱<?= number_format($price, 2) ?><span class="text-xs font-normal text-gray-500">/kg</span></span>
          </div>
          
          <?php if ($p['description']): ?>
          <p class="text-xs text-gray-500 mb-3 line-clamp-2"><?= htmlspecialchars(mb_substr($p['description'],0,80)) ?></p>
          <?php endif; ?>
          
          <div class="mb-3">
            <div class="flex justify-between text-xs mb-1">
              <span class="text-gray-500">Availability</span>
              <span class="<?= $avail > 20 ? 'text-green-600' : ($avail > 5 ? 'text-amber-600' : 'text-red-600') ?> font-medium">
                <?= $p['stock_text'] ?>
              </span>
            </div>
            <div class="stock-bar">
              <div class="stock-fill" style="width:<?= $p['stock_percentage'] ?>%;background:<?= $p['stock_color'] ?>"></div>
            </div>
          </div>
          
          <?php if ($already): ?>
          <div class="bg-gray-50 rounded-lg p-3 text-center text-sm text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>You already have an active order for this product.
          </div>
          <?php else: ?>
          <div class="flex items-center justify-between mt-2">
            <div class="flex items-center gap-2 bg-gray-100 rounded-lg p-1">
              <button type="button" onclick="adjustQty(<?= $pid ?>, -0.5)" class="qty-btn w-8 h-8 rounded-lg bg-white shadow-sm text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition flex items-center justify-center font-bold">−</button>
              <input type="number" name="items[<?= $pid ?>]" id="qty-<?= $pid ?>" class="w-16 text-center border-0 bg-transparent font-medium text-gray-700 focus:outline-none" step="0.5" min="0" max="<?= $avail ?>" value="0" oninput="updateSubtotal(<?= $pid ?>, <?= $price ?>)" onchange="validateQty(this, <?= $avail ?>)">
              <button type="button" onclick="adjustQty(<?= $pid ?>, 0.5)" class="qty-btn w-8 h-8 rounded-lg bg-white shadow-sm text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition flex items-center justify-center font-bold">+</button>
              <span class="text-xs text-gray-400 ml-1">kg</span>
            </div>
            <div class="text-right">
              <span class="text-xs text-gray-400">Subtotal</span>
              <span class="font-semibold text-blue-600 block text-sm" id="sub-<?= $pid ?>">₱0.00</span>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="lg:sticky lg:bottom-6 z-10">
      <div class="glass-card rounded-xl shadow-xl border-t-4 border-blue-500 p-5 bg-white">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
              <i class="fas fa-receipt text-blue-600"></i>
            </div>
            <div>
              <h3 class="font-semibold text-gray-800">Order Summary</h3>
              <p class="text-xs text-gray-400" id="itemCount">No items selected</p>
            </div>
          </div>
          
          <div class="flex flex-wrap items-center gap-4 flex-1 justify-end">
            <div class="bg-gray-50 px-4 py-2 rounded-lg">
              <span class="text-xs text-gray-500 block">Payment</span>
              <span class="font-medium text-gray-700"><i class="fas fa-id-card mr-1 text-blue-400"></i>Salary Deduction</span>
            </div>
            <div class="bg-blue-50 px-4 py-2 rounded-lg">
              <span class="text-xs text-blue-600 block">Total Amount</span>
              <span class="font-bold text-2xl text-blue-700" id="grandTotal">₱0.00</span>
            </div>
            <button type="submit" id="submitBtn" disabled class="px-6 py-3 rounded-xl font-semibold transition-all duration-200 bg-gray-300 text-gray-500 cursor-not-allowed">
              <i class="fas fa-check mr-2"></i>Place Order
            </button>
          </div>
        </div>
        <div id="summaryList" class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-500 hidden md:block"></div>
      </div>
    </div>
    
    <div class="mt-6">
      <label class="text-sm font-medium text-gray-700 block mb-2"><i class="fas fa-sticky-note mr-1 text-gray-400"></i>Special Instructions (optional)</label>
      <textarea name="remarks" rows="2" class="w-full border border-gray-200 rounded-xl p-3 text-sm focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200 transition" placeholder="Any special request or notes for your order…"></textarea>
    </div>
    
    <p class="text-xs text-gray-400 text-center mt-4 pb-4">
      <i class="fas fa-info-circle mr-1"></i>
      Your order is subject to manager confirmation before processing. Deductions will be reflected on your next payroll.
    </p>
  </form>

  <?php endif; ?>
</div>

<script>
const prices = <?= json_encode(array_column($products, 'price_per_kg', 'product_id')) ?>;
const names  = <?= json_encode(array_column($products, 'fish_name',   'product_id')) ?>;
const maxQty = <?= json_encode(array_column($products, 'available_quantity', 'product_id')) ?>;

function validateQty(input, max) {
    let val = parseFloat(input.value) || 0;
    if (val > max) { input.value = max; val = max; }
    if (val < 0) input.value = 0;
    updateSubtotal(parseInt(input.name.match(/\d+/)[0]), prices[parseInt(input.name.match(/\d+/)[0])]);
}

function adjustQty(pid, delta) {
    const inp = document.getElementById('qty-' + pid);
    if (!inp) return;
    let val = (parseFloat(inp.value) || 0) + delta;
    val = Math.max(0, Math.min(parseFloat(maxQty[pid] || 0), parseFloat(val.toFixed(2))));
    inp.value = val;
    updateSubtotal(pid, prices[pid]);
}

function updateSubtotal(pid, pricePerKg) {
    const qty = parseFloat(document.getElementById('qty-' + pid)?.value) || 0;
    const sub = qty * pricePerKg;
    const subEl = document.getElementById('sub-' + pid);
    if (subEl) subEl.textContent = '₱' + sub.toFixed(2);

    const card = document.getElementById('card-' + pid);
    if (card) {
        if (qty > 0) {
            card.classList.add('ring-2', 'ring-blue-400', 'shadow-lg');
            card.classList.remove('shadow-md');
        } else {
            card.classList.remove('ring-2', 'ring-blue-400', 'shadow-lg');
            card.classList.add('shadow-md');
        }
    }
    updateSummary();
}

function updateSummary() {
    const listDiv = document.getElementById('summaryList');
    const totEl  = document.getElementById('grandTotal');
    const btn    = document.getElementById('submitBtn');
    const itemCountSpan = document.getElementById('itemCount');
    let total = 0, itemCount = 0;
    let html = '<div class="flex flex-wrap gap-3 justify-end">';

    for (const pid in prices) {
        const qty = parseFloat(document.getElementById('qty-' + pid)?.value) || 0;
        if (qty > 0) {
            const sub = qty * prices[pid];
            total += sub;
            itemCount++;
            html += `<div class="bg-gray-50 rounded-lg px-3 py-1 text-xs"><span class="font-medium">${names[pid]}</span> ${qty}kg <span class="text-blue-600">₱${sub.toFixed(2)}</span></div>`;
        }
    }
    html += '</div>';

    if (itemCount > 0) {
        listDiv.innerHTML = html;
        listDiv.classList.remove('hidden');
        itemCountSpan.innerHTML = `${itemCount} item${itemCount > 1 ? 's' : ''} selected`;
    } else {
        listDiv.innerHTML = '';
        listDiv.classList.add('hidden');
        itemCountSpan.innerHTML = 'No items selected';
    }
    
    totEl.textContent = '₱' + total.toFixed(2);

    if (total > 0) {
        btn.disabled = false;
        btn.className = 'px-6 py-3 rounded-xl font-semibold transition-all duration-200 bg-blue-600 hover:bg-blue-700 text-white shadow-md hover:shadow-lg cursor-pointer';
    } else {
        btn.disabled = true;
        btn.className = 'px-6 py-3 rounded-xl font-semibold transition-all duration-200 bg-gray-300 text-gray-500 cursor-not-allowed';
    }
}

function confirmOrder() {
    const total = document.getElementById('grandTotal').textContent;
    const items = [];
    for (const pid in prices) {
        const qty = parseFloat(document.getElementById('qty-' + pid)?.value) || 0;
        if (qty > 0) items.push(`${qty}kg of ${names[pid]}`);
    }
    if (items.length === 0) {
        alert('Please select at least one item to order.');
        return false;
    }
    return confirm(`Confirm your order?\n\nItems: ${items.join(', ')}\nTotal: ${total}\nPayment: Salary Deduction\n\nThis amount will be deducted from your salary.`);
}

document.getElementById('orderForm')?.addEventListener('submit', function(e) {
    if (!confirmOrder()) e.preventDefault();
});

document.addEventListener('DOMContentLoaded', function() {
    for (const pid in prices) {
        const inp = document.getElementById('qty-' + pid);
        if (inp && inp.value > 0) updateSubtotal(pid, prices[pid]);
    }
});
</script>
</body>
</html>