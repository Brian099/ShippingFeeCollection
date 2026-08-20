<?php
/**
 * 修改密码接口
 */
require_once 'db_config.php';

header('Content-Type: application/json');
session_start();

// 必须登录才能修改密码
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $current_password = isset($input['current_password']) ? trim($input['current_password']) : '';
    $new_password = isset($input['new_password']) ? trim($input['new_password']) : '';

    if (empty($current_password) || empty($new_password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请输入当前密码和新密码']);
        exit;
    }

    $username = $_SESSION['username'];

    // 1. 验证当前密码
    $stmt = $mysqli->prepare("SELECT password_hash FROM system_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($current_password, $row['password_hash'])) {
            // 2. 更新新密码
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $update_stmt = $mysqli->prepare("UPDATE system_users SET password_hash = ?, updated_at = ? WHERE username = ?");
            $update_stmt->bind_param("sss", $new_hash, $now, $username);
            
            if ($update_stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '密码修改成功']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => '密码更新失败']);
            }
            $update_stmt->close();
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '当前密码错误']);
        }
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '用户不存在']);
    }
    $stmt->close();

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
}
?>
