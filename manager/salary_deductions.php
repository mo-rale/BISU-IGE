<?php
/**
 * manager/deductions.php
 * Salary deduction management.
 * Allows managers to record payments, view deduction history, and close deductions.
 * Updated for new schema.
 */

require_once '../includes/config.php';
require_once '../includes/session.php';

SessionManager::requireManagerOrStaff();

$db      = (new Database())->getConnection();
$success = '';
$errors  = [];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']       ?? '';
    $deductionId = (int)($_POST['deduction_id'] ?? 0);

    // RECORD A PAYMENT
    if ($action === 'pay' && $deductionId) {
        $amount        = (float)($_POST['amount_paid']   ?? 0);
        $period        = trim($_POST['payroll_period']   ?? '');
        $remarks       = trim($_POST['remarks']          ?? '');
        $deductionDate = trim($_POST['deduction_date']   ?? date('Y-m-d'));

        if ($amount <= 0)    $errors[] = 'Payment amount must be greater than 0.';
        if (empty($period))  $errors[] = 'Payroll period is required.';

        if (empty($errors)) {
            // Fetch current deduction
            $dStmt = $db->prepare("SELECT * FROM salary_deductions WHERE deduction_id = :id");
            $dStmt->execute([':id' => $deductionId]);
            $ded = $dStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ded) {
                $errors[] = 'Deduction record not found.';
            } else {
                $newPaid    = (float)$ded['amount_paid'] + $amount;
                $newBalance = max(0, (float)$ded['total_amount'] - $newPaid);
                $newStatus  = $newBalance <= 0 ? 'completed' : 'active';
                $completedAt = $newBalance <= 0 ? 'CURRENT_TIMESTAMP' : 'NULL';

                $db->beginTransaction();
                try {
                    $db->prepare("
                        UPDATE salary_deductions
                        SET amount_paid       = :paid,
                            remaining_balance = :balance,
                            deduction_status  = :status,
                            completed_at      = " . ($newBalance <= 0 ? 'CURRENT_TIMESTAMP' : 'NULL') . ",
                            updated_at        = CURRENT_TIMESTAMP
                        WHERE deduction_id = :id
                    ")->execute([
                        ':paid'    => $newPaid,
                        ':balance' => $newBalance,
                        ':status'  => $newStatus,
                        ':id'      => $deductionId,
                    ]);

                    // Log to deduction_history
                    $db->prepare("
                        INSERT INTO deduction_history
                            (deduction_id, amount_deducted, deduction_date, payroll_period, remarks)
                        VALUES
                            (:did, :amt, :date, :period, :remarks)
                    ")->execute([
                        ':did'     => $deductionId,
                        ':amt'     => $amount,
                        ':date'    => $deductionDate,
                        ':period'  => $period,
                        ':remarks' => $remarks ?: null,
                    ]);

                    // Notify user
                    $db->prepare("
                        INSERT INTO notifications (user_id, title, message, type)
                        VALUES (:uid, 'Salary Deduction Recorded', :msg, 'deduction')
                    ")->execute([
                        ':uid' => $ded['user_id'],
                        ':msg' => "A salary deduction of ₱" . number_format($amount, 2) . " has been recorded for payroll period: {$period}." .
                                  ($newBalance <= 0 ? ' Your balance is fully settled!' : " Remaining balance: ₱" . number_format($newBalance, 2)),
                    ]);

                    $db->commit();
                    $success = "Payment of ₱" . number_format($amount, 2) . " recorded." .
                               ($newBalance <= 0 ? ' Deduction fully settled!' : " Remaining: ₱" . number_format($newBalance, 2));
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to record payment: ' . $e->getMessage();
                }
            }
        }
    }

    // WAIVE remaining balance
    if ($action === 'waive' && $deductionId) {
        $remarks = trim($_POST['waive_remarks'] ?? '');
        $dStmt   = $db->prepare("SELECT * FROM salary_deductions WHERE deduction_id = :id");
        $dStmt->execute([':id' => $deductionId]);
        $ded = $dStmt->fetch(PDO::FETCH_ASSOC);

        if ($ded && (float)$ded['remaining_balance'] > 0) {
            $db->prepare("
                UPDATE salary_deductions
                SET amount_paid       = total_amount,
                    remaining_balance = 0,
                    deduction_status  = 'completed',
                    completed_at      = CURRENT_TIMESTAMP,
                    remarks           = :remarks,
                    updated_at        = CURRENT_TIMESTAMP
                WHERE deduction_id = :id
            ")->execute([':remarks' => $remarks ?: null, ':id' => $deductionId]);

            $db->prepare("
                INSERT INTO deduction_history
                    (deduction_id, amount_deducted, deduction_date, payroll_period, remarks)
                VALUES (:did, :amt, CURRENT_DATE, 'Waived', :rem)
            ")->execute([
                ':did' => $deductionId,
                ':amt' => (float)$ded['remaining_balance'],
                ':rem' => 'Balance waived by manager. ' . $remarks,
            ]);

            $db->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:uid, 'Balance Waived', :msg, 'deduction')
            ")->execute([
                ':uid' => $ded['user_id'],
                ':msg' => "Your remaining salary deduction balance of ₱" . number_format($ded['remaining_balance'], 2) . " has been waived.",
            ]);

            $success = 'Remaining balance waived and deduction marked complete.';
        } else {
            $errors[] = 'Deduction not found or already settled.';
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']     ?? 'active';
$filterSearch = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterStatus !== 'all') {
    $where[]           = "sd.deduction_status = :status";
    $params[':status'] = $filterStatus;
}
if ($filterSearch) {
    $where[]      = "(u.full_name ILIKE :s OR u.employee_id ILIKE :s)";
    $params[':s'] = "%{$filterSearch}%";
}
$whereClause = implode(' AND ', $where);

