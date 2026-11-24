<?php
// admin/shared_inventory.php - Redirects to Inventory Management (Shared Stock Module)
session_start();
header("Location: products.php?view=shared_stock");
exit();
