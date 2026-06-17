<?php
// ============================================================
// Payment Success — default landing page after payment
// ============================================================
require_once __DIR__ . '/../config/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Successful</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0fdf4;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
.box{background:#fff;border-radius:16px;padding:2.5rem 2rem;max-width:460px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.icon{font-size:4rem;margin-bottom:1rem}
h1{font-size:1.4rem;color:#166534;font-weight:700;margin-bottom:.6rem}
p{color:#4b5563;font-size:.9rem;line-height:1.55}
.back{display:inline-block;margin-top:1.5rem;padding:.55rem 1.25rem;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-size:.875rem;font-weight:600}
.back:hover{background:#15803d}
</style>
</head>
<body>
<div class="box">
    <div class="icon">🎉</div>
    <h1>Payment Successful!</h1>
    <p>Thank you — your payment has been received and your invoice has been settled.</p>
    <p style="margin-top:.75rem;font-size:.8rem;color:#9ca3af;">You will receive a confirmation shortly.</p>
</div>
</body>
</html>
