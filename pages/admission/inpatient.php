<?php
// pages/admission/inpatient.php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Inpatient Admissions - Arawa Hospital';

// Get filter and search
$searchQuery = $_GET['search'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

// Fetch admissions
$sql = "SELECT a.*, p.patient_id, p.full_name as patient_name, p.phone_number, p.age, p.gender, 
        r.room_number, r.ward_name, r.room_type, s.full_name as admitted_by_name
        FROM admissions a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN rooms r ON a.room_id = r.id
        LEFT JOIN staff_users s ON a.admitted_by = s.id
        WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR r.room_number LIKE ?)";
}

if ($filterStatus !== 'all') {
    $sql .= " AND a.status = ?";
}

$sql .= " ORDER BY FIELD(a.status, 'admitted', 'discharged'), a.admission_date DESC";

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

$admissions = $stmt->fetchAll();

// Fetch patients (for dropdown)
$patientsStmt = $pdo->query("SELECT id, patient_id, full_name, phone_number, age, gender FROM patients WHERE status = 'active'");
$patients = $patientsStmt->fetchAll();

// Fetch available rooms
$roomsStmt = $pdo->query("SELECT id, room_number, ward_name, room_type, floor, capacity, occupancy, daily_rate FROM rooms WHERE status = 'available'");
$rooms = $roomsStmt->fetchAll();

// Fetch doctors
$doctorsStmt = $pdo->query("SELECT id, full_name, email FROM staff_users WHERE role = 'doctor'");
$doctors = $doctorsStmt->fetchAll();

// Handle admission submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_patient'])) {
    $patientId = $_POST['patient_id'];
    $roomId = $_POST['room_id'];
    $admissionDate = $_POST['admission_date'];
    $admittedBy = $_POST['admitted_by'];
    $diagnosis = trim($_POST['diagnosis']);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admissions (patient_id, room_id, admission_date, admitted_by, diagnosis, status)
            VALUES (?, ?, ?, ?, ?, 'admitted')
        ");
        
        $stmt->execute([$patientId, $roomId, $admissionDate, $admittedBy, $diagnosis]);
        
        // Update room occupancy
        $pdo->prepare("UPDATE rooms SET occupancy = occupancy + 1 WHERE id = ?")->execute([$roomId]);
        
        $_SESSION['success_message'] = 'Patient admitted successfully';
        header('Location: inpatient.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to admit patient';
    }
}

// Handle discharge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discharge_patient'])) {
    $admissionId = $_POST['admission_id'];
    
    try {
        // Get room_id before discharge
        $roomStmt = $pdo->prepare("SELECT room_id FROM admissions WHERE id = ?");
        $roomStmt->execute([$admissionId]);
        $roomId = $roomStmt->fetchColumn();
        
        // Discharge patient
        $stmt = $pdo->prepare("UPDATE admissions SET status = 'discharged', discharge_date = CURRENT_DATE WHERE id = ?");
        $stmt->execute([$admissionId]);
        
        // Update room occupancy
        $pdo->prepare("UPDATE rooms SET occupancy = GREATEST(0, occupancy - 1) WHERE id = ?")->execute([$roomId]);
        
        $_SESSION['success_message'] = 'Patient discharged successfully';
        header('Location: inpatient.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to discharge patient';
    }
}

$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

