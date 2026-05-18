<?php
// includes/session.php - COMPLETE FIXED VERSION with Accounting Support
require_once __DIR__ . '/config.php';

// Define SITE_URL if not already defined
if (!defined('SITE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $base_dir = str_replace('/includes', '', $script_dir);
    define('SITE_URL', $protocol . $host . $base_dir);
}

class SessionManager {
    
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public static function login($user) {
        self::init();
        
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['first_name'] = $user['name'];
        $_SESSION['last_name'] = '';
        $_SESSION['email'] = $user['email'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        session_regenerate_id(true);
    }
    
    public static function logout() {
        self::init();
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public static function isLoggedIn() {
        self::init();
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public static function getUserId() {
        self::init();
        return $_SESSION['user_id'] ?? null;
    }
    
    public static function getUserRole() {
        self::init();
        return $_SESSION['user_role'] ?? null;
    }
    
    public static function getUserType() {
        return self::getUserRole();
    }
    
    public static function getName() {
        self::init();
        return $_SESSION['first_name'] ?? null;
    }
    
    public static function getEmail() {
        self::init();
        return $_SESSION['email'] ?? null;
    }
    
    public static function isManager() {
        return self::isLoggedIn() && self::getUserRole() === 'manager';
    }
    
    public static function isAccounting() {
        return self::isLoggedIn() && self::getUserRole() === 'accounting';
    }
    
    public static function isCashier() {
        $role = self::getUserRole();
        return self::isLoggedIn() && ($role === 'staff' || $role === 'manager');
    }
    
    public static function isStandard() {
        return self::isLoggedIn() && self::getUserRole() === 'standard';
    }

    public static function isStaff() {
        $role = self::getUserRole();
        return self::isLoggedIn() && ($role === 'staff' || $role === 'manager');
    }

    public static function isAdmin() {
        return self::isManager() || self::isStaff();
    }
    
    public static function isOfficeUser() {
        return self::isManager() || self::isStaff();
    }

    public static function getOfficeStaffId() {
        self::init();
        return $_SESSION['user_id'] ?? null;
    }

    public static function requireManagerOrStaff() {
        self::requireLogin();
        if (!self::isManager() && !self::isStaff()) {
            self::setError('You do not have permission to access this page.');
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
    
    public static function requireAccounting() {
        self::requireLogin();
        if (!self::isAccounting()) {
            self::setError('Access denied. Accounting privileges required.');
            header('Location: ' . SITE_URL . '/login.php');
            exit();
        }
    }
    
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            self::setWarning('Please log in to access this page.');
            header('Location: ' . SITE_URL . '/login.php');
            exit();
        }
    }
    
    public static function requireManager() {
        self::requireLogin();
        if (!self::isManager()) {
            self::setError('You do not have permission to access this page.');
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
    
    public static function requireCashier() {
        self::requireLogin();
        if (!self::isCashier()) {
            self::setError('You do not have permission to access this page.');
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
    
    public static function requireStandard() {
        self::requireLogin();
        if (!self::isStandard() && !self::isManager() && !self::isStaff()) {
            self::setError('You do not have permission to access this page.');
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
    
    public static function requireOfficeUser() {
        self::requireLogin();
        if (!self::isOfficeUser()) {
            self::setError('You do not have permission to access this page.');
            header('Location: ' . SITE_URL . '/index.php');
            exit();
        }
    }
    
    public static function setMessage($message, $type = 'info') {
        self::init();
        $_SESSION['flash_message'] = [
            'message' => $message,
            'type' => $type
        ];
    }
    
    public static function setSuccess($message) {
        self::setMessage($message, 'success');
    }
    
    public static function setError($message) {
        self::setMessage($message, 'error');
    }
    
    public static function setWarning($message) {
        self::setMessage($message, 'warning');
    }
    
    public static function setInfo($message) {
        self::setMessage($message, 'info');
    }
    
    public static function getMessage() {
        self::init();
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $message;
        }
        return null;
    }
    
    public static function hasMessage() {
        self::init();
        return isset($_SESSION['flash_message']);
    }
    
    public static function getLoginTime() {
        self::init();
        return $_SESSION['login_time'] ?? null;
    }
    
    public static function refresh() {
        self::init();
        if (self::isLoggedIn()) {
            $_SESSION['login_time'] = time();
        }
    }
    
    public static function set($key, $value) {
        self::init();
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        self::init();
        return $_SESSION[$key] ?? $default;
    }
    
    public static function delete($key) {
        self::init();
        unset($_SESSION[$key]);
    }
    
    public static function clear() {
        self::init();
        $flash = self::getMessage();
        $_SESSION = array();
        if ($flash) {
            $_SESSION['flash_message'] = $flash;
        }
    }
    
    public static function getAll() {
        self::init();
        return $_SESSION;
    }
}

SessionManager::init();
?>