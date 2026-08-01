<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();
migrate_new_tables();

$packages = Database::fetchAll("SELECT * FROM packages ORDER BY sort_order ASC, id ASC");
$edit_pkg = null;
if (get('edit')) {
    $edit_pkg = Database::fetch("SELECT * FROM packages WHERE id = ?", [intval(get('edit'))]);
}

// 获取分类列表
$categories = Database::fetchAll("SELECT * FROM package_categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");

if (is_post()) {
    $data = [
        'name' => trim(post('name')),
        'category_id' => intval(post('category_id', 0)),
        'description' => trim(post('description')),
        'price_monthly' => floatval(post('price_monthly')),
        'webdx' => intval(post('webdx', 1000)),
        'sqldx' => intval(post('sqldx', 500)),
        'sizemax' => intval(post('sizemax', 50)),
        'ymbds' => intval(post('ymbds', 5)),
        'type' => intval(post('type', 2)),
        'is_kvm' => intval(post('is_kvm', 0)),
        'is_nat_kvm' => intval(post('is_nat_kvm', 0)),
        'kvm_vcpu' => intval(post('kvm_vcpu', 2)),
        'kvm_memory_mb' => intval(post('kvm_memory_mb', 2048)),
        'kvm_disk_gb' => intval(post('kvm_disk_gb', 40)),
        'kvm_bandwidth_mbps' => intval(post('kvm_bandwidth_mbps', 100)),
        'kvm_traffic_gb' => intval(post('kvm_traffic_gb', 100)),
        'is_recommended' => intval(post('is_recommended', 0)),
        'sort_order' => intval(post('sort_order', 0)),
        'status' => post('status', 'active'),
    ];
    if ($data['is_nat_kvm']) {
        $data['is_kvm'] = 1;
    }
    if (post('pkg_id')) {
        Database::update('packages', $data, 'id = ?', [intval(post('pkg_id'))]);
        flash('success', '套餐已更新');
    } else {
        Database::insert('packages', $data);
        flash('success', '套餐已添加');
    }
    header('Location: /admin/packages.php');
    exit;
}

