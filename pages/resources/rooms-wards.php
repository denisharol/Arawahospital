<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();
if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Rooms & Wards - Arawa Hospital';

// Get filter and search
$searchQuery = $_GET['search'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

// Fetch rooms
$sql = "SELECT * FROM rooms WHERE 1=1";

if (!empty($searchQuery)) {
    $sql .= " AND (room_number LIKE ? OR ward_name LIKE ?)";
}

if ($filterStatus !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY room_number ASC";

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

$rooms = $stmt->fetchAll();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_room'])) {
    $id = $_POST['id'] ?? null;
    $roomNumber = trim($_POST['room_number']);
    $wardName = trim($_POST['ward_name']);
    $roomType = $_POST['room_type'];
    $floor = $_POST['floor'];
    $capacity = intval($_POST['capacity']);
    $occupancy = intval($_POST['occupancy']);
    $dailyRate = floatval($_POST['daily_rate']);
    $status = $_POST['status'];
    
    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE rooms 
                SET room_number = ?, ward_name = ?, room_type = ?, floor = ?, 
                    capacity = ?, occupancy = ?, daily_rate = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$roomNumber, $wardName, $roomType, $floor, $capacity, $occupancy, $dailyRate, $status, $id]);
            $_SESSION['success_message'] = 'Room updated successfully';
        } else {
            // Create
            $stmt = $pdo->prepare("
                INSERT INTO rooms (room_number, ward_name, room_type, floor, capacity, occupancy, daily_rate, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$roomNumber, $wardName, $roomType, $floor, $capacity, $occupancy, $dailyRate, $status]);
            $_SESSION['success_message'] = 'Room created successfully';
        }
        
        header('Location: rooms.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to save room';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room'])) {
    $id = $_POST['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success_message'] = 'Room deleted successfully';
        header('Location: rooms.php');
        exit;
    } catch (PDOException $e) {
        $error = 'Failed to delete room';
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
    <link rel="stylesheet" href="../../assets/css/rooms.css">
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
    <!-- Header -->
    <div class="rooms-header">
        <div class="rooms-header-content">
            <h1>Rooms & Wards</h1>
            <p>Manage hospital rooms, wards, and their occupancy status.</p>
        </div>
        <div class="rooms-header-actions">
            <div class="rooms-search-wrapper">
                <svg class="rooms-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                    <input 
                        type="text" 
                        name="search"
                        class="rooms-search-input" 
                        placeholder="Search rooms..."
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                    />
                </form>
            </div>
            <button class="rooms-btn-primary" onclick="openRoomModal()">
                Add Room
            </button>
        </div>
    </div>

    <?php if ($successMessage): ?>
    <div class="patients-alert-success" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="rooms-filter-bar">
        <div style="display: flex; gap: 12px; align-items: center;">
            <span class="rooms-filter-label">Filter by status:</span>
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <select name="status" class="rooms-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Rooms</option>
                    <option value="available" <?php echo $filterStatus === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="occupied" <?php echo $filterStatus === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                    <option value="maintenance" <?php echo $filterStatus === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="reserved" <?php echo $filterStatus === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                </select>
            </form>
        </div>
        <div class="rooms-count-badge">
            <?php echo count($rooms); ?> rooms found
        </div>
    </div>

    <!-- Table -->
    <div class="rooms-table">
        <div class="rooms-table-header">
            <h3>All Rooms</h3>
        </div>

        <?php if (count($rooms) === 0): ?>
        <div class="rooms-empty">
            No rooms found
        </div>
        <?php else: ?>
        <?php foreach ($rooms as $room): ?>
        <div class="room-row">
            <div class="room-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                    <path d="M2 4v16"></path>
                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                    <path d="M2 17h20"></path>
                    <path d="M6 8v9"></path>
                </svg>
            </div>
            <div class="room-info">
                <div class="room-name-row">
                    <h3 class="room-name">Room <?php echo htmlspecialchars($room['room_number']); ?> - <?php echo htmlspecialchars($room['ward_name']); ?></h3>
                    <span class="room-type-badge"><?php echo $roomTypeLabels[$room['room_type']] ?? $room['room_type']; ?></span>
                    <span class="room-status <?php echo $room['status']; ?>">
                        <?php echo ucfirst($room['status']); ?>
                    </span>
                </div>
                <p class="room-details">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    </svg>
                    Floor: <?php echo htmlspecialchars($room['floor']); ?> • Occupancy: <?php echo $room['occupancy']; ?>/<?php echo $room['capacity']; ?> beds • KSh <?php echo number_format($room['daily_rate'], 2); ?>/day
                </p>
            </div>
            <div class="room-actions">
                <button class="btn-edit" onclick='editRoom(<?php echo json_encode($room); ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this room? This action cannot be undone.');">
                    <input type="hidden" name="id" value="<?php echo $room['id']; ?>">
                    <button type="submit" name="delete_room" class="btn-delete">
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

