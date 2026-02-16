<?php
// ==========================================
// Aプラン：科目名連動型・出席管理システム
// ==========================================

$show_ip_badge = true; 
$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

date_default_timezone_set('Asia/Tokyo');

// 管理者IP
$admin_ips = ['10.100.56.7', '10.100.56.8', '10.100.56.13', '10.100.56.14', '10.100.56.20', '10.100.56.50'];

// 学生マスター
$all_students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207'],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208'],
    ['id' => 'T209', 'name' => '近松門左衛門', 'assigned_ip' => '10.100.56.209'],
];

// IPチェック
$current_ip = $_SERVER['REMOTE_ADDR'];
$is_admin = in_array($current_ip, $admin_ips);
$students = $all_students;
$is_student_access = false;
foreach ($all_students as $student) {
    if ($current_ip === $student['assigned_ip']) {
        $students = [$student];
        $is_student_access = true;
        break;
    }
}
$is_access_denied = !($is_admin || $is_student_access);

$target_date = $_GET['date'] ?? date('Y-m-d');
$target_schedule_id = $_GET['schedule_id'] ?? null;

$target_period = "-";
$target_room_id = "-";
$target_subject_name = "";
$attended_ips = [];
$total_attendance_data = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1. 授業リスト取得
    $sql_sch = "SELECT s.id, s.period, s.subject_name, s.room_id, r.name as room_name 
                FROM schedules s JOIN rooms r ON s.room_id = r.id 
                ORDER BY s.day_of_week ASC, s.period ASC";
    $schedule_list = $pdo->query($sql_sch)->fetchAll(PDO::FETCH_ASSOC);

    if ($target_schedule_id) {
        foreach ($schedule_list as $sch) {
            if ($sch['id'] == $target_schedule_id) {
                $target_period = $sch['period'];
                $target_room_id = $sch['room_id'];
                $target_subject_name = $sch['subject_name'];
                break;
            }
        }

        // 2. 【改善ポイント】科目名で過去ログをすべて合算
        $sql_total = "
            SELECT a.user_id, COUNT(DISTINCT DATE(a.logged_at)) AS total_count
            FROM attendance_logs a
            JOIN schedules s ON a.room_id = s.room_id AND a.period = s.period
            WHERE s.subject_name = :subject_name AND a.action = '出席'
            GROUP BY a.user_id";
        $stmt_total = $pdo->prepare($sql_total);
        $stmt_total->execute([':subject_name' => $target_subject_name]);
        $total_attendance_data = $stmt_total->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. 本日の点呼
        $sql_today = "SELECT user_id FROM attendance_logs 
                      WHERE room_id = :room_id AND period = :period AND DATE(logged_at) = :target_date AND action = '出席'";
        $stmt_today = $pdo->prepare($sql_today);
        $stmt_today->execute([':room_id' => $target_room_id, ':period' => $target_period, ':target_date' => $target_date]);
        $attended_ips = $stmt_today->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) { $error_message = $e->getMessage(); }

$total_classes = 15;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定・出席管理(A)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --header-bg: #eaf6ff; --accent-blue: #007bff; --success-green: #28a745; --danger-red: #d9534f; }
        body { font-family: sans-serif; margin: 0; padding: 0; }
        .system-header { background: var(--header-bg); padding: 20px; text-align: center; }
        .summary-bar { display: flex; border-bottom: 1px solid #eee; background: #fff; }
        .summary-item { flex: 1; padding: 15px; text-align: center; border-right: 1px solid #eee; }
        .main-container { max-width: 1000px; margin: 20px auto; padding: 0 20px; }
        .filter-form { display: flex; gap: 10px; background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .attendance-card { background: #f0fdf4; border-radius: 12px; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid rgba(0,0,0,0.05); text-align: left; }
        .badge { padding: 5px 10px; border-radius: 20px; color: #fff; font-size: 11px; }
        .bg-ok { background: var(--success-green); } .bg-ng { background: var(--danger-red); }
        .mark-present { color: var(--accent-blue); font-size: 20px; } .mark-absent { color: #ccc; font-size: 20px; }
    </style>
</head>
<body>
<header class="system-header">
    <div class="header-date"><?= date('Y/m/d') ?> <?= date('H:i') ?> | Aプラン（科目名連動）</div>
</header>

<div class="summary-bar">
    <div class="summary-item">授業：<?= $target_subject_name ?: '未選択' ?></div>
    <div class="summary-item">累計出席対象：全日程（同科目名すべて）</div>
</div>

<div class="main-container">
    <form method="GET" class="filter-form">
        <input type="date" name="date" value="<?= htmlspecialchars($target_date) ?>" onchange="this.form.submit();">
        <select name="schedule_id" style="flex-grow:1" onchange="this.form.submit();">
            <option value="">-- 授業を選択 --</option>
            <?php foreach ($schedule_list as $sch): ?>
                <option value="<?= $sch['id'] ?>" <?= $target_schedule_id == $sch['id'] ? 'selected' : '' ?>>
                    <?= $sch['period'] ?>限: <?= htmlspecialchars($sch['subject_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($target_schedule_id): ?>
        <div class="attendance-card">
            <table>
                <thead>
                    <tr><th>学籍番号</th><th>氏名</th><th>科目累計</th><th>出席率</th><th>判定</th><th>本日</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): 
                        $is_today = in_array($student['assigned_ip'], $attended_ips);
                        $current_count = $total_attendance_data[$student['assigned_ip']] ?? 0;
                        $rate = ($current_count / $total_classes) * 100;
                    ?>
                        <tr>
                            <td><?= $student['id'] ?></td>
                            <td><strong><?= $student['name'] ?></strong></td>
                            <td><?= $current_count ?> / <?= $total_classes ?></td>
                            <td style="color: <?= $rate < 80 ? 'red' : 'inherit' ?>"><?= number_format($rate, 1) ?>%</td>
                            <td><span class="badge <?= $rate >= 80 ? 'bg-ok' : 'bg-ng' ?>"><?= $rate >= 80 ? '充足' : '不足' ?></span></td>
                            <td><span class="<?= $is_today ? 'mark-present' : 'mark-absent' ?>"><?= $is_today ? '●' : '×' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>