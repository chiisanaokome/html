<?php
// ==========================================
// 1. 設定・データベース接続
// ==========================================
$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// 変数の初期化
$target_date = $_GET['date'] ?? date('Y-m-d');
$target_schedule_id = $_GET['schedule_id'] ?? null;

// デフォルト値
$target_period = 4;
$target_room_id = 2;
$target_subject_name = "";

// 学生データの定義（テスト用ダミーデータ）
$students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207', 'past_attendance' => 0],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208', 'past_attendance' => 1],
];

$attended_ips = [];
$schedule_list = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // --------------------------------------------------
    // A. プルダウン用に時間割リストを取得
    // --------------------------------------------------
    // 【変更点】 day_of_week (曜日) を追加で取得します
    $sql_sch = "SELECT s.id, s.period, s.subject_name, s.room_id, s.day_of_week, r.name as room_name 
                FROM schedules s
                JOIN rooms r ON s.room_id = r.id
                ORDER BY s.day_of_week ASC, s.period ASC";
    $stmt_sch = $pdo->query($sql_sch);
    $schedule_list = $stmt_sch->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // B. 選択された授業IDから情報を特定
    // --------------------------------------------------
    if ($target_schedule_id) {
        foreach ($schedule_list as $sch) {
            if ($sch['id'] == $target_schedule_id) {
                $target_period = $sch['period'];
                $target_room_id = $sch['room_id'];
                $target_subject_name = $sch['subject_name'];
                break;
            }
        }
    } else {
        // 未選択時はリスト先頭をデフォルトにするなどの処理
        // (JSでの絞り込み後に選択が変わる可能性があるため、ここでは仮設定)
        if (!empty($schedule_list)) {
             // 実際にはJSで制御しますが、PHP側でも一応セット
             $first = $schedule_list[0];
             $target_schedule_id = $first['id'];
             $target_subject_name = $first['subject_name'];
             $target_period = $first['period'];
             $target_room_id = $first['room_id'];
        }
    }

    // --------------------------------------------------
    // C. 出席データの取得
    // --------------------------------------------------
    // ※実際の運用では IPアドレス判定用のカラムを取得してください
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

} catch (PDOException $e) {
    $error_message = "データベース接続エラー: " . $e->getMessage();
}

