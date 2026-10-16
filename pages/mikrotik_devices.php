<?php
ob_start();
$pageTitle = 'MikroTik Devices';
$activePage = 'mikrotik_devices';
include __DIR__ . '/../components/header.php';
$deviceId = $_GET['id'] ?? null;
?>
<style>
.mikrotik-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.mikrotik-card {
    background: var(--surface);
    border: 1px solid var(--surface-4);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    cursor: pointer;
    transition: all var(--transition);
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-1);
}
.mikrotik-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-3);
    border-color: var(--blue-300);
}
.mikrotik-card .device-icon {
    width: 48px; height: 48px; border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; color: #fff; margin-bottom: 1rem;
}
.mikrotik-card .device-icon.online { background: linear-gradient(135deg, var(--green), #20c997); }
.mikrotik-card .device-icon.offline { background: linear-gradient(135deg, var(--red), #e83e8c); }
.mikrotik-card .device-icon.pending { background: linear-gradient(135deg, var(--yellow), var(--orange)); }

.mikrotik-card .status-badge {
    position: absolute; top: 12px; right: 12px;
    padding: 3px 10px; border-radius: var(--radius-full); font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
}
.mikrotik-card .status-badge.online { background: #E6F4EA; color: #137333; }
.mikrotik-card .status-badge.offline { background: #FCE8E6; color: #C5221F; }
.mikrotik-card .status-badge.pending { background: #FEF7E0; color: #B45309; }

.empty-state { text-align:center; padding:4rem 2rem; color:var(--on-surface-med); }
</style>

<?php if ($deviceId): ?>
<!-- DEVICE DETAIL VIEW -->
<div class="page-header">
    <div class="page-header-left">
        <a href="/mikrotik_devices" class="back-link"><i class="fas fa-arrow-left"></i> Back to devices</a>
        <h1 class="page-title"><i class="fas fa-router"></i> <span id="deviceName">Loading...</span></h1>
    </div>
    <div class="page-header-actions" id="deviceStatusBadge"></div>
</div>

<div class="form-row">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-wifi"></i> Wireless Configuration</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Configure the WiFi network name (SSID) for this device.</p>
            <form id="wirelessForm">
                <input type="hidden" id="routerId" value="<?= htmlspecialchars($deviceId) ?>">
                <div class="form-group">
                    <label class="form-label" for="ssid">Network Name (SSID)</label>
                    <input type="text" class="form-control" id="ssid" placeholder="e.g. OneLink" maxlength="32" required>
                </div>
                <div id="wirelessMsg"></div>
                <button type="submit" class="btn btn-primary" id="applyWirelessBtn">
                    <i class="fas fa-check"></i> Apply Configuration
                </button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-info-circle"></i> Device Info</span>
        </div>
        <div class="card-body p-0">
            <div class="table-wrapper">
                <table>
                    <tbody>
                        <tr><td class="td-label">Device ID</td><td id="infoDeviceId">—</td></tr>
                        <tr><td class="td-label">Location</td><td id="infoLocation">—</td></tr>
                        <tr><td class="td-label">IP Address</td><td id="infoIP">—</td></tr>
                        <tr><td class="td-label">WireGuard IP</td><td id="infoWG">—</td></tr>
                        <tr><td class="td-label">API Password</td><td id="infoApiPass">—</td></tr>
                        <tr><td class="td-label">Status</td><td id="infoStatus">—</td></tr>
                        <tr><td class="td-label">Last Provisioned</td><td id="infoLastProv">—</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let deviceData = null;

document.getElementById('wirelessForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!deviceData) return;

    const btn = document.getElementById('applyWirelessBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying...';
    document.getElementById('wirelessMsg').innerHTML = '';

    try {
        const res = await fetch('/api/wireless.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'configure',
                router_id: deviceData.id,
                ssid: document.getElementById('ssid').value.trim()
            })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed');
        document.getElementById('ssid').value = data.ssid || document.getElementById('ssid').value.trim();
        document.getElementById('wirelessMsg').innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check"></i> ' + data.message + '</div>';
    } catch (err) {
        document.getElementById('wirelessMsg').innerHTML = '<div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle"></i> ' + err.message + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Apply Configuration';
    }
});

async function loadWirelessSsid() {
    if (!deviceData) return;
    try {
        const res = await fetch(`/api/wireless.php?action=get&router_id=${deviceData.id}`);
        const data = await res.json();
        document.getElementById('ssid').value = data.ssid || deviceData.ssid || 'OneLink';
        if (data.reachable === false) {
            document.getElementById('wirelessMsg').innerHTML = '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> Router offline — showing saved config.</div>';
        }
    } catch (e) {
        document.getElementById('ssid').value = deviceData.ssid || 'OneLink';
    }
}

async function loadDevice() {
    try {
        const res = await fetch('/api/mikrotik.php?action=get&router_id=<?= (int)$deviceId ?>');
        const data = await res.json();
        deviceData = data.router || null;
        if (!deviceData) {
            document.querySelector('.page-container').innerHTML = '<div class="alert alert-danger mt-4"><i class="fas fa-exclamation-triangle"></i> Device not found. <a href="/mikrotik_devices">Go back</a></div>';
            return;
        }

        document.getElementById('deviceName').textContent = deviceData.name;
        document.getElementById('routerId').value = deviceData.id;
        document.getElementById('ssid').value = deviceData.ssid || 'OneLink';
        loadWirelessSsid();

        const status = deviceData.provisioning_status || 'offline';
        const statusClass = status === 'online' ? 'active' : status === 'provisioning' ? 'pending' : 'inactive';
        const statusLabel = status === 'provisioning' ? 'provisioning' : status;
        document.getElementById('deviceStatusBadge').innerHTML = '<span class="chip ' + statusClass + '"><span class="chip-dot"></span>' + statusLabel.toUpperCase() + '</span>';

        document.getElementById('infoDeviceId').textContent = (deviceData.device_id || '').substring(0, 16) + '...';
        document.getElementById('infoLocation').textContent = deviceData.location || '—';
        document.getElementById('infoIP').textContent = deviceData.ip || '—';
        document.getElementById('infoWG').textContent = deviceData.wireguard_ip || '—';
        document.getElementById('infoApiPass').textContent = deviceData.password ? '••••••••' : '—';
        document.getElementById('infoStatus').textContent = status;
        document.getElementById('infoLastProv').textContent = deviceData.last_provisioned_at || '—';
    } catch (err) {
        document.getElementById('deviceName').textContent = 'Error loading device';
    }
}

loadDevice();

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php else: ?>
<!-- DEVICE LIST VIEW -->
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><i class="fas fa-server"></i> MikroTik Devices</h1>
        <p class="page-subtitle">Manage your connected MikroTik routers.</p>
    </div>
    <div class="page-header-actions">
        <a href="connect_mikrotik" class="btn btn-primary"><i class="fas fa-plus"></i> Add Device</a>
    </div>
</div>

<div id="devicesGrid" class="mikrotik-grid"></div>

<div id="emptyState" class="empty-state" style="display:none;">
    <div class="empty-state-icon"><i class="fas fa-router"></i></div>
    <h3>No MikroTik Devices</h3>
    <p>Connect your first MikroTik router to get started.</p>
    <a href="connect_mikrotik" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-plug"></i> Connect Device</a>
</div>

<div id="loading" class="text-center py-5">
    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
    <p class="text-muted mt-2">Loading devices...</p>
</div>

<script>
async function loadDevices() {
    try {
        const res = await fetch('/api/mikrotik.php?action=list');
        const data = await res.json();

        document.getElementById('loading').style.display = 'none';

        if (!data.routers || data.routers.length === 0) {
            document.getElementById('emptyState').style.display = 'block';
            return;
        }

        const grid = document.getElementById('devicesGrid');
        grid.innerHTML = data.routers.map(r => `
            <div class="mikrotik-card" onclick="viewDevice(${r.id})">
                <button class="btn btn-sm btn-outline-danger" style="position:absolute;top:10px;left:10px;z-index:2;" onclick="event.stopPropagation();deleteDevice(${r.id},'${escapeHtml(r.name)}')"><i class="fas fa-trash"></i></button>
                <span class="status-badge ${r.provisioning_status || 'offline'}">${r.provisioning_status || 'offline'}</span>
                <div class="device-icon ${r.provisioning_status || 'offline'}">
                    <i class="fas fa-router"></i>
                </div>
                <h5 class="mb-1">${escapeHtml(r.name)}</h5>
                <p class="text-muted small mb-2">${escapeHtml(r.location || 'No location set')}</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted"><i class="fas fa-network-wired"></i> ${r.wireguard_ip || r.ip || '—'}</small>
                    <small class="text-muted"><i class="fas fa-fingerprint"></i> ${(r.device_id || '').substring(0, 8)}...</small>
                </div>
                ${r.last_provisioned_at ? `<div class="mt-2"><small class="text-success"><i class="fas fa-check-circle"></i> Provisioned ${r.last_provisioned_at}</small></div>` : ''}
            </div>
        `).join('');

    } catch (err) {
        document.getElementById('loading').innerHTML = '<div class="alert alert-danger">Failed to load devices</div>';
    }
}

function viewDevice(id) {
    window.location.href = `/mikrotik_devices?id=${id}`;
}

async function deleteDevice(id, name) {
    if (!confirm(`Delete device "${name}"? This cannot be undone.`)) return;
    try {
        const res = await fetch('/api/mikrotik.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', router_id: id })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Delete failed');
        loadDevices();
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadDevices();
setInterval(loadDevices, 30000);
</script>
<?php endif; ?>

<?php
include __DIR__ . '/../components/footer.php';
ob_end_flush();
