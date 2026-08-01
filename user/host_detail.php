<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$user = auth_user();
$uid = auth_id();
$id_param = get('id', '');

$host = null;
if (is_numeric($id_param)) {
    $host_id = intval($id_param);
    $host = Database::fetch("SELECT h.*, p.name as package_name, p.webdx, p.sqldx, p.sizemax, p.ymbds FROM hosts h LEFT JOIN packages p ON h.package_id = p.id WHERE h.id = ? AND h.user_id = ?", [$host_id, $uid]);
} else {
    $host_uuid = $id_param;
    $host = Database::fetch("SELECT h.*, p.name as package_name, p.webdx, p.sqldx, p.sizemax, p.ymbds FROM hosts h LEFT JOIN packages p ON h.package_id = p.id WHERE h.uuid = ? AND h.user_id = ?", [$host_uuid, $uid]);
}

if (!$host) {
    flash('error', '主机不存在');
    header('Location: /user/hosts.php');
    exit;
}

$host_id = $host['id'];
$host_uuid = $host['uuid'] ?? $host_id;

$mnbt_url = config('mnbt.base_url');

// 处理操作
if (is_post()) {
    $action = post('action');
    // 核验码验证：所有主机操作均需验证
    if (in_array($action, ['reset_password', 'suspend', 'unsuspend', 'delete', 'vm_start', 'vm_stop', 'vm_delete'])) {
        license_require_for_service('虚拟主机管理');
    }
    $api = mnbt_api();

    if ($action === 'reset_password') {
        $new_pwd = post('new_password');
        if (!$new_pwd || strlen($new_pwd) < 6) {
            flash('error', '密码长度至少6位');
            header("Location: /user/host_detail.php?id=$host_uuid");
            exit;
        }
        $result = $api->reset_password($host['mnbt_username'], $new_pwd);
        if ($result['code'] == 200) {
            Database::update('hosts', ['mnbt_password' => $new_pwd], 'id = ?', [$host_id]);
            flash('success', '密码重置成功: ' . $result['msg']);
        } else {
            flash('error', '操作失败: ' . ($result['msg'] ?? '未知错误'));
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'suspend') {
        $result = $api->suspend_host($host['mnbt_username']);
        if ($result['code'] == 200) {
            Database::update('hosts', ['status' => 'suspended'], 'id = ?', [$host_id]);
            flash('success', '主机已暂停: ' . $result['msg']);
        } else {
            flash('error', '操作失败: ' . ($result['msg'] ?? '未知错误'));
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'unsuspend') {
        $result = $api->unsuspend_host($host['mnbt_username']);
        if ($result['code'] == 200) {
            Database::update('hosts', ['status' => 'running'], 'id = ?', [$host_id]);
            flash('success', '主机已解除暂停: ' . $result['msg']);
        } else {
            flash('error', '操作失败: ' . ($result['msg'] ?? '未知错误'));
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'delete') {
        $result = $api->delete_host($host['mnbt_username']);
        if ($result['code'] == 200) {
            Database::update('hosts', ['status' => 'cancelled'], 'id = ?', [$host_id]);
            flash('success', '主机已删除: ' . $result['msg']);
            header('Location: /user/hosts.php');
            exit;
        } else {
            flash('error', '操作失败: ' . ($result['msg'] ?? '未知错误'));
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'vm_start') {
        if (empty($host['vm_name'])) {
            flash('error', '无效的虚拟机');
            header("Location: /user/host_detail.php?id=$host_uuid");
            exit;
        }
        $kvm = kvm_get_manager();
        $result = $kvm->startVM($host['vm_name']);
        if ($result) {
            Database::update('hosts', ['status' => 'running'], 'id = ?', [$host_id]);
            flash('success', '虚拟机启动成功');
        } else {
            flash('error', '启动失败: ' . $kvm->getError());
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'vm_stop') {
        if (empty($host['vm_name'])) {
            flash('error', '无效的虚拟机');
            header("Location: /user/host_detail.php?id=$host_uuid");
            exit;
        }
        $kvm = kvm_get_manager();
        $result = $kvm->stopVM($host['vm_name']);
        if ($result) {
            Database::update('hosts', ['status' => 'stopped'], 'id = ?', [$host_id]);
            flash('success', '虚拟机关机成功');
        } else {
            flash('error', '关机失败: ' . $kvm->getError());
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }

    if ($action === 'vm_delete') {
        if (empty($host['vm_name'])) {
            flash('error', '无效的虚拟机');
            header("Location: /user/host_detail.php?id=$host_uuid");
            exit;
        }
        $kvm = kvm_get_manager();
        $result = $kvm->destroyVM($host['vm_name']);
        if ($result) {
            kvm_cleanup_host($host_id);
            Database::update('hosts', ['status' => 'cancelled'], 'id = ?', [$host_id]);
            flash('success', '虚拟机已删除');
            header('Location: /user/hosts.php');
            exit;
        } else {
            flash('error', '删除失败: ' . $kvm->getError());
        }
        header("Location: /user/host_detail.php?id=$host_uuid");
        exit;
    }
}

$is_kvm = !empty($host['vm_name']);
$login_url = '';
if (!$is_kvm && !empty($host['mnbt_username']) && !empty($host['mnbt_password'])) {
    $login_url = mnbt_api()->get_login_url($host['mnbt_username'], $host['mnbt_password']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主机管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">主机管理</h1>
                    <p class="page-subtitle"><?php echo e($host['mnbt_username'] ?? ($host['vm_name'] ?: '主机#' . $host['id'])); ?> - <?php echo e($host['package_name']); ?></p>
                </div>
                <a href="/user/hosts.php" class="btn btn-secondary">← 返回列表</a>
            </div>

            <?php
            $error = flash('error');
            $success = flash('success');
            if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-title">
                    <span>主机状态</span>
                    <?php echo get_status_label($host['status'], 'host'); ?>
                </div>
                <div class="host-info" style="border-top: none; padding-top: 0;">
                    <?php if (!empty($host['mnbt_username'])): ?>
                    <div class="host-info-item">
                        <div class="label">主机账号</div>
                        <div class="value"><?php echo e($host['mnbt_username']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="host-info-item">
                        <div class="label">套餐</div>
                        <div class="value"><?php echo e($host['package_name']); ?></div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">网页空间</div>
                        <div class="value"><?php echo $host['webdx']; ?> MB</div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">数据库</div>
                        <div class="value"><?php echo $host['sqldx']; ?> MB</div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">月流量</div>
                        <div class="value"><?php echo $host['sizemax']; ?> GB</div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">域名绑定</div>
                        <div class="value"><?php echo $host['ymbds']; ?> 个</div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">创建时间</div>
                        <div class="value"><?php echo format_date($host['created_at']); ?></div>
                    </div>
                    <div class="host-info-item">
                        <div class="label">到期时间</div>
                        <div class="value"><?php echo format_date($host['expire_at']); ?></div>
                    </div>
                </div>
            </div>

            <?php if (!$is_kvm): ?>
            <div class="card">
                <div class="card-title">
                    <span>控制面板登录</span>
                </div>
                <div class="credentials-box">
                    <div class="credentials-row">
                        <span class="label">面板地址</span>
                        <span class="value" id="panel-url"><?php echo e($mnbt_url); ?>/user/</span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">用户名</span>
                        <span class="value"><?php echo e($host['mnbt_username'] ?? ''); ?></span>
                    </div>
                    <div class="credentials-row">
                        <span class="label">密码</span>
                        <span class="value" id="pwd-text">••••••••</span>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="<?php echo $login_url; ?>" target="_blank" class="btn btn-primary">一键登录控制面板</a>
                    <button onclick="togglePassword()" class="btn btn-secondary">显示密码</button>
                </div>
                <div style="margin-top: 16px; padding: 16px; background: rgba(245, 158, 11, 0.05); border-radius: 8px; font-size: 13px; color: var(--text-secondary);">
                    💡 提示：点击"一键登录"将自动携带账号密码跳转至面板，无需手动输入。
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title">
                    <span>主机操作</span>
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="/user/renew.php?id=<?php echo $host_id; ?>" class="btn btn-primary">🔄 续费主机</a>
                    <?php if (!$is_kvm): ?>
                    <?php if ($host['status'] == 'running'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要暂停此主机吗？暂停后网站将无法访问。');">
                            <input type="hidden" name="action" value="suspend">
                            <button type="submit" class="btn btn-warning">暂停主机</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($host['status'] == 'suspended'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要解除暂停吗？');">
                            <input type="hidden" name="action" value="unsuspend">
                            <button type="submit" class="btn btn-success">解除暂停</button>
                        </form>
                    <?php endif; ?>
                    <button onclick="showResetModal()" class="btn btn-secondary">重置密码</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除此主机吗？此操作不可恢复！');">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-danger">删除主机</button>
                    </form>
                    <?php else: ?>
                    <?php if ($host['status'] == 'running'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要关机此虚拟机吗？');">
                            <input type="hidden" name="action" value="vm_stop">
                            <button type="submit" class="btn btn-warning">关机</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($host['status'] == 'stopped'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要启动此虚拟机吗？');">
                            <input type="hidden" name="action" value="vm_start">
                            <button type="submit" class="btn btn-success">启动</button>
                        </form>
                    <?php endif; ?>
                    <button onclick="showResetModal()" class="btn btn-secondary">重置密码</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除此虚拟机吗？此操作不可恢复！');">
                        <input type="hidden" name="action" value="vm_delete">
                        <button type="submit" class="btn btn-danger">删除虚拟机</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 重置密码弹窗 -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>重置主机密码</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <div class="form-group">
                    <label>新密码</label>
                    <input type="text" class="form-control" name="new_password" required placeholder="至少6位" id="new_pwd_input">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认重置</button>
            </form>
        </div>
    </div>

    <script>
        function showResetModal() {
            document.getElementById('resetModal').classList.add('active');
            document.getElementById('new_pwd_input').focus();
        }
        function closeModal() {
            document.getElementById('resetModal').classList.remove('active');
        }
        let passwordVisible = false;
        function togglePassword() {
            passwordVisible = !passwordVisible;
            document.getElementById('pwd-text').textContent = passwordVisible ? <?php echo json_encode($host['mnbt_password'] ?? ''); ?> : '••••••••';
        }
    </script>
</body>
</html>
