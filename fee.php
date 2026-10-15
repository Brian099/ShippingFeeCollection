<?php
// 引入数据库配置文件
require_once 'db_config.php';

// 获取 URL 参数
$track = isset($_GET['track']) ? $_GET['track'] : '';
$fee = isset($_GET['fee']) ? $_GET['fee'] : '';

// 检查参数是否存在
if ($track && $fee) {
    
    // 自动尝试添加 carrier 字段 (兼容旧数据库)
    // 注意：在生产环境频繁执行 ALTER 可能有性能问题，但对于此小工具可接受
    // 使用 silent query 避免抛出 duplicate column 异常干扰流程
    $mysqli->query("ALTER TABLE freight_logs ADD COLUMN carrier VARCHAR(50) DEFAULT NULL COMMENT '物流商' AFTER track_no");

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

    // 检查运单是否已存在：已存在则提示并跳过，避免重复写入
    $checkSql = "SELECT id FROM freight_logs WHERE track_no = ? LIMIT 1";
    if ($checkStmt = $mysqli->prepare($checkSql)) {
        $checkStmt->bind_param("s", $track);
        $checkStmt->execute();
        $checkStmt->store_result();
        $exists = $checkStmt->num_rows > 0;
        $checkStmt->close();

        if ($exists) {
            http_response_code(409); // Conflict: 运单已存在
            echo "DUPLICATE_TRACK";
            $mysqli->close();
            exit;
        }
    } else {
        http_response_code(500);
        echo "Database prepare error: " . $mysqli->error;
        $mysqli->close();
        exit;
    }

    // 准备 SQL 语句 (使用预处理语句防止 SQL 注入)
    $sql = "INSERT INTO freight_logs (track_no, carrier, fee, created_at) VALUES (?, ?, ?, NOW())";
    
    if ($stmt = $mysqli->prepare($sql)) {
        // 绑定参数: s = string, s = string, d = double
        $stmt->bind_param("ssd", $track, $carrier, $fee);
        
        // 执行查询
        if ($stmt->execute()) {
            echo "Data received and logged successfully.";
        } else {
            // 记录错误日志或返回错误信息
            // 生产环境建议不要直接输出 SQL 错误给用户，但此处保持原有简单风格便于调试
            http_response_code(500);
            echo "Error logging data: " . $stmt->error;
        }
        
        // 关闭语句
        $stmt->close();
    } else {
        http_response_code(500);
        echo "Database prepare error: " . $mysqli->error;
    }
    
} else {
    http_response_code(400); // Bad Request
    echo "Invalid request. Please provide both 'track' and 'fee' parameters.";
}

// 关闭数据库连接
$mysqli->close();
?>
