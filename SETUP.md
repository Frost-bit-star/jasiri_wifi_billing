# OneLink - Server Setup Guide

Step-by-step guide for deploying the OneLink billing system on a fresh Ubuntu server.

---

## Prerequisites

- Fresh Ubuntu 22.04/24.04 server (VPS or local)
- Root or sudo access
- A domain pointing to your server (e.g. `onelink.example.com`)
- Git installed

---

## Step 1: Initial Server Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install essential packages
sudo apt install -y git curl wget unzip ufw
```

---

## Step 2: Install PHP and Extensions

```bash
sudo apt install -y php php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip php-gd
```

Verify:

```bash
php -v
php -m | grep -E "sqlite3|curl|mbstring"
```

---

## Step 3: Install TunGuard

TunGuard manages the WireGuard server and router peers. The billing app
provisions router peers through the TunGuard HTTP API.

1. Install TunGuard from https://github.com/TunGuard/tanguard-binary
2. Configure TunGuard (server IP/subnet, listen port, and an API key)
3. Start the TunGuard service
4. Add `TUNGARD_API_KEY` to your `.env` (Step 5)

Ensure TunGuard is reachable at `TUNGARD_API_URL` (default `http://127.0.0.1:9000`).

## Step 4: Configure Firewall

```bash
sudo ufw allow 22/tcp comment "SSH"
sudo ufw allow 80/tcp comment "HTTP"
sudo ufw allow 443/tcp comment "HTTPS"
sudo ufw allow 13231/udp comment "TunGuard UDP"
sudo ufw enable
sudo ufw status
```

---

## Step 5: Deploy the Application

```bash
# Clone the repo
cd /home
git clone https://github.com/Frost-bit-star/tendaN301-billing.git
cd tendaN301-billing
```

### Configure `.env`

Copy the example file and set the public URL of this instance plus the TunGuard API key:

```bash
cp .env.example .env
nano .env
```

```env
APP_NAME=OneLink
APP_URL=https://onelink.example.com
TUNGARD_API_URL=http://127.0.0.1:9000
TUNGARD_API_KEY=<your-tunguard-api-key>
```

Get the TunGuard API key from the TunGuard dashboard under **Settings → API Key**.

### WireGuard peers are managed by TunGuard

Router provisioning goes through the TunGuard HTTP API, so the app no longer runs
Peers are managed by the TunGuard API; no sudoers entry is needed. Make sure the TunGuard
server is running and reachable at `TUNGARD_API_URL`. The server public key and listening port are read from the TunGuard API.

### Build the database

```bash
php stack build
```

Or manually:

```bash
php -r "require 'db/schema.php';"
```

### Set file permissions

```bash
chmod 600 db/routers.db
chmod 700 db/
chmod +x stack
```

### Start the server

```bash
# Development (foreground)
php stack start

# Or run in background
nohup php -S 0.0.0.0:8000 -t . > logs/server.log 2>&1 &
```

The dashboard is now at `http://YOUR_SERVER_IP:8000`.

---

## Step 6: Set Up Nginx Reverse Proxy (Production)

For a production setup with your domain and HTTPS:

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
```

Create the Nginx config:

```bash
sudo tee /etc/nginx/sites-available/onelink <<'EOF'
server {
    listen 80;
    server_name onelink.example.com;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Allow large file uploads (vouchers, etc.)
    client_max_body_size 10M;
}
EOF

sudo ln -s /etc/nginx/sites-available/onelink /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Get the SSL certificate:

```bash
sudo certbot --nginx -d onelink.example.com
sudo certbot renew --dry-run
```

---

## Step 7: Start the App on Boot (systemd)

Create a systemd service so the PHP server starts automatically:

```bash
sudo tee /etc/systemd/system/onelink.service <<'EOF'
[Unit]
Description=OneLink Billing Server
After=network.target

[Service]
Type=simple
User=jackal
WorkingDirectory=/home/jackal/tendaN301-billing
ExecStart=/usr/bin/php -S 0.0.0.0:8000 -t .
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable onelink
sudo systemctl start onelink
sudo systemctl status onelink
```

---

## Step 8: Open the Dashboard

1. Go to `https://onelink.example.com`
2. Login with:
   - Username: `admin`
   - Password: `1111`
3. **Change the default password immediately**

---

## Step 9: Add Your First Router

### Tenda Router

1. Go to **Tenda Routers > Add Router**
2. Enter router name, IP, port (80), and admin password
3. Click Add

### MikroTik Router

1. Go to **MikroTik > Connect Device**
2. **Step 1**: Enter device name and location, click Register
3. **Step 2**: Copy and run the two commands on the MikroTik via Winbox/SSH:
   ```
   /system device-mode update mode=advanced fetch=yes
   /tool fetch mode=https url="https://onelink.example.com/provision/TOKEN" dst-path=onelink_TIMESTAMP.rsc; :delay 2s; /import onelink_TIMESTAMP.rsc;
   ```
4. Wait 1-2 minutes for the device to come online (dashboard polls automatically)
5. **Step 3**: Configure services and finish

---

## MikroTik Setup Checklist

