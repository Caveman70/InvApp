<?php
/**
 * invAudit.php
 *
 * Manage inventory items by location.  Request items and update stock levels.
 */

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

// Handle success/error messages from redirect
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
            $item_id = (int)$_POST['item_id'] ?? 0;
            $location_id = (int)$_POST['location_id'] ?? 0;
            $new_quantity = (float)$_POST['new_quantity'] ?? 0;
            $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
            
            if ($item_id === 0 || $location_id === 0) {
                throw new Exception("Invalid item or location ID.");
            }
            
            if ($new_quantity < 0) {
                throw new Exception("Stock quantity cannot be negative.");
            }
            
            // Get current stock level for comparison
            $current_stock_sql = "SELECT quantity FROM item_stocks WHERE item_id = :item_id AND location_id = :location_id";
            $current_stock_stmt = $pdo->prepare($current_stock_sql);
            $current_stock_stmt->execute([':item_id' => $item_id, ':location_id' => $location_id]);
            $current_quantity = $current_stock_stmt->fetchColumn();
            
            if ($current_quantity === false) {
                throw new Exception("Stock record not found.");
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update stock quantity
            $update_sql = "UPDATE item_stocks SET quantity = :quantity, last_adjusted_at = NOW() 
                          WHERE item_id = :item_id AND location_id = :location_id";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                ':quantity' => $new_quantity,
                ':item_id' => $item_id,
                ':location_id' => $location_id
            ]);
            
            // Log the stock adjustment in item_history
            $history_details = json_encode([
                'action' => 'Stock adjusted',
                'location_id' => $location_id,
                'old_quantity' => (float)$current_quantity,
                'new_quantity' => $new_quantity,
                'adjustment_amount' => $new_quantity - $current_quantity,
                'reason' => $adjustment_reason
            ]);
            
            $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) 
                           VALUES (:item_id, :action_type, :details, :performed_by)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([
                ':item_id' => $item_id,
                ':action_type' => 'stock_adjust',
                ':details' => $history_details,
                ':performed_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $success = "Stock adjusted successfully from " . number_format($current_quantity, 2) . " to " . number_format($new_quantity, 2) . ".";
            
            // Redirect to preserve state and prevent form resubmission
            $redirect_url = $_SERVER['PHP_SELF'] . '?location_id=' . $location_id . '&success=' . urlencode($success);
            header("Location: $redirect_url");
            exit;
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'request_item') {
            $item_id = (int)$_POST['item_id'] ?? 0;
            $from_location_id = null;
            $to_location_id = (int)$_POST['to_location_id'] ?? null;
            $quantity_requested = (float)$_POST['quantity_requested'] ?? 0;
            $priority = $_POST['priority'] ?? 'normal';
            $request_reason = trim($_POST['request_reason'] ?? '');
            $needed_by_date = $_POST['needed_by_date'] ?? null;
            
            if ($item_id === 0) {
                throw new Exception("Invalid item ID.");
            }
            
            if ($quantity_requested <= 0) {
                throw new Exception("Requested quantity must be greater than zero.");
            }
            
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
                $priority = 'normal';
            }
            
            // Validate needed_by_date if provided
            if ($needed_by_date && !DateTime::createFromFormat('Y-m-d', $needed_by_date)) {
                throw new Exception("Invalid date format.");
            }
                        
            // Start transaction
            $pdo->beginTransaction();
            
            // Insert request
            $insert_sql = "INSERT INTO item_requests (item_id, requested_by, requested_from_location, requested_to_location, 
                                                    quantity_requested, priority, request_reason, needed_by_date) 
                          VALUES (:item_id, :requested_by, :from_location_id, :to_location_id, 
                                 :quantity_requested, :priority, :request_reason, :needed_by_date)";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                ':item_id' => $item_id,
                ':requested_by' => $_SESSION['user_id'],
                ':from_location_id' => null,
                ':to_location_id' => $to_location_id ? $to_location_id : null,
                ':quantity_requested' => $quantity_requested,
                ':priority' => $priority,
                ':request_reason' => $request_reason,
                ':needed_by_date' => ($needed_by_date && $needed_by_date !== '') ? $needed_by_date : null
            ]);
            
            $request_id = $pdo->lastInsertId();
            
            // Log the request in item_history
            $history_details = json_encode([
                'action' => 'Item requested',
                'request_id' => $request_id,
                'from_location_id' => null,
                'to_location_id' => $to_location_id,
                'quantity_requested' => $quantity_requested,
                'priority' => $priority,
                'reason' => $request_reason,
                'current_stock' => 0
            ]);
            
            $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) 
                           VALUES (:item_id, :action_type, :details, :performed_by)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([
                ':item_id' => $item_id,
                ':action_type' => 'assignment',
                ':details' => $history_details,
                ':performed_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $success = "Item request submitted successfully. Request ID: #" . $request_id;
            
            // Redirect to preserve state and prevent form resubmission
            $redirect_url = $_SERVER['PHP_SELF'] . '?location_id=' . $selected_location_id . '&success=' . urlencode($success);
            header("Location: $redirect_url");
            exit;
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Handle AJAX request for total stock data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_total_stock' && isset($_GET['item_id'])) {
    header('Content-Type: application/json');
    $item_id = (int)$_GET['item_id'];
    
    $total_stock_sql = "SELECT SUM(quantity) as total_stock FROM item_stocks WHERE item_id = :item_id";
    $total_stock_stmt = $pdo->prepare($total_stock_sql);
    $total_stock_stmt->execute([':item_id' => $item_id]);
    $total_stock = $total_stock_stmt->fetchColumn() ?: 0;
    
    echo json_encode(['success' => true, 'total_stock' => number_format($total_stock, 2)]);
    exit;
}

// Get selected location from GET parameter
$selected_location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

// Fetch all active sites and locations for the dropdown
$sites_sql = "SELECT s.site_id, s.name as site_name, l.location_id, l.name as location_name 
              FROM sites s 
              LEFT JOIN locations l ON s.site_id = l.site_id 
              WHERE s.is_active = 1 AND l.is_active = 1
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

// Function to calculate stock status with location-based reorder thresholds
function getStockStatus($quantity, $reorder_threshold) {
    if ($quantity == 0) {
        return ['status' => 'No Stock', 'color' => 'red', 'details' => 'No stock at this location'];
    } elseif ($quantity < $reorder_threshold && $reorder_threshold > 0) {
        return ['status' => 'Low Stock', 'color' => 'yellow', 'details' => 'Below reorder threshold'];
    } else {
        return ['status' => 'Ok Stock', 'color' => 'green', 'details' => 'Adequately stocked'];
    }
}

// Get location details if a location is selected
$location_details = null;
$inventory_items = [];

if ($selected_location_id > 0) {
    // Get location and site details
    $location_sql = "SELECT l.location_id, l.name as location_name, l.description as location_description,
                            s.site_id, s.name as site_name, s.address as site_address
                     FROM locations l
                     JOIN sites s ON l.site_id = s.site_id
                     WHERE l.location_id = :location_id AND l.is_active = 1";
    $location_stmt = $pdo->prepare($location_sql);
    $location_stmt->execute([':location_id' => $selected_location_id]);
    $location_details = $location_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($location_details) {
        // Fetch inventory items that have stock at this location
        $inventory_sql = "SELECT DISTINCT i.id, i.name, i.description, i.sku, i.supplier_info, i.part_number,
                                 c.name as category_name,
                                 s.quantity, s.reorder_threshold
                          FROM items i
                          JOIN categories c ON i.category_id = c.id
                          JOIN item_stocks s ON i.id = s.item_id
                          WHERE s.location_id = :location_id 
                            AND i.is_active = 1 
                            AND (s.quantity > 0 OR s.reorder_threshold > 0)
                          ORDER BY i.name";
        $inventory_stmt = $pdo->prepare($inventory_sql);
        $inventory_stmt->execute([':location_id' => $selected_location_id]);
        $inventory_items = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Check for duplicates
        $item_ids = array_column($inventory_items, 'id');
        $unique_ids = array_unique($item_ids);
        if (count($item_ids) !== count($unique_ids)) {
            error_log("Duplicate items found in location $selected_location_id");
            // Remove duplicates based on item ID
            $unique_items = [];
            $seen_ids = [];
            foreach ($inventory_items as $item) {
                if (!in_array($item['id'], $seen_ids)) {
                    $unique_items[] = $item;
                    $seen_ids[] = $item['id'];
                }
            }
            $inventory_items = $unique_items;
        }
        
        // Add stock status to each item
        foreach ($inventory_items as $key => $item) {
            $inventory_items[$key]['stock_status'] = getStockStatus($item['quantity'], $item['reorder_threshold']);
        }
    }
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
                <h3 class="text-xl font-semibold text-gray-800">Inventory Audit by Location</h3>
            </div>
            
            <!-- Location Selection Form -->
            <div class="mb-6">
                <form method="GET" class="flex items-end gap-4">
                    <div>
                        <label for="location_id" class="block text-sm font-medium text-gray-700 mb-2">Select Location</label>
                        <select id="location_id" name="location_id" 
                                class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                onchange="this.form.submit()">
                            <option value="">Choose a location...</option>
                            <?php echo renderLocationOptions($sites, $selected_location_id); ?>
                        </select>
                    </div>
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">
                        Load Inventory
                    </button>
                </form>
            </div>
            
            <?php if ($selected_location_id > 0): ?>
                <?php if ($location_details): ?>
                    <!-- Location Information -->
                    <div class="bg-gray-50 p-4 rounded-lg mb-6">
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">
                            <?php echo htmlspecialchars($location_details['location_name']); ?>
                        </h4>
                        <p class="text-sm text-gray-600">
                            <strong>Site:</strong> <?php echo htmlspecialchars($location_details['site_name']); ?>
                            <?php if ($location_details['site_address']): ?>
                                - <?php echo htmlspecialchars($location_details['site_address']); ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($location_details['location_description']): ?>
                            <p class="text-sm text-gray-600 mt-1">
                                <strong>Description:</strong> <?php echo htmlspecialchars($location_details['location_description']); ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-sm text-gray-600 mt-1">
                            <strong>Total Items:</strong> <?php echo count($inventory_items); ?>
                        </p>
                    </div>
                    
                    <?php if (!empty($inventory_items)): ?>
                        <!-- Print Button -->
                        <div class="mb-4 flex justify-end">
                            <button type="button" id="printBtn" class="bg-sky-500 text-white px-4 py-2 rounded-md hover:bg-sky-600 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm-6 8a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4z" clip-rule="evenodd"></path>
                                </svg>
                                Print Audit Report
                            </button>
                        </div>
                        
                        <!-- Inventory Items Table -->
                        <div class="overflow-x-auto">
                            <table id="auditTable" class="min-w-full bg-white">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Stock</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reorder Level</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <?php if (in_array('manage_inventory', $permissions) || in_array('request_items', $permissions)): ?>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($inventory_items as $item): 
                                        $stock_status = $item['stock_status'];
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <?php if ($item['description']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['description']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($item['category_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($item['sku'] ?? ''); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                            <?php echo number_format($item['quantity'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $item['reorder_threshold'] > 0 ? $item['reorder_threshold'] : 'Not set'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold bg-<?php echo $stock_status['color']; ?>-100 text-<?php echo $stock_status['color']; ?>-800" 
                                                  title="<?php echo htmlspecialchars($stock_status['details']); ?>">
                                                <?php echo $stock_status['status']; ?>
                                            </span>
                                        </td>
                                        <?php if (in_array('manage_inventory', $permissions) || in_array('request_items', $permissions)): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if (in_array('manage_inventory', $permissions)): ?>
                                                <button class="text-blue-600 hover:text-blue-900 mr-3" 
                                                        onclick="adjustStock(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    Adjust Stock
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array('request_items', $permissions)): ?>
                                                <button class="text-green-600 hover:text-green-900" 
                                                        onclick="requestItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    Request
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="text-gray-500 text-lg">No inventory items found at this location</div>
                            <p class="text-sm text-gray-400 mt-2">
                                This location either has no items assigned or all items have zero stock.
                            </p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                        Location not found or is inactive.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-12">
                    <div class="text-gray-500 text-lg mb-2">Select a location to view inventory</div>
                    <p class="text-sm text-gray-400">
                        Choose a location from the dropdown above to display all inventory items and their current stock levels.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Adjust Stock Modal - ALWAYS PRESENT -->
<div id="adjustStockModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Adjust Stock Level</h4>
            <span class="close" data-modal="adjustStockModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" id="adjust_item_id" name="item_id">
                <input type="hidden" id="adjust_location_id" name="location_id" value="<?php echo $selected_location_id; ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Item</label>
                    <div id="adjust_item_name" class="text-lg font-semibold text-gray-900"></div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Stock</label>
                    <div id="adjust_current_stock" class="text-lg font-semibold text-blue-600"></div>
                </div>
                
                <div class="mb-4">
                    <label for="new_quantity" class="block text-sm font-medium text-gray-700 mb-2">New Stock Quantity</label>
                    <input type="number" id="new_quantity" name="new_quantity" 
                           min="0" step="0.01" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="adjustment_reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for Adjustment (Optional)</label>
                    <textarea id="adjustment_reason" name="adjustment_reason" rows="3"
                              placeholder="e.g., Physical count correction, damaged items removed, etc."
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="adjustStockModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Request Item Modal - ALWAYS PRESENT -->
<div id="requestItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Request Item</h4>
            <span class="close" data-modal="requestItemModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="request_item">
                <input type="hidden" id="request_item_id" name="item_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Item</label>
                    <div id="request_item_name" class="text-lg font-semibold text-gray-900"></div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Stock Information</label>
                    <div class="bg-gray-50 p-3 rounded-md">
                        <div class="text-sm">
                            <div class="font-semibold text-blue-600">This Location: <span id="request_current_stock"></span></div>
                            <div class="text-gray-600">Total All Locations: <span id="request_total_stock"></span></div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="quantity_requested" class="block text-sm font-medium text-gray-700 mb-2">Quantity Requested *</label>
                        <input type="number" id="quantity_requested" name="quantity_requested" 
                               min="0.01" step="0.01" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">Priority</label>
                        <select id="priority" name="priority" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="to_location_id" class="block text-sm font-medium text-gray-700 mb-2">Deliver To Location (Optional)</label>
                    <select id="to_location_id" name="to_location_id" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Select delivery location...</option>
                        <?php echo renderLocationOptions($sites); ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="needed_by_date" class="block text-sm font-medium text-gray-700 mb-2">Needed By Date (Optional)</label>
                    <input type="date" id="needed_by_date" name="needed_by_date" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="request_reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for Request *</label>
                    <textarea id="request_reason" name="request_reason" rows="3" required
                              placeholder="e.g., Restock, Replace expired item, etc."
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="requestItemModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-md">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.bg-red-100 { background-color: #fee2e2; }
.bg-red-400 { background-color: #f87171; }
.text-red-700 { color: #b91c1c; }
.text-red-800 { color: #991b1b; }
.bg-yellow-100 { background-color: #fef3c7; }
.text-yellow-800 { color: #92400e; }
.bg-green-100 { background-color: #dcfce7; }
.text-green-800 { color: #166534; }

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
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('printBtn');
    
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            // Get location details for the header
            const locationName = <?php echo json_encode($location_details['location_name'] ?? ''); ?>;
            const siteName = <?php echo json_encode($location_details['site_name'] ?? ''); ?>;
            
            // Create print window
            const printWindow = window.open('', '_blank');
            
            // Generate print HTML
            const printHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Inventory Audit Report - ${locationName}</title>
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
                        .location-info {
                            text-align: center;
                            margin-bottom: 20px;
                            font-size: 14px;
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
                        .status-no-stock { 
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
                        @media print {
                            body { margin: 10px; }
                            table { font-size: 9px; }
                            th, td { padding: 4px; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Inventory Audit Report</h1>
                    <div class="location-info">
                        <strong>Location:</strong> ${locationName}<br>
                        <strong>Site:</strong> ${siteName}
                    </div>
                    <div class="print-date">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>SKU</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            // Get table rows from current display
            const tableRows = document.querySelectorAll('#auditTable tbody tr');
            let tableRowsHTML = '';
            
            tableRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    tableRowsHTML += '<tr>';
                    
                    // Item Name (first div content)
                    const nameDiv = cells[0].querySelector('div');
                    tableRowsHTML += `<td>${nameDiv ? nameDiv.textContent.trim() : cells[0].textContent.trim()}</td>`;
                    
                    // Category
                    tableRowsHTML += `<td>${cells[1].textContent.trim()}</td>`;
                    
                    // SKU
                    tableRowsHTML += `<td>${cells[2].textContent.trim()}</td>`;
                    
                    // Current Stock
                    tableRowsHTML += `<td>${cells[3].textContent.trim()}</td>`;
                    
                    // Reorder Level
                    tableRowsHTML += `<td>${cells[4].textContent.trim()}</td>`;
                    
                    // Status (with proper styling)
                    const statusSpan = cells[5].querySelector('span');
                    let statusHTML = cells[5].textContent.trim();
                    if (statusSpan) {
                        const statusText = statusSpan.textContent;
                        let statusClass = '';
                        if (statusText.includes('No Stock')) statusClass = 'status-no-stock';
                        else if (statusText.includes('Low Stock')) statusClass = 'status-low-stock';
                        else if (statusText.includes('Ok Stock')) statusClass = 'status-ok-stock';
                        
                        statusHTML = `<span class="${statusClass}">${statusText}</span>`;
                    }
                    tableRowsHTML += `<td>${statusHTML}</td>`;
                    
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
    }
    
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

// Function to show the adjust stock modal
function adjustStock(itemId, itemName) {
    // Check if modal exists first
    const modal = document.getElementById('adjustStockModal');
    if (!modal) {
        alert('Error: Stock adjustment modal not found. Please refresh the page and try again.');
        return;
    }
    
    // Find the current stock from the table row by finding the button that was clicked
    let currentStock = '0';
    const tableRows = document.querySelectorAll('#auditTable tbody tr');
    
    // Find the row that contains the button for this specific item
    for (let row of tableRows) {
        const adjustButtons = row.querySelectorAll('button');
        let foundCorrectRow = false;
        
        // Check each button in this row to see if it's for our item
        adjustButtons.forEach(button => {
            const onclickAttr = button.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes(`adjustStock(${itemId},`)) {
                foundCorrectRow = true;
            }
        });
        
        if (foundCorrectRow) {
            const stockCell = row.querySelector('td:nth-child(4)'); // Current Stock column (4th column)
            if (stockCell) {
                currentStock = stockCell.textContent.trim();
                break;
            }
        }
    }
    
    // Check if all required elements exist
    const itemIdField = document.getElementById('adjust_item_id');
    const itemNameField = document.getElementById('adjust_item_name');
    const currentStockField = document.getElementById('adjust_current_stock');
    const newQuantityField = document.getElementById('new_quantity');
    const reasonField = document.getElementById('adjustment_reason');
    
    if (!itemIdField || !itemNameField || !currentStockField || !newQuantityField || !reasonField) {
        alert('Error: Some form fields are missing. Please refresh the page and try again.');
        return;
    }
    
    // Populate modal fields
    itemIdField.value = itemId;
    itemNameField.textContent = itemName;
    currentStockField.textContent = currentStock;
    newQuantityField.value = parseFloat(currentStock.replace(/,/g, '')) || 0;
    reasonField.value = '';
    
    // Show modal
    modal.style.display = 'block';
    
    // Focus on the quantity input
    setTimeout(() => {
        newQuantityField.focus();
        newQuantityField.select();
    }, 100);
}

// Function to show the request item modal
function requestItem(itemId, itemName) {
    // Check if modal exists first
    const modal = document.getElementById('requestItemModal');
    if (!modal) {
        alert('Error: Request item modal not found. Please refresh the page and try again.');
        return;
    }
    
    // Find the current stock from the table row
    let currentStock = '0';
    const tableRows = document.querySelectorAll('#auditTable tbody tr');
    
    for (let row of tableRows) {
        const requestButtons = row.querySelectorAll('button');
        let foundCorrectRow = false;
        
        requestButtons.forEach(button => {
            const onclickAttr = button.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes(`requestItem(${itemId},`)) {
                foundCorrectRow = true;
            }
        });
        
        if (foundCorrectRow) {
            const stockCell = row.querySelector('td:nth-child(4)');
            if (stockCell) {
                currentStock = stockCell.textContent.trim();
                break;
            }
        }
    }
    
    // Get total stock via AJAX
    fetch(`?ajax=get_total_stock&item_id=${itemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('request_total_stock').textContent = data.total_stock;
            }
        })
        .catch(error => {
            console.error('Error fetching total stock:', error);
            document.getElementById('request_total_stock').textContent = 'Error loading';
        });
    
    // Populate modal fields
    const itemIdField = document.getElementById('request_item_id');
    const itemNameField = document.getElementById('request_item_name');
    const currentStockField = document.getElementById('request_current_stock');
    const quantityRequestedField = document.getElementById('quantity_requested');
    const priorityField = document.getElementById('priority');
    const toLocationField = document.getElementById('to_location_id');
    const neededByDateField = document.getElementById('needed_by_date');
    const reasonField = document.getElementById('request_reason');
    
    if (!itemIdField || !itemNameField || !currentStockField || !quantityRequestedField || !reasonField) {
        alert('Error: Some form fields are missing. Please refresh the page and try again.');
        return;
    }
    
    itemIdField.value = itemId;
    itemNameField.textContent = itemName;
    currentStockField.textContent = currentStock;
    quantityRequestedField.value = '';
    priorityField.value = 'normal';
    
    // Set default delivery location to current location
    const currentLocationId = <?php echo $selected_location_id; ?>;
    if (toLocationField && currentLocationId) {
        toLocationField.value = currentLocationId;
    }
    
    if (neededByDateField) neededByDateField.value = '';
    reasonField.value = '';
    
    modal.style.display = 'block';
    
    setTimeout(() => {
        quantityRequestedField.focus();
    }, 100);
}
</script>

<?php include '../../includes/footer.php'; ?>