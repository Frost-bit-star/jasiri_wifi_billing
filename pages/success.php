<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: text/html; charset=utf-8');
?>

<!DOCTYPE html>
<html lang="sw">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= htmlspecialchars(appName()) ?> - Connected</title>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{
height:100vh;
display:flex;
align-items:center;
justify-content:center;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
background:linear-gradient(135deg,#f0f7ff,#d6ecff);
}


.card{

background:white;
width:90%;
max-width:380px;
padding:40px 30px;
border-radius:24px;
text-align:center;
box-shadow:0 20px 40px rgba(0,0,0,.08);

}


.check{

width:80px;
height:80px;
margin:auto;
border-radius:50%;
background:#16a34a;
display:flex;
align-items:center;
justify-content:center;
color:white;
font-size:45px;
animation:pop .5s ease;

}


h1{

margin-top:20px;
font-size:25px;
color:#111;

}


p{

margin-top:10px;
color:#666;
font-size:14px;

}


.btn{

display:block;
margin-top:25px;
padding:14px;
background:#0066cc;
color:white;
text-decoration:none;
border-radius:14px;
font-weight:600;

}


@keyframes pop{

0%{
transform:scale(.5);
opacity:0;
}

100%{
transform:scale(1);
opacity:1;
}

}

</style>

</head>


<body>


<div class="card">

<div class="check">
✓
</div>


<h1>Umeunganishwa!</h1>


<p>
Vocha yako imekubaliwa.<br>
Sasa unaweza kutumia intaneti ya <?= htmlspecialchars(appName()) ?>.
</p>


<a class="btn" href="https://www.google.com">
Endelea Mtandaoni
</a>


</div>


</body>

</html>
