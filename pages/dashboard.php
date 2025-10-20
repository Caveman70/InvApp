<?php
/**
 * dashboard.php
 *
 * Main dashboard page, shows links based on permissions.
 */

$required_permission = 'view_inventory';
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$pdo = getPDO();

?>

<?php include '../includes/header.php'; ?>
        
        <!-- Welcome Section -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Welcome back, <?= $username ?></h1>
            <p class="text-gray-600">Here's what's happening with your dashboard today.</p>
        </div>

        <!-- Stats Cards -->
         <?php
            $lsClinical = get_lowstock_count(7, $pdo);
         ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white shadow-md p-6 card-hover transition-all duration-300 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Low Stock Clinical</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $lsClinical ?> Item(s)</p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M9 14.2354V17.0001C9 19.7615 11.2386 22.0001 14 22.0001H14.8824C16.7691 22.0001 18.3595 20.7311 18.8465 19.0001" stroke-width="1.5"></path> <path d="M5.42857 3H5.3369C5.02404 3 4.86761 3 4.73574 3.01166C3.28763 3.13972 2.13972 4.28763 2.01166 5.73574C2 5.86761 2 6.02404 2 6.3369V7.23529C2 11.1013 5.13401 14.2353 9 14.2353C12.7082 14.2353 15.7143 11.2292 15.7143 7.521V6.3369C15.7143 6.02404 15.7143 5.86761 15.7026 5.73574C15.5746 4.28763 14.4267 3.13972 12.9785 3.01166C12.8467 3 12.6902 3 12.3774 3H12.2857" stroke-width="1.5" stroke-linecap="round"></path> <circle cx="19" cy="16" r="3" stroke-width="1.5"></circle> <path d="M12 2V4" stroke-width="1.5" stroke-linecap="round"></path> <path d="M6 2V4" stroke-width="1.5" stroke-linecap="round"></path>    
                        </svg>
                    </div>
                </div>
            </div>

            <?php
                $zsClinical = get_zerostock_count(7, $pdo);
            ?>
            <div class="bg-white shadow-md p-6 card-hover transition-all duration-300 border-l-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Zero Stock Clinical</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $zsClinical ?> Item(s)</p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M9 14.2354V17.0001C9 19.7615 11.2386 22.0001 14 22.0001H14.8824C16.7691 22.0001 18.3595 20.7311 18.8465 19.0001" stroke-width="1.5"></path> <path d="M5.42857 3H5.3369C5.02404 3 4.86761 3 4.73574 3.01166C3.28763 3.13972 2.13972 4.28763 2.01166 5.73574C2 5.86761 2 6.02404 2 6.3369V7.23529C2 11.1013 5.13401 14.2353 9 14.2353C12.7082 14.2353 15.7143 11.2292 15.7143 7.521V6.3369C15.7143 6.02404 15.7143 5.86761 15.7026 5.73574C15.5746 4.28763 14.4267 3.13972 12.9785 3.01166C12.8467 3 12.6902 3 12.3774 3H12.2857" stroke-width="1.5" stroke-linecap="round"></path> <circle cx="19" cy="16" r="3" stroke-width="1.5"></circle> <path d="M12 2V4" stroke-width="1.5" stroke-linecap="round"></path> <path d="M6 2V4" stroke-width="1.5" stroke-linecap="round"></path>    
                        </svg>
                    </div>
                </div>
            </div>

            <?php
                $lsClerical = get_lowstock_count(16, $pdo);
            ?>
            <div class="bg-white shadow-md p-6 card-hover transition-all duration-300 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Low Stock Clerical</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $lsClerical ?> Item(s)</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M1.226,24h16.548C18.45,24,19,23.45,19,22.774V17.5c0-0.276-0.224-0.5-0.5-0.5S18,17.224,18,17.5v5.274   C18,22.898,17.898,23,17.774,23H1.226C1.102,23,1,22.898,1,22.774V2.226C1,2.102,1.102,2,1.226,2h1.866C3.299,2.581,3.849,3,4.5,3   h10c0.651,0,1.201-0.419,1.408-1h1.866C17.898,2,18,2.102,18,2.226v3.839c0,0.276,0.224,0.5,0.5,0.5s0.5-0.224,0.5-0.5V2.226   C19,1.55,18.45,1,17.774,1h-1.866c-0.207-0.581-0.757-1-1.408-1h-10C3.849,0,3.299,0.419,3.092,1H1.226C0.55,1,0,1.55,0,2.226   v20.549C0,23.45,0.55,24,1.226,24z M4.5,1h10C14.776,1,15,1.225,15,1.5S14.776,2,14.5,2h-10C4.224,2,4,1.775,4,1.5S4.224,1,4.5,1z"/><path d="M15,7.5C15,7.224,14.776,7,14.5,7h-10C4.224,7,4,7.224,4,7.5S4.224,8,4.5,8h10C14.776,8,15,7.776,15,7.5z"/><path d="M12.286,12.5c0-0.276-0.224-0.5-0.5-0.5H4.5C4.224,12,4,12.224,4,12.5S4.224,13,4.5,13h7.286   C12.063,13,12.286,12.776,12.286,12.5z"/><path d="M4.5,17C4.224,17,4,17.224,4,17.5S4.224,18,4.5,18h5c0.276,0,0.5-0.224,0.5-0.5S9.776,17,9.5,17H4.5z"/><path d="M13.5,17.5c0.044,0,0.089-0.006,0.133-0.018l2.318-0.639c0.083-0.023,0.16-0.067,0.221-0.129l7.334-7.335   c0.657-0.658,0.657-1.728,0-2.386c-0.637-0.637-1.749-0.637-2.386,0l-7.334,7.335c-0.061,0.062-0.105,0.137-0.128,0.221   l-0.639,2.317c-0.048,0.173,0.001,0.359,0.128,0.486C13.241,17.448,13.369,17.5,13.5,17.5z M14.586,14.942l7.241-7.241   c0.26-0.26,0.712-0.26,0.972,0c0.268,0.268,0.268,0.704,0,0.972l-7.241,7.241l-1.341,0.37L14.586,14.942z"/>
                         </svg>
                    </div>
                </div>
            </div>

            <?php
                $zsClerical = get_zerostock_count(16, $pdo);
            ?>
            <div class="bg-white shadow-md p-6 card-hover transition-all duration-300 border-l-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">Zero Stock Clerical</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $zsClerical ?> Item(s)</p>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M1.226,24h16.548C18.45,24,19,23.45,19,22.774V17.5c0-0.276-0.224-0.5-0.5-0.5S18,17.224,18,17.5v5.274   C18,22.898,17.898,23,17.774,23H1.226C1.102,23,1,22.898,1,22.774V2.226C1,2.102,1.102,2,1.226,2h1.866C3.299,2.581,3.849,3,4.5,3   h10c0.651,0,1.201-0.419,1.408-1h1.866C17.898,2,18,2.102,18,2.226v3.839c0,0.276,0.224,0.5,0.5,0.5s0.5-0.224,0.5-0.5V2.226   C19,1.55,18.45,1,17.774,1h-1.866c-0.207-0.581-0.757-1-1.408-1h-10C3.849,0,3.299,0.419,3.092,1H1.226C0.55,1,0,1.55,0,2.226   v20.549C0,23.45,0.55,24,1.226,24z M4.5,1h10C14.776,1,15,1.225,15,1.5S14.776,2,14.5,2h-10C4.224,2,4,1.775,4,1.5S4.224,1,4.5,1z"/><path d="M15,7.5C15,7.224,14.776,7,14.5,7h-10C4.224,7,4,7.224,4,7.5S4.224,8,4.5,8h10C14.776,8,15,7.776,15,7.5z"/><path d="M12.286,12.5c0-0.276-0.224-0.5-0.5-0.5H4.5C4.224,12,4,12.224,4,12.5S4.224,13,4.5,13h7.286   C12.063,13,12.286,12.776,12.286,12.5z"/><path d="M4.5,17C4.224,17,4,17.224,4,17.5S4.224,18,4.5,18h5c0.276,0,0.5-0.224,0.5-0.5S9.776,17,9.5,17H4.5z"/><path d="M13.5,17.5c0.044,0,0.089-0.006,0.133-0.018l2.318-0.639c0.083-0.023,0.16-0.067,0.221-0.129l7.334-7.335   c0.657-0.658,0.657-1.728,0-2.386c-0.637-0.637-1.749-0.637-2.386,0l-7.334,7.335c-0.061,0.062-0.105,0.137-0.128,0.221   l-0.639,2.317c-0.048,0.173,0.001,0.359,0.128,0.486C13.241,17.448,13.369,17.5,13.5,17.5z M14.586,14.942l7.241-7.241   c0.26-0.26,0.712-0.26,0.972,0c0.268,0.268,0.268,0.704,0,0.972l-7.241,7.241l-1.341,0.37L14.586,14.942z"/>
                        </svg>
                    </div>
                </div>
            </div>
        </div>


<?php include '../includes/footer.php'; ?>