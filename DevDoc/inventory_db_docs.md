# Inventory Management Database Documentation

## Overview

This is a comprehensive inventory management system built on MariaDB 10.6.22. The database supports multi-site inventory tracking with role-based access control, hierarchical categories, and detailed audit trails.

**Database Name:** `inventory`  
**Engine:** InnoDB  
**Charset:** utf8mb4  
**Collation:** utf8mb4_general_ci  

## Database Architecture

### Core Domain Model

The system is organized around these main entities:
- **Items**: Physical inventory items with stock tracking
- **Categories**: Hierarchical organization of items
- **Sites & Locations**: Multi-location inventory management
- **Users & Permissions**: Role-based access control
- **History**: Complete audit trail of all actions

### Entity Relationship Overview

```
Sites (1) ──→ (N) Locations (1) ──→ (N) Item_Stocks (N) ──→ (1) Items
Items (N) ──→ (1) Categories (self-referencing hierarchy)
Items (1) ──→ (N) Item_History
Users (N) ──→ (N) Roles (N) ──→ (N) Permissions
Users (1) ──→ (N) Items (created_by, updated_by)
```

---

## Table Definitions

### Core Inventory Tables

#### `items`
**Purpose:** Central table for all inventory items

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | Unique item identifier |
| `name` | VARCHAR(255) | NOT NULL | Item display name |
| `description` | TEXT | NULL | Detailed item description |
| `category_id` | INT(11) | NOT NULL, FK→categories.id | Category classification |
| `sku` | VARCHAR(100) | NULL | Stock Keeping Unit identifier |
| `unit_cost` | DECIMAL(10,2) | DEFAULT 0.00 | Cost per unit |
| `reorder_threshold` | INT(11) | DEFAULT 0 | Minimum stock level trigger |
| `supplier_info` | VARCHAR(255) | NULL | Supplier contact/details |
| `part_number` | VARCHAR(100) | NULL | Manufacturer part number |
| `created_by` | INT(11) | NULL, FK→users.id | User who created item |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_by` | INT(11) | NULL, FK→users.id | Last user to update |
| `updated_at` | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | Last update timestamp |
| `is_active` | TINYINT(1) | DEFAULT 1 | Soft delete flag |
| `full_quantity` | INT(11) | DEFAULT 0 | Total quantity across all locations |

**Key Relationships:**
- Belongs to one Category (CASCADE DELETE)
- Created/Updated by Users
- Has many Item_Stocks
- Has many Item_History records

**Indexes:**
- Primary: `id`
- Foreign Keys: `category_id`, `created_by`, `updated_by`

#### `item_stocks`
**Purpose:** Track quantity of items at specific locations

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | Stock record identifier |
| `item_id` | INT(11) | NOT NULL, FK→items.id | Item reference |
| `location_id` | INT(11) | NOT NULL, FK→locations.location_id | Location reference |
| `quantity` | DECIMAL(10,2) | NOT NULL, DEFAULT 0.00 | Current stock level |
| `last_adjusted_at` | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | Last stock change |

**Key Relationships:**
- Belongs to one Item (CASCADE DELETE)
- Belongs to one Location (CASCADE DELETE)

**Indexes:**
- Primary: `id`
- Unique: `(item_id, location_id)` - One stock record per item-location
- Foreign Keys: `item_id`, `location_id`

#### `item_history`
**Purpose:** Complete audit trail for all item operations

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | History record identifier |
| `item_id` | INT(11) | NOT NULL, FK→items.id | Item reference |
| `action_type` | ENUM | NOT NULL | Type of action performed |
| `details` | LONGTEXT | JSON, NULL | Action-specific data |
| `performed_by` | INT(11) | NULL, FK→users.id | User who performed action |
| `performed_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | When action occurred |

**Action Types:**
- `create`: Item creation
- `update`: Item modification
- `delete`: Item deletion
- `stock_adjust`: Quantity changes
- `location_change`: Stock movement
- `assignment`: Item assignment changes

**Key Relationships:**
- Belongs to one Item (CASCADE DELETE)
- Performed by User

**Indexes:**
- Primary: `id`
- Foreign Keys: `item_id`, `performed_by`

### Organization & Hierarchy Tables

#### `categories`
**Purpose:** Hierarchical categorization of items

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | Category identifier |
| `name` | VARCHAR(255) | NOT NULL | Category name |
| `parent_id` | INT(11) | NULL, FK→categories.id | Parent category (self-reference) |
| `description` | TEXT | NULL | Category description |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | Last update timestamp |
| `is_active` | TINYINT(1) | DEFAULT 1 | Soft delete flag |

