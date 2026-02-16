<?php
// ==========================================
// Bプラン：全科目横断・単位判定一覧（集計特化）
// ==========================================

$host = '10.100.56.163'; $dbname = 'group3'; $user = 'gthree'; $pass = 'Gthree';

$all_students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207'],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208'],
    ['id' => 'T209', 'name' => '近松門左衛門', 'assigned_ip' => '10.100.56.209'],
];

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 全学生 × 全科目の累計出席数を一気に取得
    $sql = "
        SELECT s.subject_name, a.user_id, COUNT(DISTINCT DATE(a.logged_at)) as cnt
        FROM attendance_logs a
        JOIN schedules s ON a.room_id = s.room_id AND a.period = s.period
        WHERE a.action = '出席'
        GROUP BY s.subject_name, a.user_id";
    
    $raw_results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // データの整理 [科目][学生IP] = 回数
    $matrix = [];
    $subjects = [];
    foreach ($raw_results as $row) {
        $matrix[$row['subject_name']][$row['user_id']] = $row['cnt'];
        if (!in_array($row['subject_name'], $subjects)) $subjects[] = $row['subject_name'];
    }
    sort($subjects);

} catch (PDOException $e) { die("エラー: " . $e->getMessage()); }

$total_classes = 15;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定マトリクス(B)</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #fff; }
        h1 { border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: center; }
        th { background: #f2f2f2; font-size: 13px; }
        .student-col { text-align: left; background: #fafafa; font-weight: bold; width: 200px; }
        .danger { background: #fff0f0; color: #d9534f; font-weight: bold; }
        .safe { color: #28a745; }
        .rate { font-size: 11px; display: block; color: #888; }
    </style>
</head>
<body>
    <h1>単位判定マトリクス（全科目一覧）</h1>
    <p>※各セルの数値は「累計出席回数」、（）内は出席率です。80%未満は赤く表示されます。</p>

    <table>
        <thead>
            <tr>
                <th>学生名</th>
                <?php foreach ($subjects as $sub): ?>
                    <th><?= htmlspecialchars($sub) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_students as $st): ?>
                <tr>
                    <td class="student-col"><?= htmlspecialchars($st['name']) ?><br><small><?= $st['assigned_ip'] ?></small></td>
                    <?php foreach ($subjects as $sub): 
                        $count = $matrix[$sub][$st['assigned_ip']] ?? 0;
                        $rate = ($count / $total_classes) * 100;
                        $is_danger = ($rate < 80);
                    ?>
                        <td class="<?= $is_danger ? 'danger' : 'safe' ?>">
                            <?= $count ?> / <?= $total_classes ?>
                            <span class="rate">(<?= number_format($rate, 0) ?>%)</span>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 30px;">
        <a href="attendance.php">← 点呼ページへ戻る</a>
    </div>
</body>
</html>