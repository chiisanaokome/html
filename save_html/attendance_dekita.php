<?php
// 1. データベース接続設定
$host = '10.100.56.163';
$dbname = 'group3'; // 画像のプロンプトより
$user = 'gthree';
$pass = 'Gthree';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// ==========================================
// 設定・検索条件（画面の入力値に対応）
// ==========================================
$target_date = '2026-01-30'; // 確認日
$target_period = 4;          // 対象授業の時限
$target_room_id = 2;         // 教室ID（科目と紐付くと仮定）
$total_classes = 15;         // 全授業回数

// ==========================================
// 学生マスタとIPアドレスの紐付け定義
// ※本来はstudentsテーブルなどで管理しますが、ここでは配列で定義します
// ==========================================
$students = [
    [
        'id' => 'T207',
        'name' => '田所たろう',
        'assigned_ip' => '10.100.56.207', // このIPなら田所さん
        'past_attendance' => 0 // 過去の出席回数（デモ用）
    ],
    [
        'id' => 'T208',
        'name' => '小川おたろう',
        'assigned_ip' => '10.100.56.208', // このIPなら小川さん
        'past_attendance' => 0 // 過去の出席回数
    ],
];

// ==========================================
// 2. データベースから「当日の出席者」を取得
// ==========================================
// 画像1の user_id, room_id, logged_at, period を使用して検索
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

// 出席したIPアドレスのリストを作成（例: ['10.100.56.208', ...]）
$attended_ips = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>単位判定・出席管理システム</title>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { background-color: #f2f2f2; }
        .badge-danger { background-color: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; }
        .badge-success { background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; }
        .status-ok { color: green; font-weight: bold; }
        .status-ng { color: #ccc; }
        .rate-low { color: red; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h3>組込みシステム構築課題実習</h3>
    <p>日付: <?= htmlspecialchars($target_date) ?> / 時限: <?= $target_period ?></p>

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
                // --- 判定ロジック ---
                
                // A. 当日出席しているか判定
                // DBから取得したIPリストの中に、この学生のIPがあるかチェック
                $is_present_today = in_array($student['assigned_ip'], $attended_ips);

                // B. 出席回数の計算 (過去 + 今日)
                $current_count = $student['past_attendance'] + ($is_present_today ? 1 : 0);
                
                // C. 出席率の計算
                $rate = ($total_classes > 0) ? ($current_count / $total_classes) * 100 : 0;
                
                // D. 単位判定 (例: 80%未満は不足)
                $is_credit_ok = ($rate >= 80);
                ?>

                <tr>
                    <td><?= htmlspecialchars($student['id']) ?></td>
                    
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    
                    <td><?= $current_count ?> / <?= $total_classes ?></td>
                    
                    <td class="<?= $rate < 80 ? 'rate-low' : '' ?>">
                        <?= number_format($rate, 1) ?>%
                    </td>
                    
                    <td>
                        <?php if ($is_credit_ok): ?>
                            <span class="badge-success">充足</span>
                        <?php else: ?>
                            <span class="badge-danger">不足</span>
                        <?php endif; ?>
                    </td>
                    
                    <td>
                        <?php if ($is_present_today): ?>
                            <span class="status-ok">○</span> <?php else: ?>
                            <span class="status-ng">×</span> <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>