**Key Relationships:**
- Self-referencing hierarchy (parent_id → id)
- Has many Items

**Indexes:**
- Primary: `id`
- Foreign Key: `parent_id` (SET NULL on delete)

#### `sites`
**Purpose:** Physical sites/facilities for inventory

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `site_id` | INT(11) | PK, AUTO_INCREMENT | Site identifier |
| `name` | VARCHAR(100) | NOT NULL | Site name |
| `address` | VARCHAR(255) | NULL | Physical address |
| `description` | TEXT | NULL | Site description |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | Last update timestamp |
| `is_active` | TINYINT(1) | DEFAULT 1 | Soft delete flag |

**Key Relationships:**
- Has many Locations

**Indexes:**
- Primary: `site_id`
- Index: `name`

#### `locations`
**Purpose:** Specific locations within sites (warehouses, rooms, etc.)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `location_id` | INT(11) | PK, AUTO_INCREMENT | Location identifier |
| `site_id` | INT(11) | NOT NULL, FK→sites.site_id | Parent site |
| `name` | VARCHAR(100) | NOT NULL | Location name |
| `description` | TEXT | NULL | Location description |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | Last update timestamp |
| `is_active` | TINYINT(1) | DEFAULT 1 | Soft delete flag |

**Key Relationships:**
- Belongs to one Site (CASCADE DELETE)
- Has many Item_Stocks

**Indexes:**
- Primary: `location_id`
- Foreign Key: `site_id`

### Access Control Tables

#### `users`
**Purpose:** System users with authentication

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | User identifier |
| `username` | VARCHAR(50) | UNIQUE | Login username |
| `email` | VARCHAR(100) | UNIQUE | User email address |
| `password` | VARCHAR(255) | NULL | Hashed password |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | Account creation |
| `is_active` | TINYINT(1) | DEFAULT 1 | Account status |

**Key Relationships:**
- Has many User_Roles
- Can create/update Items
- Can perform Item_History actions

**Indexes:**
- Primary: `id`
- Unique: `username`, `email`

#### `roles`
**Purpose:** User role definitions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | Role identifier |
| `role_name` | VARCHAR(50) | UNIQUE | Role name |

**Predefined Roles:**
- `Admin` (ID: 1): Full system access
- `Manager` (ID: 2): Management operations
- `Staff` (ID: 3): Basic operations

**Key Relationships:**
- Has many Role_Permissions
- Has many User_Roles

**Indexes:**
- Primary: `id`
- Unique: `role_name`

#### `permissions`
**Purpose:** Granular system permissions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT(11) | PK, AUTO_INCREMENT | Permission identifier |
| `permission_name` | VARCHAR(50) | UNIQUE | Permission name |

**Predefined Permissions:**
- `manage_users` (ID: 1): User management
- `manage_inventory` (ID: 2): Full inventory control
- `view_reports` (ID: 3): Report access
- `view_inventory` (ID: 4): Read-only inventory
- `manage_locations` (ID: 5): Location management
- `manage_categories` (ID: 6): Category management

**Key Relationships:**
- Has many Role_Permissions

**Indexes:**
- Primary: `id`
- Unique: `permission_name`

#### `role_permissions`
**Purpose:** Many-to-many relationship between roles and permissions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `role_id` | INT(11) | PK, FK→roles.id | Role reference |
| `permission_id` | INT(11) | PK, FK→permissions.id | Permission reference |

**Key Relationships:**
- Belongs to one Role
- Belongs to one Permission

**Indexes:**
- Composite Primary: `(role_id, permission_id)`
- Foreign Keys: `role_id`, `permission_id`

#### `user_roles`
**Purpose:** Many-to-many relationship between users and roles

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `user_id` | INT(11) | PK, FK→users.id | User reference |
| `role_id` | INT(11) | PK, FK→roles.id | Role reference |

**Key Relationships:**
- Belongs to one User
- Belongs to one Role

**Indexes:**
- Composite Primary: `(user_id, role_id)`
- Foreign Keys: `user_id`, `role_id`

---

## Common Query Patterns

### Inventory Queries

```sql
-- Get total stock for an item across all locations
SELECT i.name, SUM(s.quantity) as total_stock
FROM items i
LEFT JOIN item_stocks s ON i.id = s.item_id
WHERE i.id = ?
GROUP BY i.id;

-- Find items below reorder threshold
SELECT i.name, i.reorder_threshold, COALESCE(SUM(s.quantity), 0) as current_stock
FROM items i
LEFT JOIN item_stocks s ON i.id = s.item_id
WHERE i.is_active = 1
GROUP BY i.id
HAVING current_stock < i.reorder_threshold;

-- Get stock by location
SELECT l.name as location, s.quantity, i.name as item_name
FROM item_stocks s
JOIN items i ON s.item_id = i.id
JOIN locations l ON s.location_id = l.location_id
WHERE l.location_id = ?;
```