<!-- Room Modal -->
<div id="roomModal" class="rooms-modal">
    <div class="rooms-modal-container">
        <h2 class="rooms-modal-title" id="modalTitle">Add New Room</h2>
        <form method="POST">
            <input type="hidden" name="id" id="roomId">
            
            <div class="rooms-form-grid">
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Room Number *</label>
                    <input type="text" name="room_number" id="roomNumber" class="rooms-form-input" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Ward Name *</label>
                    <input type="text" name="ward_name" id="wardName" class="rooms-form-input" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Room Type *</label>
                    <select name="room_type" id="roomType" class="rooms-form-select" required>
                        <option value="private">Private</option>
                        <option value="semi-private">Semi-Private</option>
                        <option value="general">General</option>
                        <option value="icu">ICU</option>
                        <option value="ot">Operating Theater</option>
                    </select>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Floor *</label>
                    <input type="text" name="floor" id="floor" class="rooms-form-input" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Capacity *</label>
                    <input type="number" name="capacity" id="capacity" class="rooms-form-input" min="1" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Occupancy</label>
                    <input type="number" name="occupancy" id="occupancy" class="rooms-form-input" min="0" value="0" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Daily Rate (KSh) *</label>
                    <input type="number" step="0.01" name="daily_rate" id="dailyRate" class="rooms-form-input" required>
                </div>
                
                <div class="rooms-form-group">
                    <label class="rooms-form-label">Status</label>
                    <select name="status" id="roomStatus" class="rooms-form-select">
                        <option value="available">Available</option>
                        <option value="occupied">Occupied</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="reserved">Reserved</option>
                    </select>
                </div>
            </div>
            
            <div class="rooms-modal-footer">
                <button type="button" class="rooms-btn-cancel" onclick="closeRoomModal()">Cancel</button>
                <button type="submit" name="save_room" class="rooms-btn-submit" id="submitBtn">Add Room</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script>
function openRoomModal() {
    document.getElementById('roomModal').classList.add('active');
    document.getElementById('modalTitle').textContent = 'Add New Room';
    document.getElementById('submitBtn').textContent = 'Add Room';
    document.body.style.overflow = 'hidden';
}

function closeRoomModal() {
    document.getElementById('roomModal').classList.remove('active');
    document.querySelector('#roomModal form').reset();
    document.getElementById('roomId').value = '';
    document.body.style.overflow = '';
}

function editRoom(room) {
    document.getElementById('roomId').value = room.id;
    document.getElementById('roomNumber').value = room.room_number;
    document.getElementById('wardName').value = room.ward_name;
    document.getElementById('roomType').value = room.room_type;
    document.getElementById('floor').value = room.floor;
    document.getElementById('capacity').value = room.capacity;
    document.getElementById('occupancy').value = room.occupancy;
    document.getElementById('dailyRate').value = room.daily_rate;
    document.getElementById('roomStatus').value = room.status;
    
    document.getElementById('modalTitle').textContent = 'Edit Room';
    document.getElementById('submitBtn').textContent = 'Update Room';
    document.getElementById('roomModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close modal on overlay click
document.getElementById('roomModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRoomModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRoomModal();
    }
});
</script>
</body>
</html>