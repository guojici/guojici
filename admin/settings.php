<?php
require_once __DIR__ . '/../config/db.php';

$debug_mode = intval(db_get_setting('app_debug_mode') ?? 0);

// 根据模式设置错误报告
if ($debug_mode) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
require_once __DIR__ . '/../config/helper.php';
require_admin();

$mnbt_config = config('mnbt');
$site_config = config('site');
$idverify_config = config('idverify');
$frp_config = config('frp');
$bt_config = config('bt_panel');
$kvm_config = config('kvm');
$smtp_config = array_merge(config('smtp'), db_get_settings('smtp'));

$flash_success = flash('success');
$flash_error = flash('error');

if (is_post()) {
    try {
        $action = post('action');

        if ($action === 'test_api') {
            $api = mnbt_api();
            $result = $api->test_connection('testuser');
            flash('success', 'API测试结果: code=' . $result['code'] . ' msg=' . ($result['msg'] ?? '无'));
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_site') {
            $site_fields = ['title', 'subtitle', 'description', 'keywords', 'logo_text', 'logo_icon',
                             'hero_title', 'hero_subtitle', 'footer_company', 'footer_copyright',
                             'footer_icp', 'footer_contact', 'contact_phone', 'contact_email'];
            foreach ($site_fields as $f) {
                $val = trim(post('site_' . $f, ''));
                db_set_setting('site_' . $f, $val);
            }
            flash('success', '站点信息已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_mnbt') {
            $mnbt_fields = ['base_url', 'mn_bh', 'mn_key', 'mn_keye', 'mn_vs'];
            foreach ($mnbt_fields as $f) {
                $val = trim(post('mnbt_' . $f, ''));
                db_set_setting('mnbt_' . $f, $val);
            }
            flash('success', 'MNBT API配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_idverify') {
            $idv_fields = ['api_url', 'appkey'];
            foreach ($idv_fields as $f) {
                $val = trim(post('idverify_' . $f, ''));
                db_set_setting('idverify_' . $f, $val);
            }
            db_set_setting('idverify_enabled', post('idverify_enabled') === 'on' ? 1 : 0);
            db_set_setting('idverify_required', post('idverify_required') === 'on' ? 1 : 0);
            flash('success', '实名认证配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_frp') {
            $frp_fields = ['admin_api_url', 'admin_user', 'admin_password', 'server_addr', 'server_port', 'token', 'local_ip', 'local_port', 'port_range', 'public_domain'];
            foreach ($frp_fields as $f) {
                $val = trim(post('frp_' . $f, ''));
                if ($f === 'local_ip' || $f === 'server_addr' || $f === 'public_domain') {
                    $val = frp_clean_local_ip($val);
                }
                db_set_setting('frp_' . $f, $val);
            }
            db_set_setting('frp_enabled', post('frp_enabled') === 'on' ? 1 : 0);
            flash('success', 'FRP 内网穿透配置已保存 (local_ip已自动去除端口号)');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'test_frp') {
            $test = frp_get_config();
            if ($test['success']) {
                $count = is_array($test['data']['proxies'] ?? null) ? count($test['data']['proxies']) : 0;
                flash('success', 'FRP API 连接成功，当前代理数量: ' . $count . ' (HTTP ' . intval($test['httpcode'] ?? 0) . ')');
            } else {
                flash('error', 'FRP API 连接失败: ' . ($test['message'] ?? '未知错误'));
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'test_frp_reload') {
            $reload = frp_reload();
            if ($reload['success']) {
                flash('success', 'FRP 配置已上传并重新加载 ✓ (接口: ' . ($reload['endpoint'] ?? 'unknown') . ')');
            } else {
                flash('error', 'FRP reload: ' . ($reload['message'] ?? '未知错误') . ' — 请检查 frpc 是否启用 admin_port，frpc.ini 需包含 [common] 下的 admin_addr/admin_port/admin_user/admin_password');
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_smtp') {
            $smtp_fields = ['host', 'port', 'username', 'password', 'from_email', 'from_name', 'secure'];
            foreach ($smtp_fields as $f) {
                $val = trim(post('smtp_' . $f, ''));
                db_set_setting('smtp_' . $f, $val);
            }
            db_set_setting('smtp_enabled', post('smtp_enabled') === 'on' ? 1 : 0);
            flash('success', 'SMTP邮件配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'test_smtp') {
            require_once __DIR__ . '/../config/Mailer.php';
            $mailer = new Mailer();
            $test = $mailer->testConnection();
            if ($test['success']) {
                flash('success', 'SMTP测试: ' . ($test['message'] ?? '连接成功'));
            } else {
                flash('error', 'SMTP测试失败: ' . ($test['message'] ?? '未知错误'));
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_kvm') {
            $kvm_fields = ['host', 'port', 'user', 'password', 'public_domain', 'bridge', 'storage'];
            foreach ($kvm_fields as $f) {
                $val = trim(post('kvm_' . $f, ''));
                db_set_setting('kvm_' . $f, $val);
            }
            db_set_setting('kvm_enabled', post('kvm_enabled') === 'on' ? 1 : 0);
            flash('success', 'KVM宿主机配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_bt') {
            $bt_fields = ['api_url', 'api_key'];
            foreach ($bt_fields as $f) {
                $val = trim(post('bt_' . $f, ''));
                db_set_setting('bt_' . $f, $val);
            }
            db_set_setting('bt_enabled', post('bt_enabled') === 'on' ? 1 : 0);
            flash('success', '宝塔面板API配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'test_bt') {
            $test = bt_test_connection();
            if ($test['success']) {
                flash('success', '宝塔面板API测试: ' . ($test['message'] ?? '连接成功'));
            } else {
                flash('error', '宝塔面板API测试失败: ' . ($test['message'] ?? '未知错误') . ' (请检查API地址和密钥)');
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'add_admin') {
            $username = trim(post('admin_username'));
            $password = post('admin_password');
            $role = post('admin_role', 'admin');
            $existing = Database::fetch("SELECT id FROM admin_users WHERE username = ?", [$username]);
            if ($existing) {
                flash('error', '管理员用户名已存在');
            } else {
                Database::insert('admin_users', [
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                flash('success', '管理员已添加');
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'delete_admin') {
            $aid = intval(post('admin_id'));
            if ($aid != 1) {
                Database::delete('admin_users', 'id = ?', [$aid]);
                flash('success', '管理员已删除');
            } else {
                flash('error', '不能删除超级管理员');
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'change_pwd') {
            $aid = intval(post('admin_id'));
            $password = post('new_password');
            Database::update('admin_users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$aid]);
            flash('success', '密码已修改');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_epay') {
            $epay_fields = ['api_url', 'pid', 'key', 'sign_type', 'notify_url', 'return_url'];
            foreach ($epay_fields as $f) {
                $val = trim(post('epay_' . $f, ''));
                db_set_setting('epay_' . $f, $val);
            }
            db_set_setting('epay_enabled', post('epay_enabled') === 'on' ? 1 : 0);
            db_set_setting('epay_debug', post('epay_debug') === 'on' ? 1 : 0);
            flash('success', '易支付配置已保存');
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'test_epay') {
            $epay_config = array_merge(config('epay') ?: [], db_get_settings('epay'));
            $api_url = rtrim($epay_config['api_url'] ?? '', '/');
            if (empty($api_url)) {
                flash('error', '请先配置易支付API地址');
            } else {
                $test_url = $api_url . '/api.php?act=pay&pid=' . $epay_config['pid'] . '&type=test';
                $ch = curl_init($test_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                $resp = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($resp === false) {
                    flash('error', '易支付连接失败: ' . $err);
                } elseif ($http_code !== 200 && $http_code !== 302) {
                    flash('error', '易支付返回 HTTP ' . $http_code);
                } else {
                    flash('success', '易支付API连接成功，地址有效');
                }
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_mode') {
            $mode = post('app_mode', 'production');
            $debug_mode = $mode === 'debug' ? 1 : 0;
            db_set_setting('app_debug_mode', $debug_mode);
            
            if ($debug_mode) {
                flash('success', '已切换到调试模式 - 错误信息将显示在页面上');
            } else {
                flash('success', '已切换到生产模式 - 错误信息将隐藏');
            }
            header('Location: /admin/settings.php');
            exit;
        }

        if ($action === 'save_demo') {
            require_once __DIR__ . '/../config/DemoMode.php';
            
            $password = post('demo_password', '');
            $new_password = post('demo_new_password', '');
            $confirm_password = post('demo_confirm_password', '');
            $enable_demo = post('demo_enable', '') === 'on';
            
            if ($enable_demo) {
                // 启用演示模式需要验证密码
                $result = DemoMode::enable($password);
                if (!$result['success']) {
                    flash('error', $result['message']);
                } else {
                    flash('success', $result['message']);
                    // 启用后重定向到演示页面
                    header('Location: /demo/');
                    exit;
                }
            } else {
                // 禁用演示模式需要验证密码
                $result = DemoMode::disable($password);
                if (!$result['success']) {
                    flash('error', $result['message']);
                } else {
                    flash('success', $result['message']);
                }
            }
            
            // 修改密码
            if (!empty($new_password)) {
                if ($new_password !== $confirm_password) {
                    flash('error', '两次输入的新密码不一致');
                } else {
                    $result = DemoMode::setPassword($password, $new_password);
                    if (!$result['success']) {
                        flash('error', $result['message']);
                    } else {
                        flash('success', '演示模式密码已修改');
                    }
                }
            }
            
            header('Location: /admin/settings.php');
            exit;
        }
    } catch (Exception $e) {
        flash('error', '操作失败: ' . $e->getMessage());
        header('Location: /admin/settings.php');
        exit;
    }
}

$admin_users = Database::fetchAll("SELECT * FROM admin_users ORDER BY id ASC");

// 获取易支付配置
$epay_config = array_merge(config('epay') ?: [], db_get_settings('epay'));

// 获取演示模式配置
require_once __DIR__ . '/../config/DemoMode.php';
$demo_enabled = DemoMode::isEnabled();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - <?php echo e(config('site.title')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">系统设置</h1>
                    <p class="page-subtitle">站点信息、MNBT API与管理员管理</p>
                </div>
            </div>

            <?php if ($flash_success): ?><div class="alert alert-success"><?php echo e($flash_success); ?></div><?php endif; ?>
            <?php if ($flash_error): ?><div class="alert alert-error"><?php echo e($flash_error); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-title">站点信息设置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_site">
                        <div class="form-group">
                            <label>站点标题 (浏览器标题)</label>
                            <input type="text" class="form-control" name="site_title" value="<?php echo e($site_config['title']); ?>">
                        </div>
                        <div class="form-group">
                            <label>副标题 (简短描述)</label>
                            <input type="text" class="form-control" name="site_subtitle" value="<?php echo e($site_config['subtitle']); ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>LOGO 文字</label>
                                <input type="text" class="form-control" name="site_logo_text" value="<?php echo e($site_config['logo_text']); ?>">
                            </div>
                            <div class="form-group">
                                <label>LOGO 图标 (Emoji 或符号)</label>
                                <input type="text" class="form-control" name="site_logo_icon" value="<?php echo e($site_config['logo_icon']); ?>" placeholder="如: 🖥️ 或 ☁️">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>首页主标题 (Hero Title)</label>
                            <input type="text" class="form-control" name="site_hero_title" value="<?php echo e($site_config['hero_title']); ?>">
                        </div>
                        <div class="form-group">
                            <label>首页副标题 (Hero Subtitle)</label>
                            <input type="text" class="form-control" name="site_hero_subtitle" value="<?php echo e($site_config['hero_subtitle']); ?>">
                        </div>
                        <div class="form-group">
                            <label>站点描述 (用于 SEO/首页展示)</label>
                            <textarea class="form-control" name="site_description" rows="2"><?php echo e($site_config['description']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>站点关键词 (SEO, 逗号分隔)</label>
                            <input type="text" class="form-control" name="site_keywords" value="<?php echo e($site_config['keywords']); ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>页脚公司名称</label>
                                <input type="text" class="form-control" name="site_footer_company" value="<?php echo e($site_config['footer_company']); ?>">
                            </div>
                            <div class="form-group">
                                <label>页脚版权信息</label>
                                <input type="text" class="form-control" name="site_footer_copyright" value="<?php echo e($site_config['footer_copyright']); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>备案号 (ICP)</label>
                                <input type="text" class="form-control" name="site_footer_icp" value="<?php echo e($site_config['footer_icp']); ?>">
                            </div>
                            <div class="form-group">
                                <label>联系方式</label>
                                <input type="text" class="form-control" name="site_footer_contact" value="<?php echo e($site_config['footer_contact']); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>联系电话</label>
                                <input type="text" class="form-control" name="site_contact_phone" value="<?php echo e($site_config['contact_phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label>联系邮箱</label>
                                <input type="text" class="form-control" name="site_contact_email" value="<?php echo e($site_config['contact_email']); ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">保存站点信息</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-title">运行模式切换</div>
                <div class="credentials-box">
                    <div style="padding: 16px; background: <?php echo $debug_mode ? 'rgba(239,68,68,0.08)' : 'rgba(34,197,94,0.08)'; ?>; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid <?php echo $debug_mode ? '#ef4444' : '#22c55e'; ?>;">
                        <div style="font-size: 16px; font-weight: 600; color: <?php echo $debug_mode ? '#ef4444' : '#22c55e'; ?>; margin-bottom: 4px;">
                            <?php echo $debug_mode ? '🐛 调试模式' : '🚀 生产模式'; ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">
                            <?php echo $debug_mode ? '当前处于调试模式，PHP错误信息将直接显示在页面上，便于排查问题。不建议在生产环境中长期使用。' : '当前处于生产模式，错误信息被隐藏，仅记录到日志文件，确保用户体验和安全性。'; ?>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_mode">
                        <div style="display: flex; gap: 16px; align-items: center;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 12px 24px; border-radius: 8px; border: 2px solid <?php echo !$debug_mode ? '#22c55e' : 'var(--border)'; ?>; background: <?php echo !$debug_mode ? 'rgba(34,197,94,0.08)' : 'transparent'; ?>; transition: all 0.2s;">
                                <input type="radio" name="app_mode" value="production" <?php echo !$debug_mode ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                                <span style="font-weight: 600; color: <?php echo !$debug_mode ? '#22c55e' : 'var(--text-secondary)'; ?>;">🚀 生产模式</span>
                                <span style="font-size: 12px; color: var(--text-secondary);">隐藏错误，安全运行</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 12px 24px; border-radius: 8px; border: 2px solid <?php echo $debug_mode ? '#ef4444' : 'var(--border)'; ?>; background: <?php echo $debug_mode ? 'rgba(239,68,68,0.08)' : 'transparent'; ?>; transition: all 0.2s;">
                                <input type="radio" name="app_mode" value="debug" <?php echo $debug_mode ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                                <span style="font-weight: 600; color: <?php echo $debug_mode ? '#ef4444' : 'var(--text-secondary)'; ?>;">🐛 调试模式</span>
                                <span style="font-size: 12px; color: var(--text-secondary);">显示错误，便于排查</span>
                            </label>
                        </div>
                        <div style="margin-top: 16px; padding: 12px; background: rgba(245,158,11,0.08); border-radius: 6px; font-size: 12px; color: #b45309;">
                            ⚠️ <strong>注意：</strong>调试模式下所有PHP错误、警告和异常信息将直接显示在页面上，可能暴露敏感信息（如文件路径、数据库结构等）。仅在排查问题时临时开启，确认问题后应立即切换回生产模式。
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 16px;">切换运行模式</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-title">MNBT API 配置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_mnbt">
                        <div class="form-group">
                            <label>面板地址 (base_url)</label>
                            <input type="text" class="form-control" name="mnbt_base_url" value="<?php echo e($mnbt_config['base_url']); ?>" placeholder="http://192.168.3.2:7894">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>宝塔编号 (mn_bh)</label>
                                <input type="text" class="form-control" name="mnbt_mn_bh" value="<?php echo e($mnbt_config['mn_bh']); ?>">
                            </div>
                            <div class="form-group">
                                <label>版本 (mn_vs)</label>
                                <input type="text" class="form-control" name="mnbt_mn_vs" value="<?php echo e($mnbt_config['mn_vs']); ?>" placeholder="17">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>API 密钥 (mn_key)</label>
                            <input type="text" class="form-control" name="mnbt_mn_key" value="<?php echo e($mnbt_config['mn_key']); ?>">
                        </div>
                        <div class="form-group">
                            <label>宝塔调用密钥 (mn_keye)</label>
                            <input type="text" class="form-control" name="mnbt_mn_keye" value="<?php echo e($mnbt_config['mn_keye']); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">保存 API 配置</button>
                    </form>
                    <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <input type="hidden" name="action" value="test_api">
                        <button type="submit" class="btn btn-secondary">测试 API 连接</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-title">实名认证 API 配置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_idverify">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="idverify_enabled" <?php echo !empty($idverify_config['enabled']) ? 'checked' : ''; ?>>
                                    启用实名认证功能
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="idverify_required" <?php echo !empty($idverify_config['required']) ? 'checked' : ''; ?>>
                                    强制用户必须认证
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>接口地址 (JuHe 实名认证查询)</label>
                            <input type="text" class="form-control" name="idverify_api_url" value="<?php echo e($idverify_config['api_url'] ?? ''); ?>" placeholder="https://op.juhe.cn/idcard/query">
                        </div>
                        <div class="form-group">
                            <label>AppKey</label>
                            <input type="text" class="form-control" name="idverify_appkey" value="<?php echo e($idverify_config['appkey'] ?? ''); ?>" placeholder="从聚合数据官网申请的 AppKey">
                        </div>
                        <button type="submit" class="btn btn-primary">保存实名认证配置</button>
                    </form>
                    <?php
                    $v_count = 0;
                    $total_count = 0;
                    try {
                        $v_row = Database::fetch("SELECT COUNT(*) as c FROM users WHERE id_verify_status = 1");
                        $v_count = $v_row['c'] ?? 0;
                    } catch (Exception $e) {}
                    try {
                        $t_row = Database::fetch("SELECT COUNT(*) as c FROM users");
                        $total_count = $t_row['c'] ?? 0;
                    } catch (Exception $e) {}
                    ?>
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 13px; color: var(--text-secondary);">
                        <div>已认证用户: <strong style="color: var(--text-primary);"><?php echo $v_count; ?></strong> / 总用户: <?php echo $total_count; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">FRP 内网穿透配置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_frp">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="frp_enabled" <?php echo !empty($frp_config['enabled']) ? 'checked' : ''; ?>>
                                    启用 FRP 内网穿透功能
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>frpc Admin API 地址</label>
                            <input type="text" class="form-control" name="frp_admin_api_url" value="<?php echo e($frp_config['admin_api_url'] ?? ''); ?>" placeholder="http://192.168.3.2:7400/api">
                            <small style="color: var(--text-secondary); font-size: 12px;">示例: http://192.168.3.2:7400/api (不要只填写主机名+端口)</small>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Admin 用户名</label>
                                <input type="text" class="form-control" name="frp_admin_user" value="<?php echo e($frp_config['admin_user'] ?? ''); ?>" placeholder="frpadmin">
                            </div>
                            <div class="form-group">
                                <label>Admin 密码</label>
                                <input type="text" class="form-control" name="frp_admin_password" value="<?php echo e($frp_config['admin_password'] ?? ''); ?>" placeholder="123456">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>FRP 服务器地址 (server_addr)</label>
                                <input type="text" class="form-control" name="frp_server_addr" value="<?php echo e($frp_config['server_addr'] ?? ''); ?>" placeholder="82.157.25.246">
                            </div>
                            <div class="form-group">
                                <label>FRP 服务器端口 (server_port)</label>
                                <input type="number" class="form-control" name="frp_server_port" value="<?php echo e($frp_config['server_port'] ?? ''); ?>" placeholder="7000">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Token</label>
                            <input type="text" class="form-control" name="frp_token" value="<?php echo e($frp_config['token'] ?? ''); ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>本地 IP (local_ip) <span style="color: #ef4444; font-size: 12px;">* 只填 IP，不要带端口</span></label>
                                <input type="text" class="form-control" name="frp_local_ip" value="<?php echo e($frp_config['local_ip'] ?? ''); ?>" placeholder="127.0.0.1 或 192.168.3.2">
                                <small style="color: var(--text-secondary); font-size: 12px;">frpc 转发到此 IP。如果 frpc 与面板在同一机器，填 127.0.0.1。如果不同机器，填面板机器内网IP。</small>
                            </div>
                            <div class="form-group">
                                <label>本地端口 (local_port)</label>
                                <input type="number" class="form-control" name="frp_local_port" value="<?php echo e($frp_config['local_port'] ?? 80); ?>" placeholder="80">
                                <small style="color: var(--text-secondary); font-size: 12px;">面板 Web 端口，默认 80</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>远程端口范围 (port_range)</label>
                                <input type="text" class="form-control" name="frp_port_range" value="<?php echo e($frp_config['port_range'] ?? ''); ?>" placeholder="2000-59999">
                            </div>
                            <div class="form-group">
                                <label>公网访问域名/IP (public_domain)</label>
                                <input type="text" class="form-control" name="frp_public_domain" value="<?php echo e($frp_config['public_domain'] ?? ''); ?>" placeholder="82.157.25.246">
                                <small style="color: var(--text-secondary); font-size: 12px;">用户看到的远程访问地址将为: http://此域名:远程端口</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">保存 FRP 配置</button>
                    </form>
                    <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <input type="hidden" name="action" value="test_frp">
                        <button type="submit" class="btn btn-secondary">测试 FRP API 连接</button>
                    </form>
                    <form method="POST" style="margin-top: 8px;">
                        <input type="hidden" name="action" value="test_frp_reload">
                        <button type="submit" class="btn btn-secondary">测试 FRP 上传/重新加载</button>
                    </form>
                    <?php
                    $frp_total = 0;
                    $frp_active = 0;
                    $frp_live = [];
                    try {
                        $f_row = Database::fetch("SELECT COUNT(*) as c FROM hosts WHERE frp_rule_name IS NOT NULL AND frp_rule_name != ''");
                        $frp_total = $f_row['c'] ?? 0;
                        $f_row2 = Database::fetch("SELECT COUNT(*) as c FROM hosts WHERE frp_status = 'online'");
                        $frp_active = $f_row2['c'] ?? 0;
                    } catch (Exception $e) {}
                    $frp_api_test = null;
                    $frp_status_test = null;
                    $frp_proxies = [];
                    try {
                        $frp_api_test = frp_get_config();
                        $frp_status_test = frp_get_status();
                    } catch (Exception $e) {}
                    $api_ok = $frp_api_test && !empty($frp_api_test['success']);
                    if ($api_ok && is_array($frp_status_test['data']['proxies'] ?? null)) {
                        $frp_proxies = $frp_status_test['data']['proxies'];
                    } elseif ($api_ok && is_array($frp_api_test['data']['proxies'] ?? null)) {
                        $frp_proxies = $frp_api_test['data']['proxies'];
                    }
                    ?>
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 13px; color: var(--text-secondary);">
                        <div>已配置穿透主机: <strong style="color: var(--text-primary);"><?php echo $frp_total; ?></strong> 台 (在线: <?php echo $frp_active; ?> 台)</div>
                        <div style="margin-top: 8px;">
                            API 状态:
                            <?php if ($api_ok): ?>
                                <span style="color: #22c55e;">● 已连接</span>
                                <?php
                                $c = is_array($frp_api_test['data']['proxies'] ?? null) ? count($frp_api_test['data']['proxies']) : 0;
                                echo ' - 当前代理 ' . $c . ' 条';
                                ?>
                            <?php else: ?>
                                <span style="color: #ef4444;">● 未连接</span>
                                <?php if (!empty($frp_api_test['message'])): ?>
                                    <div style="margin-top: 4px; padding: 8px; background: rgba(239,68,68,0.05); border-radius: 4px;">
                                        错误信息: <?php echo e($frp_api_test['message']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($api_ok && !empty($frp_proxies)): ?>
                        <div style="margin-top: 12px; padding: 12px; background: rgba(34,197,94,0.05); border-radius: 8px;">
                            <strong style="color: var(--text-primary);">frpc 当前代理列表</strong>
                            <div style="margin-top: 8px; overflow-x: auto;">
                                <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                    <thead>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <th style="text-align: left; padding: 6px 8px;">规则名</th>
                                            <th style="text-align: left; padding: 6px 8px;">类型</th>
                                            <th style="text-align: left; padding: 6px 8px;">本地</th>
                                            <th style="text-align: left; padding: 6px 8px;">远程端口</th>
                                            <th style="text-align: left; padding: 6px 8px;">状态</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($frp_proxies as $p): ?>
                                            <?php
                                            $p_name = $p['name'] ?? '?';
                                            $p_type = $p['type'] ?? '?';
                                            $p_local = ($p['localIP'] ?? '?') . ':' . ($p['localPort'] ?? '?');
                                            $p_remote = $p['remotePort'] ?? '?';
                                            $p_status = $p['status'] ?? 'unknown';
                                            $status_color = 'var(--text-secondary)';
                                            if ($p_status === 'online') $status_color = '#22c55e';
                                            elseif ($p_status === 'offline') $status_color = '#ef4444';
                                            ?>
                                            <tr style="border-bottom: 1px solid var(--border);">
                                                <td style="padding: 6px 8px;"><?php echo e($p_name); ?></td>
                                                <td style="padding: 6px 8px;"><?php echo e($p_type); ?></td>
                                                <td style="padding: 6px 8px;"><?php echo e($p_local); ?></td>
                                                <td style="padding: 6px 8px;"><?php echo e($p_remote); ?></td>
                                                <td style="padding: 6px 8px; color: <?php echo $status_color; ?>;"><?php echo e($p_status); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 12px; padding: 12px; background: rgba(59,130,246,0.05); border-radius: 8px; line-height: 1.8;">
                            <strong style="color: var(--text-primary);">代理 offline 排查 (从最上面列表复制确认)</strong><br>
                            1. <strong>本地 IP</strong>：frpc 运行机器上填 <code style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 3px;">127.0.0.1</code>，不同机器填面板内网 IP<br>
                            2. <strong>本地端口</strong>：面板 Web 端口，默认为 <code style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 3px;">7894</code>（不是 80）<br>
                            3. <strong>确认本地服务</strong>：在 frpc 机器执行 <code style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 3px;">curl http://127.0.0.1:7894</code> 必须返回页面<br>
                            4. <strong>端口冲突</strong>：检查远程端口是否已被其他程序占用（可尝试从 20000 起手动指定）<br>
                            5. <strong>修改配置后</strong>：先删除已有规则，再重新添加；或直接在表格中看到 online 即生效
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">SMTP邮件配置（注册/找回密码验证码）</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_smtp">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="smtp_enabled" <?php echo !empty($smtp_config['enabled']) ? 'checked' : ''; ?>>
                                    启用SMTP邮件发送（注册/找回密码时发送验证码）
                                </label>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>SMTP服务器地址</label>
                                <input type="text" class="form-control" name="smtp_host" value="<?php echo e($smtp_config['host'] ?? 'smtp.163.com'); ?>" placeholder="smtp.163.com 或 smtp.qq.com">
                            </div>
                            <div class="form-group">
                                <label>SMTP端口</label>
                                <input type="number" class="form-control" name="smtp_port" value="<?php echo e($smtp_config['port'] ?? 465); ?>" placeholder="465">
                            </div>
                            <div class="form-group">
                                <label>加密方式</label>
                                <select class="form-control" name="smtp_secure">
                                    <option value="ssl" <?php echo (!empty($smtp_config['secure']) && $smtp_config['secure'] === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                    <option value="tls" <?php echo (!empty($smtp_config['secure']) && $smtp_config['secure'] === 'tls') ? 'selected' : ''; ?>>TLS</option>
                                    <option value="" <?php echo (empty($smtp_config['secure'])) ? 'selected' : ''; ?>>无</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>SMTP用户名</label>
                                <input type="text" class="form-control" name="smtp_username" value="<?php echo e($smtp_config['username'] ?? ''); ?>" placeholder="邮箱账号">
                            </div>
                            <div class="form-group">
                                <label>SMTP密码/授权码</label>
                                <input type="password" class="form-control" name="smtp_password" value="<?php echo e($smtp_config['password'] ?? ''); ?>" placeholder="邮箱密码或授权码">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>发件人邮箱</label>
                                <input type="text" class="form-control" name="smtp_from_email" value="<?php echo e($smtp_config['from_email'] ?? ''); ?>" placeholder="发送邮件显示的邮箱地址">
                            </div>
                            <div class="form-group">
                                <label>发件人名称</label>
                                <input type="text" class="form-control" name="smtp_from_name" value="<?php echo e($smtp_config['from_name'] ?? 'guojici云'); ?>" placeholder="发送邮件显示的名称">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">保存SMTP配置</button>
                    </form>
                    <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <input type="hidden" name="action" value="test_smtp">
                        <button type="submit" class="btn btn-secondary">测试SMTP发送（发送测试邮件到发件人邮箱）</button>
                    </form>
                    <div style="margin-top: 16px; padding: 12px; background: rgba(59,130,246,0.05); border-radius: 8px; font-size: 12px; color: var(--text-secondary); line-height: 1.8;">
                        <strong style="color: var(--text-primary);">常见邮箱SMTP配置</strong><br>
                        <table style="margin-top: 8px; width: 100%; font-size: 11px;">
                            <tr><td style="padding: 4px 0;">163邮箱</td><td>smtp.163.com</td><td>465</td><td>SSL</td><td>需开启SMTP并获取授权码</td></tr>
                            <tr><td style="padding: 4px 0;">QQ邮箱</td><td>smtp.qq.com</td><td>465</td><td>SSL</td><td>需开启SMTP并获取授权码</td></tr>
                            <tr><td style="padding: 4px 0;">Gmail</td><td>smtp.gmail.com</td><td>465</td><td>SSL</td><td>需启用应用密码</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">易支付配置（在线支付接口）</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_epay">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="epay_enabled" <?php echo !empty($epay_config['enabled']) ? 'checked' : ''; ?>>
                                    启用易支付（用户可在线充值/支付订单）
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="epay_debug" <?php echo !empty($epay_config['debug']) ? 'checked' : ''; ?>>
                                    调试模式（记录详细日志）
                                </label>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>易支付API地址</label>
                                <input type="text" class="form-control" name="epay_api_url" value="<?php echo e($epay_config['api_url'] ?? ''); ?>" placeholder="https://pay.example.com">
                                <small style="color: var(--text-secondary); font-size: 12px;">易支付网关地址，如: https://pay.example.com</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>商户ID (PID)</label>
                                <input type="text" class="form-control" name="epay_pid" value="<?php echo e($epay_config['pid'] ?? ''); ?>" placeholder="1001">
                            </div>
                            <div class="form-group">
                                <label>商户密钥 (KEY)</label>
                                <input type="text" class="form-control" name="epay_key" value="<?php echo e($epay_config['key'] ?? ''); ?>" placeholder="商户后台获取的密钥">
                            </div>
                            <div class="form-group">
                                <label>签名类型</label>
                                <select class="form-control" name="epay_sign_type">
                                    <option value="md5" <?php echo ($epay_config['sign_type'] ?? 'md5') === 'md5' ? 'selected' : ''; ?>>MD5</option>
                                    <option value="rsa" <?php echo ($epay_config['sign_type'] ?? '') === 'rsa' ? 'selected' : ''; ?>>RSA</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>异步通知地址 (Notify URL)</label>
                                <input type="text" class="form-control" name="epay_notify_url" value="<?php echo e($epay_config['notify_url'] ?? ''); ?>" placeholder="https://yourdomain.com/pay/notify.php">
                                <small style="color: var(--text-secondary); font-size: 12px;">支付成功后易支付服务器主动通知的地址</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>同步跳转地址 (Return URL)</label>
                                <input type="text" class="form-control" name="epay_return_url" value="<?php echo e($epay_config['return_url'] ?? ''); ?>" placeholder="https://yourdomain.com/pay/return.php">
                                <small style="color: var(--text-secondary); font-size: 12px;">支付成功后用户浏览器跳转的地址</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">保存易支付配置</button>
                    </form>
                    <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <input type="hidden" name="action" value="test_epay">
                        <button type="submit" class="btn btn-secondary">测试易支付连接</button>
                    </form>
                    <div style="margin-top: 16px; padding: 12px; background: rgba(59,130,246,0.05); border-radius: 8px; font-size: 12px; color: var(--text-secondary); line-height: 1.8;">
                        <strong style="color: var(--text-primary);">易支付配置说明</strong><br>
                        <table style="margin-top: 8px; width: 100%; font-size: 11px;">
                            <tr><td style="padding: 4px 0; width: 120px;">1. 获取商户ID</td><td>在易支付商户后台获取PID和KEY</td></tr>
                            <tr><td style="padding: 4px 0;">2. 配置回调地址</td><td>将Notify URL和Return URL填写到商户后台</td></tr>
                            <tr><td style="padding: 4px 0;">3. 支持支付方式</td><td>支付宝、微信支付、QQ钱包等（由易支付网关决定）</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">演示模式配置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_demo">
                        <div style="padding: 16px; background: <?php echo $demo_enabled ? 'rgba(238,174,202,0.2)' : 'rgba(255,243,205,0.2)'; ?>; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid <?php echo $demo_enabled ? '#e94560' : '#ffd93d'; ?>;">
                            <div style="font-size: 16px; font-weight: 600; color: <?php echo $demo_enabled ? '#e94560' : '#ffd93d'; ?>; margin-bottom: 4px;">
                                <?php echo $demo_enabled ? '🎭 演示模式已启用' : '⚠️ 演示模式未启用'; ?>
                            </div>
                            <div style="font-size: 13px; color: var(--text-secondary);">
                                <?php echo $demo_enabled ? '所有操作仅为模拟，实际数据不会被修改。访问 /demo/ 查看演示页面。' : '启用后将显示演示页面，所有操作将被拦截。'; ?>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="demo_enable" <?php echo $demo_enabled ? 'checked' : ''; ?>>
                                    启用演示模式
                                </label>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>演示模式密码 <span style="color: #f53f3f;">*</span></label>
                                <input type="password" class="form-control" name="demo_password" required placeholder="请输入演示模式密码">
                                <small style="color: var(--text-secondary); font-size: 12px;">默认密码: demo123</small>
                            </div>
                        </div>
                        <div style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 16px;">
                            <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 12px;">修改密码（可选）</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>新密码</label>
                                    <input type="password" class="form-control" name="demo_new_password" placeholder="设置新的演示模式密码（至少6位）">
                                </div>
                                <div class="form-group">
                                    <label>确认新密码</label>
                                    <input type="password" class="form-control" name="demo_confirm_password" placeholder="再次输入新密码">
                                </div>
                            </div>
                        </div>
                        <div style="padding: 12px; background: rgba(245,158,11,0.08); border-radius: 6px; font-size: 12px; color: #b45309; margin-top: 16px; margin-bottom: 20px;">
                            ⚠️ <strong>注意：</strong>启用演示模式后，系统所有写操作将被拦截，页面将显示模拟数据。请确保已备份重要数据。
                        </div>
                        <button type="submit" class="btn btn-primary">保存配置</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-title">KVM 宿主机配置</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_kvm">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="kvm_enabled" <?php echo !empty($kvm_config['enabled']) ? 'checked' : ''; ?>>
                                    启用 KVM 虚拟服务器功能
                                </label>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>宿主机地址</label>
                                <input type="text" class="form-control" name="kvm_host" value="<?php echo e($kvm_config['host'] ?? '127.0.0.1'); ?>" placeholder="宿主机IP或域名">
                                <small style="color: var(--text-secondary); font-size: 12px;">KVM宿主机的IP地址，本机填127.0.0.1</small>
                            </div>
                            <div class="form-group">
                                <label>SSH端口</label>
                                <input type="number" class="form-control" name="kvm_port" value="<?php echo e($kvm_config['port'] ?? 22); ?>" placeholder="SSH端口" style="width: 120px;">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>SSH用户名</label>
                                <input type="text" class="form-control" name="kvm_user" value="<?php echo e($kvm_config['user'] ?? 'root'); ?>" placeholder="SSH登录用户名">
                            </div>
                            <div class="form-group">
                                <label>SSH密码</label>
                                <input type="password" class="form-control" name="kvm_password" value="<?php echo e($kvm_config['password'] ?? ''); ?>" placeholder="SSH登录密码（留空不修改）">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>公网访问域名/IP</label>
                                <input type="text" class="form-control" name="kvm_public_domain" value="<?php echo e($kvm_config['public_domain'] ?? ''); ?>" placeholder="用户访问虚拟机的公网IP或域名">
                                <small style="color: var(--text-secondary); font-size: 12px;">用于显示给用户的访问地址</small>
                            </div>
                            <div class="form-group">
                                <label>网桥名称</label>
                                <input type="text" class="form-control" name="kvm_bridge" value="<?php echo e($kvm_config['bridge'] ?? 'virbr0'); ?>" placeholder="虚拟网桥名称">
                                <small style="color: var(--text-secondary); font-size: 12px;">默认使用virbr0（NAT模式）</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>虚拟机磁盘存储目录</label>
                            <input type="text" class="form-control" name="kvm_storage" value="<?php echo e($kvm_config['storage'] ?? '/var/lib/libvirt/images'); ?>" placeholder="虚拟机磁盘文件存放路径">
                            <small style="color: var(--text-secondary); font-size: 12px;">
                                建议使用 <code>/var/lib/libvirt/images</code> 或 <code>/kvm/images</code> 等稳定路径
                            </small>
                        </div>
                        <div style="margin-top: 12px; padding: 12px; background: #fff7e6; border-radius: 8px; font-size: 12px; color: #ad6800; line-height: 1.8;">
                            <strong style="color: #d48806;">⚠️ 存储目录注意事项：</strong><br>
                            1. 不要使用 <code>/run/media/root/xxx</code> 等临时挂载目录，重启后会失效<br>
                            2. 确保目录已存在且 www 用户有 sudo 权限执行 virsh 和 qemu-img<br>
                            3. 修改存储路径后，已有的虚拟机磁盘文件需要手动迁移<br>
                            4. 推荐路径：<code>/var/lib/libvirt/images</code>（libvirt默认目录）
                        </div>
                        <button type="submit" class="btn btn-primary">保存KVM配置</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-title">宝塔面板API配置（自动绑定域名/公网IP）</div>
                <div class="credentials-box">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_bt">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="bt_enabled" <?php echo !empty($bt_config['enabled']) ? 'checked' : ''; ?>>
                                    启用宝塔面板API（添加穿透后自动把公网IP绑定到网站）
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>宝塔面板 API 地址</label>
                            <input type="text" class="form-control" name="bt_api_url" value="<?php echo e($bt_config['api_url'] ?? ''); ?>" placeholder="http://192.168.3.2:7894 或 http://192.168.3.2:8888">
                            <small style="color: var(--text-secondary); font-size: 12px;">宝塔面板的完整访问地址，通常是 7894 或 8888 端口</small>
                        </div>
                        <div class="form-group">
                            <label>API 密钥 (可选)</label>
                            <input type="text" class="form-control" name="bt_api_key" value="<?php echo e($bt_config['api_key'] ?? ''); ?>" placeholder="宝塔面板设置中获取的API密钥">
                            <small style="color: var(--text-secondary); font-size: 12px;">如不需要API自动操作，可留空。添加穿透后系统会自动提示您手动在宝塔面板设置域名</small>
                        </div>
                        <button type="submit" class="btn btn-primary">保存宝塔API配置</button>
                    </form>
                    <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <input type="hidden" name="action" value="test_bt">
                        <button type="submit" class="btn btn-secondary">测试宝塔API连接</button>
                    </form>
                    <div style="margin-top: 16px; padding: 12px; background: rgba(59,130,246,0.05); border-radius: 8px; font-size: 12px; color: var(--text-secondary); line-height: 1.8;">
                        <strong style="color: var(--text-primary);">说明</strong><br>
                        1. 启用后，当用户点击"一键添加内网穿透"时，系统会自动尝试把穿透地址 (公网IP:远程端口) 绑定到网站的域名列表<br>
                        2. 如果宝塔API密钥未配置或API调用失败，系统仍会正常添加穿透，只是提示您需要在宝塔面板手动设置域名<br>
                        3. <strong>常见场景</strong>：在宝塔面板 → 网站设置 → 域名管理 → 添加域名 (填写公网IP:端口) 即可
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">管理员账号管理</div>
                <form method="POST" style="margin-bottom: 32px; padding: 20px; background: var(--bg-secondary); border-radius: 8px;">
                    <input type="hidden" name="action" value="add_admin">
                    <div style="font-size: 14px; margin-bottom: 16px; color: var(--text-secondary);">添加新管理员</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" class="form-control" name="admin_username" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="text" class="form-control" name="admin_password" required>
                        </div>
                        <div class="form-group">
                            <label>角色</label>
                            <select class="form-control" name="admin_role">
                                <option value="admin">普通管理员</option>
                                <option value="super_admin">超级管理员</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">添加管理员</button>
                </form>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>用户名</th>
                                <th>角色</th>
                                <th>最后登录</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admin_users as $a): ?>
                            <tr>
                                <td><?php echo $a['id']; ?></td>
                                <td><?php echo e($a['username']); ?></td>
                                <td><?php echo $a['role'] === 'super_admin' ? '<span class="badge badge-success">超级管理员</span>' : '<span class="badge badge-info">管理员</span>'; ?></td>
                                <td><?php echo format_date($a['last_login'] ?? null); ?></td>
                                <td>
                                    <button onclick="document.getElementById('pwdModal').classList.add('active'); document.getElementById('pwd_admin_id').value='<?php echo $a['id']; ?>';" class="btn btn-sm btn-primary">修改密码</button>
                                    <?php if ($a['id'] != 1): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_admin">
                                            <input type="hidden" name="admin_id" value="<?php echo $a['id']; ?>">
                                            <button class="btn btn-sm btn-danger" onclick="return confirm('确定要删除此管理员？')">删除</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-title">系统信息</div>
                <div class="credentials-box">
                    <div class="credentials-row">
                        <span class="label">商城版本</span>
                        <span class="value"><?php echo config('app.version'); ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">PHP 版本</span>
                        <span class="value"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">数据库</span>
                        <span class="value">MySQL</span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">总用户数</span>
                        <span class="value"><?php echo Database::fetch("SELECT COUNT(*) as c FROM users")['c']; ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">总订单数</span>
                        <span class="value"><?php echo Database::fetch("SELECT COUNT(*) as c FROM orders")['c']; ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">总主机数</span>
                        <span class="value"><?php echo Database::fetch("SELECT COUNT(*) as c FROM hosts")['c']; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="pwdModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>修改管理员密码</h3>
                <button class="modal-close" onclick="document.getElementById('pwdModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="change_pwd">
                <input type="hidden" name="admin_id" id="pwd_admin_id">
                <div class="form-group">
                    <label>新密码</label>
                    <input type="text" class="form-control" name="new_password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认修改</button>
            </form>
        </div>
    </div>
</body>
</html>
