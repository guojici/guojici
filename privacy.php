<?php
require_once __DIR__ . '/config/helper.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>隐私政策 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <div class="dashboard">
        <?php if (auth_check()) include __DIR__ . '/user/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">隐私政策</h1>
                <p class="page-subtitle">我们如何收集、使用和保护您的信息</p>
            </div>

            <div class="card">
                <div style="max-width: 800px; margin: 0 auto; padding: 20px; font-size: 14px; line-height: 1.8; color: #4e5969;">
                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">一、信息收集</h2>
                    <p>我们收集的信息包括：</p>
                    <p>1. <strong>账户信息：</strong>当您注册账户时，我们可能收集您的用户名、电子邮件地址、联系电话等信息。</p>
                    <p>2. <strong>支付信息：</strong>当您购买服务时，我们收集支付相关信息，包括支付金额、支付时间等。支付由第三方支付机构处理，我们不会收集您的完整银行卡信息。</p>
                    <p>3. <strong>使用信息：</strong>我们自动收集您使用服务时的相关信息，包括IP地址、浏览器类型、访问时间、操作系统等。</p>
                    <p>4. <strong>主机数据：</strong>您在使用云服务器时创建的所有数据，包括文件、数据库、内容等，这些数据归您所有。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">二、信息使用</h2>
                    <p>我们使用收集的信息用于以下目的：</p>
                    <p>1. 提供、维护和改进我们的服务</p>
                    <p>2. 处理您的交易和支付</p>
                    <p>3. 向您发送服务相关通知</p>
                    <p>4. 监控和分析服务使用情况</p>
                    <p>5. 预防和应对安全问题和欺诈行为</p>
                    <p>6. 遵守法律法规的要求</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">三、信息共享</h2>
                    <p>我们不会向第三方出售您的个人信息。在以下情况下，我们可能共享您的信息：</p>
                    <p>1. <strong>服务提供商：</strong>我们可能与帮助我们提供服务的第三方共享信息，例如支付处理商、云基础设施提供商等。</p>
                    <p>2. <strong>法律要求：</strong>当法律、法规或政府要求时，我们可能披露您的信息。</p>
                    <p>3. <strong>保护权利：</strong>当我们认为披露信息对保护我们的权利、用户安全或公共安全必要时，我们可能披露信息。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">四、数据安全</h2>
                    <p>我们采用适当的技术和组织措施来保护您的信息，包括：</p>
                    <p>1. 使用加密技术保护数据传输</p>
                    <p>2. 限制员工访问个人信息的权限</p>
                    <p>3. 定期审查信息收集、存储和处理实践</p>
                    <p>4. 使用防火墙和安全监控</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">五、数据保留</h2>
                    <p>我们保留您的信息的时间限于实现本政策所述目的所需的时间，除非法律要求或允许更长的保留期。当您的账户被删除时，我们将在合理时间内删除您的个人信息。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">六、您的权利</h2>
                    <p>根据适用法律，您可能享有以下权利：</p>
                    <p>1. 访问您的个人信息</p>
                    <p>2. 更正不准确的信息</p>
                    <p>3. 删除您的个人信息</p>
                    <p>4. 限制或反对处理您的个人信息</p>
                    <p>5. 数据可携带权</p>
                    <p>如需行使上述权利，请通过客服联系我们。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">七、Cookie政策</h2>
                    <p>我们使用Cookie和类似技术来增强您的用户体验。Cookie是存储在您设备上的小文件，用于记住您的偏好和设置。您可以通过浏览器设置禁用Cookie，但这可能影响部分功能。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">八、未成年人隐私</h2>
                    <p>我们的服务不面向未满18岁的未成年人。我们不会故意收集未成年人的个人信息。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">九、政策更新</h2>
                    <p>我们可能不时更新本隐私政策。任何更新都会在本页面发布。我们鼓励您定期查看本政策以了解最新版本。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">十、联系我们</h2>
                    <p>如您对本隐私政策有任何疑问，请通过客服渠道联系我们。</p>

                    <p style="margin-top: 32px; color: #86909c;">最后更新日期：2024年1月1日</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
