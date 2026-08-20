<?php
// 检测安装锁文件是否存在
if (!file_exists(__DIR__ . '/install.lock')) {
    // 检查当前请求是否已经是 init.php，避免死循环（虽然 check_install 通常被其他页面引用）
    $current_script = basename($_SERVER['PHP_SELF']);
    if ($current_script !== 'init.php') {
        // 返回 JSON 格式（供 AJAX 使用）或跳转（供直接访问使用）
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'not_installed', 'redirect' => 'init.php']);
            exit;
        } else {
            // 如果是纯 HTML 页面引用此检测脚本，最好通过 JS 变量或 header 跳转
            // 这里我们假设此脚本被 PHP 文件 include，或者作为独立接口调用
            // 如果被 PHP include:
            header("Location: init.php");
            exit;
        }
    }
} else {
    // 已安装
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'installed']);
        exit;
    }
}
?>
