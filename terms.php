<?php
require_once __DIR__ . '/config/helper.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务协议 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <div class="dashboard">
        <?php if (auth_check()) include __DIR__ . '/user/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">服务协议</h1>
                <p class="page-subtitle">请仔细阅读本服务协议</p>
            </div>

            <div class="card">
                <div style="max-width: 800px; margin: 0 auto; padding: 20px; font-size: 14px; line-height: 1.8; color: #4e5969;">
                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">一、服务说明</h2>
                    <p>本服务协议（以下简称"协议"）规定了用户与 <?php echo config('app.name'); ?>（以下简称"服务商"）之间的权利义务关系。用户在使用服务商提供的云服务器服务前，应仔细阅读本协议的全部内容。用户一旦购买或使用服务商提供的服务，即视为用户已阅读、理解并同意接受本协议的全部内容。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">二、服务内容</h2>
                    <p>服务商向用户提供以下服务：云服务器租用、存储服务、网络带宽服务以及其他相关技术服务。服务商有权根据实际情况调整服务内容和费用标准，但应提前通知用户。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">三、用户权利与义务</h2>
                    <p>1. 用户承诺遵守中华人民共和国的法律法规，不得利用服务从事任何违法活动，包括但不限于：入侵他人系统、传播恶意软件、发送垃圾邮件等。</p>
                    <p>2. 用户不得利用服务搭建或支持任何违反法律法规的网站或服务。</p>
                    <p>3. 用户需妥善保管账号信息，因保管不当导致的一切损失由用户自行承担。</p>
                    <p>4. 用户有权在服务期限内正常使用所购买的服务。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">四、服务商权利与义务</h2>
                    <p>1. 服务商承诺为用户提供稳定可靠的服务，但不对以下情况负责：不可抗力、网络运营商原因、黑客攻击等。</p>
                    <p>2. 服务商有权根据法律法规或相关部门要求，对用户数据进行审查或披露。</p>
                    <p>3. 如用户违反本协议，服务商有权暂停或终止服务。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">五、费用与支付</h2>
                    <p>1. 用户需按照服务商公布的收费标准支付费用。</p>
                    <p>2. 服务费用按月/年计费，到期后如需续费请及时续费。</p>
                    <p>3. 已支付的费用不予退还，法律法规另有规定的除外。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">六、退款政策</h2>
                    <p>1. 因服务商原因导致服务无法正常使用的，用户可申请退款。</p>
                    <p>2. 因用户自身原因申请退款的，不予退款。</p>
                    <p>3. 特殊活动或产品另有约定的，以活动规则为准。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">七、免责声明</h2>
                    <p>1. 服务商不对因用户操作失误或第三方原因造成的数据丢失负责。</p>
                    <p>2. 用户需定期备份重要数据，服务商不承担数据丢失的责任。</p>
                    <p>3. 对于因网络中断、服务器维护等导致的暂时性服务中断，服务商不承担责任。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">八、协议修改</h2>
                    <p>服务商有权随时修改本协议，修改后的协议一旦在本网站上公布即生效。如果用户不同意修改后的协议，有权停止使用服务。如果用户继续使用服务，则视为用户接受修改后的协议。</p>

                    <h2 style="font-size: 18px; color: #1d2129; margin-top: 24px; margin-bottom: 12px;">九、争议解决</h2>
                    <p>本协议的解释和执行均适用中华人民共和国法律。如因本协议产生争议，双方应友好协商解决；协商不成的，任一方可向服务商所在地有管辖权的人民法院提起诉讼。</p>

                    <p style="margin-top: 32px; color: #86909c;">最后更新日期：2024年1月1日</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
