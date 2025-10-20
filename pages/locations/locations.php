<?php
/**
 * locations.php
 *
 * Manage sites and locations page, shows links based on permissions.
 */

$required_permission = 'manage_locations';
require_once __DIR__ . '/../../includes/init.php';

$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'] ?? [];

$error = ''; // Initialize error variable
$success = '';

$pdo = getPDO();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_site') {
        // Add new site
        try {
            $name = trim($_POST['name']);
            $address = trim($_POST['address']);
            $description = trim($_POST['description']);
            
            $insert_sql = "INSERT INTO sites (name, address, description) VALUES (:name, :address, :description)";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                ':name' => $name,
                ':address' => $address,
                ':description' => $description
            ]);
            
            $success = "Site added successfully!";
        } catch (PDOException $e) {
            $error = "Error adding site: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'edit_site') {
        // Edit existing site
        try {
            $site_id = (int)$_POST['site_id'];
            $name = trim($_POST['name']);
            $address = trim($_POST['address']);
            $description = trim($_POST['description']);
            
            $update_sql = "UPDATE sites SET name = :name, address = :address, description = :description WHERE site_id = :site_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([
                ':name' => $name,
                ':address' => $address,
                ':description' => $description,
                ':site_id' => $site_id
            ]);
            
            $success = "Site updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating site: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_location') {
        // Add new location
        try {
            $site_id = (int)$_POST['site_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            
            $insert_sql = "INSERT INTO locations (site_id, name, description) VALUES (:site_id, :name, :description)";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                ':site_id' => $site_id,
                ':name' => $name,
                ':description' => $description
            ]);
            
            $success = "Location added successfully!";
        } catch (PDOException $e) {
            $error = "Error adding location: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'edit_location') {
        // Edit existing location
        try {
            $location_id = (int)$_POST['location_id'];
            $site_id = (int)$_POST['site_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            
            $update_sql = "UPDATE locations SET site_id = :site_id, name = :name, description = :description WHERE location_id = :location_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([
                ':site_id' => $site_id,
                ':name' => $name,
                ':description' => $description,
                ':location_id' => $location_id
            ]);
            
            $success = "Location updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating location: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'deactivate_site') {
        // Deactivate site (soft delete)
        try {
            $site_id = (int)$_POST['site_id'];
            
            $update_sql = "UPDATE sites SET is_active = 0 WHERE site_id = :site_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':site_id' => $site_id]);
            
            // Also deactivate all locations in this site
            $update_locations_sql = "UPDATE locations SET is_active = 0 WHERE site_id = :site_id";
            $stmt = $pdo->prepare($update_locations_sql);
            $stmt->execute([':site_id' => $site_id]);
            
            $success = "Site deactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error deactivating site: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'deactivate_location') {
        // Deactivate location (soft delete)
        try {
            $location_id = (int)$_POST['location_id'];
            
            $update_sql = "UPDATE locations SET is_active = 0 WHERE location_id = :location_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':location_id' => $location_id]);
            
            $success = "Location deactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error deactivating location: " . $e->getMessage();
        }
    }

       if (isset($_POST['action']) && $_POST['action'] === 'reactivate_site') {
        // Reactivate site
        try {
            $site_id = (int)$_POST['site_id'];
            
            $update_sql = "UPDATE sites SET is_active = 1 WHERE site_id = :site_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':site_id' => $site_id]);
            
            $success = "Site reactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error reactivating site: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'reactivate_location') {
        // Reactivate location
        try {
            $location_id = (int)$_POST['location_id'];
            
            $update_sql = "UPDATE locations SET is_active = 1 WHERE location_id = :location_id";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([':location_id' => $location_id]);
            
            $success = "Location reactivated successfully!";
        } catch (PDOException $e) {
            $error = "Error reactivating location: " . $e->getMessage();
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

if ($show_inactive) {
    // Show all sites and all locations
    $query = "
        SELECT 
            s.site_id,
            s.name as site_name,
            s.address,
            s.description as site_description,
            s.created_at as site_created_at,
            s.is_active as site_active,
            l.location_id,
            l.name as location_name,
            l.description as location_description,
            l.created_at as location_created_at,
            l.is_active as location_active
        FROM sites s
        LEFT JOIN locations l ON s.site_id = l.site_id
        ORDER BY s.is_active DESC, s.name ASC, l.is_active DESC, l.name ASC
    ";
} else {
    // Show only active sites, but show ALL locations (active and inactive) for active sites
    $query = "
        SELECT 
            s.site_id,
            s.name as site_name,
            s.address,
            s.description as site_description,
            s.created_at as site_created_at,
            s.is_active as site_active,
            l.location_id,
            l.name as location_name,
            l.description as location_description,
            l.created_at as location_created_at,
            l.is_active as location_active
        FROM sites s
        LEFT JOIN locations l ON s.site_id = l.site_id
        WHERE s.is_active = 1
        ORDER BY s.name ASC, l.is_active DESC, l.name ASC
    ";
}

$stmt = $pdo->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group results by site
$sites = [];
foreach ($results as $row) {
    $site_id = $row['site_id'];
    
    if (!isset($sites[$site_id])) {
        $sites[$site_id] = [
            'site_id' => $row['site_id'],
            'name' => $row['site_name'],
            'address' => $row['address'],
            'description' => $row['site_description'],
            'created_at' => $row['site_created_at'],
            'is_active' => $row['site_active'],
            'locations' => []
        ];
    }
    
    // Add location if it exists
    /*
    if ($row['location_id']) {
        $sites[$site_id]['locations'][] = [
            'location_id' => $row['location_id'],
            'name' => $row['location_name'],
            'description' => $row['location_description'],
            'created_at' => $row['location_created_at'],
            'is_active' => $row['location_active']
        ];
    }
        */
    if ($row['location_id']) {
        // show all locations if show_inactive is true
        if ($show_inactive) {
            $sites[$site_id]['locations'][] = [
                'location_id' => $row['location_id'],
                'name' => $row['location_name'],
                'description' => $row['location_description'],
                'created_at' => $row['location_created_at'],
                'is_active' => $row['location_active']
            ];
        } else {
            // Only add active locations
            if ($row['location_active']) {
                $sites[$site_id]['locations'][] = [
                    'location_id' => $row['location_id'],
                    'name' => $row['location_name'],
                    'description' => $row['location_description'],
                    'created_at' => $row['location_created_at'],
                    'is_active' => $row['location_active']
                ];
            }
        }
    }
}

// Get count of inactive sites and locations for the toggle button
$inactive_sites_query = "SELECT COUNT(*) as count FROM sites WHERE is_active = 0";
$inactive_sites_stmt = $pdo->prepare($inactive_sites_query);
$inactive_sites_stmt->execute();
$inactive_sites_count = $inactive_sites_stmt->fetch()['count'];

$inactive_locations_query = "SELECT COUNT(*) as count FROM locations WHERE is_active = 0";
$inactive_locations_stmt = $pdo->prepare($inactive_locations_query);
$inactive_locations_stmt->execute();
$inactive_locations_count = $inactive_locations_stmt->fetch()['count'];

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
                    <h3 class="text-xl font-semibold text-gray-800">Manage Sites and Locations</h3>
                    <div class="flex items-center gap-4 mb-4">
                        <?php if ($inactive_sites_count > 0 || $inactive_locations_count > 0): ?>
                            <a href="?show_inactive=<?= $show_inactive ? '0' : '1' ?>" 
                            class="text-sm text-gray-600 hover:text-gray-800 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                </svg>
                                <?= $show_inactive 
                                    ? 'Hide Inactive Items' 
                                    : "Show Inactive Items ($inactive_sites_count sites, $inactive_locations_count locations)" ?>
                            </a>
                        <?php endif; ?>
                        <button id="addUserBtn" class="ml-auto mr-4 bg-blue-500 text-white px-4 py-2 text-sm hover:bg-blue-600 transition-all duration-200">
                            Add Site
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($sites)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        <?= $show_inactive ? 'No inactive sites found.' : 'No sites found.' ?>
                                        <?php if (!$show_inactive): ?>
                                            <a href="#" id="addFirstSite" class="text-blue-500 hover:text-blue-700">Add your first site</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sites as $site): ?>
                                    <!-- Site Row -->
                                    <tr class="<?= $site['is_active'] ? 'bg-blue-50 border-l-4 border-blue-500' : 'bg-red-50 border-l-4 border-red-400 opacity-75' ?>">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 w-4 h-4 mr-2">
                                                    <svg class="w-4 h-4 <?= $site['is_active'] ? 'text-blue-600' : 'text-red-500' ?>" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2H4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                                    </svg>
                                                </div>
                                                <div class="text-sm font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($site['name']); ?>
                                                    <?php if (!$site['is_active']): ?>
                                                        <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 rounded">Inactive</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($site['description'] ?: 'No description'); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($site['address'] ?: 'No address'); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($site['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                            <?php if ($site['is_active']): ?>
                                                <button class="text-blue-600 hover:text-blue-900 edit-site" data-site-id="<?php echo $site['site_id']; ?>">Edit</button>
                                                <button class="text-green-600 hover:text-green-900 add-location" data-site-id="<?php echo $site['site_id']; ?>">Add Location</button>
                                                <button class="text-red-600 hover:text-red-900 deactivate-site" data-site-id="<?php echo $site['site_id']; ?>">Deactivate</button>
                                            <?php else: ?>
                                                <button class="text-green-600 hover:text-green-900 reactivate-site" data-site-id="<?php echo $site['site_id']; ?>">Reactivate</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Location Rows -->
                                    <?php if (!empty($site['locations'])): ?>
                                        <?php foreach ($site['locations'] as $location): ?>
                                            <tr class="<?= $location['is_active'] ? 'bg-gray-50' : 'bg-red-50 opacity-60' ?>">
                                                <td class="px-6 py-3 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="w-8"></div> <!-- Indent spacer -->
                                                        <div class="flex-shrink-0 w-4 h-4 mr-2">
                                                            <svg class="w-4 h-4 <?= $location['is_active'] ? 'text-gray-600' : 'text-red-500' ?>" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                                            </svg>
                                                        </div>
                                                        <div class="text-sm text-gray-900">
                                                            <?php echo htmlspecialchars($location['name']); ?>
                                                            <?php if (!$location['is_active']): ?>
                                                                <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 rounded">Inactive</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-3">
                                                    <div class="text-sm text-gray-700"><?php echo htmlspecialchars($location['description'] ?: 'No description'); ?></div>
                                                </td>
                                                <td class="px-6 py-3 text-sm text-gray-500">—</td>
                                                <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('M j, Y', strtotime($location['created_at'])); ?>
                                                </td>
                                                <td class="px-6 py-3 whitespace-nowrap text-sm font-medium space-x-2">
                                                    <?php if ($location['is_active'] && $site['is_active']): ?>
                                                        <button class="text-blue-600 hover:text-blue-900 edit-location" data-location-id="<?php echo $location['location_id']; ?>">Edit</button>
                                                        <button class="text-red-600 hover:text-red-900 deactivate-location" data-location-id="<?php echo $location['location_id']; ?>">Deactivate</button>
                                                    <?php elseif (!$location['is_active'] && $site['is_active']): ?>
                                                        <button class="text-green-600 hover:text-green-900 reactivate-location" data-location-id="<?php echo $location['location_id']; ?>">Reactivate</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- No locations message -->
                                        <tr class="bg-gray-50">
                                            <td class="px-6 py-3" colspan="5">
                                                <div class="flex items-center text-sm text-gray-500">
                                                    <div class="w-8"></div> <!-- Indent spacer -->
                                                    <em>No locations found for this site. <button class="text-blue-500 hover:text-blue-700 add-location" data-site-id="<?php echo $site['site_id']; ?>">Add a location</button></em>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<!-- Add Site Modal -->
<div id="addSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Add New Site</h4>
            <span class="close" data-modal="addSiteModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_site">
                
                <div class="mb-4">
                    <label for="add_site_name" class="block text-sm font-medium text-gray-700 mb-2">Site Name *</label>
                    <input type="text" id="add_site_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_site_address" class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                    <input type="text" id="add_site_address" name="address"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_site_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="add_site_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="addSiteModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Save Site
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Site Modal -->
<div id="editSiteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Edit Site</h4>
            <span class="close" data-modal="editSiteModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_site">
                <input type="hidden" id="edit_site_id" name="site_id">
                
                <div class="mb-4">
                    <label for="edit_site_name" class="block text-sm font-medium text-gray-700 mb-2">Site Name *</label>
                    <input type="text" id="edit_site_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_site_address" class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                    <input type="text" id="edit_site_address" name="address"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_site_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="edit_site_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="editSiteModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md">
                    Update Site
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Location Modal -->
<div id="addLocationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Add New Location</h4>
            <span class="close" data-modal="addLocationModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_location">
                <input type="hidden" id="add_location_site_id" name="site_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Site</label>
                    <div id="add_location_site_name" class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-md text-gray-700"></div>
                </div>
                
                <div class="mb-4">
                    <label for="add_location_name" class="block text-sm font-medium text-gray-700 mb-2">Location Name *</label>
                    <input type="text" id="add_location_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="add_location_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="add_location_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="addLocationModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-md">
                    Save Location
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Location Modal -->
<div id="editLocationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 class="text-lg font-semibold text-gray-800">Edit Location</h4>
            <span class="close" data-modal="editLocationModal">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_location">
                <input type="hidden" id="edit_location_id" name="location_id">
                
                <div class="mb-4">
                    <label for="edit_location_site_id" class="block text-sm font-medium text-gray-700 mb-2">Site *</label>
                    <select id="edit_location_site_id" name="site_id" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($sites as $site): ?>
                            <?php if ($site['is_active']): ?>
                                <option value="<?= $site['site_id'] ?>"><?= htmlspecialchars($site['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="edit_location_name" class="block text-sm font-medium text-gray-700 mb-2">Location Name *</label>
                    <input type="text" id="edit_location_name" name="name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="edit_location_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="edit_location_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="editLocationModal">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-md">
                    Update Location
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
            <p class="text-gray-700 mb-4">Are you sure you want to deactivate this <span id="deactivate_item_type"></span>?</p>
            <p class="text-sm text-amber-600" id="deactivate_warning_text"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="close-modal px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-md" data-modal="deactivateConfirmModal">
                Cancel
            </button>
            <form method="POST" style="display: inline;" id="deactivateForm">
                <input type="hidden" name="action" id="deactivate_action">
                <input type="hidden" name="site_id" id="deactivate_site_id">
                <input type="hidden" name="location_id" id="deactivate_location_id">
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
    const addSiteModal = document.getElementById('addSiteModal');
    const editSiteModal = document.getElementById('editSiteModal');
    const addLocationModal = document.getElementById('addLocationModal');
    const editLocationModal = document.getElementById('editLocationModal');
    const deactivateConfirmModal = document.getElementById('deactivateConfirmModal');
    
    // Get button elements
    const addUserBtn = document.getElementById('addUserBtn'); // This is your existing "Add Site" button
    
    // Open add site modal
    addUserBtn.addEventListener('click', function() {
        addSiteModal.style.display = 'block';
    });
    
    // Handle edit site buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('edit-site')) {
            const siteId = e.target.dataset.siteId;
            
            // Find the site data from the table row
            const row = e.target.closest('tr');
            const siteName = row.querySelector('td:nth-child(1) div:nth-child(2)').textContent.trim();
            const siteDescription = row.querySelector('td:nth-child(2) div').textContent;
            const siteAddress = row.querySelector('td:nth-child(3) div').textContent;
            
            document.getElementById('edit_site_id').value = siteId;
            document.getElementById('edit_site_name').value = siteName;
            document.getElementById('edit_site_address').value = siteAddress === 'No address' ? '' : siteAddress;
            document.getElementById('edit_site_description').value = siteDescription === 'No description' ? '' : siteDescription;
            
            editSiteModal.style.display = 'block';
        }
    });
    
    // Handle add location buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('add-location')) {
            const siteId = e.target.dataset.siteId;
            
            // Find the site name from the table row
            const row = e.target.closest('tr');
            const siteName = row.querySelector('td:nth-child(1) div:nth-child(2)').textContent.trim();
            
            document.getElementById('add_location_site_id').value = siteId;
            document.getElementById('add_location_site_name').textContent = siteName;
            
            // Clear form
            document.getElementById('add_location_name').value = '';
            document.getElementById('add_location_description').value = '';
            
            addLocationModal.style.display = 'block';
        }
    });
    
    // Handle edit location buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('edit-location')) {
            const locationId = e.target.dataset.locationId;
            
            // Get location data from the table row
            const row = e.target.closest('tr');
            const locationName = row.querySelector('td:nth-child(1) div:nth-child(3)').textContent.trim();
            const locationDescription = row.querySelector('td:nth-child(2) div').textContent;
            
            // Find the site ID by looking at the previous site row
            let currentRow = row.previousElementSibling;
            while (currentRow && !currentRow.classList.contains('bg-blue-50') && !currentRow.classList.contains('bg-red-50')) {
                currentRow = currentRow.previousElementSibling;
            }
            const siteId = currentRow ? currentRow.querySelector('.edit-site, .reactivate-site').dataset.siteId : '';
            
            document.getElementById('edit_location_id').value = locationId;
            document.getElementById('edit_location_site_id').value = siteId;
            document.getElementById('edit_location_name').value = locationName;
            document.getElementById('edit_location_description').value = locationDescription === 'No description' ? '' : locationDescription;
            
            editLocationModal.style.display = 'block';
        }
    });
    
    // Handle deactivate buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('deactivate-site')) {
            const siteId = e.target.dataset.siteId;
            const row = e.target.closest('tr');
            const siteName = row.querySelector('td:nth-child(1) div:nth-child(2)').textContent.trim();
            
            document.getElementById('deactivate_item_type').textContent = 'site';
            document.getElementById('deactivate_warning_text').textContent = 'This will also deactivate all locations within this site. The site and locations will be hidden but preserved for historical data.';
            document.getElementById('deactivate_action').value = 'deactivate_site';
            document.getElementById('deactivate_site_id').value = siteId;
            document.getElementById('deactivate_location_id').value = '';
            
            deactivateConfirmModal.style.display = 'block';
        }
        
        if (e.target.classList.contains('deactivate-location')) {
            const locationId = e.target.dataset.locationId;
            const row = e.target.closest('tr');
            const locationName = row.querySelector('td:nth-child(1) div:nth-child(3)').textContent.trim();
            
            document.getElementById('deactivate_item_type').textContent = 'location';
            document.getElementById('deactivate_warning_text').textContent = 'The location will be hidden but preserved for historical data.';
            document.getElementById('deactivate_action').value = 'deactivate_location';
            document.getElementById('deactivate_site_id').value = '';
            document.getElementById('deactivate_location_id').value = locationId;
            
            deactivateConfirmModal.style.display = 'block';
        }
    });
    
    // Handle reactivate buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('reactivate-site')) {
            const siteId = e.target.dataset.siteId;
            
            // Create and submit form directly for reactivation (no confirmation needed)
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reactivate_site">
                <input type="hidden" name="site_id" value="${siteId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        if (e.target.classList.contains('reactivate-location')) {
            const locationId = e.target.dataset.locationId;
            
            // Create and submit form directly for reactivation (no confirmation needed)
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="reactivate_location">
                <input type="hidden" name="location_id" value="${locationId}">
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