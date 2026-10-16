<?php
ob_start();
$pageTitle = 'Connect MikroTik Device';
$activePage = 'connect_mikrotik';
include __DIR__ . '/../components/header.php';
?>
<style>
.page-max { max-width: 900px; margin: 0 auto; }
</style>

<div class="page-max">

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><i class="fas fa-plug"></i> Connect MikroTik Device</h1>
        <p class="page-subtitle">Register your device in 3 steps: device details, basic provisioning, and service configuration.</p>
    </div>
</div>

<!-- Step Indicator -->
<div class="step-wizard" id="stepWizard">
    <div class="step active" data-step="1">
        <div class="step-number">1</div>
        <span class="step-label">Device Details</span>
    </div>
    <div class="step-connector"></div>
    <div class="step" data-step="2">
        <div class="step-number">2</div>
        <span class="step-label">Basic Provisioning</span>
    </div>
    <div class="step-connector"></div>
    <div class="step" data-step="3">
        <div class="step-number">3</div>
        <span class="step-label">Service Configuration</span>
    </div>
</div>

<!-- STEP 1: Device Details -->
<div class="step-content active" id="step1">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-info-circle"></i> Step 1: Device Details</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Name and location</p>
            <form id="deviceForm">
                <div class="form-group">
                    <label class="form-label" for="deviceName">Device Name <span style="color:var(--red)">*</span></label>
                    <input type="text" class="form-control" id="deviceName" placeholder="e.g. sirari-mt-49152" required>
                    <small style="color:var(--on-surface-med)">A unique name for this MikroTik router</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="deviceLocation">Location</label>
                    <input type="text" class="form-control" id="deviceLocation" placeholder="e.g. Sirari, Geita">
                    <small style="color:var(--on-surface-med)">Physical location of the device</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="deviceLanIP">Router LAN IP <small style="color:var(--on-surface-med)">(for local testing without WireGuard)</small></label>
                    <input type="text" class="form-control" id="deviceLanIP" placeholder="e.g. 192.168.88.130">
                    <small style="color:var(--on-surface-med)">If set, dashboard checks connectivity via this IP instead of WireGuard</small>
                </div>
                <div class="wizard-nav" style="text-align:right;">
                    <button type="submit" class="btn btn-primary" id="registerBtn">
                        Register Device <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- STEP 2: Basic Provisioning -->
<div class="step-content" id="step2">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-terminal"></i> Step 2: Basic Provisioning</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Connect to your MikroTik via Winbox or SSH, then run both terminal commands below in order.</p>

            <div id="localModeNotice" class="alert alert-info" style="display:none;">
                <i class="fas fa-info-circle"></i> <strong>Local Mode:</strong> Your server is running on a local network. WireGuard VPN will be skipped. The MikroTik connects directly to this server.
            </div>

            <div class="mb-4">
                <h6><span class="chip info">1</span> Enable advanced device mode and fetch (RouterOS 7+)</h6>
                <p class="card-subtitle">New routers start in basic mode and block <code>/tool fetch</code> until device-mode allows it. Run this once.</p>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode('cmd1')"><i class="fas fa-copy"></i> Copy</button>
                    <code id="cmd1">/system device-mode update mode=advanced fetch=yes</code>
                </div>
            </div>

            <div class="mb-4">
                <h6><span class="chip info">2</span> Download and import OneLink script</h6>
                <p class="card-subtitle">Fetches the provisioning script from OneLink and imports it. Wait 1–2 minutes for completion.</p>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode('cmd2')"><i class="fas fa-copy"></i> Copy</button>
                    <code id="cmd2"></code>
                </div>
            </div>

            <!-- Connection Status -->
            <div class="card" style="margin-top:16px;border-color:var(--surface-4);box-shadow:none;">
                <div class="card-body" style="text-align:center;">
                    <h6 style="margin-bottom:10px;color:var(--on-surface-med);text-transform:uppercase;font-size:12px;letter-spacing:.5px;">Connection Status</h6>
                    <div id="statusDisplay">
                        <span class="status-indicator provisioning"></span>
                        <strong>Waiting for MikroTik</strong>
                        <p class="card-subtitle" style="margin-top:8px;">After both Step 2 commands finish on the MikroTik, we'll detect when it's online over WireGuard.</p>
                    </div>
                    <div id="statusDetails" style="display:none;margin-top:14px;">
                        <div class="form-row" style="text-align:left;">
                            <div>
                                <small><strong>WireGuard IP:</strong> <span id="wgIPDisplay">—</span></small>
                            </div>
                            <div>
                                <small><strong>Status:</strong> <span id="statusText">—</span></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wizard-nav flex-row" style="justify-content:space-between;margin-top:20px;">
                <button class="btn btn-outline" onclick="goToStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                <button class="btn btn-primary" id="toStep3Btn" disabled onclick="goToStep(3)">
                    Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- STEP 3: Service Configuration -->
