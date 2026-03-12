<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Medical Inventory - Arawa Hospital';

// Get filter and search
$searchQuery = $_GET['search'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

// Fetch inventory
$sql = "SELECT * FROM inventory WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (item_name LIKE ? OR item_code LIKE ?)";
}

if ($filterStatus !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY item_name ASC";

$stmt = $pdo->prepare($sql);
$params = [];

if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filterStatus !== 'all') {
    $params[] = $filterStatus;
}

if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}

$inventory = $stmt->fetchAll();

// Calculate status for each item
foreach ($inventory as &$item) {
    if ($item['quantity'] == 0) {
        $item['computed_status'] = 'out-of-stock';
    } elseif ($item['quantity'] <= $item['reorder_level']) {
        $item['computed_status'] = 'low-stock';
    } else {
        $item['computed_status'] = 'in-stock';
    }
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $id = $_POST['id'] ?? null;
    $itemName = trim($_POST['item_name']);
    $itemCode = strtoupper(trim($_POST['item_code']));
    $category = $_POST['category'];
    $quantity = intval($_POST['quantity']);
    $unit = trim($_POST['unit']);
    $pricePerUnit = floatval($_POST['price_per_unit']);
    $reorderLevel = intval($_POST['reorder_level']);
    
    // Determine status
    if ($quantity == 0) {
        $status = 'out-of-stock';
    } elseif ($quantity <= $reorderLevel) {
        $status = 'low-stock';
    } else {
        $status = 'in-stock';
    }
    
    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET item_name = ?, item_code = ?, category = ?, quantity = ?, 
                    unit = ?, price_per_unit = ?, reorder_level = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$itemName, $itemCode, $category, $quantity, $unit, $pricePerUnit, $reorderLevel, $status, $id]);
            $_SESSION['success_message'] = 'Item updated successfully';
        } else {
            // Create
            $stmt = $pdo->prepare("
                INSERT INTO inventory (item_name, item_code, category, quantity, unit, price_per_unit, reorder_level, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$itemName, $itemCode, $category, $quantity, $unit, $pricePerUnit, $reorderLevel, $status]);
            $_SESSION['success_message'] = 'Item added successfully';
        }
        
        header('Location: inventory.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to save item';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $id = $_POST['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success_message'] = 'Item deleted successfully';
        header('Location: inventory.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to delete item';
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

$categoryLabels = [
    'medication' => 'Medication',
    'equipment' => 'Equipment',
    'supplies' => 'Supplies',
    'surgical' => 'Surgical',
    'diagnostic' => 'Diagnostic'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/inventory.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="inventory-header">
        <div class="inventory-header-content">
            <h1>Medical Inventory</h1>
            <p>Track and manage medical supplies, medications, and equipment.</p>
        </div>
        <div class="inventory-header-actions">
            <div class="inventory-search-wrapper">
                <svg class="inventory-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="inventory-search-input" 
                        placeholder="Search inventory..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="inventory-btn-primary" onclick="openInventoryModal()">
                Add Item
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="inventory-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="inventory-filter-label">Filter by status:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="status" class="inventory-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Items</option>
                    <option value="in-stock" <?php echo $filterStatus === 'in-stock' ? 'selected' : ''; ?>>In Stock</option>
                    <option value="low-stock" <?php echo $filterStatus === 'low-stock' ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out-of-stock" <?php echo $filterStatus === 'out-of-stock' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
            </form>
        </div>
        <div class="inventory-count-badge">
            <?php echo count($inventory); ?> items found
        </div>
    </div>

    <!-- Table -->
    <div class="inventory-table">
        <div class="inventory-table-header">
            <h3>Inventory Items</h3>
        </div>

        <?php if (count($inventory) === 0): ?>
        <div class="inventory-empty">
            No items found
        </div>
        <?php else: ?>
        <?php foreach ($inventory as $item): ?>
        <?php 
            $needsReorder = $item['quantity'] <= $item['reorder_level'];
            $totalValue = $item['quantity'] * $item['price_per_unit'];
        ?>
        <div class="inventory-item-row">
            <div class="inventory-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line>
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                </svg>
                <?php if ($needsReorder): ?>
                <svg class="inventory-alert-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <?php endif; ?>
            </div>
            <div class="inventory-item-info">
                <div class="inventory-name-row">
                    <h3 class="inventory-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                    <span class="inventory-code"><?php echo htmlspecialchars($item['item_code']); ?></span>
                    <span class="inventory-category"><?php echo $categoryLabels[$item['category']]; ?></span>
                    <span class="inventory-status <?php echo $item['computed_status']; ?>">
                        <?php echo str_replace('-', ' ', ucfirst($item['computed_status'])); ?>
                    </span>
                </div>
                <p class="inventory-details">
                    Quantity: <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?> • Reorder at: <?php echo $item['reorder_level']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                </p>
                <p class="inventory-price">
                    KSh <?php echo number_format($item['price_per_unit'], 2); ?>/<?php echo htmlspecialchars($item['unit']); ?> • Total Value: KSh <?php echo number_format($totalValue, 2); ?>
                </p>
            </div>
            <div class="inventory-actions">
                <button class="btn-edit" onclick='editItem(<?php echo json_encode($item); ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this item? This action cannot be undone.');">
                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                    <button type="submit" name="delete_item" class="btn-delete">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Inventory Modal -->
<div id="inventoryModal" class="inventory-modal">
    <div class="inventory-modal-container">
        <h2 class="inventory-modal-title" id="modalTitle">Add New Item</h2>
        <form method="POST">
            <input type="hidden" name="id" id="itemId">
            
            <div class="inventory-form-grid">
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Item Name *</label>
                    <input type="text" name="item_name" id="itemName" class="inventory-form-input" required>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Item Code *</label>
                    <input type="text" name="item_code" id="itemCode" class="inventory-form-input" style="text-transform: uppercase;" required>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Category *</label>
                    <select name="category" id="itemCategory" class="inventory-form-select" required>
                        <option value="medication">Medication</option>
                        <option value="equipment">Equipment</option>
                        <option value="supplies">Supplies</option>
                        <option value="surgical">Surgical</option>
                        <option value="diagnostic">Diagnostic</option>
                    </select>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Unit *</label>
                    <input type="text" name="unit" id="itemUnit" class="inventory-form-input" placeholder="e.g., tablets, units, pieces" required>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Quantity *</label>
                    <input type="number" name="quantity" id="itemQuantity" class="inventory-form-input" min="0" required>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Price Per Unit (KSh) *</label>
                    <input type="number" step="0.01" name="price_per_unit" id="pricePerUnit" class="inventory-form-input" required>
                </div>
                
                <div class="inventory-form-group">
                    <label class="inventory-form-label">Reorder Level *</label>
                    <input type="number" name="reorder_level" id="reorderLevel" class="inventory-form-input" min="0" required>
                </div>
            </div>
            
            <div class="inventory-modal-footer">
                <button type="button" class="inventory-btn-cancel" onclick="closeInventoryModal()">Cancel</button>
                <button type="submit" name="save_item" class="inventory-btn-submit" id="submitBtn">Add Item</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openInventoryModal() {
    document.getElementById('inventoryModal').classList.add('active');
    document.getElementById('modalTitle').textContent = 'Add New Item';
    document.getElementById('submitBtn').textContent = 'Add Item';
    document.body.style.overflow = 'hidden';
}

function closeInventoryModal() {
    document.getElementById('inventoryModal').classList.remove('active');
    document.querySelector('#inventoryModal form').reset();
    document.getElementById('itemId').value = '';
    document.body.style.overflow = '';
}

function editItem(item) {
    document.getElementById('itemId').value = item.id;
    document.getElementById('itemName').value = item.item_name;
    document.getElementById('itemCode').value = item.item_code;
    document.getElementById('itemCategory').value = item.category;
    document.getElementById('itemUnit').value = item.unit;
    document.getElementById('itemQuantity').value = item.quantity;
    document.getElementById('pricePerUnit').value = item.price_per_unit;
    document.getElementById('reorderLevel').value = item.reorder_level;
    
    document.getElementById('modalTitle').textContent = 'Edit Item';
    document.getElementById('submitBtn').textContent = 'Update Item';
    document.getElementById('inventoryModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modal on overlay click
document.getElementById('inventoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeInventoryModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInventoryModal();
    }
});
</script>
</body>
</html>