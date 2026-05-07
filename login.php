<?php
require_once __DIR__ . '/includes/auth.php';

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['session_token'])) {
        logout_user($_COOKIE['session_token']);
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Check if already logged in
$user = get_authenticated_user();
if ($user) {
    $loggedIn = true;
} else {
    $loggedIn = false;
    $error = null;

    // Handle login form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);

        $authenticated_user = authenticate_user($username, $password);

        if ($authenticated_user) {
            create_session($authenticated_user['id'], $remember_me);
            $loggedIn = true;
            $user = $authenticated_user;
        } else {
            $error = "❌ اسم المستخدم أو كلمة المرور غير صحيحة.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <title>صفحة محمية</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(to right, #74ebd5, #9face6);
            margin: 0;
            padding: 0;
            direction: rtl;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-box, .protected-content {
            background-color: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            animation: fadeIn 0.6s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-top: 15px;
            color: #555;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 8px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #5b9bd5;
            outline: none;
        }

        input[type="submit"] {
            width: 100%;
            margin-top: 20px;
            padding: 12px;
            background: linear-gradient(to right, #5b9bd5, #4a6fdc);
            border: none;
            color: white;
            font-size: 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease-in-out;
        }

        input[type="submit"]:hover {
            background: linear-gradient(to right, #4a6fdc, #5b9bd5);
        }

        .error {
            color: #e74c3c;
            margin-top: 15px;
            text-align: center;
            animation: shake 0.3s;
        }

        @keyframes shake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }

        .protected-content p {
            margin-top: 10px;
            font-size: 16px;
            color: #444;
            text-align: center;
        }

        .remember-me {
            margin-top: 15px;
            display: flex;
            align-items: center;
        }

        .remember-me input[type="checkbox"] {
            width: auto;
            margin-left: 8px;
        }
    </style>
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php if ($loggedIn): ?>
    <div class="protected-content">
    <h2> مرحباً بك <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></h2>
    <div style="display:flex;margin: 90px 0;flex-wrap: wrap;">
    <?php if ($user['role'] === 'admin'): ?>
    <a href="pages/pilgrims.php" class="btn btn-primary" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">الحجاج</a>
    <a href="pages/groups.php" class="btn btn-primary" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">المجموعات</a>
    <a href="pages/flights.php" class="btn btn-primary" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">الطيران</a>
    <a href="pages/users.php" class="btn btn-success" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">المستخدمين</a>
    <?php endif; ?>
    <?php if (role_meets_requirement($user['role'] ?? '', 'user')): ?>
    <a href="hotel.php" class="btn btn-info" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">الفنادق</a>
    <a href="room.php" class="btn btn-info" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">الغرف</a>
    <a href="res.php" class="btn btn-info" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">الحجوزات</a>
    <a href="med_hotels.php" class="btn btn-info" style="flex: 1;margin-right: 10px;margin-bottom: 10px;padding: 16px;min-width: 150px;">فنادق المدينة</a>
    <?php endif; ?>
    </div>
    <form method="GET" style="margin-top: 20px; text-align: center;">
        <button type="submit" name="logout" style="
            padding: 10px 20px;
            background: #e74c3c;
            border: none;
            color: white;
            font-size: 15px;
            border-radius: 8px;
            cursor: pointer;
        ">🚪 تسجيل الخروج</button>
    </form>
</div>

<?php else: ?>
    <div class="login-box">
        <h2>🔒 تسجيل الدخول</h2>
        <form method="POST">
            <label for="username">اسم المستخدم:</label>
            <input type="text" name="username" id="username" required>

            <label for="password">كلمة المرور:</label>
            <input type="password" name="password" id="password" required>

            <div class="remember-me">
                <input type="checkbox" name="remember_me" id="remember_me">
                <label for="remember_me" style="margin: 0;">تذكرني (30 يوم)</label>
            </div>

            <input type="submit" value="دخول">
        </form>
        <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
    </div>
<?php endif; ?>
<!-- Bootstrap Bundle JS (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
