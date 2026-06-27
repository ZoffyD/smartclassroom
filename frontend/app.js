// if the session expired or you're not logged in, any API call returns 401 ->
// bounce to the login page so the data is never shown without logging in
(function () {
  const realFetch = window.fetch;
  window.fetch = async (...args) => {
    const res = await realFetch(...args);
    if (res.status === 401) window.location = 'auth.php';
    return res;
  };
})();

// base path where the app is hosted. the api lives under this folder.
// deployed at https://canorcannot.com/Eric/smartclassroom/ , so the api is at
// /Eric/smartclassroom/api/... (leave '' only if hosted at the domain root).
const API = '/Eric/smartclassroom';

// which classroom we are currently looking at, and which tab is open
let currentClassroom = '';
let currentTab = 'environment';
const classroomList = [];
let attendanceRows = [];   // latest attendance rows, kept for the Excel export

// add ?classroom=... to an API path so every call is scoped to the chosen room
function withRoom(path) {
  if (!currentClassroom) return `${API}${path}`;
  const sep = path.includes('?') ? '&' : '?';
  return `${API}${path}${sep}classroom=${encodeURIComponent(currentClassroom)}`;
}

// ----- classroom picker -----

// load the list of classrooms into the dropdown
async function loadClassrooms() {
  try {
    const res  = await fetch(`${API}/api/getClassrooms.php`);
    const rows = await res.json();
    classroomList.length = 0;
    rows.forEach(r => classroomList.push(r));

    const sel = document.getElementById('classroom-select');

    if (!rows.length) {
      sel.innerHTML = '<option value="">No classroom yet</option>';
      currentClassroom = '';
      document.getElementById('last-updated').textContent =
        'No classroom yet - set up an ESP32 on your WiFi and it will appear here automatically.';
      return;
    }

    // keep the current choice if it still exists, otherwise pick the first one
    if (!currentClassroom || !rows.some(r => String(r.id) === String(currentClassroom))) {
      currentClassroom = String(rows[0].id);
    }
    sel.innerHTML = rows.map(r => {
      const selected = String(r.id) === String(currentClassroom) ? 'selected' : '';
      return `<option value="${r.id}" ${selected}>${r.name}</option>`;
    }).join('');
  } catch (e) {
    console.error('loadClassrooms:', e);
  }
}

// user picked a different classroom from the dropdown
function switchClassroom(id) {
  currentClassroom = id;
  loadSettings();
  loadRoomInfo();
  refreshTab();
}

// rename the current classroom
async function renameClassroom() {
  const name = document.getElementById('room-name').value.trim();
  const msg  = document.getElementById('room-msg');
  if (!currentClassroom) { msg.className = 'reg-msg err'; msg.textContent = 'Pick a classroom first.'; return; }
  if (!name) { msg.className = 'reg-msg err'; msg.textContent = 'Please enter a name.'; return; }
  try {
    const res  = await fetch(`${API}/api/updateClassroom.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: currentClassroom, name })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || ('Server error ' + res.status));
    msg.className = 'reg-msg ok';
    msg.textContent = `✅ Renamed to "${name}".`;
    await loadClassrooms();
    loadRoomInfo();
  } catch (e) {
    msg.className = 'reg-msg err';
    msg.textContent = '❌ ' + e.message;
  }
}

// delete the current classroom (and all its students / readings / attendance)
async function deleteClassroom() {
  const room = classroomList.find(r => String(r.id) === String(currentClassroom));
  if (!room) return;
  if (!confirm(`Delete "${room.name}" and all its students and records?`)) return;
  try {
    await fetch(`${API}/api/deleteClassroom.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: currentClassroom })
    });
    currentClassroom = '';
    await loadClassrooms();
    loadSettings();
    loadRoomInfo();
    refreshTab();
  } catch (e) {
    console.error('deleteClassroom:', e);
  }
}

// ----- tab switching -----

