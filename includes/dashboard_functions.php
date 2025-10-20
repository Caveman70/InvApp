<?php

// get lowstock item count by category id
function get_lowstock_count($catId, $pdo) {
    $sql = "WITH RECURSIVE category_tree AS (
            -- Base case: the selected category
            SELECT id FROM categories WHERE id = $catId
            
            UNION ALL
            
            -- Recursive case: all subcategories
            SELECT c.id 
            FROM categories c
            INNER JOIN category_tree ct ON c.parent_id = ct.id
            WHERE c.is_active = 1
        )
        SELECT COUNT(*) as low_stock_count
        FROM items i
        LEFT JOIN (
            SELECT item_id, SUM(quantity) as total_quantity
            FROM item_stocks
            GROUP BY item_id
        ) s ON i.id = s.item_id
        WHERE i.category_id IN (SELECT id FROM category_tree)
        AND i.is_active = 1
        AND COALESCE(s.total_quantity, 0) > 0
        AND COALESCE(s.total_quantity, 0) <= i.reorder_threshold";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchColumn();

}


// get zero stock item count by category id
function get_zerostock_count($catId, $pdo) {
    $sql = "WITH RECURSIVE category_tree AS (
            -- Base case: the selected category
            SELECT id FROM categories WHERE id = $catId
            
            UNION ALL
            
            -- Recursive case: all subcategories
            SELECT c.id 
            FROM categories c
            INNER JOIN category_tree ct ON c.parent_id = ct.id
            WHERE c.is_active = 1
        )
        SELECT COUNT(*) as no_stock_count
        FROM items i
        LEFT JOIN (
            SELECT item_id, SUM(quantity) as total_quantity
            FROM item_stocks
            GROUP BY item_id
        ) s ON i.id = s.item_id
        WHERE i.category_id IN (SELECT id FROM category_tree)
        AND i.is_active = 1
          AND COALESCE(s.total_quantity, 0) = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchColumn();

}