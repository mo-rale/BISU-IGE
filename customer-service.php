<?php
require_once 'includes/session.php';
require_once 'includes/config.php';

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$page_title = "Customer Service";
$userData = SessionManager::getUserData();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Service - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <?php include 'includes/navbar.php'; ?>
    
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 font-['Playfair_Display']">Customer Service</h1>
            <p class="text-gray-500 mt-2">How can we help you today?</p>
        </div>
        
        <div class="grid md:grid-cols-2 gap-8">
            <!-- FAQ Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-xl bg-sky-100 flex items-center justify-center">
                        <i class="fas fa-question-circle text-sky-600 text-xl"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900">FAQs</h2>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-2">How do I place an order?</h3>
                        <p class="text-gray-600 text-sm">Browse available fish products, add to cart, and proceed to checkout.</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-2">When is pickup available?</h3>
                        <p class="text-gray-600 text-sm">Pickup schedules are announced after each harvest cycle.</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-2">How are deductions calculated?</h3>
                        <p class="text-gray-600 text-sm">Deductions are based on order value and processed monthly.</p>
                    </div>
                </div>
            </div>
            
            <!-- Contact Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                        <i class="fas fa-headset text-emerald-600 text-xl"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900">Contact Us</h2>
                </div>
                
                <div class="space-y-4">
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                        <i class="fas fa-envelope text-gray-400 w-5"></i>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Email</p>
                            <a href="mailto:ige@bisu.edu.ph" class="text-sm text-sky-600">ige@bisu.edu.ph</a>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                        <i class="fas fa-phone text-gray-400 w-5"></i>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Phone</p>
                            <a href="tel:+639123456789" class="text-sm text-sky-600">+63 912 345 6789</a>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                        <i class="fas fa-clock text-gray-400 w-5"></i>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Hours</p>
                            <p class="text-sm text-gray-600">Mon-Fri, 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>