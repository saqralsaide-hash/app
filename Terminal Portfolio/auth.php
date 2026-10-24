<?php
require 'config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$activeView = 'viewGate';
$errorMsg = '';
$showForgotLink = false;

// قراءة بيانات "تذكرني" لملء الحقول تلقائياً إذا كانت مخزنة
$saved_email = isset($_COOKIE['remember_email']) ? $_COOKIE['remember_email'] : '';
$saved_password = isset($_COOKIE['remember_password']) ? $_COOKIE['remember_password'] : '';
$isChecked = !empty($saved_email) ? 'checked' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. تسجيل دخول الطالب ---
    if (isset($_POST['student_action']) && $_POST['student_action'] === 'login') {
        $activeView = 'viewStudentAuth'; 
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'student'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            
            // إدارة كوكيز "تذكرني" (حفظ البريد وكلمة المرور لتعبئة الحقول لاحقاً)
            if (isset($_POST['remember'])) {
                setcookie('remember_email', $username, time() + (86400 * 30), "/");
                setcookie('remember_password', $password, time() + (86400 * 30), "/");
            } else {
                setcookie('remember_email', '', time() - 3600, "/");
                setcookie('remember_password', '', time() - 3600, "/");
            }

            header("Location: dashboard.php");
            exit();
        } else {
            $errorMsg = "كلمة المرور أو اسم المستخدم غير صحيح.";
            $showForgotLink = true;
        }
    }
    
    // --- 2. إنشاء حساب (الخطوة 1: استقبال البريد من جوجل OAuth) ---
    if (isset($_POST['register_step1'])) {
        $email = trim($_POST['email']);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "حدث خطأ أثناء جلب البيانات من جوجل.";
            $activeView = 'viewRegisterStep1';
        } else {
            $activeView = 'viewRegisterStep2';
        }
    }

    // --- 3. إنشاء حساب (الخطوة 2: كلمة المرور الجديدة الخاصة بالموقع) ---
    if (isset($_POST['register_step2'])) {
        $email = trim($_POST['hidden_email']); 
        $new_pass = $_POST['new_pass'];
        $confirm_pass = $_POST['confirm_pass'];
        
        if ($new_pass !== $confirm_pass) {
            $errorMsg = "كلمات المرور غير متطابقة، حاول مرة أخرى.";
            $activeView = 'viewRegisterStep2';
        } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{8,}$/', $new_pass)) {
            $errorMsg = "كلمة المرور يجب أن تحوي حروف وأرقام ورموز (8 أحرف).";
            $activeView = 'viewRegisterStep2';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $errorMsg = "هذا البريد مسجل مسبقاً، يرجى تسجيل الدخول.";
                $activeView = 'viewStudentAuth';
            } else {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $insert = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                if ($insert->execute([$email, $hashed])) {
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['role'] = 'student';
                    header("Location: dashboard.php");
                    exit();
                }
            }
        }
    }
    
    // --- 4. تسجيل دخول المسؤول ---
    if (isset($_POST['admin_action']) && $_POST['admin_action'] === 'login') {
        $activeView = 'viewAdminLogin'; 
        $user = $_POST['admin_user'];
        $pass = $_POST['admin_pass'];
        
        if ($user === 'admin' && $pass === '123') { 
            $_SESSION['user_id'] = 999;
            $_SESSION['role'] = 'admin';
            header("Location: dashboard.php");
            exit();
        } else {
            $errorMsg = "بيانات المسؤول غير صحيحة!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة الخدمات الجامعية</title>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        :root {
            --admin-color: #1a73e8;
            --student-color: #28a745;
            --bg-color: #f0f2f5;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            display: none; 
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            width: 90%;
            max-width: 800px;
            text-align: center;
        }
        .active-view { display: block; }
        h1, h2, h3 { color: #333; margin-bottom: 10px; }
        p { color: #666; margin-bottom: 25px; }
        
        .gateway { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .gate-card {
            flex: 1; min-width: 250px; padding: 30px; border-radius: 10px;
            cursor: pointer; transition: all 0.3s ease; background: white;
        }
        .student-gate { border: 2px solid var(--student-color); color: var(--student-color); }
        .student-gate:hover { background: var(--student-color); color: white; }
        .student-gate:hover p { color: white; }
        .admin-gate { border: 2px solid var(--admin-color); color: var(--admin-color); }
        .admin-gate:hover { background: var(--admin-color); color: white; }
        .admin-gate:hover p { color: white; }

        .form-container { max-width: 400px; margin: auto; }
        .form-grid {
            display: flex; flex-direction: column; gap: 15px;
            background: #f8f9fa; padding: 20px; border-radius: 10px;
            text-align: right; margin-bottom: 20px;
        }
        .nav-input {
            padding: 12px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 14px; width: 100%; box-sizing: border-box; outline: none;
        }
        .nav-input:focus { border-color: var(--admin-color); }
        
        button {
            cursor: pointer; color: white; font-weight: bold; border: none;
            padding: 12px; border-radius: 8px; font-size: 15px; transition: 0.3s; width: 100%;
        }
        .btn-primary { background: var(--admin-color); }
        .btn-success { background: var(--student-color); }
        .btn-dark { background: #6c757d; }
        button:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .button-group { display: flex; gap: 10px; }
        .button-group button { flex: 1; }
        .forgot-link { color: #dc3545; font-size: 14px; text-decoration: underline; cursor: pointer; display: block; margin-top: 10px;}
    </style>
</head>
<body>

    <!-- الواجهة الرئيسية -->
    <div id="viewGate" class="container <?= $activeView == 'viewGate' ? 'active-view' : '' ?>">
        <h1>بوابة الخدمات الجامعية الذكية</h1>
        <p>اختر البوابة المناسبة للدخول</p>
        <div class="gateway">
            <div class="gate-card student-gate" onclick="goTo('viewStudentAuth')">
                <h2>بوابة الطالب (Student)</h2>
                <p>تسجيل الدخول وتقديم طلبات الالتحاق</p>
            </div>
            <div class="gate-card admin-gate" onclick="goTo('viewAdminLogin')">
                <h2>بوابة المسؤول (Admin)</h2>
                <p>السيطرة الكاملة، الإحصائيات، ومراقبة النظام</p>
            </div>
        </div>
    </div>

    <!-- واجهة الطالب (تسجيل الدخول) -->
    <div id="viewStudentAuth" class="container form-container <?= $activeView == 'viewStudentAuth' ? 'active-view' : '' ?>">
        <h2 style="color: var(--student-color)">تسجيل دخول الطالب</h2>
        <p>قم بتسجيل الدخول للمتابعة</p>
        
        <?php if($activeView == 'viewStudentAuth' && $errorMsg): ?>
            <p style='color:red; font-weight:bold;'><?= $errorMsg ?></p>
            <?php if($showForgotLink): ?>
                <a class="forgot-link" onclick="alert('سيتم إرسال رابط استعادة كلمة المرور إلى بريدك الإلكتروني.')">هل نسيت كلمة المرور؟</a>
            <?php endif; ?>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-grid">
                <!-- تعبئة الحقول تلقائياً من الـ Cookies إذا وُجدت -->
                <input type="email" name="username" placeholder="البريد الإلكتروني" value="<?= htmlspecialchars($saved_email) ?>" required class="nav-input">
                <input type="password" name="password" placeholder="كلمة المرور" value="<?= htmlspecialchars($saved_password) ?>" required class="nav-input">
                <label style="display:flex; align-items:center; gap:10px; color:#333; font-size:14px;">
                    <input type="checkbox" name="remember" class="nav-input" style="width:auto;" <?= $isChecked ?>> تذكرني (حفظ بيانات الدخول)
                </label>
            </div>
            <div class="button-group">
                <button type="submit" name="student_action" value="login" class="btn-primary nav-input">تسجيل دخول</button>
                <button type="button" class="btn-success nav-input" onclick="goTo('viewRegisterStep1')">إنشاء حساب</button>
            </div>
        </form>
        <button class="btn-dark nav-input" style="margin-top: 15px;" onclick="goTo('viewGate')">رجوع للرئيسية</button>
    </div>

    <!-- واجهة إنشاء حساب (الخطوة 1: جوجل OAuth) -->
    <div id="viewRegisterStep1" class="container form-container <?= $activeView == 'viewRegisterStep1' ? 'active-view' : '' ?>">
        <h2 style="color: var(--student-color)">إنشاء حساب جديد</h2>
        <p>الخطوة 1: يرجى التحقق من هويتك عبر حساب Google الخاص بك</p>
        
        <?php if($activeView == 'viewRegisterStep1' && $errorMsg) echo "<p style='color:red; font-weight:bold;'>$errorMsg</p>"; ?>

        <div style="background: #f8f9fa; padding: 30px 20px; border-radius: 10px; margin-bottom: 20px;">
            <div id="g_id_onload"
                 data-client_id="928301065016-bd2d2oddbb6ohb75ad8fjss795qt4jma.apps.googleusercontent.com" 
                 data-callback="handleGoogleLogin">
            </div>
            <div class="g_id_signin" data-type="standard" style="display:flex; justify-content:center;"></div>
        </div>

        <form id="googleAuthForm" method="POST" action="">
            <input type="hidden" name="email" id="google_email" value="">
            <input type="hidden" name="register_step1" value="1">
        </form>

        <button class="btn-dark nav-input" onclick="goTo('viewStudentAuth')">رجوع</button>
    </div>

    <!-- واجهة إنشاء حساب (الخطوة 2: تأمين الحساب) -->
    <div id="viewRegisterStep2" class="container form-container <?= $activeView == 'viewRegisterStep2' ? 'active-view' : '' ?>">
        <h2 style="color: var(--student-color)">تأمين الحساب</h2>
        <p>الخطوة 2: قم بتعيين كلمة مرور جديدة خاصة بالموقع</p>
        
        <?php if($activeView == 'viewRegisterStep2' && $errorMsg) echo "<p style='color:red; font-weight:bold;'>$errorMsg</p>"; ?>

        <form method="POST" action="">
            <input type="hidden" name="hidden_email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($_POST['hidden_email']) ? htmlspecialchars($_POST['hidden_email']) : '') ?>">
            
            <div class="form-grid">
                <input type="password" name="new_pass" placeholder="كلمة المرور الجديدة (حروف+أرقام+رموز)" required class="nav-input">
                <input type="password" name="confirm_pass" placeholder="أعد كتابة كلمة المرور" required class="nav-input">
            </div>
            <button type="submit" name="register_step2" class="btn-primary nav-input">حفظ ودخول للموقع</button>
        </form>
        <button class="btn-dark nav-input" style="margin-top: 15px;" onclick="goTo('viewRegisterStep1')">إلغاء</button>
    </div>

    <!-- واجهة دخول الإدارة -->
    <div id="viewAdminLogin" class="container form-container <?= $activeView == 'viewAdminLogin' ? 'active-view' : '' ?>">
        <h2 style="color: var(--admin-color)">دخول الإدارة المركزية</h2>
        
        <?php if($activeView == 'viewAdminLogin' && $errorMsg) echo "<p style='color:red; font-weight:bold;'>$errorMsg</p>"; ?>
        
        <form method="POST" action="">
            <div class="form-grid">
                <input type="text" name="admin_user" placeholder="اسم المستخدم" class="nav-input" required>
                <input type="password" name="admin_pass" placeholder="كلمة المرور" class="nav-input" required>
            </div>
            <button type="submit" name="admin_action" value="login" class="btn-primary nav-input">دخول للنظام</button>
        </form>
        <button class="btn-dark nav-input" style="margin-top: 15px;" onclick="goTo('viewGate')">إلغاء</button>
    </div>

    <script>
        function handleGoogleLogin(response) {
            const responsePayload = parseJwt(response.credential);
            const userEmail = responsePayload.email;
            document.getElementById('google_email').value = userEmail;
            document.getElementById('googleAuthForm').submit();
        }

        function parseJwt(token) {
            var base64Url = token.split('.')[1];
            var base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            var jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(jsonPayload);
        }

        function goTo(viewId) {
            document.querySelectorAll('.container').forEach(function(el) {
                el.classList.remove('active-view');
            });
            document.getElementById(viewId).classList.add('active-view');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                const activeContainer = document.querySelector('.active-view');
                if (!activeContainer) return;
                
                const inputs = Array.from(activeContainer.querySelectorAll('.nav-input'));
                const activeIndex = inputs.indexOf(document.activeElement);
                
                if (activeIndex > -1) {
                    let nextIndex = event.key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1;
                    if (nextIndex >= inputs.length) nextIndex = 0;
                    if (nextIndex < 0) nextIndex = inputs.length - 1;
                    inputs[nextIndex].focus();
                    event.preventDefault();
                }
            }
        });
    </script>
</body>
</html>