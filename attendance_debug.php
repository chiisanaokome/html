<?php
/**
 * attendance.php
 * デバッグ強化版
 */

// 文字化け対策
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ob_start();

date_default_timezone_set('Asia/Tokyo');

$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3'; 
$user = 'gp3';
$pass = 'gpz';

$TOTAL_LECTURES = 15;
$PASS_LINE = 0.8;

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

        // デバッグ用：受け取ったパラメータをログ
        error_log("AJAX Request - schedule_id: $schedule_id, target_date: $target_date");

        $schedule = $pdo->prepare("SELECT room_id, period, subject_name, day_of_week FROM schedules WHERE id = ?");
        $schedule->execute([$schedule_id]);
        $sched = $schedule->fetch();

        if (!$sched) {
            echo json_encode(['error' => 'スケジュールが見つかりません'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // デバッグ用：取得したスケジュール情報をログ
        error_log("Schedule - room_id: {$sched['room_id']}, period: {$sched['period']}");

        $sql = "
            SELECT 
                u.user_code, 
                u.name, 
                COUNT(DISTINCT CASE 
                    WHEN al.action = '出席' 
                    THEN al.logged_at::date 
                    ELSE NULL 
                END) as attended_count,
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
        
        $students = $stmt->fetchAll();
        
        // デバッグ用：取得した学生データをログ
        foreach ($students as $student) {
            error_log("Student: {$student['name']}, attendance_status: '{$student['attendance_status']}'");
        }
        
        $result = [
            'schedule' => $sched,
            'students' => $students,
            'target_date' => $target_date
        ];
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- B. 初期表示用の時間割リスト取得 ---
    $schedule_list = $pdo->query("
        SELECT s.id, s.day_of_week, s.period, s.subject_name, r.name as room_name
        FROM schedules s
        JOIN rooms r ON s.room_id = r.id
        ORDER BY s.day_of_week ASC, s.period ASC
    ")->fetchAll();

    $valid_days = array_unique(array_column($schedule_list, 'day_of_week'));
    $valid_days_json = json_encode(array_values($valid_days));

} catch (Exception $e) {
    die("エラーが発生いたしました: " . $e->getMessage());
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
        :root { --main-blue: #007bff; --bg-gray: #f4f7f6; --pass-green: #28a745; --fail-red: #dc3545; }
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
        th { background: #f8f9fa; color: #666; font-size: 0.9em; }
        
        .badge { padding: 5px 12px; border-radius: 20px; color: white; font-weight: bold; font-size: 0.8em; }
        .bg-pass { background: var(--pass-green); }
        .bg-fail { background: var(--fail-red); }
        
        .attendance-mark { font-size: 1.4em; font-weight: bold; }
        .attendance-mark.present { color: var(--pass-green); }
        .attendance-mark.absent { color: var(--fail-red); }
        .attendance-mark.none { color: #999; }
        
        .loading { opacity: 0.5; pointer-events: none; }
        
        /* デバッグ情報表示エリア */
        .debug-info { background: #fffacd; border: 1px solid #e6d700; padding: 10px; margin-bottom: 20px; font-family: monospace; font-size: 0.9em; display: none; }
    </style>
</head>
<body>

<div class="container" id="main-container">
    <h2 style="color: var(--main-blue); margin-bottom: 25px;">
        <i class="fas fa-calendar-check"></i> 単位判定・出席管理システム
    </h2>

    <!-- デバッグ情報表示エリア -->
    <div class="debug-info" id="debug-info"></div>

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
            <tr><td colspan="6" style="color: #999; padding: 40px;">カレンダーから日付を選択してください</td></tr>
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

    // デバッグモード切り替え（trueで有効）
    const DEBUG_MODE = true;

    function showDebug(message) {
        if (DEBUG_MODE) {
            const debugDiv = document.getElementById('debug-info');
            debugDiv.style.display = 'block';
            debugDiv.innerHTML += message + '<br>';
            console.log(message);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        flatpickr("#target_date", {
            locale: "ja",
            dateFormat: "Y-m-d",
            disableMobile: true,
            defaultDate: "today",
            enable: [
                function(date) {
                    const day = date.getDay();
                    const mappedDay = (day === 0) ? 7 : day;
                    return validDays.includes(mappedDay);
                }
            ],
            onChange: function(selectedDates, dateStr) {
                showDebug(`日付選択: ${dateStr}`);
                filterSchedulesByDate(dateStr);
            },
            onReady: function(selectedDates, dateStr) {
                if(dateStr) {
                    showDebug(`初期日付: ${dateStr}`);
                    filterSchedulesByDate(dateStr);
                }
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
        
        showDebug(`選択曜日: ${selectedDayOfWeek}, 該当授業数: ${filteredSchedules.length}`);
        
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
        
        document.getElementById('subject-info').style.display = 'none';
        document.getElementById('student-table-body').innerHTML = 
            '<tr><td colspan="6" style="color: #999; padding: 20px;">授業を選択してください</td></tr>';
    }

    async function updateTable() {
        const scheduleId = document.getElementById('schedule_id').value;
        const targetDate = document.getElementById('target_date').value;
        
        if (!scheduleId) return;

        showDebug(`データ取得開始 - schedule_id: ${scheduleId}, date: ${targetDate}`);

        const icon = document.getElementById('refresh-icon');
        const container = document.getElementById('main-container');
        icon.classList.add('fa-spin');
        container.classList.add('loading');
        
        try {
            const url = `attendance.php?ajax=1&schedule_id=${scheduleId}&target_date=${targetDate}`;
            showDebug(`リクエストURL: ${url}`);
            
            const response = await fetch(url);
            const data = await response.json();
            
            showDebug(`レスポンス取得: ${JSON.stringify(data)}`);
            
            if (data.error) throw new Error(data.error);

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

                showDebug(`学生: ${s.name}, attendance_status: "${s.attendance_status}", 表示: ${status.text}`);

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
            showDebug(`エラー発生: ${error.message}`);
            alert('データの取得に失敗しました: ' + error.message);
        } finally {
            setTimeout(() => {
                icon.classList.remove('fa-spin');
                container.classList.remove('loading');
            }, 300);
        }
    }

    function getAttendanceMark(status) {
        showDebug(`getAttendanceMark呼び出し - 引数: "${status}", 型: ${typeof status}`);
        
        if (!status || status === null || status === '') {
            return { text: '－', class: 'none' };
        }
        
        // 完全一致で判定
        if (status === '出席') {
            return { text: '◯', class: 'present' };
        } else if (status === '欠席') {
            return { text: '×', class: 'absent' };
        } else {
            showDebug(`想定外の値: "${status}"`);
            return { text: '?', class: 'none' };
        }
    }
</script>
</body>
</html>
