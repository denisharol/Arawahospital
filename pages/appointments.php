<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireLogin();

if (!validateSession($pdo)) {
    destroySession($pdo);
    header('Location: /auth/login.php');
    exit;
}

$pageTitle = 'Appointments - Arawa Hospital';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$host = 'localhost'; $db = 'arawadb'; $user = 'root'; $pass = '';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$isApi = isset($_GET['api']);

if ($isApi) {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';
    $id = $_GET['id'] ?? null;
    $subResource = $_GET['sub'] ?? null;
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

    switch ($resource) {
        case 'appointments':
            if ($method === 'GET' && !$id) {
                $search = $_GET['search'] ?? '';
                $status = $_GET['status'] ?? '';
                $sql = 'SELECT * FROM appointments WHERE 1=1';
                $params = [];
                if ($search) {
                    $sql .= ' AND (patient_name LIKE :s1 OR doctor_name LIKE :s2 OR department LIKE :s3 OR type LIKE :s4 OR status LIKE :s5)';
                    $like = "%$search%";
                    $params = array_merge($params, [':s1'=>$like,':s2'=>$like,':s3'=>$like,':s4'=>$like,':s5'=>$like]);
                }
                if ($status) { $sql .= ' AND status = :status'; $params[':status'] = $status; }
                $sql .= ' ORDER BY date DESC, time DESC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            if ($method === 'POST') {
                $required = ['patientName','patientPhone','date','time'];
                foreach ($required as $f) { if (empty($body[$f])) respondError("Missing: $f"); }
                $stmt = $pdo->prepare('INSERT INTO appointments (patient_name,patient_phone,doctor_name,department,date,time,duration,type,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$body['patientName'],$body['patientPhone'],$body['doctorName']??'',$body['department']??'',$body['date'],$body['time'],$body['duration']??'30 min',$body['type']??'',$body['status']??'confirmed',$body['notes']??'']);
                respond(['id' => $pdo->lastInsertId()], 201);
            }

            if ($method === 'PUT' && $id) {
                $stmt = $pdo->prepare('UPDATE appointments SET patient_name=?,patient_phone=?,doctor_name=?,department=?,date=?,time=?,duration=?,type=?,status=? WHERE id=?');
                $stmt->execute([$body['patientName'],$body['patientPhone'],$body['doctorName']??'',$body['department']??'',$body['date'],$body['time'],$body['duration']??'30 min',$body['type']??'',$body['status']??'confirmed',$id]);
                respond(['updated' => $id]);
            }

            if ($method === 'PATCH' && $id && $subResource === 'status') {
                if (empty($body['status'])) respondError('Missing status');
                $stmt = $pdo->prepare('UPDATE appointments SET status=? WHERE id=?');
                $stmt->execute([$body['status'], $id]);
                respond(['updated' => $id]);
            }

            if ($method === 'DELETE' && $id) {
                $stmt = $pdo->prepare('DELETE FROM appointments WHERE id=?');
                $stmt->execute([$id]);
                respond(['deleted' => $id]);
            }
            break;

        case 'staff':
            if ($method === 'GET') {
                $role = $_GET['role'] ?? '';
                $sql = 'SELECT full_name AS fullName, specialization FROM staff WHERE 1=1';
                $params = [];
                if ($role) { $sql .= ' AND role = :role'; $params[':role'] = $role; }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;

        case 'departments':
            if ($method === 'GET') {
                $stmt = $pdo->query('SELECT name, code FROM departments ORDER BY name');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;

        default:
            respondError('Not found', 404);
    }
    exit;
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
  <link rel="stylesheet" href="../../assets/css/appointments.css">
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<?php include_once '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="page">

  <div class="header">
    <div>
      <h1>Appointments</h1>
      <p>Manage patient appointments and schedules efficiently.</p>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i data-lucide="search" class="search-icon"></i>
        <input id="searchInput" type="text" placeholder="Search appointments..." oninput="onSearch()" />
      </div>
      <button class="btn-primary" onclick="openModal()">
        <i data-lucide="plus"></i> New Appointment
      </button>
    </div>
  </div>

  <div class="filter-bar">
    <div class="filter-left">
      <span class="filter-label">Filter by status:</span>
      <select id="statusFilter" onchange="onFilterChange()">
        <option value="all">All</option>
        <option value="confirmed">Confirmed</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>
    <div class="count-badge" id="countBadge">0 appointments found</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Appointment List</h3>
    </div>
    <div id="listContainer">
      <div class="state-msg">Loading appointments...</div>
    </div>
  </div>

</div>

<div id="modal" class="modal-overlay" onclick="onOverlayClick(event)" style="display:none;">
  <div class="modal">
    <h2 id="modalTitle">Add Appointment</h2>
    <form id="appointmentForm" onsubmit="handleSubmit(event)">
      <div class="form-grid">
        <div class="field">
          <label>Patient Name *</label>
          <input type="text" id="f_patientName" placeholder="Patient Name" required />
        </div>
        <div class="field">
          <label>Patient Phone *</label>
          <input type="tel" id="f_patientPhone" placeholder="Patient Phone" required />
        </div>
        <div class="field">
          <label>Doctor</label>
          <select id="f_doctorName">
            <option value="">Select Doctor</option>
          </select>
        </div>
        <div class="field">
          <label>Department</label>
          <select id="f_department">
            <option value="">Select Department</option>
          </select>
        </div>
        <div class="field">
          <label>Date *</label>
          <input type="date" id="f_date" required />
        </div>
        <div class="field">
          <label>Time *</label>
          <input type="time" id="f_time" required />
        </div>
        <div class="field field-full">
          <label>Type</label>
          <select id="f_type">
            <option value="">Select Type</option>
            <option>Consultation</option>
            <option>Follow-up</option>
            <option>Check-up</option>
            <option>Treatment</option>
            <option>Vaccination</option>
            <option>Emergency</option>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-primary" id="submitBtn">Create Appointment</button>
      </div>
    </form>
  </div>
</div>
</div>

<script src="../../assets/js/theme.js"></script>
<script src="../../assets/js/sidebar.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
const API = 'appointments.php?api=1';
let appointments = [];
let editId = null;
let openDropdownId = null;

async function fetchAppointments() {
  const search = document.getElementById('searchInput').value;
  const status = document.getElementById('statusFilter').value;
  const params = new URLSearchParams({ resource: 'appointments', search });
  if (status !== 'all') params.append('status', status);
  const res = await fetch(`${API}&${params}`);
  const json = await res.json();
  appointments = json.data || [];
  renderList();
}

async function fetchDoctors() {
  const res = await fetch(`${API}&resource=staff&role=doctor`);
  const json = await res.json();
  const sel = document.getElementById('f_doctorName');
  (json.data || []).forEach(d => {
    const o = document.createElement('option');
    o.value = d.fullName; o.textContent = d.fullName;
    sel.appendChild(o);
  });
}

async function fetchDepartments() {
  const res = await fetch(`${API}&resource=departments`);
  const json = await res.json();
  const sel = document.getElementById('f_department');
  (json.data || []).forEach(d => {
    const o = document.createElement('option');
    o.value = d.name; o.textContent = d.name;
    sel.appendChild(o);
  });
}

function statusColor(s) {
  return { confirmed: '#10b981', cancelled: '#ef4444', completed: '#64748b' }[s] || '#64748b';
}

function statusIcon(s) {
  if (s === 'cancelled') return `<i data-lucide="x-circle" style="width:16px;height:16px;color:${statusColor(s)}"></i>`;
  return `<i data-lucide="check-circle" style="width:16px;height:16px;color:${statusColor(s)}"></i>`;
}

function initials(name) {
  return name.split(' ').map(n => n[0]).join('').toUpperCase();
}

function renderList() {
  const container = document.getElementById('listContainer');
  const search = document.getElementById('searchInput').value.toLowerCase();

  if (appointments.length === 0) {
    container.innerHTML = '<div class="state-msg">No appointments found</div>';
    document.getElementById('countBadge').textContent = '0 appointments found';
    return;
  }

  const filtered = appointments.filter(a =>
    a.patient_name.toLowerCase().includes(search) ||
    (a.doctor_name||'').toLowerCase().includes(search) ||
    (a.department||'').toLowerCase().includes(search) ||
    (a.type||'').toLowerCase().includes(search) ||
    a.status.toLowerCase().includes(search)
  );

  document.getElementById('countBadge').textContent = `${appointments.length} appointments found`;

  container.innerHTML = filtered.map(a => {
    const color = statusColor(a.status);
    return `
    <div class="appt-row" data-id="${a.id}">
      <div class="avatar">${initials(a.patient_name)}</div>
      <div class="appt-info">
        <h3>${a.patient_name}</h3>
        <p class="sub">${[a.doctor_name, a.department, a.type].filter(Boolean).join(' • ')}</p>
        <p class="meta">
          <i data-lucide="calendar" class="meta-icon"></i>${a.date}
          <i data-lucide="clock" class="meta-icon" style="margin-left:12px"></i>${a.time}
        </p>
        ${a.notes ? `<p class="meta">Notes: ${a.notes}</p>` : ''}
      </div>
      <div class="status-badge" style="background:${color}20;color:${color}">
        ${statusIcon(a.status)} ${a.status}
      </div>
      <div class="dropdown-wrap" onclick="event.stopPropagation()">
        <button class="icon-btn" onclick="toggleDropdown('${a.id}')">
          <i data-lucide="more-vertical"></i>
        </button>
        <div class="dropdown" id="dd_${a.id}" style="display:none">
          ${a.status !== 'confirmed' ? `<button onclick="updateStatus('${a.id}','confirmed')">Confirm</button>` : ''}
          ${a.status !== 'completed' ? `<button onclick="updateStatus('${a.id}','completed')">Complete</button>` : ''}
          ${a.status === 'cancelled' ? `<button onclick='editAppointment(${JSON.stringify(a)})'>Reappoint</button>` : ''}
          <button class="danger" onclick="deleteAppointment('${a.id}')">Cancel</button>
        </div>
      </div>
    </div>`;
  }).join('');

  lucide.createIcons();
}

function toggleDropdown(id) {
  if (openDropdownId && openDropdownId !== id) {
    document.getElementById(`dd_${openDropdownId}`).style.display = 'none';
  }
  const dd = document.getElementById(`dd_${id}`);
  dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
  openDropdownId = dd.style.display === 'block' ? id : null;
}

document.addEventListener('click', () => {
  if (openDropdownId) {
    const dd = document.getElementById(`dd_${openDropdownId}`);
    if (dd) dd.style.display = 'none';
    openDropdownId = null;
  }
});

async function updateStatus(id, status) {
  await fetch(`${API}&resource=appointments&id=${id}&sub=status`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ status }) });
  fetchAppointments();
}

async function deleteAppointment(id) {
  if (!confirm('Are you sure you want to cancel this appointment?')) return;
  await fetch(`${API}&resource=appointments&id=${id}`, { method: 'DELETE' });
  fetchAppointments();
}

function editAppointment(a) {
  editId = a.id;
  document.getElementById('modalTitle').textContent = 'Edit Appointment';
  document.getElementById('submitBtn').textContent = 'Update Appointment';
  document.getElementById('f_patientName').value = a.patient_name;
  document.getElementById('f_patientPhone').value = a.patient_phone;
  document.getElementById('f_doctorName').value = a.doctor_name || '';
  document.getElementById('f_department').value = a.department || '';
  document.getElementById('f_date').value = a.date;
  document.getElementById('f_time').value = a.time;
  document.getElementById('f_type').value = a.type || '';
  document.getElementById('modal').style.display = 'flex';
}

async function handleSubmit(e) {
  e.preventDefault();
  const payload = {
    patientName: document.getElementById('f_patientName').value,
    patientPhone: document.getElementById('f_patientPhone').value,
    doctorName: document.getElementById('f_doctorName').value,
    department: document.getElementById('f_department').value,
    date: document.getElementById('f_date').value,
    time: document.getElementById('f_time').value,
    type: document.getElementById('f_type').value,
    duration: '30 min', status: 'confirmed'
  };
  if (editId) {
    await fetch(`${API}&resource=appointments&id=${editId}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  } else {
    await fetch(`${API}&resource=appointments`, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  }
  closeModal();
  fetchAppointments();
}

function openModal() {
  editId = null;
  document.getElementById('appointmentForm').reset();
  document.getElementById('modalTitle').textContent = 'Add Appointment';
  document.getElementById('submitBtn').textContent = 'Create Appointment';
  document.getElementById('modal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('modal').style.display = 'none';
  editId = null;
}

function onOverlayClick(e) { if (e.target === e.currentTarget) closeModal(); }
function onSearch() { renderList(); }
function onFilterChange() { fetchAppointments(); }

fetchDoctors();
fetchDepartments();
fetchAppointments();
</script>
</body>
</html>