<div class="step-content" id="step3">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-cogs"></i> Step 3: Service Configuration</span>
        </div>
        <div class="card-body">
            <p class="card-subtitle">Port assignment and services. Configure which services to enable on the device.</p>

            <div class="config-card">
                <h6><i class="fas fa-wifi" style="color:var(--blue-500)"></i> Hotspot / PPPoE</h6>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Service Mode</label>
                    <select class="form-control" id="serviceMode" style="max-width:320px;">
                        <option value="hotspot">Hotspot (Captive Portal)</option>
                        <option value="pppoe">PPPoE</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Bridge Interface</label>
                        <input type="text" class="form-control" value="onelink-bridge" readonly>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">IP Pool</label>
                        <input type="text" class="form-control" value="10.10.0.2-10.10.0.254" readonly>
                    </div>
                </div>
            </div>

            <div class="config-card">
                <h6><i class="fas fa-shield-alt" style="color:var(--yellow)"></i> Firewall &amp; Services</h6>
                <div class="service-toggle">
                    <label class="fw"><i class="fas fa-lock"></i> API SSL (port 8729)</label>
                    <label class="switch">
                        <input type="checkbox" id="svcApiSsl" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="service-toggle">
                    <label class="fw"><i class="fas fa-terminal"></i> SSH (port 22)</label>
                    <label class="switch">
                        <input type="checkbox" id="svcSsh" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="service-toggle">
                    <label class="fw"><i class="fas fa-globe"></i> Webfig (port 80)</label>
                    <label class="switch">
                        <input type="checkbox" id="svcWeb" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="service-toggle">
                    <label class="fw"><i class="fas fa-satellite-dish"></i> SNMP (port 161)</label>
                    <label class="switch">
                        <input type="checkbox" id="svcSnmp" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="service-toggle">
                    <label class="fw"><i class="fas fa-exchange-alt"></i> RADIUS CoA (port 3799)</label>
                    <label class="switch">
                        <input type="checkbox" id="svcRadius" checked>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <div class="config-card">
                <h6><i class="fas fa-file-download" style="color:var(--blue-500)"></i> Provisioning Script</h6>
                <p class="card-subtitle">Download the generated RouterOS script for this device:</p>
                <button class="btn btn-secondary btn-sm" id="downloadConfigBtn" onclick="downloadConfig()">
                    <i class="fas fa-download"></i> Download routersetup.txt
                </button>
            </div>

            <div class="wizard-nav flex-row" style="justify-content:space-between;margin-top:20px;">
                <button class="btn btn-outline" onclick="goToStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                <button class="btn btn-primary btn-lg" id="finishBtn" onclick="finishSetup()">
                    <i class="fas fa-check-circle"></i> Finish Setup
                </button>
            </div>
        </div>
    </div>
</div>

</div>

<script>
let currentStep = 1;
let registeredRouter = null;
let statusPollInterval = null;

function goToStep(step) {
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.getElementById('step' + step).classList.add('active');

    document.querySelectorAll('.step-wizard .step').forEach(el => {
        const s = parseInt(el.dataset.step);
        el.classList.remove('active', 'completed');
        if (s < step) el.classList.add('completed');
        if (s === step) el.classList.add('active');
    });
    document.querySelectorAll('.step-wizard .step-connector').forEach((el, i) => {
        el.classList.toggle('active', i < step - 1);
    });
    currentStep = step;
    window.scrollTo(0, 0);
}

function copyCode(id) {
    const text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById(id).closest('.code-block').querySelector('.copy-btn');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i> Copy', 2000);
    });
}

