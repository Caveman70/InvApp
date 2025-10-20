<?php
/**
 * inventory.php
 *
 * Manage inventory items with location-based reorder thresholds.
 */
// Handle AJAX request for stock data FIRST
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_stock' && isset($_GET['item_id'])) {
    require_once __DIR__ . '/../../includes/init.php';
    $pdo = getPDO();
    
    header('Content-Type: application/json');
    $item_id = (int)$_GET['item_id'];
    
    // Function to get current stock quantities for an item including reorder thresholds
    $stock_sql = "SELECT l.location_id, s.quantity, s.reorder_threshold, si.name as site_name, l.name as location_name
                  FROM item_stocks s
                  JOIN locations l ON s.location_id = l.location_id
                  JOIN sites si ON l.site_id = si.site_id
                  WHERE s.item_id = :item_id";
    $stock_stmt = $pdo->prepare($stock_sql);
    $stock_stmt->execute([':item_id' => $item_id]);
    $stocks = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'stocks' => $stocks]);
    exit;
}

$required_permission = 'view_inventory';
require_once __DIR__ . '/../../includes/init.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$error = ''; // Initialize error variable
$success = '';

// Assume $_SESSION['user_id'] is set in init.php; if not, fetch it
if (!isset($_SESSION['user_id'])) {
    $user_sql = "SELECT id FROM users WHERE username = :username";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([':username' => $username]);
    $_SESSION['user_id'] = $user_stmt->fetchColumn();
}

$pdo = getPDO();

// Handle show/hide inactive functionality
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

// Handle success/error messages from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Fetch active categories hierarchically
$categories_sql = "SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY parent_id ASC, name ASC";
$categories_stmt = $pdo->query($categories_sql);
$raw_categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build category_map
$category_map = [];
foreach ($raw_categories as $cat) {
    $category_map[$cat['id']] = $cat;
    $category_map[$cat['id']]['children'] = [];
}

// Build children
foreach ($raw_categories as $cat) {
    if ($cat['parent_id'] !== null && isset($category_map[$cat['parent_id']])) {
        $category_map[$cat['parent_id']]['children'][] = $category_map[$cat['id']];
    }
}

// Fetch all active sites and locations for the modals
$sites_sql = "SELECT s.site_id, s.name as site_name, l.location_id, l.name as location_name 
              FROM sites s 
              LEFT JOIN locations l ON s.site_id = l.site_id 
              WHERE s.is_active = 1
              ORDER BY s.name, l.name";
$sites_stmt = $pdo->query($sites_sql);
$sites_data = $sites_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize sites and locations
$sites = [];
foreach ($sites_data as $row) {
    if (!isset($sites[$row['site_id']])) {
        $sites[$row['site_id']] = [
            'site_id' => $row['site_id'],
            'site_name' => $row['site_name'],
            'locations' => []
        ];
    }
    if ($row['location_id']) {
        $sites[$row['site_id']]['locations'][] = [
            'location_id' => $row['location_id'],
            'location_name' => $row['location_name']
        ];
    }
}

// Get count of inactive items for the toggle button
$inactive_items_query = "SELECT COUNT(*) as count FROM items WHERE is_active = 0";
$inactive_items_stmt = $pdo->prepare($inactive_items_query);
$inactive_items_stmt->execute();
$inactive_items_count = $inactive_items_stmt->fetch()['count'];

// Define item statuses (keeping original for other uses if needed)
$item_statuses = ['available', 'in_use', 'maintenance', 'damaged'];

// Function to calculate stock status with location-based reorder thresholds
function getStockStatus($total_quantity, $reorder_threshold, $full_quantity = null, $location_stocks = []) {
    // Check if any location is below its specific reorder threshold
    $low_stock_locations = [];
    $no_stock_locations = [];
    
    foreach ($location_stocks as $stock) {
        if ($stock['quantity'] == 0) {
            $no_stock_locations[] = $stock['location_name'];
        } elseif ($stock['quantity'] < $stock['reorder_threshold'] && $stock['reorder_threshold'] > 0) {
            $low_stock_locations[] = $stock['location_name'];
        }
    }
    
    // Priority: No stock anywhere
    if ($total_quantity == 0) {
        return ['status' => 'No Stock', 'color' => 'red', 'details' => 'No stock at any location'];
    }
    
    // Priority: Some locations have no stock
    if (!empty($no_stock_locations)) {
        $details = 'No stock at: ' . implode(', ', $no_stock_locations);
        return ['status' => 'Critical', 'color' => 'red', 'details' => $details];
    }
    
    // Priority: Some locations are below reorder threshold
    if (!empty($low_stock_locations)) {
        $details = 'Low stock at: ' . implode(', ', $low_stock_locations);
        return ['status' => 'Low Stock', 'color' => 'yellow', 'details' => $details];
    }
    
    // Check overall stock levels
    if ($full_quantity && $total_quantity > $full_quantity) {
        return ['status' => 'Over Stock', 'color' => 'purple', 'details' => 'Total stock exceeds target'];
    } elseif ($full_quantity && $total_quantity == $full_quantity) {
        return ['status' => 'Full Stock', 'color' => 'blue', 'details' => 'At target stock level'];
    } else {
        return ['status' => 'Ok Stock', 'color' => 'green', 'details' => 'All locations adequately stocked'];
    }
}

// Function to get all descendant category IDs (including the parent)
function getAllDescendantCategoryIds($category_map, $parent_id) {
    $category_ids = [$parent_id];
    
    foreach ($category_map as $cat) {
        if ($cat['parent_id'] == $parent_id) {
            $category_ids = array_merge($category_ids, getAllDescendantCategoryIds($category_map, $cat['id']));
        }
    }
    
    return $category_ids;
}

