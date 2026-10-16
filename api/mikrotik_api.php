<?php
class MikroTikAPI {
    private $sock = null;
    private $host;
    private $port;
    private $user;
    private $pass;

    public function __construct($host, $port = 8728, $user = 'admin', $pass = '') {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
    }

    public function connect() {
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 5);
        if (!$this->sock) {
            $ctx = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
            ]);
            $this->sock = @stream_socket_client("tls://{$this->host}:{$this->port}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        }
        if (!$this->sock) {
            throw new Exception("Cannot connect to {$this->host}:{$this->port} - $errstr ($errno)");
        }
        stream_set_timeout($this->sock, 10);
        $this->readWord();
        $this->login();
        return true;
    }

    public function close() {
        if ($this->sock) { fclose($this->sock); $this->sock = null; }
    }

    private function login() {
        $response = $this->command(['/login']);
        if (isset($response[0]['ret'])) {
            $challenge = $response[0]['ret'];
            $md5 = md5("\0" . $this->pass . hex2bin($challenge));
            $this->command(['/login', "=name={$this->user}", "=response=00$md5"]);
        } else {
            $this->command(['/login', "=name={$this->user}", "=password={$this->pass}"]);
        }
    }

    public function command($words) {
        $this->sendSentence($words);
        $all = [];
        $current = [];
        $hasTrap = false;
        while (true) {
            $word = $this->readWord();
            if ($word === false) break;
            if ($word === '') {
                if (!empty($current)) { $all[] = $current; $current = []; }
                continue;
            }
            if ($word === '!done') break;
            if ($word === '!trap') {
                if (!empty($current)) { $all[] = $current; $current = []; }
                $hasTrap = true;
                continue;
            }
            if ($word === '!re') {
                if (!empty($current)) { $all[] = $current; $current = []; }
                $hasTrap = false;
                continue;
            }
            $this->parseWord($word, $current);
        }
        if (!empty($current)) $all[] = $current;
        if ($hasTrap && !empty($all)) {
            $last = end($all);
            $all[array_key_last($all)]['!trap'] = true;
        }
        return $all;
    }

    public function commandRaw($words) {
        $this->sendSentence($words);
        $all = [];
        $current = [];
        $done = false;
        while (!$done) {
            $word = $this->readWord();
            if ($word === false) break;
            if ($word === '') {
                if (!empty($current)) { $all[] = $current; $current = []; }
                continue;
            }
            if ($word[0] === '!') {
                if ($word === '!done') $done = true;
                if ($word === '!trap' && !empty($current)) { $current['!trap'] = true; }
                if (!empty($current)) { $all[] = $current; $current = []; }
                if ($done) break;
                continue;
            }
            $this->parseWord($word, $current);
        }
        return $all;
    }

    private function parseWord($word, &$current) {
        if ($word[0] === '=' && isset($word[1]) && $word[1] === '!') {
            $current['!error'] = substr($word, 2);
        } elseif ($word[0] === '=') {
            $eqPos = strpos($word, '=', 1);
            $key = substr($word, 1, $eqPos - 1);
            $val = substr($word, $eqPos + 1);
            $current[$key] = $val;
        } elseif ($word[0] === '.') {
            $current['_sentence'] = $word;
        }
    }

    private function sendSentence($words) {
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        $this->writeWord('');
    }

    private function writeWord($word) {
        $len = strlen($word);
        $this->writeLength($len);
        if ($len > 0) fwrite($this->sock, $word);
    }

    private function readWord() {
        $len = $this->readLength();
        if ($len === false) return false;
        if ($len === 0) return '';
        return $this->readExact($len);
    }

    private function readExact($len) {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($this->sock, $len - strlen($buf));
            if ($chunk === false || $chunk === '') return false;
            $buf .= $chunk;
        }
        return $buf;
    }

    private function writeLength($length) {
        if ($length < 128) {
            fwrite($this->sock, chr($length));
        } elseif ($length < 16384) {
            fwrite($this->sock, chr(($length & 0x7F) | 0x80) . chr(($length >> 7) & 0x7F));
        } else {
            $result = chr(($length & 0x7F) | 0x80);
            $length >>= 7;
            while ($length >= 128) {
                $result .= chr(($length & 0x7F) | 0x80);
                $length >>= 7;
            }
            $result .= chr($length);
            fwrite($this->sock, $result);
        }
    }

    private function readLength() {
        $byte = fread($this->sock, 1);
        if ($byte === false || $byte === '') return false;
        $value = ord($byte);
        if ($value & 0x80) {
            $result = $value & 0x7F;
            $multiplier = 128;
            while (true) {
                $byte = fread($this->sock, 1);
                if ($byte === false) return false;
                $value = ord($byte);
                $result += ($value & 0x7F) * $multiplier;
                $multiplier *= 128;
                if (!($value & 0x80)) break;
            }
            return $result;
        }
        return $value;
    }

    public function addHotspotUser($server, $username, $password, $limitUptime, $rateLimit = null, $profile = null) {
        $cmd = [
            '/ip/hotspot/user/add',
            "=server=$server",
            "=name=$username",
            "=password=$password",
            "=limit-uptime=$limitUptime",
            "=comment=OneLink voucher",
        ];
        if ($profile) {
            $cmd[] = "=profile=$profile";
        } elseif ($rateLimit) {
            $cmd[] = "=rate-limit=$rateLimit";
        }
        $result = $this->command($cmd);
        return $result;
    }

    public function ensureCappedProfile($rateLimit = '1M/500k') {
        $existing = $this->command(['/ip/hotspot/user-profile/find', '?name=capped']);
        if (!empty($existing)) {
            return true;
        }
        $result = $this->command([
            '/ip/hotspot/user-profile/add',
            '=name=capped',
            "=rate-limit=$rateLimit",
        ]);
        foreach ($result as $item) {
            if (!empty($item['!trap']) || isset($item['!error'])) {
                return false;
            }
        }
        return true;
    }

    public function removeHotspotUser($username) {
        $found = $this->command(['/ip/hotspot/user/print', '?name=' . $username]);
        if (is_array($found)) {
            foreach ($found as $item) {
                $id = is_array($item) ? ($item['.id'] ?? null) : null;
                if ($id) {
                    return $this->command(['/ip/hotspot/user/remove', "=.id=$id"]);
                }
            }
        }
        return null;
    }

    public function findHotspotActive($user) {
        return $this->command(['/ip/hotspot/active/find', "?user=$user"]);
    }

    public function disconnectHotspotActive($activeId) {
        return $this->command(['/ip/hotspot/active/remove', "=.id=$activeId"]);
    }

    public function disconnectHotspotUser($username) {
        $active = $this->command(['/ip/hotspot/active/print', "?user=$username"]);
        $removed = 0;
        if (is_array($active)) {
            foreach ($active as $a) {
                $id = is_array($a) ? ($a['.id'] ?? null) : null;
                if ($id) {
                    $this->command(['/ip/hotspot/active/remove', "=.id=$id"]);
                    $removed++;
                }
            }
        }
        return $removed;
    }

    public function getHotspotActiveCount() {
        $result = $this->command(['/ip/hotspot/active/print']);
        return $result;
    }

    public function removeHotspotUserByUsername($username) {
        $found = $this->command(['/ip/hotspot/user/print', '?name=' . $username]);
        if (is_array($found)) {
            foreach ($found as $item) {
                $id = is_array($item) ? ($item['.id'] ?? null) : null;
                if ($id) {
                    $this->command(['/ip/hotspot/user/remove', "=.id=$id"]);
                    return true;
                }
            }
        }
        return null;
    }

    public function getWirelessInfo() {
        $interfaces = $this->command(['/interface/wireless/print']);
        $profiles = $this->command(['/interface/wireless/security-profiles/print']);
        return ['interfaces' => $interfaces, 'profiles' => $profiles];
    }

    public function getWirelessSsid() {
        $interfaces = $this->command(['/interface/wireless/print']);
        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? '';
            $defaultName = $iface['default-name'] ?? '';
            if (strtolower($name) === 'wlan1' || strtolower($defaultName) === 'wlan1') {
                return $iface['ssid'] ?? '';
            }
        }
        foreach ($interfaces as $iface) {
            if (!empty($iface['ssid'])) return $iface['ssid'];
        }
        return '';
    }

    public function getWirelessSecurityProfile() {
        $interfaces = $this->command(['/interface/wireless/print']);
        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? '';
            $defaultName = $iface['default-name'] ?? '';
            if (strtolower($name) === 'wlan1' || strtolower($defaultName) === 'wlan1') {
                return $iface['security-profile'] ?? '';
            }
        }
        return '';
    }

    public function setWireless($ssid, $securityProfile = null, $mode = 'ap-bridge') {
        $this->command(['/interface/wireless/set', '=wlan1', "=ssid=$ssid", "=mode=$mode", "=disabled=no"]);
        if ($securityProfile) {
            $this->command(['/interface/wireless/set', '=wlan1', "=security-profile=$securityProfile"]);
        }
        return true;
    }

    public function setWirelessSsid($ssid, $mode = 'ap-bridge') {
        $interfaces = $this->command(['/interface/wireless/print']);
        $target = null;
        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? '';
            $defaultName = $iface['default-name'] ?? '';
            if (strtolower($name) === 'wlan1' || strtolower($defaultName) === 'wlan1') {
                $target = $iface;
                break;
            }
        }
        if (!$target) $target = $interfaces[0] ?? null;
        if (!$target || empty($target['.id'])) {
            throw new Exception('No wireless interface found on router');
        }
        $id = $target['.id'];
        $result = $this->commandRaw(['/interface/wireless/set', "=.id=$id", "=ssid=$ssid", "=mode=$mode", "=disabled=no"]);
        foreach ($result as $item) {
            if (!empty($item['!trap']) || isset($item['!error'])) {
                throw new Exception('Router rejected SSID change: ' . ($item['!error'] ?? 'unknown error'));
            }
        }
        return true;
    }

    public function setWirelessProfile($name, $authTypes = '', $enc = 'none') {
        $existing = $this->command(['/interface/wireless/security-profiles/find', "?name=$name"]);
        if (!empty($existing)) {
            $id = is_array($existing[0]) ? ($existing[0]['.id'] ?? '*0') : $existing[0];
            return $this->command(['/interface/wireless/security-profiles/set', "=.id=$id", "=mode=none", "=authentication-types=$authTypes", "=unicast-cast-encryption=$enc"]);
        }
        return $this->command(['/interface/wireless/security-profiles/add', "=name=$name", "=mode=none", "=authentication-types=$authTypes", "=unicast-cast-encryption=$enc"]);
    }

    public function setBridgePort($interface, $bridge) {
        $existing = $this->command(['/interface/bridge/port/find', "?interface=$interface"]);
        if (!empty($existing)) {
            $id = is_array($existing[0]) ? ($existing[0]['.id'] ?? '*0') : $existing[0];
            return $this->command(['/interface/bridge/port/set', "=.id=$id", "=bridge=$bridge"]);
        }
        return $this->command(['/interface/bridge/port/add', "=interface=$interface", "=bridge=$bridge"]);
    }

    public function getHotspotActiveUsers() {
        return $this->command(['/ip/hotspot/active/print']);
    }

    public function getPppActiveUsers() {
        return $this->command(['/ppp/active/print']);
    }

    public function getHotspotUsers() {
        return $this->command(['/ip/hotspot/user/print']);
    }

    public function getPppSecrets() {
        return $this->command(['/ppp/secret/print']);
    }

    public function getHotspotUserStats() {
        return $this->command(['/ip/hotspot/user/stats/print']);
    }

    public function getInterfaceTraffic() {
        return $this->command(['/interface/monitor-traffic', '=once=', '=interface=onelink-wg']);
    }

    public function getSystemResources() {
        return $this->command(['/system/resource/print']);
    }

    public function getInterfaceStats() {
        return $this->command(['/interface/print']);
    }
}
