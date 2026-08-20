<?php
/**
 * 独立导入工具页面 import.php
 * 功能：提供界面验证密码，并将 CSV 数据导入数据库
 * 特性：设置了长时间超时限制，防止大数据量导入失败
 */

require_once 'db_config.php';

// 设置不限制执行时间
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '1024M');

$message = '';
$status = ''; // success, error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    
    // 验证密码（数据库验证）
    $auth_success = false;
    $stmt = $mysqli->prepare("SELECT password_hash FROM system_users WHERE username = 'admin'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                $auth_success = true;
            }
        }
        $stmt->close();
    }

    if (!$auth_success) {
        $message = "密码错误，无法执行导入。";
        $status = 'error';
    } else {
        $csv_file = '运费统计.csv';
        if (!file_exists($csv_file)) {
            $message = "服务器上找不到“运费统计.csv”文件。";
            $status = 'error';
        } else {
            // 开始导入逻辑
            if (($handle = fopen($csv_file, "r")) !== FALSE) {
                // 读取表头并跳过
                fgetcsv($handle);

                // 准备 SQL
                $sql = "INSERT INTO freight_logs (track_no, carrier, fee, created_at) VALUES (?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);

                if (!$stmt) {
                    $message = "数据库准备失败: " . $mysqli->error;
                    $status = 'error';
                } else {
                    $success_count = 0;
                    $fail_count = 0;
                    $total_rows = 0;
                    
                    // 自动尝试添加 carrier 字段 (兼容旧数据库)
                    $mysqli->query("ALTER TABLE freight_logs ADD COLUMN carrier VARCHAR(50) DEFAULT NULL COMMENT '物流商' AFTER track_no");

                    // 开启事务以提高插入速度
                    $mysqli->begin_transaction();

                    while (($data = fgetcsv($handle)) !== FALSE) {
                        $total_rows++;
                        if (count($data) < 3) {
                            $fail_count++;
                            continue;
                        }

                        $track = trim($data[0]);
                        // 编码处理
                        $encoding = mb_detect_encoding($track, ['UTF-8', 'GBK', 'GB2312']);
                        if ($encoding && $encoding !== 'UTF-8') {
                             $track = mb_convert_encoding($track, 'UTF-8', $encoding);
                        }

                        $fee = floatval($data[1]);
                        $time_str = trim($data[2]);
                        
                        $timestamp = strtotime($time_str);
                        if ($timestamp) {
                            $created_at = date('Y-m-d H:i:s', $timestamp);
                        } else {
                            $created_at = date('Y-m-d H:i:s');
                        }

                        // 自动判断物流商
                        $carrier = '未知';
                        $upperTrack = strtoupper($track);
                        $len = strlen($track);
                        
                        if (ctype_digit($track)) {
                            // 12位或15位纯数字 -> 韵达
                            if ($len == 12 || $len == 15) {
                                $carrier = '韵达';
                            }
                        } elseif ($len == 12 && strpos($upperTrack, 'XF') === 0) {
                            // 12位 XF开头 -> 信丰
                            $carrier = '信丰';
                        } elseif ($len == 15 && strpos($upperTrack, 'SF') === 0) {
                            // 15位 SF开头 -> 顺丰
                            $carrier = '顺丰';
                        }

                        $stmt->bind_param("ssds", $track, $carrier, $fee, $created_at);

                        if ($stmt->execute()) {
                            $success_count++;
                        } else {
                            $fail_count++;
                        }

                        // 每 1000 条提交一次事务，避免内存溢出并提高速度
                        if ($total_rows % 1000 == 0) {
                            $mysqli->commit();
                            $mysqli->begin_transaction();
                        }
                    }

                    // 提交剩余事务
                    $mysqli->commit();

                    fclose($handle);
                    $stmt->close();

                    $message = "导入完成！共处理 $total_rows 条数据。<br>成功: $success_count 条，失败: $fail_count 条。";
                    $status = 'success';
                }
            } else {
                $message = "无法打开 CSV 文件。";
                $status = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据导入工具</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .description {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.5;
            font-size: 14px;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 16px;
            margin-bottom: 20px;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #ff9800;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        button:hover {
            background-color: #f57c00;
        }
        .message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            font-size: 14px;
        }
        .success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            display: none;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #ff9800;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>CSV 数据导入工具</h1>
    
    <div class="description">
        此工具将把服务器上的 <strong>运费统计.csv</strong> 文件内容导入到数据库中。<br>
        如果数据量较大，导入过程可能需要几分钟，请耐心等待，<strong>不要关闭页面</strong>。
    </div>

    <form method="POST" onsubmit="showLoading()">
        <input type="password" name="password" placeholder="请输入管理密码" required>
        <button type="submit">开始导入</button>
    </form>

    <?php if ($message): ?>
        <div class="message <?php echo $status; ?>">
            <?php echo $message; ?>
            <?php if ($status === 'success'): ?>
                <div style="margin-top: 10px; text-align: center;">
                    <a href="report.html" style="color: #2e7d32; font-weight: bold;">前往查询页面</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div style="text-align: center;">
        <div class="spinner" style="margin: 0 auto 15px;"></div>
        <div style="color: #333; font-weight: 500;">正在导入数据，请勿关闭页面...</div>
    </div>
</div>

<script>
    function showLoading() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }
</script>

</body>
</html>
