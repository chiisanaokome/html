<?php
/**
 * attendance.php
 * 時間割(schedules)テーブル対応版
 */

date_default_timezone_set('Asia/Tokyo');

// 1. データベース接続情報
$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3'; 
$user = 'gp3';
$pass = 'gpz';

$TOTAL_LECTURES = 15;
$PASS_LINE = 0.8;

// 曜日名変換
$day_names = ['', '月', '火', '水', '木', '金', '土', '日'];

// --- A. AJAXリクエスト（データ取得用） ---
if (isset($_GET['ajax'])) {
    header("Content-Type: application/json; charset=UTF-8");
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        
        $schedule_id = (int)$_GET['schedule_id'];

        // スケジュールから教室・時限を取得
        $schedule = $pdo->prepare("SELECT room_id, period, subject_name, day_of_week FROM schedules WHERE id = ?");
        $schedule->execute([$schedule_id]);
        $sched = $schedule->fetch();

        if (!$sched) {
            echo json_encode(['error' => 'スケジュールが見つかりません']);
            exit;
        }

        $sql = "
            SELECT u.user_code, u.name, COUNT(DISTINCT al.logged_at::date) as attended_count
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
        $stmt->execute();
        
        $result = [
            'schedule' => $sched,
            'students' => $stmt->fetchAll()
        ];
        
        echo json_encode($result);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// --- B. 時間割リスト取得 ---
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    
    $schedule_list = $pdo->query("
        SELECT s.id, s.day_of_week, s.period, s.subject_name, r.name as room_name
        FROM schedules s
        JOIN rooms r ON s.room_id = r.id
        ORDER BY s.day_of_week ASC, s.period ASC
    ")->fetchAll();
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定管理システム（時間割対応版）</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --main-blue: #007bff; --bg-gray: #f4f7f6; --pass-green: #28a745; --fail-red: #dc3545; --btn-gray: #6c757d; }
        body { font-family: sans-serif; background-color: var(--bg-gray); padding: 20px; }
        .container { max-width: 1100px; margin: auto; background: white; border-radius: 12px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        
        /* 操作エリア */
        .control-bar { display: flex; gap: 15px; background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; align-items: flex-end; border: 1px solid #eee; flex-wrap: wrap; }
        .styled-select { padding: 10px; border-radius: 6px; border: 1px solid #ccc; font-weight: bold; cursor: pointer; background: white; min-width: 300px; }
        
        /* 授業情報表示 */
        .subject-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: none; }
        .subject-info h3 { margin: 0 0 8px 0; font-size: 1.3em; }
        .subject-info p { margin: 5px 0; opacity: 0.9; }
        
        /* ボタン共通設定 */
        .btn { padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 8px; transition: 0.2s; text-decoration: none; border: none; font-size: 0.95em; }
        .btn:active { transform: scale(0.95); }

        /* 更新ボタン */
        .btn-refresh { background: var(--main-blue); color: white; }
        .btn-refresh:hover { background: #0056b3; }

        /* 出席簿ボタン */
        .btn-rollbook { background: var(--btn-gray); color: white; }
        .btn-rollbook:hover { background: #5a6268; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: center; }
        th { background: #f1f3f5; }
        .badge { padding: 5px 12px; border-radius: 20px; color: white; font-weight: bold; font-size: 0.85em; }
        .bg-pass { background: var(--pass-green); }
        .bg-fail { background: var(--fail-red); }
        
        .loading { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body>

<div class="container" id="main-container">
    <h2 style="color: var(--main-blue);"><i class="fas fa-graduation-cap"></i> 単位判定・出席状況一覧（時間割対応版）</h2>

    <div class="control-bar">
        <div style="flex: 1;">
            <label style="font-size: 0.8em; font-weight: bold; display: block; color: #666;">対象授業を選択</label>
            <select id="schedule_id" class="styled-select" onchange="updateTable()">
                <option value="">-- 授業を選択してください --</option>
                <?php foreach ($schedule_list as $s): ?>
                    <option value="<?= $s['id'] ?>">
                        <?= $day_names[$s['day_of_week']] ?>曜<?= $s['period'] ?>限 - 
                        <?= htmlspecialchars($s['subject_name']) ?> 
                        (<?= htmlspecialchars($s['room_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn-refresh" onclick="updateTable()">
            <i class="fas fa-sync-alt" id="refresh-icon"></i> 最新の状態にする
        </button>

        <a href="rollbook.php" class="btn btn-rollbook">
            <i class="fas fa-table"></i> 出席簿を表示
        </a>
    </div>

    <!-- 授業情報表示エリア -->
    <div class="subject-info" id="subject-info">
        <h3 id="subject-title"></h3>
        <p id="subject-detail"></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>学籍番号</th>
                <th>氏名</th>
                <th>出席回数</th>
                <th>出席率</th>
                <th>判定</th>
            </tr>
        </thead>
        <tbody id="student-table-body">
            <tr><td colspan="5" style="color: #999;">授業を選択してください</td></tr>
        </tbody>
    </table>

    <div style="margin-top:20px; text-align:center; border-top: 1px solid #eee; padding-top: 20px;">
        <a href="home.php" style="color:var(--main-blue); text-decoration:none; font-weight:bold;">システムトップへ戻る</a>
    </div>
</div>

<script>
    const TOTAL_LECTURES = <?= $TOTAL_LECTURES ?>;
    const PASS_LINE = <?= $PASS_LINE ?>;
    const dayNames = <?= json_encode($day_names) ?>;

    async function updateTable() {
        const scheduleId = document.getElementById('schedule_id').value;
        
        if (!scheduleId) {
            document.getElementById('student-table-body').innerHTML = 
                '<tr><td colspan="5" style="color: #999;">授業を選択してください</td></tr>';
            document.getElementById('subject-info').style.display = 'none';
            return;
        }

        const icon = document.getElementById('refresh-icon');
        const container = document.getElementById('main-container');

        icon.classList.add('fa-spin');
        container.classList.add('loading');
        
        try {
            const response = await fetch(`attendance.php?ajax=1&schedule_id=${scheduleId}`);
            const data = await response.json();
            
            if (data.error) {
                alert('エラー: ' + data.error);
                return;
            }

            // 授業情報を表示
            const sched = data.schedule;
            document.getElementById('subject-title').textContent = sched.subject_name;
            document.getElementById('subject-detail').textContent = 
                `${dayNames[sched.day_of_week]}曜日 ${sched.period}時限目`;
            document.getElementById('subject-info').style.display = 'block';

            const tbody = document.getElementById('student-table-body');
            tbody.innerHTML = '';

            data.students.forEach(s => {
                const count = parseInt(s.attended_count);
                const rate = (count / TOTAL_LECTURES) * 100;
                const isPass = (count / TOTAL_LECTURES) >= PASS_LINE;

                tbody.innerHTML += `
                    <tr>
                        <td>${s.user_code}</td>
                        <td><strong>${s.name}</strong></td>
                        <td>${count} / ${TOTAL_LECTURES}</td>
                        <td style="color: ${isPass ? '' : 'red'}; font-weight: bold;">
                            ${rate.toFixed(1)} %
                        </td>
                        <td>
                            <span class="badge ${isPass ? 'bg-pass' : 'bg-fail'}">
                                ${isPass ? '◯ 合格圏' : '× 不足'}
                            </span>
                        </td>
                    </tr>
                `;
            });
        } catch (error) {
            console.error("更新失敗:", error);
            alert('データの取得に失敗しました');
        } finally {
            setTimeout(() => {
                icon.classList.remove('fa-spin');
                container.classList.remove('loading');
            }, 300);
        }
    }

    // 初期状態では何も表示しない
    window.onload = function() {
        document.getElementById('subject-info').style.display = 'none';
    };
</script>
</body>
</html>