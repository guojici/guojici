<?php
/**
 * 简体中文语言包（默认语言）
 */
return [
    // 导航栏
    'nav' => [
        'home' => '首页',
        'pricing' => '价格方案',
        'hosts' => '主机列表',
        'contact' => '联系我们',
        'search' => '搜索主机名、IP或规格...',
        'create_host' => '创建主机',
        'console' => '控制台',
        'login' => '登录',
        'register' => '注册',
        'region' => '选择地区',
        'default_region' => '默认',
    ],

    // 用户菜单
    'user_menu' => [
        'profile' => '个人资料',
        'points' => '积分中心',
        'tickets' => '工单中心',
        'api_keys' => 'API密钥',
        'balance' => '余额',
        'logout' => '退出登录',
    ],

    // 通知
    'notification' => [
        'title' => '通知中心',
        'unread' => ':count条未读',
        'mark_all_read' => '全部已读',
        'view_all' => '查看全部',
        'all' => '全部',
        'host' => '主机',
        'order' => '订单',
        'system' => '系统',
        'empty' => '暂无通知',
    ],

    // 首页 Hero
    'hero' => [
        'badge' => 'KVM 自动化系统',
        'title_1' => '集中控制',
        'title_2' => '简化运维',
        'desc' => '面向现代数据中心的智能基础设施管理平台，提升效率，降低风险，全面掌控 IT 环境。',
        'demo' => '预约产品演示',
        'free_register' => '免费注册',
        'enter_console' => '进入控制台',
        'stat_users' => '企业用户',
        'stat_uptime' => '系统可用性',
        'stat_devices' => '受管设备',
    ],

    // 功能特性
    'features' => [
        'title' => '强大功能，全面覆盖运维场景',
        'desc' => '通过统一界面访问和控制所有服务器设备，提升运维效率',
    ],

    // 通用按钮/操作
    'btn' => [
        'submit' => '提交',
        'save' => '保存',
        'cancel' => '取消',
        'delete' => '删除',
        'edit' => '编辑',
        'add' => '添加',
        'create' => '创建',
        'confirm' => '确认',
        'close' => '关闭',
        'back' => '返回',
        'refresh' => '刷新',
        'search' => '搜索',
        'send' => '发送',
        'upload' => '上传',
        'download' => '下载',
        'export' => '导出',
        'import' => '导入',
        'enable' => '启用',
        'disable' => '禁用',
        'yes' => '是',
        'no' => '否',
        'ok' => '确定',
        'reset' => '重置',
        'more' => '更多',
        'view' => '查看',
        'copy' => '复制',
        'retry' => '重试',
    ],

    // 状态
    'status' => [
        'active' => '正常',
        'inactive' => '未激活',
        'pending' => '待处理',
        'running' => '运行中',
        'stopped' => '已停止',
        'expired' => '已过期',
        'suspended' => '已暂停',
        'deleted' => '已删除',
        'success' => '成功',
        'failed' => '失败',
        'error' => '错误',
        'warning' => '警告',
        'info' => '提示',
    ],

    // 主机管理
    'host' => [
        'name' => '主机名',
        'ip' => 'IP地址',
        'spec' => '规格',
        'os' => '操作系统',
        'cpu' => 'CPU',
        'memory' => '内存',
        'disk' => '磁盘',
        'traffic' => '流量',
        'bandwidth' => '带宽',
        'status' => '状态',
        'expire' => '到期时间',
        'node' => '节点',
        'region' => '区域',
        'actions' => '操作',
        'start' => '开机',
        'stop' => '关机',
        'restart' => '重启',
        'reinstall' => '重装系统',
        'snapshot' => '快照',
        'vnc' => 'VNC控制台',
        'ssh' => 'SSH连接',
        'firewall' => '防火墙',
        'upgrade' => '升级配置',
        'detail' => '详情',
    ],

    // 订单
    'order' => [
        'title' => '订单',
        'no' => '订单号',
        'amount' => '金额',
        'period' => '周期',
        'created_at' => '下单时间',
        'paid_at' => '支付时间',
        'pay' => '支付',
        'detail' => '订单详情',
        'month' => '月',
        'quarter' => '季',
        'year' => '年',
    ],

    // 财务
    'finance' => [
        'balance' => '余额',
        'recharge' => '充值',
        'points' => '积分',
        'records' => '财务记录',
    ],

    // 客服
    'chat' => [
        'title' => '在线客服',
        'send' => '发送',
        'input_placeholder' => '输入消息，按 Enter 发送，Shift+Enter 换行',
        'end_session' => '结束会话',
        'transfer' => '转接',
        'rating' => '会话评分',
        'no_session' => '选择一个会话开始聊天',
        'typing' => '正在输入...',
    ],

    // 登录/注册
    'auth' => [
        'login_title' => '登录',
        'register_title' => '注册',
        'username' => '用户名',
        'email' => '邮箱',
        'password' => '密码',
        'confirm_password' => '确认密码',
        'remember_me' => '记住我',
        'forgot_password' => '忘记密码？',
        'no_account' => '还没有账号？',
        'has_account' => '已有账号？',
        'login_now' => '立即登录',
        'register_now' => '立即注册',
    ],

    // 管理后台
    'admin' => [
        'dashboard' => '数据概览',
        'users' => '用户管理',
        'orders' => '订单管理',
        'hosts' => '主机管理',
        'packages' => '套餐管理',
        'settings' => '系统设置',
        'logs' => '操作日志',
        'security' => '安全中心',
        'notifications' => '通知管理',
        'server_monitor' => '服务器监控',
        'user_quotas' => '租户配额',
        'api_keys' => 'API密钥审核',
        'refunds' => '退款管理',
        'finance' => '财务统计',
        'transfers' => '转移管理',
        'theme' => '主题配置',
        'performance' => '性能优化',
        'updates' => '系统更新',
        'licenses' => '核验码管理',
        'shortcuts' => '快捷链接',
    ],

    // 页脚
    'footer' => [
        'copyright' => '© :year :site 版权所有',
        'terms' => '服务条款',
        'privacy' => '隐私政策',
        'icp' => 'ICP备案号',
    ],

    // 分页
    'pagination' => [
        'prev' => '上一页',
        'next' => '下一页',
        'first' => '首页',
        'last' => '末页',
        'total' => '共 :total 条',
    ],

    // 消息提示
    'msg' => [
        'success' => '操作成功',
        'error' => '操作失败',
        'confirm_delete' => '确定要删除吗？',
        'no_data' => '暂无数据',
        'loading' => '加载中...',
        'network_error' => '网络错误，请稍后重试',
        'unauthorized' => '未授权，请先登录',
        'forbidden' => '无权限访问',
        'not_found' => '页面不存在',
    ],
];
