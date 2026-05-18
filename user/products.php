<?php
/**
 * user/products.php
 * Employee-facing product ordering page.
 * Updated for harvest-based inventory schema with fish images.
 * Stock shown is live SUM of harvest.remaining_quantity.
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

// ── Handle order submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $rawItems = $_POST['items'] ?? [];
    $remarks  = trim($_POST['remarks'] ?? '');

    // Filter only items with quantity > 0
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
        // Use the API internally via FifoStock + direct DB (mirrors api/place_order.php)
        $db->beginTransaction();
        try {
            // Pre-flight check
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

            // Create order
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

            // Salary deduction record
            $db->prepare("
                INSERT INTO salary_deductions (order_id, user_id, total_amount, remaining_balance, deduction_status, deduction_start_date)
                VALUES (:oid, :uid, :amt, :bal, 'pending', CURRENT_DATE)
            ")->execute([':oid'=>$orderId,':uid'=>$userId,':amt'=>$totalAmount,':bal'=>$totalAmount]);

            // Notification
            $db->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:uid, 'Order Placed', :msg, 'order')
            ")->execute([':uid'=>$userId, ':msg'=>"Your order #{$orderId} has been placed. Total: ₱".number_format($totalAmount,2)]);

            $db->commit();
            $success = "Order #{$orderId} placed successfully! Total: ₱" . number_format($totalAmount, 2) . ". You will be notified once it is confirmed.";

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// ── Fetch products with live stock (using your actual harvest table structure) ──
$products = $db->query("
    SELECT
        fp.product_id,
        fp.fish_name,
        fp.description,
        fp.price_per_kg,
        COALESCE(SUM(h.remaining_quantity), 0) AS available_quantity
    FROM fish_products fp
    LEFT JOIN harvest h
        ON h.fish_product_id = fp.product_id
       AND h.remaining_quantity > 0
       AND h.status = 'active'
    GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg
    HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
    ORDER BY fp.fish_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Get user's pending/active orders to prevent double-ordering ───────────────
$activeOrderProducts = $db->prepare("
    SELECT DISTINCT oi.product_id
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    WHERE o.user_id = :uid AND o.order_status NOT IN ('cancelled', 'claimed')
");
$activeOrderProducts->execute([':uid' => $userId]);
$orderedProductIds = array_column($activeOrderProducts->fetchAll(PDO::FETCH_ASSOC), 'product_id');

// ── User info ─────────────────────────────────────────────────────────────────
$userStmt = $db->prepare("SELECT full_name, department, position FROM users WHERE user_id = :uid");
$userStmt->execute([':uid' => $userId]);
$userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

// Helper function to get image from assets/images folder
function getFishImage($fishName) {
    // Clean fish name for filename (lowercase, remove special chars)
    $cleanName = strtolower(trim($fishName));
    $cleanName = preg_replace('/[^a-z]/', '', $cleanName);
    
    // Map fish names to image files in assets/images/
    $imageMap = [
        'bangus' => 'bangus-default.jpg',
        'tilapia' => 'tilapia.jpg',
        'galunggong' => 'galunggong.jpg',
        'shrimp' => 'shrimp-default.jpg',
        'gg' => 'galunggong.jpg',
        'tanigue' => 'tanigue.jpg',
        'maya' => 'maya-maya.jpg',
        'mayamaya' => 'maya-maya.jpg',
        'lapulapu' => 'lapu-lapu.jpg',
        'sugpo' => 'sugpo.jpg',
        'hipon' => 'sugpo.jpg',
        'alimasag' => 'alimasag.jpg',
        'blue' => 'blue-marlin.jpg',
        'marlin' => 'blue-marlin.jpg',
        'tulingan' => 'tulingan.jpg',
        'salmon' => 'salmon.jpg',
        'tuna' => 'tuna.jpg',
        'bonito' => 'tuna.jpg',
        'sapsap' => 'sapsap.jpg',
        'dalagang' => 'dalagang-bukid.jpg',
        'dalag' => 'dalag.jpg',
        'hito' => 'hito.jpg',
        'pampano' => 'pompano.jpg',
        'pompano' => 'pompano.jpg',
    ];
    
    // Check if we have a mapped image
    foreach ($imageMap as $key => $filename) {
        if (strpos($cleanName, $key) !== false) {
            $imagePath = '../assets/images/fish/' . $filename;
            if (file_exists($imagePath)) {
                return $imagePath;
            }
        }
    }
    
    // Return default fish image
    return '../assets/images/default-fish.jpg';
}

// Create an array with image paths for each product
foreach ($products as &$product) {
    $product['image_path'] = getFishImage($product['fish_name']);
}

// Calculate stock percentages for each product
foreach ($products as &$product) {
    $avail = $product['available_quantity'];
    $product['stock_percentage'] = $avail > 0 ? min(100, ($avail / max($avail, 50)) * 100) : 0;
    $product['stock_color'] = $avail > 50 ? '#10b981' : ($avail > 20 ? '#f59e0b' : '#ef4444');
    $product['stock_text'] = $avail > 50 ? 'In Stock' : ($avail > 20 ? 'Limited' : 'Low Stock');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Fresh Fish - BISU IGE</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
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
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-8">
  
  <!-- Welcome Banner -->
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

  <!-- Alerts -->
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

  <?php if (empty($products)): ?>
  <div class="glass-card rounded-2xl p-12 text-center shadow-md">
    <i class="fas fa-fish text-gray-300 text-6xl mb-4"></i>
    <h3 class="text-xl font-medium text-gray-500">No fresh catch available</h3>
    <p class="text-gray-400 mt-2">New harvest batches will appear here soon.</p>
  </div>
  <?php else: ?>

  <form method="POST" id="orderForm">
    <input type="hidden" name="action" value="place_order">

    <!-- Product Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
      <?php foreach ($products as $p):
        $pid       = (int)   $p['product_id'];
        $avail     = (float) $p['available_quantity'];
        $price     = (float) $p['price_per_kg'];
        $already   = in_array($pid, $orderedProductIds);
        $stockPct  = $p['stock_percentage'];
        $stockColor = $p['stock_color'];
        $stockText = $p['stock_text'];
        $imageUrl = $p['image_path'];
      ?>
      <div class="product-card glass-card rounded-xl overflow-hidden shadow-md border border-white/50 <?= $already ? 'opacity-60' : '' ?>" id="card-<?= $pid ?>" onclick="selectCard(<?= $pid ?>, event)">
        <!-- Product Image -->
        <div class="image-container h-44 relative overflow-hidden">
          <img src="<?= htmlspecialchars($imageUrl) ?>" 
               alt="<?= htmlspecialchars($p['fish_name']) ?>" 
               class="w-full h-full object-cover transition-transform duration-300 hover:scale-105"
               onerror="this.src='../assets/images/default-fish.jpg'">
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
          <!-- Title & Price -->
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($p['fish_name']) ?></h3>
            <span class="bg-blue-50 text-blue-700 font-bold px-2 py-1 rounded-lg text-sm">₱<?= number_format($price, 2) ?><span class="text-xs font-normal text-gray-500">/kg</span></span>
          </div>
          
          <!-- Description -->
          <?php if ($p['description']): ?>
          <p class="text-xs text-gray-500 mb-3 line-clamp-2"><?= htmlspecialchars(mb_substr($p['description'],0,80)) ?></p>
          <?php endif; ?>
          
          <!-- Stock Indicator -->
          <div class="mb-3">
            <div class="flex justify-between text-xs mb-1">
              <span class="text-gray-500">Availability</span>
              <span class="<?= $avail > 20 ? 'text-green-600' : ($avail > 5 ? 'text-amber-600' : 'text-red-600') ?> font-medium">
                <?= $stockText ?>
              </span>
            </div>
            <div class="stock-bar">
              <div class="stock-fill" style="width:<?= $stockPct ?>%;background:<?= $stockColor ?>"></div>
            </div>
          </div>
          
          <!-- Quantity Controls -->
          <?php if ($already): ?>
          <div class="bg-gray-50 rounded-lg p-3 text-center text-sm text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>You already have an active order for this product.
          </div>
          <?php else: ?>
          <div class="flex items-center justify-between mt-2" onclick="event.stopPropagation()">
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

    <!-- Order Summary Card (Sticky on desktop) -->
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
            <button type="submit" id="submitBtn" disabled
              class="px-6 py-3 rounded-xl font-semibold transition-all duration-200
                     bg-gray-300 text-gray-500 cursor-not-allowed"
              onclick="return confirmOrder()">
              <i class="fas fa-check mr-2"></i>Place Order
            </button>
          </div>
        </div>
        
        <!-- Detailed items preview -->
        <div id="summaryList" class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-500 hidden md:block"></div>
      </div>
    </div>
    
    <!-- Remarks -->
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

function selectCard(pid, event) {
    // Don't trigger if already has pending order
    const card = document.getElementById('card-' + pid);
    if (card.classList.contains('opacity-60')) return;
    
    // Focus the quantity input
    const qtyInput = document.getElementById('qty-' + pid);
    if (qtyInput) {
        qtyInput.focus();
        if (parseFloat(qtyInput.value) === 0) {
            adjustQty(pid, 0.5);
        }
    }
}

function validateQty(input, max) {
    let val = parseFloat(input.value) || 0;
    if (val > max) {
        input.value = max;
        val = max;
    }
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
    let total    = 0;
    let itemCount = 0;
    let html     = '<div class="flex flex-wrap gap-3 justify-end">';

    for (const pid in prices) {
        const qty = parseFloat(document.getElementById('qty-' + pid)?.value) || 0;
        if (qty > 0) {
            const sub = qty * prices[pid];
            total += sub;
            itemCount++;
            html += `<div class="bg-gray-50 rounded-lg px-3 py-1 text-xs">
                <span class="font-medium">${names[pid]}</span> ${qty}kg <span class="text-blue-600">₱${sub.toFixed(2)}</span>
            </div>`;
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
        btn.disabled  = false;
        btn.className = 'px-6 py-3 rounded-xl font-semibold transition-all duration-200 bg-blue-600 hover:bg-blue-700 text-white shadow-md hover:shadow-lg cursor-pointer';
    } else {
        btn.disabled  = true;
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

// Trigger summary update on page load
document.addEventListener('DOMContentLoaded', function() {
    for (const pid in prices) {
        const inp = document.getElementById('qty-' + pid);
        if (inp && inp.value > 0) updateSubtotal(pid, prices[pid]);
    }
});
</script>
</body>
</html>