<?php
require_once __DIR__ . '/../config.php';
$deviceId = $_GET['device'] ?? '';
$host = appHost();
$scheme = appScheme();
$captiveUrl = "$scheme://$host/captive?router=" . urlencode($deviceId);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(appName()) ?></title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: white;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: Arial, sans-serif;
}

.loader {
    width: 55px;
    height: 55px;
    border: 6px solid #eee;
    border-top: 6px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.text {
    position: absolute;
    margin-top: 100px;
    color: #777;
    font-size: 14px;
}

@keyframes spin {
    100% {
        transform: rotate(360deg);
    }
}
</style>

</head>

<body>

<div class="loader"></div>
<div class="text">Connecting <?= htmlspecialchars(appName()) ?>...</div>


<script>

setTimeout(function(){

    window.location.href = "<?= $captiveUrl ?>";

}, 2000);

</script>

</body>
</html>
