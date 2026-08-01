<?php
require_once __DIR__ . '/../config/helper.php';
$title = '服务政策 - ' . config('app.name');
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
        .highlight { background: rgba(250, 173, 20, 0.1); padding: 12px 16px; border-radius: 6px; margin: 16px 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="page-container">
        <h1>服务政策</h1>
        <div class="update-time">更新日期：<?php echo date('Y年m月d日'); ?></div>

        <p>欢迎使用我们的云服务。本服务政策规定了您使用我们服务的条款和条件。请在注册和使用服务前仔细阅读。</p>

        <h2>一、服务范围</h2>
        <p>我们提供以下云服务产品：</p>
        <ul>
            <li><strong>虚拟主机</strong>：基于Web服务器的高性能网站托管服务</li>
            <li><strong>KVM云服务器</strong>：独立虚拟机实例，支持自定义配置</li>
            <li><strong>域名注册</strong>：提供域名查询、注册和管理服务</li>
            <li><strong>SSL证书</strong>：提供网站安全证书服务</li>
        </ul>

        <h2>二、账户注册</h2>
        <p>使用我们的服务需要注册账户：</p>
        <ul>
            <li>您需要提供真实、准确的信息</li>
            <li>每个用户只能注册一个账户</li>
            <li>您有责任保护账户安全，不得与他人共享密码</li>
            <li>账户内的所有活动由账户持有人负责</li>
        </ul>

        <h2>三、服务费用</h2>
        <ul>
            <li>服务费用以人民币（CNY）计价</li>
            <li>支持支付宝、微信支付等多种支付方式</li>
            <li>服务开通后不支持退款（特殊情况下可申请部分退款）</li>
            <li>账户余额可随时申请提现</li>
        </ul>

        <div class="highlight">
            <strong>注意：</strong>服务到期前7天系统将发送续费提醒，如未及时续费，服务将在到期后24小时内暂停。
        </div>

        <h2>四、服务保障</h2>
        <p>我们承诺提供稳定可靠的服务：</p>
        <ul>
            <li><strong>可用性保证</strong>：99.9%服务可用性</li>
            <li><strong>数据备份</strong>：每日自动备份，保留7天</li>
            <li><strong>技术支持</strong>：提供在线客服和工单支持</li>
            <li><strong>故障响应</strong>：紧急故障2小时内响应处理</li>
        </ul>

        <h2>五、使用规范</h2>
        <p>您同意不会利用我们的服务从事以下活动：</p>
        <ul>
            <li>发布违法、侵权、欺诈或有害信息</li>
            <li>传播病毒、木马或其他恶意软件</li>
            <li>进行网络攻击、垃圾邮件发送等行为</li>
            <li>侵犯他人知识产权或隐私权</li>
            <li>托管钓鱼网站或进行网络诈骗</li>
            <li>从事任何违反中国法律法规的活动</li>
        </ul>

        <h2>六、违约处理</h2>
        <p>如您违反上述规定，我们将采取以下措施：</p>
        <ul>
            <li><strong>警告</strong>：首次违规将发送警告通知</li>
            <li><strong>暂停</strong>：严重违规将暂停服务</li>
            <li><strong>终止</strong>：情节严重将终止服务并封禁账户</li>
            <li><strong>法律追责</strong>：涉及违法犯罪的将移交司法机关</li>
        </ul>

        <h2>七、知识产权</h2>
        <ul>
            <li>您上传至服务器的数据、代码和内容的知识产权归您所有</li>
            <li>您授权我们为提供服务而存储、传输和处理您的数据</li>
            <li>我们平台的设计、代码和品牌归我们所有</li>
        </ul>

        <h2>八、免责声明</h2>
        <p>以下情况我们不承担责任：</p>
        <ul>
            <li>因不可抗力（如自然灾害、战争等）导致的服务中断</li>
            <li>因第三方原因（如电力故障、网络中断）导致的服务问题</li>
            <li>因您自身操作不当导致的数据丢失</li>
            <li>因您违反服务条款导致的任何损失</li>
        </ul>

        <h2>九、协议变更</h2>
        <p>我们有权根据业务需要修改本服务政策。修改后的政策将在网站公布，重大变更将通过邮件或站内通知告知您。继续使用服务即表示您接受变更后的政策。</p>

        <h2>十、争议解决</h2>
        <p>因本协议引起的任何争议，双方应友好协商解决。协商不成的，可向我们所在地有管辖权的人民法院提起诉讼。</p>

        <h2>十一、联系我们</h2>
        <p>如有任何问题或建议，请通过以下方式联系我们：</p>
        <ul>
            <li>邮箱：<?php echo e(config('app.email', 'support@example.com')); ?></li>
            <li>网站：<?php echo e(config('app.url', $_SERVER['HTTP_HOST'])); ?></li>
        </ul>
    </div>
</body>
</html>