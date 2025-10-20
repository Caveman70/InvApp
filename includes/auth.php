<?php
function check_access($required_permission) {
    include_once 'db_connect1.php';
    $permissions = $_SESSION['permissions'] ?? [];

    if (!in_array($required_permission, $permissions)) {
        try {
            $pdo = getPDO();
            $sql = "SELECT p.permission_name 
                    FROM permissions p 
                    JOIN role_permissions rp ON p.id = rp.permission_id 
                    JOIN user_roles ur ON rp.role_id = ur.role_id 
                    WHERE ur.user_id = :user_id AND p.permission_name = :permission";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? 0,
                ':permission' => $required_permission
            ]);
            $permission = $stmt->fetchColumn();

            if (!$permission) {
                header('Location: /InvApp/pages/access_denied.php');
                exit;
            }

            $_SESSION['permissions'][] = $permission;
        } catch (PDOException $e) {
            header('Location: /InvApp/pages/access_denied.php');
            exit;
        }
    }
}
