<?php
// ==========================================
// 1. 設定・データベース接続
// ==========================================
$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

date_default_timezone_set('Asia/Tokyo');

$target_date = $_GET['date'] ?? date('Y-m-d');
$target_schedule_id = $_GET['schedule_id'] ?? null;

$target_period = "-";
$target_room_id = "-";
$target_subject_name = "";

// 学生データの定義
$students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207', 'past_attendance' => 0],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208', 'past_attendance' => 0],
    ['id' => 'T209', 'name' => '近松<Xx_a.k.a_xX>門左衛門', 'assigned_ip' => '10.100.56.209', 'past_attendance' => 0],
];

$attended_ips = [];
$schedule_list = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // A. 授業リスト取得
    $sql_sch = "SELECT s.id, s.period, s.subject_name, s.room_id, s.day_of_week, r.name as room_name 
                FROM schedules s
                JOIN rooms r ON s.room_id = r.id
                ORDER BY s.day_of_week ASC, s.period ASC";
    $stmt_sch = $pdo->query($sql_sch);
    $schedule_list = $stmt_sch->fetchAll(PDO::FETCH_ASSOC);

    // B. 選択された授業の情報を特定
    if ($target_schedule_id) {
        foreach ($schedule_list as $sch) {
            if ($sch['id'] == $target_schedule_id) {
                $target_period = $sch['period'];
                $target_room_id = $sch['room_id'];
                $target_subject_name = $sch['subject_name'];
                break;
            }
        }

        // C. 出席データの取得
        $sql = "SELECT user_id FROM attendance_logs 
                WHERE room_id = :room_id 
                AND period = :period 
                AND DATE(logged_at) = :target_date
                AND action = '出席'";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':room_id' => $target_room_id,
            ':period' => $target_period,
            ':target_date' => $target_date
        ]);
        $attended_ips = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    $error_message = "データベース接続エラー: " . $e->getMessage();
}

$total_classes = 15;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定・出席管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-blue: #007bff; --primary-hover: #0056b3; --bg-light: #f4f7f6; --text-dark: #333; --danger-red: #dc3545; --success-green: #28a745; }
        body { font-family: sans-serif; background-color: var(--bg-light); color: var(--text-dark); margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        h2.page-title { color: var(--primary-blue); font-size: 24px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .control-panel { background-color: #f8f9fa; padding: 20px; border-radius: 6px; border: 1px solid #e9ecef; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: bold; color: #666; margin-bottom: 5px; }
        .form-control { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .btn-update { background-color: var(--primary-blue); color: white; border: none; padding: 9px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .class-banner { background: linear-gradient(to right, #667eea, #764ba2); color: white; padding: 15px 20px; border-radius: 6px; margin-bottom: 20px; }
        .class-banner h3 { margin: 0 0 5px 0; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #f8f9fa; padding: 15px; border-bottom: 2px solid #dee2e6; }
        td { padding: 15px; border-bottom: 1px solid #dee2e6; text-align: center; }
        .badge { padding: 5px 12px; border-radius: 50px; color: white; font-size: 11px; font-weight: bold; }
        .badge-success { background-color: var(--success-green); }
        .badge-danger { background-color: var(--danger-red); }
        .status-ok { color: var(--success-green); font-size: 2.5em; font-weight: bold; }
        .status-ng { color: #ccc; font-size: 2.5em; font-weight: bold; }
        .no-selection { text-align: center; padding: 50px; color: #999; }
    </style>
</head>
<body>

<div class="container">
    <h2 class="page-title">
        <i class="fa-regular fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <form method="GET" action="" class="control-panel">
        <div class="form-group">
            <label for="date">確認日を選択</label>
            <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($target_date) ?>" onchange="filterScheduleByDate(); this.form.submit();">
        </div>

        <div class="form-group" style="flex-grow: 1;">
            <label for="schedule_id">対象授業</label>
            <select id="schedule_id" name="schedule_id" class="form-control" onchange="this.form.submit()">
                <option value="" data-day="all">-- 授業を選択してください --</option>
                <?php foreach ($schedule_list as $sch): ?>
                    <option value="<?= $sch['id'] ?>" data-day="<?= $sch['day_of_week'] ?>" <?= $target_schedule_id == $sch['id'] ? 'selected' : '' ?>>
                        <?= $sch['period'] ?>限: <?= htmlspecialchars($sch['subject_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($target_schedule_id): ?>
        <div class="class-banner">
            <h3><?= htmlspecialchars($target_subject_name) ?></h3>
            <p><?= htmlspecialchars($target_period) ?>限 / 教室ID: <?= htmlspecialchars($target_room_id) ?> / 確認日: <?= htmlspecialchars($target_date) ?></p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>学籍番号</th>
                    <th>氏名</th>
                    <th>出席回数</th>
                    <th>出席率</th>
                    <th>単位判定</th>
                    <th>当日状況</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                    <?php
                    $is_present_today = in_array($student['assigned_ip'], $attended_ips);
                    $current_count = $student['past_attendance'] + ($is_present_today ? 1 : 0);
                    $rate = ($total_classes > 0) ? ($current_count / $total_classes) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($student['id']) ?></td>
                        <td><strong><?= htmlspecialchars($student['name']) ?></strong></td>
                        <td><?= $current_count ?> / <?= $total_classes ?></td>
                        <td style="color: <?= $rate < 80 ? 'red' : 'inherit' ?>; font-weight: <?= $rate < 80 ? 'bold' : 'normal' ?>;">
                            <?= number_format($rate, 1) ?>%
                        </td>
                        <td>
                            <span class="badge <?= $rate >= 80 ? 'badge-success' : 'badge-danger' ?>">
                                <?= $rate >= 80 ? '充足' : '不足' ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?= $is_present_today ? 'status-ok' : 'status-ng' ?>">
                                <?= $is_present_today ? '○' : '×' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-selection">
            
        </div>
    <?php endif; ?>

    <div class="footer-link" style="text-align: center; margin-top: 30px;">
        <a href="home.php" style="text-decoration: none; color: var(--primary-blue); font-weight: bold;">
            <i class="fa-solid fa-arrow-left"></i> システムトップへ戻る
        </a>
    </div>
</div>

<script>
function filterScheduleByDate() {
    const dateInput = document.getElementById('date');
    const select = document.getElementById('schedule_id');
    const options = select.querySelectorAll('option');
    if (!dateInput.value) return;
    const dateObj = new Date(dateInput.value);
    const jsDay = dateObj.getDay(); 
    let hasVisible = false;

    options.forEach(opt => {
        if (opt.getAttribute('data-day') === 'all') return;
        const dbDay = parseInt(opt.getAttribute('data-day'));
        let isMatch = (jsDay === 0) ? (dbDay === 7 || dbDay === 0) : (dbDay === jsDay);
        
        if (isMatch) {
            opt.style.display = "";
            opt.disabled = false;
            hasVisible = true;
        } else {
            opt.style.display = "none";
            opt.disabled = true;
        }
    });

    const currentSelected = select.options[select.selectedIndex];
    if (currentSelected && (currentSelected.style.display === "none" || currentSelected.disabled)) {
        select.value = ""; 
    }
}
window.addEventListener('load', filterScheduleByDate);
</script>

</body>
</html>