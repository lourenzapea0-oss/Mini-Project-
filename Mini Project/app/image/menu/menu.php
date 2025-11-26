<?php 
  session_start();
  require_once '../../connection.php';
  
  // Check if user is logged in
  if (!isset($_SESSION['user_id'])) {
      header("Location: ../index.php");
      exit;
  }
  
  // Check if table is selected
  if (!isset($_SESSION['selected_table_id'])) {
      header("Location: ../../table/table.php");
      exit;
  }
  
  $message = "";
  $message_type = "";
  
  // Handle finalizing the order from the sidebar
  if (isset($_POST['finalize_order']) && isset($_POST['order_id'])) {
      $order_id_to_finalize = (int)$_POST['order_id'];
  
      try {
          // This action doesn't change the order status, it just confirms and redirects.
          // The order remains 'pending' until payment.
          $_SESSION['show_order_complete_alert'] = true;
          header("Location: ../../staff/index.php");
          exit;
  
      } catch (PDOException $e) {
          $message = "Error processing order: " . $e->getMessage();
          $message_type = "error";
      }
  }

  // Handle clearing the entire order
  if (isset($_POST['clear_order']) && isset($_POST['order_id'])) {
      $order_id_to_clear = (int)$_POST['order_id'];
  
      try {
          // Delete all items associated with this order
          $sql_clear = "DELETE FROM order_items WHERE order_id = ?";
          $stmt_clear = $pdo->prepare($sql_clear);
          $stmt_clear->execute([$order_id_to_clear]);
  
          // Recalculate total for the order, which will now be 0
          recalculateOrderTotal($pdo, $order_id_to_clear);
  
          $message = "Order has been cleared.";
          $message_type = "success";
  
      } catch (PDOException $e) {
          $message = "Error clearing order: " . $e->getMessage();
          $message_type = "error";
      }
  }

  // --- SIDEBAR ACTIONS ---
  
  // Handle increasing quantity
  if (isset($_POST['increase_quantity']) && isset($_POST['order_item_id'])) {
      $order_item_id = (int)$_POST['order_item_id'];
      $order_id_to_update = (int)$_POST['order_id'];
  
      try {
          $sql_update = "UPDATE order_items SET quantity = quantity + 1 WHERE id = ?";
          $stmt_update = $pdo->prepare($sql_update);
          $stmt_update->execute([$order_item_id]);
  
          recalculateOrderTotal($pdo, $order_id_to_update);
          // No success message needed to avoid cluttering the UI on every click
      } catch (PDOException $e) {
          $message = "Error updating quantity: " . $e->getMessage();
          $message_type = "error";
      }
  }
  
  // Handle decreasing quantity
  if (isset($_POST['decrease_quantity']) && isset($_POST['order_item_id'])) {
      $order_item_id = (int)$_POST['order_item_id'];
      $order_id_to_update = (int)$_POST['order_id'];
  
      try {
          // First, get current quantity
          $sql_get_qty = "SELECT quantity FROM order_items WHERE id = ?";
          $stmt_get_qty = $pdo->prepare($sql_get_qty);
          $stmt_get_qty->execute([$order_item_id]);
          $item = $stmt_get_qty->fetch();
  
          if ($item && $item['quantity'] > 1) {
              // If quantity > 1, just decrease it
              $sql_update = "UPDATE order_items SET quantity = quantity - 1 WHERE id = ?";
              $stmt_update = $pdo->prepare($sql_update);
              $stmt_update->execute([$order_item_id]);
          } else {
              // If quantity is 1, delete the item
              $sql_delete = "DELETE FROM order_items WHERE id = ?";
              $stmt_delete = $pdo->prepare($sql_delete);
              $stmt_delete->execute([$order_item_id]);
          }
  
          recalculateOrderTotal($pdo, $order_id_to_update);
      } catch (PDOException $e) {
          $message = "Error updating quantity: " . $e->getMessage();
          $message_type = "error";
      }
  }

  // Handle removing item from order
  if (isset($_POST['remove_from_order']) && isset($_POST['order_item_id'])) {
      $order_item_id = (int)$_POST['order_item_id'];
      $order_id_to_update = (int)$_POST['order_id'];
  
      try {
          $sql_delete = "DELETE FROM order_items WHERE id = ?";
          $stmt_delete = $pdo->prepare($sql_delete);
          $stmt_delete->execute([$order_item_id]);
  
          // Recalculate the total for the order
          recalculateOrderTotal($pdo, $order_id_to_update);
  
          $message = "Item removed from order.";
          $message_type = "success";
  
      } catch (PDOException $e) {
          $message = "Error removing item: " . $e->getMessage();
          $message_type = "error";
      }
  }

  // Handle adding item to order (for staff)
  if (isset($_POST['add_to_order']) && isset($_POST['menu_item_id']) && ($_SESSION['role'] === 'staff' || $_SESSION['role'] === 'admin')) {
      $menu_item_id = (int)$_POST['menu_item_id'];
      $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
      $table_id = $_SESSION['selected_table_id'];
      
      try {
          // Get menu item price
          $sql_item = "SELECT price FROM menu_items WHERE id = ?";
          $stmt_item = $pdo->prepare($sql_item);
          $stmt_item->execute([$menu_item_id]);
          $menu_item = $stmt_item->fetch();
          
          if ($menu_item) {
              // Check if order exists for this table
              $sql_order = "SELECT * FROM orders WHERE table_id = ? AND status = 'pending' LIMIT 1";
              $stmt_order = $pdo->prepare($sql_order);
              $stmt_order->execute([$table_id]);
              $order = $stmt_order->fetch();
              
              if (!$order) {
                  // Create new order
                  $sql_new_order = "INSERT INTO orders (table_id, user_id, status) VALUES (?, ?, 'pending')";
                  $stmt_new_order = $pdo->prepare($sql_new_order);
                  $stmt_new_order->execute([$table_id, $_SESSION['user_id']]);
                  $order_id = $pdo->lastInsertId();                  
                  // Update table status to occupied
                  $sql_update_table = "UPDATE dining_tables SET status = 'occupied' WHERE id = ?";
                  $stmt_update_table = $pdo->prepare($sql_update_table);
                  $stmt_update_table->execute([$table_id]);
              } else {
                  $order_id = $order['id'];
              }
              
              // Check if item already exists in order
              $sql_check = "SELECT * FROM order_items WHERE order_id = ? AND menu_item_id = ?";
              $stmt_check = $pdo->prepare($sql_check);
              $stmt_check->execute([$order_id, $menu_item_id]);
              $existing_item = $stmt_check->fetch();
              
              if ($existing_item) {
                  // Update quantity
                  $new_quantity = $existing_item['quantity'] + $quantity;
                  $sql_update = "UPDATE order_items SET quantity = ? WHERE id = ?";
                  $stmt_update = $pdo->prepare($sql_update);
                  $stmt_update->execute([$new_quantity, $existing_item['id']]);
              } else {
                  // Add new item
                  $sql_add = "INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)";
                  $stmt_add = $pdo->prepare($sql_add);
                  $stmt_add->execute([$order_id, $menu_item_id, $quantity, $menu_item['price']]);
              }

              // --- FIX: Manually recalculate the order total ---
              recalculateOrderTotal($pdo, $order_id);

              $message = "Item added to order successfully!";
              $message_type = "success";
          }
      } catch (PDOException $e) {
          $message = "Error adding item: " . $e->getMessage();
          $message_type = "error";
      }
  }
  
  $pageTitle = "Menu"; 
  $basePath = "../../";
  include '../../_header.php'; 

  // Fetch menu items from database
  try {
      $sql = "SELECT mi.*, mc.name as category_name 
              FROM menu_items mi 
              LEFT JOIN menu_categories mc ON mi.category_id = mc.id 
              ORDER BY mi.id DESC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute();
      $menuItems = $stmt->fetchAll();
      
      // Transform data to match expected format
      foreach ($menuItems as &$item) {
          $item['category'] = $item['category_name'] ? $item['category_name'] : 'Uncategorized';
          $item['image'] = $item['image_url'] ? $basePath . htmlspecialchars($item['image_url']) : 'https://via.placeholder.com/280x200.png?text=No+Image';
      }
      unset($item);
  } catch (PDOException $e) {
      $menuItems = [];
      $error_message = "Error loading menu: " . $e->getMessage();
  }
  
  // Fetch all categories for filter buttons
  try {
      $sql_categories = "SELECT DISTINCT mc.name 
                        FROM menu_categories mc 
                        INNER JOIN menu_items mi ON mc.id = mi.category_id 
                        ORDER BY mc.name ASC";
      $stmt_categories = $pdo->prepare($sql_categories);
      $stmt_categories->execute();
      $categories = $stmt_categories->fetchAll(PDO::FETCH_COLUMN);
  } catch (PDOException $e) {
      $categories = [];
  }
