<?php
date_default_timezone_set('Asia/Tokyo');
$host = '127.0.0.1'; $port = '5432'; $dbname = 'group3'; $user = 'gp3'; $pass = 'gpz';

// --- AJAX処理（データ取得） ---
if (isset($_GET['ajax'])) {
    header("Content-Type: application/json; charset=UTF-8");
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $room = (int)$_GET['room_id'];
        $period = (int)$_GET['period'];
        $dow = (int)$_GET['dow'];

        // 1. その教室・時間・曜日の科目名を取得
        $s_stmt = $pdo->prepare("SELECT subject_name FROM schedules WHERE room_id = :r AND period = :p AND day_of_week = :d");
        $s_stmt->execute([':r' => $room, ':p' => $period, ':d' => $dow]);
        $subject = $s_stmt->fetchColumn();

        // 2. 出席状況を取得
        $stmt = $pdo->prepare("
            SELECT u.user_code, u.name, COUNT(DISTINCT al.logged_at::date) as attended_count
            FROM users u
            LEFT JOIN attendance_logs al ON u.id = al.user_id AND al.room_id = :r AND al.period = :p
            WHERE u.role = '学生'
            GROUP BY u.user_code, u.name ORDER BY u.user_code ASC
        ");
        $stmt->execute([':r' => $room, ':p' => $period]);
        
        echo json_encode(['subject' => $subject ?: null, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
}

// 教室リスト取得
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("DB Error"); }
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --main-blue: #007bff; --pass: #28a745; --fail: #dc3545; --warn: #ffc107; }
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: white; border-radius: 12px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .subject-tag { background: #e9ecef; padding: 8px 15px; border-radius: 5px; font-weight: bold; color: #333; display: inline-block; margin: 10px 0; border-left: 5px solid var(--main-blue); }
        .control-bar { display: flex; gap: 15px; background: #f8f9fa; padding: 15px; border-radius: 8px; align-items: flex-end; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: center; }
        .warning-blink { background: #fff9e6; animation: blink 1s infinite; }
        @keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
        .badge { padding: 5px 10px; border-radius: 15px; color: white; font-size: 0.8em; font-weight: bold; }
        .bg-pass { background: var(--pass); } .bg-fail { background: var(--fail); } .bg-warn { background: var(--warn); color: #333; }
    </style>
</head>
<body>
<div class="container">
    <h2><i class="fas fa-graduation-cap"></i> 単位取得判定モニター</h2>
    <div id="subject-display" class="subject-tag">担当科目: 読込中...</div>

    <div class="control-bar">
        <div>
            <label>教室</label><br>
            <select id="room_id" onchange="updateTable()" style="padding:8px;">
                <?php foreach($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>時限</label><br>
            <select id="period" onchange="updateTable()" style="padding:8px;">
                <?php for($i=1;$i<=4;$i++): ?><option value="<?= $i ?>"><?= $i ?>時限目</option><?php endfor; ?>
            </select>
        </div>
        <button onclick="updateTable()" style="padding:9px 15px; cursor:pointer; background:var(--main-blue); color:white; border:none; border-radius:4px;">更新</button>
    </div>

    <table>
        <thead><tr><th>学籍番号</th><th>氏名</th><th>出席状況</th><th>判定</th></tr></thead>
        <tbody id="student-table-body"></tbody>
    </table>
</div>

<script>
    async function updateTable() {
        const roomId = document.getElementById('room_id').value;
        const period = document.getElementById('period').value;
        
        // 今日の曜日を取得(1:月～5:金)
        let dow = new Date().getDay();
        if (dow === 0 || dow === 6) dow = 1; // 土日はテスト用に月曜表示

        try {
            const res = await fetch(`attendance.php?ajax=1&room_id=${roomId}&period=${period}&dow=${dow}`);
            const data = await res.json();
            
            // 科目名表示。データがない場合は「（なし）」
            document.getElementById('subject-display').innerText = "担当科目: " + (data.subject || "（この時間は授業がありません）");

            const tbody = document.getElementById('student-table-body');
            tbody.innerHTML = '';

            data.students.forEach(s => {
                const count = parseInt(s.attended_count);
                const absent = 15 - count;
                const isPass = count >= 12;
                
                let rowClass = (absent === 3) ? "warning-blink" : "";
                let badge = isPass ? (absent === 3 ? '<span class="badge bg-warn">警告：残り1回</span>' : '<span class="badge bg-pass">合格圏</span>') : '<span class="badge bg-fail">出席不足</span>';

                tbody.innerHTML += `<tr class="${rowClass}">
                    <td>${s.user_code}</td>
                    <td><strong>${s.name}</strong></td>
                    <td>${count} / 15 (欠席${absent}回)</td>
                    <td>${badge}</td>
                </tr>`;
            });
        } catch (e) { console.error(e); }
    }
    window.onload = updateTable;
</script>
</body>
</html>