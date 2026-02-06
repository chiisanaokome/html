<?php
// ==========================================
// 1. 設定・データベース接続・アクセス制限
// ==========================================

// --- 追加機能: IP表示バッジの切り替え (true: 表示 / false: 非表示) ---
$show_ip_badge = true; 

$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

date_default_timezone_set('Asia/Tokyo');

// 管理者IPの定義
$admin_ips = [
    '10.100.56.7',
    '10.100.56.8',
    '10.100.56.13',
    '10.100.56.14',
    '10.100.56.20'
];

// 学生データの定義（マスター）
$all_students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207', 'past_attendance' => 0],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208', 'past_attendance' => 0],
    ['id' => 'T209', 'name' => '近松<Xx_a.k.a_xX>門左衛門', 'assigned_ip' => '10.100.56.209', 'past_attendance' => 0],
];

// --- IPチェックロジック ---
$remote_addr = $_SERVER['REMOTE_ADDR'];
$current_ip = gethostbyname($remote_addr); 

// 管理者判定
$is_admin = in_array($current_ip, $admin_ips);

// 特定学生の個別アクセス判定
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

// ------------------------------------------

$target_date = $_GET['date'] ?? date('Y-m-d');
$target_schedule_id = $_GET['schedule_id'] ?? null;

$target_period = "-";
$target_room_id = "-";
$target_subject_name = "";

$attended_ips = [];
$schedule_list = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $sql_sch = "SELECT s.id, s.period, s.subject_name, s.room_id, s.day_of_week, r.name as room_name 
                FROM schedules s
                JOIN rooms r ON s.room_id = r.id
                ORDER BY s.day_of_week ASC, s.period ASC";
    $stmt_sch = $pdo->query($sql_sch);
    $schedule_list = $stmt_sch->fetchAll(PDO::FETCH_ASSOC);

    if ($target_schedule_id) {
        foreach ($schedule_list as $sch) {
            if ($sch['id'] == $target_schedule_id) {
                $target_period = $sch['period'];
                $target_room_id = $sch['room_id'];
                $target_subject_name = $sch['subject_name'];
                break;
            }
        }

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
        body { font-family: sans-serif; background-color: var(--bg-light); color: var(--text-dark); margin: 0; padding: 0; position: relative; }
        
        .ip-indicator {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            z-index: 10000;
        }
        .ip-indicator span { font-weight: bold; margin-left: 5px; }
        .ip-status-ok { color: var(--primary-blue); }
        .ip-status-ng { color: var(--danger-red); }

        .alert-banner {
            background-color: var(--danger-red);
            color: white;
            text-align: center;
            padding: 15px;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .alert-banner a { color: white; text-decoration: underline; margin-left: 15px; font-size: 0.9em; }

        .access-restricted { opacity: 0.5; pointer-events: none; user-select: none; filter: grayscale(50%); }

        .container { max-width: 900px; margin: 40px auto 20px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        h2.page-title { color: var(--primary-blue); font-size: 24px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        
        .control-panel { background-color: #f8f9fa; padding: 20px; border-radius: 6px; border: 1px solid #e9ecef; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: bold; color: #666; margin-bottom: 5px; }
        .form-control { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; height: 38px; box-sizing: border-box; }
        
        .refresh-btn {
            background-color: var(--primary-blue);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 120px;
        }
        .refresh-btn:hover { background-color: var(--primary-hover); }
        .refresh-btn:active { transform: translateY(1px); }

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

<?php if ($show_ip_badge): ?>
<div class="ip-indicator">
    Your IP: <span class="<?= ($is_admin || $is_student_access) ? 'ip-status-ok' : 'ip-status-ng' ?>"><?= htmlspecialchars($current_ip) ?></span>
</div>
<?php endif; ?>

<?php if ($is_access_denied): ?>
    <div class="alert-banner">
        <i class="fa-solid fa-circle-exclamation"></i> 
        このIP (<?= htmlspecialchars($current_ip) ?>) からはアクセスできません。
        <a href="home.php">システムトップへ戻る</a>
    </div>
<?php endif; ?>

<div class="container <?= $is_access_denied ? 'access-restricted' : '' ?>">
    <h2 class="page-title">
        <i class="fa-regular fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <?php if ($error_message): ?>
        <div style="color: red; margin-bottom: 20px;"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <form id="attendanceForm" method="GET" action="" class="control-panel">
        <div class="form-group">
            <label for="date">確認日を選択</label>
            <input type="date" id="date" name="date" class="form-control" 
                   value="<?= htmlspecialchars($target_date) ?>" 
                   onchange="filterScheduleByDate(); this.form.submit();">
        </div>

        <div class="form-group" style="flex-grow: 1;">
            <label for="schedule_id">対象授業</label>
            <select id="schedule_id" name="schedule_id" class="form-control" onchange="this.form.submit();">
                <option value="" data-day="all">-- 授業を選択してください --</option>
                <?php foreach ($schedule_list as $sch): ?>
                    <option value="<?= $sch['id'] ?>" data-day="<?= $sch['day_of_week'] ?>" <?= $target_schedule_id == $sch['id'] ? 'selected' : '' ?>>
                        <?= $sch['period'] ?>限: <?= htmlspecialchars($sch['subject_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit" class="form-control refresh-btn">
                <i class="fa-solid fa-rotate"></i> 状況を更新
            </button>
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
                <?php foreach ($students as $student): 
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
            <i class="fa-solid fa-arrow-up"></i><br>授業を選択すると自動で出席状況が表示されます
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
    
    options.forEach(opt => {
        if (opt.getAttribute('data-day') === 'all') return;
        const dbDay = parseInt(opt.getAttribute('data-day'));
        let isMatch = (jsDay === 0) ? (dbDay === 7) : (dbDay === jsDay);
        
        if (isMatch) {
            opt.style.display = "";
            opt.disabled = false;
        } else {
            opt.style.display = "none";
            opt.disabled = true;
            // 非表示にする曜日の授業が選択されていたらリセット
            if (opt.selected) select.value = "";
        }
    });
}
// 読み込み時にも曜日フィルタを実行（リロード後の整合性のため）
window.addEventListener('load', filterScheduleByDate);
</script>

</body>
</html>