document.getElementById('deviceForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const name = document.getElementById('deviceName').value.trim();
    const location = document.getElementById('deviceLocation').value.trim();
    const lanIP = document.getElementById('deviceLanIP').value.trim();

    if (!name) return alert('Device name is required');

    const btn = document.getElementById('registerBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';

    try {
        const res = await fetch('/api/mikrotik.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'register', name, location, lan_ip: lanIP })
        });
        const data = await res.json();

        if (!res.ok) throw new Error(data.error || 'Registration failed');

        registeredRouter = data;

        const serverHost = window.location.host || '<?= htmlspecialchars(appHost(), ENT_QUOTES) ?>';
        const scheme = window.location.protocol === 'https:' ? 'https' : 'http';
        const fetchMode = scheme === 'https' ? 'https' : 'http';
        const fetchCmd = `/tool fetch mode=${fetchMode} url="${scheme}://${serverHost}/provision/${data.provision_token}" dst-path=onelink_${data.timestamp}.rsc; :delay 2s; /import onelink_${data.timestamp}.rsc;`;
        document.getElementById('cmd2').textContent = fetchCmd;

        if (scheme === 'http' && window.location.hostname !== 'localhost') {
            document.getElementById('localModeNotice').style.display = 'block';
        }

        goToStep(2);
        startStatusPolling(data.router_id);

    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Register Device <i class="fas fa-arrow-right"></i>';
    }
});

function startStatusPolling(routerId) {
    if (statusPollInterval) clearInterval(statusPollInterval);

    statusPollInterval = setInterval(async () => {
        try {
            const res = await fetch(`/api/mikrotik.php?action=check_status&router_id=${routerId}`);
            const data = await res.json();

            const indicator = document.querySelector('#statusDisplay .status-indicator');
            const statusStrong = document.querySelector('#statusDisplay strong');
            const details = document.getElementById('statusDetails');

            indicator.className = 'status-indicator ' + (data.status === 'registered' ? 'provisioning' : data.status);

            if (data.status === 'online') {
                statusStrong.textContent = 'MikroTik Connected!';
                document.querySelector('#statusDisplay p').textContent = data.wireguard_ip && data.wireguard_ip !== '0.0.0.0' ? 'Secure connection established via WireGuard VPN tunnel.' : 'Device is reachable on the local network.';
                details.style.display = 'block';
                document.getElementById('wgIPDisplay').textContent = data.wireguard_ip || 'N/A (local mode)';
                document.getElementById('statusText').textContent = 'Online';
                document.getElementById('statusText').style.color = 'var(--green)';
                document.getElementById('toStep3Btn').disabled = false;
                clearInterval(statusPollInterval);
            } else if (data.status === 'registered') {
                statusStrong.textContent = 'Device Registered!';
                document.querySelector('#statusDisplay p').textContent = 'WireGuard peer added. Waiting for API connection on port 8729...';
                details.style.display = 'block';
                document.getElementById('wgIPDisplay').textContent = data.wireguard_ip || '—';
                document.getElementById('statusText').textContent = 'Registered';
                document.getElementById('statusText').style.color = 'var(--blue-500)';
            } else if (data.status === 'provisioning') {
                statusStrong.textContent = 'Provisioning in progress...';
                document.querySelector('#statusDisplay p').textContent = 'Script has been served to the device. Waiting for it to come online.';
                details.style.display = 'none';
            } else {
                statusStrong.textContent = 'Waiting for MikroTik';
                document.querySelector('#statusDisplay p').textContent = 'After both Step 2 commands finish on the MikroTik, we\'ll detect when it\'s online over WireGuard.';
                details.style.display = 'none';
            }
        } catch (err) {
            console.error('Status check failed:', err);
        }
    }, 5000);
}

async function downloadConfig() {
    if (!registeredRouter) return alert('No device registered yet');
    window.open(`/api/mikrotik.php?action=provision_script&token=${registeredRouter.provision_token}`, '_blank');
}

async function finishSetup() {
    if (!registeredRouter) return;

    const btn = document.getElementById('finishBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const res = await fetch('/api/mikrotik.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_config',
                router_id: registeredRouter.router_id,
                services: {
                    api_ssl: document.getElementById('svcApiSsl').checked,
                    ssh: document.getElementById('svcSsh').checked,
                    web: document.getElementById('svcWeb').checked,
                    snmp: document.getElementById('svcSnmp').checked,
                    radius: document.getElementById('svcRadius').checked,
                    service_mode: document.getElementById('serviceMode').value
                }
            })
        });
        const data = await res.json();

        if (data.success) {
            alert('Setup complete! Your MikroTik device is now connected to OneLink.');
            window.location.href = '/mikrotik_devices';
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Finish Setup';
    }
}
</script>

<?php
include __DIR__ . '/../components/footer.php';
ob_end_flush();
