<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Departments - Arawa Hospital';

$searchQuery = $_GET['search'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

$sql = "SELECT * FROM departments WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (name LIKE ? OR code LIKE ? OR description LIKE ?)";
}

if ($filterStatus !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$params = [];

if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
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

$departments = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department'])) {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name']);
    $code = strtoupper(trim($_POST['code']));
    $description = trim($_POST['description'] ?? '');
    $consultationFee = floatval($_POST['consultation_fee']);
    $status = $_POST['status'];
    
    try {
        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE departments 
                SET name = ?, code = ?, description = ?, consultation_fee = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $code, $description, $consultationFee, $status, $id]);
            $_SESSION['success_message'] = 'Department updated successfully';
        } else {
            // Create
            $stmt = $pdo->prepare("
                INSERT INTO departments (name, code, description, consultation_fee, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $code, $description, $consultationFee, $status]);
            $_SESSION['success_message'] = 'Department created successfully';
        }
        
        header('Location: departments.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to save department';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department'])) {
    $id = $_POST['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success_message'] = 'Department deleted successfully';
        header('Location: departments.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to delete department';
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
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
    <link rel="stylesheet" href="../../assets/css/departments.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="departments-header">
        <div class="departments-header-content">
            <h1>Departments</h1>
            <p>Manage hospital departments and their information.</p>
        </div>
        <div class="departments-header-actions">
            <div class="departments-search-wrapper">
                <svg class="departments-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="departments-search-input" 
                        placeholder="Search departments..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="departments-btn-primary" onclick="openDepartmentModal()">
                Add Department
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="departments-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="departments-filter-label">Filter by status:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="status" class="departments-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Departments</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </form>
        </div>
        <div class="departments-count-badge">
            <?php echo count($departments); ?> departments found
        </div>
    </div>

    <!-- Table -->
    <div class="departments-table">
        <div class="departments-table-header">
            <h3>All Departments</h3>
        </div>

        <?php if (count($departments) === 0): ?>
        <div class="departments-empty">
            No departments found
        </div>
        <?php else: ?>
        <?php foreach ($departments as $dept): ?>
        <div class="department-row">
            <div class="department-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                    <path d="M9 22v-4h6v4"></path>
                    <path d="M8 6h.01"></path>
                    <path d="M16 6h.01"></path>
                    <path d="M12 6h.01"></path>
                    <path d="M12 10h.01"></path>
                    <path d="M12 14h.01"></path>
                    <path d="M16 10h.01"></path>
                    <path d="M16 14h.01"></path>
                    <path d="M8 10h.01"></path>
                    <path d="M8 14h.01"></path>
                </svg>
            </div>
            <div class="department-info">
                <div class="department-name-row">
                    <h3 class="department-name"><?php echo htmlspecialchars($dept['name']); ?></h3>
                    <span class="department-code"><?php echo htmlspecialchars($dept['code']); ?></span>
                    <span class="department-status <?php echo $dept['status']; ?>">
                        <?php echo ucfirst($dept['status']); ?>
                    </span>
                </div>
                <?php if ($dept['description']): ?>
                <p class="department-details">
                    <?php echo htmlspecialchars($dept['description']); ?>
                </p>
                <?php endif; ?>
                <p class="department-details">
                    Consultation Fee: KSh <?php echo number_format($dept['consultation_fee'], 2); ?>
                </p>
            </div>
            <div class="department-actions">
                <button class="btn-edit" onclick='editDepartment(<?php echo json_encode($dept); ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this department? This action cannot be undone.');">
                    <input type="hidden" name="id" value="<?php echo $dept['id']; ?>">
                    <button type="submit" name="delete_department" class="btn-delete">
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

<!-- Department Modal -->
<div id="departmentModal" class="departments-modal">
    <div class="departments-modal-container">
        <h2 class="departments-modal-title" id="modalTitle">Add New Department</h2>
        <form method="POST">
            <input type="hidden" name="id" id="departmentId">
            
            <div class="departments-form-grid">
                <div class="departments-form-group">
                    <label class="departments-form-label">Department Name *</label>
                    <input type="text" name="name" id="departmentName" class="departments-form-input" required>
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Department Code *</label>
                    <input type="text" name="code" id="departmentCode" class="departments-form-input" style="text-transform: uppercase;" required>
                </div>
                
                <div class="departments-form-group" style="grid-column: 1 / -1;">
                    <label class="departments-form-label">Description</label>
                    <input type="text" name="description" id="departmentDescription" class="departments-form-input">
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Consultation Fee (KSh) *</label>
                    <input type="number" step="0.01" name="consultation_fee" id="consultationFee" class="departments-form-input" required>
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Status</label>
                    <select name="status" id="departmentStatus" class="departments-form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="departments-modal-footer">
                <button type="button" class="departments-btn-cancel" onclick="closeDepartmentModal()">Cancel</button>
                <button type="submit" name="save_department" class="departments-btn-submit" id="submitBtn">Add Department</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openDepartmentModal() {
    document.getElementById('departmentModal').classList.add('active');
    document.getElementById('modalTitle').textContent = 'Add New Department';
    document.getElementById('submitBtn').textContent = 'Add Department';
    document.body.style.overflow = 'hidden';
}

function closeDepartmentModal() {
    document.getElementById('departmentModal').classList.remove('active');
    document.querySelector('#departmentModal form').reset();
    document.getElementById('departmentId').value = '';
    document.body.style.overflow = '';
}

function editDepartment(dept) {
    document.getElementById('departmentId').value = dept.id;
    document.getElementById('departmentName').value = dept.name;
    document.getElementById('departmentCode').value = dept.code;
    document.getElementById('departmentDescription').value = dept.description || '';
    document.getElementById('consultationFee').value = dept.consultation_fee;
    document.getElementById('departmentStatus').value = dept.status;
    
    document.getElementById('modalTitle').textContent = 'Edit Department';
    document.getElementById('submitBtn').textContent = 'Update Department';
    document.getElementById('departmentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modal on overlay click
document.getElementById('departmentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDepartmentModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDepartmentModal();
    }
});
</script>
</body>
</html><?php
// pages/resources/departments.php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Departments - Arawa Hospital';

// Get filter and search
$searchQuery = $_GET['search'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

// Fetch departments
$sql = "SELECT * FROM departments WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (name LIKE ? OR code LIKE ? OR description LIKE ?)";
}

if ($filterStatus !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$params = [];

if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
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

$departments = $stmt->fetchAll();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department'])) {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name']);
    $code = strtoupper(trim($_POST['code']));
    $description = trim($_POST['description'] ?? '');
    $consultationFee = floatval($_POST['consultation_fee']);
    $status = $_POST['status'];
    
    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE departments 
                SET name = ?, code = ?, description = ?, consultation_fee = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $code, $description, $consultationFee, $status, $id]);
            $_SESSION['success_message'] = 'Department updated successfully';
        } else {
            // Create
            $stmt = $pdo->prepare("
                INSERT INTO departments (name, code, description, consultation_fee, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $code, $description, $consultationFee, $status]);
            $_SESSION['success_message'] = 'Department created successfully';
        }
        
        header('Location: departments.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to save department';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department'])) {
    $id = $_POST['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success_message'] = 'Department deleted successfully';
        header('Location: departments.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to delete department';
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
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
    <link rel="stylesheet" href="../../assets/css/departments.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="departments-header">
        <div class="departments-header-content">
            <h1>Departments</h1>
            <p>Manage hospital departments and their information.</p>
        </div>
        <div class="departments-header-actions">
            <div class="departments-search-wrapper">
                <svg class="departments-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="departments-search-input" 
                        placeholder="Search departments..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="departments-btn-primary" onclick="openDepartmentModal()">
                Add Department
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="departments-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="departments-filter-label">Filter by status:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="status" class="departments-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Departments</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </form>
        </div>
        <div class="departments-count-badge">
            <?php echo count($departments); ?> departments found
        </div>
    </div>

    <!-- Table -->
    <div class="departments-table">
        <div class="departments-table-header">
            <h3>All Departments</h3>
        </div>

        <?php if (count($departments) === 0): ?>
        <div class="departments-empty">
            No departments found
        </div>
        <?php else: ?>
        <?php foreach ($departments as $dept): ?>
        <div class="department-row">
            <div class="department-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                    <path d="M9 22v-4h6v4"></path>
                    <path d="M8 6h.01"></path>
                    <path d="M16 6h.01"></path>
                    <path d="M12 6h.01"></path>
                    <path d="M12 10h.01"></path>
                    <path d="M12 14h.01"></path>
                    <path d="M16 10h.01"></path>
                    <path d="M16 14h.01"></path>
                    <path d="M8 10h.01"></path>
                    <path d="M8 14h.01"></path>
                </svg>
            </div>
            <div class="department-info">
                <div class="department-name-row">
                    <h3 class="department-name"><?php echo htmlspecialchars($dept['name']); ?></h3>
                    <span class="department-code"><?php echo htmlspecialchars($dept['code']); ?></span>
                    <span class="department-status <?php echo $dept['status']; ?>">
                        <?php echo ucfirst($dept['status']); ?>
                    </span>
                </div>
                <?php if ($dept['description']): ?>
                <p class="department-details">
                    <?php echo htmlspecialchars($dept['description']); ?>
                </p>
                <?php endif; ?>
                <p class="department-details">
                    Consultation Fee: KSh <?php echo number_format($dept['consultation_fee'], 2); ?>
                </p>
            </div>
            <div class="department-actions">
                <button class="btn-edit" onclick='editDepartment(<?php echo json_encode($dept); ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this department? This action cannot be undone.');">
                    <input type="hidden" name="id" value="<?php echo $dept['id']; ?>">
                    <button type="submit" name="delete_department" class="btn-delete">
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

<!-- Department Modal -->
<div id="departmentModal" class="departments-modal">
    <div class="departments-modal-container">
        <h2 class="departments-modal-title" id="modalTitle">Add New Department</h2>
        <form method="POST">
            <input type="hidden" name="id" id="departmentId">
            
            <div class="departments-form-grid">
                <div class="departments-form-group">
                    <label class="departments-form-label">Department Name *</label>
                    <input type="text" name="name" id="departmentName" class="departments-form-input" required>
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Department Code *</label>
                    <input type="text" name="code" id="departmentCode" class="departments-form-input" style="text-transform: uppercase;" required>
                </div>
                
                <div class="departments-form-group" style="grid-column: 1 / -1;">
                    <label class="departments-form-label">Description</label>
                    <input type="text" name="description" id="departmentDescription" class="departments-form-input">
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Consultation Fee (KSh) *</label>
                    <input type="number" step="0.01" name="consultation_fee" id="consultationFee" class="departments-form-input" required>
                </div>
                
                <div class="departments-form-group">
                    <label class="departments-form-label">Status</label>
                    <select name="status" id="departmentStatus" class="departments-form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="departments-modal-footer">
                <button type="button" class="departments-btn-cancel" onclick="closeDepartmentModal()">Cancel</button>
                <button type="submit" name="save_department" class="departments-btn-submit" id="submitBtn">Add Department</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openDepartmentModal() {
    document.getElementById('departmentModal').classList.add('active');
    document.getElementById('modalTitle').textContent = 'Add New Department';
    document.getElementById('submitBtn').textContent = 'Add Department';
    document.body.style.overflow = 'hidden';
}

function closeDepartmentModal() {
    document.getElementById('departmentModal').classList.remove('active');
    document.querySelector('#departmentModal form').reset();
    document.getElementById('departmentId').value = '';
    document.body.style.overflow = '';
}

function editDepartment(dept) {
    document.getElementById('departmentId').value = dept.id;
    document.getElementById('departmentName').value = dept.name;
    document.getElementById('departmentCode').value = dept.code;
    document.getElementById('departmentDescription').value = dept.description || '';
    document.getElementById('consultationFee').value = dept.consultation_fee;
    document.getElementById('departmentStatus').value = dept.status;
    
    document.getElementById('modalTitle').textContent = 'Edit Department';
    document.getElementById('submitBtn').textContent = 'Update Department';
    document.getElementById('departmentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modal on overlay click
document.getElementById('departmentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDepartmentModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDepartmentModal();
    }
});
</script>
</body>
</html>