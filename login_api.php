<?php
/**
 * 登录验证接口
 */
require_once 'db_config.php';

header('Content-Type: application/json');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取 JSON 输入
    $input = json_decode(file_get_contents('php://input'), true);
    $password = isset($input['password']) ? trim($input['password']) : '';
    $action = isset($input['action']) ? $input['action'] : 'login';

    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true, 'message' => '已退出登录']);
        exit;
    }

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请输入密码']);
        exit;
    }

    // 验证密码
    // 目前只支持 admin 用户
    $stmt = $mysqli->prepare("SELECT password_hash FROM system_users WHERE username = 'admin'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                // 登录成功
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = 'admin';
                
                echo json_encode(['success' => true, 'message' => '登录成功']);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '密码错误']);
            }
        } else {
            // 没有找到管理员用户，可能是未初始化或数据丢失
            // 尝试回退到 pw 文件验证（为了兼容性？不，用户要求数据库存储，所以我们严格执行）
            // 如果 system_users 表为空，可能需要重新 install
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '系统错误：找不到管理员账户，请检查数据库。']);
        }
        $stmt->close();
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '数据库查询失败']);
    }

} else {
    // GET 请求检查登录状态
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        echo json_encode(['logged_in' => true, 'username' => $_SESSION['username']]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
}
?>
