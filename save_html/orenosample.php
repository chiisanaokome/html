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

// 変数の初期化（GETパラメータから取得、なければデフォルト値）
$target_date = $_GET['date'] ?? date('Y-m-d');
$target_period = $_GET['period'] ?? 4;  // デフォルト4限
$target_room_id = 2;                    // 教室IDは固定と仮定
$total_classes = 15;                    // 全授業数

// 学生データの定義（IPアドレス紐付け）
$students = [
    ['id' => 'T207', 'name' => '田所たろう', 'assigned_ip' => '10.100.56.207', 'past_attendance' => 0],
    ['id' => 'T208', 'name' => '小川おたろう', 'assigned_ip' => '10.100.56.208', 'past_attendance' => 1], // 画像に合わせて初期値を調整
];

$attended_ips = [];
$error_message = "";

try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 検索クエリ実行
    // 指定された「日付」「時限」「教室」で「出席」アクションをしたログを取得
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

    // 結果を配列（IPアドレスのリスト）として取得
    $attended_ips = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error_message = "データベース接続エラー: " . $e->getMessage();
}
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
           基本スタイル (画像のデザインを再現)
           ========================================= */
        :root {
            --primary-blue: #007bff;
            --primary-hover: #0056b3;
            --bg-light: #f4f7f6;
            --text-dark: #333;
            --danger-red: #dc3545;
            --success-green: #28a745;
        }

        body {
            font-family: "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 900px;
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
        }

        .btn-update {
            background-color: var(--primary-blue);
            color: white;
            border: none;
            padding: 9px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
        }

        .btn-update:hover {
            background-color: var(--primary-hover);
        }

        /* 授業情報バナー (紫色の部分) */
        .class-banner {
            background: linear-gradient(135deg, #6a82fb 0%, #fc5c7d 100%); /* 画像に近いグラデーション */
            background: linear-gradient(to right, #667eea, #764ba2); /* より画像に近い紫 */
            color: white;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
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

        .status-icon { font-weight: bold; font-size: 16px; }
        .status-ok { color: var(--success-green); } /* ○ */
        .status-ng { color: #ccc; } /* ×（グレー） */
        .status-ng-red { color: var(--danger-red); } /* ×（赤） */

        /* フッターリンク */
        .footer-link {
            text-align: center;
            margin-top: 30px;
        }
        .footer-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }
        .footer-link a:hover { text-decoration: underline; }

        /* エラー表示 */
        .alert {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
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
            <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($target_date) ?>">
        </div>

        <div class="form-group" style="flex-grow: 1;">
            <label for="period">対象授業</label>
            <select id="period" name="period" class="form-control">
                <option value="4" <?= $target_period == 4 ? 'selected' : '' ?>>4限: 組込みシステム構築課題実習 (0-504)</option>
                <option value="3" <?= $target_period == 3 ? 'selected' : '' ?>>3限: 組込みシステム基礎 (0-504)</option>
            </select>
        </div>

        <button type="submit" class="btn-update">
            <i class="fa-solid fa-rotate"></i> 最新状態を反映
        </button>
    </form>

    <div class="class-banner">
        <h3>組込みシステム構築課題実習</h3>
        <p>金曜日 <?= htmlspecialchars($target_period) ?>限 / 確認日: <?= htmlspecialchars($target_date) ?></p>
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
                // --- 計算ロジック ---
                // 1. IPアドレスがログにあるか確認 (当日出席判定)
                $is_present_today = in_array($student['assigned_ip'], $attended_ips);

                // 2. 累計出席回数 (過去 + 今日)
                $current_count = $student['past_attendance'] + ($is_present_today ? 1 : 0);
                
                // 3. 出席率
                $rate = ($total_classes > 0) ? ($current_count / $total_classes) * 100 : 0;
                
                // 4. 単位判定 (80%以上で充足)
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
                            <span class="status-icon status-ok">○</span>
                        <?php else: ?>
                            <span class="status-icon status-ng">×</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-link">
        <a href="#"><i class="fa-solid fa-arrow-left"></i> システムトップへ戻る</a>
    </div>
</div>

</body>
</html>