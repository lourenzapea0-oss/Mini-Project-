<?php
session_start();
require_once '../connection.php';

// Check if user is logged in as staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../index.php");
    exit;
}

$pageTitle = "Active Orders";
$basePath = "../";
include '../_header.php';

$selected_table_id = null;
$current_order = null;
$order_items = [];
$message = "";
$message_type = "";

?>

<link rel="stylesheet" href="../css/order.css">
<link rel="stylesheet" href="../css/table.css">

<!-- Custom styles for a cleaner background -->
<style>
    body {
        background-color: var(--second) !important;
    }
</style>

<main class="main-wrapper">
    <div class="order-container">
        <div class="page-header">
            <h1>Active Orders</h1>
            <a href="../staff/index.php" class="back-link">← Back to Dashboard</a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Active Bills Display -->
        <div class="table-selection-section">
            <h2 class="page-title" style="text-align: center; margin-bottom: 30px;">Current Active Orders</h2>
            
            <?php
            // Fetch only tables that have a 'pending' order
            try {
                $sql_active_orders = "SELECT dt.table_number, o.id as order_id, o.total_amount, o.created_at
                                      FROM orders o
                                      JOIN dining_tables dt ON o.table_id = dt.id
                                      WHERE o.status = 'pending'
                                      ORDER BY o.created_at ASC";
                $stmt_active_orders = $pdo->query($sql_active_orders);
                $active_orders = $stmt_active_orders->fetchAll();

                // Pre-fetch all items for the active orders to avoid N+1 queries
                $order_items_by_order_id = [];
                if (!empty($active_orders)) {
                    $order_ids = array_column($active_orders, 'order_id');
                    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));

                    $sql_items = "SELECT oi.order_id, oi.quantity, mi.name as item_name
                                  FROM order_items oi JOIN menu_items mi ON oi.menu_item_id = mi.id
                                  WHERE oi.order_id IN ($placeholders)";
                    $stmt_items = $pdo->prepare($sql_items);
                    $stmt_items->execute($order_ids);
                    $all_items = $stmt_items->fetchAll();

                    // Group items by order_id
                    foreach ($all_items as $item) {
                        $order_items_by_order_id[$item['order_id']][] = $item;
                    }
                }
            } catch (PDOException $e) {
                $active_orders = [];
                echo '<div class="message error">Could not fetch active bills.</div>';
            }
            ?>

            <div class="tables-grid">
                <?php if (empty($active_orders)): ?>
                    <div class="no-order-message" style="grid-column: 1 / -1;">
                        <p>There are no active bills to process right now.</p>
                    </div>
                <?php else: ?>
                    <?php 
                    $order_count = 1;
                    foreach ($active_orders as $order): 
                        // Format the date and time
                        $order_time = date("d M Y, h:i A", strtotime($order['created_at']));
                    ?>
                        <div class="kitchen-order-card">
                            <div class="kitchen-order-header">
                                <h3>Order #<?php echo htmlspecialchars($order['order_id']); ?></h3>
                                <span>Table: <strong><?php echo htmlspecialchars($order['table_number']); ?></strong></span>
                            </div>
                            <ul class="kitchen-order-items">
                                <?php if (isset($order_items_by_order_id[$order['order_id']])): ?>
                                    <?php foreach ($order_items_by_order_id[$order['order_id']] as $item): ?>
                                        <li>
                                            <span class="item-quantity"><?php echo $item['quantity']; ?>x</span>
                                            <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                            <div class="kitchen-order-actions">
                                <button class="action-btn prepared-btn">Prepared</button>
                            </div>
                            <div class="kitchen-order-footer">
                                <?php echo $order_time; ?>
                            </div>
                        </div>
                    <?php 
                        $order_count++;
                    endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="../js/script.js" defer></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all 'Prepared' buttons
    const preparedButtons = document.querySelectorAll('.prepared-btn');

    preparedButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Add 'clicked' class to the button to turn it green
            this.classList.add('clicked');

            // Disable the button to prevent further clicks
            this.disabled = true;
        });
    });
});
</script>

</body>
</html>