### Category Hierarchy Queries

```sql
-- Get category tree (recursive CTE for full hierarchy)
WITH RECURSIVE category_tree AS (
  SELECT id, name, parent_id, 0 as level
  FROM categories 
  WHERE parent_id IS NULL
  
  UNION ALL
  
  SELECT c.id, c.name, c.parent_id, ct.level + 1
  FROM categories c
  JOIN category_tree ct ON c.parent_id = ct.id
)
SELECT * FROM category_tree ORDER BY level, name;

-- Get all items in a category and its subcategories
WITH RECURSIVE subcats AS (
  SELECT id FROM categories WHERE id = ?
  UNION ALL
  SELECT c.id FROM categories c
  JOIN subcats s ON c.parent_id = s.id
)
SELECT i.* FROM items i
JOIN subcats s ON i.category_id = s.id
WHERE i.is_active = 1;
```

### User Permission Queries

```sql
-- Check if user has specific permission
SELECT COUNT(*) as has_permission
FROM users u
JOIN user_roles ur ON u.id = ur.user_id
JOIN role_permissions rp ON ur.role_id = rp.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE u.id = ? AND p.permission_name = ?;

-- Get all permissions for a user
SELECT DISTINCT p.permission_name
FROM users u
JOIN user_roles ur ON u.id = ur.user_id
JOIN role_permissions rp ON ur.role_id = rp.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE u.id = ?;
```

### Audit Trail Queries

```sql
-- Get item history with user details
SELECT h.action_type, h.details, h.performed_at, u.username
FROM item_history h
LEFT JOIN users u ON h.performed_by = u.id
WHERE h.item_id = ?
ORDER BY h.performed_at DESC;

-- Recent stock changes
SELECT i.name, h.action_type, h.details, h.performed_at, u.username
FROM item_history h
JOIN items i ON h.item_id = i.id
LEFT JOIN users u ON h.performed_by = u.id
WHERE h.action_type IN ('stock_adjust', 'location_change')
AND h.performed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY h.performed_at DESC;
```

---

## Data Integrity Rules

### Foreign Key Constraints

- **CASCADE DELETE**: When parent is deleted, children are automatically deleted
  - `items.category_id → categories.id`
  - `item_stocks.item_id → items.id`
  - `item_stocks.location_id → locations.location_id`
  - `item_history.item_id → items.id`
  - `locations.site_id → sites.site_id`

- **SET NULL**: When parent is deleted, child reference becomes NULL
  - `categories.parent_id → categories.id`

- **RESTRICT** (default): Parent cannot be deleted if children exist
  - All user references in items and history

### Unique Constraints

- `item_stocks`: One record per `(item_id, location_id)` combination
- `users`: Unique `username` and `email`
- `roles`: Unique `role_name`
- `permissions`: Unique `permission_name`

### JSON Data Validation

The `item_history.details` column uses JSON validation:
```sql
CHECK (json_valid(`details`))
```

**Expected JSON structure by action_type:**
- `stock_adjust`: `{"old_quantity": 10, "new_quantity": 15, "location_id": 1}`
- `location_change`: `{"from_location": 1, "to_location": 2, "quantity": 5}`
- `update`: `{"changed_fields": ["name", "unit_cost"], "old_values": {...}, "new_values": {...}}`

---

## Performance Considerations

### Existing Indexes

- **Primary Keys**: All tables have auto-increment primary keys
- **Foreign Keys**: All foreign key columns are indexed
- **Unique Constraints**: Username, email, role names, permission names
- **Composite Unique**: `(item_id, location_id)` in item_stocks
- **Named Indexes**: 
  - `idx_site_id` on locations.site_id
  - `idx_site_name` on sites.name

### Recommended Additional Indexes

For high-volume operations, consider adding:

```sql
-- For inventory reporting queries
CREATE INDEX idx_items_active ON items (is_active);
CREATE INDEX idx_items_category_active ON items (category_id, is_active);

-- For stock queries
CREATE INDEX idx_item_stocks_quantity ON item_stocks (quantity);

-- For audit queries
CREATE INDEX idx_item_history_action_date ON item_history (action_type, performed_at);
CREATE INDEX idx_item_history_date ON item_history (performed_at);

-- For user lookup
CREATE INDEX idx_users_active ON users (is_active);
```

