<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Medical Services - Arawa Hospital';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    function respond($data, $code = 200) {
        http_response_code($code);
        echo json_encode(['data' => $data]);
        exit;
    }

    function respondError($msg, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $msg]);
        exit;
    }

    if ($method === 'GET' && !$id) {
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $sql = 'SELECT * FROM services WHERE 1=1';
        $params = [];
        if ($search) {
            $sql .= ' AND (service_name LIKE :s1 OR department LIKE :s2)';
            $like = "%$search%";
            $params[':s1'] = $like; $params[':s2'] = $like;
        }
        if ($status && $status !== 'all') { $sql .= ' AND status = :status'; $params[':status'] = $status; }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST') {
        if (empty($body['serviceName']) || !isset($body['basePrice'])) respondError('Missing required fields');
        $stmt = $pdo->prepare('INSERT INTO services (service_name, category, department, base_price, status, requires_appointment) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$body['serviceName'], $body['category'] ?? 'consultation', $body['department'] ?? '', $body['basePrice'], $body['status'] ?? 'available', isset($body['requiresAppointment']) ? (int)$body['requiresAppointment'] : 1]);
        respond(['id' => $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        $stmt = $pdo->prepare('UPDATE services SET service_name=?, category=?, department=?, base_price=?, status=?, requires_appointment=? WHERE id=?');
        $stmt->execute([$body['serviceName'], $body['category'] ?? 'consultation', $body['department'] ?? '', $body['basePrice'], $body['status'] ?? 'available', isset($body['requiresAppointment']) ? (int)$body['requiresAppointment'] : 1, $id]);
        respond(['updated' => $id]);
    }

    if ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare('DELETE FROM services WHERE id=?');
        $stmt->execute([$id]);
        respond(['deleted' => $id]);
    }

    respondError('Not found', 404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $pageTitle; ?></title>
  <link rel="stylesheet" href="../../assets/css/theme.css">
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link rel="stylesheet" href="../../assets/css/sidebar.css">
  <link rel="stylesheet" href="../../assets/css/services.css">
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<?php include_once '../../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <div class="header">
    <div>
      <h1>Medical Services</h1>
      <p>Manage hospital services, procedures, and their pricing.</p>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i data-lucide="search" class="search-icon"></i>
        <input id="searchInput" type="text" placeholder="Search services..." oninput="onSearch()" />
      </div>
      <button class="btn-primary" onclick="openModal()">
        <i data-lucide="plus"></i> Add Service
      </button>
    </div>
  </div>

  <div class="filter-bar">
    <div class="filter-left">
      <span class="filter-label">Filter by status:</span>
      <select id="statusFilter" onchange="onFilterChange()">
        <option value="all">All Services</option>
        <option value="available">Available</option>
        <option value="unavailable">Unavailable</option>
      </select>
    </div>
    <div class="count-badge" id="countBadge">0 services found</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>All Services</h3>
    </div>
    <div id="listContainer">
      <div class="state-msg">Loading services...</div>
    </div>
  </div>

</div>
</div>

<div id="modal" class="modal-overlay" onclick="onOverlayClick(event)" style="display:none;">
  <div class="modal custom-scrollbar">
    <h2 id="modalTitle">Add New Service</h2>
    <div class="form-grid">
      <div class="field">
        <label>Service Name *</label>
        <input type="text" id="f_serviceName" />
      </div>
      <div class="field">
        <label>Category *</label>
        <select id="f_category">
          <option value="consultation">Consultation</option>
          <option value="surgery">Surgery</option>
          <option value="diagnostic">Diagnostic</option>
          <option value="therapy">Therapy</option>
          <option value="emergency">Emergency</option>
        </select>
      </div>
      <div class="field">
        <label>Department</label>
        <input type="text" id="f_department" />
      </div>
      <div class="field">
        <label>Base Price (KSh) *</label>
        <input type="number" id="f_basePrice" />
      </div>
      <div class="field">
        <label>Status</label>
        <select id="f_status">
          <option value="available">Available</option>
          <option value="unavailable">Unavailable</option>
        </select>
      </div>
      <div class="field">
        <label>Requires Appointment</label>
        <input type="checkbox" id="f_requiresAppointment" checked />
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="handleSave()" id="submitBtn">Add Service</button>
    </div>
  </div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
const API = 'services.php?api=1';
let services = [];
let editId = null;

const categoryLabels = { consultation:'Consultation', surgery:'Surgery', diagnostic:'Diagnostic', therapy:'Therapy', emergency:'Emergency' };

async function fetchServices() {
  const search = document.getElementById('searchInput').value;
  const status = document.getElementById('statusFilter').value;
  const params = new URLSearchParams({ search });
  if (status !== 'all') params.append('status', status);
  const res = await fetch(`${API}&${params}`);
  const json = await res.json();
  services = json.data || [];
  renderList();
}

function renderList() {
  const container = document.getElementById('listContainer');
  const search = document.getElementById('searchInput').value.toLowerCase();

  const filtered = services.filter(s =>
    s.service_name.toLowerCase().includes(search) ||
    (s.department||'').toLowerCase().includes(search)
  );

  document.getElementById('countBadge').textContent = `${filtered.length} services found`;

  if (filtered.length === 0) {
    container.innerHTML = '<div class="state-msg">No services found</div>';
    return;
  }

  container.innerHTML = filtered.map(s => `
    <div class="service-row">
      <div class="service-icon">
        <i data-lucide="activity"></i>
      </div>
      <div class="service-info">
        <div class="service-title-row">
          <h3>${s.service_name}</h3>
          <span class="badge badge-category">${categoryLabels[s.category] || s.category}</span>
          <span class="badge ${s.status === 'available' ? 'badge-available' : 'badge-unavailable'}">${s.status}</span>
        </div>
        <p class="sub">${s.department || ''}${s.requires_appointment ? ' • Appointment Required' : ''}</p>
        ${s.description ? `<p class="sub">${s.description}</p>` : ''}
      </div>
      <div class="service-price">
        <div class="price-amount">KSh ${parseFloat(s.base_price).toLocaleString('en-KE', {minimumFractionDigits:2})}</div>
        <div class="price-label">Base Price</div>
      </div>
      <div class="row-actions">
        <button class="btn-edit" onclick='handleEdit(${JSON.stringify(s)})'>
          <i data-lucide="edit-2"></i> Edit
        </button>
        <button class="btn-delete" onclick="handleDelete('${s.id}')">
          <i data-lucide="trash-2"></i> Delete
        </button>
      </div>
    </div>
  `).join('');

  lucide.createIcons();
}

function openModal() {
  editId = null;
  document.getElementById('modalTitle').textContent = 'Add New Service';
  document.getElementById('submitBtn').textContent = 'Add Service';
  document.getElementById('f_serviceName').value = '';
  document.getElementById('f_category').value = 'consultation';
  document.getElementById('f_department').value = '';
  document.getElementById('f_basePrice').value = '';
  document.getElementById('f_status').value = 'available';
  document.getElementById('f_requiresAppointment').checked = true;
  document.getElementById('modal').style.display = 'flex';
}

function handleEdit(s) {
  editId = s.id;
  document.getElementById('modalTitle').textContent = 'Edit Service';
  document.getElementById('submitBtn').textContent = 'Update Service';
  document.getElementById('f_serviceName').value = s.service_name;
  document.getElementById('f_category').value = s.category;
  document.getElementById('f_department').value = s.department || '';
  document.getElementById('f_basePrice').value = s.base_price;
  document.getElementById('f_status').value = s.status;
  document.getElementById('f_requiresAppointment').checked = !!parseInt(s.requires_appointment);
  document.getElementById('modal').style.display = 'flex';
}

async function handleSave() {
  const serviceName = document.getElementById('f_serviceName').value.trim();
  const basePrice = document.getElementById('f_basePrice').value;
  if (!serviceName || !basePrice) { alert('Please fill in all required fields'); return; }

  const payload = {
    serviceName,
    category: document.getElementById('f_category').value,
    department: document.getElementById('f_department').value,
    basePrice: parseFloat(basePrice),
    status: document.getElementById('f_status').value,
    requiresAppointment: document.getElementById('f_requiresAppointment').checked
  };

  if (editId) {
    await fetch(`${API}&id=${editId}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  } else {
    await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  }

  closeModal();
  fetchServices();
}

async function handleDelete(id) {
  if (!confirm('Are you sure you want to delete this service? This action cannot be undone.')) return;
  await fetch(`${API}&id=${id}`, { method: 'DELETE' });
  fetchServices();
}

function closeModal() { document.getElementById('modal').style.display = 'none'; editId = null; }
function onOverlayClick(e) { if (e.target === e.currentTarget) closeModal(); }
function onSearch() { renderList(); }
function onFilterChange() { fetchServices(); }

fetchServices();
</script>
</body>
</html>