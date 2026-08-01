<?php
require_once __DIR__ . '/../config/db.php';

if (class_exists('DataCache')) {
    DataCache::flush();
    echo "缓存已清空\n";
}

$settings_cache = __DIR__ . '/settings_cache.php';
if (file_exists($settings_cache)) {
    @unlink($settings_cache);
    echo "设置缓存已清空\n";
}

echo "完成\n";
