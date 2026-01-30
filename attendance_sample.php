<?php
/**
 * attendance.php
 * Flatpickr導入・有効日制限版
 */

// 文字化け対策
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ob_start();

date_default_timezone_set('Asia/Tokyo');

// 1. データベース接続情報（お嬢様の環境に合わせて適宜ご変更ください）
$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3'; 
$user = 'gp3';
$pass = 'gpz';

$TOTAL_LECTURES = 15;
$PASS_LINE = 0.8;

// 曜日名変換
$day_names = ['', '月', '火', '水', '木', '金', '土', '日'];

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("SET NAMES 'UTF8'");

    // --- A. AJAXリクエスト（データ取得用） ---
    if (isset($_GET['ajax'])) {
        header("Content-Type: application/json; charset=UTF-8");
        ob_clean(); 
        
        $schedule_id = (int)$_GET['schedule_id'];
        $target_date = $_GET['target_date'] ?? date('Y-m-d');

        // スケジュールから詳細を取得
        $schedule = $pdo->prepare("SELECT room_id, period, subject_name, day_of_week FROM schedules WHERE id = ?");
        $schedule->execute([$schedule_id]);
        $sched = $schedule->fetch();

        if (!$sched) {
            echo json_encode(['error' => 'スケジュールが見つかりません'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 学生ごとの出席データを取得（累計 + 当日のaction列の値）
        $sql = "
            SELECT 
                u.user_code, 
                u.name, 
                COUNT(DISTINCT al.logged_at::date) as attended_count,
                MAX(CASE 
                    WHEN al.logged_at::date = :target_date 
                    THEN al.action 
                    ELSE NULL 
                END) as attendance_status
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
        $stmt->bindValue(':target_date', $target_date, PDO::PARAM_STR);
        $stmt->execute();
        
        echo json_encode([
            'schedule' => $sched,
            'students' => $stmt->fetchAll(),
            'target_date' => $target_date
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- B. 初期表示用の時間割リスト取得 ---
    $schedule_list = $pdo->query("
        SELECT s.id, s.day_of_week, s.period, s.subject_name, r.name as room_name
        FROM schedules s
        JOIN rooms r ON s.room_id = r.id
        ORDER BY s.day_of_week ASC, s.period ASC
    ")->fetchAll();

    // 授業が存在する曜日リストを抽出 (1:月?7:日)
    $valid_days = array_unique(array_column($schedule_list, 'day_of_week'));
    $valid_days_json = json_encode(array_values($valid_days));

} catch (Exception $e) {
    die("エラーが発生いたしました、お嬢様: " . $e->getMessage());
}

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
    
    <style>
        :root { --main-blue: #007bff; --bg-gray: #f4f7f6; --pass-green: #28a745; --fail-red: #dc3545; --warning-orange: #ff9800; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: var(--bg-gray); padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: auto; background: white; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        
        .control-bar { display: flex; gap: 15px; background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; align-items: flex-end; border: 1px solid #eee; }
        .date-input-container { position: relative; min-width: 200px; }
        .styled-input { padding: 10px; border-radius: 6px; border: 1px solid #ccc; font-weight: bold; background: white; width: 100%; box-sizing: border-box; }
        .styled-select { padding: 10px; border-radius: 6px; border: 1px solid #ccc; font-weight: bold; cursor: pointer; background: white; width: 100%; }
        
        .subject-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: none; animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .btn { padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 8px; border: none; transition: 0.2s; }
        .btn-refresh { background: var(--main-blue); color: white; }
        .btn-refresh:hover { background: #0056b3; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: center; }
        th { background: #f8f9fa; color: #666; font-size: 0.9em; text-transform: uppercase; }
        
        .badge { padding: 5px 12px; border-radius: 20px; color: white; font-weight: bold; font-size: 0.8em; }
        .bg-pass { background: var(--pass-green); }
        .bg-fail { background: var(--fail-red); }
        
        .attendance-mark { font-size: 1.4em; font-weight: bold; }
        .attendance-mark.present { color: var(--pass-green); }
        .attendance-mark.absent { color: var(--fail-red); }
        .attendance-mark.none { color: #999; }
        
        .loading { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body>

<div class="container" id="main-container">
    <h2 style="color: var(--main-blue); margin-bottom: 25px;">
        <i class="fas fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <div class="control-bar">
        <div class="date-input-container">
            <label style="font-size: 0.8em; font-weight: bold; display: block; color: #666; margin-bottom: 5px;">確認日を選択</label>
            <input type="text" id="target_date" class="styled-input" placeholder="日付を選択...">
        </div>

        <div style="flex: 1;">
            <label style="font-size: 0.8em; font-weight: bold; display: block; color: #666; margin-bottom: 5px;">対象授業</label>
            <select id="schedule_id" class="styled-select" onchange="updateTable()">
                <option value="">-- 先に日付を選択してください --</option>
            </select>
        </div>

        <button class="btn btn-refresh" onclick="updateTable()">
            <i class="fas fa-sync-alt" id="refresh-icon"></i> 最新状態を反映
        </button>
    </div>

    <div class="subject-info" id="subject-info">
        <h3 id="subject-title" style="margin:0;"></h3>
        <p id="subject-detail" style="margin:5px 0 0 0; opacity:0.9;"></p>
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
            <tr><td colspan="6" style="color: #999; padding: 40px;">カレンダーから日付を選択してくださいませ</td></tr>
        </tbody>
    </table>

    <div style="margin-top:30px; text-align:center;">
        <a href="home.php" style="color:var(--main-blue); text-decoration:none; font-weight:bold;">
            <i class="fas fa-arrow-left"></i> システムトップへ戻る
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ja.js"></script>

<script>
    const TOTAL_LECTURES = <?= $TOTAL_LECTURES ?>;
    const PASS_LINE = <?= $PASS_LINE ?>;
    const dayNames = <?= json_encode($day_names, JSON_UNESCAPED_UNICODE) ?>;
    const allSchedules = <?= json_encode($schedule_list, JSON_UNESCAPED_UNICODE) ?>;
    const validDays = <?= $valid_days_json ?>;

    // Flatpickrの初期化
    document.addEventListener('DOMContentLoaded', function() {
        flatpickr("#target_date", {
            locale: "ja",
            dateFormat: "Y-m-d",
            disableMobile: true,
            defaultDate: "today",
            enable: [
                function(date) {
                    // 1(月)?7(日)に変換してチェック
                    const day = date.getDay();
                    const mappedDay = (day === 0) ? 7 : day;
                    return validDays.includes(mappedDay);
                }
            ],
            onChange: function(selectedDates, dateStr) {
                filterSchedulesByDate(dateStr);
            },
            onReady: function(selectedDates, dateStr) {
                // 初期表示時にもフィルタリングを実行
                if(dateStr) filterSchedulesByDate(dateStr);
            }
        });
    });

    function getDayOfWeek(dateString) {
        const date = new Date(dateString);
        const day = date.getDay();
        return day === 0 ? 7 : day;
    }

    function filterSchedulesByDate(targetDate) {
        const scheduleSelect = document.getElementById('schedule_id');
        const selectedDayOfWeek = getDayOfWeek(targetDate);
        const filteredSchedules = allSchedules.filter(s => s.day_of_week === selectedDayOfWeek);
        
        scheduleSelect.innerHTML = '<option value="">-- 授業を選択してください --</option>';
        
        if (filteredSchedules.length === 0) {
            scheduleSelect.innerHTML = '<option value="">この日は授業がございません</option>';
        } else {
            filteredSchedules.forEach(s => {
                const option = document.createElement('option');
                option.value = s.id;
                option.textContent = `${s.period}限: ${s.subject_name} (${s.room_name})`;
                scheduleSelect.appendChild(option);
            });
        }
        
        // 表示のリセット
        document.getElementById('subject-info').style.display = 'none';
        document.getElementById('student-table-body').innerHTML = 
            '<tr><td colspan="6" style="color: #999; padding: 20px;">授業を選択してください</td></tr>';
    }

    async function updateTable() {
        const scheduleId = document.getElementById('schedule_id').value;
        const targetDate = document.getElementById('target_date').value;
        
        if (!scheduleId) return;

        const icon = document.getElementById('refresh-icon');
        const container = document.getElementById('main-container');
        icon.classList.add('fa-spin');
        container.classList.add('loading');
        
        try {
            const response = await fetch(`attendance.php?ajax=1&schedule_id=${scheduleId}&target_date=${targetDate}`);
            const data = await response.json();
            
            if (data.error) throw new Error(data.error);

            // 授業情報更新
            document.getElementById('subject-title').textContent = data.schedule.subject_name;
            document.getElementById('subject-detail').textContent = 
                `${dayNames[data.schedule.day_of_week]}曜日 ${data.schedule.period}限 / 確認日: ${data.target_date}`;
            document.getElementById('subject-info').style.display = 'block';

            const tbody = document.getElementById('student-table-body');
            tbody.innerHTML = '';

            data.students.forEach(s => {
                const count = parseInt(s.attended_count);
                const rate = (count / TOTAL_LECTURES) * 100;
                const isPass = (count / TOTAL_LECTURES) >= PASS_LINE;
                const status = getAttendanceMark(s.attendance_status);

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${s.user_code}</td>
                    <td><strong>${s.name}</strong></td>
                    <td>${count} / ${TOTAL_LECTURES}</td>
                    <td style="color: ${isPass ? '' : 'red'}; font-weight: bold;">${rate.toFixed(1)}%</td>
                    <td><span class="badge ${isPass ? 'bg-pass' : 'bg-fail'}">${isPass ? '合格圏' : '不足'}</span></td>
                    <td><span class="attendance-mark ${status.class}">${status.text}</span></td>
                `;
                tbody.appendChild(row);
            });
        } catch (error) {
            alert('データの取得に失敗いたしましたわ: ' + error.message);
        } finally {
            setTimeout(() => {
                icon.classList.remove('fa-spin');
                container.classList.remove('loading');
            }, 300);
        }
    }

    // action列の値「出席」「欠席」に対応
    function getAttendanceMark(status) {
        if (!status) return { text: '－', class: 'none' };
        
        switch(status) {
            case '出席':
                return { text: '◯', class: 'present' };
            case '欠席':
                return { text: '×', class: 'absent' };
            default:
                return { text: '－', class: 'none' };
        }
    }
</script>
</body>
</html>
