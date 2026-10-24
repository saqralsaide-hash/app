<?php
session_start(); // بدء الجلسة لجميع الصفحات

$host = "aws-0-ap-southeast-2.pooler.supabase.com"; // رابط الـ Host من Supabase
$port = "5432";
$dbname = "postgres"; // اسم قاعدة البيانات
$user = "postgres.ceozlbnqokxedrnxrprt"; // اسم المستخدم
$password = "770093782saqr"; // كلمة المرور

try {
    // الاتصال باستخدام PDO لـ PostgreSQL
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}
?>