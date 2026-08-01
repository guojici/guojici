<?php
/**
 * 易支付处理模块
 * 支持彩虹易支付、XorPay等易支付接口
 */
require_once __DIR__ . '/../config/helper.php';

class Epay {
    private $config;
    
    public function __construct() {
        $this->config = array_merge(config('epay') ?: [], db_get_settings('epay'));
    }
    
    /**
     * 检查易支付是否启用
     */
    public function isEnabled() {
        return !empty($this->config['enabled']) && !empty($this->config['api_url']) && !empty($this->config['pid']) && !empty($this->config['key']);
    }
    
    /**
     * 获取支付类型列表
     */
    public function getPayTypes() {
        return [
            'alipay' => ['name' => '支付宝', 'icon' => '支付宝.png'],
            'wxpay' => ['name' => '微信支付', 'icon' => '微信.png'],
            'qqpay' => ['name' => 'QQ钱包', 'icon' => 'QQ.png'],
        ];
    }
    
    /**
     * 生成支付链接
     * @param array $params 支付参数
     * @return string|null 支付链接或null
     */
    public function createPayment($params) {
        if (!$this->isEnabled()) {
            return null;
        }
        
        $api_url = rtrim($this->config['api_url'], '/');
        $pid = $this->config['pid'];
        $key = $this->config['key'];
        $sign_type = $this->config['sign_type'] ?? 'md5';
        
        $data = [
            'pid' => $pid,
            'type' => $params['type'] ?? 'alipay',
            'out_trade_no' => $params['out_trade_no'],
            'notify_url' => $this->config['notify_url'] ?: $params['notify_url'],
            'return_url' => $this->config['return_url'] ?: $params['return_url'],
            'name' => $params['name'] ?? '商品订单',
            'money' => $params['money'],
        ];
        
        $sign = $this->generateSign($data, $key, $sign_type);
        $data['sign'] = $sign;
        $data['sign_type'] = $sign_type;
        
        if ($this->config['debug']) {
            $log_file = __DIR__ . '/../logs/epay.log';
            file_put_contents($log_file, date('Y-m-d H:i:s') . ' [createPayment] ' . json_encode($data) . "\n", FILE_APPEND);
        }
        
        return $api_url . '/mapi.php?' . http_build_query($data);
    }
    
    /**
     * 验证回调签名
     * @param array $data 回调数据
     * @return bool 是否有效
     */
    public function verifyNotify($data) {
        if (!$this->isEnabled()) {
            return false;
        }
        
        $key = $this->config['key'];
        $sign_type = $data['sign_type'] ?? $this->config['sign_type'] ?? 'md5';
        
        $expected_sign = $this->generateSign($data, $key, $sign_type);
        
        if ($this->config['debug']) {
            $log_file = __DIR__ . '/../logs/epay.log';
            file_put_contents($log_file, date('Y-m-d H:i:s') . ' [verifyNotify] data=' . json_encode($data) . ' expected_sign=' . $expected_sign . ' received_sign=' . ($data['sign'] ?? '') . "\n", FILE_APPEND);
        }
        
        return ($data['sign'] ?? '') === $expected_sign;
    }
    
    /**
     * 生成签名
     */
    private function generateSign($data, $key, $sign_type = 'md5') {
        // 移除sign字段
        unset($data['sign']);
        unset($data['sign_type']);
        
        // 过滤空值并按key排序
        $data = array_filter($data, function($v) {
            return $v !== '' && $v !== null;
        });
        ksort($data);
        
        $sign_str = http_build_query($data) . $key;
        
        if ($sign_type === 'rsa') {
            // RSA签名需要私钥，这里暂不实现
            return '';
        }
        
        return md5($sign_str);
    }
    
    /**
     * 查询订单状态
     */
    public function queryOrder($out_trade_no) {
        if (!$this->isEnabled()) {
            return null;
        }
        
        $api_url = rtrim($this->config['api_url'], '/');
        $pid = $this->config['pid'];
        $key = $this->config['key'];
        $sign_type = $this->config['sign_type'] ?? 'md5';
        
        $data = [
            'act' => 'order',
            'pid' => $pid,
            'key' => $key,
            'out_trade_no' => $out_trade_no,
            'sign_type' => $sign_type,
        ];
        
        $sign = $this->generateSign($data, $key, $sign_type);
        $data['sign'] = $sign;
        
        $url = $api_url . '/api.php?' . http_build_query($data);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        if ($this->config['debug']) {
            $log_file = __DIR__ . '/../logs/epay.log';
            file_put_contents($log_file, date('Y-m-d H:i:s') . ' [queryOrder] url=' . $url . ' resp=' . $resp . "\n", FILE_APPEND);
        }
        
        return json_decode($resp, true);
    }
}

/**
 * 创建易支付订单
 */
function epay_create_order($params) {
    $epay = new Epay();
    if (!$epay->isEnabled()) {
        return ['success' => false, 'message' => '易支付未启用'];
    }
    
    $pay_url = $epay->createPayment($params);
    if (!$pay_url) {
        return ['success' => false, 'message' => '支付链接生成失败'];
    }
    
    return ['success' => true, 'pay_url' => $pay_url];
}

/**
 * 验证易支付回调
 */
function epay_verify_notify($data) {
    $epay = new Epay();
    return $epay->verifyNotify($data);
}

/**
 * 查询易支付订单状态
 */
function epay_query_order($out_trade_no) {
    $epay = new Epay();
    return $epay->queryOrder($out_trade_no);
}

/**
 * 易支付是否启用
 */
function epay_is_enabled() {
    $epay = new Epay();
    return $epay->isEnabled();
}

/**
 * 获取易支付支付方式列表
 */
function epay_get_pay_types() {
    $epay = new Epay();
    return $epay->getPayTypes();
}

/**
 * 生成易支付签名
 * @param array $data 待签名数据
 * @param string $key 商户密钥
 * @param string $sign_type 签名类型 md5 或 rsa
 * @return string 签名值
 */
function epay_generate_sign($data, $key, $sign_type = 'md5') {
    // 移除sign字段
    unset($data['sign']);
    unset($data['sign_type']);
    
    // 过滤空值并按key排序
    $data = array_filter($data, function($v) {
        return $v !== '' && $v !== null;
    });
    ksort($data);
    
    $sign_str = http_build_query($data) . $key;
    
    if ($sign_type === 'rsa' || $sign_type === 'RSA') {
        // RSA签名需要私钥，这里暂不实现
        return '';
    }
    
    return md5($sign_str);
}

/**
 * 获取易支付配置（合并默认配置和数据库配置）
 */
function epay_get_config() {
    return array_merge(config('epay') ?: [], db_get_settings('epay'));
}

/**
 * 获取当前网站基础URL
 * @return string 网站基础URL
 */
function get_base_url() {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $protocol = $is_https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}