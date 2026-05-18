<?php
// includes/functions.php - COMPLETE FINAL VERSION
class SystemFunctions {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    private function tableExists($table_name) {
        try {
            $sql = "SELECT to_regclass('public." . $table_name . "')";
            $stmt = $this->conn->query($sql);
            $result = $stmt->fetch();
            return $result && $result[0];
        } catch (Exception $e) {
            return false;
        }
    }
    
    // ============ USER FUNCTIONS ============
    
    public function getUserById($user_id) {
        try {
            // Your table uses 'user_id' as primary key
            $sql = "SELECT * FROM users WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Add backward compatibility fields
            if ($user) {
                $nameParts = explode(' ', $user['full_name'] ?? '', 2);
                $user['first_name'] = $nameParts[0] ?? '';
                $user['last_name'] = $nameParts[1] ?? '';
                $user['user_role'] = $user['role'] ?? 'standard';
                $user['contact_number'] = $user['contact_number'] ?? '';
            }
            return $user;
        } catch (Exception $e) {
            error_log("getUserById error: " . $e->getMessage());
            return null;
        }
    }
    
    public function createUser($data) {
        try {
            $sql = "INSERT INTO users (full_name, email, hashed_password, role, contact_number, department, position, employee_id, created_at, updated_at) 
                    VALUES (:full_name, :email, :hashed_password, :role, :contact_number, :department, :position, :employee_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) 
                    RETURNING user_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':full_name' => trim($data['full_name'] ?? ($data['first_name'] . ' ' . ($data['last_name'] ?? ''))),
                ':email' => $data['email'],
                ':hashed_password' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':role' => $data['role'] ?? 'standard',
                ':contact_number' => $data['contact_number'] ?? null,
                ':department' => $data['department'] ?? null,
                ':position' => $data['position'] ?? null,
                ':employee_id' => $data['employee_id'] ?? null
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && isset($result['user_id'])) {
                $this->createNotification(
                    $result['user_id'],
                    'system',
                    'Welcome to BISU IGE Aquaculture',
                    'Your account has been successfully created.',
                    null
                );
                return $result['user_id'];
            }
            return false;
        } catch (Exception $e) {
            error_log("createUser error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateLastLogin($user_id) {
        try {
            $sql = "UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([':user_id' => $user_id]);
        } catch (Exception $e) {
            error_log("updateLastLogin error: " . $e->getMessage());
            return false;
        }
    }
    
    // ============ NOTIFICATION FUNCTIONS ============
    
    public function createNotification($user_id, $type, $title, $message, $related_id = null) {
        try {
            if (!$this->tableExists('notifications')) {
                return false;
            }
            
            $sql = "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                    VALUES (:user_id, :type, :title, :message, false, CURRENT_TIMESTAMP)";
            $stmt = $this->conn->prepare($sql);
            
            return $stmt->execute([
                ':user_id' => $user_id,
                ':type' => $type,
                ':title' => $title,
                ':message' => $message
            ]);
        } catch (Exception $e) {
            error_log("createNotification error: " . $e->getMessage());
            return false;
        }
    }
    
    public function createNotificationForAllUsers($type, $title, $message, $related_id = null) {
        try {
            if (!$this->tableExists('notifications')) {
                return false;
            }
            
            $sql = "SELECT user_id FROM users";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($users as $user_id) {
                $this->createNotification($user_id, $type, $title, $message, $related_id);
            }
            return true;
        } catch (Exception $e) {
            error_log("createNotificationForAllUsers error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getUnreadCount($user_id) {
        try {
            if (!$this->tableExists('notifications')) {
                return 0;
            }
            
            $sql = "SELECT COUNT(*) FROM notifications 
                    WHERE user_id = :user_id AND is_read = false";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("getUnreadCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    public function getUserNotifications($user_id, $limit = 50, $unread_only = false) {
        try {
            if (!$this->tableExists('notifications')) {
                return [];
            }
            
            $sql = "SELECT * FROM notifications WHERE user_id = :user_id";
            
            if ($unread_only) {
                $sql .= " AND is_read = false";
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT :limit";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getUserNotifications error: " . $e->getMessage());
            return [];
        }
    }
    
    public function markNotificationAsRead($notification_id, $user_id) {
        try {
            if (!$this->tableExists('notifications')) {
                return false;
            }
            
            $sql = "UPDATE notifications SET is_read = true 
                    WHERE notification_id = :notification_id AND user_id = :user_id";
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':notification_id' => $notification_id,
                ':user_id' => $user_id
            ]);
        } catch (Exception $e) {
            error_log("markNotificationAsRead error: " . $e->getMessage());
            return false;
        }
    }
    
    public function markAllNotificationsAsRead($user_id) {
        try {
            if (!$this->tableExists('notifications')) {
                return false;
            }
            
            $sql = "UPDATE notifications SET is_read = true 
                    WHERE user_id = :user_id AND is_read = false";
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([':user_id' => $user_id]);
        } catch (Exception $e) {
            error_log("markAllNotificationsAsRead error: " . $e->getMessage());
            return false;
        }
    }
    
    // ============ RESERVATION FUNCTIONS (CRITICAL FOR DASHBOARD) ============
    
    /**
     * Get user reservations - FIXED: This method was missing!
     */
    public function getUserReservations($user_id, $limit = null) {
        try {
            $sql = "SELECT 
                        o.user_id,
                        o.id as reservation_id,
                        o.order_number,
                        o.total_amount as total_price,
                        o.status,
                        o.payment_status,
                        o.order_date as created_at,
                        o.pickup_date,
                        o.notes,
                        oi.quantity,
                        oi.unit_price as price_per_kilo,
                        oi.subtotal,
                        p.name as fish_name
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN harvest_products hp ON oi.harvest_product_id = hp.id
                    JOIN products p ON hp.product_id = p.id
                    WHERE o.user_id = :user_id 
                      AND o.order_type = 'reservation'
                    ORDER BY o.order_date DESC";
            
            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Transform to match expected format from old system
            $reservations = [];
            foreach ($results as $row) {
                $reservations[] = [
                    'reservation_id' => $row['reservation_id'],
                    'user_id' => $row['user_id'],
                    'order_number' => $row['order_number'],
                    'fish_name' => $row['fish_name'],
                    'quantity' => $row['quantity'],
                    'total_price' => $row['total_price'],
                    'price_per_kilo' => $row['price_per_kilo'],
                    'status' => $row['status'],
                    'payment_status' => $row['payment_status'],
                    'payment_method' => $this->mapPaymentStatusToMethod($row['payment_status']),
                    'created_at' => $row['created_at'],
                    'pickup_date' => $row['pickup_date'],
                    'notes' => $row['notes']
                ];
            }
            
            return $reservations;
        } catch (Exception $e) {
            error_log("getUserReservations error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Map payment status to payment method
     */
    private function mapPaymentStatusToMethod($payment_status) {
        switch ($payment_status) {
            case 'salary_deducted':
                return 'salary_deduction';
            case 'paid':
                return 'cash';
            case 'unpaid':
                return 'pay_later';
            default:
                return 'pending';
        }
    }
    
    /**
     * Get user dashboard statistics
     */
    public function getUserDashboardStats($user_id) {
        try {
            $stats = [
                'reservations' => [
                    'pending_count' => 0,
                    'confirmed_count' => 0,
                    'processing_count' => 0,
                    'completed_count' => 0,
                    'cancelled_count' => 0,
                    'claimed_count' => 0
                ],
                'total_spent' => 0
            ];
            
            $sql = "SELECT 
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                        COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
                        COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_count,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
                        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount END), 0) as total_spent
                    FROM orders 
                    WHERE user_id = :user_id 
                      AND order_type = 'reservation'";
                    
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $stats['reservations'] = [
                    'pending_count' => intval($result['pending_count']),
                    'confirmed_count' => intval($result['confirmed_count']),
                    'processing_count' => intval($result['processing_count']),
                    'completed_count' => intval($result['completed_count']),
                    'cancelled_count' => intval($result['cancelled_count']),
                    'claimed_count' => intval($result['confirmed_count']) // Claimed = confirmed for compatibility
                ];
                $stats['total_spent'] = floatval($result['total_spent']);
            }
            
            return $stats;
        } catch (Exception $e) {
            error_log("getUserDashboardStats error: " . $e->getMessage());
            return $stats;
        }
    }
    
    /**
     * Check if user already has a reservation for a product
     */
    public function checkUserReservationForProduct($user_id, $product_id) {
        try {
            $sql = "SELECT o.id as reservation_id, o.status 
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    WHERE o.user_id = :user_id 
                      AND oi.harvest_product_id = :product_id
                      AND o.status IN ('pending', 'confirmed', 'processing')
                      AND o.order_type = 'reservation'
                    LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':product_id' => $product_id
            ]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("checkUserReservationForProduct error: " . $e->getMessage());
            return null;
        }
    }
    
    // ============ PRODUCT FUNCTIONS ============
    
    public function getAvailableProducts($limit = null) {
        try {
            $sql = "SELECT 
                        hp.id as product_id,
                        p.name as fish_name,
                        p.description,
                        p.category,
                        hp.price_per_unit as price_per_kilo,
                        hp.available_quantity,
                        hp.quantity as total_quantity,
                        h.harvest_name,
                        h.harvest_date,
                        h.id as harvest_id
                    FROM harvest_products hp
                    JOIN products p ON hp.product_id = p.id
                    JOIN harvests h ON hp.harvest_id = h.id
                    WHERE hp.available_quantity > 0 
                      AND h.status = 'completed'
                    ORDER BY h.harvest_date DESC";
            
            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }
            
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getAvailableProducts error: " . $e->getMessage());
            return [];
        }
    }
    
    // ============ ANNOUNCEMENT FUNCTIONS ============
    
    public function getActiveAnnouncements($limit = null) {
        try {
            if (!$this->tableExists('announcements')) {
                return [];
            }
            
            $sql = "SELECT * FROM announcements 
                    WHERE is_active = true 
                      AND published_at <= CURRENT_TIMESTAMP
                      AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
                    ORDER BY published_at DESC";
            
            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }
            
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getActiveAnnouncements error: " . $e->getMessage());
            return [];
        }
    }
    
