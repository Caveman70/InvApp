<?php
/**
 * init.php
 *
 * Central session, DB, and permission check for all pages.
 * Also provides common helper functions.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect1.php'; // change path to match actual location of db_connect1.php

if (!isset($_SESSION['user_id'])) {
    header('Location: /InvApp/login.php');
    exit;
}

// Optionally check for a required permission
if (isset($required_permission)) {
    require_once __DIR__ . '/auth.php';
    check_access($required_permission);
}

// Helper: redirect to a URL and exit
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

// Helper: display error message in a standard way
function display_error($error) {
    if (!empty($error)) {
        echo '<div class="bg-red-100 text-red-700 p-4 rounded-md mb-6">' . htmlspecialchars($error) . '</div>';
    }
}
?>