// Function to render category options hierarchically
function renderCategoryOptions($category_map, $parent_id = null, $prefix = '', $seen_ids = [], $selected_id = 0) {
    $options = '';
    foreach ($category_map as $cat) {
        if ($cat['parent_id'] === $parent_id && !in_array($cat['id'], $seen_ids)) {
            $seen_ids[] = $cat['id'];
            $selected = ($cat['id'] == $selected_id) ? 'selected' : '';
            $options .= '<option value="' . $cat['id'] . '" ' . $selected . '>' . htmlspecialchars($prefix . $cat['name']) . '</option>';
            if (!empty($cat['children'])) {
                $options .= renderCategoryOptions($category_map, $cat['id'], $prefix . '— ', $seen_ids, $selected_id);
            }
        }
    }
    return $options;
}

// Function to render location options hierarchically (Site > Location)
function renderLocationOptions($sites, $selected_location_id = 0) {
    $options = '';
    foreach ($sites as $site) {
        // Add site as optgroup
        $options .= '<optgroup label="' . htmlspecialchars($site['site_name']) . '">';
        
        // Add locations under this site
        foreach ($site['locations'] as $location) {
            $selected = ($location['location_id'] == $selected_location_id) ? 'selected' : '';
            $options .= '<option value="' . $location['location_id'] . '" ' . $selected . '>' 
                     . htmlspecialchars('  ' . $location['location_name']) . '</option>';
        }
        
        $options .= '</optgroup>';
    }
    return $options;
}