?>

<!-- Link to the new stylesheet -->
<link rel="stylesheet" href="<?php echo $basePath; ?>css/menu.css">

<main class="main-wrapper" style="padding-top: 20px;">

  <div class="menu-page-layout">
    <!-- Main Menu Content -->
    <div class="menu-main-content">
      <span style="background-color: var(--fourth); padding: 8px 16px; border-radius: 20px; font-weight: 600; color: var(--text-dark);">
        Table: <?php echo htmlspecialchars($_SESSION['selected_table_number']); ?>
      </span>
    
      <?php if (!empty($message)): ?>
        <div class="message <?php echo $message_type; ?>" style="max-width: 600px; margin: 20px auto; padding: 15px; border-radius: 8px; text-align: center; font-weight: 500; <?php echo $message_type === 'success' ? 'background-color: #e8f5e9; color: #2e7d32; border: 1px solid #66bb6a;' : 'background-color: #ffebee; color: #c62828; border: 1px solid #ef5350;'; ?>">
          <?php echo htmlspecialchars($message); ?>
        </div> 
      <?php endif; ?>
      
      <h2 class="page-title">Our Menu</h2>

      <div class="category-filters">
        <button class="filter-btn active" data-filter="all">All</button>
        <?php foreach ($categories as $category): ?>
          <button class="filter-btn" data-filter="<?php echo htmlspecialchars($category); ?>">
            <?php echo htmlspecialchars($category); ?>
          </button>
        <?php endforeach; ?>
        <?php if (empty($categories)): ?>
          <p style="text-align: center; color: var(--text-light); margin-top: 20px;">
            No categories available. Menu items will be displayed below.
          </p>
        <?php endif; ?>
      </div>

      <div id="menu-grid">
        <?php if (empty($menuItems)): ?>
          <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-light);">
            <p style="font-size: 1.2rem; margin-bottom: 10px;">No menu items available yet.</p>
            <p>Please check back later or contact the administrator.</p>
          </div>
        <?php else: ?>
          <?php foreach ($menuItems as $item): ?>
            <article class="menu-item-card" data-category="<?php echo htmlspecialchars($item['category']); ?>">
              
              <img src="<?php echo $item['image']; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="item-image">
              
              <div class="item-info">
                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                <p><?php echo htmlspecialchars($item['description']); ?></p>
                
                <div class="item-footer">
                  <span class="item-price">
                    RM <?php echo number_format($item['price'], 2); ?>
                  </span>
                  
                  <?php if ($_SESSION['role'] === 'staff' || $_SESSION['role'] === 'admin'): ?>
                    <!-- Staff: Add to order form -->
                    <form method="post" class="add-to-order-form">
                      <input type="hidden" name="menu_item_id" value="<?php echo $item['id']; ?>">
                      <button type="submit" name="add_to_order" class="add-to-cart-btn">
                        Add to Order
                      </button>
                    </form>
                  <?php else: ?>
                    <!-- Customer: Add to cart button (JavaScript) -->
                    <button class="add-to-cart-btn" 
                      data-id="<?php echo $item['id']; ?>"
                      data-name="<?php echo htmlspecialchars($item['name']); ?>"
                      data-price="<?php echo $item['price']; ?>">
                      +
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Order Sidebar -->
    <aside class="order-sidebar">
        <h3>Current Order</h3>
        <?php
            // Fetch current order items for the side panel
            $current_order_items = [];
            $current_order_id = null;
            $current_subtotal = 0;
            try {
                $sql_find_order = "SELECT id, total_amount FROM orders WHERE table_id = ? AND status = 'pending' LIMIT 1";
                $stmt_find_order = $pdo->prepare($sql_find_order);
                $stmt_find_order->execute([$_SESSION['selected_table_id']]);
                $current_order = $stmt_find_order->fetch();

                if ($current_order) {
                    $current_order_id = $current_order['id'];
                    $current_subtotal = $current_order['total_amount'];

                    $sql_get_items = "SELECT oi.id, oi.quantity, mi.name 
                                      FROM order_items oi 
                                      JOIN menu_items mi ON oi.menu_item_id = mi.id 
                                      WHERE oi.order_id = ?";
                    $stmt_get_items = $pdo->prepare($sql_get_items);
                    $stmt_get_items->execute([$current_order_id]);
                    $current_order_items = $stmt_get_items->fetchAll();
                }
            } catch (PDOException $e) {
                echo "<p>Error loading order.</p>";
            }
        ?>

        <?php if (empty($current_order_items)): ?>
            <p class="no-items-message">No items in this order yet.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($current_order_items as $order_item): ?>
                    <li>
                        <span><?php echo htmlspecialchars($order_item['name']); ?></span>
                        <div class="quantity-control">
                            <!-- Decrease Quantity Button -->
                            <form method="post">
                                <input type="hidden" name="order_item_id" value="<?php echo $order_item['id']; ?>">
                                <input type="hidden" name="order_id" value="<?php echo $current_order_id; ?>">
                                <button type="submit" name="decrease_quantity" class="quantity-btn">-</button>
                            </form>
                            <span class="quantity-display"><?php echo $order_item['quantity']; ?></span>
                            <!-- Increase Quantity Button -->
                            <form method="post">
                                <input type="hidden" name="order_item_id" value="<?php echo $order_item['id']; ?>">
                                <input type="hidden" name="order_id" value="<?php echo $current_order_id; ?>">
                                <button type="submit" name="increase_quantity" class="quantity-btn">+</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="subtotal-display">
                Subtotal: <span>RM <?php echo number_format($current_subtotal, 2); ?></span>
            </div>
            <!-- Action Buttons -->
            <div class="sidebar-actions">
                <form method="post" class="finalize-form">
                    <input type="hidden" name="order_id" value="<?php echo $current_order_id; ?>">
                    <button type="submit" name="finalize_order" class="finalize-order-btn">Order</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>
  </div>

</main>
