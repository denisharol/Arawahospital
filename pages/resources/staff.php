<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Medical Staff - Arawa Hospital';

// Get filter and search
$searchQuery = $_GET['search'] ?? '';
$filterRole = $_GET['role'] ?? 'all';

// Fetch staff
$sql = "SELECT * FROM staff_users WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (full_name LIKE ? OR email LIKE ?)";
}

if ($filterRole !== 'all') {
    $sql .= " AND role = ?";
}

$sql .= " ORDER BY full_name ASC";

$stmt = $pdo->prepare($sql);
$params = [];

if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filterRole !== 'all') {
    $params[] = $filterRole;
}

if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}

$staff = $stmt->fetchAll();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_staff'])) {
    $id = $_POST['id'] ?? null;
    $fullName = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $password = trim($_POST['password'] ?? '');
    
    try {
        if ($id) {
            // Update
            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                // Update with password
                $stmt = $pdo->prepare("
                    UPDATE staff_users 
                    SET full_name = ?, email = ?, role = ?, password_hash = ?
                    WHERE id = ?
                ");
                $stmt->execute([$fullName, $email, $role, $hashedPassword, $id]);
            } else {
                // Update without password
                $stmt = $pdo->prepare("
                    UPDATE staff_users 
                    SET full_name = ?, email = ?, role = ?
                    WHERE id = ?
                ");
                $stmt->execute([$fullName, $email, $role, $id]);
            }
            $_SESSION['success_message'] = 'Staff member updated successfully';
        } else {
            // Create - password is required for new staff
            if (empty($password)) {
                $_SESSION['error_message'] = 'Password is required for new staff members';
                header('Location: staff.php');
                exit;
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO staff_users (full_name, email, role, password_hash)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$fullName, $email, $role, $hashedPassword]);
            $_SESSION['success_message'] = 'Staff member added successfully';
        }
        
        header('Location: staff.php');
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $_SESSION['error_message'] = 'Email already exists';
        } else {
            $_SESSION['error_message'] = 'Failed to save staff member';
        }
        header('Location: staff.php');
        exit;
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff'])) {
    $id = $_POST['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM staff_users WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success_message'] = 'Staff member deleted successfully';
        header('Location: staff.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Failed to delete staff member';
        header('Location: staff.php');
        exit;
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$roleLabels = [
    'doctor' => 'Doctor',
    'nurse' => 'Nurse',
    'admin' => 'Administrator',
    'technician' => 'Technician',
    'other' => 'Other'
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
    <link rel="stylesheet" href="../../assets/css/staff.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="staff-header">
        <div class="staff-header-content">
            <h1>Medical Staff</h1>
            <p>Manage hospital staff, doctors, nurses, and support personnel.</p>
        </div>
        <div class="staff-header-actions">
            <div class="staff-search-wrapper">
                <svg class="staff-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($filterRole); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="staff-search-input" 
                        placeholder="Search staff..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="staff-btn-primary" onclick="openStaffModal()">
                Add Staff
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
    <div style="margin-bottom: 24px; padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; border: 1px solid #fecaca;">
        <?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="staff-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="staff-filter-label">Filter by role:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="role" class="staff-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterRole === 'all' ? 'selected' : ''; ?>>All Staff</option>
                    <option value="doctor" <?php echo $filterRole === 'doctor' ? 'selected' : ''; ?>>Doctors</option>
                    <option value="nurse" <?php echo $filterRole === 'nurse' ? 'selected' : ''; ?>>Nurses</option>
                    <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Administrators</option>
                    <option value="technician" <?php echo $filterRole === 'technician' ? 'selected' : ''; ?>>Technicians</option>
                    <option value="other" <?php echo $filterRole === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </form>
        </div>
        <div class="staff-count-badge">
            <?php echo count($staff); ?> staff members found
        </div>
    </div>

    <!-- Table -->
    <div class="staff-table">
        <div class="staff-table-header">
            <h3>All Staff Members</h3>
        </div>

        <?php if (count($staff) === 0): ?>
        <div class="staff-empty">
            No staff members found
        </div>
        <?php else: ?>
        <?php foreach ($staff as $member): ?>
        <div class="staff-member-row">
            <div class="staff-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
            <div class="staff-member-info">
                <div class="staff-name-row">
                    <h3 class="staff-name"><?php echo htmlspecialchars($member['full_name']); ?></h3>
                    <span class="staff-role-badge"><?php echo $roleLabels[$member['role']] ?? ucfirst($member['role']); ?></span>
                </div>
                <p class="staff-details">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                    </svg>
                    <?php echo htmlspecialchars($member['email']); ?>
                </p>
                <?php if ($member['created_at']): ?>
                <p class="staff-details" style="font-size: 12px;">
                    Joined: <?php echo date('M d, Y', strtotime($member['created_at'])); ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="staff-actions">
                <button class="btn-edit" onclick='editStaff(<?php echo json_encode($member); ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this staff member? This action cannot be undone.');">
                    <input type="hidden" name="id" value="<?php echo $member['id']; ?>">
                    <button type="submit" name="delete_staff" class="btn-delete">
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

<!-- Staff Modal -->
<div id="staffModal" class="staff-modal">
    <div class="staff-modal-container">
        <h2 class="staff-modal-title" id="modalTitle">Add New Staff Member</h2>
        <form method="POST">
            <input type="hidden" name="id" id="staffId">
            
            <div class="staff-form-grid">
                <div class="staff-form-group">
                    <label class="staff-form-label">Full Name *</label>
                    <input type="text" name="full_name" id="fullName" class="staff-form-input" required>
                </div>
                
                <div class="staff-form-group">
                    <label class="staff-form-label">Email *</label>
                    <input type="email" name="email" id="email" class="staff-form-input" required>
                </div>
                
                <div class="staff-form-group">
                    <label class="staff-form-label">Role *</label>
                    <select name="role" id="role" class="staff-form-select" required>
                        <option value="doctor">Doctor</option>
                        <option value="nurse">Nurse</option>
                        <option value="admin">Administrator</option>
                        <option value="technician">Technician</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="staff-form-group">
                    <label class="staff-form-label" id="passwordLabel">Password *</label>
                    <input type="password" name="password" id="password" class="staff-form-input">
                    <small style="font-size: 12px; color: var(--text-secondary);" id="passwordHint">Leave blank to keep current password</small>
                </div>
            </div>
            
            <div class="staff-modal-footer">
                <button type="button" class="staff-btn-cancel" onclick="closeStaffModal()">Cancel</button>
                <button type="submit" name="save_staff" class="staff-btn-submit" id="submitBtn">Add Staff</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openStaffModal() {
    document.getElementById('staffModal').classList.add('active');
    document.getElementById('modalTitle').textContent = 'Add New Staff Member';
    document.getElementById('submitBtn').textContent = 'Add Staff';
    document.getElementById('passwordLabel').textContent = 'Password *';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').style.display = 'none';
    document.body.style.overflow = 'hidden';
}

function closeStaffModal() {
    document.getElementById('staffModal').classList.remove('active');
    document.querySelector('#staffModal form').reset();
    document.getElementById('staffId').value = '';
    document.body.style.overflow = '';
}

function editStaff(member) {
    document.getElementById('staffId').value = member.id;
    document.getElementById('fullName').value = member.full_name;
    document.getElementById('email').value = member.email;
    document.getElementById('role').value = member.role;
    document.getElementById('password').value = '';
    
    document.getElementById('modalTitle').textContent = 'Edit Staff Member';
    document.getElementById('submitBtn').textContent = 'Update Staff';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('password').required = false;
    document.getElementById('passwordHint').style.display = 'block';
    document.getElementById('staffModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modal on overlay click
document.getElementById('staffModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStaffModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStaffModal();
    }
});
</script>
</body>
</html>