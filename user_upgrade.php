<?php
define('PUBLIC_URL', '');

$_xc_config = __DIR__ . '/宣传/config.php';
if (file_exists($_xc_config)) {
    require_once $_xc_config;
} else {
    require_once __DIR__ . '/hym_license/config.php';
}

require_user_auth();

$user = current_user();
$orders = get_user_orders($user['id']);

$upgrade_code = $_GET['code'] ?? '';
$from_type = $_GET['from'] ?? '';

if ($from_type === 'trial') {
    $upgrade_types = ['standard', 'enterprise'];
} elseif ($from_type === 'standard') {
    $upgrade_types = ['enterprise'];
} else {
    $upgrade_types = ['standard', 'enterprise'];
}

$latest_paid = null;
foreach ($orders as $order) {
    if ($order['status'] === 'paid') {
        $latest_paid = $order;
        break;
    }
}

$prices = get_all_prices();
$upgrade_prices = [];
foreach ($prices as $p) {
    if (in_array($p['type'], $upgrade_types)) {
        $upgrade_prices[$p['type']] = $p;
    }
}

$error = '';
$selected_type = $_GET['type'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $captcha_token = $_POST['captcha_token'] ?? '';
    $upgrade_code = trim($_POST['upgrade_code'] ?? '');

    if (!in_array($type, $upgrade_types)) {
        $error = '请选择有效的升级套餐';
    } elseif (empty($captcha_token)) {
        $error = '请完成安全验证';
    } else {
        $captcha_verified = verify_captcha_token($captcha_token);
        if (!$captcha_verified) {
            $error = '安全验证失败，请重试';
        } else {
            $price = get_license_price($type);
            if (!$price) {
                $error = '套餐不存在';
            } elseif ($price['price'] <= 0) {
                $error = '该套餐价格配置异常，请联系管理员';
            } else {
                $order_no = create_buy_order($type, $user['email'], $price['price'], $user['id'], $upgrade_code);

                $epay_config = get_epay_config();
                if (!$epay_config['enabled'] || empty($epay_config['api_url']) || empty($epay_config['pid']) || empty($epay_config['key'])) {
                    $error = '支付功能未配置，请联系管理员';
                } else {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $notify_url = $scheme . $host . '/hym_license/pay_callback.php';
                    $return_url = $scheme . $host . '/user_dashboard.php?order=' . $order_no;

                    $params = [
                        'pid' => $epay_config['pid'],
                        'type' => $epay_config['type'],
                        'out_trade_no' => $order_no,
                        'notify_url' => $notify_url,
                        'return_url' => $return_url,
                        'name' => '核验码升级 - ' . $price['name'],
                        'money' => $price['price'],
                        'sitename' => SITE_NAME,
                    ];

                    ksort($params);
                    $sign_str = '';
                    foreach ($params as $k => $v) {
                        if ($v !== '') {
                            $sign_str .= $k . '=' . $v . '&';
                        }
                    }
                    $sign_str = rtrim($sign_str, '&');
                    $sign = md5($sign_str . $epay_config['key']);
                    $params['sign'] = $sign;
                    $params['sign_type'] = 'MD5';

                    $api_url = rtrim($epay_config['api_url'], '/') . '/submit.php?' . http_build_query($params);

                    header('Location: ' . $api_url);
                    exit;
                }
            }
        }
    }
}
$pub = defined('PUBLIC_URL') ? PUBLIC_URL : '';
if ($pub !== '' && substr($pub, -1) !== '/') $pub .= '/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>升级套餐 - <?php echo e(SITE_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        .login-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            width: 100%;
            max-width: 500px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 8px;
            font-size: 24px;
        }
        .subtitle {
            text-align: center;
            color: #999;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .current-order {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #555;
        }
        .current-order strong {
            color: #333;
        }
        .pricing-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        .pricing-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .pricing-card:hover {
            border-color: #667eea;
        }
        .pricing-card.selected {
            border-color: #667eea;
            background: #f5f7ff;
        }
        .pricing-card h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 8px;
        }
        .pricing-card .price {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 4px;
        }
        .pricing-card .price span {
            font-size: 14px;
            font-weight: 400;
        }
        .pricing-card p {
            font-size: 12px;
            color: #999;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group input[type="radio"] {
            display: none;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }
        .link-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
        .link-footer a {
            color: #667eea;
            text-decoration: none;
        }
        .link-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>升级套餐</h1>
        <p class="subtitle"><?php echo e(SITE_NAME); ?></p>

        <?php if ($error): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($upgrade_code)): ?>
        <div class="current-order">
            <div><strong>升级核验码：</strong><?php echo e($upgrade_code); ?></div>
            <div style="margin-top:4px;"><strong>当前版本：</strong><?php echo e($from_type === 'trial' ? '试用版' : ($from_type === 'standard' ? '标准版' : $from_type)); ?></div>
        </div>
        <?php elseif ($latest_paid): ?>
        <div class="current-order">
            <div><strong>当前套餐：</strong><?php echo e($upgrade_prices[$latest_paid['type']]['name'] ?? $latest_paid['type']); ?></div>
            <?php if (!empty($latest_paid['license_code'])): ?>
            <div style="margin-top:4px;"><strong>核验码：</strong><?php echo e($latest_paid['license_code']); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="upgradeForm">
            <input type="hidden" name="upgrade_code" value="<?php echo e($upgrade_code); ?>">
            <input type="hidden" name="from_type" value="<?php echo e($from_type); ?>">
            <div class="pricing-grid">
                <?php foreach ($upgrade_prices as $ptype => $p): ?>
                <label class="pricing-card <?php echo $selected_type === $ptype ? 'selected' : ''; ?>" onclick="selectType(this, '<?php echo $ptype; ?>')">
                    <input type="radio" name="type" value="<?php echo e($ptype); ?>" <?php echo $selected_type === $ptype ? 'checked' : ''; ?>>
                    <h3><?php echo e($p['name']); ?></h3>
                    <div class="price"><span>¥</span><?php echo e($p['price']); ?></div>
                    <p>有效期: <?php echo ($p['valid_days'] ?? $p['duration_days'] ?? 0) > 0 ? ($p['valid_days'] ?? $p['duration_days']) . '天' : '永久'; ?> | 设备: <?php echo e($p['max_devices']); ?>台</p>
                </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="captcha_token" value="">
            <button type="button" onclick="submitUpgrade()" class="btn">立即升级</button>
        </form>

        <div class="link-footer">
            <a href="/user_dashboard.php">返回用户中心</a>
        </div>
    </div>

    <div id="captcha-box" style="position:fixed;top:0;left:0;width:0;height:0;z-index:9999;"></div>

    <script>
    function selectType(el, type) {
        document.querySelectorAll('.pricing-card').forEach(function(card) {
            card.classList.remove('selected');
        });
        el.classList.add('selected');
        el.querySelector('input[type="radio"]').checked = true;
    }

    function submitUpgrade() {
        var form = document.getElementById('upgradeForm');
        var selected = form.querySelector('input[name="type"]:checked');
        if (!selected) {
            alert('请选择要升级的套餐');
            return;
        }
        openCaptcha('upgradeForm');
    }

    function openCaptcha(formId) {
        var form = document.getElementById(formId);
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '<?php echo $pub; ?>captcha.php?action=gen', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.code === 200 && res.data && res.data.token) {
                        var tokenInput = form.querySelector('[name=captcha_token]');
                        if (tokenInput) {
                            tokenInput.value = res.data.token;
                        }
                        
                        var xhr2 = new XMLHttpRequest();
                        xhr2.open('POST', '<?php echo $pub; ?>captcha.php?action=check', true);
                        xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr2.onload = function() {
                            if (xhr2.status === 200) {
                                try {
                                    var res2 = JSON.parse(xhr2.responseText);
                                    if (res2.code === 200) {
                                        form.submit();
                                    } else {
                                        alert('验证失败,请重试');
                                    }
                                } catch(e) {
                                    alert('验证失败,请重试');
                                }
                            } else {
                                alert('验证失败,请重试');
                            }
                        };
                        xhr2.send('token=' + encodeURIComponent(res.data.token));
                    } else {
                        alert('获取验证码失败,请重试');
                    }
                } catch(e) {
                    alert('获取验证码失败,请重试');
                }
            } else {
                alert('获取验证码失败,请重试');
            }
        };
        xhr.send();
    }
    </script>
</body>
</html>