    // ============ CASHIER FUNCTIONS ============
    
    public function getConfirmedReservations($limit = 20) {
        try {
            $sql = "SELECT 
                        o.id as reservation_id,
                        o.order_number,
                        o.user_id,
                        o.total_amount as total_price,
                        o.payment_status,
                        o.pickup_date,
                        o.order_date as created_at,
                        o.notes,
                        u.full_name as customer_name,
                        u.email,
                        u.contact_number,
                        p.name as fish_name,
                        oi.quantity
                    FROM orders o
                    JOIN users u ON o.user_id = u.user_id
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN harvest_products hp ON oi.harvest_product_id = hp.id
                    JOIN products p ON hp.product_id = p.id
                    WHERE o.status = 'confirmed'
                      AND o.order_type = 'reservation'
                    ORDER BY o.order_date ASC
                    LIMIT :limit";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getConfirmedReservations error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getTodaySales() {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_transactions,
                        COALESCE(SUM(total_amount), 0) as total_sales,
                        COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count
                    FROM orders 
                    WHERE DATE(order_date) = CURRENT_DATE
                      AND order_type = 'reservation'
                      AND payment_status = 'paid'";
            
            $stmt = $this->conn->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_transactions' => $result['total_transactions'] ?? 0,
                'total_sales' => $result['total_sales'] ?? 0,
                'paid_count' => $result['paid_count'] ?? 0
            ];
        } catch (Exception $e) {
            error_log("getTodaySales error: " . $e->getMessage());
            return ['total_transactions' => 0, 'total_sales' => 0, 'paid_count' => 0];
        }
    }
    
    public function getRecentSales($limit = 10) {
        try {
            $sql = "SELECT 
                        o.id as sale_id,
                        o.order_number as receipt_number,
                        o.total_amount as total_price,
                        o.payment_status,
                        o.order_date as paid_at,
                        u.full_name as customer_name,
                        u.email,
                        p.name as fish_name,
                        oi.quantity
                    FROM orders o
                    JOIN users u ON o.user_id = u.user_id
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN harvest_products hp ON oi.harvest_product_id = hp.id
                    JOIN products p ON hp.product_id = p.id
                    WHERE o.payment_status = 'paid'
                      AND o.order_type = 'reservation'
                    ORDER BY o.order_date DESC
                    LIMIT :limit";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("getRecentSales error: " . $e->getMessage());
            return [];
        }
    }
    

    // ============ ORDER FUNCTIONS (NO RESERVATIONS) ============

