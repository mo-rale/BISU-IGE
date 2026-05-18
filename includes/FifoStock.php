<?php
/**
 * FifoStock.php
 * Handles FIFO-based inventory deduction from harvest batches.
 * Place in: includes/FifoStock.php
 */

class FifoStock
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get total available stock for a fish product (across all active harvest batches).
     */
    public function getAvailableStock(int $productId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(remaining_quantity), 0) AS total
            FROM harvest
            WHERE fish_product_id = :product_id
              AND status != 'depleted'
              AND remaining_quantity > 0
        ");
        $stmt->execute([':product_id' => $productId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Deduct stock using FIFO (oldest harvest batch first).
     * Inserts audit records into harvest_consumption.
     *
     * @param int   $productId    fish_products.product_id
     * @param int   $orderItemId  order_items.order_item_id
     * @param float $quantityKg   how many kg to deduct
     *
     * @return array ['success' => bool, 'message' => string, 'batches_used' => array]
     */
    public function deductStock(int $productId, int $orderItemId, float $quantityKg): array
    {
        // 1. Check total available stock first
        $available = $this->getAvailableStock($productId);
        if ($available < $quantityKg) {
            return [
                'success'      => false,
                'message'      => "Insufficient stock. Available: {$available} kg, Requested: {$quantityKg} kg.",
                'batches_used' => [],
            ];
        }

        // 2. Fetch harvest batches FIFO (oldest harvest_date first)
        $stmt = $this->db->prepare("
            SELECT harvest_id, batch_no, remaining_quantity
            FROM harvest
            WHERE fish_product_id = :product_id
              AND status != 'depleted'
              AND remaining_quantity > 0
            ORDER BY harvest_date ASC, harvest_id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $remaining = $quantityKg;
        $batchesUsed = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $harvestId     = (int)   $batch['harvest_id'];
            $batchStock    = (float) $batch['remaining_quantity'];
            $deductFromBatch = min($remaining, $batchStock);
            $newBatchStock   = $batchStock - $deductFromBatch;

            // 3. Update harvest remaining_quantity
            $updateStatus = $newBatchStock <= 0 ? 'depleted' : 'active';
            $upd = $this->db->prepare("
                UPDATE harvest
                SET remaining_quantity = :qty,
                    status             = :status,
                    updated_at         = CURRENT_TIMESTAMP
                WHERE harvest_id = :id
            ");
            $upd->execute([
                ':qty'    => $newBatchStock,
                ':status' => $updateStatus,
                ':id'     => $harvestId,
            ]);

            // 4. Log consumption for audit trail
            $ins = $this->db->prepare("
                INSERT INTO harvest_consumption (harvest_id, order_item_id, quantity_used)
                VALUES (:harvest_id, :order_item_id, :qty_used)
            ");
            $ins->execute([
                ':harvest_id'    => $harvestId,
                ':order_item_id' => $orderItemId,
                ':qty_used'      => $deductFromBatch,
            ]);

            $batchesUsed[] = [
                'harvest_id'   => $harvestId,
                'batch_no'     => $batch['batch_no'],
                'qty_deducted' => $deductFromBatch,
            ];

            $remaining -= $deductFromBatch;
        }

        return [
            'success'      => true,
            'message'      => 'Stock deducted successfully.',
            'batches_used' => $batchesUsed,
        ];
    }

    /**
     * Reverse a stock deduction (e.g. on order cancellation or return).
     * Adds stock back to the SAME harvest batches originally consumed.
     *
     * @param int $orderItemId  order_items.order_item_id
     */
    public function reverseDeduction(int $orderItemId): bool
    {
        // Find all consumption records for this order item
        $stmt = $this->db->prepare("
            SELECT harvest_id, quantity_used
            FROM harvest_consumption
            WHERE order_item_id = :order_item_id
        ");
        $stmt->execute([':order_item_id' => $orderItemId]);
        $consumptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($consumptions as $c) {
            $this->db->prepare("
                UPDATE harvest
                SET remaining_quantity = remaining_quantity + :qty,
                    status             = CASE WHEN status = 'depleted' THEN 'active' ELSE status END,
                    updated_at         = CURRENT_TIMESTAMP
                WHERE harvest_id = :id
            ")->execute([':qty' => $c['quantity_used'], ':id' => $c['harvest_id']]);
        }

        // Remove the consumption log entries
        $this->db->prepare("
            DELETE FROM harvest_consumption WHERE order_item_id = :order_item_id
        ")->execute([':order_item_id' => $orderItemId]);

        return true;
    }

    /**
     * Get all products that have available stock, with total available kg.
     * Use this to replace any query that used fish_products.available_quantity.
     */
    public function getProductsWithStock(?string $search = null): array
    {
        $sql = "
            SELECT
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
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " WHERE fp.fish_name ILIKE :search ";
            $params[':search'] = "%{$search}%";
        }

        $sql .= "
            GROUP BY fp.product_id, fp.fish_name, fp.description, fp.price_per_kg,
                     fp.created_at, fp.updated_at
            HAVING COALESCE(SUM(h.remaining_quantity), 0) > 0
            ORDER BY fp.fish_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
