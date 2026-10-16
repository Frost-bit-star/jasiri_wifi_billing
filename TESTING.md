# Manual Testing: WireGuard + MikroTik Provisioning

## Login Credentials

- **URL:** https://onelink.example.com
- **Admin login:** username `admin`, password `1111`
- (The email `jnyaragita12@gmail.com` is tied to this admin account)

---

echo '' | sudo -S wg show wg0
```

---

## Test 1: Server WireGuard Is Running

SSH into the VPS and verify:

```bash
curl -s -H "X-API-Key: ${TUNGARD_API_KEY:-<key>}" http://127.0.0.1:9000/api/status
```

Expected output should show:
- `interface: wg0` with a `public key`
- `listening port: 13231`
- No peers yet (or peers from previous tests)

```bash
curl -s -H "X-API-Key: ${TUNGARD_API_KEY:-<key>}" http://127.0.0.1:9000/api/status
```

---

## Test 2: Register a MikroTik Device

1. Login as admin at https://onelink.example.com
2. Go to **MikroTik > Connect Device**
3. Step 1 — Fill in:
   - Device Name: `test-router`
   - Location: `Test Office`
4. Click **Register Device**

The page should show:
- A device ID (UUID)
- A WireGuard IP (e.g. `10.100.0.2`)
- A **fetch command** to paste into MikroTik

**Save the fetch command — you need it next.**

---

## Test 3: Run the Provision Script on MikroTik

SSH into the MikroTik router and paste the fetch command:

```
/tool fetch mode=https url="https://onelink.example.com/provision/<TOKEN>" dst-path=onelink_<timestamp>.rsc; :delay 2s; /import onelink_<timestamp>.rsc;
```

Expected result:
- `status: finished`
- `downloaded: 4KiB`
- Script output ending with:
  ```
  OneLink Provisioning Complete!
  Device ID: <uuid>
  Client IP: 10.100.0.X
  Server IP: 10.100.0.1
  ```

If you see `Script Error: failure: already have ...` on re-runs, that means the `on-error={}` wrappers are working — it will continue past the error.

---

## Test 4: Verify WireGuard Tunnel From Server

SSH into the VPS and check:

```bash
curl -s -H "X-API-Key: ${TUNGARD_API_KEY:-<key>}" http://127.0.0.1:9000/api/status
```

After the MikroTik runs the provision script, you should see a new peer:

```
peer: <mikrotik-public-key>
  endpoint: <mikrotik-public-ip>:<port>
  allowed ips: 10.100.0.X/32
  latest handshake: X seconds ago
  transfer: ...
```

**If no peer appears:** the TunGuard API call to add the peer failed; check TunGuard logs and API key.

**If peer appears but no handshake:** the MikroTik firewall is blocking UDP 13231 outbound, or the MikroTik WireGuard config endpoint is wrong.

---

## Test 5: Verify Web UI Detects Online Status

1. Go to https://onelink.example.com (MikroTik > View Devices)
2. The device card should show **Online** (green badge)

If it still shows `provisioning`:
- The WG handshake hasn't happened yet (wait 30s, refresh)
- Or `checkWgHandshake()` is failing due to sudo issue

Test the check_status API directly:
```
https://onelink.example.com/api/mikrotik.php?action=check_status&router_id=<ID>
```

Should return:
```json
{"router_id":10,"status":"online","wireguard_ip":"10.100.0.2"}
```

If status is still `provisioning`, run on VPS:
```bash
curl -s -H "X-API-Key: ${TUNGARD_API_KEY:-<key>}" http://127.0.0.1:9000/api/status latest-handshakes
```

Look for the MikroTik's public key and verify the handshake timestamp is recent (< 180 seconds).

---

## Test 6: Re-Provisioning (Idempotency)

Run the same fetch command on MikroTik again. It should complete without errors. Any duplicate resource errors are silently caught by `on-error={}`.

---

## Test 7: Wizard Flow (Connect Device page)

1. Go to **MikroTik > Connect Device**
2. Register a device (Step 1)
3. The page moves to Step 2 — showing the fetch command
4. Run the command on MikroTik
5. The page should auto-detect the device coming online (polls every 5s)
6. Click **Next** to go to Step 3 (service config)
7. Click **Finish** to save

If Step 2 never advances:
- Check WG handshake on VPS (Test 4)
- Check check_status API (Test 5)
- Check browser console for fetch errors

---

## Troubleshooting

### TunGuard API issues
Ensure TunGuard is running and reachable at http://127.0.0.1:9000 with valid TUNGARD_API_KEY.

### MikroTik can't reach server
```bash
# On MikroTik:
/tool fetch url="https://onelink.example.com" mode=https
```
Should return 200/HTML. If not, DNS or firewall issue.

### WireGuard port blocked
```bash
# On VPS:
sudo ufw status | grep 13231
```
Should show ALLOW. If not:
```bash
sudo ufw allow 13231/udp
```

### PHP session errors in provision endpoint
The `/provision/<token>` endpoint goes through `index.php` which calls `session_start()`. Then `mikrotik.php` also calls `session_start()`. This was fixed — if you see "session already active" notices, the fix hasn't been deployed yet.
