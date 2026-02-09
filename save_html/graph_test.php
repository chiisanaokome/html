<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>折れ線グラフのサンプル</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <div style="width: 80%; margin: auto;">
        <canvas id="myLineChart"></canvas>
    </div>

    <script>
        // HTMLのcanvas要素を取得
        const ctx = document.getElementById('myLineChart').getContext('2d');

        // グラフの設定
        new Chart(ctx, {
            type: 'line', // グラフの種類：折れ線
            data: {
                // 横軸のラベル（時間）
                labels: ['10:00', '11:00', '12:00', '13:00', '14:00', '15:00'], 
                datasets: [{
                    label: 'データセットA', // 凡例のラベル
                    data: [12, 19, 3, 5, 2, 30], // 縦軸のデータ
                    borderColor: 'rgb(75, 192, 192)', // 線の色
                    backgroundColor: 'rgba(75, 192, 192, 0.2)', // 塗りつぶしの色（必要な場合）
                    tension: 0.1, // 線の滑らかさ（0だと直線、数値を上げると曲線）
                    fill: false // 線の下を塗りつぶさない
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: '時間の経過と推移' // グラフ全体のタイトル
                    }
                },
                scales: {
                    // 横軸（X軸）の設定
                    x: {
                        title: {
                            display: true,
                            text: '時間' // 横軸のラベル
                        }
                    },
                    // 縦軸（Y軸）の設定
                    y: {
                        title: {
                            display: true,
                            text: '項目１' // 縦軸のラベル
                        },
                        beginAtZero: true // 0からスタートさせるか
                    }
                }
            }
        });
    </script>
</body>
</html>