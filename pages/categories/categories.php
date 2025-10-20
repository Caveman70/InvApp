<?php
/**
 * categories.php
 *
 * Manage inventory item categories and subcategories, shows links based on permissions.
 */

$required_permission = 'manage_categories';
require_once __DIR__ . '/../../includes/init.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$error = ''; // Initialize error variable
$success = '';

$pdo = getPDO();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
        // Add new category
        try {
            $name = trim($_POST['name']);
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $description = trim($_POST['description']);
            
            $insert_sql = "INSERT INTO categories (name, parent_id, description) VALUES (:name, :parent_id, :description)";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                ':name' => $name,
                ':parent_id' => $parent_id,
                ':description' => $description
            ]);
            
            $success = "Category added successfully!";
        } catch (PDOException $e) {
            $error = "Error adding category: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'edit_category') {
        // Edit existing category
        try {
            $category_id = (int)$_POST['category_id'];
            $name = trim($_POST['name']);
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $description = trim($_POST['description']);
            
            // Prevent setting parent_id to self or creating circular references
            if ($parent_id == $category_id) {
                $error = "A category cannot be its own parent.";
            } else {
                $update_sql = "UPDATE categories SET name = :name, parent_id = :parent_id, description = :description WHERE id = :id";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([
                    ':name' => $name,
                    ':parent_id' => $parent_id,
                    ':description' => $description,
                    ':id' => $category_id
                ]);
                
                $success = "Category updated successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error updating category: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'deactivate_category') {
        // Deactivate category (soft delete)
        try {
            $category_id = (int)$_POST['category_id'];
            
            $update_sql = "UPDATE categories SET is_active = 0 WHERE id = :id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':id' => $category_id]);
            
            // Also deactivate all child categories
            $update_children_sql = "UPDATE categories SET is_active = 0 WHERE parent_id = :parent_id";
            $stmt = $pdo->prepare($update_children_sql);
            $stmt->execute([':parent_id' => $category_id]);
            
            $success = "Category deactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error deactivating category: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'reactivate_category') {
        // Reactivate category
        try {
            $category_id = (int)$_POST['category_id'];
            
            $update_sql = "UPDATE categories SET is_active = 1 WHERE id = :id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':id' => $category_id]);
            
            $success = "Category reactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error reactivating category: " . $e->getMessage();
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF'] . ($success ? "?success=" . urlencode($success) : ($error ? "?error=" . urlencode($error) : "")));
    exit;
}

// Handle success/error messages from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Handle show/hide inactive functionality
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

// Function to build category tree
function buildCategoryTree($categories, $parent_id = null, $level = 0) {
    $tree = [];
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $category['level'] = $level;
            $tree[] = $category;
            $children = buildCategoryTree($categories, $category['id'], $level + 1);
            $tree = array_merge($tree, $children);
        }
    }
    return $tree;
}

if ($show_inactive) {
    // Show all categories
    $query = "SELECT * FROM categories ORDER BY is_active DESC, name ASC";
} else {
    // Show only active categories
    $query = "SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC";
}

$stmt = $pdo->prepare($query);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build hierarchical tree
$category_tree = buildCategoryTree($categories);

// Get count of inactive categories for the toggle button
$inactive_categories_query = "SELECT COUNT(*) as count FROM categories WHERE is_active = 0";
$inactive_categories_stmt = $pdo->prepare($inactive_categories_query);
$inactive_categories_stmt->execute();
$inactive_categories_count = $inactive_categories_stmt->fetch()['count'];

// Get all active categories for parent selection in forms
$parent_options_query = "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC";
$parent_options_stmt = $pdo->prepare($parent_options_query);
$parent_options_stmt->execute();
$parent_options = $parent_options_stmt->fetchAll(PDO::FETCH_ASSOC);

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

 <!--Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-3">
            <div class="bg-white shadow-md p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Manage Inventory Item Categories</h3>
                    <div class="flex items-center gap-4 mb-4">
                        <?php if ($inactive_categories_count > 0): ?>
                            <a href="?show_inactive=<?= $show_inactive ? '0' : '1' ?>" 
                            class="text-sm text-gray-600 hover:text-gray-800 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                </svg>
                                <?= $show_inactive 
                                    ? 'Hide Inactive Items' 
                                    : "Show Inactive Items ($inactive_categories_count categories)" ?>
                            </a>
                        <?php endif; ?>
                        <button id="addUserBtn" class="ml-auto mr-4 bg-blue-500 text-white px-4 py-2 text-sm hover:bg-blue-600 transition-all duration-200">
                            Add Category
                        </button>
                    </div>

                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parent Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($category_tree)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        <?= $show_inactive ? 'No categories found.' : 'No categories found.' ?>
                                        <?php if (!$show_inactive): ?>
                                            <a href="#" id="addFirstCategory" class="text-blue-500 hover:text-blue-700">Add your first category</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($category_tree as $category): ?>
                                    <?php 
                                    $level = $category['level'];
                                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                                    $is_parent = $level == 0;
                                    ?>
                                    <tr class="<?= $category['is_active'] ? ($is_parent ? 'bg-blue-50 border-l-4 border-blue-500' : 'bg-gray-50') : 'bg-red-50 border-l-4 border-red-400 opacity-75' ?>">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <?php if ($level > 0): ?>
                                                    <div class="w-<?= $level * 8 ?>"></div> <!-- Indent spacer -->
                                                <?php endif; ?>
                                                <div class="flex-shrink-0 w-4 h-4 mr-2">
                                                    <?php if ($is_parent): ?>
                                                        <svg class="w-4 h-4 <?= $category['is_active'] ? 'text-blue-600' : 'text-red-500' ?>" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    <?php else: ?>
                                                        <svg class="w-4 h-4 <?= $category['is_active'] ? 'text-gray-600' : 'text-red-500' ?>" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M3 4a1 1 0 000 2h11.586l-2.293 2.293a1 1 0 101.414 1.414L18.414 5.414A2 2 0 0017 2H3a1 1 0 000 2z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm <?= $is_parent ? 'font-bold' : '' ?> text-gray-900">
                                                    <?= $indent ?><?php echo htmlspecialchars($category['name']); ?>
                                                    <?php if (!$category['is_active']): ?>
                                                        <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 rounded">Inactive</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($category['description'] ?: 'No description'); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php 
                                                if ($category['parent_id']) {
                                                    // Find parent name
                                                    $parent_name = 'Unknown';
                                                    foreach ($categories as $cat) {
                                                        if ($cat['id'] == $category['parent_id']) {
                                                            $parent_name = $cat['name'];
                                                            break;
                                                        }
                                                    }
                                                    echo htmlspecialchars($parent_name);
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($category['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                            <?php if ($category['is_active']): ?>
                                                <button class="text-blue-600 hover:text-blue-900 edit-category" data-category-id="<?php echo $category['id']; ?>">Edit</button>
                                                <button class="text-green-600 hover:text-green-900 add-subcategory" data-parent-id="<?php echo $category['id']; ?>">Add Subcategory</button>
                                                <button class="text-red-600 hover:text-red-900 deactivate-category" data-category-id="<?php echo $category['id']; ?>">Deactivate</button>
                                            <?php else: ?>
                                                <button class="text-green-600 hover:text-green-900 reactivate-category" data-category-id="<?php echo $category['id']; ?>">Reactivate</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<!-- Add Category Modal -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Add New Category</h4>
            <span class="close" data-modal="addCategoryModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_category">
                <input type="hidden" id="add_parent_id" name="parent_id">
                
                <div class="mb-4" id="parent_category_display" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Parent Category</label>
                    <div id="parent_category_name" class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-md text-gray-700"></div>
                </div>
                
                <div class="mb-4">
                    <label for="parent_id_select" class="block text-sm font-medium text-gray-700 mb-2">Parent Category (Optional)</label>
                    <select id="parent_id_select" name="parent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">None (Top Level Category)</option>
                        <?php foreach ($parent_options as $option): ?>
                            <option value="<?= $option['id'] ?>"><?= htmlspecialchars($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="add_category_name" class="block text-sm font-medium text-gray-700 mb-2">Category Name *</label>
                    <input type="text" id="add_category_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_category_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="add_category_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="addCategoryModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Save Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editCategoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Edit Category</h4>
            <span class="close" data-modal="editCategoryModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" id="edit_category_id" name="category_id">
                
                <div class="mb-4">
                    <label for="edit_parent_id" class="block text-sm font-medium text-gray-700 mb-2">Parent Category</label>
                    <select id="edit_parent_id" name="parent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">None (Top Level Category)</option>
                        <?php foreach ($parent_options as $option): ?>
                            <option value="<?= $option['id'] ?>"><?= htmlspecialchars($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="edit_category_name" class="block text-sm font-medium text-gray-700 mb-2">Category Name *</label>
                    <input type="text" id="edit_category_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_category_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="edit_category_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="editCategoryModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Deactivate Confirmation Modal -->
<div id="deactivateConfirmModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Confirm Deactivation</h4>
            <span class="close" data-modal="deactivateConfirmModal">&times;</span>
        </div>
        <div class="modal-body">
            <p class="text-gray-700 mb-4">Are you sure you want to deactivate this category?</p>
            <p class="text-sm text-amber-600">This will also deactivate all subcategories within this category. Categories will be hidden but preserved for historical data.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="deactivateConfirmModal">
                Cancel
            </button>
            <form method="POST" style="display: inline;" id="deactivateForm">
                <input type="hidden" name="action" value="deactivate_category">
                <input type="hidden" name="category_id" id="deactivate_category_id">
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-md">
                    Deactivate
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Modal functionality
document.addEventListener('DOMContentLoaded', function() {
    // Get modal elements
    const addCategoryModal = document.getElementById('addCategoryModal');
    const editCategoryModal = document.getElementById('editCategoryModal');
    const deactivateConfirmModal = document.getElementById('deactivateConfirmModal');
    
    // Get button elements
    const addUserBtn = document.getElementById('addUserBtn');
    
    // Open add category modal
    if (addUserBtn) {
        addUserBtn.addEventListener('click', function() {
            document.getElementById('parent_category_display').style.display = 'none';
            document.getElementById('parent_id_select').style.display = 'block';
            document.getElementById('add_parent_id').value = '';
            document.getElementById('parent_id_select').value = '';
            document.getElementById('add_category_name').value = '';
            document.getElementById('add_category_description').value = '';
            addCategoryModal.style.display = 'block';
        });
    }
    
    // Handle add subcategory buttons
    document.addEventListener('click', function(e) {
        const addSubBtn = e.target.closest('.add-subcategory');
        if (addSubBtn) {
            const parentId = addSubBtn.dataset.parentId;
            // Find parent category name
            const row = addSubBtn.closest('tr');
            //const parentName = row.querySelector('td:nth-child(1) div:nth-child(3)').textContent.trim();
            const parentName = row.querySelector('td:nth-child(1) .text-sm').textContent.trim();
            document.getElementById('add_parent_id').value = parentId;
            document.getElementById('parent_id_select').value = parentId;
            document.getElementById('parent_id_select').style.display = 'none';
            document.getElementById('parent_category_display').style.display = 'block';
            document.getElementById('parent_category_name').textContent = parentName;
            // Clear form
            document.getElementById('add_category_name').value = '';
            document.getElementById('add_category_description').value = '';
            addCategoryModal.style.display = 'block';
        }
    });
    
    // Handle edit category buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('edit-category')) {
            const categoryId = e.target.dataset.categoryId;
            // Get category data from table row
            const row = e.target.closest('tr');
            const categoryName = row.querySelector('td:nth-child(1) .text-sm').textContent.trim();
            //const nameElement = row.querySelector('td:nth-child(1) div:nth-child(3)');
            //const categoryName = nameElement.textContent.trim().replace(/^\s+/, '');
            const categoryDescription = row.querySelector('td:nth-child(2) div').textContent;
            const parentCategory = row.querySelector('td:nth-child(3)').textContent.trim();
            document.getElementById('edit_category_id').value = categoryId;
            document.getElementById('edit_category_name').value = categoryName;
            document.getElementById('edit_category_description').value = categoryDescription === 'No description' ? '' : categoryDescription;
            // Set parent category
            const parentSelect = document.getElementById('edit_parent_id');
            parentSelect.value = '';
            if (parentCategory !== '—') {
                for (let option of parentSelect.options) {
                    if (option.text === parentCategory) {
                        parentSelect.value = option.value;
                        break;
                    }
                }
            }
            editCategoryModal.style.display = 'block';
        }
    });
    
    // Handle deactivate buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('deactivate-category')) {
            const categoryId = e.target.dataset.categoryId;
            document.getElementById('deactivate_category_id').value = categoryId;
            deactivateConfirmModal.style.display = 'block';
        }
    });
    
    // Handle reactivate buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('reactivate-category')) {
            const categoryId = e.target.dataset.categoryId;
            
            // Create and submit form directly for reactivation
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reactivate_category">
                <input type="hidden" name="category_id" value="${categoryId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
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