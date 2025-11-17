<?php
$host = "localhost";   
$user = "root";       
$pass = "";          
$db   = "yobita_db";  

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Recalculates the total_amount for a given order based on its items.
 *
 * @param PDO $pdo The database connection object.
 * @param int $order_id The ID of the order to recalculate.
 * @return void
 */
function recalculateOrderTotal($pdo, $order_id) {
    try {
        $sql_sum = "SELECT SUM(quantity * price) AS new_total FROM order_items WHERE order_id = ?";
        $stmt_sum = $pdo->prepare($sql_sum);
        $stmt_sum->execute([$order_id]);
        $result = $stmt_sum->fetch();

        $new_total = $result['new_total'] ?? 0;

        $sql_update_order = "UPDATE orders SET total_amount = ? WHERE id = ?";
        $stmt_update_order = $pdo->prepare($sql_update_order);
        $stmt_update_order->execute([$new_total, $order_id]);
        
    } catch (PDOException $e) {
        error_log("Error recalculating total for order $order_id: " . $e->getMessage());
    }
}
?>