function showTab(name) {
  currentTab = name;
  document.querySelectorAll('.tab-btn').forEach(b =>
    b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.tab-page').forEach(p =>
    p.classList.toggle('active', p.id === `tab-${name}`));
  refreshTab();
}

// only refresh the data that the open tab actually shows
function refreshTab() {
  if (currentTab === 'environment') {
    loadLatest();
    loadInsight();
    loadCharts();
  } else if (currentTab === 'attendance') {
    loadStudents();
    loadAttendance();
    loadPendingScan();
  } else if (currentTab === 'settings') {
    loadRoomInfo();
  }
}

// ----- environment page -----

// turn raw sensor numbers into plain words a teacher can read at a glance.
// each returns [word, small-print reading].
function airQuality(g) {
  if (g == null) return ['--', ''];
  if (g >= 1500) return ['Dangerous', `reading ${g}`];
  if (g >= 800)  return ['Poor', `reading ${g}`];
  if (g >= 400)  return ['Moderate', `reading ${g}`];
  return ['Good', `reading ${g}`];
}
function lightLevel(l) {
  if (l == null) return ['--', ''];
  if (l >= 3500) return ['Bright', `reading ${l}`];
  if (l >= 2000) return ['Normal', `reading ${l}`];
  if (l >= 800)  return ['Dim', `reading ${l}`];
  return ['Dark', `reading ${l}`];
}
function noiseLevel(s) {
  if (s == null) return ['--', ''];
  if (s >= 2500) return ['Loud', `reading ${s}`];
  if (s >= 1000) return ['Moderate', `reading ${s}`];
  return ['Quiet', `reading ${s}`];
}

// show the latest sensor values
async function loadLatest() {
  try {
    const res = await fetch(withRoom('/api/getLatest.php'));
    const d   = await res.json();
    if (!d) return;

    document.getElementById('temp').textContent   = d.temperature ?? '--';
    document.getElementById('hum').textContent    = d.humidity    ?? '--';
    document.getElementById('motion').textContent = d.motion ? '✅ Detected' : '⬜ None';

    // plain-word readings 
    const [gasWord, gasRaw]     = airQuality(d.gas);
    const [lightWord, lightRaw] = lightLevel(d.light);
    const [soundWord, soundRaw] = noiseLevel(d.sound);
    document.getElementById('gas').textContent       = gasWord;
    document.getElementById('gas-sub').textContent   = gasRaw;
    document.getElementById('light').textContent     = lightWord;
    document.getElementById('light-sub').textContent = lightRaw;
    document.getElementById('sound').textContent     = soundWord;
    document.getElementById('sound-sub').textContent = soundRaw;

    const badge = document.getElementById('status-badge');
    badge.textContent = d.status || 'Normal';
    badge.className   = '';
    if (d.status === 'Warning')  badge.className = 'warning';
    if (d.status === 'Critical') badge.className = 'critical';

    // the fan runs automatically at the Warning level (and above), so we can show
    // its state straight from the room status - no separate request needed
    const fan   = document.getElementById('fan-status');
    const fanOn = d.status === 'Warning' || d.status === 'Critical';
    fan.textContent = fanOn ? 'ON' : 'OFF';
    fan.className   = 'fan-pill ' + (fanOn ? 'on' : 'off');

    const ts = d.created_at ? new Date(d.created_at).toLocaleTimeString() : '--';
    document.getElementById('last-updated').textContent = `Last updated: ${ts}`;
  } catch (e) {
    console.error('loadLatest:', e);
  }
}

