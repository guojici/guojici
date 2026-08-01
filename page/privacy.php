<?php
require_once __DIR__ . '/../config/helper.php';
$title = '隐私协议 - ' . config('app.name');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .page-container { max-width: 900px; margin: 40px auto; padding: 40px; background: var(--bg-card); border-radius: 12px; }
        .page-container h1 { font-size: 28px; margin-bottom: 24px; color: var(--text-primary); }
        .page-container h2 { font-size: 20px; margin: 32px 0 16px; color: var(--text-primary); }
        .page-container p, .page-container ul { font-size: 14px; line-height: 2; color: var(--text-secondary); margin-bottom: 16px; }
        .page-container ul { padding-left: 20px; }
        .page-container li { margin-bottom: 8px; }
        .update-time { font-size: 12px; color: var(--text-secondary); margin-bottom: 24px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="page-container">
        <h1>隐私协议</h1>
        <div class="update-time">更新日期：<?php echo date('Y年m月d日'); ?></div>

        <p>欢迎使用我们的服务。我们非常重视您的隐私保护，本隐私协议将向您说明我们如何收集、使用、存储和保护您的个人信息。</p>

        <h2>一、信息收集</h2>
        <p>在您使用我们的服务时，我们可能会收集以下类型的信息：</p>
        <ul>
            <li><strong>账户信息</strong>：用户名、邮箱地址、密码（加密存储）</li>
            <li><strong>交易信息</strong>：订单记录、支付信息、消费金额</li>
            <li><strong>设备信息</strong>：IP地址、设备指纹、浏览器类型</li>
            <li><strong>服务信息</strong>：虚拟主机配置、域名绑定、服务器资源使用情况</li>
        </ul>

        <h2>二、信息使用</h2>
        <p>我们收集的信息将用于：</p>
        <ul>
            <li>提供、维护和改进我们的服务</li>
            <li>处理您的订单和支付</li>
            <li>向您发送服务通知和重要更新</li>
            <li>防止欺诈和保障账户安全</li>
            <li>遵守法律法规的要求</li>
        </ul>

        <h2>三、信息存储</h2>
        <p>您的信息将存储在安全的服务器上，我们采取多种安全措施保护您的数据：</p>
        <ul>
            <li>所有敏感数据均采用加密存储</li>
            <li>使用HTTPS加密传输</li>
            <li>定期安全审计和漏洞扫描</li>
            <li>访问控制和日志监控</li>
        </ul>

        <h2>四、信息共享</h2>
        <p>我们不会向第三方出售您的个人信息。但在以下情况下，我们可能会共享您的信息：</p>
        <ul>
            <li>获得您的明确同意后</li>
            <li>与支付服务商合作完成交易处理</li>
            <li>法律法规要求或政府机关依法要求</li>
            <li>保护我们或用户的合法权益</li>
        </ul>

        <h2>五、您的权利</h2>
        <p>您对您的个人信息享有以下权利：</p>
        <ul>
            <li>查询和访问您的个人信息</li>
            <li>更正不准确的信息</li>
            <li>删除您的账户和相关数据</li>
            <li>撤回同意或选择退出某些数据处理活动</li>
        </ul>

        <h2>六、Cookie政策</h2>
        <p>我们使用Cookie和类似技术来：</p>
        <ul>
            <li>保持您的登录状态</li>
            <li>记住您的偏好设置</li>
            <li>分析网站流量和使用情况</li>
            <li>提供个性化的用户体验</li>
        </ul>

        <h2>七、未成年人保护</h2>
        <p>我们的服务不面向18岁以下的未成年人。如果我们发现在未获得监护人同意的情况下收集了未成年人的个人信息，我们将尽快删除相关信息。</p>

        <h2>八、协议更新</h2>
        <p>我们可能会不时更新本隐私协议。更新后的协议将在网站上发布，重大变更将通过邮件或站内通知告知您。继续使用我们的服务即表示您接受更新后的协议。</p>

        <h2>九、联系我们</h2>
        <p>如果您对本隐私协议有任何疑问或建议，请通过以下方式联系我们：</p>
        <ul>
            <li>邮箱：<?php echo e(config('app.email', 'support@example.com')); ?></li>
            <li>网站：<?php echo e(config('app.url', $_SERVER['HTTP_HOST'])); ?></li>
        </ul>
    </div>
</body>
</html>