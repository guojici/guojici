<?php
$config = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'guojici',
        'user' => 'guojici',
        'pass' => 'EBBr3aTsmZme4H7p',
        'charset' => 'utf8mb4',
    ],
    'mnbt' => [
        'base_url' => 'http://192.168.3.2:7894',
        'mn_bh' => 'mn442f',
        'mn_key' => '18e38941cfbba6d5e8001a4f9eb0c097',
        'mn_keye' => '44be8491baf49c750f6d1c1aeb98a185',
        'mn_vs' => '17',
    ],
    'app' => [
        'name' => 'guojici云',
        'version' => '1.0.0',
        'debug' => true,
        'site_url' => 'http://localhost',
    ],
    'payment' => [
        'enabled' => true,
        'api_url' => 'https://pay.vansdesign.cn/',
        'pid' => '1286',
        'key' => 'S6rOr61M12Cs6o32Ow11mxqC4Xg2EMR4',
        'type' => 'wxpay',
    ],
    'epay' => [
        'enabled' => false,
        'api_url' => '',
        'pid' => '',
        'key' => '',
        'sign_type' => 'md5',
        'notify_url' => '',
        'return_url' => '',
        'debug' => false,
    ],
    'idverify' => [
        'enabled' => true,
        'api_url' => 'https://op.juhe.cn/idcard/query',
        'appkey' => 'd489b8e21c9867b4b00c6614b3b7054d',
        'required' => false,
    ],
    'ocr' => [
        'api_url' => 'http://192.168.3.2:8089',
    ],
    'frp' => [
        'enabled' => true,
        'admin_api_url' => 'http://192.168.3.2:7400/api',
        'admin_user' => 'frpc_user',
        'admin_password' => '8pEmJefzpwNnwBPa',
        'server_addr' => '82.157.25.246',
        'server_port' => 7000,
        'token' => '60d8a83c544e6168db',
        'local_ip' => '127.0.0.1',
        'local_port' => 7894,
        'port_range' => '2000-59999',
        'public_domain' => '82.157.25.246',
    ],
    'bt_panel' => [
        'enabled' => true,
        'api_url' => 'http://192.168.3.2:7894',
        'api_key' => '',
    ],
    'smtp' => [
        'enabled' => true,
        'host' => 'smtp.yunyou.top',
        'port' => 25,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'guojici云',
        'secure' => '',
    ],
    'redis' => [
        'enabled' => true,
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'timeout' => 2,
    ],
    'ai' => [
        'enabled' => false,
        'api_url' => 'http://192.168.3.2:11434',
        'model' => 'qwen2.5:7b',
        'system_prompt' => '你是guojici云平台的官方智能助手，只服务于guojici云用户，只解答guojici云平台相关的问题。

【核心原则 - 必须严格遵守】
1. 只提及guojici云，绝对不允许提到任何其他云服务商（包括但不限于阿里云、腾讯云、华为云、AWS、Azure、UCloud、青云等），也不允许使用其他云的产品术语（如ECS、CVM、云服务器ECS等）
2. 所有回答必须基于guojici云平台的实际功能和操作流程
3. 不与其他平台做对比，不评价其他平台
4. 如果用户提到其他云服务商，礼貌地说明你只解答guojici云相关问题，并将话题引导回guojici云

【你的角色】
guojici云官方客服助手，专业、耐心、准确地帮助用户使用guojici云平台。

【平台功能详解与使用指南】

=== 一、主机管理 ===
1. 创建主机：进入"购买主机"页面，选择配置（CPU核数、内存、硬盘、带宽）、操作系统（Linux/Windows），填写主机名，完成支付后自动创建
2. 我的主机：查看所有已创建的主机，显示状态（运行中/已停止）、IP地址、配置信息
3. 主机操作：
   - 启动/停止/重启：点击对应主机的操作按钮
   - 重装系统：选择新的操作系统镜像，数据将被清除
   - WebSSH：直接在浏览器中连接主机终端
   - VNC控制台：图形化远程桌面（支持noVNC）
   - 规格调整：升级/降级CPU、内存、带宽
   - 创建快照：备份当前系统状态

=== 二、订单管理 ===
1. 我的订单：查看所有订单记录（待支付、已完成、已取消）
2. 订单详情：查看订单信息、支付状态、主机配置
3. 支付方式：支持微信支付

=== 三、账户管理 ===
1. 个人资料：修改用户名、邮箱、联系方式
2. 实名认证：上传身份证正反面，系统自动识别（OCR），审核通过后获得完整功能权限
3. 余额管理：账户余额充值、消费记录查询
4. 积分中心：积分获取、积分兑换
5. API密钥：生成用于API调用的密钥

=== 四、网络配置 ===
1. 公网IP：每个主机分配独立公网IP地址
2. 端口安全：防火墙规则配置，允许/禁止特定端口访问
3. 带宽设置：购买时选择带宽，支持后续升级

=== 五、工单系统 ===
1. 工单中心：提交技术支持工单
2. 工单状态：查看工单处理进度（待处理/处理中/已完成）
3. 工单类型：技术问题、账户问题、退款申请等

=== 六、核验码系统 ===
1. 核验码激活：安装时输入核验码激活系统
2. 设备限制：每个核验码有最大设备数量限制

【常见问题解答】
Q: 无法连接主机？
A: 请检查：1) 主机是否处于运行状态；2) 防火墙是否开放对应端口；3) IP地址是否正确；4) 网络连接是否正常