$total_classes = 15;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>単位判定・出席管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* CSSスタイル（変更なし） */
        :root { --primary-blue: #007bff; --primary-hover: #0056b3; --bg-light: #f4f7f6; --text-dark: #333; --danger-red: #dc3545; --success-green: #28a745; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; background-color: var(--bg-light); color: var(--text-dark); margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        h2.page-title { color: var(--primary-blue); font-size: 24px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .control-panel { background-color: #f8f9fa; padding: 20px; border-radius: 6px; border: 1px solid #e9ecef; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: bold; color: #666; margin-bottom: 5px; }
        .form-control { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .btn-update { background-color: var(--primary-blue); color: white; border: none; padding: 9px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; transition: background 0.2s; }
        .btn-update:hover { background-color: var(--primary-hover); }
        .class-banner { background: linear-gradient(to right, #667eea, #764ba2); color: white; padding: 15px 20px; border-radius: 6px; margin-bottom: 20px; }
        .class-banner h3 { margin: 0 0 5px 0; font-size: 18px; }
        .class-banner p { margin: 0; font-size: 13px; opacity: 0.9; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 13px; padding: 15px; border-bottom: 2px solid #dee2e6; text-align: center; }
        td { padding: 15px; border-bottom: 1px solid #dee2e6; text-align: center; font-size: 14px; vertical-align: middle; }
        .rate-low { color: var(--danger-red); font-weight: bold; }
        .rate-high { color: var(--text-dark); }
        .badge { padding: 5px 12px; border-radius: 50px; color: white; font-size: 11px; font-weight: bold; }
        .badge-success { background-color: var(--success-green); }
        .badge-danger { background-color: var(--danger-red); }
        .status-icon { font-weight: bold; font-size: 16px; }
        .status-ok { color: var(--success-green); }
        .status-ng { color: #ccc; }
        .alert { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .footer-link { text-align: center; margin-top: 30px; }
        .footer-link a { color: var(--primary-blue); text-decoration: none; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <h2 class="page-title">
        <i class="fa-regular fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <?php if ($error_message): ?>
        <div class="alert"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <form method="GET" action="" class="control-panel">
        <div class="form-group">
            <label for="date">確認日を選択</label>
            <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($target_date) ?>" onchange="filterScheduleByDate()">
        </div>

        <div class="form-group" style="flex-grow: 1;">
            <label for="schedule_id">対象授業</label>
            <select id="schedule_id" name="schedule_id" class="form-control">
                <option value="" data-day="all">-- 日付に対応する授業を選択 --</option>
                
                <?php foreach ($schedule_list as $sch): ?>
                    <option value="<?= $sch['id'] ?>" 
                            data-day="<?= $sch['day_of_week'] ?>"
                            <?= $target_schedule_id == $sch['id'] ? 'selected' : '' ?>>
                        <?= $sch['period'] ?>限: <?= htmlspecialchars($sch['subject_name']) ?> (<?= htmlspecialchars($sch['room_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-update">
            <i class="fa-solid fa-rotate" id="refresh-icon"></i> 最新状態を反映
        </button>
    </form>

    <div class="class-banner">
        <h3><?= htmlspecialchars($target_subject_name ?: '授業が選択されていません') ?></h3>
        <p>時限: <?= htmlspecialchars($target_period) ?>限 / 教室ID: <?= htmlspecialchars($target_room_id) ?> / 確認日: <?= htmlspecialchars($target_date) ?></p>
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
                $is_credit_ok = ($rate >= 80);
                ?>
                <tr>
                    <td><?= htmlspecialchars($student['id']) ?></td>
                    <td style="font-weight: bold;"><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= $current_count ?> / <?= $total_classes ?></td>
                    <td class="<?= $rate < 80 ? 'rate-low' : 'rate-high' ?>">
                        <?= number_format($rate, 1) ?>%
                    </td>
                    <td>
                        <?php if ($is_credit_ok): ?>
                            <span class="badge badge-success">充足</span>
                        <?php else: ?>
                            <span class="badge badge-danger">不足</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_present_today): ?>
                            <span class="status-icon status-ok" style="font-size: 2.5em;">○</span>
                        <?php else: ?>
                            <span class="status-icon status-ng" style="font-size: 2.5em;">×</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-link">
        <a href="home.php"><i class="fa-solid fa-arrow-left"></i> システムトップへ戻る</a>
    </div>
</div>

<script>
/**
 * 日付から曜日を取得し、プルダウンを絞り込むスクリプト
 */
function filterScheduleByDate() {
    const dateInput = document.getElementById('date');
    const select = document.getElementById('schedule_id');
    const options = select.querySelectorAll('option');

    // 日付が入力されていない場合は処理しない
    if (!dateInput.value) return;

    // 日付オブジェクトを作成
    const dateObj = new Date(dateInput.value);
    
    // 曜日を取得 (0:日, 1:月, 2:火 ... 6:土)
    const jsDay = dateObj.getDay();

    let hasVisible = false;

    // オプションを走査して表示・非表示を切り替え
    options.forEach(opt => {
        // "all"指定（初期文言）は常にチェック対象外だが、一旦表示しておく
        if (opt.getAttribute('data-day') === 'all') return;

        // DBの曜日値を取得 (1~7想定)
        const dbDay = parseInt(opt.getAttribute('data-day'));

        // マッチング判定
        // JSの0(日)とDBの曜日定義(7 or 0)を合わせる
        // ここでは DB: 1=月...6=土, 7=日 と仮定してマッチング
        let isMatch = false;
        if (jsDay === 0) {
            // 日曜の場合
            isMatch = (dbDay === 7 || dbDay === 0);
        } else {
            // 月～土
            isMatch = (dbDay === jsDay);
        }

        if (isMatch) {
            opt.style.display = ""; // 表示
            opt.disabled = false;
            hasVisible = true;
        } else {
            opt.style.display = "none"; // 非表示
            opt.disabled = true; // 選択不可（Safari等対策）
        }
    });

    // 選択中のオプションが非表示になった場合、選択を解除する
    const currentSelected = select.options[select.selectedIndex];
    if (currentSelected && (currentSelected.style.display === "none" || currentSelected.disabled)) {
        select.value = ""; // 選択解除
    }

    // デフォルト文言の切り替え
    const defaultOpt = select.querySelector('option[data-day="all"]');
    if (hasVisible) {
        defaultOpt.text = "-- 授業を選択してください --";
        defaultOpt.style.display = "";
    } else {
        defaultOpt.text = "-- この曜日の授業はありません --";
        defaultOpt.selected = true;
    }
}

// ページ読み込み時にも実行（リロード時などに現在の曜日に合わせるため）
window.addEventListener('load', filterScheduleByDate);
</script>

</body>
</html>