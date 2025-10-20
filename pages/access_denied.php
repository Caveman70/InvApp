<?php
/**
 * access_denied.php
 *
 * Landing page when user lacks required permissions.
 */


require_once __DIR__ . '/../includes/init.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$error = ''; // Initialize error variable
$success = '';

?>


<?php include '../includes/header.php'; ?>

 <!--Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-3">
            <div class="bg-white shadow-md p-6">
                <div class="mx-auto text-center mb-6">
                    <h1 class="text-3xl font-bold text-red-600 mb-4">Access Denied</h1>
                    <p class="text-gray-600 mb-6">You do not have permission to access this page.</p>
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="dashboard.php" class="inline-block p-3 bg-blue-500 text-white rounded-md hover:bg-blue-700 transition duration-200">Back to Dashboard</a>
                        <?php else: ?>
                            <a href="../login.php" class="inline-block p-3 bg-blue-500 text-white rounded-md hover:bg-blue-700 transition duration-200">Back to Login</a>
                        <?php endif; ?>
                </div>
                <!-- put table here -->
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>