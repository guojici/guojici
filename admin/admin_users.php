<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();
migrate_new_tables();

require_permission('admin:view');

$action = get('action', 'list');
$search = trim(get('search', ''));
$status = get('status', '');
$role_id = intval(get('role_id', 0));
$page = max(1, intval(get('page', 1)));
$page_size = 20;

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (au.username LIKE ? OR au.last_ip LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
}
if ($status) {
    $where .= " AND au.status = ?";
    $params[] = $status;
}
if ($role_id > 0) {
    $where .= " AND au.role_id = ?";
    $params[] = $role_id;
}

// 检查admin_users是否有status字段
$has_status = false;
try {
    $col = Database::query("SHOW COLUMNS FROM admin_users LIKE 'status'")->fetch();
    $has_status = !empty($col);
} catch (Exception $e) {}

if (!$has_status) {
    try {
        Database::query("ALTER TABLE admin_users ADD COLUMN status ENUM('active','disabled') DEFAULT 'active' AFTER password");
        $has_status = true;
    } catch (Exception $e) {}
}

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (au.username LIKE ? OR au.last_ip LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
}
if ($status && $has_status) {
    $where .= " AND au.status = ?";
    $params[] = $status;
}
if ($role_id > 0) {
    $where .= " AND au.role_id = ?";
    $params[] = $role_id;
}

