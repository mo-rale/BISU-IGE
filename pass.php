<?php
// hash_maker.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Generator - BISU IGE Aquaculture</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .hash-container {
            width: 100%;
            max-width: 600px;
            margin: 1rem;
            animation: fadeIn 0.5s ease-out;
        }
        
        .hash-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .input-group {
            transition: all 0.3s ease;
        }
        
        .input-group:focus-within {
            transform: translateY(-2px);
        }
        
        .hash-output {
            background: #1a202c;
            color: #00ff9d;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            word-break: break-all;
            border-radius: 0.75rem;
            border: 1px solid #2d3748;
        }
        
        .copy-btn {
            transition: all 0.2s ease;
        }
        
        .copy-btn:hover {
            transform: scale(1.05);
        }
        
        .copy-btn:active {
            transform: scale(0.95);
        }
        
        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
            overflow: hidden;
        }
        
        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite;
        }
        
        .shape-1 {
            width: 200px;
            height: 200px;
            top: -50px;
            right: -50px;
        }
        
        .shape-2 {
            width: 300px;
            height: 300px;
            bottom: -100px;
            left: -100px;
            animation-delay: -5s;
        }
        
        .shape-3 {
            width: 150px;
            height: 150px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -10s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            50% {
                transform: translateY(-20px) rotate(180deg);
            }
        }
        
        .strength-meter {
            height: 6px;
            transition: all 0.3s ease;
        }
        
        .info-tooltip {
            position: relative;
            cursor: help;
        }
        
        .info-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .tooltip-text {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #2d3748;
            color: white;
            padding: 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            white-space: nowrap;
            transition: all 0.3s ease;
            z-index: 10;
            margin-bottom: 0.5rem;
        }
        
        .tooltip-text::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: #2d3748 transparent transparent transparent;
        }
    </style>