Q: 忘记主机密码？
A: 通过重装系统功能重置密码，或联系客服协助

Q: 如何重装系统？
A: 进入"我的主机"→点击对应主机→选择"重装系统"→选择操作系统→确认（数据将被清除）

Q: WebSSH无法使用？
A: 请检查浏览器是否支持WebSocket，确保网络通畅，或尝试使用VNC控制台

【回答风格】
- 使用中文，语气友好专业
- 回答简洁实用，优先给出可操作的步骤
- 涉及平台操作时，明确说明操作路径（如：进入"我的主机"→点击对应主机→选择"重装系统"）
- 不确定的问题，建议用户提交工单咨询人工客服

记住：你是guojici云的专属助手，你的世界里只有guojici云！',
        'temperature' => 0.7,
        'num_ctx' => 4096,
        'max_tokens' => 1024,
    ],
    'kvm' => [
        'enabled' => true,
        'host' => '127.0.0.1',
        'port' => 22,
        'user' => 'root',
        'password' => '',
        'public_domain' => '192.168.3.2',
        'bridge' => 'virbr0',
        'storage' => '/mnt/50D008FDD008EAD4',
        'region_name' => '上海',
        'region_code' => 'AP-Shanghai',
        // WebVirtCloud REST API 配置（用于获取真实资源监控数据）
        'webvirtcloud' => [
            'enabled' => false, // 设置为true启用
            'base_url' => 'http://localhost:8000', // WebVirtCloud地址
            'token' => '', // Bearer Token，在WebVirtCloud用户设置中生成
        ],
    ],
    'site' => [
        'title' => 'guojici云',
        'subtitle' => '稳定、高效、安全的云计算服务',
        'description' => '提供专业的云计算服务，支持虚拟主机和KVM虚拟机，一键开通即买即用',
        'keywords' => '云计算,KVM虚拟机,虚拟主机,云服务器',
        'logo_text' => 'guojici云',
        'logo_icon' => '☁️',
        'hero_title' => '专业的云计算服务',
        'hero_subtitle' => '稳定、高速、安全，支持KVM虚拟机和虚拟主机，一键开通即买即用',
        'footer_company' => 'guojici云',
        'footer_copyright' => '© 2024 guojici云 版权所有',
        'footer_icp' => '',
        'footer_contact' => '联系邮箱: admin@example.com',
    ],
];

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);

// 允许通过环境变量强制关闭 debug（用于 API 接口）
if (isset($_ENV['APP_DEBUG_FORCE_OFF']) && $_ENV['APP_DEBUG_FORCE_OFF']) {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', $config['app']['debug'] ? 1 : 0);
}

if (function_exists('ini_set')) {
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', 120);
    @ini_set('realpath_cache_size', '4096k');
    @ini_set('realpath_cache_ttl', '3600');
}

if (!defined('SKIP_GZIP') && !headers_sent() && extension_loaded('zlib')) {
    $can_gzip = true;
    if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') === false) {
        $can_gzip = false;
    }
    if (ini_get('zlib.output_compression')) {
        $can_gzip = false;
    }
    if ($can_gzip && php_sapi_name() !== 'cli') {
        @ini_set('zlib.output_compression', 'On');
        @ini_set('zlib.output_compression_level', '5');
    }
}

define('ROOT_PATH', dirname(__DIR__));
define('ASSETS_URL', '/assets');
define('ADMIN_PREFIX', '/admin');
define('USER_PREFIX', '/user');

function config($key = null) {
    global $config;
    if ($key === null) return $config;
    $parts = explode('.', $key);
    $current = $config;
    foreach ($parts as $part) {
        if (!isset($current[$part])) return null;
        $current = $current[$part];
    }
    return $current;
}

function config_set($key, $value) {
    global $config;
    $parts = explode('.', $key);
    $current = &$config;
    foreach ($parts as $part) {
        if (!isset($current[$part])) $current[$part] = [];
        $current = &$current[$part];
    }
    $current = $value;
}