$roomTypeLabels = [
    'private' => 'Private',
    'semi-private' => 'Semi-Private',
    'general' => 'General',
    'icu' => 'ICU',
    'ot' => 'Operating Theater'
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
    <link rel="stylesheet" href="../../assets/css/inpatient.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="inpatient-header">
        <div class="inpatient-header-content">
            <h1>Inpatient Admissions</h1>
            <p>Manage patient admissions and room assignments.</p>
        </div>
        <div class="inpatient-header-actions">
            <div class="inpatient-search-wrapper">
                <svg class="inpatient-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="inpatient-search-input" 
                        placeholder="Search admissions..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="inpatient-btn-primary" onclick="openAdmitModal()">
                Admit Patient
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="inpatient-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="inpatient-filter-label">Filter by status:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="status" class="inpatient-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="admitted" <?php echo $filterStatus === 'admitted' ? 'selected' : ''; ?>>Admitted</option>
                    <option value="discharged" <?php echo $filterStatus === 'discharged' ? 'selected' : ''; ?>>Discharged</option>
                </select>
            </form>
        </div>
        <div class="inpatient-count-badge">
            <?php echo count($admissions); ?> admissions found
        </div>
    </div>

    <!-- Table -->
    <div class="inpatient-table">
        <div class="inpatient-table-header">
            <h3>Current Admissions</h3>
        </div>

        <?php if (count($admissions) === 0): ?>
        <div class="inpatient-empty">
            No admissions found
        </div>
        <?php else: ?>
        <?php foreach ($admissions as $admission): ?>
        <div class="admission-row">
            <div class="admission-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
            <div class="admission-info">
                <div class="admission-name-row">
                    <h3 class="admission-name"><?php echo htmlspecialchars($admission['patient_name']); ?></h3>
                    <span class="admission-patient-id"><?php echo htmlspecialchars($admission['patient_id']); ?></span>
                    <span class="admission-status-badge admission-status-<?php echo $admission['status']; ?>">
                        <?php echo ucfirst($admission['status']); ?>
                    </span>
                </div>
                <p class="admission-details">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <?php echo htmlspecialchars($admission['phone_number']); ?> • <?php echo $admission['age']; ?> years • <?php echo htmlspecialchars($admission['gender']); ?>
                </p>
                <p class="admission-details">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M2 4v16"></path>
                        <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                        <path d="M2 17h20"></path>
                        <path d="M6 8v9"></path>
                    </svg>
                    Room <?php echo htmlspecialchars($admission['room_number']); ?> • <?php echo htmlspecialchars($admission['ward_name']); ?> • <?php echo $roomTypeLabels[$admission['room_type']] ?? $admission['room_type']; ?>
                </p>
                <p class="admission-details" style="font-size: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    Admitted: <?php echo date('d M Y', strtotime($admission['admission_date'])); ?> • By: <?php echo htmlspecialchars($admission['admitted_by_name']); ?>
                    <?php if ($admission['discharge_date']): ?>
                        • Discharged: <?php echo date('d M Y', strtotime($admission['discharge_date'])); ?>
                    <?php endif; ?>
                </p>
                <p class="admission-diagnosis">
                    Diagnosis: <?php echo htmlspecialchars($admission['diagnosis']); ?>
                </p>
            </div>
            <div class="admission-actions">
                <?php if ($admission['status'] === 'admitted'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to discharge this patient?');">
                    <input type="hidden" name="admission_id" value="<?php echo $admission['id']; ?>">
                    <button type="submit" name="discharge_patient" class="btn-discharge">
                        Discharge
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Admit Modal -->
<div id="admitModal" class="inpatient-modal">
    <div class="inpatient-modal-container">
        <h2 class="inpatient-modal-title">Admit Patient</h2>
        <form method="POST" id="admitForm">
            <div class="inpatient-form-group">
                <label class="inpatient-form-label">Patient *</label>
                <div class="inpatient-dropdown-wrapper">
                    <input 
                        type="text" 
                        id="patientSearch"
                        class="inpatient-form-input inpatient-dropdown-input" 
                        placeholder="Search and select patient..."
                        autocomplete="off"
                        onclick="toggleDropdown('patient')"
                    />
                    <input type="hidden" name="patient_id" id="selectedPatientId" required>
                    <div id="patientDropdown" class="inpatient-dropdown" style="display: none;">
                        <?php foreach ($patients as $patient): ?>
                        <div class="inpatient-dropdown-item" onclick="selectPatient(<?php echo htmlspecialchars(json_encode($patient)); ?>)">
                            <div class="inpatient-dropdown-item-title"><?php echo htmlspecialchars($patient['full_name']); ?></div>
                            <div class="inpatient-dropdown-item-subtitle">
                                <?php echo htmlspecialchars($patient['patient_id']); ?> • <?php echo $patient['age']; ?> years • <?php echo htmlspecialchars($patient['gender']); ?> • <?php echo htmlspecialchars($patient['phone_number']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div id="patientInfo" style="display: none;">
                <div class="inpatient-form-grid">
                    <div class="inpatient-form-group">
                        <label class="inpatient-form-label">Phone Number</label>
                        <input type="text" id="patientPhone" class="inpatient-form-input" disabled>
                    </div>
                    <div class="inpatient-form-group">
                        <label class="inpatient-form-label">Age</label>
                        <input type="text" id="patientAge" class="inpatient-form-input" disabled>
                    </div>
                </div>

                <div class="inpatient-form-group">
                    <label class="inpatient-form-label">Room *</label>
                    <div class="inpatient-dropdown-wrapper">
                        <input 
                            type="text" 
                            id="roomSearch"
                            class="inpatient-form-input inpatient-dropdown-input" 
                            placeholder="Search and select room..."
                            autocomplete="off"
                            onclick="toggleDropdown('room')"
                        />
                        <input type="hidden" name="room_id" id="selectedRoomId" required>
                        <div id="roomDropdown" class="inpatient-dropdown" style="display: none;">
                            <?php foreach ($rooms as $room): ?>
                            <div class="inpatient-dropdown-item" onclick="selectRoom(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                                <div class="inpatient-dropdown-item-title">Room <?php echo htmlspecialchars($room['room_number']); ?> - <?php echo htmlspecialchars($room['ward_name']); ?></div>
                                <div class="inpatient-dropdown-item-subtitle">
                                    <?php echo $roomTypeLabels[$room['room_type']] ?? $room['room_type']; ?> • <?php echo htmlspecialchars($room['floor']); ?> • <?php echo $room['occupancy']; ?>/<?php echo $room['capacity']; ?> beds • KSh <?php echo number_format($room['daily_rate']); ?>/day
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="inpatient-form-grid">
                    <div class="inpatient-form-group">
                        <label class="inpatient-form-label">Admission Date *</label>
                        <input type="date" name="admission_date" class="inpatient-form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="inpatient-form-group">
                        <label class="inpatient-form-label">Admitted By (Doctor) *</label>
                        <select name="admitted_by" class="inpatient-form-input" required>
                            <option value="">Select doctor...</option>
                            <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo $doctor['id']; ?>">
                                <?php echo htmlspecialchars($doctor['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="inpatient-form-group">
                    <label class="inpatient-form-label">Diagnosis *</label>
                    <textarea name="diagnosis" class="inpatient-form-textarea" placeholder="Enter diagnosis..." required></textarea>
                </div>
            </div>

            <div class="inpatient-modal-footer">
                <button type="button" class="inpatient-btn-cancel" onclick="closeAdmitModal()">Cancel</button>
                <button type="submit" name="admit_patient" class="inpatient-btn-submit">Admit Patient</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="dischargeForm" style="display: none;">
    <input type="hidden" name="admission_id" id="dischargeId">
    <input type="hidden" name="discharge_patient" value="1">
</form>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openAdmitModal() {
    document.getElementById('admitModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeAdmitModal() {
    document.getElementById('admitModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('admitForm').reset();
    document.getElementById('patientInfo').style.display = 'none';
    document.getElementById('patientDropdown').style.display = 'none';
    document.getElementById('roomDropdown').style.display = 'none';
}

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

function selectPatient(patient) {
    document.getElementById('patientSearch').value = `${patient.full_name} (${patient.patient_id})`;
    document.getElementById('selectedPatientId').value = patient.id;
    document.getElementById('patientPhone').value = patient.phone_number;
    document.getElementById('patientAge').value = `${patient.age} years`;
    document.getElementById('patientInfo').style.display = 'block';
    document.getElementById('patientDropdown').style.display = 'none';
}

function selectRoom(room) {
    document.getElementById('roomSearch').value = `Room ${room.room_number} - ${room.ward_name}`;
    document.getElementById('selectedRoomId').value = room.id;
    document.getElementById('roomDropdown').style.display = 'none';
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.inpatient-dropdown-wrapper')) {
        document.getElementById('patientDropdown').style.display = 'none';
        document.getElementById('roomDropdown').style.display = 'none';
    }
});

// Close modal on overlay click
document.getElementById('admitModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAdmitModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAdmitModal();
    }
});
</script>
</body>
</html>