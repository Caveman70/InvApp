<?php
/**
 * db_connect1.php
 *
 * Database connection logic.
 * Uses PDO for secure database access.
 * 
 * SECURITY NOTE: This file should be located outside the web root to prevent direct access.
 *
 * @package InvApp
 */

function getPDO() {
    $host = 'localhost';
    $dbname = 'inventory';
    $username = 'your_ursername'; // Replace with your database username
    $password = 'your_password'; // Replace with your database password

    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Connection error: " . $e->getMessage());
        }
    }
    return $pdo;
}
