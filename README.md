# OneLink - MikroTik Voucher Billing System

![PHP](https://img.shields.io/badge/PHP-7.4+-blue) ![SQLite](https://img.shields.io/badge/SQLite-supported-orange) ![License](https://img.shields.io/badge/License-MIT-green) ![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen)

> **This branch (`master`) is stable and ready for production deployment.**

A voucher-based billing system for managing WiFi access through MikroTik routers. Customers purchase prepaid vouchers, redeem them on a captive portal, and get timed internet access. Administrators generate, track, and manage vouchers through a web dashboard with full revenue reporting.

---

## Table of Contents

1. [How It Works](#how-it-works)
2. [Features](#features)
3. [Architecture](#architecture)
4. [Requirements](#requirements)
5. [Installation](#installation)
6. [Usage](#usage)
7. [API Reference](#api-reference)
8. [Database Schema](#database-schema)
9. [Security Considerations](#security-considerations)
10. [Troubleshooting](#troubleshooting)
11. [File Structure](#file-structure)
12. [License](#license)

---

## How It Works

```
1. Admin generates vouchers (select plan, quantity, price)
        ↓
2. Vouchers are printed or shared with customers
        ↓
3. Customer connects to WiFi → redirected to captive portal
        ↓
4. Customer enters voucher code → validated against database
        ↓
5. Voucher is marked as "used" → hotspot user created on router
        ↓
6. Customer gets internet access for the plan duration
        ↓
7. When time expires → access is automatically revoked
```

---

## Features

- **Voucher Generation**: Batch-create vouchers with unique 8-digit codes, linked to plans and priced in TSh
- **Captive Portal**: Customers enter voucher code on a branded login page; auto-login after redemption
- **Revenue Tracking**: Dashboard with total revenue, vouchers used, revenue by router, and daily breakdowns
- **Printable Vouchers**: Select and print voucher cards with code, plan, price, and expiry
- **Multi-Router Support**: Manage multiple MikroTik routers from one dashboard
- **Plan Management**: Define plans with duration in days/hours/minutes
- **Automatic Expiration**: Vouchers expire after their validity period
- **Voucher Management**: Filter, search, and delete vouchers; deleted used vouchers also remove hotspot users from the router
- **Customer Tracking**: Store customer name and phone number per voucher
- **Admin Authentication**: Login-protected dashboard

---

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│                  Web Dashboard (UI)                       │
│  /pages/dashboard.php  /pages/vouchers.php               │
│  /pages/revenue.php    /pages/plans.php                  │
└────────────────────────┬─────────────────────────────────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
          ▼              ▼              ▼
    /api/vouchers.php  /api/plans.php  /api/qos.php
    (generate,         (CRUD plans)    (block/unblock)
     redeem, list,
     revenue)
          │              │              │
          └──────────────┼──────────────┘
                         │
          ┌──────────────▼──────────────┐
          │       SQLite Database        │
          │      /db/routers.db          │
          │  (routers, plans, vouchers,  │
          │   billing, admins)           │
          └──────────────┬──────────────┘
                         │
          ┌──────────────▼──────────────┐
          │    Captive Portal            │
          │    /pages/captive.php        │
          │  (customer voucher redeem)   │
          └──────────────┬──────────────┘
                         │
              ┌──────────▼──────────┐
               │   MikroTik Router      │
               │   (Hotspot)            │
              └─────────────────────┘
```

---

## Requirements

- PHP 7.4+ with `pdo_sqlite` and `curl` extensions
- MikroTik router with hotspot enabled
- Web server (Apache/Nginx or PHP built-in server)

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/Frost-bit-star/tendaN301-billing.git
cd tendaN301-billing
```

### 2. Install PHP and required extensions

```bash
sudo apt update
sudo apt install -y php php-cli php-sqlite3 php-curl
```

### 3. Build and start

```bash
php stack install
php stack build
php stack start
```

The dashboard will be available at `http://localhost:8000`.

### 4. Configure `.env`

Copy the example file and fill in your values:

```bash
cp .env.example .env
```

| Variable | Description |
|----------|-------------|
| `APP_NAME` | Brand shown across the UI. Defaults to `OneLink`. |
| `APP_URL` | Public base URL of this instance (e.g. `https://onelink.example.com`). Used for all internal links: captive portal redirect, MikroTik provisioning scripts and hotspot walled-garden rules. |
| `TUNGARD_API_URL` | Base URL of the TunGuard server. Defaults to `http://127.0.0.1:9000`. |
| `TUNGARD_API_KEY` | API key from the TunGuard dashboard (**Settings → API Key**). Required for router provisioning. |
| `WG_ENDPOINT` | Optional override for the WireGuard endpoint advertised to routers. Defaults to the host of `APP_URL`. |
| `SMS_API_URL` / `SMS_API_KEY` | Optional SMS gateway overrides. |

> Router provisioning is handled through the TunGuard API.

---

## Usage

### Generating Vouchers

1. Navigate to **Vouchers** in the sidebar
2. Select a **Router** and **Plan**
3. Set **Quantity**, **Price (TSh)**, and optionally **Phone** / **Customer Name**
4. Click **Generate Vouchers** — codes appear instantly
5. Use the **Print** tab to print voucher cards for distribution

### Customer Redeems a Voucher

1. Customer connects to WiFi → browser redirects to captive portal
2. Customer enters their 8-digit voucher code
3. System validates the code, creates a hotspot user on the router
4. Customer is automatically logged in and gets internet access

### Viewing Revenue

1. Navigate to **Revenue** in the sidebar
2. Filter by period (Today / This Week / This Month / All Time)
3. Filter by router
4. View total revenue, vouchers used, average per voucher, and breakdowns by router and day

### Managing Vouchers

- **Filter** by router and status (Active / Used / Expired)
- **Delete** vouchers — for used vouchers, this also removes the hotspot user from the router

---

## API Reference

### Vouchers API (`/api/vouchers.php`)

**POST** — Generate vouchers
```bash
curl -X POST http://localhost:8000/api/vouchers.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "generate",
    "plan_id": 1,
    "router_id": 1,
    "quantity": 5,
    "price": 500,
    "phone": "255712345678",
    "customer_name": "John Doe"
  }'
```

**POST** — Redeem a voucher
```bash
curl -X POST http://localhost:8000/api/vouchers.php \
  -H "Content-Type: application/json" \
  -d '{"action": "redeem", "code": "12345678"}'
```

**GET** — List vouchers
```bash
curl "http://localhost:8000/api/vouchers.php?action=list&status=active&limit=50"
```

**GET** — Voucher stats
```bash
curl "http://localhost:8000/api/vouchers.php?action=stats"
```

**GET** — Revenue report
```bash
curl "http://localhost:8000/api/vouchers.php?action=revenue&period=today"
```

**DELETE** — Remove a voucher
```bash
curl -X DELETE http://localhost:8000/api/vouchers.php \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

### Plans API (`/api/plans.php`)

```bash
# List plans
curl http://localhost:8000/api/plans.php

# Create plan
curl -X POST http://localhost:8000/api/plans.php \
  -H "Content-Type: application/json" \
  -d '{"name": "12 Hours", "hours": 12, "price": 500}'
```

### Router Control (`/api/control.php`)

```bash
# List all routers
curl http://localhost:8000/api/control.php
```

---

## Database Schema

### Vouchers Table
```sql
CREATE TABLE vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,          -- 8-digit voucher code
    plan_id INTEGER,                    -- linked plan
    router_id INTEGER,                  -- assigned router
    phone TEXT,                         -- customer phone
    price REAL DEFAULT 0,               -- price in TSh
    status TEXT DEFAULT 'active',       -- active | used | expired
    customer_name TEXT,
    created_at TIMESTAMP,
    used_at TEXT,                       -- timestamp when redeemed
    used_mac TEXT,                      -- MAC of device that redeemed
    expires_at TEXT                     -- voucher expiry date
);
```

### Plans Table
```sql
CREATE TABLE plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,          -- e.g. "12 Hours", "7 Days"
    days INTEGER DEFAULT 0,
    hours INTEGER DEFAULT 0,
    minutes INTEGER DEFAULT 0,
    created_at TIMESTAMP
);
```

### Routers Table
```sql
CREATE TABLE routers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    ip TEXT NOT NULL,
    port INTEGER DEFAULT 80,
    password TEXT NOT NULL,
    type TEXT DEFAULT 'mikrotik',       -- router type
    device_id TEXT,
    wireguard_ip TEXT,
    tenant_id INTEGER
);
```

---

## Security Considerations

- Set database permissions: `chmod 600 db/routers.db && chmod 700 db/`
- Use HTTPS in production (reverse proxy with Nginx/Apache or ngrok)
- Default admin credentials (`admin` / `1111`) should be changed immediately
- Restrict API access with authentication for external calls
- Regularly back up `db/routers.db`
- Keep PHP and server packages updated

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Customer can't redeem voucher | Verify router is online in dashboard; check voucher status is "active" and not expired |
| Voucher redeemed but no internet | Check router hotspot is configured; verify MikroTik API connectivity |
| Router shows offline | Verify IP and port; test with `ping <router-ip>`; check firewall rules |
| Revenue data missing | Ensure vouchers have a price set; check "Used" status in voucher list |
| Captive portal not loading | Verify router's hotspot redirect URL points to `/pages/captive.php` |

---

## File Structure

```
.
├── index.php                    # Main entry point / router
├── stack                        # CLI tool for building/running
├── db/
│   ├── routers.db               # SQLite database
│   └── schema.php               # Database schema setup
├── api/
│   ├── vouchers.php             # Voucher CRUD, redeem, revenue
│   ├── plans.php                # Plan management
│   ├── control.php              # Router CRUD
│   ├── qos.php                  # Device block/unblock
│   ├── mikrotik.php             # MikroTik router management
│   └── mikrotik_api.php         # MikroTik API client
├── pages/
│   ├── dashboard.php            # Main dashboard
│   ├── vouchers.php             # Voucher management UI
│   ├── revenue.php              # Revenue reports
│   ├── captive.php              # Customer captive portal
│   ├── plans.php                # Plan management
│   ├── users.php                # Device/user list
│   └── connect_mikrotik.php     # MikroTik setup wizard
├── auth/
│   ├── login.php                # Device fetching
│   ├── billing.php              # Billing logic
│   └── sync.php                 # Background sync worker
├── components/
│   ├── header.php
│   ├── footer.php
│   └── sidebar.php
└── logs/
    ├── sync.log
    └── ws.log
```

---

## License

MIT License — see [LICENSE](./LICENSE) for details.
