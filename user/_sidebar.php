<?php
$user = auth_user();
$current_page = $_SERVER['REQUEST_URI'];

?>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <span class="logo-icon"><?php echo e(config('site.logo_icon')); ?></span>
            <span class="logo-text"><?php echo e(config('site.logo_text')); ?></span>
        </div>
    </div>

    <div class="user-profile-card">
        <div class="user-avatar"><?php echo mb_substr($user['username'], 0, 1); ?></div>
        <div class="user-info">
            <h3><?php echo e($user['username']); ?></h3>
            <p class="user-balance">余额: ¥<?php echo number_format($user['balance'], 2); ?></p>
        </div>
        <a href="/user/profile.php" class="edit-profile">编辑</a>
    </div>

    <nav class="sidebar-nav">
        <ul class="sidebar-menu">
            <li><a href="/user/index.php" class="<?php echo strpos($current_page, '/user/index') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">📊</span>
                <span class="menu-text">控制台</span>
            </a></li>
            <li><a href="/user/hosts.php" class="<?php echo strpos($current_page, '/user/hosts') !== false || strpos($current_page, '/user/host_detail') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">🖥️</span>
                <span class="menu-text">我的主机</span>
            </a></li>
            <li><a href="/user/orders.php" class="<?php echo strpos($current_page, '/user/orders') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">📋</span>
                <span class="menu-text">我的订单</span>
            </a></li>
            <li><a href="/checkout.php" class="<?php echo strpos($current_page, '/checkout') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">🛒</span>
                <span class="menu-text">购买主机</span>
            </a></li>
            <li><a href="/user/notifications.php" class="<?php echo strpos($current_page, '/user/notifications') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">🔔</span>
                <span class="menu-text">通知中心</span>
            </a></li>
            <li><a href="/user/profile.php" class="<?php echo strpos($current_page, '/user/profile') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">👤</span>
                <span class="menu-text">个人资料</span>
            </a></li>
            <li><a href="/user/verify.php" class="<?php echo strpos($current_page, '/user/verify') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">🪪</span>
                <span class="menu-text">实名认证</span>
            </a></li>
            <li><a href="/user/resize.php" class="<?php echo strpos($current_page, '/user/resize') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">⚙️</span>
                <span class="menu-text">规格调整</span>
            </a></li>
            <li><a href="/user/api_keys.php" class="<?php echo strpos($current_page, '/user/api_keys') !== false ? 'active' : ''; ?>">
                <span class="menu-icon">🔑</span>
                <span class="menu-text">API密钥</span>
            </a></li>
            <li class="sidebar-divider"></li>
            <li><a href="/logout.php" class="logout-item">
                <span class="menu-icon">🚪</span>
                <span class="menu-text">退出登录</span>
            </a></li>
        </ul>
    </nav>
</aside>
