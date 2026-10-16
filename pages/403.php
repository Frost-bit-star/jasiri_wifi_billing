<?php
require_once __DIR__ . '/../config.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 - Restricted | <?= htmlspecialchars(appName()) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">

<style>
:root {
  --blue-500: #1A73E8;
  --blue-600: #1765CC;
  --green: #34A853;
  --yellow: #FBBC04;
  --red: #EA4335;
  --teal-400: #26C6DA;
  --on-surface: #202124;
  --on-surface-med: #5F6368;
  --on-surface-low: #9AA0A6;
  --surface: #FFFFFF;
  --surface-2: #F8F9FA;
  --surface-3: #F1F3F4;
  --surface-4: #DADCE0;
  --radius-md: 12px;
  --radius-lg: 16px;
  --radius-full: 999px;
  --shadow-2: 0 2px 6px rgba(60,64,67,.15), 0 1px 2px rgba(60,64,67,.3);
  --shadow-3: 0 4px 12px rgba(60,64,67,.25), 0 2px 6px rgba(60,64,67,.2);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  font-family: 'Roboto', sans-serif;
  background: linear-gradient(160deg, #F8F9FA 0%, #E8F0FE 100%);
  color: var(--on-surface);
}

.container {
  text-align: center;
  margin: auto;
  padding: 3rem 2rem;
  max-width: 520px;
  width: 100%;
}

.logo {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 2.5rem;
  font-family: 'Google Sans', sans-serif;
  font-size: 20px;
  font-weight: 500;
  color: var(--on-surface);
}
.logo svg { width: 28px; height: 28px; fill: var(--blue-500); }

.error-icon {
  width: 96px;
  height: 96px;
  margin: 0 auto 1.5rem;
  border-radius: 50%;
  background: #E8F0FE;
  display: flex;
  align-items: center;
  justify-content: center;
}
.error-icon svg { width: 48px; height: 48px; fill: var(--blue-500); }

.container h1 {
  font-family: 'Google Sans', sans-serif;
  font-size: 32px;
  font-weight: 500;
  color: var(--on-surface);
}

.container h1 span {
  display: block;
  font-size: 72px;
  font-weight: 700;
  background: linear-gradient(135deg, var(--blue-500), #8AB4F8);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  color: transparent;
}

.container p {
  margin-top: 0.75rem;
  font-size: 14px;
  color: var(--on-surface-med);
}

.back-btn {
  margin-top: 2rem;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 22px;
  background: var(--blue-500);
  color: #fff;
  text-decoration: none;
  border-radius: var(--radius-full);
  font-family: 'Google Sans', sans-serif;
  font-weight: 500;
  font-size: 14px;
  box-shadow: var(--shadow-2);
  transition: background 0.2s ease, box-shadow 0.2s ease;
}
.back-btn:hover { background: var(--blue-600); box-shadow: var(--shadow-3); }
.back-btn svg { width: 16px; height: 16px; fill: #fff; }

.container p.info {
  margin-top: 3rem;
  font-size: 12px;
  color: var(--on-surface-low);
}

.container p.info a {
  text-decoration: none;
  color: var(--blue-500);
}
</style>
</head>

<body>

<div class="container">
  <div class="logo">
    <svg viewBox="0 0 24 24"><path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/></svg>
    <?= htmlspecialchars(appName()) ?>
  </div>

  <div class="error-icon">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
  </div>

  <h1>
    <span>403</span>
    Restricted Page
  </h1>

  <p>Only the superadmin account can access this page.</p>

  <a href="/billuser" class="back-btn">
    <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
    Back to Billuser
  </a>

  <p class="info">
    <?= htmlspecialchars(appName()) ?> &middot; ISP Billing Platform
  </p>
</div>

</body>
</html>
