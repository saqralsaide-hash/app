<?php
require 'config.php'; // تأكد من ربط قاعدة البيانات

// معالجة تسجيل الخروج
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: auth.php");
    exit();
}

date_default_timezone_set('Asia/Riyadh'); // ضبط التوقيت

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// دالة لتسجيل حركات الأمان في الـ Terminal
function addAuditLog($pdo, $message) {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (log_message) VALUES (?)");
    $stmt->execute([$message]);
}

// ---------------- معالجة عمليات الطالب ----------------
if ($role == 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application'])) {
    $stmt = $pdo->prepare("INSERT INTO applications (user_id, first_name, father_name, last_name, id_number, major, high_school_grade, seat_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'منتظر')");
    $stmt->execute([
        $user_id, $_POST['first_name'], $_POST['father_name'], $_POST['last_name'], 
        $_POST['id_number'], $_POST['major'], $_POST['high_school_grade'], $_POST['seat_number']
    ]);
    addAuditLog($pdo, "إضافة طلب جديد: " . $_POST['first_name'] . " " . $_POST['last_name']);
    header("Location: dashboard.php");
    exit();
}

// التحقق مما إذا كان الطالب قد قدم طلباً مسبقاً
$student_app = null;
if ($role == 'student') {
    $stmt = $pdo->prepare("SELECT status FROM applications WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student_app = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---------------- معالجة عمليات المسؤول ----------------
if ($role == 'admin' && isset($_POST['admin_action'])) {
    $action = $_POST['admin_action'];

    if ($action == 'auto_accept') {
        $pdo->query("UPDATE applications SET status = 'مقبول' WHERE high_school_grade >= 80 AND status = 'منتظر'");
        $pdo->query("UPDATE applications SET status = 'مرفوض' WHERE high_school_grade < 80 AND status = 'منتظر'");
        addAuditLog($pdo, "المسؤول نفذ عملية القبول التلقائي (>80%)");
    
    } elseif ($action == 'queue_next') {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM applications WHERE status = 'منتظر' ORDER BY created_at ASC LIMIT 1");
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next) {
            $pdo->prepare("UPDATE applications SET status = 'قيد المقابلة' WHERE id = ?")->execute([$next['id']]);
            addAuditLog($pdo, "سحب للمقابلة (Queue): " . $next['first_name'] . " " . $next['last_name']);
        }
    
    } elseif ($action == 'delete_app') {
        // نقل الطلب لجدول المحذوفات (سلة المحذوفات)
        $app_id = $_POST['app_id'];
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$app_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($app) {
            $insert = $pdo->prepare("INSERT INTO deleted_applications (original_id, user_id, first_name, father_name, last_name, id_number, major, high_school_grade, seat_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$app['id'], $app['user_id'], $app['first_name'], $app['father_name'], $app['last_name'], $app['id_number'], $app['major'], $app['high_school_grade'], $app['seat_number'], $app['status']]);
            
            $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$app_id]);
            addAuditLog($pdo, "نقل للسلة: " . $app['first_name'] . " " . $app['last_name']);
        }
    
    } elseif ($action == 'undo_delete') {
        // استرجاع أحدث طلب محذوف (Stack LIFO)
        $stmt = $pdo->query("SELECT * FROM deleted_applications ORDER BY deleted_at DESC LIMIT 1");
        $last_deleted = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($last_deleted) {
            $insert = $pdo->prepare("INSERT INTO applications (id, user_id, first_name, father_name, last_name, id_number, major, high_school_grade, seat_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$last_deleted['original_id'], $last_deleted['user_id'], $last_deleted['first_name'], $last_deleted['father_name'], $last_deleted['last_name'], $last_deleted['id_number'], $last_deleted['major'], $last_deleted['high_school_grade'], $last_deleted['seat_number'], $last_deleted['status']]);
            
            $pdo->prepare("DELETE FROM deleted_applications WHERE id = ?")->execute([$last_deleted['id']]);
            addAuditLog($pdo, "تراجع عن الحذف (Stack): " . $last_deleted['first_name'] . " " . $last_deleted['last_name']);
        }
    } elseif ($action == 'restore_specific') {
        // استرجاع عنصر محدد من السلة
        $del_id = $_POST['del_id'];
        $stmt = $pdo->prepare("SELECT * FROM deleted_applications WHERE id = ?");
        $stmt->execute([$del_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $insert = $pdo->prepare("INSERT INTO applications (id, user_id, first_name, father_name, last_name, id_number, major, high_school_grade, seat_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$item['original_id'], $item['user_id'], $item['first_name'], $item['father_name'], $item['last_name'], $item['id_number'], $item['major'], $item['high_school_grade'], $item['seat_number'], $item['status']]);
            
            $pdo->prepare("DELETE FROM deleted_applications WHERE id = ?")->execute([$del_id]);
            addAuditLog($pdo, "استرجاع من السلة: " . $item['first_name'] . " " . $item['last_name']);
        }
    } elseif ($action == 'delete_forever') {
        // حذف نهائي من السلة
        $del_id = $_POST['del_id'];
        $stmt = $pdo->prepare("DELETE FROM deleted_applications WHERE id = ?");
        $stmt->execute([$del_id]);
        addAuditLog($pdo, "حذف نهائي من سلة المحذوفات رقم ID: " . $del_id);
    }
    header("Location: dashboard.php");
    exit();
}

// ---------------- جلب بيانات المسؤول ----------------
if ($role == 'admin') {
    $total_apps = $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
    $pending_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'منتظر'")->fetchColumn();
    $accepted_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'مقبول'")->fetchColumn();
    $applications = $pdo->query("SELECT * FROM applications ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $deleted_applications = $pdo->query("SELECT * FROM deleted_applications ORDER BY deleted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $logs = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $role == 'admin' ? 'لوحة السيطرة والمراقبة الأمنية' : 'بوابة الطالب' ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; display: flex; justify-content: center; align-items: <?= $role == 'admin' ? 'flex-start' : 'center' ?>; min-height: 100vh; padding: <?= $role == 'admin' ? '40px 20px' : '0' ?>; }
        .logout-btn { position: absolute; top: 20px; right: 20px; background-color: white; border: 1px solid #dc3545; color: #dc3545; padding: 8px 15px; text-decoration: none; border-radius: 5px; font-size: 14px; }
        .container { background-color: #ffffff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); width: 95%; max-width: 1000px; }
        h2 { color: #28a745; text-align: center; margin-bottom: 30px; font-size: 24px; }
        .grid-form { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 20px; }
        .form-input { padding: 12px 10px; border: 1px solid #dee2e6; border-radius: 5px; text-align: center; font-size: 14px; width: 100%; box-sizing: border-box; outline: none; }
        .submit-btn { background-color: #28a745; color: white; border: none; padding: 12px; border-radius: 5px; width: 100%; font-size: 16px; font-weight: bold; cursor: pointer; }
        
        .admin-container { width: 100%; max-width: 1200px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; margin-bottom: 40px; text-align: center; }
        .stat-card { padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card h3 { margin: 0 0 15px 0; font-size: 18px; color: #555; }
        .stat-card .num { font-size: 24px; font-weight: bold; color: #333; }
        .border-blue { border-right: 5px solid #0d6efd; } .border-orange { border-right: 5px solid #fd7e14; } .border-green { border-right: 5px solid #198754; }
        .action-buttons { display: flex; justify-content: flex-start; gap: 10px; margin-bottom: 20px; }
        .btn-action { padding: 10px 15px; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .btn-auto { background-color: #0d6efd; } .btn-queue { background-color: #6f42c1; } .btn-stack { background-color: #fd7e14; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 40px; text-align: center; }
        th { font-weight: bold; color: #333; padding: 15px; border-bottom: 2px solid #dee2e6; }
        td { padding: 15px; border-bottom: 1px solid #dee2e6; color: #555; }
        .btn-delete { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .btn-restore { background: #198754; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; margin-left: 5px; }
        .btn-forever { background: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .terminal { background-color: #000; color: #28a745; font-family: 'Courier New', monospace; padding: 15px; border-radius: 8px; direction: ltr; text-align: left; font-size: 14px; line-height: 1.6; max-height: 200px; overflow-y: auto; }
        
        .status-msg { padding: 20px; border-radius: 8px; text-align: center; font-size: 18px; font-weight: bold; }
        .msg-wait { background-color: #fff3cd; color: #856404; }
        .msg-accept { background-color: #d4edda; color: #155724; }
        .msg-reject { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

    <a href="?logout=1" class="logout-btn">تسجيل خروج</a>

    <?php if ($role == 'student'): ?>
    <div class="container">
        <?php if (!$student_app): ?>
            <h2>مرحباً بك! سجلت عبر Google</h2>
            <form action="" method="POST">
                <div class="grid-form">
                    <input type="text" name="first_name" class="form-input" placeholder="الاسم الأول" required>
                    <input type="text" name="father_name" class="form-input" placeholder="اسم الأب والجد" required>
                    <input type="text" name="last_name" class="form-input" placeholder="اللقب" required>
                    <input type="text" name="id_number" class="form-input" placeholder="رقم الهوية / الجواز" required>
                    <select name="major" class="form-input" required>
                        <option value="" disabled selected hidden>الأمن السيبراني والشبكات</option>
                        <option value="الأمن السيبراني والشبكات">الأمن السيبراني والشبكات</option>
                        <option value="تقنية المعلومات">تقنية المعلومات (IT)</option>
                        <option value="هندسة البرمجيات">هندسة البرمجيات</option>
                        <option value="نظم المعلومات الحاسوبية">نظم المعلومات الحاسوبية</option>
                    </select>
                    <input type="number" name="high_school_grade" class="form-input" placeholder="معدل الثانوية %" required>
                    <input type="text" name="seat_number" class="form-input" placeholder="رقم الجلوس" required>
                </div>
                <button type="submit" name="submit_application" class="submit-btn">إرسال الطلب للمراجعة</button>
            </form>
        <?php else: ?>
            <h2>حالة طلب الالتحاق</h2>
            <?php if ($student_app['status'] == 'منتظر'): ?>
                <div class="status-msg msg-wait">تم استلام طلبك بنجاح وهو الآن قيد المراجعة في طابور الانتظار.</div>
            <?php elseif ($student_app['status'] == 'مقبول'): ?>
                <div class="status-msg msg-accept">تهانينا! تم قبول طلب التحاقك بالجامعة.</div>
            <?php elseif ($student_app['status'] == 'مرفوض'): ?>
                <div class="status-msg msg-reject">نعتذر، لم يتم قبول طلبك لعدم استيفاء الشروط.</div>
            <?php else: ?>
                <div class="status-msg msg-wait">حالة الطلب: <?= htmlspecialchars($student_app['status']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php elseif ($role == 'admin'): ?>
    <div class="admin-container">
        <h1 style="text-align: center; color: #333;">لوحة السيطرة والمراقبة الأمنية</h1>
        
        <div class="stats-grid">
            <div class="stat-card border-blue">
                <h3>المتقدمين</h3><div class="num"><?= $total_apps ?></div>
            </div>
            <div class="stat-card border-orange">
                <h3>في الطابور</h3><div class="num"><?= $pending_apps ?></div>
            </div>
            <div class="stat-card border-green">
                <h3>المقبولين</h3><div class="num"><?= $accepted_apps ?></div>
            </div>
        </div>

        <div class="action-buttons">
            <form action="" method="POST" style="display:inline;">
                <input type="hidden" name="admin_action" value="auto_accept">
                <button type="submit" class="btn-action btn-auto">قبول تلقائي (>80%)</button>
            </form>
            <form action="" method="POST" style="display:inline;">
                <input type="hidden" name="admin_action" value="queue_next">
                <button type="submit" class="btn-action btn-queue">المقابلة التالية (Queue)</button>
            </form>
            <form action="" method="POST" style="display:inline;">
                <input type="hidden" name="admin_action" value="undo_delete">
                <button type="submit" class="btn-action btn-stack">تراجع عن الحذف (Stack LIFO)</button>
            </form>
        </div>

        <h3 style="color: #333; margin-top: 30px;">طلبات الالتحاق النشطة</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>الاسم الكامل</th>
                    <th>التخصص</th>
                    <th>المعدل</th>
                    <th>الحالة</th>
                    <th>الإدارة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                    <tr><td colspan="6" style="color: #775;">لا توجد طلبات نشطة حالياً.</td></tr>
                <?php else: ?>
                    <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= $app['id'] ?></td>
                        <td><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></td>
                        <td><?= htmlspecialchars($app['major']) ?></td>
                        <td><?= $app['high_school_grade'] ?>%</td>
                        <td style="font-weight:bold; color: <?= $app['status'] == 'مقبول' ? '#198754' : ($app['status'] == 'مرفوض' ? '#dc3545' : '#fd7e14') ?>;">
                            <?= $app['status'] ?>
                        </td>
                        <td>
                            <form action="" method="POST" style="display:inline;">
                                <input type="hidden" name="admin_action" value="delete_app">
                                <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                                <button type="submit" class="btn-delete">نقل للسلة</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- قسم سلة المحذوفات (Recycle Bin) -->
        <h3 style="color: #dc3545; margin-top: 40px;">سلة المحذوفات (Recycle Bin)</h3>
        <table>
            <thead>
                <tr style="background-color: #fff3f3;">
                    <th>ID</th>
                    <th>الاسم الكامل</th>
                    <th>التخصص</th>
                    <th>المعدل</th>
                    <th>حالة الطلب السابقة</th>
                    <th>إدارة السلة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deleted_applications)): ?>
                    <tr><td colspan="6" style="color: #777;">سلة المحذوفات فارغة.</td></tr>
                <?php else: ?>
                    <?php foreach ($deleted_applications as $del): ?>
                    <tr>
                        <td><?= $del['id'] ?></td>
                        <td><?= htmlspecialchars($del['first_name'] . ' ' . $del['last_name']) ?></td>
                        <td><?= htmlspecialchars($del['major']) ?></td>
                        <td><?= $del['high_school_grade'] ?>%</td>
                        <td><?= $del['status'] ?></td>
                        <td>
                            <!-- زر استرجاع الطلب المحدد -->
                            <form action="" method="POST" style="display:inline;">
                                <input type="hidden" name="admin_action" value="restore_specific">
                                <input type="hidden" name="del_id" value="<?= $del['id'] ?>">
                                <button type="submit" class="btn-restore">استرجاع</button>
                            </form>
                            <!-- زر الحذف النهائي للعنصر -->
                            <form action="" method="POST" style="display:inline;">
                                <input type="hidden" name="admin_action" value="delete_forever">
                                <input type="hidden" name="del_id" value="<?= $del['id'] ?>">
                                <button type="submit" class="btn-forever" onclick="return confirm('هل أنت متأكد من الحذف النهائي؟');">حذف نهائي</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="font-weight: bold; margin-bottom: 10px;">سجل الأمان (Security Audit Log):</div>
        <div class="terminal">
            <?php foreach ($logs as $log): 
                $time = date('h:i', strtotime($log['created_at']));
                $am_pm = date('A', strtotime($log['created_at'])) == 'AM' ? 'ص' : 'م';
            ?>
                [<?= $time . ' ' . $am_pm ?>] <?= htmlspecialchars($log['log_message']) ?><br>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>