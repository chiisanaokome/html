<?php
/**
 * attendance.php
 * デザイン：上のコード（画像再現版）
 * ロジック：下のコード（時間割・AJAX版）+ 日付指定判定
 * 修正：プルダウンの表示を「時限 - 授業名」に変更
 */

date_default_timezone_set('Asia/Tokyo');

// ==========================================
// 1. データベース接続情報
// ==========================================
$host = '10.100.56.163';
$port = '5432';
$dbname = 'group3'; 
$user = 'gthree';
$pass = 'Gthree';

$TOTAL_LECTURES = 15;
$PASS_LINE = 0.8;

// 曜日名変換
$day_names = ['', '月', '火', '水', '木', '金', '土', '日'];

// ==========================================
// A. AJAXリクエスト処理（データ取得）
// ==========================================
if (isset($_GET['ajax'])) {
    header("Content-Type: application/json; charset=UTF-8");
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        
        $schedule_id = (int)$_GET['schedule_id'];
        $target_date = $_GET['date'] ?? date('Y-m-d'); // 日付パラメータを取得

        // スケジュール情報の取得
        $schedule = $pdo->prepare("SELECT room_id, period, subject_name, day_of_week, r.name as room_name FROM schedules JOIN rooms r ON schedules.room_id = r.id WHERE schedules.id = ?");
        $schedule->execute([$schedule_id]);
        $sched = $schedule->fetch();

        if (!$sched) {
            echo json_encode(['error' => 'スケジュールが見つかりません']);
            exit;
        }

        // 学生リスト、累計出席数、および「指定日の出席状況」を取得
        // SUM(CASE WHEN al.logged_at::date = :target_date THEN 1 ELSE 0 END) で当日出席を判定
        $sql = "
            SELECT 
                u.user_code, 
                u.name, 
                COUNT(DISTINCT al.logged_at::date) as attended_count,
                SUM(CASE WHEN al.logged_at::date = :target_date THEN 1 ELSE 0 END) as is_present_on_date
            FROM users u
            LEFT JOIN attendance_logs al ON u.id = al.user_id 
                AND al.room_id = :room 
                AND al.period = :period
            WHERE u.role = '学生'
            GROUP BY u.user_code, u.name
            ORDER BY u.user_code ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':room', $sched['room_id'], PDO::PARAM_INT);
        $stmt->bindValue(':period', $sched['period'], PDO::PARAM_INT);
        $stmt->bindValue(':target_date', $target_date); // 日付バインド
        $stmt->execute();
        
        $result = [
            'schedule' => $sched,
            'students' => $stmt->fetchAll(),
            'target_date' => $target_date
        ];
        
        echo json_encode($result);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// B. 初期表示用：時間割リスト取得
