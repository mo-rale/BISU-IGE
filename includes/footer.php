<?php
/**
 * Footer Component for BISU IGE Aquaculture Website
 * Automatically detects login status from includes/session.php
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (you can adjust this based on your session.php structure)
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['username']) || isset($_SESSION['email']);
?>

<?php if ($is_logged_in): ?>
    <!-- Logged In Footer - Simple version -->
    <footer class="bg-white border-t border-gray-200 mt-12 py-8">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <p class="text-sm text-gray-500">
                &copy; <?php echo date('Y'); ?> Bohol Island State University — Production and Business Services.
            </p>
        </div>
    </footer>
<?php else: ?>
    <!-- Guest Footer - Full version with login/register links -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-center md:text-left">
                    <h3 class="text-lg font-bold text-gray-900">BISU IGE Aquaculture</h3>
                    <p class="text-sm text-gray-500 mt-1">Bohol Island State University — Production and Business Services</p>
                </div>
                <div class="flex gap-4">
                    <a href="login.php" class="text-sm text-gray-500 hover:text-brand-600 transition">Login</a>
                    <a href="register.php" class="text-sm text-gray-500 hover:text-brand-600 transition">Register</a>
                    <a href="products.php" class="text-sm text-gray-500 hover:text-brand-600 transition">Products</a>
                </div>
            </div>
            <div class="border-t border-gray-100 mt-6 pt-6 text-center">
                <p class="text-sm text-gray-400">
                    &copy; <?php echo date('Y'); ?> Bohol Island State University — Production and Business Services. All rights reserved.
                </p>
            </div>
        </div>
    </footer>
<?php endif; ?>