// Count
$cStmt = $db->prepare("
    SELECT COUNT(*) FROM salary_deductions sd
    JOIN users u ON u.user_id = sd.user_id
    WHERE {$whereClause}
");
$cStmt->execute($params);
$totalRecords = (int)$cStmt->fetchColumn();
$totalPages   = (int)ceil($totalRecords / $perPage);

// Fetch
$dStmt = $db->prepare("
    SELECT
        sd.*,
        u.full_name, u.employee_id, u.department, u.position,
        o.order_date, o.order_status
    FROM salary_deductions sd
    JOIN users  u ON u.user_id  = sd.user_id
    JOIN orders o ON o.order_id = sd.order_id
    WHERE {$whereClause}
    ORDER BY sd.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) $dStmt->bindValue($k, $v);
$dStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$dStmt->execute();
$deductions = $dStmt->fetchAll(PDO::FETCH_ASSOC);

// Tab counts
$tabStmt = $db->query("SELECT deduction_status, COUNT(*) AS cnt FROM salary_deductions GROUP BY deduction_status");
$tabCounts = ['all' => 0];
foreach ($tabStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tabCounts[$r['deduction_status']] = (int)$r['cnt'];
    $tabCounts['all'] += (int)$r['cnt'];
}

// Summary totals
$totals = $db->query("
    SELECT
        COALESCE(SUM(total_amount), 0)     AS total_billed,
        COALESCE(SUM(amount_paid), 0)      AS total_collected,
        COALESCE(SUM(remaining_balance), 0) AS total_outstanding
    FROM salary_deductions
    WHERE deduction_status NOT IN ('cancelled')
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Deductions – Manager | BISU IGE</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:#f1f5f9;font-family:'Inter',system-ui,sans-serif;}
.card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid #e2e8f0;}
th{text-align:left;font-size:.7rem;font-weight:700;color:#6b7280;text-transform:uppercase;padding:.65rem 1rem;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
td{padding:.75rem 1rem;font-size:.8125rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border:none;}
tr:hover td{background:#f8fafc;}
.badge{display:inline-flex;padding:.2rem .6rem;border-radius:9999px;font-size:.68rem;font-weight:600;}
.badge-pending  {background:#fef9c3;color:#854d0e;}
.badge-active   {background:#dbeafe;color:#1e40af;}
.badge-completed{background:#dcfce7;color:#166534;}
.badge-cancelled{background:#f1f5f9;color:#64748b;}
.tab-btn{padding:.45rem 1rem;border-radius:.5rem;font-size:.8rem;font-weight:500;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;white-space:nowrap;}
.tab-btn.active{background:#0ea5e9;color:#fff;border-color:#0ea5e9;}
input,select,textarea{border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .75rem;font-size:.875rem;}
input:focus,select:focus,textarea:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.1);}
.progress-bar{height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden;}
.progress-fill{height:100%;border-radius:3px;}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

  <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-money-bill-wave text-green-500 mr-2"></i>Salary Deductions</h1>
      <p class="text-sm text-gray-500 mt-1">Track and record employee salary deduction payments.</p>
    </div>
  </div>

  <!-- Alerts -->
  <?php if (!empty($errors)): ?>
  <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
    <?php foreach ($errors as $e): ?><p><i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-700">
    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
  </div>
  <?php endif; ?>

  <!-- Summary KPIs -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <?php
    $kpis = [
      ['Total Billed',       '₱'.number_format((float)$totals['total_billed'],2),       'fa-receipt',         'text-blue-600',  'bg-blue-50'],
      ['Total Collected',    '₱'.number_format((float)$totals['total_collected'],2),    'fa-check-circle',    'text-green-600', 'bg-green-50'],
      ['Outstanding Balance','₱'.number_format((float)$totals['total_outstanding'],2),  'fa-exclamation-circle','text-red-600','bg-red-50'],
    ];
    foreach ($kpis as [$label, $val, $icon, $tc, $bg]):
    ?>
    <div class="card p-5 flex items-center gap-4">
      <div class="w-11 h-11 rounded-xl <?= $bg ?> flex items-center justify-center shrink-0">
        <i class="fas <?= $icon ?> <?= $tc ?>"></i>
      </div>
      <div>
        <p class="text-xs text-gray-500"><?= $label ?></p>
        <p class="font-bold text-gray-800 text-lg"><?= $val ?></p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Tabs -->
  <div class="flex gap-2 flex-wrap mb-5">
    <?php foreach (['all'=>'All','pending'=>'Pending','active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled'] as $val => $label): ?>
    <a href="?status=<?= $val ?>&search=<?= urlencode($filterSearch) ?>"
      class="tab-btn <?= $filterStatus===$val?'active':'' ?>">
      <?= $label ?> <span class="opacity-70 ml-1">(<?= $tabCounts[$val] ?? 0 ?>)</span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <div class="card p-4 mb-5">
    <form method="GET" class="flex gap-3">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <div class="flex-1 relative">
        <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>"
          placeholder="Search by employee name or ID…" class="pl-9 w-full">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition">Search</button>
      <?php if ($filterSearch): ?>
      <a href="?status=<?= $filterStatus ?>" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-sm flex items-center hover:bg-gray-200">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Deductions table -->
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Order</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Started</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($deductions)): ?>
          <tr><td colspan="9" class="text-center py-10 text-gray-400">No deductions found.</td></tr>
          <?php else: ?>
          <?php foreach ($deductions as $d):
            $total   = (float)$d['total_amount'];
            $paid    = (float)$d['amount_paid'];
            $balance = (float)$d['remaining_balance'];
            $pct     = $total > 0 ? round(($paid / $total) * 100) : 0;
            $barColor = $pct >= 100 ? 'bg-green-500' : ($pct >= 50 ? 'bg-blue-400' : 'bg-yellow-400');
          ?>
          <tr>
            <td>
              <div class="font-medium text-gray-800"><?= htmlspecialchars($d['full_name']) ?></div>
              <div class="text-xs text-gray-400"><?= htmlspecialchars($d['employee_id'] ?? '—') ?> · <?= htmlspecialchars($d['department'] ?? '') ?></div>
            </td>
            <td>
              <a href="orders.php?search=<?= $d['order_id'] ?>" class="text-blue-500 hover:underline font-medium">#<?= $d['order_id'] ?></a>
            </td>
            <td class="font-semibold text-gray-800">₱<?= number_format($total, 2) ?></td>
            <td class="text-green-700 font-medium">₱<?= number_format($paid, 2) ?></td>
            <td class="<?= $balance > 0 ? 'text-red-500 font-semibold' : 'text-gray-300' ?>">
              <?= $balance > 0 ? '₱'.number_format($balance,2) : '—' ?>
            </td>
            <td class="w-28">
              <div class="progress-bar">
                <div class="progress-fill <?= $barColor ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <div class="text-xs text-gray-400 mt-0.5"><?= $pct ?>%</div>
            </td>
            <td><span class="badge badge-<?= $d['deduction_status'] ?>"><?= ucfirst($d['deduction_status']) ?></span></td>
            <td class="text-xs text-gray-400 whitespace-nowrap">
              <?= $d['deduction_start_date'] ? date('M d, Y', strtotime($d['deduction_start_date'])) : '—' ?>
            </td>
            <td>
              <div class="flex gap-2 flex-wrap">
                <?php if (in_array($d['deduction_status'], ['pending','active'])): ?>
                <button onclick="openPay(<?= htmlspecialchars(json_encode($d)) ?>)"
                  class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                  <i class="fas fa-plus mr-0.5"></i>Pay
                </button>
                <button onclick="openWaive(<?= $d['deduction_id'] ?>)"
                  class="text-xs text-orange-500 hover:text-orange-700 font-medium">
                  <i class="fas fa-hand-holding-usd mr-0.5"></i>Waive
                </button>
                <?php endif; ?>
                <button onclick="viewHistory(<?= $d['deduction_id'] ?>)"
                  class="text-xs text-gray-500 hover:text-gray-700 font-medium">
                  <i class="fas fa-history mr-0.5"></i>History
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="mt-6 flex justify-center gap-2 flex-wrap">
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"
      class="px-3 py-1.5 rounded-lg text-sm border <?= $i===$page?'bg-blue-600 text-white border-blue-600':'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
      <?= $i ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Pay Modal -->
<div id="payModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md">
    <div class="p-6 border-b flex items-center justify-between">
      <h2 class="font-semibold text-gray-800"><i class="fas fa-money-bill-wave text-green-500 mr-2"></i>Record Payment</h2>
      <button onclick="document.getElementById('payModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-6 space-y-4">
      <input type="hidden" name="action"       value="pay">
      <input type="hidden" name="deduction_id" id="pay_deduction_id">
      <div id="payInfo" class="p-3 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-800"></div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-sm font-medium text-gray-700 block mb-1">Amount (₱) <span class="text-red-500">*</span></label>
          <input type="number" name="amount_paid" id="pay_amount" step="0.01" min="0.01" placeholder="0.00" required>
        </div>
        <div>
          <label class="text-sm font-medium text-gray-700 block mb-1">Deduction Date <span class="text-red-500">*</span></label>
          <input type="date" name="deduction_date" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div>
        <label class="text-sm font-medium text-gray-700 block mb-1">Payroll Period <span class="text-red-500">*</span></label>
        <input type="text" name="payroll_period" placeholder="e.g. May 2026 – 1st Cutoff" required>
      </div>
      <div>
        <label class="text-sm font-medium text-gray-700 block mb-1">Remarks</label>
        <input type="text" name="remarks" placeholder="Optional note…">
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg text-sm font-medium transition">
          <i class="fas fa-save mr-1"></i>Record Payment
        </button>
        <button type="button" onclick="document.getElementById('payModal').classList.add('hidden')"
          class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 px-4 rounded-lg text-sm font-medium transition">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Waive Modal -->
<div id="waiveModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md">
    <div class="p-6 border-b flex items-center justify-between">
      <h2 class="font-semibold text-orange-600"><i class="fas fa-hand-holding-usd mr-2"></i>Waive Balance</h2>
      <button onclick="document.getElementById('waiveModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-6 space-y-4">
      <input type="hidden" name="action"       value="waive">
      <input type="hidden" name="deduction_id" id="waive_deduction_id">
      <p class="text-sm text-gray-600">This will waive the remaining balance and mark the deduction as completed.</p>
      <div>
        <label class="text-sm font-medium text-gray-700 block mb-1">Reason for Waiver</label>
        <textarea name="waive_remarks" rows="2" placeholder="Reason (optional)…"></textarea>
      </div>
      <div class="flex gap-3">
        <button type="submit" class="flex-1 bg-orange-500 hover:bg-orange-600 text-white py-2 px-4 rounded-lg text-sm font-medium transition">
          Confirm Waiver
        </button>
        <button type="button" onclick="document.getElementById('waiveModal').classList.add('hidden')"
          class="flex-1 bg-gray-100 text-gray-700 hover:bg-gray-200 py-2 px-4 rounded-lg text-sm font-medium transition">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
    <div class="p-6 border-b flex items-center justify-between">
      <h2 class="font-semibold text-gray-800"><i class="fas fa-history mr-2 text-blue-500"></i>Payment History</h2>
      <button onclick="document.getElementById('historyModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <div id="historyContent" class="p-6 text-sm max-h-96 overflow-y-auto"></div>
  </div>
</div>

<script>
function openPay(d) {
  document.getElementById('pay_deduction_id').value = d.deduction_id;
  document.getElementById('pay_amount').max         = d.remaining_balance;
  document.getElementById('payInfo').innerHTML      =
    `<strong>${d.full_name}</strong> — Balance: <strong>₱${parseFloat(d.remaining_balance).toFixed(2)}</strong> of ₱${parseFloat(d.total_amount).toFixed(2)}`;
  document.getElementById('payModal').classList.remove('hidden');
}

function openWaive(id) {
  document.getElementById('waive_deduction_id').value = id;
  document.getElementById('waiveModal').classList.remove('hidden');
}

function viewHistory(id) {
  document.getElementById('historyContent').innerHTML =
    '<div class="text-center py-6 text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Loading…</div>';
  document.getElementById('historyModal').classList.remove('hidden');

  fetch('ajax/get_deduction_history.php?deduction_id=' + id)
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.history.length) {
        document.getElementById('historyContent').innerHTML = '<p class="text-gray-400 text-center py-4">No payment history yet.</p>';
        return;
      }
      let html = '<div class="space-y-3">';
      data.history.forEach(h => {
        html += `<div class="flex justify-between items-start p-3 bg-gray-50 rounded-xl">
          <div>
            <div class="font-medium text-gray-800">₱${parseFloat(h.amount_deducted).toFixed(2)}</div>
            <div class="text-xs text-gray-400">${h.payroll_period} · ${h.deduction_date}</div>
            ${h.remarks ? `<div class="text-xs text-gray-400 italic">${h.remarks}</div>` : ''}
          </div>
          <i class="fas fa-check-circle text-green-400"></i>
        </div>`;
      });
      html += `<div class="text-xs text-right text-gray-400 pt-2">Total paid: ₱${data.total_paid}</div></div>`;
      document.getElementById('historyContent').innerHTML = html;
    })
    .catch(() => {
      document.getElementById('historyContent').innerHTML = '<p class="text-red-400 text-center py-4">Failed to load history.</p>';
    });
}
</script>
</body>
</html>
