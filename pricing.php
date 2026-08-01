<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KVM自动化系统 - 价格方案</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: #333; line-height: 1.6; }
        
        /* Header */
        .header { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; background: rgba(255,255,255,0.95); box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #165dff; }
        .nav-links { display: flex; gap: 32px; }
        .nav-links a { text-decoration: none; color: #333; font-weight: 500; }
        .nav-links a:hover { color: #165dff; }
        
        /* Hero */
        .hero { padding: 140px 40px 80px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #fff; text-align: center; }
        .hero h1 { font-size: 48px; margin-bottom: 16px; }
        .hero p { font-size: 18px; opacity: 0.9; max-width: 600px; margin: 0 auto; }
        
        /* Pricing Section */
        .pricing-section { padding: 80px 40px; max-width: 1200px; margin: 0 auto; }
        .section-title { text-align: center; font-size: 32px; margin-bottom: 60px; }
        .section-title::after { content: ''; display: block; width: 60px; height: 4px; background: #165dff; margin: 16px auto 0; border-radius: 2px; }
        
        .pricing-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
        .pricing-card { background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); position: relative; }
        .pricing-card.popular { border: 2px solid #165dff; transform: scale(1.05); }
        .popular-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: #165dff; color: #fff; padding: 4px 20px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        
        .pricing-name { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .pricing-desc { font-size: 14px; color: #666; margin-bottom: 24px; }
        
        .pricing-price { font-size: 48px; font-weight: 700; color: #165dff; }
        .pricing-period { font-size: 14px; color: #666; margin-bottom: 32px; }
        
        .pricing-features { list-style: none; margin-bottom: 32px; }
        .pricing-features li { padding: 12px 0; font-size: 14px; color: #333; border-bottom: 1px solid #f2f3f5; }
        .pricing-features li:last-child { border-bottom: none; }
        .pricing-features li::before { content: '✓'; color: #00b42a; margin-right: 8px; }
        
        .pricing-btn { display: block; padding: 16px; text-align: center; text-decoration: none; font-weight: 600; border-radius: 8px; transition: all 0.3s; }
        .pricing-btn.primary { background: #165dff; color: #fff; }
        .pricing-btn.primary:hover { background: #0d47a1; }
        .pricing-btn.secondary { background: #f7f8fa; color: #333; border: 2px solid #e5e8eb; }
        .pricing-btn.secondary:hover { border-color: #165dff; color: #165dff; }
        
        /* Comparison Table */
        .comparison-section { padding: 80px 40px; background: #f7f8fa; }
        .comparison-table { width: 100%; max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .comparison-table th, .comparison-table td { padding: 20px; text-align: left; border-bottom: 1px solid #f2f3f5; }
        .comparison-table th { background: #1a1a2e; color: #fff; font-weight: 600; }
        .comparison-table tr:hover { background: #f7f8fa; }
        
        .feature-name { font-weight: 600; }
        .check-icon { color: #00b42a; font-size: 20px; }
        .cross-icon { color: #ccc; font-size: 20px; }
        
        /* FAQ Section */
        .faq-section { padding: 80px 40px; max-width: 800px; margin: 0 auto; }
        .faq-item { margin-bottom: 16px; }
        .faq-question { padding: 20px; background: #fff; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .faq-question:hover { background: #f7f8fa; }
        .faq-icon { font-size: 20px; color: #165dff; }
        .faq-answer { padding: 20px; background: #fff; border-radius: 8px; display: none; color: #666; border-top: 1px solid #f2f3f5; }
        
        /* Footer */
        .footer { background: #1a1a2e; color: #fff; padding: 60px 40px; margin-top: 60px; }
        .footer-content { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 40px; }
        .footer-brand { font-size: 24px; font-weight: 700; margin-bottom: 16px; }
        .footer-desc { font-size: 14px; color: #aaa; }
        .footer-title { font-size: 14px; font-weight: 600; margin-bottom: 16px; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 8px; }
        .footer-links a { text-decoration: none; color: #aaa; font-size: 14px; }
        .footer-links a:hover { color: #fff; }
        
        @media (max-width: 768px) {
            .pricing-grid { grid-template-columns: 1fr; }
            .pricing-card.popular { transform: none; }
            .comparison-table { overflow-x: auto; }
            .footer-content { grid-template-columns: 1fr; }
            .hero h1 { font-size: 32px; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="logo">KVM 自动化系统</div>
            <div class="nav-links">
                <a href="/">首页</a>
                <a href="/features.php">功能展示</a>
                <a href="/pricing.php">价格方案</a>
                <a href="/#contact">联系我们</a>
            </div>
        </div>
    </div>

    <!-- Hero -->
    <div class="hero">
        <h1>灵活定价，满足不同规模需求</h1>
        <p>选择适合您业务规模的方案，开启智能化运维之旅</p>
    </div>

    <!-- Pricing Section -->
    <div class="pricing-section">
        <h2 class="section-title">价格方案</h2>
        
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
                    <li>虚拟机基础管理</li>
                    <li>单租户模式</li>
                    <li>基础安全防护</li>
                </ul>
                
                <a href="/#contact" class="pricing-btn secondary">立即咨询</a>
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
                    <li>虚拟机全生命周期管理</li>
                    <li>多租户隔离</li>
                    <li>权限管理与审计日志</li>
                    <li>存储快照与备份</li>
                    <li>网络资源管控</li>
                    <li>在线热迁移</li>
                </ul>
                
                <a href="/#contact" class="pricing-btn primary">立即购买</a>
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
                    <li>三级代理体系</li>
                    <li>SaaS商用计费系统</li>
                    <li>开放API对接</li>
                    <li>私有化部署</li>
                    <li>定制化安全策略</li>
                    <li>7×24小时专属服务</li>
                </ul>
                
                <a href="/#contact" class="pricing-btn secondary">联系我们</a>
            </div>
        </div>
    </div>

    <!-- Comparison Section -->
    <div class="comparison-section">
        <h2 class="section-title">功能对比</h2>
        
        <table class="comparison-table">
            <thead>
                <tr>
                    <th>功能特性</th>
                    <th>标准版</th>
                    <th>企业版</th>
                    <th>定制版</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="feature-name">设备管理数量</td>
                    <td>50台</td>
                    <td>500台</td>
                    <td>无限制</td>
                </tr>
                <tr>
                    <td class="feature-name">虚拟机创建/管理</td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">批量操作</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">在线热迁移</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">多租户隔离</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">三级代理体系</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">存储快照与备份</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">网络资源管控(VPC/防火墙)</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">权限管理与审计日志</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">实时监控与告警</td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">计费与账单系统</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">开放API对接</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
                <tr>
                    <td class="feature-name">技术支持响应时间</td>
                    <td>工作日 8-18点</td>
                    <td>7×24小时</td>
                    <td>专属服务</td>
                </tr>
                <tr>
                    <td class="feature-name">现场服务</td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="cross-icon">✗</span></td>
                    <td><span class="check-icon">✓</span></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- FAQ Section -->
    <div class="faq-section">
        <h2 class="section-title">常见问题</h2>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">价格是否包含技术支持？<span class="faq-icon">+</span></div>
            <div class="faq-answer">标准版包含工作日技术支持，企业版和定制版包含7×24小时技术支持。所有版本均包含系统更新和安全补丁。</div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">是否支持私有化部署？<span class="faq-icon">+</span></div>
            <div class="faq-answer">标准版和企业版支持云部署，定制版支持私有化部署，可部署在您的本地数据中心或私有云环境中。</div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">设备数量是否可以升级？<span class="faq-icon">+</span></div>
            <div class="faq-answer">是的，您可以随时升级套餐以支持更多设备。升级时将按照剩余服务时间进行差价计算。</div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">是否提供免费试用？<span class="faq-icon">+</span></div>
            <div class="faq-answer">我们提供14天免费试用，包含完整功能体验。试用期间我们的技术团队将为您提供一对一的产品讲解和配置指导。</div>
        </div>
        
        <div class="faq-item">
            <div class="faq-question" onclick="toggleFaq(this)">如何获取报价？<span class="faq-icon">+</span></div>
            <div class="faq-answer">标准版和企业版价格透明，可直接购买。定制版需要根据您的具体需求进行报价，请联系我们的销售团队获取定制方案。</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-content">
            <div>
                <div class="footer-brand">KVM 自动化系统</div>
                <p class="footer-desc">专业的基础设施管理解决方案提供商，致力于提升运维效率与系统稳定性</p>
            </div>
            <div>
                <div class="footer-title">产品</div>
                <ul class="footer-links">
                    <li><a href="/features.php">功能展示</a></li>
                    <li><a href="/pricing.php">价格方案</a></li>
                    <li><a href="/#contact">联系我们</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-title">资源</div>
                <ul class="footer-links">
                    <li><a href="#">帮助文档</a></li>
                    <li><a href="#">API文档</a></li>
                    <li><a href="#">常见问题</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-title">联系方式</div>
                <div class="footer-desc">
                    电话: <?php echo db_get_setting('contact_phone', '400-123-4567'); ?><br>
                    邮箱: <?php echo db_get_setting('contact_email', 'info@kvm-automation.com'); ?>
                </div>
            </div>
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