</head>
<body>
    <!-- Floating Background Shapes -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    
    <div class="hash-container">
        <!-- Header -->
        <div class="text-center mb-6">
            <div class="flex justify-center mb-4">
                <div class="w-20 h-20 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 shadow-2xl flex items-center justify-center transform hover:rotate-12 transition-transform duration-300">
                    <i class="fas fa-lock text-4xl text-white"></i>
                </div>
            </div>
            <h1 class="text-3xl font-bold text-white drop-shadow-lg mb-2">
                Password Hash Generator
            </h1>
            <p class="text-purple-100 text-sm">
                Generate secure password hashes for your application
            </p>
        </div>
        
        <!-- Main Card -->
        <div class="hash-card">
            <?php
            $hash_result = '';
            $hash_info = '';
            $password = '';
            $error = '';
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $password = $_POST['password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';
                
                if (empty($password)) {
                    $error = 'Please enter a password.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    // Generate hash using PHP's password_hash
                    $hash_result = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Get hash information
                    $hash_info = password_get_info($hash_result);
                    
                    // Verify the hash (for demonstration)
                    $verify_result = password_verify($password, $hash_result);
                }
            }
            ?>
            
            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="mb-4 bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2 text-red-500"></i>
                        <span class="text-sm"><?php echo $error; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if ($hash_result): ?>
                <div class="mb-4 bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2 text-green-500"></i>
                        <span class="text-sm">Hash generated successfully!</span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Input Form -->
            <form method="POST" action="" onsubmit="return validateForm()">
                <!-- Password Input -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-key mr-2 text-purple-500"></i>
                        Enter Password to Hash
                    </label>
                    <div class="input-group relative">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               required
                               value="<?php echo htmlspecialchars($password); ?>"
                               onkeyup="checkPasswordStrength()"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all"
                               placeholder="Enter your password">
                        <button type="button" 
                                onclick="togglePassword()" 
                                class="absolute right-3 top-3 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                    
                    <!-- Password Strength Meter -->
                    <div class="mt-2">
                        <div class="flex justify-between mb-1">
                            <span class="text-xs text-gray-600">Password Strength:</span>
                            <span id="strengthLabel" class="text-xs font-medium text-gray-600">Not entered</span>
                        </div>
                        <div class="strength-meter flex space-x-1">
                            <div id="strength-1" class="h-1 flex-1 bg-gray-200 rounded"></div>
                            <div id="strength-2" class="h-1 flex-1 bg-gray-200 rounded"></div>
                            <div id="strength-3" class="h-1 flex-1 bg-gray-200 rounded"></div>
                            <div id="strength-4" class="h-1 flex-1 bg-gray-200 rounded"></div>
                        </div>
                        <p id="strengthText" class="text-xs mt-1 text-gray-500">
                            Use at least 8 characters with letters, numbers & symbols
                        </p>
                    </div>
                </div>
                
                <!-- Confirm Password -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-check-circle mr-2 text-purple-500"></i>
                        Confirm Password
                    </label>
                    <div class="input-group relative">
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               required
                               onkeyup="checkPasswordMatch()"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all"
                               placeholder="Confirm your password">
                    </div>
                    <p id="matchText" class="text-xs mt-1"></p>
                </div>
                
                <!-- Options (Read-only info) -->
                <div class="mb-6 bg-purple-50 p-4 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-purple-500 mt-0.5 mr-2"></i>
                        <div class="text-xs text-purple-700">
                            <p class="font-medium mb-1">Hash Algorithm Information:</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>Uses PHP's <code class="bg-purple-100 px-1 rounded">password_hash()</code> with default algorithm</li>
                                <li>Currently using: <strong><?php echo defined('PASSWORD_DEFAULT') ? PASSWORD_DEFAULT : 'bcrypt'; ?></strong></li>
                                <li>Each hash includes a unique salt for security</li>
                                <li>Same password produces different hashes each time</li>
                                <li>Use <code class="bg-purple-100 px-1 rounded">password_verify()</code> to check passwords</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Generate Button -->
                <button type="submit" 
                        class="w-full bg-gradient-to-r from-purple-600 to-pink-600 text-white py-3 px-4 rounded-lg font-medium hover:from-purple-700 hover:to-pink-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transform transition-all duration-300 hover:scale-105 shadow-lg mb-4">
                    <i class="fas fa-hashtag mr-2"></i>
                    Generate Password Hash
                </button>
            </form>
            
            <!-- Hash Result Section -->
            <?php if ($hash_result): ?>
                <div class="mt-6 border-t pt-6 border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">
                        <i class="fas fa-code mr-2 text-purple-500"></i>
                        Generated Hash:
                    </h3>
                    
                    <!-- Hash Output -->
                    <div class="hash-output p-4 rounded-lg relative group">
                        <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onclick="copyToClipboard('hashResult')" 
                                    class="copy-btn bg-gray-700 text-white px-3 py-1 rounded text-xs hover:bg-gray-600">
                                <i class="far fa-copy mr-1"></i> Copy
                            </button>
                        </div>
                        <code id="hashResult" class="text-sm"><?php echo $hash_result; ?></code>
                    </div>
                    
                    <!-- Hash Information -->
                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <span class="text-gray-600 block text-xs">Algorithm:</span>
                            <span class="font-medium text-gray-800">
                                <?php 
                                switch($hash_info['algo']) {
                                    case PASSWORD_BCRYPT:
                                        echo 'bcrypt';
                                        break;
                                    case PASSWORD_ARGON2I:
                                        echo 'Argon2i';
                                        break;
                                    case PASSWORD_ARGON2ID:
                                        echo 'Argon2id';
                                        break;
                                    default:
                                        echo 'Unknown';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <span class="text-gray-600 block text-xs">Algorithm Name:</span>
                            <span class="font-medium text-gray-800"><?php echo $hash_info['algoName']; ?></span>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <span class="text-gray-600 block text-xs">Hash Length:</span>
                            <span class="font-medium text-gray-800"><?php echo strlen($hash_result); ?> characters</span>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <span class="text-gray-600 block text-xs">Verification:</span>
                            <span class="font-medium text-green-600">
                                <i class="fas fa-check-circle mr-1"></i> Passed
                            </span>
                        </div>
                    </div>
                    
                    <!-- Options (Cost parameters if available) -->
                    <?php if (isset($hash_info['options'])): ?>
                        <div class="mt-3 bg-gray-50 p-3 rounded-lg">
                            <span class="text-gray-600 block text-xs mb-1">Algorithm Options:</span>
                            <pre class="text-xs bg-gray-800 text-green-400 p-2 rounded overflow-x-auto"><?php echo json_encode($hash_info['options'], JSON_PRETTY_PRINT); ?></pre>
                        </div>
                    <?php endif; ?>
                    
                    <!-- PHP Code Example -->
                    <div class="mt-4 bg-blue-50 p-4 rounded-lg">
                        <h4 class="text-sm font-semibold text-blue-800 mb-2">
                            <i class="fas fa-code mr-2"></i>
                            PHP Usage Example:
                        </h4>
                        <pre class="text-xs bg-gray-800 text-blue-300 p-3 rounded overflow-x-auto">
// To verify this password later:
if (password_verify($user_input_password, '<?php echo $hash_result; ?>')) {
    echo "Password is correct!";
} else {
    echo "Invalid password.";
}

// To check if rehashing is needed:
if (password_needs_rehash($stored_hash, PASSWORD_DEFAULT)) {
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    // Update the hash in database
}</pre>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Quick Test Section -->
            <div class="mt-4 text-center">
                <button onclick="fillTestPassword()" 
                        class="text-sm text-purple-600 hover:text-purple-800 transition-colors">
                    <i class="fas fa-flask mr-1"></i>
                    Use test password
                </button>
                <span class="mx-2 text-gray-300">|</span>
                <a href="?reset" 
                   class="text-sm text-purple-600 hover:text-purple-800 transition-colors">
                    <i class="fas fa-redo-alt mr-1"></i>
                    Clear form
                </a>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="mt-4 text-center">
            <p class="text-xs text-purple-200">
                <i class="fas fa-shield-alt mr-1"></i>
                Secure hash generation • Never store plain text passwords
            </p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBars = [
                document.getElementById('strength-1'),
                document.getElementById('strength-2'),
                document.getElementById('strength-3'),
                document.getElementById('strength-4')
            ];
            const strengthLabel = document.getElementById('strengthLabel');
            const strengthText = document.getElementById('strengthText');
            
            // Reset bars
            strengthBars.forEach(bar => {
                bar.className = 'h-1 flex-1 bg-gray-200 rounded';
            });
            
            if (password.length === 0) {
                strengthLabel.textContent = 'Not entered';
                strengthLabel.className = 'text-xs font-medium text-gray-600';
                strengthText.textContent = 'Use at least 8 characters with letters, numbers & symbols';
                return;
            }
            
            // Calculate strength score (0-4)
            let score = 0;
            
            // Length check
            if (password.length >= 8) score++;
            if (password.length >= 12) score++;
            
            // Character variety
            if (/[a-z]/.test(password)) score += 0.5;
            if (/[A-Z]/.test(password)) score += 0.5;
            if (/[0-9]/.test(password)) score += 0.5;
            if (/[^a-zA-Z0-9]/.test(password)) score += 0.5;
            
            // Normalize to 0-4
            score = Math.min(4, Math.floor(score));
            
            // Update bars
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-600'];
            const labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
            const messages = [
                'Too weak - add more characters and variety',
                'Weak - try mixing uppercase, numbers, and symbols',
                'Fair - could be stronger',
                'Strong - good password',
                'Very Strong - excellent password'
            ];
            
            for (let i = 0; i < score; i++) {
                strengthBars[i].className = `h-1 flex-1 ${colors[score-1]} rounded`;
            }
            
            strengthLabel.textContent = labels[score-1] || 'Weak';
            strengthLabel.className = 'text-xs font-medium ' + 
                (score <= 1 ? 'text-red-600' : 
                 score <= 2 ? 'text-orange-600' : 
                 score <= 3 ? 'text-yellow-600' : 'text-green-600');
            strengthText.textContent = messages[score-1] || messages[1];
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('matchText');
            
            if (confirm.length === 0) {
                matchText.textContent = '';
                return;
            }
            
            if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.className = 'text-xs mt-1 text-green-600';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.className = 'text-xs mt-1 text-red-600';
            }
        }
        
        function validateForm() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password.length < 1) {
                alert('Please enter a password.');
                return false;
            }
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            return true;
        }
        
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent || element.innerText;
            
            navigator.clipboard.writeText(text).then(function() {
                // Show temporary success message
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 2000);
            }, function() {
                alert('Failed to copy text.');
            });
        }
        
        function fillTestPassword() {
            document.getElementById('password').value = 'TestPassword123!';
            document.getElementById('confirm_password').value = 'TestPassword123!';
            checkPasswordStrength();
            checkPasswordMatch();
        }
        
        // Check if reset parameter is present
        if (window.location.search.includes('reset')) {
            document.getElementById('password').value = '';
            document.getElementById('confirm_password').value = '';
            checkPasswordStrength();
            checkPasswordMatch();
        }
    </script>
</body>
</html>