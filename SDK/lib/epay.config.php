<?php
/**
 * 易支付SDK - 生产环境配置
 * 基于 https://pay.vansdesign.cn/ 文档
 * 签名算法：MD5
 * 字符编码：UTF-8
 */

// ====== 必须配置项 ======

// 支付接口地址（文档要求：https://pay.vansdesign.cn/）
$epay_config['apiurl'] = 'https://';

// 商户ID（文档要求：pid）
$epay_config['pid'] = '1000';
// 商户密钥（文档要求：key，用于 MD5 签名拼接）
$epay_config['key'] = '1';

// ====== 支付方式配置（只保留微信支付） ======
// 文档支持：alipay / wxpay / qqpay / bank / jdpay
// 本系统仅启用：wxpay
$epay_config['enabled_types'] = ['wxpay'];

// ====== 回调地址（自动构造，无需手动修改） ======
// 若需要手动指定，可改为固定字符串：
// $epay_config['notify_url'] = 'https://yourdomain.com/SDK/notify_url.php';
// $epay_config['return_url'] = 'https://yourdomain.com/SDK/return_url.php';
$epay_config['notify_url'] = null;
$epay_config['return_url'] = null;

// ====== 安全配置 ======
$epay_config['debug'] = false;           // 是否打印调试信息（生产环境：false）
$epay_config['timeout'] = 10;             // curl 超时时间（秒）