// load the 24 hour summary box
async function loadInsight() {
  try {
    const res = await fetch(withRoom('/api/getInsight.php'));
    const d   = await res.json();

    if (d.message) {
      document.getElementById('insight-content').innerHTML =
        `<div class="insight-item"><span class="insight-icon">ℹ️</span>${d.message}</div>`;
      return;
    }

    document.getElementById('insight-content').innerHTML = `
      <div class="insight-item"><span class="insight-icon">🌡️</span>Avg temp: <b>${d.avg_temperature}°C</b> &nbsp;|&nbsp; Max: ${d.max_temperature}°C</div>
      <div class="insight-item"><span class="insight-icon">💨</span>Avg gas: <b>${d.avg_gas} ppm</b> &nbsp;|&nbsp; Max: ${d.max_gas}</div>
      <div class="insight-item"><span class="insight-icon">🚶</span>Motion events: <b>${d.motion_events}</b> &nbsp;·&nbsp; Room: <b>${d.occupancy_status}</b></div>
      <div class="insight-item"><span class="insight-icon">⚠️</span>Warnings: <b>${d.warning_count}</b> &nbsp;·&nbsp; Critical: <b>${d.critical_count}</b></div>
      <div class="insight-item"><span class="insight-icon">👨‍🎓</span>Checked in now: <b>${d.students_present}</b> &nbsp;of&nbsp; ${d.total_students} students</div>
      <div class="insight-item"><span class="insight-icon">💡</span>${d.energy_recommendation}</div>
    `;
  } catch (e) {
    console.error('loadInsight:', e);
  }
}

async function loadCharts() {
  try {
    const res  = await fetch(withRoom('/api/getHistory.php?limit=30'));
    const rows = (await res.json()).reverse();   // oldest -> newest

    drawLineChart('chart-temp',  rows.map(r => r.temperature), '#f09595', '°C');
    drawLineChart('chart-hum',   rows.map(r => r.humidity),    '#85b7eb', '%');
    drawLineChart('chart-gas',   rows.map(r => r.gas),         '#fac775', '');
    drawLineChart('chart-light', rows.map(r => r.light),       '#c0dd97', '');
  } catch (e) {
    console.error('loadCharts:', e);
  }
}

function drawLineChart(canvasId, raw, color, unit) {
  const values = raw.filter(v => v != null);
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  canvas.width = canvas.clientWidth;
  const W = canvas.width, H = canvas.height;
  ctx.clearRect(0, 0, W, H);

  if (values.length < 2) {
    ctx.fillStyle = 'rgba(255,255,255,0.35)';
    ctx.font = '13px Segoe UI';
    ctx.fillText('Not enough data yet', 10, 24);
    return;
  }

  const pad = 30;
  let min = Math.min(...values) - 1;
  let max = Math.max(...values) + 1;
  if (min === max) max = min + 1;   // avoid divide-by-zero on a flat line
  const x = i => pad + (i / (values.length - 1)) * (W - pad * 2);
  const y = v => H - pad - ((v - min) / (max - min)) * (H - pad * 2);

  // min/max labels on the left
  ctx.fillStyle = 'rgba(255,255,255,0.4)';
  ctx.font = '11px Segoe UI';
  ctx.fillText(max.toFixed(1) + unit, 2, y(max) + 4);
  ctx.fillText(min.toFixed(1) + unit, 2, y(min) + 4);

  // the line
  ctx.strokeStyle = color;
  ctx.lineWidth = 2;
  ctx.beginPath();
  values.forEach((v, i) => i ? ctx.lineTo(x(i), y(v)) : ctx.moveTo(x(i), y(v)));
  ctx.stroke();

  // a dot on each point
  ctx.fillStyle = color;
  values.forEach((v, i) => { ctx.beginPath(); ctx.arc(x(i), y(v), 2.5, 0, 7); ctx.fill(); });
}

// ----- attendance page -----

// when an unknown card is tapped on the reader, drop its UID straight into the
// register form so you only need to add the name and matric
async function loadPendingScan() {
  try {
    const res   = await fetch(withRoom('/api/getLastScan.php'));
    const d     = await res.json();
    const input = document.getElementById('reg-uid');
    // only fill if the box is empty and you're not typing in it
    if (d.uid && input && !input.value && document.activeElement !== input) {
      input.value = d.uid;
      const msg = document.getElementById('reg-msg');
      msg.className = 'reg-msg ok';
      msg.textContent = '🪪 New card detected — add the name and matric, then Register.';
    }
  } catch (e) {
    console.error('loadPendingScan:', e);
  }
}