/**
 * Get user orders
 */
public function getUserOrders($user_id, $limit = null) {
    try {
        $sql = "SELECT 
                    o.order_id,
                    o.order_status,
                    o.payment_method,
                    o.total_amount,
                    o.remarks,
                    o.order_date,
                    o.created_at,
                    o.confirmed_at,
                    o.claimed_at,
                    o.cancelled_at,
                    COUNT(oi.order_item_id) as item_count,
                    SUM(oi.quantity) as total_quantity
                FROM orders o
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE o.user_id = :user_id
                GROUP BY o.order_id
                ORDER BY o.order_date DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getUserOrders error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user order statistics for dashboard
 */
public function getUserOrderStats($user_id) {
    try {
        $stats = [
            'orders' => [
                'pending_count' => 0,
                'processing_count' => 0,
                'confirmed_count' => 0,
                'ready_count' => 0,
                'completed_count' => 0,
                'cancelled_count' => 0
            ],
            'total_spent' => 0
        ];
        
        $sql = "SELECT 
                    COUNT(CASE WHEN order_status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN order_status = 'processing' THEN 1 END) as processing_count,
                    COUNT(CASE WHEN order_status = 'confirmed' THEN 1 END) as confirmed_count,
                    COUNT(CASE WHEN order_status = 'ready_for_pickup' THEN 1 END) as ready_count,
                    COUNT(CASE WHEN order_status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN order_status = 'cancelled' THEN 1 END) as cancelled_count,
                    COALESCE(SUM(CASE WHEN order_status = 'completed' THEN total_amount ELSE 0 END), 0) as total_spent
                FROM orders 
                WHERE user_id = :user_id";
                
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats['orders'] = [
                'pending_count' => intval($result['pending_count']),
                'processing_count' => intval($result['processing_count']),
                'confirmed_count' => intval($result['confirmed_count']),
                'ready_count' => intval($result['ready_count']),
                'completed_count' => intval($result['completed_count']),
                'cancelled_count' => intval($result['cancelled_count'])
            ];
            $stats['total_spent'] = floatval($result['total_spent']);
        }
        
        return $stats;
    } catch (Exception $e) {
        error_log("getUserOrderStats error: " . $e->getMessage());
        return $stats;
    }
}

/**
 * Get single order details
 */
public function getOrderById($order_id, $user_id) {
    try {
        $sql = "SELECT 
                    o.*,
                    COUNT(oi.order_item_id) as item_count,
                    SUM(oi.quantity) as total_quantity
                FROM orders o
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE o.order_id = :order_id AND o.user_id = :user_id
                GROUP BY o.order_id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':order_id' => $order_id,
            ':user_id' => $user_id
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getOrderById error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get order items
 */
public function getOrderItems($order_id) {
    try {
        $sql = "SELECT 
                    oi.order_item_id,
                    oi.order_id,
                    oi.product_id,
                    oi.quantity,
                    oi.price_per_kg,
                    oi.subtotal,
                    fp.fish_name
                FROM order_items oi
                JOIN fish_products fp ON oi.product_id = fp.product_id
                WHERE oi.order_id = :order_id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':order_id' => $order_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getOrderItems error: " . $e->getMessage());
        return [];
    }
}

/**
 * Cancel order
 */
public function cancelOrder($order_id, $user_id) {
    try {
        $sql = "UPDATE orders 
                SET order_status = 'cancelled', 
                    cancelled_at = NOW(), 
                    updated_at = NOW() 
                WHERE order_id = :order_id AND user_id = :user_id 
                AND order_status = 'pending'";
        
        $stmt = $this->conn->prepare($sql);
        $result = $stmt->execute([
            ':order_id' => $order_id,
            ':user_id' => $user_id
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            // FIFO: reverse stock deductions back to original harvest batches
            require_once __DIR__ . '/FifoStock.php';
            $fifo = new FifoStock($this->conn);
            $oisStmt = $this->conn->prepare("SELECT order_item_id FROM order_items WHERE order_id = :oid");
            $oisStmt->execute([':oid' => $order_id]);
            foreach ($oisStmt->fetchAll(PDO::FETCH_ASSOC) as $oi) {
                $fifo->reverseDeduction((int)$oi['order_item_id']);
            }
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("cancelOrder error: " . $e->getMessage());
        return false;
    }
}

    // ============ AUDIT FUNCTIONS ============
    
    public function auditLog($user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
        try {
            if (!$this->tableExists('activity_logs')) {
                return false;
            }
            
            $sql = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) 
                    VALUES (:user_id, :action, :description, :ip_address, :user_agent, CURRENT_TIMESTAMP)";
            
            $description = $action . ' on ' . $table_name . ' (ID: ' . $record_id . ')';
            
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([ 
                ':user_id' => $user_id,
                ':action' => $action,
                ':description' => $description,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("auditLog error: " . $e->getMessage());
            return false;
        }
    }
}
?>