// ==========================================
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    
    // 曜日は表示には使いませんが、並び順(ORDER BY)としては引き続き使用します
    $schedule_list = $pdo->query("
        SELECT s.id, s.day_of_week, s.period, s.subject_name, r.name as room_name
        FROM schedules s
        JOIN rooms r ON s.room_id = r.id
        ORDER BY s.day_of_week ASC, s.period ASC
    ")->fetchAll();
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// デフォルト日付（今日）
$default_date = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>単位判定・出席管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* =========================================
           基本スタイル
           ========================================= */
        :root {
            --primary-blue: #007bff;
            --primary-hover: #0056b3;
            --bg-light: #f4f7f6;
            --text-dark: #333;
            --danger-red: #dc3545;
            --success-green: #28a745;
            --btn-gray: #6c757d;
        }

        body {
            font-family: "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 950px;
            margin: 0 auto;
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        /* ヘッダー */
        h2.page-title {
            color: var(--primary-blue);
            font-size: 24px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* 検索フォームエリア */
        .control-panel {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            font-weight: bold;
            color: #666;
            margin-bottom: 5px;
        }

        .form-control {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            height: 38px;
            box-sizing: border-box;
        }

        /* ボタン */
        .btn {
            border: none;
            padding: 0 20px;
            height: 38px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: background 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-sizing: border-box;
        }

        .btn-update {
            background-color: var(--primary-blue);
            color: white;
        }
        .btn-update:hover { background-color: var(--primary-hover); }

        .btn-rollbook {
            background-color: var(--btn-gray);
            color: white;
        }
        .btn-rollbook:hover { background-color: #5a6268; }

        /* 授業情報バナー */
        .class-banner {
            background: linear-gradient(135deg, #6a82fb 0%, #fc5c7d 100%);
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }

        .class-banner h3 {
            margin: 0 0 5px 0;
            font-size: 18px;
        }
        .class-banner p {
            margin: 0;
            font-size: 13px;
            opacity: 0.9;
        }

        /* テーブルスタイル */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th {
            background-color: #f8f9fa;
            color: #6c757d;
            font-weight: bold;
            font-size: 13px;
            padding: 15px;
            border-bottom: 2px solid #dee2e6;
            text-align: center;
            white-space: nowrap;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            text-align: center;
            font-size: 14px;
            vertical-align: middle;
        }

        /* バッジ・文字色 */
        .rate-low { color: var(--danger-red); font-weight: bold; }
        .rate-high { color: var(--text-dark); }

        .badge {
            padding: 5px 12px;
            border-radius: 50px;
            color: white;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-danger { background-color: var(--danger-red); }
        .badge-success { background-color: var(--success-green); }

        /* 当日状況アイコン */
        .status-icon { font-weight: bold; font-size: 16px; }
        .status-ok { color: var(--success-green); }
        .status-ng { color: #ccc; }

        /* ロード中 */
        .loading { opacity: 0.6; pointer-events: none; }
        .fa-spin { animation: fa-spin 2s infinite linear; }

        /* フッターリンク */
        .footer-link {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .footer-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container" id="main-container">
    <h2 class="page-title">
        <i class="fa-regular fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <div class="control-panel">
        
        <div class="form-group">
            <label for="target_date">確認日を選択</label>
            <input type="date" id="target_date" name="target_date" class="form-control" value="<?= htmlspecialchars($default_date) ?>">
        </div>

        <div class="form-group" style="flex-grow: 1;">
            <label for="schedule_id">対象授業を選択</label>
            <select id="schedule_id" name="schedule_id" class="form-control" onchange="updateTable()">
                <option value="">-- 授業を選択してください --</option>
                <?php foreach ($schedule_list as $s): ?>
                    <option value="<?= $s['id'] ?>">
                        <?= $s['period'] ?>限: <?= htmlspecialchars($s['subject_name']) ?> 
                        (<?= htmlspecialchars($s['room_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="button" class="btn btn-update" onclick="updateTable()">
            <i class="fa-solid fa-rotate" id="refresh-icon"></i> 最新状態を反映
        </button>

        <a href="rollbook.php" class="btn btn-rollbook">
            <i class="fas fa-table"></i> 出席簿
        </a>
    </div>

    <div class="class-banner" id="class-banner">
        <h3 id="subject-title">授業名</h3>
        <p id="subject-detail">日時 / 教室</p>
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
        <tbody id="student-table-body">
            <tr>
                <td colspan="6" style="color: #999; padding: 30px;">
                    授業を選択するとデータが表示されます
                </td>
            </tr>
        </tbody>
    </table>

    <div class="footer-link">
        <a href="home.php"><i class="fa-solid fa-arrow-left"></i> システムトップへ戻る</a>
    </div>
</div>

<script>
    // PHPから定数を渡す
    const TOTAL_LECTURES = <?= $TOTAL_LECTURES ?>;
    const PASS_LINE = <?= $PASS_LINE ?>;
    const dayNames = <?= json_encode($day_names) ?>;

    async function updateTable() {
        const scheduleId = document.getElementById('schedule_id').value;
        const targetDate = document.getElementById('target_date').value; // 日付取得

        const banner = document.getElementById('class-banner');
        const tbody = document.getElementById('student-table-body');
        
        // 未選択時
        if (!scheduleId) {
            tbody.innerHTML = '<tr><td colspan="6" style="color: #999; padding: 30px;">授業を選択してください</td></tr>';
            banner.style.display = 'none';
            return;
        }

        // ロード中表示
        const icon = document.getElementById('refresh-icon');
        const container = document.getElementById('main-container');
        icon.classList.add('fa-spin');
        container.classList.add('loading');
        
        try {
            // AJAXリクエスト (日付パラメータを追加)
            const response = await fetch(`attendance.php?ajax=1&schedule_id=${scheduleId}&date=${targetDate}`);
            const data = await response.json();
            
            if (data.error) {
                alert('エラー: ' + data.error);
                return;
            }

            // 1. バナー更新
            const sched = data.schedule;
            document.getElementById('subject-title').textContent = sched.subject_name;
            document.getElementById('subject-detail').textContent = 
                `${dayNames[sched.day_of_week]}曜日 ${sched.period}限 / 教室: ${sched.room_name} / 確認日: ${data.target_date}`;
            banner.style.display = 'block';

            // 2. テーブル更新
            tbody.innerHTML = ''; // クリア

            if (data.students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6">履修学生がいません</td></tr>';
            } else {
                data.students.forEach(s => {
                    const count = parseInt(s.attended_count);
                    const rate = (count > 0) ? (count / TOTAL_LECTURES) * 100 : 0;
                    const isPass = (count / TOTAL_LECTURES) >= PASS_LINE;
                    
                    // 当日出席フラグ (SQLで計算済: 1 or 0)
                    const isPresentToday = parseInt(s.is_present_on_date) > 0;

                    const rowHtml = `
                        <tr>
                            <td>${s.user_code}</td>
                            
                            <td style="font-weight: bold;">${s.name}</td>
                            
                            <td>${count} / ${TOTAL_LECTURES}</td>
                            
                            <td class="${rate < 80 ? 'rate-low' : 'rate-high'}">
                                ${rate.toFixed(1)}%
                            </td>
                            
                            <td>
                                ${isPass 
                                    ? '<span class="badge badge-success">充足</span>' 
                                    : '<span class="badge badge-danger">不足</span>'}
                            </td>

                            <td>
                                ${isPresentToday
                                    ? '<span class="status-icon status-ok">○</span>'
                                    : '<span class="status-icon status-ng">×</span>'}
                            </td>
                        </tr>
                    `;
                    tbody.innerHTML += rowHtml;
                });
            }

        } catch (error) {
            console.error("更新失敗:", error);
            alert('データの取得に失敗しました');
        } finally {
            // ロード終了処理
            setTimeout(() => {
                icon.classList.remove('fa-spin');
                container.classList.remove('loading');
            }, 300);
        }
    }
</script>

</body>
</html>