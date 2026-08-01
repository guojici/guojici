<?php
$site_name = config('site.logo_text');
$site_url = config('site.url');
?>
<footer class="footer">
    <div class="footer-container">
        <div class="footer-section">
            <div class="footer-logo">
                <span class="logo-icon"><?php echo e(config('site.logo_icon')); ?></span>
                <span class="logo-text"><?php echo $site_name; ?></span>
            </div>
            <p class="footer-description">专业的云计算服务提供商，致力于为企业和开发者提供稳定、高效、安全的云基础设施服务。</p>
            <div class="footer-social">
                <a href="#" class="social-icon">💬</a>
                <a href="#" class="social-icon">📧</a>
                <a href="#" class="social-icon">🐦</a>
                <a href="#" class="social-icon">🐙</a>
            </div>
        </div>
        
        <div class="footer-section">
            <h4>产品服务</h4>
            <ul>
                <li><a href="/user/hosts.php">云服务器</a></li>
                <li><a href="/user/hosts.php">虚拟主机</a></li>
                <li><a href="/user/hosts.php">云数据库</a></li>
                <li><a href="/user/hosts.php">负载均衡</a></li>
                <li><a href="/user/hosts.php">对象存储</a></li>
            </ul>
        </div>
        
        <div class="footer-section">
            <h4>解决方案</h4>
            <ul>
                <li><a href="#">企业上云</a></li>
                <li><a href="#">电商解决方案</a></li>
                <li><a href="#">AI计算平台</a></li>
                <li><a href="#">游戏云服务</a></li>
                <li><a href="#">混合云部署</a></li>
            </ul>
        </div>
        
        <div class="footer-section">
            <h4>支持与服务</h4>
            <ul>
                <li><a href="#">API文档</a></li>
                <li><a href="#">服务协议</a></li>
                <li><a href="#">联系我们</a></li>
            </ul>
        </div>
        
        <div class="footer-section">
            <h4>联系我们</h4>
            <div class="footer-contact">
                <div class="contact-item">
                    <span class="contact-icon">📍</span>
                    <span>上海市浦东新区张江高科技园区</span>
                </div>
                <div class="contact-item">
                    <span class="contact-icon">📞</span>
                    <span><?php echo e(db_get_setting('site_contact_phone', '400-123-4567')); ?></span>
                </div>
                <div class="contact-item">
                    <span class="contact-icon">📧</span>
                    <span><?php echo e(db_get_setting('site_contact_email', 'info@kvm-automation.com')); ?></span>
                </div>
                <div class="contact-item">
                    <span class="contact-icon">🕐</span>
                    <span>7x24小时在线服务</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="footer-bottom">
        <div class="footer-bottom-left">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $site_name; ?>. 保留所有权利。</p>
            <div class="footer-links">
                <a href="#">隐私政策</a>
                <a href="#">服务条款</a>
                <a href="#">Cookie政策</a>
                <a href="#">备案信息</a>
            </div>
        </div>
        <div class="footer-bottom-right">
            <div class="footer-badges">
                <span class="badge">IPv6 Ready</span>
                <span class="badge">ISO 27001</span>
                <span class="badge">等保三级</span>
            </div>
        </div>
    </div>
</footer>

<style>
.floating-chat-btn {
    position: fixed;
    right: 24px;
    bottom: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1677ff 0%, #4096ff 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    text-decoration: none;
    box-shadow: 0 4px 20px rgba(22, 119, 255, 0.4);
    transition: all 0.3s ease;
    z-index: 9999;
    cursor: pointer;
}
.floating-chat-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 24px rgba(22, 119, 255, 0.5);
}
.floating-chat-btn .chat-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    min-width: 18px;
    height: 18px;
    line-height: 18px;
    padding: 0 5px;
    background: #ff4d4f;
    color: #fff;
    font-size: 10px;
    font-weight: 500;
    border-radius: 9px;
    text-align: center;
}
@media (max-width: 768px) {
    .floating-chat-btn {
        right: 16px;
        bottom: 16px;
        width: 48px;
        height: 48px;
        font-size: 22px;
    }
}
</style>

<style>
.footer {
    background: #0a1628;
    color: #a0aec0;
    padding: 60px 20px 32px;
    margin-top: 80px;
}
.footer-container {
    max-width: 1400px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1.5fr;
    gap: 48px;
}
.footer-section h4 {
    font-size: 14px;
    font-weight: 600;
    color: #fff;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
}
.footer-section ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.footer-section ul li {
    margin-bottom: 10px;
}
.footer-section ul li a {
    font-size: 13px;
    color: #a0aec0;
    text-decoration: none;
    transition: color 0.2s;
}
.footer-section ul li a:hover {
    color: #1677ff;
}
.footer-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.footer-logo .logo-text {
    font-size: 18px;
    font-weight: 700;
    color: #fff;
}
.footer-description {
    font-size: 13px;
    line-height: 1.8;
    color: #a0aec0;
    margin-bottom: 20px;
    max-width: 280px;
}
.footer-social {
    display: flex;
    gap: 12px;
}
.social-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    text-decoration: none;
    transition: all 0.2s;
}
.social-icon:hover {
    background: rgba(22,119,255,0.2);
    color: #1677ff;
    transform: translateY(-2px);
}
.footer-contact {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.contact-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    color: #a0aec0;
}
.contact-icon {
    font-size: 14px;
    flex-shrink: 0;
}
.footer-bottom {
    max-width: 1400px;
    margin: 0 auto;
    padding-top: 24px;
    margin-top: 40px;
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.footer-bottom-left {
    display: flex;
    align-items: center;
    gap: 24px;
    font-size: 12px;
    color: #718096;
}
.footer-links {
    display: flex;
    gap: 16px;
}
.footer-links a {
    font-size: 12px;
    color: #718096;
    text-decoration: none;
    transition: color 0.2s;
}
.footer-links a:hover {
    color: #a0aec0;
}
.footer-bottom-right {
    display: flex;
    align-items: center;
    gap: 12px;
}
.footer-badges {
    display: flex;
    gap: 8px;
}
.footer-badges .badge {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 10px;
    background: rgba(22,119,255,0.15);
    color: #1677ff;
    font-weight: 500;
}

@media (max-width: 1024px) {
    .footer-container {
        grid-template-columns: 1fr 1fr;
        gap: 32px;
    }
}

@media (max-width: 768px) {
    .footer-container {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .footer-bottom {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    .footer-bottom-left {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}
</style>