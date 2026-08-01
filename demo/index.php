<?php
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/DemoMode.php';

// 如果演示模式未启用，重定向到主页
if (!DemoMode::isEnabled()) {
    header('Location: /');
    exit;
}

// 处理演示模式退出
if (is_post()) {
    $action = post('action', '');
    if ($action === 'demo_logout') {
        $password = post('password', '');
        $result = DemoMode::disable($password);
        if ($result['success']) {
            flash('success', $result['message']);
            header('Location: /');
            exit;
        } else {
            $error_msg = $result['message'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KVM 自动化系统 - 演示模式</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: #333; line-height: 1.6; }
        
        /* Hero Section */
        .hero-section { display: flex; min-height: 100vh; background: #fff; }
        .hero-left { flex: 1; padding: 60px 80px; display: flex; flex-direction: column; justify-content: center; }
        .hero-right { flex: 1; background: #000; position: relative; }
        .hero-right img { width: 100%; height: 100%; object-fit: cover; }
        .hero-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.3); }
        
        .logo { font-size: 24px; font-weight: 700; color: #000; margin-bottom: 40px; }
        .logo span { color: #666; font-weight: 400; }
        
        .hero-title { font-size: 56px; font-weight: 700; color: #000; line-height: 1.2; margin-bottom: 24px; }
        .hero-subtitle { font-size: 18px; color: #666; margin-bottom: 40px; }
        
        .hero-btn { display: inline-block; padding: 16px 48px; background: #000; color: #fff; text-decoration: none; font-weight: 600; border-radius: 4px; transition: all 0.3s; }
        .hero-btn:hover { background: #333; }
        
        .hero-stats { position: absolute; bottom: 0; left: 0; right: 0; display: flex; justify-content: space-around; padding: 30px; background: rgba(0,0,0,0.8); }
        .stat-item { text-align: center; color: #fff; }
        .stat-value { font-size: 32px; font-weight: 700; }
        .stat-label { font-size: 14px; color: #aaa; }
        
        /* Navigation */
        .nav-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; display: flex; justify-content: space-between; align-items: center; padding: 20px 80px; background: rgba(255,255,255,0.95); }
        .nav-links { display: flex; gap: 32px; }
        .nav-links a { text-decoration: none; color: #333; font-weight: 500; transition: color 0.3s; }
        .nav-links a:hover { color: #666; }
        .nav-demo-btn { padding: 8px 24px; background: #000; color: #fff; text-decoration: none; font-weight: 600; border-radius: 4px; }
        
        /* Features Section */
        .features-section { padding: 100px 80px; background: #fff; }
        .features-header { text-align: center; margin-bottom: 60px; }
        .features-title { font-size: 36px; font-weight: 700; color: #000; margin-bottom: 16px; }
        .features-subtitle { font-size: 16px; color: #666; }
        
        .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 40px; }
        .feature-card { text-align: center; }
        .feature-icon { width: 64px; height: 64px; margin: 0 auto 24px; background: #f5f5f5; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .feature-title { font-size: 18px; font-weight: 600; color: #000; margin-bottom: 12px; }
        .feature-desc { font-size: 14px; color: #666; line-height: 1.6; }
        
        /* Pricing Section */
        .pricing-section { padding: 100px 80px; background: #1a1a2e; color: #fff; }
        .pricing-header { text-align: center; margin-bottom: 60px; }
        .pricing-title { font-size: 36px; font-weight: 700; margin-bottom: 16px; }
        .pricing-subtitle { font-size: 16px; color: #aaa; }
        
        .pricing-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
        .pricing-card { background: #252545; padding: 40px; border-radius: 12px; position: relative; }
        .pricing-card.popular { border: 2px solid #4a90d9; }
        .popular-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: #4a90d9; color: #fff; padding: 4px 20px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        
        .pricing-name { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
        .pricing-desc { font-size: 14px; color: #aaa; margin-bottom: 24px; }
        
        .pricing-price { font-size: 48px; font-weight: 700; }
        .pricing-period { font-size: 14px; color: #aaa; margin-bottom: 32px; }
        
        .pricing-features { list-style: none; margin-bottom: 32px; }
        .pricing-features li { padding: 12px 0; font-size: 14px; color: #ccc; border-bottom: 1px solid rgba(255,255,255,0.1); }
        
        .pricing-btn { display: block; padding: 14px; text-align: center; text-decoration: none; font-weight: 600; border-radius: 4px; transition: all 0.3s; }
        .pricing-btn.primary { background: #fff; color: #000; }
        .pricing-btn.primary:hover { background: #eee; }
        .pricing-btn.secondary { background: transparent; border: 2px solid #fff; color: #fff; }
        .pricing-btn.secondary:hover { background: rgba(255,255,255,0.1); }
        
        /* Clients Section */
        .clients-section { padding: 100px 80px; background: #fff; }
        .clients-header { text-align: center; margin-bottom: 60px; }
        .clients-title { font-size: 36px; font-weight: 700; color: #000; margin-bottom: 16px; }
        .clients-subtitle { font-size: 16px; color: #666; }
        
        .clients-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 32px; }
        .client-card { text-align: center; }
        .client-logo { width: 120px; height: 60px; margin: 0 auto 16px; background: #f5f5f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .client-name { font-size: 16px; font-weight: 600; color: #000; margin-bottom: 8px; }
        .client-desc { font-size: 14px; color: #666; }
        
        /* FAQ Section */
        .faq-section { padding: 100px 80px; background: #fff; display: flex; gap: 80px; }
        .faq-left { flex: 1; }
        .faq-right { flex: 1; }
        
        .faq-title { font-size: 36px; font-weight: 700; color: #000; margin-bottom: 40px; }
        
        .faq-item { margin-bottom: 20px; }
        .faq-question { display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #f5f5f5; cursor: pointer; font-weight: 600; color: #000; }
        .faq-question:hover { background: #eee; }
        .faq-icon { font-size: 20px; color: #666; }
        .faq-answer { padding: 20px; background: #fff; border: 1px solid #eee; display: none; color: #666; }
        
        /* Contact Form */
        .contact-section { padding: 100px 80px; background: #1a1a2e; color: #fff; }
        .contact-content { display: flex; gap: 80px; }
        .contact-left { flex: 1; }
        .contact-right { flex: 1; }
        
        .contact-title { font-size: 36px; font-weight: 700; margin-bottom: 16px; }
        .contact-subtitle { font-size: 16px; color: #aaa; margin-bottom: 40px; }
        
        .contact-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; font-size: 14px; margin-bottom: 8px; color: #aaa; }
        .form-group input, .form-group textarea { width: 100%; padding: 14px; border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; background: rgba(255,255,255,0.1); color: #fff; font-size: 14px; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #4a90d9; }
        .form-group textarea { height: 120px; resize: none; }
        
        .contact-btn { grid-column: span 2; padding: 16px; background: #fff; color: #000; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .contact-btn:hover { background: #eee; }
        
        /* Footer */
        .footer { padding: 60px 80px; background: #16162a; color: #fff; }
        .footer-content { display: grid; grid-template-columns: 2fr repeat(3, 1fr); gap: 40px; }
        
        .footer-brand { font-size: 24px; font-weight: 700; margin-bottom: 16px; }
        .footer-desc { font-size: 14px; color: #aaa; line-height: 1.6; }
        
        .footer-title { font-size: 14px; font-weight: 600; margin-bottom: 20px; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 12px; }
        .footer-links a { text-decoration: none; color: #aaa; font-size: 14px; transition: color 0.3s; }
        .footer-links a:hover { color: #fff; }
        
        .footer-contact { font-size: 14px; color: #aaa; }
        .footer-contact span { display: block; margin-bottom: 8px; }
        
        .footer-bottom { padding-top: 40px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; margin-top: 40px; }
        .footer-copyright { font-size: 14px; color: #aaa; }
        .footer-policies { display: flex; gap: 24px; }
        .footer-policies a { text-decoration: none; color: #aaa; font-size: 14px; }
        
        /* Demo Overlay */
        .demo-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 2000; display: flex; align-items: center; justify-content: center; }
        .demo-modal { background: #fff; padding: 40px; border-radius: 12px; max-width: 400px; width: 90%; }
        .demo-modal h3 { font-size: 24px; margin-bottom: 20px; color: #000; }
        .demo-modal input { width: 100%; padding: 14px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .demo-modal button { width: 100%; padding: 14px; background: #000; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .demo-modal .close-btn { background: #f5f5f5; color: #333; margin-top: 12px; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .hero-section { flex-direction: column; }
            .hero-left { padding: 40px 20px; }
            .hero-title { font-size: 36px; }
            .features-grid, .pricing-grid, .clients-grid, .footer-content { grid-template-columns: 1fr; }
            .faq-section, .contact-content { flex-direction: column; }
            .nav-links { display: none; }
            .nav-bar { padding: 20px; }
        }
    </style>
</head>
<body>
    <!-- Demo Notice -->
    <?php echo DemoMode::getDemoNotice(); ?>
    
    <!-- Navigation -->
    <nav class="nav-bar">
        <div class="logo">KVM <span>自动化系统</span></div>
        <div class="nav-links">
            <a href="#features">产品功能</a>
            <a href="#pricing">价格方案</a>
            <a href="#clients">客户案例</a>
            <a href="#faq">FAQ</a>
            <a href="#contact">联系我们</a>
        </div>
        <a href="#contact" class="nav-demo-btn">预约演示</a>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-left">
            <div class="logo">KVM <span>自动化系统</span></div>
            <h1 class="hero-title">集中控制<br>简化运维</h1>
            <p class="hero-subtitle">面向现代数据中心的智能基础设施管理平台，提升效率，降低风险，全面掌控 IT 环境。</p>
            <a href="#contact" class="hero-btn">预约产品演示</a>
        </div>
        <div class="hero-right">
            <div class="hero-overlay"></div>
            <img src="https://trae-api-cn.mchost.guru/api/ide/v1/text_to_image?prompt=data%20center%20server%20room%20with%20blue%20LED%20lights%20and%20network%20equipment&image_size=landscape_16_9" alt="数据中心">
            <div class="hero-stats">
                <div class="stat-item">
                    <div class="stat-value">500+</div>
                    <div class="stat-label">企业用户</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">99.99%</div>
                    <div class="stat-label">系统可用性</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">10k+</div>
                    <div class="stat-label">受管设备</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="features-header">
            <h2 class="features-title">强大功能，<br>全面覆盖运维场景</h2>
            <p class="features-subtitle">通过统一界面访问和控制所有服务器和设备</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🎯</div>
                <h3 class="feature-title">集中访问与控制</h3>
                <p class="feature-desc">通过统一界面访问和控制所有服务器和设备，实现一站式管理。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3 class="feature-title">实时监控与告警</h3>
                <p class="feature-desc">7x24小时实时监控，智能告警，快速响应异常。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h3 class="feature-title">批量操作</h3>
                <p class="feature-desc">支持批量任务执行，固件升级，配置部署更高效。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3 class="feature-title">权限管理</h3>
                <p class="feature-desc">基于角色的细粒度权限控制，保障系统安全。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3 class="feature-title">审计与日志</h3>
                <p class="feature-desc">完整操作审计日志，满足合规与安全审计要求。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔄</div>
                <h3 class="feature-title">多平台兼容</h3>
                <p class="feature-desc">支持主流硬件设备与多种操作系统和调度器。</p>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section class="pricing-section" id="pricing">
        <div class="pricing-header">
            <h2 class="pricing-title">灵活定价，满足不同规模需求</h2>
            <p class="pricing-subtitle">选择适合您业务规模的方案</p>
        </div>
        <div class="pricing-grid">
            <div class="pricing-card">
                <div class="pricing-name">标准版</div>
                <div class="pricing-desc">适合中小型企业基础运维需求</div>
                <div class="pricing-price">¥8,000</div>
                <div class="pricing-period">/年起</div>
                <ul class="pricing-features">
                    <li>支持最多50台设备</li>
                    <li>基础监控与告警</li>
                    <li>标准技术支持</li>
                    <li>Web 访问与控制</li>
                    <li>Web 访问与控制</li>
                </ul>
                <a href="#contact" class="pricing-btn secondary">立即购买</a>
            </div>
            <div class="pricing-card popular">
                <div class="popular-badge">推荐</div>
                <div class="pricing-name">企业版</div>
                <div class="pricing-desc">适合中大型企业复杂运维场景</div>
                <div class="pricing-price">¥20,000</div>
                <div class="pricing-period">/年起</div>
                <ul class="pricing-features">
                    <li>支持最多500台设备</li>
                    <li>高级监控与告警</li>
                    <li>批量操作与自动化</li>
                    <li>优先技术支持</li>
                    <li>优先技术支持</li>
                    <li>权限管理与审计日志</li>
                </ul>
                <a href="#contact" class="pricing-btn primary">立即购买</a>
            </div>
            <div class="pricing-card">
                <div class="pricing-name">定制版</div>
                <div class="pricing-desc">满足大型企业个性化需求</div>
                <div class="pricing-price">定制报价</div>
                <div class="pricing-period"></div>
                <ul class="pricing-features">
                    <li>无限制设备接入</li>
                    <li>专属功能定制</li>
                    <li>专属技术支持</li>
                    <li>现场服务支持</li>
                    <li>现场服务支持</li>
                </ul>
                <a href="#contact" class="pricing-btn secondary">联系我们</a>
            </div>
        </div>
    </section>

    <!-- Clients Section -->
    <section class="clients-section" id="clients">
        <div class="clients-header">
            <h2 class="clients-title">全球企业的信赖之选</h2>
            <p class="clients-subtitle">我们已为众多知名企业提供服务</p>
        </div>
        <div class="clients-grid">
            <div class="client-card">
                <div class="client-logo">📱</div>
                <div class="client-name">中国移动</div>
                <div class="client-desc">通过 KVM 自动化系统实现全国数据中心的统一管理，效率提升40%。</div>
            </div>
            <div class="client-card">
                <div class="client-logo">🏦</div>
                <div class="client-name">招商银行</div>
                <div class="client-desc">实现多数据中心设备的统一管控，保障业务连续性与系统安全性。</div>
            </div>
            <div class="client-card">
                <div class="client-logo">☁️</div>
                <div class="client-name">阿里云</div>
                <div class="client-desc">通过自动化运维工具集成，大幅提升运维效率与管理精度。</div>
            </div>
            <div class="client-card">
                <div class="client-logo">🛒</div>
                <div class="client-name">京东数科</div>
                <div class="client-desc">实现混合云环境下的设备统一管理与监控，优化运维流程。</div>
            </div>
        </div>
    </section>

    <!-- FAQ & Contact Section -->
    <section class="contact-section" id="contact">
        <div class="contact-content">
            <div class="contact-left">
                <h2 class="contact-title">获取更多信息</h2>
                <p class="contact-subtitle">填写表单，我们将尽快与您联系。</p>
                <form class="contact-form" onsubmit="alert('演示模式：表单已提交（模拟）'); return false;">
                    <div class="form-group">
                        <label>您的姓名</label>
                        <input type="text" placeholder="请输入您的姓名">
                    </div>
                    <div class="form-group">
                        <label>工作邮箱</label>
                        <input type="email" placeholder="请输入您的邮箱">
                    </div>
                    <div class="form-group">
                        <label>公司名称</label>
                        <input type="text" placeholder="请输入公司名称">
                    </div>
                    <div class="form-group">
                        <label>联系电话</label>
                        <input type="tel" placeholder="请输入联系电话">
                    </div>
                    <div class="form-group full">
                        <label>您的需求</label>
                        <textarea placeholder="请描述您的需求"></textarea>
                    </div>
                    <button type="submit" class="contact-btn">提交</button>
                </form>
            </div>
            <div class="contact-right" style="background: url('https://trae-api-cn.mchost.guru/api/ide/v1/text_to_image?prompt=modern%20office%20meeting%20room%20with%20glass%20walls&image_size=portrait_4_3') no-repeat center center; background-size: cover; border-radius: 12px;"></div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section" id="faq">
        <div class="faq-left">
            <h2 class="faq-title">常见问题</h2>
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">KVM 自动化系统支持哪些设备？<span class="faq-icon">+</span></div>
                <div class="faq-answer">支持主流品牌的服务器、网络设备、存储设备等，包括戴尔、惠普、联想、华为、浪潮等品牌。</div>
            </div>
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">支持哪些访问方式？<span class="faq-icon">+</span></div>
                <div class="faq-answer">支持 Web 访问、API 接口、命令行工具等多种访问方式，满足不同场景需求。</div>
            </div>
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">系统部署方式有哪些？<span class="faq-icon">+</span></div>
                <div class="faq-answer">支持私有部署、公有云部署、混合云部署等多种方式，灵活适应不同环境。</div>
            </div>
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">如何保障系统安全性？<span class="faq-icon">+</span></div>
                <div class="faq-answer">采用端到端加密传输、细粒度权限控制、完整审计日志等多重安全机制，保障系统安全。</div>
            </div>
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">是否支持与第三方系统集成？<span class="faq-icon">+</span></div>
                <div class="faq-answer">支持与主流监控系统、ITSM 系统、自动化运维平台等第三方系统集成。</div>
            </div>
        </div>
        <div class="contact-right">
            <h2 class="faq-title">获取更多信息</h2>
            <p style="color: #666; margin-bottom: 40px;">填写表单，我们将尽快与您联系。</p>
            <form class="contact-form" style="grid-template-columns: 1fr; gap: 16px;" onsubmit="alert('演示模式：表单已提交（模拟）'); return false;">
                <div class="form-group">
                    <label>您的姓名</label>
                    <input type="text" placeholder="请输入您的姓名" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>工作邮箱</label>
                    <input type="email" placeholder="请输入您的邮箱" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>公司名称</label>
                    <input type="text" placeholder="请输入公司名称" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>联系电话</label>
                    <input type="tel" placeholder="请输入联系电话" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>您的需求</label>
                    <textarea placeholder="请描述您的需求" style="width: 100%;"></textarea>
                </div>
                <button type="submit" class="contact-btn" style="width: 100%;">提交</button>
            </form>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div>
                <div class="footer-brand">KVM 自动化系统</div>
                <p class="footer-desc">KVM 智能基础设施管理方案提供商，专业的基础设施管理解决方案提供商，致力于提升运维效率与系统稳定性。</p>
            </div>
            <div>
                <div class="footer-title">产品</div>
                <ul class="footer-links">
                    <li><a href="#features">产品功能</a></li>
                    <li><a href="#features">产品功能</a></li>
                    <li><a href="#pricing">价格方案</a></li>
                    <li><a href="#">服务对比</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-title">资源</div>
                <ul class="footer-links">
                    <li><a href="#clients">客户案例</a></li>
                    <li><a href="#clients">客户案例</a></li>
                    <li><a href="#">文档中心</a></li>
                    <li><a href="#faq">常见问题</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-title">公司</div>
                <ul class="footer-links">
                    <li><a href="#">关于我们</a></li>
                    <li><a href="#">关于我们</a></li>
                    <li><a href="#">新闻动态</a></li>
                    <li><a href="#contact">联系我们</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="footer-copyright">© 2024 KVM 自动化系统. 保留所有权利.</div>
            <div class="footer-contact">
                <span>📞 400-123-4567</span>
                <span>📞 400-123-4567</span>
                <span>✉️ info@kvm-automation.com</span>
            </div>
            <div class="footer-policies">
                <a href="#">隐私政策</a>
                <a href="#">服务条款</a>
            </div>
        </div>
    </footer>

    <!-- Demo Exit Modal -->
    <div class="demo-overlay" id="demoOverlay">
        <div class="demo-modal">
            <h3>退出演示模式</h3>
            <p style="color: #666; margin-bottom: 20px; font-size: 14px;">请输入演示模式密码以退出：</p>
            <form method="POST">
                <input type="hidden" name="action" value="demo_logout">
                <input type="password" name="password" placeholder="请输入密码" required>
                <?php if (!empty($error_msg)): ?>
                <div style="color: #e94560; font-size: 12px; margin-bottom: 12px;"><?php echo $error_msg; ?></div>
                <?php endif; ?>
                <button type="submit">确认退出</button>
                <button type="button" class="close-btn" onclick="document.getElementById('demoOverlay').style.display='none'">取消</button>
            </form>
        </div>
    </div>

    <script>
        function toggleFaq(el) {
            var answer = el.nextElementSibling;
            var icon = el.querySelector('.faq-icon');
            if (answer.style.display === 'block') {
                answer.style.display = 'none';
                icon.textContent = '+';
            } else {
                answer.style.display = 'block';
                icon.textContent = '-';
            }
        }
    </script>
</body>
</html>