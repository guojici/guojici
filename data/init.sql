-- ============================================
-- MNBT 虚拟主机商城数据库初始化脚本
-- ============================================

CREATE DATABASE IF NOT EXISTS guojici DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE guojici;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================
-- 用户表
-- ============================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    balance DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 套餐表
-- ============================================
DROP TABLE IF EXISTS packages;
CREATE TABLE packages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price_monthly DECIMAL(10,2) NOT NULL,
    webdx INT DEFAULT 100 COMMENT '网页空间MB',
    sqldx INT DEFAULT 50 COMMENT '数据库空间MB',
    sizemax INT DEFAULT 60 COMMENT '流量G/月',
    ymbds INT DEFAULT 5 COMMENT '域名绑定数',
    type INT DEFAULT 2 COMMENT '1=CDN,2=主机,3=KVM',
    is_recommended TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    status ENUM('active', 'disabled') DEFAULT 'active',
    kvm_vcpu INT DEFAULT 2 COMMENT 'KVM核心数',
    kvm_memory_mb INT DEFAULT 2048 COMMENT 'KVM内存MB',
    kvm_disk_gb INT DEFAULT 40 COMMENT 'KVM磁盘GB',
    kvm_bandwidth_mbps INT DEFAULT 100 COMMENT 'KVM带宽Mbps',
    kvm_traffic_gb INT DEFAULT 100 COMMENT 'KVM流量GB/月',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 订单表
-- ============================================
DROP TABLE IF EXISTS orders;
CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_no VARCHAR(32) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    package_id INT NOT NULL,
    package_name VARCHAR(100),
    package_info TEXT,
    duration INT NOT NULL COMMENT '月数',
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    payment_method VARCHAR(50),
    payment_trade_no VARCHAR(100),
    remark VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_order_no (order_no),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 主机表
-- ============================================
DROP TABLE IF EXISTS hosts;
CREATE TABLE hosts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    package_id INT NOT NULL,
    package_name VARCHAR(100),
    mnbt_username VARCHAR(50) NOT NULL COMMENT 'MNBT主机账号',
    mnbt_password VARCHAR(255) NOT NULL COMMENT 'MNBT主机密码',
    control_panel_url VARCHAR(255),
    expire_at TIMESTAMP NOT NULL,
    api_response TEXT,
    status ENUM('creating', 'running', 'suspended', 'cancelled', 'suspended_traffic') DEFAULT 'creating',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    vm_name VARCHAR(100) DEFAULT '' COMMENT 'KVM虚拟机名称',
    ip_address VARCHAR(50) DEFAULT '' COMMENT 'KVM IP地址',
    uuid VARCHAR(128) DEFAULT NULL COMMENT 'UUID',
    traffic_used INT DEFAULT 0 COMMENT '已用流量MB',
    traffic_limit INT DEFAULT 0 COMMENT '流量限制MB',
    traffic_reset_date DATE DEFAULT NULL COMMENT '流量重置日期',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_expire (expire_at),
    UNIQUE INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 主机流量监控表
