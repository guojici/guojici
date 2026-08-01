<?php
/**
 * 社区免费版 - 授权管理桩文件
 *
 * 社区版完全移除远程授权验证体系，所有功能默认可用。
 * 本文件仅保留函数签名供其他文件调用，所有检查均直接通过。
 */

class LicenseManager {
    public function isActivated() { return true; }
    public function validateAll() { return true; }
    public function getCurrentActivation() { return null; }
    public function getLicenseCode($code = '') { return ['type' => 'community']; }
    public function remoteVerify() { return ['ok' => true]; }
    public function activateLicense($code = '', $machineId = '') { return ['ok' => true]; }
    public function getActivationRecords() { return []; }
}

function license_manager() {
    static $lm = null;
    if ($lm === null) {
        $lm = new LicenseManager();
    }
    return $lm;
}

/** 社区版固定返回 community 类型 */
function license_type() {
    return 'community';
}

/** 社区版无远程功能矩阵 */
function license_get_features($force_refresh = false) {
    return [];
}

function license_refresh_features() {
    return [];
}

/** 社区版所有功能默认可用 */
function license_feature($feature) {
    return true;
}

/** 社区版无功能限制，直接通过 */
function license_require_feature($feature, $feature_name = '') {
    // 无操作
}

function license_check() {
    return true;
}

/** 服务操作前验证 - 社区版始终通过 */
function license_verify_for_service($service_name = '') {
    return ['ok' => true];
}

function license_require_for_service($service_name = '此服务') {
    // 无操作
}

function license_validate() {
    return true;
}

function license_require() {
    // 无操作
}

function license_checkpoint() {
    // 无操作
}
