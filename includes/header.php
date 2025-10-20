<?php
/**
 * header.php
 *
 * Shared HTML header and navigation.
 */

session_start();
$permissions = $_SESSION['permissions'] ?? [];
$is_logged_in = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="/InvApp/favicon.ico"> 
    <title>APMC Inventory</title>
    <script src="/InvApp/assets/js/tailwind.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="/InvApp/assets/css/style.css" rel="stylesheet">
</head>
<body class="clean-bg">
    
    <!-- Navigation Bar -->
    <nav class="bg-white shadow-md border-b-2 border-blue-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                
                <!-- Logo/Brand -->
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-blue-500 flex items-center justify-center mr-3">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <span class="text-gray-800 text-xl font-bold">Inventory App</span>
                </div>

                <!-- Navigation Links -->
                <div class="hidden md:flex items-center space-x-1">
                    <?php if (in_array('view_inventory', $permissions)): ?>
                    <a href="/InvApp/pages/dashboard.php" class="nav-item px-4 py-2 text-gray-700 hover:text-blue-600 transition-all duration-200 text-sm font-medium">
                        Dashboard
                    </a>
                    <?php endif; ?>
                    <?php if (in_array('view_inventory', $permissions) || in_array('manage_inventory', $permissions) || in_array('view_all_requests', $permissions)): ?>
                    <div class="dropdown">
                        <button class="nav-item px-4 py-2 text-gray-700 hover:text-blue-600 transition-all duration-200 text-sm font-medium flex items-center">
                            Inventory
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div class="dropdown-menu">
                            <a href="/InvApp/pages/inventory/invAudit.php" class="dropdown-item">Do An Audit</a>
                            <?php if (in_array('view_inventory', $permissions)): ?>
                            <a href="/InvApp/pages/inventory/inventory.php" class="dropdown-item">Manage Inventory</a>
                            <?php endif; ?>
                            <?php if (in_array('view_all_requests', $permissions)): ?>
                            <a href="/InvApp/pages/inventory/requestManagement.php" class="dropdown-item">Manage Requests</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!--
                    <?php if (in_array('manage_inventory', $permissions)): ?>
                    <a href="#" class="nav-item px-4 py-2 text-gray-700 hover:text-blue-600 transition-all duration-200 text-sm font-medium">
                        Equipment
                    </a>
                    <?php endif; ?>
                    -->
                    <?php if (in_array('manage_users', $permissions) || in_array('manage_locations', $permissions) || in_array('manage_categories', $permissions)): ?>
                    <div class="dropdown">
                        <button class="nav-item px-4 py-2 text-gray-700 hover:text-blue-600 transition-all duration-200 text-sm font-medium flex items-center">
                            Admin
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div class="dropdown-menu">
                            <a href="/InvApp/pages/users/users.php" class="dropdown-item">Manage Users</a>
                            <a href="/InvApp/pages/locations/locations.php" class="dropdown-item">Manage Sites</a>
                            <a href="/InvApp/pages/categories/categories.php" class="dropdown-item">Manage Categories</a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="/InvApp/logout.php" class="nav-item px-4 py-2 text-gray-700 hover:text-blue-600 transition-all duration-200 text-sm font-medium">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
<?php
$request_uri = $_SERVER['REQUEST_URI'];

if (strpos($request_uri, 'inventory.php') !== false || strpos($request_uri, 'requestManagement.php') !== false || strpos($request_uri, 'invAudit.php') !== false) {
    echo "<main class='mx-auto px-4 sm:px-6 lg:px-8 py-8'>";
} else {
    echo "<main class='max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8'>";
}
?>