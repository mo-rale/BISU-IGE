<?php
/**
 * manager/inventory_report.php
 * Inventory audit report — shows FIFO consumption trail per harvest batch.
 * New module for the harvest-based inventory schema.
 */

require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireManagerOrStaff();

$db = (new Database())->getConnection();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterProduct  = (int)($_GET['product_id'] ?? 0);
$filterDateFrom = $_GET['date_from'] ?? date('Y-m-01');
$filterDateTo   = $_GET['date_to']   ?? date('Y-m-d');

// ── Fish products dropdown ────────────────────────────────────────────────────
$products = $db->query("SELECT product_id, fish_name FROM fish_products ORDER BY fish_name")->fetchAll(PDO::FETCH_ASSOC);

// ── Stock summary (per product) ───────────────────────────────────────────────
$stockSummary = $db->query("
    SELECT
        fp.product_id,
        fp.fish_name,
        fp.price_per_kg,
        COALESCE(SUM(h.total_quantity), 0)     AS total_harvested_kg,
        COALESCE(SUM(h.remaining_quantity), 0) AS total_remaining_kg,
        COALESCE(SUM(h.total_quantity) - SUM(h.remaining_quantity), 0) AS total_consumed_kg,
        COUNT(DISTINCT h.harvest_id)            AS batch_count,
        COUNT(DISTINCT CASE WHEN h.status='depleted' THEN h.harvest_id END) AS depleted_batches
    FROM fish_products fp
    LEFT JOIN harvest h ON h.fish_product_id = fp.product_id
    GROUP BY fp.product_id, fp.fish_name, fp.price_per_kg
    ORDER BY fp.fish_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── FIFO consumption log ──────────────────────────────────────────────────────
$logSql = "
    SELECT
        hc.id AS consumption_id,
        hc.created_at AS consumed_at,
        hc.quantity_used,
        h.harvest_id,
        h.batch_no,
        h.harvest_date,
        h.location,
        fp.fish_name,
        fp.price_per_kg,
        (hc.quantity_used * fp.price_per_kg) AS value_consumed,
        oi.order_item_id,
        oi.quantity AS item_qty_ordered,
        o.order_id,
        o.order_status,
        o.order_date,
        u.full_name AS employee_name,
        u.employee_id,
        u.department
    FROM harvest_consumption hc
    JOIN harvest h       ON h.harvest_id       = hc.harvest_id
    JOIN fish_products fp ON fp.product_id     = h.fish_product_id
    JOIN order_items oi  ON oi.order_item_id   = hc.order_item_id
    JOIN orders o        ON o.order_id         = oi.order_id
    JOIN users u         ON u.user_id          = o.user_id
    WHERE DATE(hc.created_at) BETWEEN :df AND :dt
";


if ($filterProduct) {
    $logSql .= " AND h.fish_product_id = :pid ";
}

$logSql .= " ORDER BY hc.created_at DESC, hc.id DESC";

$logStmt = $db->prepare($logSql);
$consumptionLog = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Harvest batch status table ────────────────────────────────────────────────
$batchSql = "
    SELECT
        h.harvest_id,
        h.batch_no,
        h.harvest_date,
        h.location,
        h.total_quantity,
        h.remaining_quantity,
        (h.total_quantity - h.remaining_quantity) AS consumed,
        h.status,
        fp.fish_name,
        fp.price_per_kg,
        COALESCE(SUM(hc.quantity_used), 0) AS audit_consumed
    FROM harvest h
    JOIN fish_products fp ON fp.product_id = h.fish_product_id
    LEFT JOIN harvest_consumption hc ON hc.harvest_id = h.harvest_id
";
$batchParams = [];
if ($filterProduct) {
    $batchSql .= " WHERE h.fish_product_id = :pid ";
    $batchParams[':pid'] = $filterProduct;
}
$batchSql .= " GROUP BY h.harvest_id, fp.fish_name, fp.price_per_kg ORDER BY h.created_at ASC";
$bStmt = $db->prepare($batchSql);
$batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary totals ────────────────────────────────────────────────────────────
$totalConsumedKg    = array_sum(array_column($consumptionLog, 'quantity_used'));
$totalConsumedValue = array_sum(array_column($consumptionLog, 'value_consumed'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Report – BISU IGE</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:#f1f5f9;font-family:'Inter',system-ui,sans-serif;}
.card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid #e2e8f0;}
th{text-align:left;font-size:.7rem;font-weight:700;color:#6b7280;text-transform:uppercase;padding:.65rem .875rem;border-bottom:1px solid #e5e7eb;white-space:nowrap;}
td{padding:.65rem .875rem;font-size:.8125rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border:none;}
tr:hover td{background:#f8fafc;}
input,select{border:1px solid #d1d5db;border-radius:.5rem;padding:.4rem .75rem;font-size:.875rem;}
input:focus,select:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.1);}
.badge{display:inline-flex;align-items:center;padding:.15rem .5rem;border-radius:9999px;font-size:.65rem;font-weight:600;}
.badge-active{background:#dcfce7;color:#15803d;}
.badge-depleted{background:#fee2e2;color:#b91c1c;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-confirmed{background:#dbeafe;color:#1d4ed8;}
.badge-cancelled{background:#f1f5f9;color:#64748b;}
@media print{
  .no-print{display:none!important;}
  body{background:#fff;}
  .card{box-shadow:none;border:1px solid #ddd;}
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-chart-bar text-indigo-500 mr-2"></i>Inventory Report</h1>
      <p class="text-sm text-gray-500 mt-1">FIFO consumption audit trail and harvest batch status.</p>
    </div>
    <button onclick="window.print()" class="no-print bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition">
      <i class="fas fa-print"></i> Print / Export
    </button>
  </div>

  <!-- Filters -->
  <div class="card p-4 mb-6 no-print">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
      <div>
        <label class="text-xs text-gray-500 block mb-1">Fish Product</label>
        <select name="product_id" onchange="this.form.submit()" class="w-44">
          <option value="0">All Products</option>
          <?php foreach ($products as $p): ?>
          <option value="<?= $p['product_id'] ?>" <?= $filterProduct===$p['product_id']?'selected':'' ?>><?= htmlspecialchars($p['fish_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Date From</label>
        <input type="date" name="date_from" value="<?= $filterDateFrom ?>">
      </div>
      <div>
        <label class="text-xs text-gray-500 block mb-1">Date To</label>
        <input type="date" name="date_to" value="<?= $filterDateTo ?>">
      </div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition">Apply</button>
      <a href="inventory_report.php" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-sm hover:bg-gray-200">Reset</a>
    </form>
  </div>

  <!-- KPI cards -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php
    $totalHarvested = array_sum(array_column($stockSummary, 'total_harvested_kg'));
    $totalRemaining = array_sum(array_column($stockSummary, 'total_remaining_kg'));
    $totalConsumedAll = array_sum(array_column($stockSummary, 'total_consumed_kg'));
    $totalBatches   = array_sum(array_column($stockSummary, 'batch_count'));
    $kpis = [
      ['Total Harvested', number_format($totalHarvested, 2).' kg', 'fa-weight-hanging', 'text-blue-600',   'bg-blue-50'],
      ['Total Remaining', number_format($totalRemaining, 2).' kg', 'fa-box-open',       'text-green-600',  'bg-green-50'],
      ['Total Consumed',  number_format($totalConsumedAll,2).' kg','fa-fire',           'text-orange-600', 'bg-orange-50'],
      ['Harvest Batches', $totalBatches.' batches',                'fa-layer-group',    'text-purple-600', 'bg-purple-50'],
    ];
    foreach ($kpis as [$label, $value, $icon, $tColor, $bgColor]):
    ?>
    <div class="card p-4 flex items-center gap-4">
      <div class="w-10 h-10 rounded-xl <?= $bgColor ?> flex items-center justify-center shrink-0">
        <i class="fas <?= $icon ?> <?= $tColor ?>"></i>
      </div>
      <div>
        <p class="text-xs text-gray-500"><?= $label ?></p>
        <p class="font-bold text-gray-800 text-base"><?= $value ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Per-product stock summary -->
  <div class="card overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-100">
      <h2 class="font-semibold text-gray-800"><i class="fas fa-fish mr-2 text-blue-400"></i>Stock Summary by Product</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr>
            <th>Fish Product</th>
            <th>Price / kg</th>
            <th>Total Harvested</th>
            <th>Total Consumed</th>
            <th>Remaining</th>
            <th>Batches</th>
            <th>% Used</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($stockSummary)): ?>
          <tr><td colspan="7" class="text-center py-6 text-gray-400">No data.</td></tr>
          <?php else: ?>
          <?php foreach ($stockSummary as $s):
            $total    = (float)$s['total_harvested_kg'];
            $consumed = (float)$s['total_consumed_kg'];
            $pct      = $total > 0 ? round(($consumed / $total) * 100) : 0;
            $barColor = $pct < 50 ? 'bg-green-500' : ($pct < 80 ? 'bg-yellow-500' : 'bg-red-500');
          ?>
          <tr>
            <td class="font-medium text-gray-800"><?= htmlspecialchars($s['fish_name']) ?></td>
            <td>₱<?= number_format((float)$s['price_per_kg'], 2) ?></td>
            <td><?= number_format((float)$s['total_harvested_kg'], 2) ?> kg</td>
            <td class="text-orange-600"><?= number_format((float)$s['total_consumed_kg'], 2) ?> kg</td>
            <td class="font-semibold <?= (float)$s['total_remaining_kg'] <= 0 ? 'text-red-500' : 'text-gray-800' ?>">
              <?= number_format((float)$s['total_remaining_kg'], 2) ?> kg
            </td>
            <td><?= (int)$s['batch_count'] ?> (<?= (int)$s['depleted_batches'] ?> depleted)</td>
            <td>
              <div class="flex items-center gap-2">
                <div class="w-20 bg-gray-200 rounded-full h-1.5"><div class="h-1.5 rounded-full <?= $barColor ?>" style="width:<?= $pct ?>%"></div></div>
                <span class="text-xs text-gray-600"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Harvest batch detail -->
  <div class="card overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-100">
      <h2 class="font-semibold text-gray-800"><i class="fas fa-layer-group mr-2 text-green-400"></i>Harvest Batch Status</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr>
            <th>Batch No.</th>
            <th>Fish</th>
            <th>Harvest Date</th>
            <th>Location</th>
            <th>Total (kg)</th>
            <th>Consumed (kg)</th>
            <th>Remaining (kg)</th>
            <th>Audit Consumed</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($batches)): ?>
          <tr><td colspan="9" class="text-center py-6 text-gray-400">No harvest batches.</td></tr>
          <?php else: ?>
          <?php foreach ($batches as $b):
            $calcConsumed  = (float)$b['consumed'];
            $auditConsumed = (float)$b['audit_consumed'];
            $mismatch      = abs($calcConsumed - $auditConsumed) > 0.001;
          ?>
          <tr>
            <td><span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($b['batch_no']) ?></span></td>
            <td class="font-medium"><?= htmlspecialchars($b['fish_name']) ?></td>
            <td><?= date('M d, Y', strtotime($b['harvest_date'])) ?></td>
            <td class="text-gray-500"><?= htmlspecialchars($b['location'] ?? '—') ?></td>
            <td><?= number_format((float)$b['total_quantity'], 2) ?></td>
            <td class="text-orange-600"><?= number_format($calcConsumed, 2) ?></td>
            <td class="font-semibold <?= (float)$b['remaining_quantity'] <= 0 ? 'text-red-500' : 'text-gray-800' ?>">
              <?= number_format((float)$b['remaining_quantity'], 2) ?>
            </td>
            <td class="<?= $mismatch ? 'text-red-600 font-bold' : 'text-gray-600' ?>">
              <?= number_format($auditConsumed, 2) ?>
              <?php if ($mismatch): ?><i class="fas fa-exclamation-triangle ml-1" title="Audit mismatch!"></i><?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $b['status']==='active' ? 'badge-active' : 'badge-depleted' ?>">
                <?= ucfirst($b['status']) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- FIFO consumption log -->
  <div class="card overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="font-semibold text-gray-800"><i class="fas fa-history mr-2 text-indigo-400"></i>FIFO Consumption Log</h2>
      <span class="text-sm text-gray-500">
        <?= count($consumptionLog) ?> records ·
        <?= number_format($totalConsumedKg, 2) ?> kg ·
        ₱<?= number_format($totalConsumedValue, 2) ?>
      </span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr>
            <th>Date</th>
            <th>Fish</th>
            <th>Batch</th>
            <th>Qty Used (kg)</th>
            <th>Value</th>
            <th>Order #</th>
            <th>Status</th>
            <th>Employee</th>
            <th>Dept.</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($consumptionLog)): ?>
          <tr><td colspan="9" class="text-center py-6 text-gray-400">No consumption records for the selected period.</td></tr>
          <?php else: ?>
          <?php foreach ($consumptionLog as $log): ?>
          <tr>
            <td class="text-gray-500 whitespace-nowrap"><?= date('M d Y H:i', strtotime($log['consumed_at'])) ?></td>
            <td class="font-medium"><?= htmlspecialchars($log['fish_name']) ?></td>
            <td><span class="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded"><?= htmlspecialchars($log['batch_no']) ?></span></td>
            <td class="font-semibold text-orange-600"><?= number_format((float)$log['quantity_used'], 3) ?></td>
            <td class="text-green-700">₱<?= number_format((float)$log['value_consumed'], 2) ?></td>
            <td>
              <a href="orders.php?order_id=<?= $log['order_id'] ?>" class="text-blue-500 hover:underline font-medium">#<?= $log['order_id'] ?></a>
            </td>
            <td>
              <span class="badge badge-<?= $log['order_status'] ?>"><?= ucfirst($log['order_status']) ?></span>
            </td>
            <td><?= htmlspecialchars($log['employee_name']) ?></td>
            <td class="text-gray-400 text-xs"><?= htmlspecialchars($log['department'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="text-center text-xs text-gray-400 mt-6">
    Report generated: <?= date('F d, Y h:i A') ?> · BISU IGE Aquaculture Management System
  </div>

</div>
</body>
</html>