-- ============================================
DROP TABLE IF EXISTS host_traffic;
CREATE TABLE host_traffic (
    id INT PRIMARY KEY AUTO_INCREMENT,
    host_id INT NOT NULL COMMENT '主机ID',
    rx_bytes BIGINT DEFAULT 0 COMMENT '接收字节数',
    tx_bytes BIGINT DEFAULT 0 COMMENT '发送字节数',
    total_bytes BIGINT DEFAULT 0 COMMENT '总流量字节数',
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '采集时间',
    FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
    INDEX idx_host_id (host_id),
    INDEX idx_collected (collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 管理员表
-- ============================================
DROP TABLE IF EXISTS admin_users;
CREATE TABLE admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    last_ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 管理员日志表
-- ============================================
DROP TABLE IF EXISTS admin_logs;
CREATE TABLE admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    detail TEXT,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 初始化数据
-- ============================================

-- 初始化套餐
INSERT IGNORE INTO packages (name, description, price_monthly, webdx, sqldx, sizemax, ymbds, type, is_recommended, sort_order, status, kvm_vcpu, kvm_memory_mb, kvm_disk_gb, kvm_bandwidth_mbps, kvm_traffic_gb) VALUES
('基础版主机', '适合个人网站与小型项目，提供稳定的基础服务', 19.90, 1000, 500, 50, 3, 2, 0, 1, 'active', 0, 0, 0, 0, 0),
('标准版主机', '中小型企业首选，资源均衡，稳定可靠', 39.90, 3000, 1000, 100, 5, 2, 1, 2, 'active', 0, 0, 0, 0, 0),
('专业版主机', '大流量站点优选，资源充足，性能强劲', 69.90, 10000, 3000, 300, 10, 2, 0, 3, 'active', 0, 0, 0, 0, 0),
('企业版主机', '企业级服务，独享资源，7x24小时技术支持', 199.00, 50000, 10000, 1000, 20, 2, 0, 4, 'active', 0, 0, 0, 0, 0),
('CDN加速服务', '全球CDN加速，提升网站访问速度', 9.90, 0, 0, 100, 5, 1, 0, 10, 'active', 0, 0, 0, 0, 0),
('KVM云服务器 1核1G', '入门级云服务器，适合个人开发者和小型应用', 29.90, 0, 0, 0, 0, 3, 0, 5, 'active', 1, 1024, 20, 50, 50),
('KVM云服务器 1核2G', '基础型云服务器，适合小型网站和测试环境', 49.90, 0, 0, 0, 0, 3, 1, 6, 'active', 1, 2048, 40, 100, 100),
('KVM云服务器 2核4G', '标准型云服务器，适合中小型企业应用', 89.90, 0, 0, 0, 0, 3, 0, 7, 'active', 2, 4096, 80, 200, 300),
('KVM云服务器 4核8G', '性能型云服务器，适合大型应用和数据库', 169.00, 0, 0, 0, 0, 3, 0, 8, 'active', 4, 8192, 160, 500, 500),
('KVM云服务器 8核16G', '旗舰型云服务器，企业级高性能应用', 329.00, 0, 0, 0, 0, 3, 0, 9, 'active', 8, 16384, 320, 1000, 1000);

-- 初始化管理员 (密码: admin123，需要手动在安装时通过password_hash生成)
INSERT IGNORE INTO admin_users (username, password, role, created_at) VALUES
('admin', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'super_admin', NOW());

-- 测试用户 (密码: 123456)
INSERT IGNORE INTO users (username, email, password, phone, balance, status, created_at) VALUES
('testuser', 'test@example.com', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', '13800138000', 0.00, 'active', NOW());

-- ============================================
-- 用户积分表
-- ============================================
DROP TABLE IF EXISTS user_points;
CREATE TABLE user_points (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    points INT DEFAULT 0 COMMENT '当前积分余额',
    total_earned INT DEFAULT 0 COMMENT '累计获得积分',
    total_spent INT DEFAULT 0 COMMENT '累计消耗积分',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 积分记录表
-- ============================================
DROP TABLE IF EXISTS point_logs;
CREATE TABLE point_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    change_type ENUM('earn_order','earn_register','earn_referral','earn_daily','spend_exchange','admin_add','admin_deduct') NOT NULL,
    points INT NOT NULL COMMENT '变动积分数量（正数为获得，负数为消耗）',
    balance_after INT NOT NULL COMMENT '变动后余额',
    description VARCHAR(255) DEFAULT '',
    related_id INT DEFAULT NULL COMMENT '关联ID（订单ID/工单ID等）',
    operator_id INT DEFAULT NULL COMMENT '操作人（管理员ID，null表示系统）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_change_type (change_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 积分规则表
-- ============================================
DROP TABLE IF EXISTS point_rules;
CREATE TABLE point_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rule_key VARCHAR(50) NOT NULL UNIQUE COMMENT '规则标识',
    rule_name VARCHAR(100) NOT NULL COMMENT '规则名称',
    points INT NOT NULL COMMENT '积分数量',
    enabled TINYINT(1) DEFAULT 1,
    description VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 默认积分规则
INSERT IGNORE INTO point_rules (rule_key, rule_name, points, enabled, description) VALUES
('register', '注册赠送', 50, 1, '新用户注册时赠送'),
('order_pay', '消费返积分', 10, 1, '每消费1元返1积分'),
('daily_login', '每日登录', 5, 1, '每日首次登录赠送'),
('referral_signup', '推广注册', 20, 1, '推广用户注册成功');

-- ============================================
-- 工单表
-- ============================================
DROP TABLE IF EXISTS tickets;
CREATE TABLE tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_no VARCHAR(32) NOT NULL UNIQUE COMMENT '工单编号',
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL COMMENT '工单标题',
    category ENUM('tech','finance','account','complaint','other') DEFAULT 'other' COMMENT '类别',
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('open','replied','closed') DEFAULT 'open',
    last_reply_at TIMESTAMP NULL COMMENT '最后回复时间',
    last_reply_by VARCHAR(20) DEFAULT NULL COMMENT '最后回复方(user/admin)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_ticket_no (ticket_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 工单回复表
-- ============================================
DROP TABLE IF EXISTS ticket_replies;
CREATE TABLE ticket_replies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id INT NOT NULL,
    user_id INT DEFAULT NULL COMMENT 'null表示管理员回复',
    admin_id INT DEFAULT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_ticket_id (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 推广返现表
-- ============================================
DROP TABLE IF EXISTS referrals;
CREATE TABLE referrals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    referrer_id INT NOT NULL COMMENT '推广人ID',
    referred_id INT NOT NULL COMMENT '被推广人ID',
    referral_code VARCHAR(32) NOT NULL COMMENT '推广码',
    rebate_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '已返现金额',
    rebate_count INT DEFAULT 0 COMMENT '返现次数',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_referrer (referrer_id),
    INDEX idx_referred (referred_id),
    INDEX idx_code (referral_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 返现记录表
-- ============================================
DROP TABLE IF EXISTS rebate_logs;
CREATE TABLE rebate_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    referrer_id INT NOT NULL,
    referred_id INT NOT NULL,
    order_id INT NOT NULL COMMENT '关联订单',
    rebate_amount DECIMAL(10,2) NOT NULL COMMENT '返现金额',
    order_amount DECIMAL(10,2) NOT NULL COMMENT '订单金额',
    status ENUM('pending','settled','cancelled') DEFAULT 'settled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_referrer (referrer_id),
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 返现规则表
-- ============================================
DROP TABLE IF EXISTS rebate_rules;
CREATE TABLE rebate_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rule_key VARCHAR(50) NOT NULL UNIQUE,
    rule_name VARCHAR(100) NOT NULL,
    rebate_type ENUM('percent','fixed') DEFAULT 'percent' COMMENT 'percent=按比例 fixed=固定金额',
    rebate_value DECIMAL(10,2) NOT NULL COMMENT '返现值',
    min_order_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '最低订单金额',
    enabled TINYINT(1) DEFAULT 1,
    description VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO rebate_rules (rule_key, rule_name, rebate_type, rebate_value, min_order_amount, enabled, description) VALUES
('first_order', '首单返现', 'percent', 5.00, 0.00, 1, '被推广用户首单，返订单金额的5%给推广人'),
('every_order', '每单返现', 'percent', 2.00, 10.00, 1, '推广用户每笔订单返2%');

-- ============================================
-- 广告位表
-- ============================================
DROP TABLE IF EXISTS ad_positions;
CREATE TABLE ad_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pos_key VARCHAR(50) NOT NULL UNIQUE COMMENT '位置标识',
    pos_name VARCHAR(100) NOT NULL COMMENT '位置名称',
    description VARCHAR(255) DEFAULT '',
    width INT DEFAULT 0 COMMENT '宽度（0表示自适应）',
    height INT DEFAULT 0 COMMENT '高度（0表示自适应）',
    status ENUM('active','disabled') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 默认广告位
INSERT IGNORE INTO ad_positions (pos_key, pos_name, description, width, height, status, sort_order) VALUES
('user_dashboard_top', '用户后台顶部', '用户后台首页顶部横幅广告', 728, 90, 'active', 1),
('user_dashboard_bottom', '用户后台底部', '用户后台首页底部横幅广告', 728, 90, 'active', 2),
('checkout_top', '购买页顶部', '购买流程页面顶部广告', 728, 90, 'active', 3);

-- ============================================
-- 广告表
-- ============================================
DROP TABLE IF EXISTS ads;
CREATE TABLE ads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL COMMENT '广告标题',
    pos_id INT NOT NULL COMMENT '广告位置ID',
    image_url VARCHAR(500) NOT NULL COMMENT '广告图片URL',
    link_url VARCHAR(500) DEFAULT '' COMMENT '跳转链接',
    link_target ENUM('_self','_blank') DEFAULT '_blank',
    start_date DATE DEFAULT NULL COMMENT '投放开始日期',
    end_date DATE DEFAULT NULL COMMENT '投放结束日期',
    click_count INT DEFAULT 0 COMMENT '点击次数',
    status ENUM('active','paused','expired') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pos_id) REFERENCES ad_positions(id) ON DELETE CASCADE,
    INDEX idx_pos_id (pos_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 用户积分初始化（为已有用户创建积分记录）
-- ============================================
INSERT IGNORE INTO user_points (user_id, points, total_earned, total_spent)
SELECT id, 0, 0, 0 FROM users WHERE id NOT IN (SELECT user_id FROM user_points);

-- ============================================
-- 站内通知表
-- ============================================
DROP TABLE IF EXISTS user_notifications;
CREATE TABLE user_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT '用户ID',
    type ENUM('system','host','order','security','promotion') DEFAULT 'system' COMMENT '通知类型',
    title VARCHAR(200) NOT NULL COMMENT '标题',
    content TEXT COMMENT '内容',
    related_type VARCHAR(50) DEFAULT '' COMMENT '关联类型: host/order等',
    related_id INT DEFAULT 0 COMMENT '关联ID',
    is_read TINYINT(1) DEFAULT 0 COMMENT '是否已读: 0=未读, 1=已读',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- WebSSH Token表
-- ============================================
DROP TABLE IF EXISTS ssh_tokens;
CREATE TABLE ssh_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL UNIQUE COMMENT '一次性token',
    user_id INT NOT NULL COMMENT '用户ID',
    host_id INT NOT NULL COMMENT '主机ID',
    ip VARCHAR(50) NOT NULL COMMENT 'SSH IP地址',
    port INT DEFAULT 22 COMMENT 'SSH端口',
    username VARCHAR(50) DEFAULT 'root' COMMENT 'SSH用户名',
    password VARCHAR(255) DEFAULT '' COMMENT 'SSH密码',
    expire_at INT NOT NULL COMMENT '过期时间戳',
    used TINYINT(1) DEFAULT 0 COMMENT '是否已使用: 0=未用, 1=已用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expire (expire_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 主机操作日志表
-- ============================================
DROP TABLE IF EXISTS host_operation_logs;
CREATE TABLE host_operation_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    host_id INT NOT NULL COMMENT '主机ID',
    user_id INT NOT NULL COMMENT '用户ID',
    type ENUM('info','success','warning','error') DEFAULT 'info' COMMENT '日志类型',
    type_label VARCHAR(20) DEFAULT '' COMMENT '类型标签',
    action VARCHAR(100) DEFAULT '' COMMENT '操作动作',
    content VARCHAR(500) DEFAULT '' COMMENT '日志内容',
    ip VARCHAR(45) DEFAULT '' COMMENT '操作IP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_host_id (host_id),
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 积分兑换商品表
-- ============================================
DROP TABLE IF EXISTS point_products;
CREATE TABLE point_products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL COMMENT '商品名称',
    category ENUM('host','server','voucher','other') DEFAULT 'host' COMMENT '商品分类: host=虚拟主机, server=云服务器, voucher=优惠券, other=其他',
    description TEXT COMMENT '商品描述',
    image_url VARCHAR(500) DEFAULT '' COMMENT '商品图片',
    points INT NOT NULL DEFAULT 0 COMMENT '所需积分',
    original_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '原价（元）',
    stock INT DEFAULT -1 COMMENT '库存数量, -1为无限',
    sold_count INT DEFAULT 0 COMMENT '已兑换数量',
    duration INT DEFAULT 0 COMMENT '有效期/时长(天)',
    package_id INT DEFAULT 0 COMMENT '关联套餐ID',
    discount_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '折扣率%',
    status ENUM('active','disabled') DEFAULT 'active' COMMENT '状态',
    sort_order INT DEFAULT 0 COMMENT '排序',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO point_products (name, category, description, image_url, points, original_price, stock, sold_count, duration, package_id, discount_rate, status, sort_order) VALUES
('基础版虚拟主机月卡', 'host', '基础版虚拟主机1个月使用权限，适合个人网站', '', 500, 19.90, -1, 0, 30, 1, 100.00, 'active', 1),
('标准版虚拟主机月卡', 'host', '标准版虚拟主机1个月使用权限，中小型企业首选', '', 1000, 39.90, -1, 0, 30, 2, 100.00, 'active', 2),
('专业版虚拟主机月卡', 'host', '专业版虚拟主机1个月使用权限，大流量站点优选', '', 1800, 69.90, -1, 0, 30, 3, 100.00, 'active', 3),
('云服务器2核4G月卡', 'server', '云服务器2核4G配置1个月，适合中小型应用', '', 3000, 99.00, -1, 0, 30, 0, 100.00, 'active', 4),
('云服务器4核8G月卡', 'server', '云服务器4核8G配置1个月，企业级应用首选', '', 6000, 199.00, -1, 0, 30, 0, 100.00, 'active', 5),
('10元无门槛优惠券', 'voucher', '全场通用10元优惠券，无最低消费限制', '', 200, 10.00, -1, 0, 30, 0, 100.00, 'active', 6),
('50元满减优惠券', 'voucher', '满200元减50元优惠券，适用于所有套餐', '', 800, 50.00, -1, 0, 30, 0, 100.00, 'active', 7);

-- ============================================
-- 积分兑换记录表
-- ============================================
DROP TABLE IF EXISTS point_exchange_logs;
CREATE TABLE point_exchange_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT '用户ID',
    product_id INT NOT NULL COMMENT '商品ID',
    product_name VARCHAR(200) NOT NULL COMMENT '商品名称快照',
    category VARCHAR(50) DEFAULT '' COMMENT '商品分类快照',
    points INT NOT NULL COMMENT '消耗积分',
    status ENUM('pending','completed','failed','cancelled') DEFAULT 'completed' COMMENT '状态',
    related_id INT DEFAULT 0 COMMENT '关联ID(如订单ID/主机ID)',
    remark VARCHAR(500) DEFAULT '' COMMENT '备注',
    expire_at TIMESTAMP NULL COMMENT '过期时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_product_id (product_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 代理等级表
-- ============================================
CREATE TABLE IF NOT EXISTS agent_levels (
    id INT PRIMARY KEY AUTO_INCREMENT,
    level_name VARCHAR(50) NOT NULL COMMENT '等级名称',
    level_key VARCHAR(30) NOT NULL UNIQUE COMMENT '等级标识(super/top/normal)',
    level INT DEFAULT 0 COMMENT '等级数字(越大越高)',
    discount_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '拿货折扣率(%)',
    min_commission_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '最低佣金比例(%)',
    max_commission_rate DECIMAL(5,2) DEFAULT 30.00 COMMENT '最高佣金比例(%)',
    can_create_agent TINYINT(1) DEFAULT 0 COMMENT '是否可创建下级代理',
    can_set_price TINYINT(1) DEFAULT 0 COMMENT '是否可设置下级售价',
    can_view_sub_data TINYINT(1) DEFAULT 0 COMMENT '是否可查看下级数据',
    description VARCHAR(500) DEFAULT '' COMMENT '等级描述',
    status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态',
    sort_order INT DEFAULT 0 COMMENT '排序',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_key (level_key),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO agent_levels (level_name, level_key, level, discount_rate, min_commission_rate, max_commission_rate, can_create_agent, can_set_price, can_view_sub_data) VALUES
    ('超级总代', 'super', 100, 60.00, 0.00, 50.00, 1, 1, 1),
    ('高级代理', 'top', 50, 75.00, 5.00, 30.00, 1, 1, 1),
    ('普通代理', 'normal', 10, 90.00, 0.00, 15.00, 0, 0, 0);

-- ============================================
-- 代理表
-- ============================================
CREATE TABLE IF NOT EXISTS agents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT '关联用户ID',
    agent_no VARCHAR(30) NOT NULL UNIQUE COMMENT '代理编号',
    level_id INT DEFAULT 0 COMMENT '代理等级ID',
    parent_id INT DEFAULT 0 COMMENT '上级代理ID',
    parent_path VARCHAR(500) DEFAULT '' COMMENT '上级路径(逗号分隔)',
    invite_code VARCHAR(20) NOT NULL UNIQUE COMMENT '邀请码',
    discount_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '个人折扣率(%)',
    commission_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '个人佣金比例(%)',
    total_commission DECIMAL(10,2) DEFAULT 0.00 COMMENT '累计佣金',
    available_commission DECIMAL(10,2) DEFAULT 0.00 COMMENT '可提现佣金',
    frozen_commission DECIMAL(10,2) DEFAULT 0.00 COMMENT '冻结佣金',
    status ENUM('active','inactive','banned') DEFAULT 'active' COMMENT '状态',
    approved_at TIMESTAMP NULL COMMENT '审核通过时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_level (level_id),
    INDEX idx_invite (invite_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 代理客户表
-- ============================================
CREATE TABLE IF NOT EXISTS agent_customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    agent_id INT NOT NULL COMMENT '代理ID',
    user_id INT NOT NULL COMMENT '客户用户ID',
    total_orders INT DEFAULT 0 COMMENT '总订单数',
    total_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '总消费金额',
    last_order_time TIMESTAMP NULL COMMENT '最近下单时间',
    status ENUM('active','lost') DEFAULT 'active' COMMENT '客户状态',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_agent_user (agent_id, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_agent (agent_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 佣金记录表
-- ============================================
CREATE TABLE IF NOT EXISTS commission_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    agent_id INT NOT NULL COMMENT '代理ID',
    customer_id INT DEFAULT 0 COMMENT '客户ID',
    order_id INT DEFAULT 0 COMMENT '订单ID',
    order_no VARCHAR(50) DEFAULT '' COMMENT '订单号',
    order_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '订单金额',
    commission_type ENUM('sale','renew','rebate','bonus') DEFAULT 'sale' COMMENT '佣金类型',
    commission_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '佣金比例(%)',
    commission_amount DECIMAL(10,2) NOT NULL COMMENT '佣金金额',
    status ENUM('pending','available','withdrawn','frozen','cancelled') DEFAULT 'pending' COMMENT '状态',
    remark VARCHAR(500) DEFAULT '' COMMENT '备注',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_agent (agent_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 代理申请表
-- ============================================
CREATE TABLE IF NOT EXISTS agent_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT '申请用户ID',
    real_name VARCHAR(50) DEFAULT '' COMMENT '真实姓名',
    phone VARCHAR(20) DEFAULT '' COMMENT '联系电话',
    wechat VARCHAR(50) DEFAULT '' COMMENT '微信号',
    company VARCHAR(100) DEFAULT '' COMMENT '公司名称',
    experience VARCHAR(500) DEFAULT '' COMMENT '代理经验/渠道描述',
    expected_level_id INT DEFAULT 0 COMMENT '期望代理等级ID',
    expected_discount_rate DECIMAL(5,2) DEFAULT 0 COMMENT '期望拿货折扣(%)',
    expected_commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT '期望佣金比例(%)',
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending' COMMENT '状态',
    admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
    approved_level_id INT DEFAULT 0 COMMENT '审核通过的等级ID',
    approved_discount_rate DECIMAL(5,2) DEFAULT 0 COMMENT '审核通过的折扣',
    approved_commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT '审核通过的佣金比例',
    reviewed_by INT DEFAULT 0 COMMENT '审核人ID',
    reviewed_at TIMESTAMP NULL COMMENT '审核时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 代理等级定价表（每个套餐对各代理等级的拿货价）
-- ============================================
CREATE TABLE IF NOT EXISTS agent_pricing (
    id INT PRIMARY KEY AUTO_INCREMENT,
    package_id INT NOT NULL COMMENT '套餐ID',
    level_id INT NOT NULL COMMENT '代理等级ID',
    agent_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '代理拿货价(元/月)',
    min_sell_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '最低售价限制(元/月)',
    status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pkg_level (package_id, level_id),
    INDEX idx_package (package_id),
    INDEX idx_level (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 提现记录表
-- ============================================
CREATE TABLE IF NOT EXISTS withdraw_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    agent_id INT NOT NULL COMMENT '代理ID',
    amount DECIMAL(10,2) NOT NULL COMMENT '提现金额',
    method ENUM('alipay','wechat','bank') NOT NULL COMMENT '提现方式',
    alipay_account VARCHAR(100) DEFAULT '' COMMENT '支付宝账号',
    wechat_account VARCHAR(100) DEFAULT '' COMMENT '微信账号',
    bank_name VARCHAR(100) DEFAULT '' COMMENT '银行名称',
    bank_account VARCHAR(50) DEFAULT '' COMMENT '银行卡号',
    bank_holder VARCHAR(50) DEFAULT '' COMMENT '持卡人姓名',
    status ENUM('pending','processing','success','failed','cancelled') DEFAULT 'pending' COMMENT '状态',
    admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
    processed_at TIMESTAMP NULL COMMENT '处理时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_agent (agent_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 安装说明
-- ============================================
-- 默认管理员账号: admin
-- 默认管理员密码: admin123
-- 测试用户账号: test@example.com / 123456
-- 请在生产环境中修改默认密码！

SET FOREIGN_KEY_CHECKS=1;
