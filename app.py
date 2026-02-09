import time
import os
from flask import Flask, request, jsonify

app = Flask(__name__)

# PHPと同じ場所にある tokens.txt を指定
TOKEN_FILE = "./tokens.txt"

@app.route('/attendance', methods=['POST'])
def attendance():
    data = request.json
    # Androidから送られてくるデータの受け取り
    user_token = data.get('token')
    room_id    = data.get('room_id')
    user_id    = data.get('user_id')
    period     = data.get('period')

    # --- 1. tokens.txt の読み込みと検証 ---
    if not os.path.exists(TOKEN_FILE):
        return jsonify({"status": "error", "message": "Server error: No token file"}), 500

    try:
        with open(TOKEN_FILE, "r") as f:
            content = f.read().strip()
            # 形式: "token,expiry" (例: "8f5f,1716167100")
            saved_token, saved_expiry = content.split(",")
            saved_expiry = int(saved_expiry)
    except Exception as e:
        return jsonify({"status": "error", "message": f"File read error: {str(e)}"}), 500

    # --- 2. 比較ロジック ---
    now = int(time.time())

    # トークンの一致確認
    if user_token != saved_token:
        print(f"【拒否】トークン不一致: 受信={user_token}, 正解={saved_token}")
        return jsonify({"status": "invalid", "message": "Invalid Token"}), 403

    # 期限の確認（10秒の猶予）
    if now > (saved_expiry + 10):
        print(f"【拒否】期限切れ: 現在={now}, 期限={saved_expiry}")
        return jsonify({"status": "expired", "message": "Token Expired"}), 403

    # --- 3. 成功 ---
    print(f"【成功】出席を受理しました: User={user_id}, Room={room_id}, Period={period}")
    # 必要ならここでCSVやDBに出席ログを書き込みます
    return jsonify({"status": "ok"}), 200

if __name__ == '__main__':
    # 外部（Android）からアクセスできるように 0.0.0.0 で起動
    app.run(host='0.0.0.0', port=5000)