// 处理POST请求
if (is_post()) {
    $post_action = post('action');

    if ($post_action === 'create') {
        require_permission('admin:create');
        $username = trim(post('username', ''));
        $password = post('password', '');
        $role_id_new = intval(post('role_id', 0));
        $status_new = post('status', 'active');

        if ($username === '' || $password === '') {
            flash('error', '用户名和密码不能为空');
        } elseif (strlen($password) < 6) {
            flash('error', '密码长度不能少于6位');
        } elseif ($role_id_new <= 0) {
            flash('error', '请选择角色');
        } else {
            $existing = Database::fetch("SELECT id FROM admin_users WHERE username = ?", [$username]);
            if ($existing) {
                flash('error', '用户名已存在');
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                Database::insert('admin_users', [
                    'username' => $username,
                    'password' => $hashed,
                    'role' => 'admin',
                    'role_id' => $role_id_new,
                    'status' => $has_status ? $status_new : 'active',
                ]);
                flash('success', '管理员创建成功');
            }
        }
        header('Location: /admin/admin_users.php');
        exit;
    }

    if ($post_action === 'update') {
        require_permission('admin:edit');
        $admin_id = intval(post('admin_id'));
        $role_id_new = intval(post('role_id', 0));
        $status_new = post('status', 'active');
        $new_password = post('new_password', '');

        $admin = Database::fetch("SELECT * FROM admin_users WHERE id = ?", [$admin_id]);
        if (!$admin) {
            flash('error', '管理员不存在');
        } elseif ($role_id_new <= 0) {
            flash('error', '请选择角色');
        } else {
            $update_data = ['role_id' => $role_id_new];
            if ($has_status) {
                $update_data['status'] = $status_new;
            }
            if (!empty($new_password)) {
                if (strlen($new_password) < 6) {
                    flash('error', '密码长度不能少于6位');
                    header('Location: /admin/admin_users.php');
                    exit;
                }
                $update_data['password'] = password_hash($new_password, PASSWORD_DEFAULT);
            }

            Database::update('admin_users', $update_data, 'id = ?', [$admin_id]);

            @Database::insert('admin_logs', [
                'admin_id' => admin_user()['id'],
                'action' => 'admin_update',
                'target_type' => 'admin',
                'target_id' => $admin_id,
                'detail' => '更新管理员账号: ' . $admin['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            flash('success', '管理员信息已更新');
        }
        header('Location: /admin/admin_users.php');
        exit;
    }

    if ($post_action === 'delete') {
        require_permission('admin:delete');
        $admin_id = intval(post('admin_id'));
        $admin = Database::fetch("SELECT * FROM admin_users WHERE id = ?", [$admin_id]);
        if (!$admin) {
            flash('error', '管理员不存在');
        } elseif ($admin['role'] === 'super_admin') {
            flash('error', '超级管理员不可删除');
        } elseif ($admin_id == admin_user()['id']) {
            flash('error', '不能删除自己');
        } else {
            Database::query("DELETE FROM admin_users WHERE id = ?", [$admin_id]);
            flash('success', '管理员已删除');
        }
        header('Location: /admin/admin_users.php');
        exit;
    }

    if ($post_action === 'toggle_status') {
        require_permission('admin:edit');
        $admin_id = intval(post('admin_id'));
        $admin = Database::fetch("SELECT * FROM admin_users WHERE id = ?", [$admin_id]);
        if (!$admin) {
            flash('error', '管理员不存在');
        } elseif ($admin['role'] === 'super_admin') {
            flash('error', '超级管理员不可禁用');
        } elseif ($admin_id == admin_user()['id']) {
            flash('error', '不能禁用自己');
        } else {
            $new_status = $admin['status'] === 'active' ? 'disabled' : 'active';
            Database::update('admin_users', ['status' => $new_status], 'id = ?', [$admin_id]);
            flash('success', '已' . ($new_status === 'active' ? '启用' : '禁用') . '管理员');
        }
        header('Location: /admin/admin_users.php');
        exit;
    }
}

// 获取总数
$total = Database::fetch("SELECT COUNT(*) as cnt FROM admin_users au WHERE $where", $params);
$total_count = intval($total['cnt'] ?? 0);
$total_pages = ceil($total_count / $page_size);
$offset = ($page - 1) * $page_size;

// 获取列表
$admins = Database::fetchAll("SELECT au.id, au.username, au.role, au.role_id, au.status, au.last_login, au.last_ip, au.created_at,
    r.role_name, r.role_key
    FROM admin_users au
    LEFT JOIN admin_roles r ON au.role_id = r.id
    WHERE $where ORDER BY au.id DESC LIMIT $offset, $page_size", $params);

// 获取角色列表
$roles = Database::fetchAll("SELECT * FROM admin_roles WHERE status = 'active' ORDER BY sort_order ASC");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include '_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">管理员管理</h1>
                    <p class="page-subtitle">管理后台管理员账号与角色分配</p>
                </div>
                <?php if (admin_has_permission('admin:create')): ?>
                    <button onclick="showCreateModal()" class="btn btn-primary">+ 添加管理员</button>
                <?php endif; ?>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索用户名/IP..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/admin_users.php?search='+encodeURIComponent(this.value)+'&status=<?php echo urlencode($status); ?>&role_id=<?php echo $role_id; ?>';">
                    </div>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/admin_users.php?role_id='+this.value+'&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>';">
                        <option value="0">全部角色</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo $role_id == $r['id'] ? 'selected' : ''; ?>><?php echo e($r['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($has_status): ?>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/admin_users.php?status='+this.value+'&role_id=<?php echo $role_id; ?>&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部状态</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>启用</option>
                        <option value="disabled" <?php echo $status === 'disabled' ? 'selected' : ''; ?>>禁用</option>
                    </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>用户名</th>
                                <th>角色</th>
                                <th>状态</th>
                                <th>最后登录</th>
                                <th>最后IP</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($admins)): ?>
                            <tr><td colspan="8" style="text-align:center; color:#86909c; padding:24px;">暂无管理员</td></tr>
                            <?php else: ?>
                            <?php foreach ($admins as $a): ?>
                            <tr>
                                <td><?php echo $a['id']; ?></td>
                                <td>
                                    <strong><?php echo e($a['username']); ?></strong>
                                    <?php if ($a['role'] === 'super_admin'): ?>
                                        <span style="background: linear-gradient(135deg, #667eea, #764ba2); color:#fff; font-size: 11px; padding: 2px 6px; border-radius: 10px; margin-left: 4px;">超级</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-primary"><?php echo e($a['role_name'] ?? '--'); ?></span>
                                </td>
                                <td>
                                    <?php if ($has_status): ?>
                                        <span class="badge badge-<?php echo $a['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo $a['status'] === 'active' ? '启用' : '禁用'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success">启用</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo format_date($a['last_login'] ?? '-'); ?></td>
                                <td style="font-family: monospace; font-size: 12px;"><?php echo e($a['last_ip'] ?? '-'); ?></td>
                                <td><?php echo format_date($a['created_at']); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if (admin_has_permission('admin:edit')): ?>
                                        <button onclick="showEditModal(<?php echo $a['id']; ?>, '<?php echo e($a['username']); ?>', <?php echo intval($a['role_id'] ?? 0); ?>, '<?php echo $a['status'] ?? 'active'; ?>')" class="btn btn-sm btn-outline">编辑</button>
                                    <?php endif; ?>
                                    <?php if ($has_status && $a['role'] !== 'super_admin' && $a['id'] != admin_user()['id'] && admin_has_permission('admin:edit')): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="admin_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary"><?php echo $a['status'] === 'active' ? '禁用' : '启用'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($a['role'] !== 'super_admin' && $a['id'] != admin_user()['id'] && admin_has_permission('admin:delete')): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('确定删除此管理员？');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="admin_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="/admin/admin_users.php?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&role_id=<?php echo $role_id; ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    <span class="page-info">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，共 <?php echo $total_count; ?> 条</span>
                    <?php if ($page < $total_pages): ?>
                        <a href="/admin/admin_users.php?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&role_id=<?php echo $role_id; ?>" class="page-btn">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 添加管理员弹窗 -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>添加管理员</h3>
                <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>用户名 <span style="color:#ef4444;">*</span></label>
                    <input type="text" class="form-control" name="username" placeholder="请输入登录用户名" required>
                </div>
                <div class="form-group">
                    <label>初始密码 <span style="color:#ef4444;">*</span></label>
                    <input type="text" class="form-control" name="password" placeholder="至少6位" required minlength="6">
                </div>
                <div class="form-group">
                    <label>角色 <span style="color:#ef4444;">*</span></label>
                    <select class="form-control" name="role_id" required>
                        <option value="">请选择角色</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo e($r['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($has_status): ?>
                <div class="form-group">
                    <label>状态</label>
                    <select class="form-control" name="status">
                        <option value="active">启用</option>
                        <option value="disabled">禁用</option>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="width: 100%;">创建管理员</button>
            </form>
        </div>
    </div>

    <!-- 编辑管理员弹窗 -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>编辑管理员</h3>
                <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="admin_id" id="edit_admin_id">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" class="form-control" id="edit_username" readonly style="background: #f5f7fa;">
                </div>
                <div class="form-group">
                    <label>角色 <span style="color:#ef4444;">*</span></label>
                    <select class="form-control" name="role_id" id="edit_role_id" required>
                        <option value="">请选择角色</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo e($r['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($has_status): ?>
                <div class="form-group">
                    <label>状态</label>
                    <select class="form-control" name="status" id="edit_status">
                        <option value="active">启用</option>
                        <option value="disabled">禁用</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>重置密码（留空则不修改）</label>
                    <input type="text" class="form-control" name="new_password" placeholder="至少6位，留空不修改">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">保存修改</button>
            </form>
        </div>
    </div>

    <script>
    function showCreateModal() {
        document.getElementById('createModal').classList.add('active');
    }
    function showEditModal(id, username, roleId, status) {
        document.getElementById('edit_admin_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_role_id').value = roleId;
        var statusSel = document.getElementById('edit_status');
        if (statusSel) statusSel.value = status;
        document.getElementById('editModal').classList.add('active');
    }
    </script>
</body>
</html>
