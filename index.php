<?php
define('FORCE_DEBUG', true);
$is_installed = file_exists(__DIR__ . '/config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/config/helper.php';

// 演示模式检查
if (file_exists(__DIR__ . '/config/DemoMode.php')) {
    require_once __DIR__ . '/config/DemoMode.php';
    if (DemoMode::isEnabled()) {
        header('Location: /demo/');
        exit;
    }
}

$packages = Database::fetchAll("SELECT * FROM packages WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
$site_theme = db_get_setting('site_theme', 'business');
?>
<!DOCTYPE html>
<html lang="<?php echo lang_html_attr(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo e(config('site.description')); ?>">
    <meta name="keywords" content="<?php echo e(config('site.keywords')); ?>">
    <title><?php echo e(config('site.title')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body data-theme="<?php echo $site_theme; ?>">
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <!-- Hero 区域 -->
    <section class="hero-section">
        <div class="hero-content">
            <div class="hero-text">
                <span class="hero-badge"><?php echo __('hero.badge'); ?></span>
                <h1><?php echo __('hero.title_1'); ?><br><?php echo __('hero.title_2'); ?></h1>
                <p><?php echo __('hero.desc'); ?></p>
                <div class="hero-actions">
                    <a href="#pricing" class="btn btn-primary"><?php echo __('hero.demo'); ?></a>
                    <?php if (!auth_check()): ?>
                        <a href="/register.php" class="btn btn-secondary"><?php echo __('hero.free_register'); ?></a>
                    <?php else: ?>
                        <a href="/user/hosts.php" class="btn btn-secondary"><?php echo __('hero.enter_console'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-stats">
                <div class="hero-stat-item">
                    <div class="hero-stat-number">500+</div>
                    <div class="hero-stat-label"><?php echo __('hero.stat_users'); ?></div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat-item">
                    <div class="hero-stat-number">99.99%</div>
                    <div class="hero-stat-label"><?php echo __('hero.stat_uptime'); ?></div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat-item">
                    <div class="hero-stat-number">10k+</div>
                    <div class="hero-stat-label"><?php echo __('hero.stat_devices'); ?></div>
                </div>
            </div>
        </div>
        <div class="hero-image">
            <img src="https://trae-api-cn.mchost.guru/api/ide/v1/text_to_image?prompt=modern%20server%20room%20data%20center%20with%20blue%20LED%20lights%2C%20professional%20IT%20engineer%20managing%20servers%2C%20dark%20atmosphere%2C%20cinematic%20wide%20angle%20shot&image_size=landscape_16_9" alt="数据中心">
        </div>
    </section>

    <!-- 产品功能区域 -->
    <section class="features-section">
        <div class="section-header">
            <h2><?php echo __('features.title'); ?></h2>
            <p><?php echo __('features.desc'); ?></p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🔗</div>
                <h3>集中访问与控制</h3>
                <p>通过统一界面访问和控制所有服务器和设备</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>实时监控与告警</h3>
                <p>7×24小时实时监控，智能告警，快速响应异常</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h3>批量操作</h3>
                <p>支持批量任务执行、固件升级、配置部署更高效</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3>权限管理</h3>
                <p>基于角色的细粒度权限控制，保障系统安全</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3>审计与日志</h3>
                <p>完整操作审计日志，满足合规与安全审计要求</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🌐</div>
                <h3>多平台兼容</h3>
                <p>支持主流硬件设备与多种操作系统和调度器</p>
            </div>
        </div>
    </section>

    <!-- 价格方案区域 -->
    <section class="pricing-section" id="pricing">
        <div class="section-header">
            <h2>灵活定价，满足不同规模需求</h2>
            <p>多种配置方案，满足不同规模的业务需求</p>
        </div>
        <div class="pricing-grid">
            <?php
            $pkg_index = 0;
            foreach ($packages as $pkg):
                $pkg_index++;
                $is_recommended = $pkg['is_recommended'] ? true : ($pkg_index === 2);
            ?>
            <div class="price-card <?php echo $is_recommended ? 'recommended' : ''; ?>">
                <?php if ($is_recommended): ?>
                    <div class="price-badge">推荐</div>
                <?php endif; ?>
                <h3><?php echo e($pkg['name']); ?></h3>
                <p class="price-desc"><?php echo e($pkg['description'] ?: '适合中小型企业基础运维需求'); ?></p>
                <div class="price">
                    <span class="currency">¥</span>
                    <span class="amount"><?php echo $pkg['price_monthly']; ?></span>
                    <span class="period">/年起</span>
                </div>
                <ul class="price-features">
                    <?php if (!empty($pkg['is_kvm'])): ?>
                        <li>💻 <?php echo intval($pkg['kvm_vcpu']); ?> 核 vCPU</li>
                        <li>🧠 <?php echo intval($pkg['kvm_memory_mb']); ?> MB 内存</li>
                        <li>💾 <?php echo intval($pkg['kvm_disk_gb']); ?> GB SSD 磁盘</li>
                        <li>🌐 <?php echo intval($pkg['kvm_bandwidth_mbps']); ?> Mbps 峰值带宽</li>
                        <li>🔑 完整 Root 权限</li>
                        <li>🛡️ 独立公网 IP</li>
                    <?php else: ?>
                        <li><?php echo $pkg['webdx']; ?> MB 网页空间</li>
                        <li><?php echo $pkg['sqldx']; ?> MB 数据库空间</li>
                        <li><?php echo $pkg['sizemax']; ?> GB 月流量</li>
                        <li><?php echo $pkg['ymbds']; ?> 个域名绑定</li>
                        <li>PHP / MySQL 支持</li>
                        <li>免费技术支持</li>
                    <?php endif; ?>
                </ul>
                <a href="/checkout.php?package_id=<?php echo $pkg['id']; ?>" class="btn <?php echo $is_recommended ? 'btn-primary' : 'btn-outline'; ?>">立即购买</a>
            </div>
            <?php endforeach; ?>

            <?php if (empty($packages)): ?>
            <div class="price-card">
                <h3>标准版</h3>
                <p class="price-desc">适合中小型企业基础运维需求</p>
                <div class="price">
                    <span class="currency">¥</span>
                    <span class="amount">8,000</span>
                    <span class="period">/年起</span>
                </div>
                <ul class="price-features">
                    <li>支持最多 50 台设备</li>
                    <li>基础监控与告警</li>
                    <li>标准技术支持</li>
                    <li>Web 访问与控制</li>
                </ul>
                <a href="/register.php" class="btn btn-outline">立即购买</a>
            </div>
            <div class="price-card recommended">
                <div class="price-badge">推荐</div>
                <h3>企业版</h3>
                <p class="price-desc">适合中大型企业复杂运维场景</p>
                <div class="price">
                    <span class="currency">¥</span>
                    <span class="amount">20,000</span>
                    <span class="period">/年起</span>
                </div>
                <ul class="price-features">
                    <li>支持最多 500 台设备</li>
                    <li>高级监控与告警</li>
                    <li>批量操作与自动化</li>
                    <li>优先技术支持</li>
                    <li>权限管理与审计日志</li>
                </ul>
                <a href="/register.php" class="btn btn-primary">立即购买</a>
            </div>
            <div class="price-card">
                <h3>定制版</h3>
                <p class="price-desc">满足大型企业个性化需求</p>
                <div class="price">
                    <span class="currency">定制</span>
                    <span class="amount">报价</span>
                    <span class="period"></span>
                </div>
                <ul class="price-features">
                    <li>无限制设备接入</li>
                    <li>专属功能定制</li>
                    <li>专属技术支持</li>
                    <li>现场服务支持</li>
                </ul>
                <a href="#contact" class="btn btn-outline">联系我们</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="pricing-footer">
            <span>需要帮助选择？</span>
            <a href="#contact">我们的专家将根据您的需求为您推荐最合适的解决方案</a>
        </div>
    </section>

    <!-- 客户案例区域 -->
    <section class="clients-section">
        <div class="section-header">
            <h2>全球企业的信赖之选</h2>
            <p>众多知名企业选择我们的解决方案</p>
        </div>
        <div class="clients-grid">
            <div class="client-item">
                <div class="client-logo">中国移动</div>
                <p>中国移动自动化系统实现全国数据中心设备统一管控，效率提升 40%，运维响应时间降低 60%。</p>
            </div>
            <div class="client-item">
                <div class="client-logo">招商银行</div>
                <p>实现多数据中心设备的统一管控，保障业务连续性与系统安全性。</p>
            </div>
            <div class="client-item">
                <div class="client-logo">阿里云</div>
                <p>通过自动化运维工具集集成，大幅提升效率与管理精度。</p>
            </div>
            <div class="client-item">
                <div class="client-logo">京东数科</div>
                <p>实现混合云环境下的设备统一管理与监控。</p>
            </div>
        </div>
    </section>

    <!-- FAQ区域 -->
    <section class="faq-section">
        <div class="section-header">
            <h2>常见问题</h2>
            <p>解答您的疑问</p>
        </div>
        <div class="faq-container">
            <div class="faq-item">
                <button class="faq-question" onclick="toggleFaq(this)">KVM 自动化系统支持哪些设备？</button>
                <div class="faq-answer">
                    <p>我们的系统支持主流品牌的服务器、网络设备、存储设备，包括但不限于戴尔、惠普、IBM、华为、浪潮等品牌的硬件设备。同时支持多种虚拟化平台和操作系统。</p>
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-question" onclick="toggleFaq(this)">部署方式有哪些？</button>
                <div class="faq-answer">
                    <p>我们提供多种部署方式：私有云部署（部署在您的数据中心）、公有云部署（我们的云平台）、混合部署。您可以根据企业安全需求和IT架构选择最合适的部署方式。</p>
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-question" onclick="toggleFaq(this)">如何保障系统安全性？</button>
                <div class="faq-answer">
                    <p>我们采用多层安全防护机制：端到端加密传输、细粒度权限控制、完整操作审计日志、定期安全漏洞扫描、数据备份与灾备方案。通过 ISO 27001 信息安全管理体系认证。</p>
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-question" onclick="toggleFaq(this)">是否支持与第三方系统集成？</button>
                <div class="faq-answer">
                    <p>是的，我们提供丰富的 API 接口和集成方案，支持与企业现有 ITSM、CMDB、监控系统、自动化工具等第三方系统集成，实现无缝对接。</p>
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-question" onclick="toggleFaq(this)">技术支持服务包括哪些内容？</button>
                <div class="faq-answer">
                    <p>我们提供全方位技术支持：7×24 小时在线支持、定期系统巡检、问题快速响应、版本升级服务、定制化培训、专属客户成功经理。企业版及以上提供专属技术支持热线。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 联系表单区域 -->
    <section class="contact-section" id="contact">
        <div class="contact-content">
            <div class="contact-text">
                <h2>获取更多信息</h2>
                <p>填写表单，我们将尽快与您联系。</p>
            </div>
            <form class="contact-form" onsubmit="return submitContactForm(this)">
                <div class="form-row">
                    <div class="form-group">
                        <label>您的姓名</label>
                        <input type="text" name="name" class="form-control" placeholder="请输入姓名" required>
                    </div>
                    <div class="form-group">
                        <label>工作邮箱</label>
                        <input type="email" name="email" class="form-control" placeholder="请输入邮箱" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>公司名称</label>
                        <input type="text" name="company" class="form-control" placeholder="请输入公司名称">
                    </div>
                    <div class="form-group">
                        <label>联系电话</label>
                        <input type="tel" name="phone" class="form-control" placeholder="请输入联系电话">
                    </div>
                </div>
                <div class="form-group">
                    <label>您的需求</label>
                    <textarea name="message" class="form-control" placeholder="请描述您的需求..." rows="4"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">提交</button>
            </form>
        </div>
    </section>

    <?php include __DIR__ . '/templates/footer.php'; ?>

    <script>
    function toggleFaq(btn) {
        const answer = btn.nextElementSibling;
        const icon = btn.querySelector('.faq-icon');
        const allAnswers = document.querySelectorAll('.faq-answer');
        const allIcons = document.querySelectorAll('.faq-icon');
        
        allAnswers.forEach(a => {
            if (a !== answer) {
                a.style.display = 'none';
            }
        });
        
        allIcons.forEach(i => {
            if (i !== icon) {
                i.textContent = '+';
            }
        });
        
        if (answer.style.display === 'block') {
            answer.style.display = 'none';
            icon.textContent = '+';
        } else {
            answer.style.display = 'block';
            icon.textContent = '-';
        }
    }

    function submitContactForm(form) {
        const data = new FormData(form);
        fetch('/api/contact.php', {
            method: 'POST',
            body: data
        }).then(res => res.json()).then(result => {
            if (result.success) {
                alert('提交成功！我们将尽快与您联系。');
                form.reset();
            } else {
                alert('提交失败，请稍后重试。');
            }
        }).catch(() => {
            alert('网络错误，请稍后重试。');
        });
        return false;
    }
    </script>
</body>
</html>