For each MikroTik router you connect:

- [ ] Router has internet access (can reach `onelink.example.com`)
- [ ] RouterOS 7+ is installed
- [ ] Run `/system device-mode update mode=advanced fetch=yes` (once per new router)
- [ ] Run the `/tool fetch` provisioning command from the dashboard
- [ ] Router creates its own WireGuard key and calls back to register it
- [ ] Wait for WireGuard handshake (device appears "online" in dashboard)
- [ ] Configure bridge ports and hotspot services in Step 3

---

## How WireGuard Peers Work

```
MikroTik Router                        Your Server
    |                                      |
    |  1. Runs provisioning script        |
    |  2. Creates own WG key pair         |
    |  3. Fetch GET /api/wireguard_       |
    |     register.php?device=X&pubkey=Y  |
    |------------------------------------->|
    |                                      |
    |  4. Server saves pubkey             |
    |  5. Server runs:                    |
    |     TunGuard adds peer <Y>          |
    |       allowed-ips 10.100.0.x/32     |
    |                                      |
    |<-- WireGuard handshake (UDP 13231) ->|
    |                                      |
    |   MikroTik gets 10.100.0.x/24       |
    |   Server acts as 10.100.0.1         |
    |                                      |
    |<-- API calls over WireGuard --------|
    |   (SSH, REST API, SNMP)             |
```

- TunGuard manages the WireGuard server (interface/IP/listen port) according to its configuration
- TunGuard manages the interface configuration; no manual wg0.conf peers needed
- Each MikroTik router **generates its own WireGuard key pair** during provisioning
- The router keeps its private key; the server never sees it
- After creating the WireGuard interface, the router calls `/api/wireguard_register.php` with its public key
- The server saves the public key to the database and adds the peer dynamically via TunGuard API.
- MikroTik management (REST API, SSH) is only accessible over the WireGuard tunnel

---

## Troubleshooting

### Tunnel not connecting

```bash
# Check interface is up
curl -s -H "X-API-Key: $TUNGARD_API_KEY" http://127.0.0.1:9000/api/status || echo "TunGuard API unreachable"

# Check listening port
ss -ulnp | grep 13231

# Check IP forwarding
cat /proc/sys/net/ipv4/ip_forward
# Should be: 1

# Check iptables NAT
sudo iptables -t nat -L -n | grep MASQUERADE
```

### MikroTik can't reach the server

```bash
# From the MikroTik terminal, test:
/ping onelink.example.com

# Check if port 13231 is reachable
/tool netwatch add host=onelink.example.com port=13231 interval=10s
```

### Dashboard shows 502 Bad Gateway

```bash
# Check PHP server is running
sudo systemctl status onelink

# Check logs
sudo journalctl -u onelink -f

# Restart
sudo systemctl restart onelink
```

### Database errors

```bash
# Rebuild schema
php -r "require 'db/schema.php';"

# Check file permissions
ls -la db/routers.db
# Should be: -rw------- (600)
```

### UFW blocking TunGuard UDP

```bash
sudo ufw status
# Ensure 13231/udp is allowed

# If not:
sudo ufw allow 13231/udp
sudo ufw reload
```

---

## File Structure

```
tendaN301-billing/
├── index.php                    # Main router
├── stack                        # CLI launcher
│
├── api/
│   ├── mikrotik.php             # MikroTik provisioning + WG peers
│   ├── wireguard_register.php   # Router callback: saves pubkey, adds WG peer
│   ├── vouchers.php             # Voucher CRUD
│   ├── control.php              # Tenda router CRUD
│   ├── billing.php              # Billing operations
│   ├── plans.php                # Plan CRUD
│   └── cron.php                 # Background worker
│
├── pages/
│   ├── dashboard.php            # Main dashboard
│   ├── connect_mikrotik.php     # MikroTik 3-step wizard
│   ├── mikrotik_devices.php     # MikroTik device list
│   ├── vouchers.php             # Voucher management
│   ├── billuser.php             # Tenda user billing
│   ├── billing.php              # Expired users
│   ├── plans.php                # Plan management
│   └── ...
│
├── components/
│   ├── sidebar.php              # Navigation (Tenda + MikroTik)
│   ├── header.php               # Page header
│   └── footer.php               # Page footer
│
├── db/
│   ├── routers.db               # SQLite database
│   ├── schema.php               # Schema migrations
│
├── auth/
│   ├── v2.php                   # Tenda router communication
│   └── throttle.php             # Bandwidth control
│
└── logs/                        # Application logs
```

---

## Quick Reference

| Task | Command |
|------|---------|
| Start server | `php stack start` |
| Start as service | `sudo systemctl start onelink` |
| Build database | `php stack build` |
| Check TunGuard API | `curl -s -H "X-API-Key: $TUNGARD_API_KEY" http://127.0.0.1:9000/api/status || echo "TunGuard API unreachable"` |
| Restart TunGuard | `sudo systemctl restart tanguard` |
| Check server logs | `sudo journalctl -u onelink -f` |
| Check firewall | `sudo ufw status` |
| Revoke SSL | `sudo certbot revoke --cert-name onelink.example.com` |
