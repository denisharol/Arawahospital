<?php
// pages/patients.php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Patients - Arawa Hospital';

// Delete patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_patient'])) {
    $patientId = $_POST['patient_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM patients WHERE id = ?");
        $stmt->execute([$patientId]);
        $_SESSION['success_message'] = 'Patient deleted successfully';
        header('Location: patients.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to delete patient';
    }
}

// Update patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_patient'])) {
    $id = $_POST['id'];
    $fullName = trim($_POST['full_name']);
    $phone = trim($_POST['phone_number']);
    $dateOfBirth = $_POST['date_of_birth'];
    $age = $_POST['age'];
    $gender = $_POST['gender'];
    $idNumber = trim($_POST['id_number']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET full_name = ?, phone_number = ?, date_of_birth = ?, 
                age = ?, gender = ?, id_number = ?, email = ?, address = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            ucwords(strtolower($fullName)),
            $phone,
            $dateOfBirth,
            $age,
            $gender,
            $idNumber,
            $email ?: null,
            $address,
            $id
        ]);
        
        $_SESSION['success_message'] = 'Patient updated successfully';
        header('Location: patients.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to update patient';
    }
}

// Fetch patients
$searchQuery = $_GET['search'] ?? '';
$sql = "SELECT * FROM patients WHERE status = 'active'";

if (!empty($searchQuery)) {
    $sql .= " AND (full_name LIKE ? OR patient_id LIKE ? OR email LIKE ? OR address LIKE ? OR id_number LIKE ?)";
    $stmt = $pdo->prepare($sql);
    $searchTerm = "%$searchQuery%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} else {
    $stmt = $pdo->query($sql);
}

$patients = $stmt->fetchAll();
$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

