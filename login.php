<?php
session_start();
include_once 'includes/db_connect1.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /InvApp/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $pdo = getPDO();
            $sql = "SELECT id, username, password, is_active FROM users WHERE username = :username";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                $sql = "SELECT role_id FROM user_roles WHERE user_id = :user_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $user['id']]);
                $role_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $_SESSION['permissions'] = [];
                if (!empty($role_ids)) {
                    $placeholders = implode(',', array_fill(0, count($role_ids), '?'));
                    $sql = "SELECT DISTINCT p.permission_name 
                            FROM permissions p 
                            JOIN role_permissions rp ON p.id = rp.permission_id 
                            WHERE rp.role_id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($role_ids);
                    $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }

                header('Location: /InvApp/pages/dashboard.php');
                exit;
            } else {
                $error = $user && !$user['is_active'] ? 'Account is inactive.' : 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventory</title>
    <script src="assets/js/tailwind.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        .clean-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%);
        }
        
        .input-focus:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }
        
        .hover-lift:hover {
            transform: translateY(-1px);
        }
        
        .button-glow:hover {
            box-shadow: 0 0 20px rgba(249, 115, 22, 0.3);
        }
        
        .border-accent {
            border-color: #3b82f6;
        }
        
        .bg-accent {
            background-color: #f97316;
        }
        
        .bg-accent:hover {
            background-color: #ea580c;
        }
    </style>
</head>
<body class="min-h-screen clean-bg flex items-center justify-center p-4">
    
    <!-- Main login container -->
    <div class="w-full max-w-md">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-800 mb-2">APMC Inventory Manager</h1>
        </div>

        <!-- Login form -->
        <div class="bg-white shadow-lg p-8">
            <form method="post" id="loginForm" class="space-y-6">
                
                <!-- Username field -->
                <div class="space-y-3">
                    <label class="text-blue-500 text-sm font-medium block">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <input type="text" id="username" name="username"
                               class="w-full pl-10 pr-4 py-3 border-2 border-gray-300 text-gray-900 focus:outline-none focus:border-blue-500 transition-all duration-300 input-focus"
                               required>
                    </div>
                </div>

                <!-- Password field -->
                <div class="space-y-3">
                    <label class="text-blue-500 text-sm font-medium block">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <input type="password" id="password" name="password"
                               class="w-full pl-10 pr-12 py-3 border-2 border-gray-300 text-gray-900 focus:outline-none focus:border-blue-500 transition-all duration-300 input-focus"
                               required>
                        
                    </div>
                </div>

                <!-- Login button -->
                <button type="submit" 
                        class="w-full bg-accent text-white py-3 px-4 font-semibold hover:bg-accent focus:outline-none focus:ring-4 focus:ring-orange-300 transform hover-lift transition-all duration-300 button-glow shadow-md mt-8">
                    Sign In
                </button>

            </form>
        </div>

        <!-- Bottom accent -->
        <div class="mt-4">
            <div class="h-1 bg-gradient-to-r from-blue-500 to-orange-500"></div>
        </div>
    </div>

    <script>
        // Add some interactive hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.01)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>