if (get('del')) {
    Database::delete('packages', 'id = ?', [intval(get('del'))]);
    flash('success', '套餐已删除');
    header('Location: /admin/packages.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>套餐管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .kvm-fields { display: none; }
        .kvm-fields.active { display: block; }
        .host-fields.hidden { display: none; }
        .kvm-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .kvm-box strong { font-size: 15px; }
        .kvm-box .hint { opacity: 0.85; margin-top: 6px; }
        .pkg-type-card { display: flex; gap: 12px; margin-bottom: 16px; }
        .pkg-type-option { flex: 1; padding: 16px; border: 2px solid #e5e6eb; border-radius: 8px; cursor: pointer; transition: all 0.2s; text-align: center; }
        .pkg-type-option:hover { border-color: #91caff; }
        .pkg-type-option.selected { border-color: #1677ff; background: #e6f4ff; }
        .pkg-type-option .type-icon { font-size: 28px; margin-bottom: 8px; }
        .pkg-type-option .type-name { font-size: 15px; font-weight: 600; color: #1d2129; }
        .pkg-type-option .type-desc { font-size: 12px; color: #86909c; margin-top: 4px; }
        .form-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">套餐管理</h1>
                    <p class="page-subtitle">配置主机套餐 / KVM服务器套餐</p>
                </div>
                <a href="/admin/packages.php" class="btn btn-secondary">刷新</a>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-title"><?php echo $edit_pkg ? '编辑套餐' : '添加/编辑套餐'; ?></div>
                <form method="POST">
                    <input type="hidden" name="pkg_id" value="<?php echo $edit_pkg['id'] ?? ''; ?>">

                    <!-- 套餐类型选择 -->
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; font-size:13px; color:#86909c; margin-bottom:10px; font-weight:600;">套餐类型 *</label>
                        <div class="pkg-type-card">
                            <div class="pkg-type-option <?php echo ($edit_pkg['is_kvm'] ?? 0) == 0 ? 'selected' : ''; ?>" onclick="selectPkgType('host', this)">
                                <div class="type-icon">🖥️</div>
                                <div class="type-name">虚拟主机</div>
                                <div class="type-desc">传统虚拟主机，开箱即用</div>
                            </div>
                            <div class="pkg-type-option <?php echo ($edit_pkg['is_kvm'] ?? 0) == 1 && empty($edit_pkg['is_nat_kvm']) ? 'selected' : ''; ?>" onclick="selectPkgType('kvm', this)">
                                <div class="type-icon">💻</div>
                                <div class="type-name">KVM 服务器</div>
                                <div class="type-desc">独立IP，完全ROOT权限</div>
                            </div>
                            <div class="pkg-type-option <?php echo !empty($edit_pkg['is_nat_kvm']) ? 'selected' : ''; ?>" onclick="selectPkgType('nat_kvm', this)">
                                <div class="type-icon">🌐</div>
                                <div class="type-name">NAT KVM</div>
                                <div class="type-desc">共享公网IP，端口映射访问</div>
                            </div>
                        </div>
                        <input type="hidden" name="is_kvm" id="isKvmInput" value="<?php echo $edit_pkg['is_kvm'] ?? 0; ?>">
                        <input type="hidden" name="is_nat_kvm" id="isNatKvmInput" value="<?php echo $edit_pkg['is_nat_kvm'] ?? 0; ?>">
                    </div>

                    <div class="kvm-box" id="kvmTip">
                        <strong>💡 KVM 服务器套餐说明</strong>
                        <div class="hint">KVM 套餐将让用户在购买时选择操作系统镜像，支付成功后系统自动创建虚拟机。KVM套餐可同时兼容虚拟主机（image_id=0）和KVM镜像（image_id>0）两种交付方式。</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>套餐名称 *</label>
                            <input type="text" class="form-control" name="name" required value="<?php echo e($edit_pkg['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>所属分类</label>
                            <select class="form-control" name="category_id">
                                <option value="0">-- 未分类 --</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_pkg['category_id'] ?? 0) == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>月价格 (¥) *</label>
                            <input type="number" step="0.01" class="form-control" name="price_monthly" required value="<?php echo $edit_pkg['price_monthly'] ?? 0; ?>">
                        </div>
                    </div>

                    <!-- KVM 服务器规格 -->
                    <div class="kvm-fields <?php echo ($edit_pkg['is_kvm'] ?? 0) == 1 ? 'active' : ''; ?>" id="kvmFields">
                        <div style="font-size:13px; font-weight:600; color:#1d2129; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #e5e6eb;">KVM 服务器规格</div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>CPU (核)</label>
                                <input type="number" class="form-control" name="kvm_vcpu" value="<?php echo $edit_pkg['kvm_vcpu'] ?? 2; ?>">
                            </div>
                            <div class="form-group">
                                <label>内存 (MB)</label>
                                <input type="number" class="form-control" name="kvm_memory_mb" value="<?php echo $edit_pkg['kvm_memory_mb'] ?? 2048; ?>">
                            </div>
                            <div class="form-group">
                                <label>磁盘 (GB)</label>
                                <input type="number" class="form-control" name="kvm_disk_gb" value="<?php echo $edit_pkg['kvm_disk_gb'] ?? 40; ?>">
                            </div>
                            <div class="form-group">
                                <label>峰值带宽 (Mbps)</label>
                                <input type="number" class="form-control" name="kvm_bandwidth_mbps" value="<?php echo $edit_pkg['kvm_bandwidth_mbps'] ?? 100; ?>" min="1" max="10000">
                            </div>
                            <div class="form-group">
                                <label>月流量 (GB)</label>
                                <input type="number" class="form-control" name="kvm_traffic_gb" value="<?php echo $edit_pkg['kvm_traffic_gb'] ?? 100; ?>" min="0">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:8px;">
                            <label>套餐说明（显示在购买页）</label>
                            <input type="text" class="form-control" name="description" placeholder="如：2核 2GB 40GB SSD，独立KVM虚拟机" value="<?php echo e($edit_pkg['description'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- 虚拟主机规格 -->
                    <div class="host-fields <?php echo ($edit_pkg['is_kvm'] ?? 0) == 1 ? 'hidden' : ''; ?>" id="hostFields">
                        <div style="font-size:13px; font-weight:600; color:#1d2129; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #e5e6eb;">虚拟主机规格</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>网页空间 (MB)</label>
                                <input type="number" class="form-control" name="webdx" value="<?php echo $edit_pkg['webdx'] ?? 1000; ?>">
                            </div>
                            <div class="form-group">
                                <label>数据库 (MB)</label>
                                <input type="number" class="form-control" name="sqldx" value="<?php echo $edit_pkg['sqldx'] ?? 500; ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>月流量 (GB)</label>
                                <input type="number" class="form-control" name="sizemax" value="<?php echo $edit_pkg['sizemax'] ?? 50; ?>">
                            </div>
                            <div class="form-group">
                                <label>域名绑定数</label>
                                <input type="number" class="form-control" name="ymbds" value="<?php echo $edit_pkg['ymbds'] ?? 5; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top:16px;">
                        <div class="form-group">
                            <label>排序 (越小越靠前)</label>
                            <input type="number" class="form-control" name="sort_order" value="<?php echo $edit_pkg['sort_order'] ?? 0; ?>">
                        </div>
                        <div class="form-group">
                            <label>状态</label>
                            <select class="form-control" name="status">
                                <option value="active" <?php echo ($edit_pkg['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>启用</option>
                                <option value="disabled" <?php echo ($edit_pkg['status'] ?? 'active') == 'disabled' ? 'selected' : ''; ?>>禁用</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 20px; align-items: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                        <label><input type="checkbox" name="is_recommended" value="1" <?php echo !empty($edit_pkg['is_recommended']) ? 'checked' : ''; ?>> 标记为推荐</label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 20px;">保存套餐</button>
                    <?php if ($edit_pkg): ?>
                        <a href="/admin/packages.php" class="btn btn-secondary">取消</a>
                    <?php endif; ?>
                </form>
            </div>

            <script>
            function selectPkgType(type, el) {
                document.querySelectorAll('.pkg-type-option').forEach(function(o) { o.classList.remove('selected'); });
                el.classList.add('selected');
                var kvmFields = document.getElementById('kvmFields');
                var hostFields = document.getElementById('hostFields');
                var kvmTip = document.getElementById('kvmTip');

                if (type === 'host') {
                    document.getElementById('isKvmInput').value = 0;
                    document.getElementById('isNatKvmInput').value = 0;
                    kvmFields.classList.remove('active');
                    hostFields.classList.remove('hidden');
                    kvmTip.style.display = 'none';
                } else if (type === 'kvm') {
                    document.getElementById('isKvmInput').value = 1;
                    document.getElementById('isNatKvmInput').value = 0;
                    kvmFields.classList.add('active');
                    hostFields.classList.add('hidden');
                    kvmTip.style.display = 'block';
                    kvmTip.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                    kvmTip.querySelector('strong').innerHTML = '💡 KVM 服务器套餐说明';
                    kvmTip.querySelector('.hint').innerHTML = 'KVM 套餐将让用户在购买时选择操作系统镜像，支付成功后系统自动创建虚拟机，分配独立公网IP。';
                } else if (type === 'nat_kvm') {
                    document.getElementById('isKvmInput').value = 1;
                    document.getElementById('isNatKvmInput').value = 1;
                    kvmFields.classList.add('active');
                    hostFields.classList.add('hidden');
                    kvmTip.style.display = 'block';
                    kvmTip.style.background = 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)';
                    kvmTip.querySelector('strong').innerHTML = '🌐 NAT KVM 套餐说明';
                    kvmTip.querySelector('.hint').innerHTML = 'NAT KVM 套餐使用共享公网IP，通过端口映射访问虚拟机，价格更实惠，适合个人学习和测试使用。';
                }
            }
            </script>

            <?php
            // 构建分类ID到名称的映射
            $cat_name_map = [];
            foreach ($categories as $cat) {
                $cat_name_map[$cat['id']] = $cat['name'];
            }
            ?>
            <div class="card">
                <div class="card-title">现有套餐列表（共 <?php echo count($packages); ?> 个）</div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>分类</th>
                                <th>类型</th>
                                <th>月租</th>
                                <th>规格</th>
                                <th>推荐</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($packages as $p): ?>
                            <tr>
                                <td><?php echo $p['id']; ?></td>
                                <td><?php echo e($p['name']); ?></td>
                                <td>
                                    <?php if (!empty($p['category_id']) && !empty($cat_name_map[$p['category_id']])): ?>
                                        <span style="background:#e6f4ff; color:#1677ff; padding:2px 8px; border-radius:4px; font-size:12px;"><?php echo e($cat_name_map[$p['category_id']]); ?></span>
                                    <?php else: ?>
                                        <span style="color:#86909c; font-size:12px;">--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['is_nat_kvm'])): ?>
                                        <span style="background: linear-gradient(135deg,#f59e0b,#d97706); color:#fff; padding:3px 10px; border-radius:10px; font-size:12px; font-weight:600;">🌐 NAT KVM</span>
                                    <?php elseif (!empty($p['is_kvm'])): ?>
                                        <span style="background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; padding:3px 10px; border-radius:10px; font-size:12px; font-weight:600;">💻 KVM</span>
                                    <?php else: ?>
                                        <span style="background:#f0f0f0; color:#666; padding:3px 10px; border-radius:10px; font-size:12px;">🖥️ 虚拟主机</span>
                                    <?php endif; ?>
                                </td>
                                <td>¥<?php echo number_format($p['price_monthly'], 2); ?></td>
                                <td style="font-size:12px; color:#4e5969;">
                                    <?php if (!empty($p['is_kvm'])): ?>
                                        <?php echo intval($p['kvm_vcpu']); ?>核 / <?php echo intval($p['kvm_memory_mb']); ?>MB / <?php echo intval($p['kvm_disk_gb']); ?>GB / <?php echo intval($p['kvm_bandwidth_mbps']); ?>Mbps / <?php echo intval($p['kvm_traffic_gb']); ?>GB流量
                                    <?php else: ?>
                                        <?php echo $p['webdx']; ?>MB / <?php echo $p['sqldx']; ?>MB / <?php echo $p['sizemax']; ?>GB
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $p['is_recommended'] ? '⭐' : ''; ?></td>
                                <td><?php echo $p['status'] === 'active' ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-secondary">禁用</span>'; ?></td>
                                <td>
                                    <a href="/admin/packages.php?edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary">编辑</a>
                                    <a href="/admin/packages.php?del=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('确定删除此套餐？')">删除</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