function getAvatarInitials($name) {
    $nameParts = explode(' ', trim($name));
    if (count($nameParts) > 1) {
        return strtoupper($nameParts[0][0] . $nameParts[count($nameParts) - 1][0]);
    }
    return strtoupper(substr($name, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/patients.css">
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="patients-header">
        <div class="patients-header-content">
            <h1>Patients</h1>
            <p>Manage patient records and information</p>
        </div>
        <div class="patients-header-actions">
            <button class="patients-btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Import
            </button>
            <button class="patients-btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Export
            </button>
            <a href="registration.php" class="patients-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <line x1="19" y1="8" x2="19" y2="14"></line>
                    <line x1="22" y1="11" x2="16" y2="11"></line>
                </svg>
                Add Patient
            </a>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Search Section -->
    <div class="patients-search-section">
        <div class="patients-search-wrapper">
            <svg class="patients-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
            </svg>
            <form method="GET" style="width: 100%;">
                <input 
                    type="text" 
                    name="search"
                    class="patients-search-input" 
                    placeholder="Search patients by name, email, ID, or address..."
                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                />
            </form>
        </div>
        <div class="patients-count">
            <?php echo count($patients); ?> patients found
        </div>
    </div>

    <!-- Table -->
    <div class="patients-table">
        <div class="patients-table-header">
            <h3>Patient List</h3>
        </div>

        <?php if (count($patients) === 0): ?>
        <div class="patients-empty">
            <?php echo $searchQuery ? 'No patients found matching your search' : 'No patients found'; ?>
        </div>
        <?php else: ?>
        <?php foreach ($patients as $patient): ?>
        <div class="patient-row">
            <div class="patient-avatar">
                <?php echo getAvatarInitials($patient['full_name']); ?>
            </div>
            <div class="patient-info">
                <h3 class="patient-name"><?php echo htmlspecialchars($patient['full_name']); ?></h3>
                <p class="patient-meta">
                    <?php echo htmlspecialchars($patient['gender']); ?>, Age <?php echo $patient['age']; ?> • ID: <?php echo htmlspecialchars($patient['patient_id']); ?>
                </p>
                <div class="patient-details">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        DOB: <?php echo date('d M Y', strtotime($patient['date_of_birth'])); ?>
                    </span>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        ID: <?php echo htmlspecialchars($patient['id_number']); ?>
                    </span>
                    <?php if ($patient['email']): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                            <path d="m2 7 10 6 10-6"></path>
                        </svg>
                        <?php echo htmlspecialchars($patient['email']); ?>
                    </span>
                    <?php endif; ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                            <circle cx="12" cy="10" r="3"></circle>
                        </svg>
                        <?php echo htmlspecialchars($patient['address']); ?>
                    </span>
                </div>
            </div>
            
            <div class="patient-actions-wrapper">
                <button class="patient-action-btn" onclick="toggleActions(<?php echo $patient['id']; ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-secondary)">
                        <circle cx="12" cy="12" r="1"></circle>
                        <circle cx="19" cy="12" r="1"></circle>
                        <circle cx="5" cy="12" r="1"></circle>
                    </svg>
                </button>
                
                <div class="patient-actions-dropdown" id="actions-<?php echo $patient['id']; ?>">
                    <button class="patient-dropdown-action edit" onclick="openEditModal(<?php echo $patient['id']; ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit Patient
                    </button>
                    <button class="patient-dropdown-action delete" onclick="confirmDelete(<?php echo $patient['id']; ?>, '<?php echo addslashes($patient['full_name']); ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                        Delete Patient
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="patients-modal">
    <div class="patients-modal-overlay" onclick="closeEditModal()"></div>
    <div class="patients-modal-container">
        <div class="patients-modal-header">
            <h2>Edit Patient</h2>
            <button class="patients-modal-close" onclick="closeEditModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <form method="POST">
            <div class="patients-modal-body">
                <h3>Patient Details</h3>
                <input type="hidden" name="id" id="edit_id">
                
                <div class="patients-form-grid">
                    <div class="patients-form-group">
                        <label for="edit_full_name">Patient Name *</label>
                        <input type="text" id="edit_full_name" name="full_name" required>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_phone_number">Phone *</label>
                        <input type="tel" id="edit_phone_number" name="phone_number" required>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_date_of_birth">Date of Birth *</label>
                        <input type="date" id="edit_date_of_birth" name="date_of_birth" onchange="calculateEditAge()" required>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_age">Age *</label>
                        <input type="number" id="edit_age" name="age" readonly required>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_gender">Gender *</label>
                        <select id="edit_gender" name="gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_id_number">ID Number *</label>
                        <input type="text" id="edit_id_number" name="id_number" required>
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email">
                    </div>
                    
                    <div class="patients-form-group">
                        <label for="edit_address">Address *</label>
                        <input type="text" id="edit_address" name="address" required>
                    </div>
                </div>
            </div>
            <div class="patients-modal-footer">
                <button type="button" class="patients-btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="update_patient" class="patients-btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="patient_id" id="delete_patient_id">
    <input type="hidden" name="delete_patient" value="1">
</form>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script>
const patientsData = <?php echo json_encode($patients); ?>;

function toggleActions(id) {
    const dropdown = document.getElementById('actions-' + id);
    const allDropdowns = document.querySelectorAll('.patient-actions-dropdown');
    allDropdowns.forEach(d => {
        if (d.id !== 'actions-' + id) d.classList.remove('active');
    });
    dropdown.classList.toggle('active');
}

function openEditModal(id) {
    const patient = patientsData.find(p => p.id == id);
    if (!patient) return;
    
    document.getElementById('edit_id').value = patient.id;
    document.getElementById('edit_full_name').value = patient.full_name;
    document.getElementById('edit_phone_number').value = patient.phone_number;
    document.getElementById('edit_date_of_birth').value = patient.date_of_birth;
    document.getElementById('edit_age').value = patient.age;
    document.getElementById('edit_gender').value = patient.gender;
    document.getElementById('edit_id_number').value = patient.id_number;
    document.getElementById('edit_email').value = patient.email || '';
    document.getElementById('edit_address').value = patient.address;
    
    document.getElementById('editModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    document.body.style.overflow = '';
}

function calculateEditAge() {
    const dob = document.getElementById('edit_date_of_birth').value;
    if (dob) {
        const birthDate = new Date(dob);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
        document.getElementById('edit_age').value = age > 0 ? age : '';
    }
}

function confirmDelete(id, name) {
    if (confirm('Are you sure you want to delete patient "' + name + '"? This action cannot be undone.')) {
        document.getElementById('delete_patient_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.patient-actions-wrapper')) {
        document.querySelectorAll('.patient-actions-dropdown').forEach(d => d.classList.remove('active'));
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>
</body>
</html>