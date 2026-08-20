<?php
require_once 'db_config.php';

header('Content-Type: application/json');

session_start();

// 安全验证：检查 Session
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 获取筛选参数
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$track_no = isset($_GET['track_no']) ? $_GET['track_no'] : '';

// 构建基础查询条件
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($start_date) {
    $where_clauses[] = "created_at >= ?";
    $params[] = $start_date . " 00:00:00";
    $types .= "s";
}

if ($end_date) {
    $where_clauses[] = "created_at <= ?";
    $params[] = $end_date . " 23:59:59";
    $types .= "s";
}

if ($track_no) {
    $where_clauses[] = "track_no LIKE ?";
    $params[] = "%" . $track_no . "%";
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// 1. 获取统计信息
$sql_stats = "SELECT COUNT(*) as total_count, SUM(fee) as total_fee FROM freight_logs WHERE $where_sql";
$stmt = $mysqli->prepare($sql_stats);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result_stats = $stmt->get_result();
$stats = $result_stats->fetch_assoc();
$stmt->close();

// 处理 null 值
if ($stats['total_fee'] === null) $stats['total_fee'] = 0;

// 2. 获取列表数据 (限制最近 200 条，防止数据量过大)
$sql_list = "SELECT id, track_no, carrier, fee, created_at FROM freight_logs WHERE $where_sql ORDER BY created_at DESC LIMIT 200";
$stmt = $mysqli->prepare($sql_list);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => '列表查询准备失败: ' . $mysqli->error]);
    exit;
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result_list = $stmt->get_result();
$list = [];
while ($row = $result_list->fetch_assoc()) {
    $list[] = $row;
}
$stmt->close();

$mysqli->close();

// 返回结果
echo json_encode([
    'stats' => $stats,
    'list' => $list
]);
?>
