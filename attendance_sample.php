<?php
// ==========================================
// 1. 設定・データベース接続・アクセス制限
// ==========================================

// --- 設定 ---
$show_ip_badge = true; 
$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

date_default_timezone_set('Asia/Tokyo');

// 管理者IPの定義
$admin_ips = [
    '10.100.56.7', '10.100.56.8', '10.100.56.13', 
    '10.100.56.14', '10.100.56.20', '10.100.56.50'
];

// 学生データの定義（マスター）
$all_students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207', 'past_attendance' => 0],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208', 'past_attendance' => 0],
    ['id' => 'T209', 'name' => '近松<Xx_a.k.a_xX>門左衛門', 'assigned_ip' => '10.100.56.209', 'past_attendance' => 0],
];

// --- IPチェック ---
$remote_addr = $_SERVER['REMOTE_ADDR'];
$current_ip = gethostbyname($remote_addr); 
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

// --- パラメータ取得 ---
$target_date = $_GET['date'] ?? date('Y-m-d');
$target_schedule_id = $_GET['schedule_id'] ?? null;

$target_period = "-";
$target_room_id = "-";
$target_subject_name = "";
$attended_today = [];
$schedule_list = [];
$total_attendance_data = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 授業リスト取得
    $sql_sch = "SELECT s.id, s.period, s.subject_name, s.room_id, s.day_of_week, r.name as room_name 
                FROM schedules s 
                JOIN rooms r ON s.room_id = r.id 
                ORDER BY s.day_of_week ASC, s.period ASC";
    $stmt_sch = $pdo->query($sql_sch);
    $schedule_list = $stmt_sch->fetchAll(PDO::FETCH_ASSOC);

    if ($target_schedule_id) {
	    $target_schedule = null;
	    foreach ($schedule_list as $sch) {
	        if ($sch['id'] == $target_schedule_id) {
	            $target_schedule = $sch;
	            $target_period = $sch['period'];
	            $target_room_id = $sch['room_id'];
	            $target_subject_name = $sch['subject_name'];
	            break;
	        }
	    }

	    // 確認日を授業の曜日に合わせて自動調整
	    if ($target_schedule) {
	        $selected_day = $target_schedule['day_of_week']; // 1=月曜 ... 7=日曜
	        $current_day = (int)date('N', strtotime($target_date)); // 1=月曜 ... 7=日曜
	        $diff_days = $selected_day - $current_day;
	        $target_date = date('Y-m-d', strtotime("$target_date +$diff_days day"));
	    }

	    // 当日の出席取得
	    $sql_today = "SELECT user_id FROM attendance_logs 
	                  WHERE room_id = :room_id AND period = :period 
	                    AND DATE(logged_at) = :target_date AND action = '出席'";
	    $stmt_today = $pdo->prepare($sql_today);
	    $stmt_today->execute([
	        ':room_id' => $target_room_id,
	        ':period' => $target_period,
	        ':target_date' => $target_date
	    ]);
	    $rows_today = $stmt_today->fetchAll(PDO::FETCH_ASSOC);
	    foreach ($rows_today as $row) {
	        $attended_today[$row['user_id']] = true;
	    }


        // 累計出席（同じsubject_nameの全授業を合算）
        $sql_total = "
	    SELECT al.user_id, COUNT(DISTINCT al.id) AS total_count
	    FROM attendance_logs al
	    JOIN schedules s 
	      ON al.room_id = s.room_id
	      AND al.period = s.period
	    WHERE al.action='出席'
	      AND s.subject_name = :subject_name
	    GROUP BY al.user_id
	";

        $stmt_total = $pdo->prepare($sql_total);
        $stmt_total->execute([':subject_name' => $target_subject_name]);
        $total_attendance_data = $stmt_total->fetchAll(PDO::FETCH_KEY_PAIR);

        // 分母計算：1時限15回 × 該当授業の総時限数
        $sql_count_periods = "SELECT COUNT(*) FROM schedules WHERE subject_name = :subject_name";
        $stmt_count = $pdo->prepare($sql_count_periods);
        $stmt_count->execute([':subject_name' => $target_subject_name]);
        $total_periods = (int)$stmt_count->fetchColumn();
        $total_classes = $total_periods * 15;
    }
} catch (PDOException $e) {
    $error_message = "データベース接続エラー: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>単位判定・出席管理システム</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --header-bg: #eaf6ff;
    --text-dark: #333;
    --text-muted: #888;
    --accent-blue: #007bff;
    --success-green: #28a745;
    --danger-red: #d9534f;
    --warning-yellow: #fff9e6;
    --warning-border: #f0ad4e;
    --card-green: #f0fdf4;
    --btn-gray: #5a6268;
}
body { font-family:"Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; margin:0; padding:0; background:#fff; color:var(--text-dark); }
/* ヘッダー */
.system-header { background-color:var(--header-bg); padding:30px 0; text-align:center; position:relative; }
.btn-top-small { position:absolute; left:20px; top:20px; background:#fff; border:1px solid #ddd; padding:6px 18px; border-radius:20px; text-decoration:none; color:var(--text-dark); font-size:13px; display:flex; align-items:center; gap:5px; }
.header-sub { font-size:14px; color:var(--text-muted); margin-bottom:8px; }
.header-date { font-size:36px; font-weight:bold; letter-spacing:1px; }
/* サマリー */
.summary-bar { display:flex; border-bottom:1px solid #eee; }
.summary-item { flex:1; padding:25px; text-align:center; border-right:1px solid #eee; }
.summary-item:last-child { border-right:none; }
.summary-label { font-size:14px; color:var(--text-muted); margin-bottom:10px; font-weight:bold; }
.summary-value { font-size:28px; font-weight:bold; color:#444; }
.summary-unit { font-size:16px; color:var(--text-muted); margin-left:4px; }
/* 警告 */
.alert-section { background-color:var(--warning-yellow); border-left:6px solid var(--warning-border); margin:30px 40px; padding:20px 25px; border-radius:4px; }
.alert-title { font-weight:bold; color:#856404; margin-bottom:12px; display:flex; align-items:center; gap:10px; }
.alert-list { margin:0; padding-left:20px; color:#856404; font-size:15px; line-height:1.6; }
.main-container { max-width:1000px; margin:0 auto; padding:0 20px; }
/* フォーム */
.filter-form { display:flex; gap:15px; justify-content:center; align-items:flex-end; margin-bottom:30px; background:#f8f9fa; padding:20px; border-radius:8px; }
.field-group { display:flex; flex-direction:column; gap:6px; }
.field-group label { font-size:12px; font-weight:bold; color:var(--text-muted); }
.input-ui { padding:10px; border:1px solid #ccc; border-radius:5px; font-size:14px; }
.btn-refresh { background:var(--accent-blue); color:white; border:none; padding:10px 25px; border-radius:5px; cursor:pointer; font-weight:bold; display:flex; align-items:center; gap:8px; }
/* 出席カード */
.attendance-card { background-color:var(--card-green); border-radius:12px; padding:30px; margin-bottom:40px; box-shadow:0 4px 12px rgba(0,0,0,0.03); }
.card-header { font-size:18px; font-weight:bold; margin-bottom:20px; display:flex; align-items:center; gap:10px; border-bottom:1px solid rgba(0,0,0,0.05); padding-bottom:10px; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th { text-align:left; padding:12px; font-size:13px; color:var(--text-muted); border-bottom:2px solid #fff; }
.data-table td { padding:15px 12px; border-bottom:1px solid rgba(0,0,0,0.05); background: rgba(255,255,255,0.4); }
.badge { padding:5px 15px; border-radius:20px; color:white; font-size:11px; font-weight:bold; }
.bg-ok { background-color:var(--success-green); }
.bg-ng { background-color:var(--danger-red); }
.mark-present { color:var(--accent-blue); font-size:24px; font-weight:bold; }
.mark-absent { color:#aaa; font-size:24px; font-weight:bold; }
/* 下部ボタン */
.footer-nav { display:flex; justify-content:center; gap:20px; margin:40px 0 60px 0; }
.nav-link { display:flex; align-items:center; justify-content:center; gap:12px; width:260px; padding:15px; border-radius:8px; color:white; text-decoration:none; font-weight:bold; font-size:16px; transition: transform 0.1s, opacity 0.2s; }
.nav-link:active { transform:translateY(2px); }
.nav-link:hover { opacity:0.9; }
.nav-env { background-color:var(--btn-gray); }
.nav-home { background-color:var(--accent-blue); }
.ip-badge { position:fixed; bottom:10px; right:10px; background:rgba(0,0,0,0.6); color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; }
.disabled-ui { filter:grayscale(1); pointer-events:none; opacity:0.6; }
</style>
</head>
<body>

<header class="system-header">
    <a href="home.php" class="btn-top-small"><i class="fa-solid fa-house"></i> トップページ</a>
    <div class="header-sub">見えない環境を可視化する 学校スマート管理システム（グループ3）</div>
    <div class="header-date"><?= date('Y/m/d') ?> (<?= ["日","月","火","水","木","金","土"][date('w')] ?>) <?= date('H:i') ?></div>
</header>

<div class="summary-bar">
    <div class="summary-item"><div class="summary-label">対象授業</div><div class="summary-value"><?= $target_subject_name ?: '<span style="color:#ccc">未選択</span>' ?></div></div>
    <div class="summary-item"><div class="summary-label">出席率</div>
        <div class="summary-value">
        <?php 
            if($target_schedule_id && count($all_students) > 0) {
                echo number_format((array_sum($total_attendance_data) / $total_classes) * 100, 1);
            } else { echo "0.0"; }
        ?>%
        </div>
    </div>
    <div class="summary-item"><div class="summary-label">最終更新</div><div class="summary-value" style="font-size:22px;"><?= date('H時i分s秒') ?></div></div>
</div>

<?php if ($is_access_denied || $error_message): ?>
<div class="alert-section">
    <div class="alert-title"><i class="fa-solid fa-triangle-exclamation"></i> 現在の警告</div>
    <ul class="alert-list">
        <?php if ($is_access_denied): ?><li>アクセス制限：IP(<?= htmlspecialchars($current_ip) ?>)からは操作不可</li><?php endif; ?>
        <?php if ($error_message): ?><li>データベース：<?= htmlspecialchars($error_message) ?></li><?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<div class="main-container <?= $is_access_denied ? 'disabled-ui' : '' ?>">

<form method="GET" class="filter-form">
<div class="field-group">
<label>確認日</label>
<input type="date" name="date" class="input-ui" value="<?= htmlspecialchars($target_date) ?>" onchange="this.form.submit();">
</div>
<div class="field-group" style="flex-grow:1;">
<label>対象授業の選択</label>
<select name="schedule_id" class="input-ui" onchange="this.form.submit();">
<option value="">-- 授業を選択してください --</option>
<?php foreach($schedule_list as $sch): ?>
<option value="<?= $sch['id'] ?>" <?= $target_schedule_id==$sch['id']?'selected':'' ?>>
<?= $sch['period'] ?>限: <?= htmlspecialchars($sch['subject_name']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<button type="submit" class="btn-refresh"><i class="fa-solid fa-rotate"></i> 更新</button>
</form>

<?php if($target_schedule_id): ?>
<div class="attendance-card">
<div class="card-header"><i class="fa-solid fa-address-book"></i> 出席者名簿・単位判定状況</div>
<table class="data-table">
<thead><tr>
<th>学籍番号</th><th>氏名</th><th>累計出席数</th><th>出席率</th><th>単位判定</th><th style="text-align:center;">当日の出席</th>
</tr></thead>
<tbody>
<?php foreach($students as $student): 
    $current_count = $total_attendance_data[$student['assigned_ip']] ?? 0;
    $rate = ($total_classes>0)?($current_count/$total_classes*100):0;
    $is_present_today = isset($attended_today[$student['assigned_ip']]);
?>
<tr>
<td><?= htmlspecialchars($student['id']) ?></td>
<td><strong><?= htmlspecialchars($student['name']) ?></strong></td>
<td><?= $current_count ?> / <?= $total_classes ?></td>
<td style="color:<?= $rate<80?'var(--danger-red)':'inherit' ?>; font-weight:bold;"><?= number_format($rate,1) ?>%</td>
<td><span class="badge <?= $rate>=80?'bg-ok':'bg-ng' ?>"><?= $rate>=80?'充足':'不足' ?></span></td>
<td style="text-align:center;"><span class="<?= $is_present_today?'mark-present':'mark-absent' ?>"><?= $is_present_today?'〇':'×' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div style="text-align:center;padding:80px 0;color:#ccc;">
<i class="fa-solid fa-magnifying-glass" style="font-size:40px;margin-bottom:15px;"></i>
<p>授業を選択してください。自動的に集計結果が表示されます。</p>
</div>
<?php endif; ?>

<div class="footer-nav">
<a href="env_monitor.php" class="nav-link nav-env"><i class="fa-solid fa-chart-line"></i> 環境管理画面へ</a>
<a href="home.php" class="nav-link nav-home"><i class="fa-solid fa-house"></i> トップページへ</a>
</div>
</div>

<?php if($show_ip_badge): ?><div class="ip-badge">IP: <?= htmlspecialchars($current_ip) ?></div><?php endif; ?>

</body>
</html>
