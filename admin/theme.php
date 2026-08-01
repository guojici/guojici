<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$current_theme = db_get_setting('site_theme', 'business');
$flash_success = flash('success');
$flash_error = flash('error');

if (is_post()) {
    $theme = post('theme', 'business');
    if (in_array($theme, ['business', 'dark-tech', 'modern-minimal'])) {
        db_set_setting('site_theme', $theme);
        flash('success', '主题已切换为：' . theme_name($theme));
    } else {
        flash('error', '无效的主题选择');
    }
    header('Location: /admin/theme.php');
    exit;
}

function theme_name($theme) {
    $names = [
        'business' => '简洁商务风',
        'dark-tech' => '深色科技风',
        'modern-minimal' => '现代极简风'
    ];
    return $names[$theme] ?? '未知';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主题配置 - 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
    body {
        background: var(--bg-page);
    }
    .admin-container {
        display: flex;
        min-height: 100vh;
    }
    .sidebar {
        width: 240px;
        background: var(--bg-sidebar);
        min-height: 100vh;
        padding: 20px 0;
        border-right: 1px solid var(--border-sidebar);
        position: fixed;
        left: 0;
        top: 0;
    }
    .main-content {
        flex: 1;
        margin-left: 240px;
        padding: 24px;
    }
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 20px;
        font-weight: 600;
        color: var(--text-primary);
    }
    .theme-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 24px;
        margin-bottom: 20px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .theme-card:hover {
        border-color: var(--primary);
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    .theme-card.selected {
        border-color: var(--primary);
        box-shadow: var(--shadow-lg);
    }
    .theme-card.selected::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--primary-gradient);
    }
    .theme-preview {
        width: 100%;
        height: 160px;
        border-radius: var(--radius);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    .theme-preview::after {
        content: '';
        position: absolute;
        inset: 8px;
        border: 2px solid rgba(255,255,255,0.2);
        border-radius: var(--radius-sm);
    }
    .theme-demo {
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }
    .demo-bar {
        background: var(--primary);
        border-radius: 2px 2px 0 0;
        opacity: 0.8;
    }
    .demo-bar:nth-child(1) { height: 24px; width: 12px; }
    .demo-bar:nth-child(2) { height: 40px; width: 12px; }
    .demo-bar:nth-child(3) { height: 28px; width: 12px; }
    .demo-bar:nth-child(4) { height: 36px; width: 12px; }
    .demo-bar:nth-child(5) { height: 16px; width: 12px; }
    .demo-bar:nth-child(6) { height: 48px; width: 12px; }
    .demo-bar:nth-child(7) { height: 32px; width: 12px; }
    .demo-bar:nth-child(8) { height: 20px; width: 12px; }
    .theme-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .theme-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
    }
    .theme-desc {
        font-size: 13px;
        color: var(--text-secondary);
        margin-top: 4px;
    }
    .theme-select-btn {
        padding: 6px 14px;
        border: 1px solid var(--primary);
        border-radius: var(--radius-sm);
        color: var(--primary);
        font-size: 13px;
        font-weight: 500;
        background: transparent;
        cursor: pointer;
        transition: all 0.2s;
    }
    .theme-select-btn:hover {
        background: var(--primary);
        color: #fff;
    }
    .theme-card.selected .theme-select-btn {
        background: var(--primary);
        color: #fff;
    }
    .check-icon {
        font-size: 16px;
        color: var(--success);
        display: none;
    }
    .theme-card.selected .check-icon {
        display: block;
    }
    .theme-card.selected .theme-select-btn {
        display: none;
    }
    .theme-colors {
        display: flex;
        gap: 6px;
        margin-top: 12px;
    }
    .color-dot {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        border: 2px solid var(--border);
    }
    .color-dot.primary { background: var(--primary); }
    .color-dot.bg { background: var(--bg-page); }
    .color-dot.card { background: var(--bg-card); }
    .color-dot.text { background: var(--text-primary); }
    .submit-section {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding-top: 20px;
        border-top: 1px solid var(--border-light);
    }
    .btn {
        padding: 8px 20px;
        border-radius: var(--radius);
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
    }
    .btn-primary {
        background: var(--primary);
        color: #fff;
    }
    .btn-primary:hover {
        background: var(--primary-hover);
        box-shadow: var(--primary-shadow);
    }
    .btn-secondary {
        background: var(--bg-secondary);
        color: var(--text-primary);
    }
    .btn-secondary:hover {
        background: var(--bg-hover);
    }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include '_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <h1 class="page-title">主题配置</h1>
                <span class="badge" style="background: var(--primary-lighter); color: var(--primary); padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 500;">
                    当前主题：<?php echo theme_name($current_theme); ?>
                </span>
            </div>

            <?php if ($flash_success): ?>
            <div style="background: var(--success-light); color: var(--success); padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 14px;">
                ✅ <?php echo $flash_success; ?>
            </div>
            <?php endif; ?>

            <form method="post" id="theme-form">
                <input type="hidden" name="theme" id="selected-theme" value="<?php echo $current_theme; ?>">

                <div class="theme-card <?php echo $current_theme === 'business' ? 'selected' : ''; ?>" onclick="selectTheme('business')">
                    <div class="theme-preview" style="background: linear-gradient(135deg, #f5f7fa 0%, #e6f4ff 100%);">
                        <div class="theme-demo">
                            <div class="demo-bar" style="background: #1677ff;"></div>
                            <div class="demo-bar" style="background: #4096ff;"></div>
                            <div class="demo-bar" style="background: #1677ff;"></div>
                            <div class="demo-bar" style="background: #4096ff;"></div>
                            <div class="demo-bar" style="background: #1677ff;"></div>
                            <div class="demo-bar" style="background: #4096ff;"></div>
                            <div class="demo-bar" style="background: #1677ff;"></div>
                            <div class="demo-bar" style="background: #4096ff;"></div>
                        </div>
                    </div>
                    <div class="theme-info">
                        <div>
                            <div class="theme-name">简洁商务风</div>
                            <div class="theme-desc">清爽的蓝色主调，适合企业级云计算平台</div>
                        </div>
                        <div>
                            <span class="check-icon">✓</span>
                            <button type="button" class="theme-select-btn">选择</button>
                        </div>
                    </div>
                    <div class="theme-colors">
                        <div class="color-dot primary" style="background: #1677ff;"></div>
                        <div class="color-dot bg" style="background: #f5f7fa;"></div>
                        <div class="color-dot card" style="background: #ffffff;"></div>
                        <div class="color-dot text" style="background: #1d2129;"></div>
                    </div>
                </div>

                <div class="theme-card <?php echo $current_theme === 'dark-tech' ? 'selected' : ''; ?>" onclick="selectTheme('dark-tech')">
                    <div class="theme-preview" style="background: linear-gradient(135deg, #0a1628 0%, #0f172a 50%, #1e293b 100%);">
                        <div class="theme-demo">
                            <div class="demo-bar" style="background: #00d4ff;"></div>
                            <div class="demo-bar" style="background: #7c3aed;"></div>
                            <div class="demo-bar" style="background: #00d4ff;"></div>
                            <div class="demo-bar" style="background: #7c3aed;"></div>
                            <div class="demo-bar" style="background: #00d4ff;"></div>
                            <div class="demo-bar" style="background: #7c3aed;"></div>
                            <div class="demo-bar" style="background: #00d4ff;"></div>
                            <div class="demo-bar" style="background: #7c3aed;"></div>
                        </div>
                    </div>
                    <div class="theme-info">
                        <div>
                            <div class="theme-name">深色科技风</div>
                            <div class="theme-desc">炫酷的暗色主题，科技感十足的视觉体验</div>
                        </div>
                        <div>
                            <span class="check-icon">✓</span>
                            <button type="button" class="theme-select-btn">选择</button>
                        </div>
                    </div>
                    <div class="theme-colors">
                        <div class="color-dot primary" style="background: #00d4ff;"></div>
                        <div class="color-dot bg" style="background: #0a1628;"></div>
                        <div class="color-dot card" style="background: #1a2744;"></div>
                        <div class="color-dot text" style="background: #ffffff;"></div>
                    </div>
                </div>

                <div class="theme-card <?php echo $current_theme === 'modern-minimal' ? 'selected' : ''; ?>" onclick="selectTheme('modern-minimal')">
                    <div class="theme-preview" style="background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);">
                        <div class="theme-demo">
                            <div class="demo-bar" style="background: #000000;"></div>
                            <div class="demo-bar" style="background: #333333;"></div>
                            <div class="demo-bar" style="background: #000000;"></div>
                            <div class="demo-bar" style="background: #333333;"></div>
                            <div class="demo-bar" style="background: #000000;"></div>
                            <div class="demo-bar" style="background: #333333;"></div>
                            <div class="demo-bar" style="background: #000000;"></div>
                            <div class="demo-bar" style="background: #333333;"></div>
                        </div>
                    </div>
                    <div class="theme-info">
                        <div>
                            <div class="theme-name">现代极简风</div>
                            <div class="theme-desc">极简黑白配色，干净利落的设计风格</div>
                        </div>
                        <div>
                            <span class="check-icon">✓</span>
                            <button type="button" class="theme-select-btn">选择</button>
                        </div>
                    </div>
                    <div class="theme-colors">
                        <div class="color-dot primary" style="background: #000000;"></div>
                        <div class="color-dot bg" style="background: #ffffff;"></div>
                        <div class="color-dot card" style="background: #ffffff;"></div>
                        <div class="color-dot text" style="background: #000000;"></div>
                    </div>
                </div>

                <div class="submit-section">
                    <button type="button" class="btn btn-secondary" onclick="location.href='/admin/settings.php'">取消</button>
                    <button type="submit" class="btn btn-primary">应用主题</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function selectTheme(theme) {
        document.getElementById('selected-theme').value = theme;
        document.querySelectorAll('.theme-card').forEach(function(card) {
            card.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
    }
    </script>
</body>
</html>