-- 创建数据库
CREATE DATABASE IF NOT EXISTS freight_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE freight_system;

-- 创建运费统计表
CREATE TABLE IF NOT EXISTS freight_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    track_no VARCHAR(50) NOT NULL COMMENT '运单号',
    carrier VARCHAR(50) DEFAULT NULL COMMENT '物流商',
    fee DECIMAL(10, 2) NOT NULL COMMENT '运费',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    INDEX idx_track_no (track_no),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运费统计表';

-- 创建系统用户表
CREATE TABLE IF NOT EXISTS system_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT '用户名',
    password_hash VARCHAR(255) NOT NULL COMMENT '密码哈希',
    created_at DATETIME DEFAULT NULL COMMENT '创建时间',
    updated_at DATETIME DEFAULT NULL COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统用户表';
