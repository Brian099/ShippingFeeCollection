<?php
/**
 * 系统初始化脚本
 * 用于配置数据库连接并初始化数据库结构
 */

header('Content-Type: text/html; charset=utf-8');

$message = '';
$status = ''; // 'success' or 'error'

// 检测是否已安装
if (file_exists('install.lock')) {
    die("系统已安装。如需重新安装，请删除 install.lock 文件。<br><a href='index.html'>返回首页</a>");
}

// 默认值
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'freight_system';
$port = '3306';
$admin_pw = '1234'; // 默认管理密码

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = isset($_POST['host']) ? trim($_POST['host']) : '';
    $user = isset($_POST['user']) ? trim($_POST['user']) : '';
    $pass = isset($_POST['pass']) ? trim($_POST['pass']) : '';
    $dbname = isset($_POST['dbname']) ? trim($_POST['dbname']) : '';
    $port = isset($_POST['port']) ? intval($_POST['port']) : 3306;
    $admin_pw = isset($_POST['admin_pw']) ? trim($_POST['admin_pw']) : '';

    if (empty($host) || empty($user) || empty($dbname) || empty($admin_pw)) {
        $message = "请填写所有必填项。";
        $status = 'error';
    } else {
        // 1. 尝试连接数据库
        // 这里的 @ 是为了抑制连接失败时的原生 PHP 警告，我们自己处理错误
        $mysqli = @new mysqli($host, $user, $pass, "", $port);

        if ($mysqli->connect_error) {
            $message = "数据库连接失败: " . $mysqli->connect_error;
            $status = 'error';
        } else {
            // 连接成功，开始处理
            $mysqli->set_charset("utf8mb4");

            // 2. 写入配置文件
            $config_content = "<?php\n";
            $config_content .= "// 数据库配置\n";
            $config_content .= "// 由 init.php 自动生成\n";
            $config_content .= "define('DB_HOST', '" . addslashes($host) . "');\n";
            $config_content .= "define('DB_USER', '" . addslashes($user) . "');\n";
            $config_content .= "define('DB_PASS', '" . addslashes($pass) . "');\n";
            $config_content .= "define('DB_NAME', '" . addslashes($dbname) . "');\n";
            $config_content .= "define('DB_PORT', " . intval($port) . ");\n\n";
            
            $config_content .= "// 允许外部脚本跳过自动连接\n";
            $config_content .= "if (!defined('SKIP_DB_CONNECT')) {\n";
            $config_content .= "    // 建立数据库连接\n";
            $config_content .= "    \$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);\n\n";
            $config_content .= "    // 检查连接\n";
            $config_content .= "    if (\$mysqli->connect_error) {\n";
            $config_content .= "        die(\"Connection failed: \" . \$mysqli->connect_error);\n";
            $config_content .= "    }\n\n";
            $config_content .= "    // 设置字符集\n";
            $config_content .= "    \$mysqli->set_charset(\"utf8mb4\");\n";
            $config_content .= "}\n";
            $config_content .= "?>";

            if (file_put_contents('db_config.php', $config_content)) {
                
                // 3. 执行数据库初始化
                $sql_file = 'db_schema.sql';
                if (file_exists($sql_file)) {
                    $sql_content = file_get_contents($sql_file);
                    
                    // 替换 SQL 中的数据库名为用户输入的名称（如果有的话，虽然通常 SQL 里可能是 USE `freight_system`）
                    // 我们的 schema 包含 CREATE DATABASE 和 USE 语句
                    // 如果用户改了数据库名，我们需要动态调整 SQL 或者让用户知道 SQL 里是写死的。
                    // 更好的做法是：在 SQL 里去掉 CREATE DATABASE，或者在这里动态创建。
                    
                    // 为了兼容 db_schema.sql 中的硬编码库名，我们先检查用户输入的库名是否和 SQL 里的一致
                    // 如果不一致，我们尝试在 SQL 执行前创建库，并选定库。
                    
                    // 简单起见，我们先尝试创建用户指定的库
                    $create_db_sql = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                    if ($mysqli->query($create_db_sql)) {
                        $mysqli->select_db($dbname);
                        
                        // 从 SQL 文件中移除 CREATE DATABASE 和 USE 语句，避免冲突
                        // 或者我们假设 db_schema.sql 是标准的，我们直接运行它。
                        // 但是如果 db_schema.sql 里有 `USE freight_system;` 而用户填了 `my_freight`，就会出错。
                        // 所以我们最好只运行建表语句。
                        
                        // 既然是初始化，我们读取 SQL，尝试执行。
                        // 如果 db_schema.sql 是我生成的，我知道它的内容。
                        // 它是：
                        // CREATE DATABASE IF NOT EXISTS freight_system ...
                        // USE freight_system;
                        // CREATE TABLE ...
                        
                        // 如果用户修改了 dbname，我们需要替换 SQL 中的库名
                        if ($dbname !== 'freight_system') {
                             $sql_content = str_replace('freight_system', $dbname, $sql_content);
                        }

                        if ($mysqli->multi_query($sql_content)) {
                             do {
                                if ($result = $mysqli->store_result()) {
                                    $result->free();
                                }
                            } while ($mysqli->next_result());

                            if ($mysqli->errno) {
                                $message = "配置文件写入成功，但数据库初始化部分失败: " . $mysqli->error;
                                $status = 'warning';
                            } else {
                                // 插入管理员账号
                                $hashed_pw = password_hash($admin_pw, PASSWORD_DEFAULT);
                                $now = date('Y-m-d H:i:s');
                                $insert_admin_sql = "INSERT INTO system_users (username, password_hash, created_at, updated_at) VALUES ('admin', ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = ?, updated_at = ?";
                                
                                if ($stmt = $mysqli->prepare($insert_admin_sql)) {
                                    $stmt->bind_param("sssss", $hashed_pw, $now, $now, $hashed_pw, $now);
                                    if ($stmt->execute()) {
                                        // 创建安装锁文件
                                        file_put_contents('install.lock', 'INSTALLED AT ' . date('Y-m-d H:i:s'));
                                        
                                        // 删除旧的 pw 文件（如果存在）
                                        if (file_exists('pw')) {
                                            unlink('pw');
                                        }

                                        $message = "系统初始化成功！配置文件已更新，数据库已建立，管理员账户已创建。";
                                        $status = 'success';
                                    } else {
                                        $message = "数据库初始化成功，但创建管理员账户失败: " . $stmt->error;
                                        $status = 'error';
                                    }
                                    $stmt->close();
                                } else {
                                    $message = "准备创建管理员账户语句失败: " . $mysqli->error;
                                    $status = 'error';
                                }
                            }
                        } else {
                            $message = "执行 SQL 初始化脚本失败: " . $mysqli->error;
                            $status = 'error';
                        }
                    } else {
                        $message = "创建数据库失败: " . $mysqli->error;
                        $status = 'error';
                    }
                    
                } else {
                    $message = "配置文件写入成功，但找不到 db_schema.sql 文件，无法初始化数据库。";
                    $status = 'warning';
                }

            } else {
                $message = "无法写入 db_config.php，请检查文件权限。";
                $status = 'error';
            }
            
            $mysqli->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统初始化安装</title>
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
            margin-bottom: 30px;
            font-size: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }
        input[type="text"], input[type="password"], input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box; /* 确保 padding 不会撑破宽度 */
            font-size: 16px;
        }
        input:focus {
            border-color: #24b324;
            outline: none;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #24b324;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
        }
        button:hover {
            background-color: #1e9a1e;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }
        .alert-error {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
        }
        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
        }
        .alert-warning {
            background-color: #fdf6b2;
            color: #723b13;
            border: 1px solid #fce96a;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }
        .footer a {
            color: #24b324;
            text-decoration: none;
            margin: 0 10px;
        }
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>系统安装向导</h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $status; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($status !== 'success'): ?>
    <form method="POST">
        <div class="form-group">
            <label for="host">数据库地址 (Host)</label>
            <input type="text" id="host" name="host" value="<?php echo htmlspecialchars($host); ?>" required placeholder="例如: localhost">
        </div>
        
        <div class="form-group">
            <label for="port">端口 (Port)</label>
            <input type="number" id="port" name="port" value="<?php echo htmlspecialchars($port); ?>" required placeholder="例如: 3306">
        </div>

        <div class="form-group">
            <label for="user">数据库用户名 (User)</label>
            <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($user); ?>" required placeholder="例如: root">
        </div>

        <div class="form-group">
            <label for="pass">数据库密码 (Password)</label>
            <input type="password" id="pass" name="pass" value="<?php echo htmlspecialchars($pass); ?>" placeholder="数据库密码">
        </div>

        <div class="form-group">
            <label for="dbname">数据库名 (Database Name)</label>
            <input type="text" id="dbname" name="dbname" value="<?php echo htmlspecialchars($dbname); ?>" required placeholder="例如: freight_system">
            <small style="color: #999; display: block; margin-top: 5px;">如果数据库不存在，将自动创建。</small>
        </div>

        <div class="form-group">
            <label for="admin_pw">系统管理密码 (Admin Password)</label>
            <input type="text" id="admin_pw" name="admin_pw" value="<?php echo htmlspecialchars($admin_pw); ?>" required placeholder="设置登录密码">
            <small style="color: #999; display: block; margin-top: 5px;">用于登录系统和查看报表。</small>
        </div>

        <button type="submit">开始安装</button>
    </form>
    <?php else: ?>
        <div style="text-align: center;">
            <p>系统已准备就绪！</p>
            <p style="color: #666; font-size: 14px;">出于安全考虑，请在验证系统功能正常后删除 init.php 文件。</p>
            <div style="margin-top: 30px;">
                <a href="index.html" style="display: inline-block; padding: 10px 20px; background: #24b324; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px;">进入扫描页</a>
                <a href="report.html" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px;">进入统计页</a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
