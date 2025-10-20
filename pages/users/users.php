<?php
/**
 * users.php
 *
 * Manage users page, shows links based on permissions.
 */

$required_permission = 'manage_users';
require_once __DIR__ . '/../../includes/init.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$error = ''; // Initialize error variable
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
        // Add new user
        try {
            $new_username = trim($_POST['username']);
            $new_email = trim($_POST['email']);
            $new_password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
            $role_id = (int)$_POST['role_id'];
            
            // Insert user
            $insert_user_sql = "INSERT INTO users (username, email, password, is_active) VALUES (:username, :email, :password, 1)";
            $insert_user_stmt = $pdo->prepare($insert_user_sql);
            $insert_user_stmt->execute([
                ':username' => $new_username,
                ':email' => $new_email,
                ':password' => $new_password
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // Assign role
            $insert_role_sql = "INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)";
            $insert_role_stmt = $pdo->prepare($insert_role_sql);
            $insert_role_stmt->execute([
                ':user_id' => $user_id,
                ':role_id' => $role_id
            ]);
            
            $success = "User added successfully!";
        } catch (PDOException $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        // Edit existing user
        try {
            $user_id = (int)$_POST['user_id'];
            $edit_username = trim($_POST['username']);
            $edit_email = trim($_POST['email']);
            $role_id = (int)$_POST['role_id'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Update user
            $update_user_sql = "UPDATE users SET username = :username, email = :email, is_active = :is_active WHERE id = :id";
            $update_user_stmt = $pdo->prepare($update_user_sql);
            $update_user_stmt->execute([
                ':username' => $edit_username,
                ':email' => $edit_email,
                ':is_active' => $is_active,
                ':id' => $user_id
            ]);
            
            // Update password if provided
            if (!empty(trim($_POST['password']))) {
                $new_password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
                $update_password_sql = "UPDATE users SET password = :password WHERE id = :id";
                $update_password_stmt = $pdo->prepare($update_password_sql);
                $update_password_stmt->execute([
                    ':password' => $new_password,
                    ':id' => $user_id
                ]);
            }
            
            // Update role
            $delete_role_sql = "DELETE FROM user_roles WHERE user_id = :user_id";
            $delete_role_stmt = $pdo->prepare($delete_role_sql);
            $delete_role_stmt->execute([':user_id' => $user_id]);
            
            $insert_role_sql = "INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)";
            $insert_role_stmt = $pdo->prepare($insert_role_sql);
            $insert_role_stmt->execute([
                ':user_id' => $user_id,
                ':role_id' => $role_id
            ]);
            
            $success = "User updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    }
}

// Fetch all users and roles
try {
    $pdo = getPDO();
    $users_sql = "SELECT id, username, email, is_active FROM users";
    $users_stmt = $pdo->query($users_sql);
    $users = $users_stmt->fetchAll();

    $roles_sql = "SELECT id, role_name FROM roles";
    $roles_stmt = $pdo->query($roles_sql);
    $roles = $roles_stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

?>

<?php include '../../includes/header.php'; ?>

<?php if ($error): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

 <!-- Main Dashboard Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Chart Section -->
        <div class="lg:col-span-3">
            <div class="bg-white shadow-md p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Manage Users</h3>
                    <button id="addUserBtn" class="bg-blue-500 text-white px-4 py-2 text-sm hover:bg-blue-600 transition-all duration-200">
                        Add User
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $user):
                                $pdo = getPDO();
                                $role_sql = "SELECT r.id, r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = :user_id";
                                $role_stmt = $pdo->prepare($role_sql);
                                $role_stmt->execute([':user_id' => $user['id']]);
                                $user_role = $role_stmt->fetch();
                                $color = "slate"; // Default color
                                if ($user_role['role_name'] === 'Admin') {
                                    $color = "blue";
                                } elseif ($user_role['role_name'] === 'Manager') {
                                    $color = "yellow";
                                }
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold bg-<?= $color ?>-100 text-<?= $color ?>-800"><?php echo htmlspecialchars($user_role['role_name']); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button class="editUserBtn text-blue-600 hover:text-blue-900" 
                                            data-user-id="<?= $user['id'] ?>"
                                            data-username="<?= htmlspecialchars($user['username']) ?>"
                                            data-email="<?= htmlspecialchars($user['email']) ?>"
                                            data-role-id="<?= $user_role['id'] ?>"
                                            data-is-active="<?= $user['is_active'] ?>">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Add New User</h4>
            <span class="close" data-modal="addUserModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">
                
                <div class="mb-4">
                    <label for="add_username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                    <input type="text" id="add_username" name="username" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                    <input type="email" id="add_email" name="email" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                    <input type="password" id="add_password" name="password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_role_id" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                    <select id="add_role_id" name="role_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Select a role</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="addUserModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Save User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Edit User</h4>
            <span class="close" data-modal="editUserModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="edit_user_id" name="user_id">
                
                <div class="mb-4">
                    <label for="edit_username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                    <input type="text" id="edit_username" name="username" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                    <input type="email" id="edit_email" name="email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_password" class="block text-sm font-medium text-gray-700 mb-2">Password (leave blank to keep current)</label>
                    <input type="password" id="edit_password" name="password" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_role_id" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                    <select id="edit_role_id" name="role_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" id="edit_is_active" name="is_active" class="mr-2">
                        <span class="text-sm font-medium text-gray-700">Active</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="editUserModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal functionality
document.addEventListener('DOMContentLoaded', function() {
    // Get modal elements
    const addUserModal = document.getElementById('addUserModal');
    const editUserModal = document.getElementById('editUserModal');
    const addUserBtn = document.getElementById('addUserBtn');
    const editUserBtns = document.querySelectorAll('.editUserBtn');
    
    // Open add user modal
    addUserBtn.addEventListener('click', function() {
        addUserModal.style.display = 'block';
    });
    
    // Open edit user modal
    editUserBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.dataset.userId;
            const username = this.dataset.username;
            const email = this.dataset.email;
            const roleId = this.dataset.roleId;
            const isActive = this.dataset.isActive === '1';
            
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_role_id').value = roleId;
            document.getElementById('edit_is_active').checked = isActive;
            
            editUserModal.style.display = 'block';
        });
    });
    
    // Close modals
    document.querySelectorAll('.close, .close-modal').forEach(closeBtn => {
        closeBtn.addEventListener('click', function() {
            const modalId = this.dataset.modal;
            if (modalId) {
                document.getElementById(modalId).style.display = 'none';
            } else {
                this.closest('.modal').style.display = 'none';
            }
        });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>