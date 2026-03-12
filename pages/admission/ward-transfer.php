<?php
// pages/admission/ward-transfer.php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Ward Transfer - Arawa Hospital';

// Get search query
$searchQuery = $_GET['search'] ?? '';

// Fetch transfer history
$sql = "SELECT t.*, 
        p.patient_id, p.full_name as patient_name,
        fr.room_number as from_room_number, fr.ward_name as from_ward_name,
        tr.room_number as to_room_number, tr.ward_name as to_ward_name,
        s.full_name as transferred_by_name
        FROM ward_transfers t
        LEFT JOIN patients p ON t.patient_id = p.id
        LEFT JOIN rooms fr ON t.from_room_id = fr.id
        LEFT JOIN rooms tr ON t.to_room_id = tr.id
        LEFT JOIN staff_users s ON t.transferred_by = s.id
        WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR fr.room_number LIKE ? OR tr.room_number LIKE ?)";
}

$sql .= " ORDER BY t.transfer_date DESC, t.created_at DESC";

$stmt = $pdo->prepare($sql);

if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} else {
    $stmt->execute();
}

$transfers = $stmt->fetchAll();

// Fetch admitted patients
$admittedStmt = $pdo->query("
    SELECT a.*, p.patient_id, p.full_name, p.phone_number, p.age, p.gender,
           r.room_number, r.ward_name, r.room_type
    FROM admissions a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN rooms r ON a.room_id = r.id
    WHERE a.status = 'admitted'
");
$admittedPatients = $admittedStmt->fetchAll();

// Fetch available rooms
$roomsStmt = $pdo->query("SELECT id, room_number, ward_name, room_type, floor, capacity, occupancy, daily_rate FROM rooms WHERE status = 'available'");
$rooms = $roomsStmt->fetchAll();

// Fetch doctors
$doctorsStmt = $pdo->query("SELECT id, full_name, email FROM staff_users WHERE role = 'doctor'");
$doctors = $doctorsStmt->fetchAll();

// Handle transfer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_patient'])) {
    $admissionId = $_POST['admission_id'];
    $oldRoomId = $_POST['old_room_id'];
    $newRoomId = $_POST['new_room_id'];
    $transferDate = $_POST['transfer_date'];
    $transferredBy = $_POST['transferred_by'];
    $admissionDate = $_POST['admission_date'];
    
    // Calculate days in previous room
    $days = (strtotime($transferDate) - strtotime($admissionDate)) / (60 * 60 * 24);
    $daysInPreviousRoom = ceil($days);
    
    try {
        $pdo->beginTransaction();
        
        // Update admission with new room
        $stmt = $pdo->prepare("UPDATE admissions SET room_id = ? WHERE id = ?");
        $stmt->execute([$newRoomId, $admissionId]);
        
        // Get patient ID
        $patientStmt = $pdo->prepare("SELECT patient_id FROM admissions WHERE id = ?");
        $patientStmt->execute([$admissionId]);
        $patientId = $patientStmt->fetchColumn();
        
        // Create transfer record
        $transferStmt = $pdo->prepare("
            INSERT INTO ward_transfers 
            (patient_id, admission_id, from_room_id, to_room_id, transfer_date, transferred_by, days_in_previous_room)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $transferStmt->execute([
            $patientId, 
            $admissionId, 
            $oldRoomId, 
            $newRoomId, 
            $transferDate, 
            $transferredBy, 
            $daysInPreviousRoom
        ]);
        
        // Update room occupancy (decrement old, increment new)
        $pdo->prepare("UPDATE rooms SET occupancy = GREATEST(0, occupancy - 1) WHERE id = ?")->execute([$oldRoomId]);
        $pdo->prepare("UPDATE rooms SET occupancy = occupancy + 1 WHERE id = ?")->execute([$newRoomId]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = 'Patient transferred successfully';
        header('Location: ward-transfer.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Failed to transfer patient: ' . $e->getMessage();
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
    <link rel="stylesheet" href="../../assets/css/transfer.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="transfer-header">
        <div class="transfer-header-content">
            <h1>Ward Transfer</h1>
            <p>Transfer patients between wards and rooms.</p>
        </div>
        <div class="transfer-header-actions">
            <div class="transfer-search-wrapper">
                <svg class="transfer-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input 
                        type="text" 
                        name="search"
                        class="transfer-search-input" 
                        placeholder="Search transfers..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="transfer-btn-primary" onclick="openTransferModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
                Transfer Patient
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Stats Bar -->
    <div class="transfer-stats-bar">
        <div class="transfer-count-badge">
            <?php echo count($transfers); ?> transfers found
        </div>
        <div class="transfer-admitted-badge">
            <?php echo count($admittedPatients); ?> patients currently admitted
        </div>
    </div>

    <!-- Table -->
    <div class="transfer-table">
        <div class="transfer-table-header">
            <h3>Transfer History</h3>
        </div>

        <?php if (count($transfers) === 0): ?>
        <div class="transfer-empty">
            No transfers found
        </div>
        <?php else: ?>
        <?php foreach ($transfers as $transfer): ?>
        <div class="transfer-row">
            <div class="transfer-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </div>
            <div class="transfer-info">
                <div class="transfer-name-row">
                    <h3 class="transfer-name"><?php echo htmlspecialchars($transfer['patient_name']); ?></h3>
                    <span class="transfer-patient-id"><?php echo htmlspecialchars($transfer['patient_id']); ?></span>
                </div>
                <div class="transfer-rooms-row">
                    <p class="transfer-room-info">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2 4v16"></path>
                            <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                            <path d="M2 17h20"></path>
                            <path d="M6 8v9"></path>
                        </svg>
                        From: <span class="transfer-from-room">Room <?php echo htmlspecialchars($transfer['from_room_number']); ?> - <?php echo htmlspecialchars($transfer['from_ward_name']); ?></span>
                    </p>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-secondary)">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                    <p class="transfer-room-info">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2 4v16"></path>
                            <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                            <path d="M2 17h20"></path>
                            <path d="M6 8v9"></path>
                        </svg>
                        To: <span class="transfer-to-room">Room <?php echo htmlspecialchars($transfer['to_room_number']); ?> - <?php echo htmlspecialchars($transfer['to_ward_name']); ?></span>
                    </p>
                </div>
                <p class="transfer-days-info">
                    <?php echo $transfer['days_in_previous_room']; ?> <?php echo $transfer['days_in_previous_room'] == 1 ? 'day' : 'days'; ?> in Room <?php echo htmlspecialchars($transfer['from_room_number']); ?>
                </p>
                <p class="transfer-meta">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    Transferred: <?php echo date('F d, Y', strtotime($transfer['transfer_date'])); ?> • By: <?php echo htmlspecialchars($transfer['transferred_by_name']); ?>
                </p>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transferModal" class="transfer-modal">
    <div class="transfer-modal-container">
        <h2 class="transfer-modal-title">Transfer Patient</h2>
        <form method="POST" id="transferForm">
            <div class="transfer-form-group">
                <label class="transfer-form-label">Patient *</label>
                <div class="transfer-dropdown-wrapper">
                    <input 
                        type="text" 
                        id="patientSearch"
                        class="transfer-form-input transfer-dropdown-input" 
                        placeholder="Search and select patient..."
                        autocomplete="off"
                        onclick="toggleDropdown('patient')"
                    />
                    <input type="hidden" name="admission_id" id="selectedAdmissionId" required>
                    <input type="hidden" name="old_room_id" id="selectedOldRoomId" required>
                    <input type="hidden" name="admission_date" id="selectedAdmissionDate" required>
                    <div id="patientDropdown" class="transfer-dropdown" style="display: none;">
                        <?php foreach ($admittedPatients as $patient): ?>
                        <div class="transfer-dropdown-item" onclick='selectPatient(<?php echo json_encode($patient); ?>)'>
                            <div class="transfer-dropdown-item-title"><?php echo htmlspecialchars($patient['full_name']); ?></div>
                            <div class="transfer-dropdown-item-subtitle">
                                <?php echo htmlspecialchars($patient['patient_id']); ?> • Current: Room <?php echo htmlspecialchars($patient['room_number']); ?> - <?php echo htmlspecialchars($patient['ward_name']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div id="patientInfo" style="display: none;">
                <div class="transfer-form-grid">
                    <div class="transfer-form-group">
                        <label class="transfer-form-label">Current Room</label>
                        <input type="text" id="currentRoom" class="transfer-form-input" disabled>
                    </div>
                    <div class="transfer-form-group">
                        <label class="transfer-form-label">Phone Number</label>
                        <input type="text" id="patientPhone" class="transfer-form-input" disabled>
                    </div>
                    <div class="transfer-form-group">
                        <label class="transfer-form-label">Age</label>
                        <input type="text" id="patientAge" class="transfer-form-input" disabled>
                    </div>
                </div>

                <div class="transfer-form-group">
                    <label class="transfer-form-label">New Room *</label>
                    <div class="transfer-dropdown-wrapper">
                        <input 
                            type="text" 
                            id="roomSearch"
                            class="transfer-form-input transfer-dropdown-input" 
                            placeholder="Search and select room..."
                            autocomplete="off"
                            onclick="toggleDropdown('room')"
                        />
                        <input type="hidden" name="new_room_id" id="selectedNewRoomId" required>
                        <div id="roomDropdown" class="transfer-dropdown" style="display: none;">
                            <?php foreach ($rooms as $room): ?>
                            <div class="transfer-dropdown-item" onclick='selectRoom(<?php echo json_encode($room); ?>)'>
                                <div class="transfer-dropdown-item-title">Room <?php echo htmlspecialchars($room['room_number']); ?> - <?php echo htmlspecialchars($room['ward_name']); ?></div>
                                <div class="transfer-dropdown-item-subtitle">
                                    <?php echo $roomTypeLabels[$room['room_type']] ?? $room['room_type']; ?> • <?php echo htmlspecialchars($room['floor']); ?> • <?php echo $room['occupancy']; ?>/<?php echo $room['capacity']; ?> beds • KSh <?php echo number_format($room['daily_rate']); ?>/day
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="transfer-form-grid-2">
                    <div class="transfer-form-group">
                        <label class="transfer-form-label">Transfer Date *</label>
                        <input type="date" name="transfer_date" id="transferDate" class="transfer-form-input" value="<?php echo date('Y-m-d'); ?>" required onchange="calculateDays()">
                    </div>
                    <div class="transfer-form-group">
                        <label class="transfer-form-label">Transferred By (Doctor) *</label>
                        <select name="transferred_by" class="transfer-form-input" required>
                            <option value="">Select doctor...</option>
                            <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo $doctor['id']; ?>">
                                <?php echo htmlspecialchars($doctor['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="daysInfo" class="transfer-info-box" style="display: none;">
                    <p class="transfer-info-text" id="daysText"></p>
                </div>
            </div>

            <div class="transfer-modal-footer">
                <button type="button" class="transfer-btn-cancel" onclick="closeTransferModal()">Cancel</button>
                <button type="submit" name="transfer_patient" class="transfer-btn-submit" id="submitBtn" disabled>Transfer Patient</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
let selectedAdmissionDate = null;
let currentRoomNumber = null;

function openTransferModal() {
    document.getElementById('transferModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeTransferModal() {
    document.getElementById('transferModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('transferForm').reset();
    document.getElementById('patientInfo').style.display = 'none';
    document.getElementById('patientDropdown').style.display = 'none';
    document.getElementById('roomDropdown').style.display = 'none';
    document.getElementById('daysInfo').style.display = 'none';
}

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

function selectPatient(patient) {
    document.getElementById('patientSearch').value = `${patient.full_name} (${patient.patient_id})`;
    document.getElementById('selectedAdmissionId').value = patient.id;
    document.getElementById('selectedOldRoomId').value = patient.room_id;
    document.getElementById('selectedAdmissionDate').value = patient.admission_date;
    document.getElementById('currentRoom').value = `Room ${patient.room_number} - ${patient.ward_name}`;
    document.getElementById('patientPhone').value = patient.phone_number;
    document.getElementById('patientAge').value = `${patient.age} years`;
    document.getElementById('patientInfo').style.display = 'block';
    document.getElementById('patientDropdown').style.display = 'none';
    
    selectedAdmissionDate = patient.admission_date;
    currentRoomNumber = patient.room_number;
    calculateDays();
}

function selectRoom(room) {
    document.getElementById('roomSearch').value = `Room ${room.room_number} - ${room.ward_name}`;
    document.getElementById('selectedNewRoomId').value = room.id;
    document.getElementById('roomDropdown').style.display = 'none';
    document.getElementById('submitBtn').disabled = false;
    calculateDays();
}

function calculateDays() {
    if (!selectedAdmissionDate || !currentRoomNumber) return;
    
    const transferDate = document.getElementById('transferDate').value;
    if (!transferDate) return;
    
    const admission = new Date(selectedAdmissionDate);
    const transfer = new Date(transferDate);
    const diffTime = Math.abs(transfer - admission);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    document.getElementById('daysText').textContent = 
        `Patient will have stayed ${diffDays} ${diffDays === 1 ? 'day' : 'days'} in Room ${currentRoomNumber}`;
    document.getElementById('daysInfo').style.display = 'block';
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.transfer-dropdown-wrapper')) {
        document.getElementById('patientDropdown').style.display = 'none';
        document.getElementById('roomDropdown').style.display = 'none';
    }
});

// Close modal on overlay click
document.getElementById('transferModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTransferModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTransferModal();
    }
});
</script>
</body>
</html>