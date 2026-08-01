<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KVM自动化系统 - 功能展示</title>
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
        .hero { padding: 140px 40px 80px; background: linear-gradient(135deg, #165dff 0%, #00b42a 100%); color: #fff; text-align: center; }
        .hero h1 { font-size: 48px; margin-bottom: 16px; }
        .hero p { font-size: 18px; opacity: 0.9; max-width: 600px; margin: 0 auto 32px; }
        
        /* Feature Categories */
        .feature-section { padding: 80px 40px; max-width: 1200px; margin: 0 auto; }
        .section-title { text-align: center; font-size: 32px; margin-bottom: 60px; }
        .section-title::after { content: ''; display: block; width: 60px; height: 4px; background: #165dff; margin: 16px auto 0; border-radius: 2px; }
        
        .feature-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
        .feature-card { background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); transition: all 0.3s; }
        .feature-card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
        .feature-icon { width: 64px; height: 64px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 20px; }
        .icon-purple { background: #e8f3ff; color: #165dff; }
        .icon-green { background: #e8ffea; color: #00b42a; }
        .icon-orange { background: #fff7e8; color: #ff7d00; }
        .icon-red { background: #ffece8; color: #f53f3f; }
        .icon-blue { background: #e6f7ff; color: #1890ff; }
        .icon-yellow { background: #fffbe6; color: #faad14; }
        .feature-card h3 { font-size: 18px; margin-bottom: 12px; }
        .feature-card p { font-size: 14px; color: #666; line-height: 1.8; }
        .feature-list { list-style: none; margin-top: 16px; }
        .feature-list li { padding: 8px 0; font-size: 13px; color: #666; }
        .feature-list li::before { content: '✓'; color: #00b42a; margin-right: 8px; }
        
        /* Category Banner */
        .category-banner { padding: 40px; border-radius: 12px; margin-bottom: 40px; }
        .category-banner h2 { font-size: 28px; margin-bottom: 8px; }
        .category-banner p { font-size: 14px; opacity: 0.8; }
        
        .bg-purple { background: linear-gradient(135deg, #1a1a2e, #16213e); color: #fff; }
        .bg-green { background: linear-gradient(135deg, #00b42a, #165dff); color: #fff; }
        .bg-orange { background: linear-gradient(135deg, #ff7d00, #ffaa00); color: #fff; }
        .bg-blue { background: linear-gradient(135deg, #1890ff, #00b42a); color: #fff; }
        .bg-red { background: linear-gradient(135deg, #f53f3f, #ff7d00); color: #fff; }
        .bg-gray { background: linear-gradient(135deg, #4e5969, #86909c); color: #fff; }
        
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
            .feature-grid { grid-template-columns: 1fr; }
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
        <h1>强大功能，全面覆盖</h1>
        <p>面向现代数据中心的智能基础设施管理平台，提升效率，降低风险，全面掌控 IT 环境</p>
    </div>

    <!-- Feature Section 1: 多租户管理 -->
    <div class="feature-section">
        <div class="category-banner bg-purple">
            <h2>🏢 多租户SaaS管理</h2>
            <p>专为SaaS托管场景设计，实现租户完全隔离、分级管控，适配商用出租模式</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-purple">🔒</div>
                <h3>租户数据隔离</h3>
                <p>租户仅可查看、管理自身名下虚拟机与资源，数据、网络、存储完全隔离</p>
                <ul class="feature-list">
                    <li>独立账号体系</li>
                    <li>跨租户访问拦截</li>
                    <li>资源配额管控</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-green">👤</div>
                <h3>角色权限分级</h3>
                <p>多级权限体系，支持自定义权限组、操作日志溯源、账号安全审计</p>
                <ul class="feature-list">
                    <li>超级管理员</li>
                    <li>运维管理员</li>
                    <li>财务管理员</li>
                    <li>普通租户</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-orange">📊</div>
                <h3>资源配额管控</h3>
                <p>自定义单租户CPU、内存、磁盘、带宽最大配额，防止资源超配</p>
                <ul class="feature-list">
                    <li>配额设置与监控</li>
                    <li>超配预警</li>
                    <li>自助续费升级</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Feature Section 2: KVM管理 -->
    <div class="feature-section" style="background: #f7f8fa;">
        <div class="category-banner bg-green">
            <h2>🖥️ KVM虚拟机管理</h2>
            <p>覆盖虚拟机从创建到销毁的全流程自动化管理，支持批量运维操作</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-green">⚡</div>
                <h3>全生命周期管理</h3>
                <p>支持开机、关机、重启、重装系统、重置密码、升降配、销毁回收等操作</p>
                <ul class="feature-list">
                    <li>Cloud-init初始化</li>
                    <li>系统模板部署</li>
                    <li>自定义配置</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-blue">⏯️</div>
                <h3>高级运维操作</h3>
                <p>支持虚拟机暂停、休眠、恢复运行，适配业务临时启停需求</p>
                <ul class="feature-list">
                    <li>暂停/恢复</li>
                    <li>休眠/唤醒</li>
                    <li>在线热迁移</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-orange">📦</div>
                <h3>批量运维</h3>
                <p>支持批量创建、开机、关机、重装、迁移，大幅提升运维效率</p>
                <ul class="feature-list">
                    <li>批量创建</li>
                    <li>批量操作</li>
                    <li>批量迁移</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Feature Section 3: 存储管理 -->
    <div class="feature-section">
        <div class="category-banner bg-blue">
            <h2>💾 存储资源管理</h2>
            <p>支持多存储模式融合管理，内置完善的快照与备份体系</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-blue">🗄️</div>
                <h3>多存储模式</h3>
                <p>兼容raw、qcow2、vmdk、vdi等主流磁盘格式，支持本地/NFS/Ceph存储</p>
                <ul class="feature-list">
                    <li>动态扩容</li>
                    <li>数据盘独立管控</li>
                    <li>格式转换</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-yellow">📸</div>
                <h3>快照体系</h3>
                <p>支持手动快照、定时自动快照，可自定义保留时长与数量</p>
                <ul class="feature-list">
                    <li>手动快照</li>
                    <li>定时自动快照</li>
                    <li>一键回滚恢复</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-green">🔄</div>
                <h3>备份管理</h3>
                <p>支持整机备份、目录定点备份，备份文件独立存储，支持跨节点恢复</p>
                <ul class="feature-list">
                    <li>整机备份</li>
                    <li>增量备份</li>
                    <li>跨节点恢复</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Feature Section 4: 网络管理 -->
    <div class="feature-section" style="background: #f7f8fa;">
        <div class="category-banner bg-orange">
            <h2>🌐 网络资源管控</h2>
            <p>基于OVS虚拟网络技术搭建隔离网络体系，支持VPC、子网、防火墙</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-orange">🏠</div>
                <h3>VPC私有网络</h3>
                <p>支持VPC私有网络、子网划分、独立私网IP分配，实现租户私网完全隔离</p>
                <ul class="feature-list">
                    <li>VPC创建与管理</li>
                    <li>子网划分</li>
                    <li>私网IP分配</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-red">🔐</div>
                <h3>防火墙策略</h3>
                <p>支持端口放行、IP黑白名单、协议限制、访问策略自定义</p>
                <ul class="feature-list">
                    <li>端口规则</li>
                    <li>IP黑白名单</li>
                    <li>协议限制</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-blue">📡</div>
                <h3>弹性IP管理</h3>
                <p>支持公网IP绑定、弹性IP灵活挂载与解绑，支持带宽限速</p>
                <ul class="feature-list">
                    <li>弹性IP分配</li>
                    <li>带宽限速</li>
                    <li>流量统计</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Feature Section 5: 监控与计费 -->
    <div class="feature-section">
        <div class="category-banner bg-red">
            <h2>📊 监控与计费</h2>
            <p>全维度资源监控与可视化，支持多样化计费模式和财务报表</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-red">📈</div>
                <h3>实时监控</h3>
                <p>实时采集CPU、内存、磁盘IO、网络流量等核心数据，生成可视化趋势图表</p>
                <ul class="feature-list">
                    <li>资源监控</li>
                    <li>趋势图表</li>
                    <li>自定义告警</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-green">💰</div>
                <h3>计费管理</h3>
                <p>支持按时/按天/按月/按年计费，支持带宽按量计费、流量按量计费</p>
                <ul class="feature-list">
                    <li>多样化计费</li>
                    <li>自动账单生成</li>
                    <li>在线充值</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-yellow">📋</div>
                <h3>财务报表</h3>
                <p>支持免费试用、优惠套餐、批量折扣等运营配置，财务端可统一对账</p>
                <ul class="feature-list">
                    <li>运营报表</li>
                    <li>账单导出</li>
                    <li>财务对账</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Feature Section 6: 安全与代理 -->
    <div class="feature-section" style="background: #f7f8fa;">
        <div class="category-banner bg-gray">
            <h2>🛡️ 安全与代理体系</h2>
            <p>全方位安全保障与三级代理体系，支持分销返利与营销活动</p>
        </div>
        
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon icon-red">🔒</div>
                <h3>安全防护</h3>
                <p>传输层采用TLS端到端加密，接口访问采用JWT令牌认证，完整操作日志审计</p>
                <ul class="feature-list">
                    <li>TLS加密传输</li>
                    <li>JWT认证</li>
                    <li>操作日志审计</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-purple">🤝</div>
                <h3>三级代理体系</h3>
                <p>支持超级总代、高级代理、普通代理三级权限，实现层级隔离、利润分层</p>
                <ul class="feature-list">
                    <li>权限分级隔离</li>
                    <li>阶梯定价</li>
                    <li>下级代理管控</li>
                </ul>
            </div>
            <div class="feature-card">
                <div class="feature-icon icon-green">🎁</div>
                <h3>营销与推广</h3>
                <p>支持分销返利、营销活动配置、专属推广链接，助力代理快速拓客</p>
                <ul class="feature-list">
                    <li>分销返利机制</li>
                    <li>营销活动配置</li>
                    <li>专属推广链接</li>
                </ul>
            </div>
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
</body>
</html>