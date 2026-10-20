<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - Page Not Found | Unicorn Technoflex ERP</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, Helvetica, sans-serif;
            background: #eef3f8;
            color: #1f2d3d;
            padding: 24px;
        }
        .error-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 16px;
            padding: 42px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(31,45,61,.10);
        }
        .code {
            font-size: 72px;
            line-height: 1;
            font-weight: 800;
            color: #16345f;
            margin-bottom: 14px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 24px;
            color: #16345f;
        }
        p {
            margin: 0 0 24px;
            color: #6b7785;
            line-height: 1.6;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 11px 18px;
            border-radius: 8px;
            background: #16345f;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }
        .btn:hover { opacity: .92; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="code">404</div>
        <h1>Page Not Found</h1>
        <p>The ERP page you requested does not exist or may have been moved.</p>
        <a class="btn" href="/erp/dashboard.php">Back to ERP Dashboard</a>
    </div>
</body>
</html>