// Function to get current stock quantities for an item including reorder thresholds
function getItemStockQuantities($pdo, $item_id) {
    $stock_sql = "SELECT l.location_id, s.quantity, s.reorder_threshold, si.name as site_name, l.name as location_name
                  FROM item_stocks s
                  JOIN locations l ON s.location_id = l.location_id
                  JOIN sites si ON l.site_id = si.site_id
                  WHERE s.item_id = :item_id";
    $stock_stmt = $pdo->prepare($stock_sql);
    $stock_stmt->execute([':item_id' => $item_id]);
    return $stock_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'add_item') {
            // Add new item
            $fields = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'category_id' => (int)$_POST['category_id'] ?? 0,
                'sku' => trim($_POST['sku'] ?? ''),
                'unit_cost' => (float)$_POST['unit_cost'] ?? 0.00,
                'reorder_threshold' => (int)$_POST['reorder_threshold'] ?? 0, // Keep for backward compatibility
                'full_quantity' => (int)$_POST['full_quantity'] ?? 0,
                'supplier_info' => trim($_POST['supplier_info'] ?? ''),
                'part_number' => trim($_POST['part_number'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            if (empty($fields['name']) || $fields['category_id'] === 0) {
                throw new Exception("Name and category are required.");
            }
            
            $fields['created_by'] = $_SESSION['user_id'];
            $fields['updated_by'] = $_SESSION['user_id'];
            
            // Start transaction
            $pdo->beginTransaction();
            
            $insert_sql = "INSERT INTO items (name, description, category_id, sku, unit_cost, reorder_threshold, full_quantity, supplier_info, part_number, is_active, created_by, updated_by) 
                              VALUES (:name, :description, :category_id, :sku, :unit_cost, :reorder_threshold, :full_quantity, :supplier_info, :part_number, :is_active, :created_by, :updated_by)";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute($fields);
            
            $item_id = $pdo->lastInsertId();
            
            // Handle location quantities and reorder thresholds
            if (isset($_POST['location_quantities'])) {
                foreach ($_POST['location_quantities'] as $location_id => $quantity) {
                    $quantity = (float)$quantity;
                    $reorder_threshold = (int)($_POST['location_reorder_thresholds'][$location_id] ?? 0);
                    
                    if ($quantity > 0 || $reorder_threshold > 0) {
                        $stock_sql = "INSERT INTO item_stocks (item_id, location_id, quantity, reorder_threshold) VALUES (:item_id, :location_id, :quantity, :reorder_threshold)";
                        $stock_stmt = $pdo->prepare($stock_sql);
                        $stock_stmt->execute([
                            ':item_id' => $item_id,
                            ':location_id' => $location_id,
                            ':quantity' => $quantity,
                            ':reorder_threshold' => $reorder_threshold
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            
            // Log the creation in item_history
            $history_details = json_encode([
                'action' => 'Item created',
                'item_data' => [
                    'name' => $fields['name'],
                    'description' => $fields['description'],
                    'category_id' => $fields['category_id'],
                    'sku' => $fields['sku'],
                    'unit_cost' => $fields['unit_cost'],
                    'reorder_threshold' => $fields['reorder_threshold'],
                    'supplier_info' => $fields['supplier_info'],
                    'part_number' => $fields['part_number'],
                    'is_active' => $fields['is_active']
                ],
                'location_quantities' => $_POST['location_quantities'] ?? [],
                'location_reorder_thresholds' => $_POST['location_reorder_thresholds'] ?? []
            ]);
            
            $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) VALUES (:item_id, :action_type, :details, :performed_by)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([
                ':item_id' => $item_id,
                ':action_type' => 'create',
                ':details' => $history_details,
                ':performed_by' => $_SESSION['user_id']
            ]);
            
            $success = "Item added successfully with location-specific reorder thresholds!";
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'edit_item') {
            // Edit existing item
            $item_id = (int)$_POST['item_id'] ?? 0;
            if ($item_id === 0) {
                throw new Exception("Invalid item ID.");
            }
            
            // Get the original item data for comparison
            $original_sql = "SELECT * FROM items WHERE id = :id";
            $original_stmt = $pdo->prepare($original_sql);
            $original_stmt->execute([':id' => $item_id]);
            $original_data = $original_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original_data) {
                throw new Exception("Item not found.");
            }
            
            // Get original stock quantities and reorder thresholds
            $original_stocks_sql = "SELECT location_id, quantity, reorder_threshold FROM item_stocks WHERE item_id = :item_id";
            $original_stocks_stmt = $pdo->prepare($original_stocks_sql);
            $original_stocks_stmt->execute([':item_id' => $item_id]);
            $original_stocks = [];
            while ($row = $original_stocks_stmt->fetch(PDO::FETCH_ASSOC)) {
                $original_stocks[$row['location_id']] = [
                    'quantity' => $row['quantity'],
                    'reorder_threshold' => $row['reorder_threshold']
                ];
            }
            
            $fields = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'category_id' => (int)$_POST['category_id'] ?? 0,
                'sku' => trim($_POST['sku'] ?? ''),
                'unit_cost' => (float)$_POST['unit_cost'] ?? 0.00,
                'reorder_threshold' => (int)$_POST['reorder_threshold'] ?? 0, // Keep for backward compatibility
                'full_quantity' => (int)$_POST['full_quantity'] ?? 0,
                'supplier_info' => trim($_POST['supplier_info'] ?? ''),
                'part_number' => trim($_POST['part_number'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'updated_by' => $_SESSION['user_id'],
                'id' => $item_id
            ];
            
            if (empty($fields['name']) || $fields['category_id'] === 0) {
                throw new Exception("Name and category are required.");
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            $update_sql = "UPDATE items SET name = :name, description = :description, category_id = :category_id, sku = :sku, 
                              unit_cost = :unit_cost, reorder_threshold = :reorder_threshold, full_quantity = :full_quantity, supplier_info = :supplier_info, 
                              part_number = :part_number, is_active = :is_active, updated_by = :updated_by WHERE id = :id";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute($fields);
            
            // Update location quantities and reorder thresholds
            if (isset($_POST['location_quantities'])) {
                // First, delete existing stock records for this item
                $delete_stocks_sql = "DELETE FROM item_stocks WHERE item_id = :item_id";
                $delete_stocks_stmt = $pdo->prepare($delete_stocks_sql);
                $delete_stocks_stmt->execute([':item_id' => $item_id]);
                
                // Insert new stock records with reorder thresholds
                foreach ($_POST['location_quantities'] as $location_id => $quantity) {
                    $quantity = (float)$quantity;
                    $reorder_threshold = (int)($_POST['location_reorder_thresholds'][$location_id] ?? 0);
                    
                    if ($quantity > 0 || $reorder_threshold > 0) {
                        $stock_sql = "INSERT INTO item_stocks (item_id, location_id, quantity, reorder_threshold) VALUES (:item_id, :location_id, :quantity, :reorder_threshold)";
                        $stock_stmt = $pdo->prepare($stock_sql);
                        $stock_stmt->execute([
                            ':item_id' => $item_id,
                            ':location_id' => $location_id,
                            ':quantity' => $quantity,
                            ':reorder_threshold' => $reorder_threshold
                        ]);
                    }
                }
            }
            
            $pdo->commit();
            
            // Determine what changed for history logging
            $changes = [];
            $new_location_quantities = $_POST['location_quantities'] ?? [];
            $new_location_thresholds = $_POST['location_reorder_thresholds'] ?? [];
            
            // Check item field changes
            foreach ($fields as $key => $value) {
                if ($key === 'id' || $key === 'updated_by') continue;
                if ($original_data[$key] != $value) {
                    $changes['item_data'][$key] = [
                        'old' => $original_data[$key],
                        'new' => $value
                    ];
                }
            }
            
            // Check stock quantity and threshold changes
            $stock_changes = [];
            // Check for changes in existing locations
            foreach ($original_stocks as $location_id => $old_data) {
                $new_qty = (float)($new_location_quantities[$location_id] ?? 0);
                $new_threshold = (int)($new_location_thresholds[$location_id] ?? 0);
                
                if ($old_data['quantity'] != $new_qty || $old_data['reorder_threshold'] != $new_threshold) {
                    $stock_changes[$location_id] = [
                        'old' => $old_data,
                        'new' => [
                            'quantity' => $new_qty,
                            'reorder_threshold' => $new_threshold
                        ]
                    ];
                }
            }
            
            // Check for new locations with quantities or thresholds
            foreach ($new_location_quantities as $location_id => $new_qty) {
                $new_qty = (float)$new_qty;
                $new_threshold = (int)($new_location_thresholds[$location_id] ?? 0);
                
                if (!isset($original_stocks[$location_id]) && ($new_qty > 0 || $new_threshold > 0)) {
                    $stock_changes[$location_id] = [
                        'old' => ['quantity' => 0, 'reorder_threshold' => 0],
                        'new' => [
                            'quantity' => $new_qty,
                            'reorder_threshold' => $new_threshold
                        ]
                    ];
                }
            }
            
            if (!empty($stock_changes)) {
                $changes['stock_data'] = $stock_changes;
            }
            
            // Log the update in item_history if there were changes
            if (!empty($changes)) {
                $history_details = json_encode([
                    'action' => 'Item updated',
                    'changes' => $changes
                ]);
                
                $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) VALUES (:item_id, :action_type, :details, :performed_by)";
                $history_stmt = $pdo->prepare($history_sql);
                $history_stmt->execute([
                    ':item_id' => $item_id,
                    ':action_type' => 'update',
                    ':details' => $history_details,
                    ':performed_by' => $_SESSION['user_id']
                ]);
            }
            
            $success = "Item updated successfully with location-specific reorder thresholds!";
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'reactivate_item') {
            // Reactivate item (unchanged)
            $item_id = (int)$_POST['item_id'] ?? 0;
            if ($item_id === 0) {
                throw new Exception("Invalid item ID.");
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            $update_sql = "UPDATE items SET is_active = 1, updated_by = :updated_by WHERE id = :id";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $item_id
            ]);
            
            // Log the reactivation in item_history
            $history_details = json_encode([
                'action' => 'Item reactivated'
            ]);
            
            $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) VALUES (:item_id, :action_type, :details, :performed_by)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([
                ':item_id' => $item_id,
                ':action_type' => 'update',
                ':details' => $history_details,
                ':performed_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            $success = "Item reactivated successfully!";
            
            // Redirect to preserve state and prevent form resubmission
            $redirect_url = $_SERVER['PHP_SELF'];
            $params = [];
            if ($show_inactive) $params[] = 'show_inactive=1';
            if (!empty($params)) $redirect_url .= '?' . implode('&', $params);
            $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'success=' . urlencode($success);
            header("Location: $redirect_url");
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle filters
$where = [];
$params = [];
$search = '';
$filter_category_id = 0;
$filter_stock_status = '';
$filter_location_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'filter') {
    $search = trim($_POST['search'] ?? '');
    if ($search) {
        $where[] = "(i.name LIKE :search OR i.sku LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $filter_category_id = (int)$_POST['category_id'] ?? 0;
    if ($filter_category_id) {
        // Get all descendant categories including the selected one
        $category_ids = getAllDescendantCategoryIds($category_map, $filter_category_id);
        $placeholders = implode(',', array_map(function($index) { return ':category_' . $index; }, array_keys($category_ids)));
        $where[] = "i.category_id IN ($placeholders)";
        foreach ($category_ids as $index => $cat_id) {
            $params[':category_' . $index] = $cat_id;
        }
    }

    $filter_location_id = (int)$_POST['location_id'] ?? 0;
    if ($filter_location_id) {
        $where[] = "s.location_id = :location_id";
        $params[':location_id'] = $filter_location_id;
    }
    
    $filter_stock_status = $_POST['stock_status'] ?? '';
    // Stock status filtering will be handled after we fetch the data since it's calculated
}

// Add is_active filter
if (!$show_inactive) {
    $where[] = "i.is_active = 1";
}

$where_sql = '';
if ($where) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Fetch items with enhanced location breakdown including reorder thresholds
$items_sql = "SELECT i.*, c.name as category_name,
              (SELECT SUM(s2.quantity) FROM item_stocks s2 WHERE s2.item_id = i.id) as total_quantity,
              GROUP_CONCAT(
                  CONCAT(si.name, ': ', COALESCE(s.quantity, 0), 
                         CASE WHEN s.reorder_threshold > 0 THEN CONCAT(' (min: ', s.reorder_threshold, ')') ELSE '' END
                  )
                  ORDER BY si.name SEPARATOR ', '
              ) as site_quantities
              FROM items i 
              LEFT JOIN categories c ON i.category_id = c.id 
              LEFT JOIN item_stocks s ON i.id = s.item_id
              LEFT JOIN locations l ON s.location_id = l.location_id
              LEFT JOIN sites si ON l.site_id = si.site_id AND si.is_active = 1
              $where_sql 
              GROUP BY i.id
              ORDER BY i.is_active DESC, i.name";
              
$items_stmt = $pdo->prepare($items_sql);
$items_stmt->execute($params);
$all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter by stock status if specified
$items = [];
foreach ($all_items as $item) {
    // Get detailed location stocks for status calculation
    $location_stocks = getItemStockQuantities($pdo, $item['id']);
    $stock_status = getStockStatus($item['total_quantity'] ?? 0, $item['reorder_threshold'] ?? 0, $item['full_quantity'] ?? null, $location_stocks);
    
    // Apply stock status filter if set
    if ($filter_stock_status && $stock_status['status'] !== $filter_stock_status) {
        continue;
    }
    
    // Add stock status to item for display
    $item['stock_status'] = $stock_status;
    $item['location_stocks'] = $location_stocks;
    $items[] = $item;
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

<!--Main Content -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-3">
        <div class="bg-white shadow-md p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Manage Inventory Items</h3>
                <div class="flex items-center gap-4">
                    <?php if ($inactive_items_count > 0): ?>
                        <a href="?show_inactive=<?= $show_inactive ? '0' : '1' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= $filter_category_id ? '&category_id=' . $filter_category_id : '' ?><?= $filter_location_id ? '&location_id=' . $filter_location_id : '' ?><?= $filter_stock_status ? '&stock_status=' . urlencode($filter_stock_status) : '' ?>" 
                           class="text-sm text-gray-600 hover:text-gray-800 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            <?= $show_inactive 
                                ? 'Hide Inactive Items' 
                                : "Show Inactive Items ($inactive_items_count items)" ?>
                        </a>
                    <?php endif; ?>
                    <?php if (in_array('manage_inventory', $permissions)): ?>
                        <button id="addItemBtn" class="bg-blue-500 text-white px-4 py-2 text-sm hover:bg-blue-600 transition-all duration-200">
                            Add Item
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-6">
                <form method="POST" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="action" value="filter">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or SKU" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                        <select id="category_id" name="category_id" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Categories</option>
                            <?php echo renderCategoryOptions($category_map, null, '', [], $filter_category_id); ?>
                        </select>
                    </div>
                    <!-- ADD this new location filter dropdown -->
                    <div>
                        <label for="location_id" class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                        <select id="location_id" name="location_id" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Locations</option>
                            <?php echo renderLocationOptions($sites, $filter_location_id); ?>
                        </select>
                    </div>
                    <div>
                        <label for="stock_status" class="block text-sm font-medium text-gray-700 mb-2">Stock Status</label>
                        <select id="stock_status" name="stock_status" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Stock Levels</option>
                            <option value="No Stock" <?php echo ($filter_stock_status === 'No Stock') ? 'selected' : ''; ?>>No Stock</option>
                            <option value="Critical" <?php echo ($filter_stock_status === 'Critical') ? 'selected' : ''; ?>>Critical</option>
                            <option value="Low Stock" <?php echo ($filter_stock_status === 'Low Stock') ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="Ok Stock" <?php echo ($filter_stock_status === 'Ok Stock') ? 'selected' : ''; ?>>Ok Stock</option>
                            <option value="Full Stock" <?php echo ($filter_stock_status === 'Full Stock') ? 'selected' : ''; ?>>Full Stock</option>
                            <option value="Over Stock" <?php echo ($filter_stock_status === 'Over Stock') ? 'selected' : ''; ?>>Over Stock</option>
                        </select>
                    </div>
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">Filter</button>
                    <div class="ml-auto">
                        <button type="button" id="printBtn" class="bg-sky-500 text-white px-4 py-2 rounded-md hover:bg-sky-600 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm-6 8a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4z" clip-rule="evenodd"></path>
                            </svg>
                            Print
                        </button>
                    </div>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table id="inventoryTable" class="min-w-full bg-white">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Site Distribution</th>
                            <?php if (in_array('manage_inventory', $permissions)): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($items as $item):
                            $stock_status = $item['stock_status'];
                            $is_active = $item['is_active'] ?? 1;
                        ?>
                        <tr class="<?= $is_active ? 'hover:bg-gray-50' : 'bg-red-50 border-l-4 border-red-400 opacity-75' ?>">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($item['name']); ?>
                                <?php if (!$is_active): ?>
                                    <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 rounded">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold bg-<?php echo $stock_status['color']; ?>-100 text-<?php echo $stock_status['color']; ?>-800" 
                                      title="<?php echo htmlspecialchars($stock_status['details']); ?>">
                                    <?php echo $stock_status['status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-semibold"><?php echo $item['total_quantity'] ?? 0; ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <div class="max-w-xs">
                                    <?php if ($item['site_quantities']): ?>
                                        <span class="text-xs"><?php echo htmlspecialchars($item['site_quantities']); ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">No stock assigned</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if (in_array('manage_inventory', $permissions)): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if ($is_active): ?>
                                        <button class="editItemBtn text-blue-600 hover:text-blue-900" 
                                                data-item-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                                data-description="<?php echo htmlspecialchars($item['description'] ?? ''); ?>"
                                                data-category-id="<?php echo $item['category_id']; ?>"
                                                data-sku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
                                                data-unit-cost="<?php echo $item['unit_cost'] ?? 0.00; ?>"
                                                data-reorder-threshold="<?php echo $item['reorder_threshold'] ?? 0; ?>"
                                                data-supplier-info="<?php echo htmlspecialchars($item['supplier_info'] ?? ''); ?>"
                                                data-partnumber="<?php echo htmlspecialchars($item['part_number'] ?? ''); ?>"
                                                data-full-quantity="<?php echo $item['full_quantity'] ?? 0; ?>"
                                                data-is-active="<?php echo $item['is_active'] ?? 1; ?>">
                                            Edit
                                        </button>
                                    <?php else: ?>
                                        <button class="editItemBtn text-blue-600 hover:text-blue-900 mr-3" 
                                                data-item-id="<?php echo $item['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                                data-description="<?php echo htmlspecialchars($item['description'] ?? ''); ?>"
                                                data-category-id="<?php echo $item['category_id']; ?>"
                                                data-sku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
                                                data-unit-cost="<?php echo $item['unit_cost'] ?? 0.00; ?>"
                                                data-reorder-threshold="<?php echo $item['reorder_threshold'] ?? 0; ?>"
                                                data-supplier-info="<?php echo htmlspecialchars($item['supplier_info'] ?? ''); ?>"
                                                data-partnumber="<?php echo htmlspecialchars($item['part_number'] ?? ''); ?>"
                                                data-full-quantity="<?php echo $item['full_quantity'] ?? 0; ?>"
                                                data-is-active="<?php echo $item['is_active'] ?? 1; ?>">
                                            Edit
                                        </button>
                                        <button class="reactivate-item text-green-600 hover:text-green-900" 
                                                data-item-id="<?php echo $item['id']; ?>">
                                            Reactivate
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Add New Item</h4>
            <span class="close" data-modal="addItemModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_item">
                
                <div>
                    <label for="add_name" class="block text-sm font-medium text-gray-700 mb-2">Name</label>
                    <input type="text" id="add_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="full-width">
                    <label for="add_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="add_description" name="description" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div>
                    <label for="add_category_id" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                    <select id="add_category_id" name="category_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Select a category</option>
                        <?php echo renderCategoryOptions($category_map); ?>
                    </select>
                </div>
                
                <div>
                    <label for="add_sku" class="block text-sm font-medium text-gray-700 mb-2">SKU</label>
                    <input type="text" id="add_sku" name="sku" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="add_unit_cost" class="block text-sm font-medium text-gray-700 mb-2">Unit Cost</label>
                    <input type="number" id="add_unit_cost" name="unit_cost" step="0.01" min="0" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="add_reorder_threshold" class="block text-sm font-medium text-gray-700 mb-2">Global Reorder Threshold (Legacy)</label>
                    <input type="number" id="add_reorder_threshold" name="reorder_threshold" min="0" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <small class="text-gray-500">Use location-specific thresholds below instead</small>
                </div>

                <div>
                    <label for="add_full_quantity" class="block text-sm font-medium text-gray-700 mb-2">Full Stock Quantity</label>
                    <input type="number" id="add_full_quantity" name="full_quantity" min="0" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="add_supplier_info" class="block text-sm font-medium text-gray-700 mb-2">Supplier Info</label>
                    <input type="text" id="add_supplier_info" name="supplier_info" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="add_part_number" class="block text-sm font-medium text-gray-700 mb-2">Part Number</label>
                    <input type="text" id="add_part_number" name="part_number" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" id="add_is_active" name="is_active" checked 
                               class="mr-2 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <span class="text-sm font-medium text-gray-700">Active</span>
                    </label>
                </div>
                
                <div class="full-width">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quantity & Reorder Thresholds by Location</label>
                    <div class="location-grid">
                        <?php foreach ($sites as $site): ?>
                            <div class="site-section">
                                <div class="site-title"><?php echo htmlspecialchars($site['site_name']); ?></div>
                                <?php foreach ($site['locations'] as $location): ?>
                                    <div class="location-input-group">
                                        <div class="location-label"><?php echo htmlspecialchars($location['location_name']); ?>:</div>
                                        <div class="location-inputs">
                                            <div>
                                                <label for="add_loc_<?php echo $location['location_id']; ?>" class="text-xs text-gray-600">Quantity:</label>
                                                <input type="number" 
                                                       id="add_loc_<?php echo $location['location_id']; ?>"
                                                       name="location_quantities[<?php echo $location['location_id']; ?>]" 
                                                       min="0" step="0.01" value="0"
                                                       class="w-full">
                                            </div>
                                            <div>
                                                <label for="add_threshold_<?php echo $location['location_id']; ?>" class="text-xs text-gray-600">Min Qty:</label>
                                                <input type="number" 
                                                       id="add_threshold_<?php echo $location['location_id']; ?>"
                                                       name="location_reorder_thresholds[<?php echo $location['location_id']; ?>]" 
                                                       min="0" value="0"
                                                       class="w-full">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="addItemModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Save Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Edit Item</h4>
            <span class="close" data-modal="editItemModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" id="edit_item_id" name="item_id">
                
                <div>
                    <label for="edit_name" class="block text-sm font-medium text-gray-700 mb-2">Name</label>
                    <input type="text" id="edit_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="full-width">
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="edit_description" name="description" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div>
                    <label for="edit_category_id" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                    <select id="edit_category_id" name="category_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Select a category</option>
                        <?php echo renderCategoryOptions($category_map); ?>
                    </select>
                </div>
                
                <div>
                    <label for="edit_sku" class="block text-sm font-medium text-gray-700 mb-2">SKU</label>
                    <input type="text" id="edit_sku" name="sku" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="edit_unit_cost" class="block text-sm font-medium text-gray-700 mb-2">Unit Cost</label>
                    <input type="number" id="edit_unit_cost" name="unit_cost" step="0.01" min="0" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="edit_reorder_threshold" class="block text-sm font-medium text-gray-700 mb-2">Global Reorder Threshold (Legacy)</label>
                    <input type="number" id="edit_reorder_threshold" name="reorder_threshold" min="0" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <small class="text-gray-500">Use location-specific thresholds below instead</small>
                </div>

                <div>
                    <label for="edit_full_quantity" class="block text-sm font-medium text-gray-700 mb-2">Full Stock Quantity</label>
                    <input type="number" id="edit_full_quantity" name="full_quantity" min="0" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="edit_supplier_info" class="block text-sm font-medium text-gray-700 mb-2">Supplier Info</label>
                    <input type="text" id="edit_supplier_info" name="supplier_info" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label for="edit_part_number" class="block text-sm font-medium text-gray-700 mb-2">Part Number</label>
                    <input type="text" id="edit_part_number" name="part_number" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" id="edit_is_active" name="is_active" 
                               class="mr-2 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <span class="text-sm font-medium text-gray-700">Active</span>
                    </label>
                </div>
                
                <div class="full-width">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quantity & Reorder Thresholds by Location</label>
                    <div class="location-grid" id="edit-location-grid">
                        <?php foreach ($sites as $site): ?>
                            <div class="site-section">
                                <div class="site-title"><?php echo htmlspecialchars($site['site_name']); ?></div>
                                <?php foreach ($site['locations'] as $location): ?>
                                    <div class="location-input-group">
                                        <div class="location-label"><?php echo htmlspecialchars($location['location_name']); ?>:</div>
                                        <div class="location-inputs">
                                            <div>
                                                <label for="edit_loc_<?php echo $location['location_id']; ?>" class="text-xs text-gray-600">Quantity:</label>
                                                <input type="number" 
                                                       id="edit_loc_<?php echo $location['location_id']; ?>"
                                                       name="location_quantities[<?php echo $location['location_id']; ?>]" 
                                                       min="0" step="0.01" value="0"
                                                       class="w-full">
                                            </div>
                                            <div>
                                                <label for="edit_threshold_<?php echo $location['location_id']; ?>" class="text-xs text-gray-600">Min Qty:</label>
                                                <input type="number" 
                                                       id="edit_threshold_<?php echo $location['location_id']; ?>"
                                                       name="location_reorder_thresholds[<?php echo $location['location_id']; ?>]" 
                                                       min="0" value="0"
                                                       class="w-full">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="editItemModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update Item
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.modal-content {
    background-color: #fefefe;
    margin: 2% auto;
    padding: 0;
    border: 1px solid #888;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}

.modal-body {
    padding: 20px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.modal-body .full-width {
    grid-column: 1 / -1;
}

.modal-footer {
    padding: 20px;
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    text-align: right;
    border-radius: 0 0 8px 8px;
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover,
.close:focus {
    color: black;
    text-decoration: none;
}

.location-grid {
    display: grid;
    gap: 20px;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    padding: 15px;
    border-radius: 6px;
    background-color: #f8fafc;
}

.site-section {
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 10px;
    background-color: white;
}

.site-title {
    font-weight: bold;
    font-size: 14px;
    color: #2d3748;
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 1px solid #e2e8f0;
}

.location-input-group {
    margin-bottom: 10px;
    padding: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background-color: #f7fafc;
}

.location-label {
    font-weight: 500;
    font-size: 12px;
    color: #4a5568;
    margin-bottom: 5px;
}

.location-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.location-inputs input {
    padding: 4px 8px;
    border: 1px solid #cbd5e0;
    border-radius: 3px;
    font-size: 12px;
}

.location-inputs label {
    font-size: 10px;
    color: #718096;
    margin-bottom: 2px;
    display: block;
}
</style>

<script>
// Modal functionality and print functionality
document.addEventListener('DOMContentLoaded', function() {
    // Get modal elements
    const addItemModal = document.getElementById('addItemModal');
    const editItemModal = document.getElementById('editItemModal');
    const addItemBtn = document.getElementById('addItemBtn');
    const editItemBtns = document.querySelectorAll('.editItemBtn');
    const printBtn = document.getElementById('printBtn');
    
    // Print functionality (updated to include location-specific thresholds)
    printBtn.addEventListener('click', function() {
        // Get the current filter information
        const currentFilters = [];
        const searchValue = document.getElementById('search').value.trim();
        const categorySelect = document.getElementById('category_id');
        const stockStatusSelect = document.getElementById('stock_status');
        
        if (searchValue) {
            currentFilters.push(`Search: "${searchValue}"`);
        }
        if (categorySelect.value) {
            const categoryText = categorySelect.options[categorySelect.selectedIndex].text;
            currentFilters.push(`Category: ${categoryText}`);
        }
        // ADD this for location filter:
        const locationSelect = document.getElementById('location_id');
        if (locationSelect.value) {
            const locationText = locationSelect.options[locationSelect.selectedIndex].text.trim();
            currentFilters.push(`Location: ${locationText}`);
        }
        if (stockStatusSelect.value) {
            currentFilters.push(`Stock Status: ${stockStatusSelect.value}`);
        }
        
        <?php if ($show_inactive): ?>
        currentFilters.push('Including Inactive Items');
        <?php endif; ?>
        
        // Create print window
        const printWindow = window.open('', '_blank');
        
        // Generate print HTML
        const printHTML = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Inventory Items Report</title>
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        font-size: 12px; 
                        margin: 20px;
                    }
                    h1 { 
                        text-align: center; 
                        margin-bottom: 10px;
                        font-size: 18px;
                    }
                    .filters {
                        text-align: center;
                        margin-bottom: 20px;
                        font-size: 11px;
                        color: #666;
                    }
                    .print-date {
                        text-align: center;
                        margin-bottom: 20px;
                        font-size: 10px;
                        color: #888;
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        margin-top: 10px;
                    }
                    th, td { 
                        border: 2px solid #ddd; 
                        padding: 6px; 
                        text-align: left; 
                        font-size: 10px;
                    }
                    th { 
                        background-color: #f5f5f5; 
                        font-weight: bold; 
                    }
                    .status-no-stock, .status-critical { 
                        background-color: #fee2e2; 
                        color: #991b1b; 
                        padding: 2px 4px; 
                        border-radius: 2px; 
                        font-size: 9px;
                    }
                    .status-low-stock { 
                        background-color: #fef3c7; 
                        color: #92400e; 
                        padding: 2px 4px; 
                        border-radius: 2px; 
                        font-size: 9px;
                    }
                    .status-ok-stock { 
                        background-color: #dcfce7; 
                        color: #166534; 
                        padding: 2px 4px; 
                        border-radius: 2px; 
                        font-size: 9px;
                    }
                    .status-full-stock { 
                        background-color: #dbeafe; 
                        color: #1e40af; 
                        padding: 2px 4px; 
                        border-radius: 2px; 
                        font-size: 9px;
                    }
                    .status-over-stock { 
                        background-color: #f3e8ff; 
                        color: #7c3aed; 
                        padding: 2px 4px; 
                        border-radius: 2px; 
                        font-size: 9px;
                    }
                    .inactive-item {
                        background-color: #fee2e2;
                        opacity: 0.7;
                    }
                    .inactive-badge {
                        background-color: #991b1b;
                        color: white;
                        padding: 1px 4px;
                        border-radius: 2px;
                        font-size: 8px;
                        margin-left: 5px;
                    }
                    @media print {
                        body { margin: 10px; }
                        table { font-size: 9px; }
                        th, td { padding: 4px; }
                    }
                </style>
            </head>
            <body>
                <h1>Inventory Items Report</h1>
                ${currentFilters.length > 0 ? 
                    `<div class="filters">Filters Applied: ${currentFilters.join(', ')}</div>` : 
                    '<div class="filters">All Items</div>'
                }
                <div class="print-date">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>SKU</th>
                            <th>Stock Status</th>
                            <th>Total Qty</th>
                            <th>Site Distribution</th>
                        </tr>
                    </thead>
                    <tbody>`;
        
        // Get table rows from current display
        const tableRows = document.querySelectorAll('#inventoryTable tbody tr');
        let tableRowsHTML = '';
        
        tableRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length > 0) {
                const isInactive = row.innerHTML.includes('Inactive');
                
                let rowClass = isInactive ? 'inactive-item' : '';
                
                tableRowsHTML += `<tr class="${rowClass}">`;
                
                // Name (with inactive badge if applicable)
                let nameCell = cells[0].textContent.trim();
                if (isInactive && !nameCell.includes('Inactive')) {
                    nameCell += ' <span class="inactive-badge">Inactive</span>';
                }
                tableRowsHTML += `<td>${nameCell}</td>`;
                
                // Category
                tableRowsHTML += `<td>${cells[1].textContent.trim()}</td>`;
                
                // SKU
                tableRowsHTML += `<td>${cells[2].textContent.trim()}</td>`;
                
                // Stock Status (with proper styling)
                const stockStatusSpan = cells[3].querySelector('span');
                let stockStatusHTML = cells[3].textContent.trim();
                if (stockStatusSpan) {
                    const statusText = stockStatusSpan.textContent;
                    let statusClass = '';
                    if (statusText.includes('No Stock')) statusClass = 'status-no-stock';
                    else if (statusText.includes('Critical')) statusClass = 'status-critical';
                    else if (statusText.includes('Low Stock')) statusClass = 'status-low-stock';
                    else if (statusText.includes('Ok Stock')) statusClass = 'status-ok-stock';
                    else if (statusText.includes('Full Stock')) statusClass = 'status-full-stock';
                    else if (statusText.includes('Over Stock')) statusClass = 'status-over-stock';
                    
                    stockStatusHTML = `<span class="${statusClass}">${statusText}</span>`;
                }
                tableRowsHTML += `<td>${stockStatusHTML}</td>`;
                
                // Total Qty
                tableRowsHTML += `<td>${cells[4].textContent.trim()}</td>`;
                
                // Site Distribution
                tableRowsHTML += `<td>${cells[5].textContent.trim()}</td>`;
                
                tableRowsHTML += '</tr>';
            }
        });
        
        const finalHTML = printHTML + tableRowsHTML + `
                    </tbody>
                </table>
            </body>
            </html>`;
        
        printWindow.document.write(finalHTML);
        printWindow.document.close();
        
        // Wait a moment for content to load, then print
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    });
    
    // Open add item modal
    if (addItemBtn) {
        addItemBtn.addEventListener('click', function() {
            // Reset form and ensure Active checkbox is checked by default
            document.getElementById('add_is_active').checked = true;
            addItemModal.style.display = 'block';
        });
    }
    
    // Open edit item modal
    editItemBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId;
            const name = this.dataset.name;
            const description = this.dataset.description;
            const categoryId = this.dataset.categoryId;
            const sku = this.dataset.sku;
            const unitCost = this.dataset.unitCost;
            const reorderThreshold = this.dataset.reorderThreshold;
            const supplierInfo = this.dataset.supplierInfo;
            const partNumber = this.getAttribute('data-partnumber') || '';
            const isActive = this.getAttribute('data-is-active') === '1';
            const fullQuantity = this.getAttribute('data-full-quantity') || '0';
            
            // Fill basic item data
            document.getElementById('edit_item_id').value = itemId;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_category_id').value = categoryId;
            document.getElementById('edit_sku').value = sku;
            document.getElementById('edit_unit_cost').value = unitCost;
            document.getElementById('edit_reorder_threshold').value = reorderThreshold;
            document.getElementById('edit_supplier_info').value = supplierInfo;
            document.getElementById('edit_part_number').value = partNumber;
            document.getElementById('edit_full_quantity').value = fullQuantity;
            document.getElementById('edit_is_active').checked = isActive;
            
            // Reset all quantity and threshold inputs to 0
            const quantityInputs = document.querySelectorAll('#edit-location-grid input[type="number"]');
            quantityInputs.forEach(input => {
                input.value = 0;
            });
            
            // Show modal first, then load stock quantities and thresholds
            editItemModal.style.display = 'block';
            
            // Load current stock quantities and reorder thresholds via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('GET', window.location.pathname + '?ajax=get_stock&item_id=' + itemId, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            if (data.success && data.stocks) {
                                data.stocks.forEach(function(stock) {
                                    const quantityInput = document.getElementById('edit_loc_' + stock.location_id);
                                    const thresholdInput = document.getElementById('edit_threshold_' + stock.location_id);
                                    
                                    if (quantityInput) {
                                        quantityInput.value = stock.quantity;
                                    }
                                    if (thresholdInput) {
                                        thresholdInput.value = stock.reorder_threshold || 0;
                                    }
                                });
                            }
                        } catch (e) {
                            console.error('Error parsing stock data:', e);
                        }
                    } else {
                        console.error('AJAX request failed with status:', xhr.status);
                    }
                }
            };
            xhr.send();
        });
    });
    
    // Handle reactivate buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('reactivate-item')) {
            const itemId = e.target.dataset.itemId;
            
            // Create and submit form directly for reactivation
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reactivate_item">
                <input type="hidden" name="item_id" value="${itemId}">
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