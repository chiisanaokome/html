<?php
// データベース接続設定
$host = '10.100.56.163';
$dbname = 'group3';
$username = 'gthree';
$password = 'Gthree';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // attendance_logsテーブルの全データを取得（最新20件）
    $sql = "SELECT * FROM attendance_logs ORDER BY logged_at DESC LIMIT 20";
    $stmt = $pdo->query($sql);
    $all_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 今日のデータだけを取得
    $today = date('Y-m-d');
    $sql_today = "SELECT * FROM attendance_logs WHERE DATE(logged_at) = :today ORDER BY logged_at DESC";
    $stmt_today = $pdo->prepare($sql_today);
    $stmt_today->bindParam(':today', $today);
    $stmt_today->execute();
    $today_data = $stmt_today->fetchAll(PDO::FETCH_ASSOC);
    
    // 時限4のデータを取得
    $sql_period4 = "SELECT * FROM attendance_logs WHERE period = 4 ORDER BY logged_at DESC LIMIT 10";
    $stmt_period4 = $pdo->query($sql_period4);
    $period4_data = $stmt_period4->fetchAll(PDO::FETCH_ASSOC);
    
    // 出席データのカウント（現在のクエリと同じ条件）
    $room_id = 1;
    $period = 4;
    $sql_count = "SELECT COUNT(DISTINCT user_id) as count 
                  FROM attendance_logs 
                  WHERE room_id = :room_id 
                  AND period = :period 
                  AND DATE(logged_at) = :target_date 
                  AND action = '出席'";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->bindParam(':room_id', $room_id, PDO::PARAM_INT);
    $stmt_count->bindParam(':period', $period, PDO::PARAM_INT);
    $stmt_count->bindParam(':target_date', $today);
    $stmt_count->execute();
    $count_result = $stmt_count->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("データベースエラー: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベース診断ツール</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #e74c3c; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 0.9em; }
        th { background: #3498db; color: white; padding: 10px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background: #f9f9f9; }
        .info-box { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; margin: 20px 0; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .error-box { background: #ffebee; border-left: 4px solid #f44336; padding: 15px; margin: 20px 0; }
        .value { font-weight: bold; color: #2c3e50; }
        pre { background: #263238; color: #aed581; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 attendance_logs テーブル診断ツール</h1>
    
    <div class="info-box">
        <strong>現在の日時:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
        <strong>検索対象日:</strong> <?php echo $today; ?><br>
        <strong>現在の時限:</strong> 4限 (14:45~16:25)<br>
        <strong>全データ件数:</strong> <?php echo count($all_data); ?>件<br>
        <strong>今日のデータ件数:</strong> <?php echo count($today_data); ?>件<br>
        <strong>時限4のデータ件数:</strong> <?php echo count($period4_data); ?>件
    </div>
    
    <div class="warning-box">
        <strong>テストクエリ結果（教室1、時限4、今日、action='出席'）:</strong><br>
        カウント: <span class="value"><?php echo $count_result['count']; ?>名</span>
        <pre>SELECT COUNT(DISTINCT user_id) as count 
FROM attendance_logs 
WHERE room_id = 1 
AND period = 4 
AND DATE(logged_at) = '<?php echo $today; ?>' 
AND action = '出席'</pre>
    </div>

    <h2>📋 最新20件のデータ</h2>
    <?php if (count($all_data) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>user_id</th>
                <th>room_id</th>
                <th>period</th>
                <th>logged_at</th>
                <th>action</th>
                <th>DATE(logged_at)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_data as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['user_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['room_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['period'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['logged_at'] ?? 'N/A'); ?></td>
                <td style="background: <?php echo ($row['action'] ?? '') === '出席' ? '#c8e6c9' : '#ffcdd2'; ?>">
                    "<?php echo htmlspecialchars($row['action'] ?? 'N/A'); ?>" 
                    (<?php echo strlen($row['action'] ?? ''); ?>文字)
                </td>
                <td><?php echo isset($row['logged_at']) ? date('Y-m-d', strtotime($row['logged_at'])) : 'N/A'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="error-box">⚠️ データが1件も見つかりません！</div>
    <?php endif; ?>

    <h2>📅 今日のデータのみ (<?php echo $today; ?>)</h2>
    <?php if (count($today_data) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>user_id</th>
                <th>room_id</th>
                <th>period</th>
                <th>logged_at</th>
                <th>action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($today_data as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['user_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['room_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['period'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['logged_at'] ?? 'N/A'); ?></td>
                <td style="background: <?php echo ($row['action'] ?? '') === '出席' ? '#c8e6c9' : '#ffcdd2'; ?>">
                    "<?php echo htmlspecialchars($row['action'] ?? 'N/A'); ?>"
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="error-box">⚠️ 今日のデータが見つかりません！logged_atの日付を確認してください。</div>
    <?php endif; ?>

    <h2>🎯 時限4のデータのみ</h2>
    <?php if (count($period4_data) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>user_id</th>
                <th>room_id</th>
                <th>period</th>
                <th>logged_at</th>
                <th>action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($period4_data as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['user_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['room_id'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['period'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['logged_at'] ?? 'N/A'); ?></td>
                <td style="background: <?php echo ($row['action'] ?? '') === '出席' ? '#c8e6c9' : '#ffcdd2'; ?>">
                    "<?php echo htmlspecialchars($row['action'] ?? 'N/A'); ?>"
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="error-box">⚠️ 時限4のデータが見つかりません！periodの値を確認してください。</div>
    <?php endif; ?>

    <h2>🔍 チェックポイント</h2>
    <div class="info-box">
        <ol>
            <li><strong>logged_at</strong> が今日の日付（<?php echo $today; ?>）になっているか？</li>
            <li><strong>period</strong> が 4 になっているか？</li>
            <li><strong>action</strong> が正確に「出席」になっているか？（前後のスペースなし）</li>
            <li><strong>room_id</strong> が 1, 2, 3 のいずれかになっているか？</li>
        </ol>
    </div>

    <div style="margin-top: 40px; padding: 20px; background: #f0f0f0; border-radius: 5px;">
        <strong>💡 解決方法:</strong><br>
        上記のテーブルを見て、データが条件に合っていない部分を修正してください。<br>
        特に「action」カラムの値が緑色（'出席'）になっているかを確認してください。
    </div>
</div>

</body>
</html>