// register a new student from the form
async function registerStudent() {
  const uid    = document.getElementById('reg-uid').value.trim();
  const name   = document.getElementById('reg-name').value.trim();
  const matric = document.getElementById('reg-matric').value.trim();
  const msg    = document.getElementById('reg-msg');

  if (!uid || !name || !matric) {
    msg.className = 'reg-msg err';
    msg.textContent = 'Please fill in UID, name and matric.';
    return;
  }

  try {
    const res  = await fetch(`${API}/api/addStudent.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ classroom: currentClassroom, uid, name, matric })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Failed to register');

    msg.className = 'reg-msg ok';
    msg.textContent = `✅ Registered "${name}".`;
    document.getElementById('reg-uid').value    = '';
    document.getElementById('reg-name').value   = '';
    document.getElementById('reg-matric').value = '';
    loadStudents();
  } catch (e) {
    msg.className = 'reg-msg err';
    msg.textContent = '❌ ' + e.message;
  }
}

// show the registered students with their current status (for this device)
async function loadStudents() {
  try {
    const res   = await fetch(withRoom('/api/getStudents.php'));
    const rows  = await res.json();
    const tbody = document.getElementById('students-body');

    // how many are in the room right now (last tap was a check-in)
    const present = rows.filter(r => r.last_type === 'IN').length;
    document.getElementById('present-count').textContent =
      `Present now: ${present} / ${rows.length}`;

    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="loading" style="padding:12px 0;">No students registered yet.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(r => {
      const inRoom = r.last_type === 'IN';
      const label  = inRoom ? 'Present' : (r.last_type ? 'Left' : 'Not arrived');
      const cls    = inRoom ? 'present' : (r.last_type ? 'out' : 'none');
      return `<tr>
        <td>${r.name}</td>
        <td>${r.matric}</td>
        <td><span class="badge ${cls}">${label}</span></td>
        <td><button class="btn-delete" onclick="deleteStudent('${r.uid}')">Delete</button></td>
      </tr>`;
    }).join('');
  } catch (e) {
    console.error('loadStudents:', e);
  }
}

// show the attendance history log (optionally for one chosen day)
async function loadAttendance() {
  try {
    const date  = document.getElementById('att-date')?.value || '';
    const path  = `/api/getAttendance.php?limit=200${date ? '&date=' + date : ''}`;
    const res   = await fetch(withRoom(path));
    const rows  = await res.json();
    attendanceRows = rows;                       // keep for the Excel export
    const tbody = document.getElementById('att-body');

    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="loading" style="padding:12px 0;">No attendance for this day.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(r => {
      const out   = r.type === 'OUT';
      const label = out ? 'Left' : 'Arrived';
      const cls   = out ? 'out' : 'present';
      const time  = r.created_at ? new Date(r.created_at).toLocaleString() : '--';
      return `<tr>
        <td>${r.name}</td>
        <td>${r.matric}</td>
        <td><span class="badge ${cls}">${label}</span></td>
        <td>${time}</td>
      </tr>`;
    }).join('');
  } catch (e) {
    console.error('loadAttendance:', e);
  }
}

// download the attendance you're currently looking at as an Excel-friendly CSV
function exportAttendanceCSV() {
  if (!attendanceRows.length) { alert('Nothing to export yet.'); return; }
  const cell = v => {
    v = String(v ?? '');
    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  };
  const lines = ['Name,Matric,Action,Time'];
  attendanceRows.forEach(r => {
    const action = r.type === 'OUT' ? 'Left' : 'Arrived';
    const time   = r.created_at ? new Date(r.created_at).toLocaleString() : '';
    lines.push([cell(r.name), cell(r.matric), action, cell(time)].join(','));
  });
  const day  = document.getElementById('att-date')?.value || 'all';
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = `attendance-${day}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}

// delete a student from the current classroom
async function deleteStudent(uid) {
  if (!confirm('Remove this student?')) return;
  try {
    await fetch(withRoom('/api/deleteStudent.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid })
    });
    loadStudents();
  } catch (e) {
    console.error('deleteStudent:', e);
  }
}

// ----- settings page -----

// load the current thresholds into the settings form (for this device)
async function loadSettings() {
  try {
    const res = await fetch(withRoom('/api/getSettings.php'));
    const s   = await res.json();
    document.getElementById('set-temp-warn').value   = s.temp_warning;
    document.getElementById('set-temp-danger').value = s.temp_danger;
    document.getElementById('set-gas-warn').value    = s.gas_warning;
    document.getElementById('set-gas-danger').value  = s.gas_danger;
    document.getElementById('set-interval').value    = s.upload_interval;
  } catch (e) {
    console.error('loadSettings:', e);
  }
}

// fill the threshold boxes with one of three ready-made presets, so a teacher
// never has to guess the numbers (still needs Save to apply)
function applyPreset(name) {
  const presets = {
    comfortable: { tw: 28, td: 32, gw: 600, gd: 1200, iv: 5 },  // reacts early, keeps it cool
    standard:    { tw: 30, td: 35, gw: 800, gd: 1500, iv: 5 },  // sensible defaults
    strict:      { tw: 27, td: 31, gw: 500, gd: 1000, iv: 5 },  // safety-first, most sensitive
  };
  const p = presets[name] || presets.standard;
  document.getElementById('set-temp-warn').value   = p.tw;
  document.getElementById('set-temp-danger').value = p.td;
  document.getElementById('set-gas-warn').value    = p.gw;
  document.getElementById('set-gas-danger').value  = p.gd;
  document.getElementById('set-interval').value    = p.iv;
  const msg = document.getElementById('set-msg');
  msg.className = 'reg-msg ok';
  msg.textContent = `Loaded the "${name}" preset — press Save to apply.`;
}

// save the thresholds from the form (for this device)
async function saveSettings() {
  const msg = document.getElementById('set-msg');
  const body = {
    classroom:       currentClassroom,
    temp_warning:    parseFloat(document.getElementById('set-temp-warn').value),
    temp_danger:     parseFloat(document.getElementById('set-temp-danger').value),
    gas_warning:     parseInt(document.getElementById('set-gas-warn').value),
    gas_danger:      parseInt(document.getElementById('set-gas-danger').value),
    upload_interval: parseInt(document.getElementById('set-interval').value),
  };
  try {
    const res = await fetch(withRoom('/api/updateSettings.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    if (!res.ok) throw new Error('Failed to save');
    msg.className = 'reg-msg ok';
    msg.textContent = '✅ Settings saved.';
  } catch (e) {
    msg.className = 'reg-msg err';
    msg.textContent = '❌ ' + e.message;
  }
}

// show info about the selected classroom (its linked board, if any)
function loadRoomInfo() {
  const el = document.getElementById('device-info');
  const r  = classroomList.find(x => String(x.id) === String(currentClassroom));
  if (!r) { el.textContent = 'No classroom selected.'; return; }
  // pre-fill the rename box, but don't overwrite it while the user is typing in it
  const nameInput = document.getElementById('room-name');
  if (document.activeElement !== nameInput) nameInput.value = r.name;
  if (r.device_id) {
    const seen = r.last_seen ? new Date(r.last_seen).toLocaleString() : '--';
    el.innerHTML = `📡 Sensor device: <b>Connected</b><br>Last seen: ${seen}`;
  } else {
    el.innerHTML = 'No sensor device connected yet. Set one up on the same WiFi and it will appear here automatically.';
  }
}

// ----- startup + auto refresh -----

async function boot() {
  await loadClassrooms();
  loadSettings();
  loadRoomInfo();
  refreshTab();
}

boot();

// every few seconds: re-check the classroom list, then refresh the open tab
setInterval(async () => {
  await loadClassrooms();
  refreshTab();
}, 4000);
