<?php
/**
 * requestManagement.php
 *
 * Admin page to view and manage all item requests in the system.
 */

$required_permission = 'view_all_requests';
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
        if (isset($_POST['action']) && $_POST['action'] === 'update_request_status') {
            $request_id = (int)$_POST['request_id'] ?? 0;
            $new_status = $_POST['new_status'] ?? '';
            $new_priority = $_POST['new_priority'] ?? '';
            $quantity_approved = isset($_POST['quantity_approved']) ? (float)$_POST['quantity_approved'] : null;
            $manager_notes = trim($_POST['manager_notes'] ?? '');
            
            if ($request_id === 0) {
                throw new Exception("Invalid request ID.");
            }
            
            $valid_statuses = ['pending', 'approved', 'partially_approved', 'rejected', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($new_status, $valid_statuses)) {
                throw new Exception("Invalid status.");
            }
            
            // Add priority validation
            if ($new_priority && !in_array($new_priority, ['low', 'normal', 'high', 'urgent'])) {
                throw new Exception("Invalid priority.");
            }

            // In the update_fields array, add:
            if ($new_priority && $new_priority !== $current_request['priority']) {
                $update_fields['priority'] = $new_priority;
            }

            // Get current request details
            $current_request_sql = "SELECT * FROM item_requests WHERE id = :request_id";
            $current_request_stmt = $pdo->prepare($current_request_sql);
            $current_request_stmt->execute([':request_id' => $request_id]);
            $current_request = $current_request_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_request) {
                throw new Exception("Request not found.");
            }
            
            // Validate quantity_approved for approved/partially_approved status
            if (in_array($new_status, ['approved', 'partially_approved'])) {
                if ($quantity_approved === null || $quantity_approved < 0) {
                    throw new Exception("Approved quantity is required and must be non-negative.");
                }
                if ($quantity_approved > $current_request['quantity_requested']) {
                    throw new Exception("Approved quantity cannot exceed requested quantity.");
                }
                if ($new_status === 'partially_approved' && $quantity_approved >= $current_request['quantity_requested']) {
                    throw new Exception("For partial approval, approved quantity must be less than requested quantity.");
                }
                if ($new_status === 'approved' && $quantity_approved != $current_request['quantity_requested']) {
                    $new_status = 'partially_approved'; // Auto-correct status
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update request
            $update_fields = [
                'status' => $new_status,
                'manager_notes' => $manager_notes
            ];
                        
            if (in_array($new_status, ['approved', 'partially_approved', 'rejected'])) {
                $update_fields['approved_by'] = $_SESSION['user_id'];
                $update_fields['approved_date'] = date('Y-m-d H:i:s');
                if (isset($quantity_approved)) {
                    $update_fields['quantity_approved'] = $quantity_approved;
                }
            }
            
            if ($new_status === 'completed') {
                $update_fields['completed_by'] = $_SESSION['user_id'];
                $update_fields['completed_date'] = date('Y-m-d H:i:s');
            }

            // Add priority to update if it's provided and different
            if ($new_priority && $new_priority !== $current_request['priority']) {
                $update_fields['priority'] = $new_priority;
            }
            
            $set_clauses = [];
            $update_params = [];
            foreach ($update_fields as $field => $value) {
                $set_clauses[] = "$field = ?";
                $update_params[] = $value;
            }
            $update_sql = "UPDATE item_requests SET " . implode(', ', $set_clauses) . " WHERE id = ?";
            $update_params[] = $request_id;

            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute($update_params);
            
            // Log status change in item_history
            $history_details = json_encode([
                'action' => 'Request status updated',
                'request_id' => $request_id,
                'old_status' => $current_request['status'],
                'new_status' => $new_status,
                'quantity_approved' => $quantity_approved,
                'manager_notes' => $manager_notes
            ]);
            
            $history_sql = "INSERT INTO item_history (item_id, action_type, details, performed_by) 
                           VALUES (:item_id, :action_type, :details, :performed_by)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([
                ':item_id' => $current_request['item_id'],
                ':action_type' => 'assignment',
                ':details' => $history_details,
                ':performed_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $success = "Request #" . $request_id . " status updated to: " . ucfirst(str_replace('_', ' ', $new_status));
            
            // Redirect to preserve state and prevent form resubmission
            $redirect_url = $_SERVER['PHP_SELF'] . '?success=' . urlencode($success);
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

// Handle filters
$where = [];
$params = [];
$filter_status = '';
$filter_priority = '';
$filter_requester = '';
$search = '';
$filter_start_date = '';
$filter_end_date = '';

// Set default dates: 1 month ago to today
$default_start_date = date('Y-m-d', strtotime('-1 month'));
$default_end_date = date('Y-m-d');


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter_status = $_GET['status'] ?? '';
    if ($filter_status && in_array($filter_status, ['pending', 'approved', 'partially_approved', 'rejected', 'in_progress', 'completed', 'cancelled'])) {
        $where[] = "r.status = :status";
        $params[':status'] = $filter_status;
    }
    
    $filter_priority = $_GET['priority'] ?? '';
    if ($filter_priority && in_array($filter_priority, ['low', 'normal', 'high', 'urgent'])) {
        $where[] = "r.priority = :priority";
        $params[':priority'] = $filter_priority;
    }
    
    $search = trim($_GET['search'] ?? '');
    if ($search) {
        $where[] = "(i.name LIKE :search OR r.request_reason LIKE :search OR u_req.username LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // Add date filtering
    $filter_start_date = $_GET['start_date'] ?? $default_start_date;
    $filter_end_date = $_GET['end_date'] ?? $default_end_date;
    
    // Validate and apply date filters
    if ($filter_start_date) {
        // Validate date format
        if (DateTime::createFromFormat('Y-m-d', $filter_start_date)) {
            $where[] = "DATE(r.requested_date) >= :start_date";
            $params[':start_date'] = $filter_start_date;
        }
    }
    
    if ($filter_end_date) {
        // Validate date format
        if (DateTime::createFromFormat('Y-m-d', $filter_end_date)) {
            $where[] = "DATE(r.requested_date) <= :end_date";
            $params[':end_date'] = $filter_end_date;
        }
    }
} else {
    // Set defaults for initial page load
    $filter_start_date = $default_start_date;
    $filter_end_date = $default_end_date;
    
    // Apply default date filters
    $where[] = "DATE(r.requested_date) >= :start_date";
    $params[':start_date'] = $filter_start_date;
    $where[] = "DATE(r.requested_date) <= :end_date";
    $params[':end_date'] = $filter_end_date;
}

$where_sql = '';
if ($where) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Fetch requests using the view we created
$requests_sql = "SELECT r.*, 
                        i.name as item_name, i.sku as item_sku,
                        c.name as category_name,
                        u_req.username as requested_by_username,
                        l_from.name as from_location_name,
                        s_from.name as from_site_name,
                        l_to.name as to_location_name,
                        s_to.name as to_site_name,
                        u_app.username as approved_by_username,
                        u_comp.username as completed_by_username,
                        COALESCE(stock.quantity, 0) as current_stock
                 FROM item_requests r
                 JOIN items i ON r.item_id = i.id
                 JOIN categories c ON i.category_id = c.id
                 JOIN users u_req ON r.requested_by = u_req.id
                 LEFT JOIN locations l_from ON r.requested_from_location = l_from.location_id
                 LEFT JOIN sites s_from ON l_from.site_id = s_from.site_id
                 LEFT JOIN locations l_to ON r.requested_to_location = l_to.location_id
                 LEFT JOIN sites s_to ON l_to.site_id = s_to.site_id
                 LEFT JOIN users u_app ON r.approved_by = u_app.id
                 LEFT JOIN users u_comp ON r.completed_by = u_comp.id
                 LEFT JOIN item_stocks stock ON r.item_id = stock.item_id AND r.requested_from_location = stock.location_id
                 $where_sql
                 ORDER BY 
                    CASE r.priority 
                        WHEN 'urgent' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'normal' THEN 3 
                        WHEN 'low' THEN 4 
                    END,
                    r.requested_date DESC";

$requests_stmt = $pdo->prepare($requests_sql);
$requests_stmt->execute($params);
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);


// Function to get status color
function getStatusColor($status) {
    switch ($status) {
        case 'pending': return 'yellow';
        case 'approved': return 'green';
        case 'partially_approved': return 'blue';
        case 'rejected': return 'red';
        case 'in_progress': return 'purple';
        case 'completed': return 'green';
        case 'cancelled': return 'gray';
        default: return 'gray';
    }
}

// Function to get priority color
function getPriorityColor($priority) {
    switch ($priority) {
        case 'urgent': return 'red';
        case 'high': return 'orange';
        case 'normal': return 'blue';
        case 'low': return 'gray';
        default: return 'gray';
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
                <h3 class="text-xl font-semibold text-gray-800">Item Request Management</h3>
                <div class="text-sm text-gray-600">
                    Total Requests: <?php echo count($requests); ?>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="mb-6">
                <form method="GET" class="space-y-4">
                    <!-- First row of filters -->
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Item name, reason, or requester" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="min-w-[140px]">
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo ($filter_status === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                <option value="partially_approved" <?php echo ($filter_status === 'partially_approved') ? 'selected' : ''; ?>>Partially Approved</option>
                                <option value="rejected" <?php echo ($filter_status === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                <option value="in_progress" <?php echo ($filter_status === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo ($filter_status === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo ($filter_status === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="min-w-[120px]">
                            <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">Priority</label>
                            <select id="priority" name="priority" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Priorities</option>
                                <option value="urgent" <?php echo ($filter_priority === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                                <option value="high" <?php echo ($filter_priority === 'high') ? 'selected' : ''; ?>>High</option>
                                <option value="normal" <?php echo ($filter_priority === 'normal') ? 'selected' : ''; ?>>Normal</option>
                                <option value="low" <?php echo ($filter_priority === 'low') ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Second row with date filters and buttons -->
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-[140px]">
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" id="start_date" name="start_date" 
                                value="<?php echo htmlspecialchars($filter_start_date); ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="min-w-[140px]">
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" id="end_date" name="end_date" 
                                value="<?php echo htmlspecialchars($filter_end_date); ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">
                                Filter
                            </button>
                            <button type="button" id="clearFilters" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">
                                Clear Filters
                            </button>
                        </div>
                        <div class="ml-auto">
                            <button type="button" id="printBtn" class="bg-sky-500 text-white px-4 py-2 rounded-md hover:bg-sky-600 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm-6 8a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4z" clip-rule="evenodd"></path>
                                </svg>
                                Print Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!empty($requests)): ?>
                <!-- Requests Table -->
                <div class="overflow-x-auto">
                    <table id="requestsTable" class="min-w-full bg-white">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested By</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">To Location</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <?php if (in_array('approve_requests', $permissions)): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($requests as $request): 
                                $status_color = getStatusColor($request['status']);
                                $priority_color = getPriorityColor($request['priority']);
                                //$is_overdue = $request['needed_by_date'] && strtotime($request['needed_by_date']) < time() && !in_array($request['status'], ['completed', 'cancelled']);
                                $is_overdue = !empty($request['needed_by_date']) && strtotime($request['needed_by_date']) < time() && !in_array($request['status'], ['completed', 'cancelled']);
                            ?>
                            <tr class="hover:bg-gray-50 <?php echo $is_overdue ? 'bg-red-50' : ''; ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    #<?php echo $request['id']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['item_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($request['category_name']); ?></div>
                                    <?php if ($request['item_sku']): ?>
                                        <div class="text-xs text-gray-400">SKU: <?php echo htmlspecialchars($request['item_sku']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($request['requested_by_username']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($request['to_location_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($request['to_site_name']); ?></div>
                                    <div class="text-xs text-gray-400">Available: <?php echo number_format($request['current_stock'], 2); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">Req: <?php echo number_format($request['quantity_requested'], 2); ?></div>
                                    <?php if ($request['quantity_approved'] !== null): ?>
                                        <div class="text-sm text-green-600">App: <?php echo number_format($request['quantity_approved'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold bg-<?php echo $priority_color; ?>-100 text-<?php echo $priority_color; ?>-800">
                                        <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($request['requested_date'])); ?></div>
                                    <?php if ($request['needed_by_date']): ?>
                                        <div class="text-xs <?php echo $is_overdue ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                                            Need by: <?php echo date('M j, Y', strtotime($request['needed_by_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php if (in_array('approve_requests', $permissions)): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button class="text-blue-600 hover:text-blue-900" 
                                                onclick="manageRequest(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['item_name']); ?>', '<?php echo $request['status']; ?>', <?php echo $request['quantity_requested']; ?>, '<?php echo htmlspecialchars($request['manager_notes'] ?? ''); ?>', '<?php echo $request['priority']; ?>', '<?php echo htmlspecialchars($request['request_reason'] ?? ''); ?>', '<?php echo $request['from_location_name'] ? htmlspecialchars($request['from_site_name'] . ' → ' . $request['from_location_name']) : 'No specific location'; ?>', '<?php echo $request['to_location_name'] ? htmlspecialchars($request['to_site_name'] . ' → ' . $request['to_location_name']) : ''; ?>')">
                                            Manage
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <div class="text-gray-500 text-lg mb-2">No requests found</div>
                    <p class="text-sm text-gray-400">
                        No item requests match your current filters.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Manage Request Modal - ALWAYS PRESENT -->
<div id="manageRequestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Manage Request</h4>
            <span class="close" data-modal="manageRequestModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_request_status">
                <input type="hidden" id="manage_request_id" name="request_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Item</label>
                    <div id="manage_item_name" class="text-lg font-semibold text-gray-900"></div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Status</label>
                    <div id="manage_current_status" class="text-lg font-semibold text-blue-600"></div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Request Details</label>
                    <div class="bg-blue-50 p-3 rounded-md border">
                        <div class="text-sm">
                            <div class="font-semibold text-blue-800">Requested Quantity: <span id="manage_requested_quantity"></span></div>
                            <div class="text-blue-600" id="manage_to_location_div" style="display: none;">To Location: <span id="manage_to_location"></span></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Original Request Reason</label>
                    <div id="manage_request_reason" class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md border"></div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label for="new_status" class="block text-sm font-medium text-gray-700 mb-2">New Status *</label>
                        <select id="new_status" name="new_status" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                onchange="toggleQuantityApproved()">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="partially_approved">Partially Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="new_priority" class="block text-sm font-medium text-gray-700 mb-2">New Priority *</label>
                        <select id="new_priority" name="new_priority" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div id="quantity_approved_div" style="display: none;">
                        <label for="quantity_approved" class="block text-sm font-medium text-gray-700 mb-2">Approved Quantity</label>
                        <input type="number" id="quantity_approved" name="quantity_approved" 
                            min="0" step="0.01"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <div class="text-xs text-gray-500 mt-1">Requested: <span id="requested_quantity_display"></span></div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="manager_notes" class="block text-sm font-medium text-gray-700 mb-2">Manager Notes</label>
                    <textarea id="manager_notes" name="manager_notes" rows="3"
                              placeholder="Add notes about this decision..."
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="manageRequestModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update Request
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.bg-red-100 { background-color: #fee2e2; }
.bg-red-400 { background-color: #f87171; }
.bg-red-50 { background-color: #fef2f2; }
.text-red-700 { color: #b91c1c; }
.text-red-600 { color: #dc2626; }
.text-red-800 { color: #991b1b; }
.bg-yellow-100 { background-color: #fef3c7; }
.text-yellow-800 { color: #92400e; }
.bg-green-100 { background-color: #dcfce7; }
.text-green-800 { color: #166534; }
.text-green-600 { color: #16a34a; }
.bg-blue-100 { background-color: #dbeafe; }
.text-blue-800 { color: #1e40af; }
.bg-purple-100 { background-color: #f3e8ff; }
.text-purple-800 { color: #7c3aed; }
.bg-gray-100 { background-color: #f3f4f6; }
.text-gray-800 { color: #1f2937; }
.bg-orange-100 { background-color: #fed7aa; }
.text-orange-800 { color: #ea580c; }

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
let currentRequestedQuantity = 0;

document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('printBtn');
    
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            // Create print window
            const printWindow = window.open('', '_blank');
            
            // Generate print HTML
            const printHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Item Requests Report</title>
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
                        .status-pending { background-color: #fef3c7; color: #92400e; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .status-approved { background-color: #dcfce7; color: #166534; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .status-rejected { background-color: #fee2e2; color: #991b1b; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .priority-urgent { background-color: #fee2e2; color: #991b1b; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .priority-high { background-color: #fed7aa; color: #ea580c; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .priority-normal { background-color: #dbeafe; color: #1e40af; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        .priority-low { background-color: #f3f4f6; color: #1f2937; padding: 2px 4px; border-radius: 2px; font-size: 9px; }
                        @media print {
                            body { margin: 10px; }
                            table { font-size: 9px; }
                            th, td { padding: 4px; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Item Requests Report</h1>
                    <div class="print-date">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Item</th>
                                <th>Requested By</th>
                                <th>To Location</th>
                                <th>Quantity</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            // Get table rows from current display
            const tableRows = document.querySelectorAll('#requestsTable tbody tr');
            let tableRowsHTML = '';
            
            tableRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    tableRowsHTML += '<tr>';
                    
                    // Request ID
                    tableRowsHTML += `<td>${cells[0].textContent.trim()}</td>`;
                    
                    // Item (first div content)
                    const itemDiv = cells[1].querySelector('div');
                    tableRowsHTML += `<td>${itemDiv ? itemDiv.textContent.trim() : cells[1].textContent.trim()}</td>`;
                    
                    // Requested By
                    tableRowsHTML += `<td>${cells[2].textContent.trim()}</td>`;
                    
                    // To Location (first div content)
                    const locationDiv = cells[3].querySelector('div');
                    tableRowsHTML += `<td>${locationDiv ? locationDiv.textContent.trim() : cells[3].textContent.trim()}</td>`;
                    
                    // Quantity (first div content)
                    const quantityDiv = cells[4].querySelector('div');
                    tableRowsHTML += `<td>${quantityDiv ? quantityDiv.textContent.trim() : cells[4].textContent.trim()}</td>`;
                    
                    // Priority (with styling)
                    const prioritySpan = cells[5].querySelector('span');
                    let priorityHTML = cells[5].textContent.trim();
                    if (prioritySpan) {
                        const priorityText = prioritySpan.textContent.toLowerCase();
                        const priorityClass = `priority-${priorityText}`;
                        priorityHTML = `<span class="${priorityClass}">${prioritySpan.textContent}</span>`;
                    }
                    tableRowsHTML += `<td>${priorityHTML}</td>`;
                    
                    // Status (with styling)
                    const statusSpan = cells[6].querySelector('span');
                    let statusHTML = cells[6].textContent.trim();
                    if (statusSpan) {
                        const statusText = statusSpan.textContent.toLowerCase().replace(/\s+/g, '');
                        const statusClass = `status-${statusText}`;
                        statusHTML = `<span class="${statusClass}">${statusSpan.textContent}</span>`;
                    }
                    tableRowsHTML += `<td>${statusHTML}</td>`;
                    
                    // Date (first div content)
                    const dateDiv = cells[7].querySelector('div');
                    tableRowsHTML += `<td>${dateDiv ? dateDiv.textContent.trim() : cells[7].textContent.trim()}</td>`;
                    
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

    // Clear filters functionality
    const clearFiltersBtn = document.getElementById('clearFilters');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function() {
            // Calculate default dates
            const today = new Date();
            const oneMonthAgo = new Date();
            oneMonthAgo.setMonth(today.getMonth() - 1);
            
            // Format dates to YYYY-MM-DD
            const formatDate = (date) => {
                return date.getFullYear() + '-' + 
                    String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(date.getDate()).padStart(2, '0');
            };
            
            // Reset all form fields
            document.getElementById('search').value = '';
            document.getElementById('status').value = '';
            document.getElementById('priority').value = '';
            document.getElementById('start_date').value = formatDate(oneMonthAgo);
            document.getElementById('end_date').value = formatDate(today);
            
            // Submit the form to refresh with default filters
            document.querySelector('form').submit();
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

// Function to show/hide approved quantity field based on status
function toggleQuantityApproved() {
    const statusSelect = document.getElementById('new_status');
    const quantityDiv = document.getElementById('quantity_approved_div');
    const quantityInput = document.getElementById('quantity_approved');
    
    if (statusSelect.value === 'approved' || statusSelect.value === 'partially_approved') {
        quantityDiv.style.display = 'block';
        quantityInput.required = true;
        quantityInput.max = currentRequestedQuantity;
        quantityInput.value = statusSelect.value === 'approved' ? currentRequestedQuantity : '';
    } else {
        quantityDiv.style.display = 'none';
        quantityInput.required = false;
        quantityInput.value = '';
    }
}

// Function to show the manage request modal
function manageRequest(requestId, itemName, currentStatus, requestedQuantity, managerNotes, currentPriority = 'normal', requestReason = '', fromLocation = '', toLocation = '') {
    // Check if modal exists first
    const modal = document.getElementById('manageRequestModal');
    if (!modal) {
        alert('Error: Manage request modal not found. Please refresh the page and try again.');
        return;
    }
    
    // Check if all required elements exist
    const requestIdField = document.getElementById('manage_request_id');
    const itemNameField = document.getElementById('manage_item_name');
    const currentStatusField = document.getElementById('manage_current_status');
    const newStatusField = document.getElementById('new_status');
    const quantityApprovedField = document.getElementById('quantity_approved');
    const managerNotesField = document.getElementById('manager_notes');
    const requestedQuantityDisplay = document.getElementById('requested_quantity_display');
    const newPriorityField = document.getElementById('new_priority');
    const requestReasonField = document.getElementById('manage_request_reason');
    const requestedQuantityField = document.getElementById('manage_requested_quantity');
    const fromLocationField = document.getElementById('manage_from_location');
    const toLocationField = document.getElementById('manage_to_location');
    const toLocationDiv = document.getElementById('manage_to_location_div');
    
    if (!requestIdField || !itemNameField || !currentStatusField || !newStatusField || !managerNotesField) {
        alert('Error: Some form fields are missing. Please refresh the page and try again.');
        return;
    }
    
    // Set global variable for quantity validation
    currentRequestedQuantity = requestedQuantity;
    
    // Populate modal fields
    requestIdField.value = requestId;
    itemNameField.textContent = itemName;
    currentStatusField.textContent = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1).replace('_', ' ');
    newStatusField.value = currentStatus;
    newPriorityField.value = currentPriority || 'normal';
    quantityApprovedField.value = '';
    managerNotesField.value = managerNotes;
    
    // Display the original request reason
    if (requestReasonField) {
        requestReasonField.textContent = requestReason || 'No reason provided';
    }
    
    // Display request details
    if (requestedQuantityField) {
        requestedQuantityField.textContent = requestedQuantity;
    }
    if (toLocationField && toLocationDiv) {
        if (toLocation) {
            toLocationField.textContent = toLocation;
            toLocationDiv.style.display = 'block';
        } else {
            toLocationDiv.style.display = 'none';
        }
    }
    
    if (requestedQuantityDisplay) {
        requestedQuantityDisplay.textContent = requestedQuantity;
    }
    
    // Initialize quantity approved field visibility
    toggleQuantityApproved();
    
    // Show modal
    modal.style.display = 'block';
    
    // Focus on the status select
    setTimeout(() => {
        newStatusField.focus();
    }, 100);
}
</script>

<?php include '../../includes/footer.php'; ?>