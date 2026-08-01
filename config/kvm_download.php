<?php
/**
 * KVM 镜像后台下载脚本
 * 使用方式: php config/kvm_download.php <image_id>
 * 下载完成后会自动更新 vm_images 表状态、文件大小、iso_path
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helper.php';

if ($argc < 2) {
    echo "Usage: php kvm_download.php <image_id>\n";
    exit(1);
}

$image_id = intval($argv[1]);
$image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
if (!$image || empty($image['source_url'])) {
    echo "ERROR: image #$image_id not found or source_url empty\n";
    exit(1);
}

$url = trim($image['source_url']);
$kvm_cfg = config('kvm');
$storage_dir = rtrim($kvm_cfg['storage'] ?? '/var/lib/libvirt/images', '/');

// 确保存储目录存在
if (!is_dir($storage_dir)) {
    @mkdir($storage_dir, 0755, true);
}

// 推断文件名
$info = kvm_infer_filename_from_url($url, $image['name']);
$filename = $info['filename'];
$format = $info['format'];

// 最终存储路径
$target_path = $storage_dir . '/' . $filename;
$tmp_path = $target_path . '.downloading';

// 更新数据库：开始下载
Database::update('vm_images', [
    'download_status' => 'downloading',
    'download_progress' => 0,
    'download_error' => '',
    'iso_path' => $target_path,
    'image_format' => $format,
], 'id = ?', [$image_id]);

echo "START download: $url\nTARGET: $target_path\nFORMAT: $format\n";

// ========== 第一步：通过 HTTP HEAD 先获取文件总大小 ==========
$total_size = 0;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 KVM-Image-Downloader');
if (curl_exec($ch) !== false) {
    $total_size = intval(curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD));
}
curl_close($ch);

if ($total_size > 0) {
    Database::update('vm_images', ['file_size' => $total_size], 'id = ?', [$image_id]);
    echo "Total size: " . round($total_size / 1024 / 1024, 2) . " MB\n";
}

// ========== 第二步：使用 wget 或 curl 下载 ==========
$download_ok = false;
$download_error = '';

// 优先使用 wget（进度解析更简单）
$wget_bin = trim(shell_exec('which wget 2>/dev/null') ?: '');
$curl_bin = trim(shell_exec('which curl 2>/dev/null') ?: '');

$start_time = time();

if ($wget_bin) {
    echo "Using wget: $wget_bin\n";

    $wget_log = sys_get_temp_dir() . '/kvm_wget_' . $image_id . '.log';
    @unlink($wget_log);

    $cmd = sprintf(
        '%s -c -O %s --tries=3 --timeout=60 -q --no-check-certificate %s > %s 2>&1',
        $wget_bin,
        escapeshellarg($tmp_path),
        escapeshellarg($url),
        $wget_log
    );

    // 启动后台 wget 进程并监控
    $pid = shell_exec($cmd . ' & echo $!');
    $pid = intval(trim($pid));

    // 轮询更新进度
    $poll_count = 0;
    while (true) {
        sleep(3);
        $current_size = @filesize($tmp_path);
        if ($current_size === false) $current_size = 0;

        if ($total_size > 0) {
            $progress = min(99, intval($current_size * 100 / $total_size));
        } else {
            $progress = min(99, intval($poll_count * 2)); // 无总大小时的占位进度
        }

        Database::update('vm_images', [
            'download_progress' => $progress,
            'file_size' => $current_size > $total_size ? $current_size : $total_size,
        ], 'id = ?', [$image_id]);

        echo "Progress: $progress% (" . round($current_size / 1024 / 1024, 2) . " MB)\n";

        // 检查进程是否还在
        $check_cmd = "ps aux | grep -E 'wget.*$image_id|wget.*" . escapeshellarg(basename($tmp_path)) . "' | grep -v grep";
        $check_out = shell_exec($check_cmd);
        if (empty(trim($check_out))) {
            // 进程已结束，检查文件
            $final_size = @filesize($tmp_path);
            if ($final_size > 0 && file_exists($tmp_path)) {
                $download_ok = true;
            } else {
                $download_error = trim(@file_get_contents($wget_log) ?: 'wget 进程异常退出');
            }
            break;
        }

        $poll_count++;
        if ($poll_count > 600) { // 30分钟超时
            $download_error = '下载超时（超过30分钟）';
            break;
        }
    }

    // 如果 pid 方式没起作用，改用同步下载 + 直接监控
    if (!$download_ok && $download_error === 'wget 进程异常退出') {
        echo "Retry with synchronous wget...\n";
        $download_ok = false;
        $out = [];
        $ret_var = 0;
        exec(sprintf(
            '%s -c -O %s --tries=5 --timeout=120 --no-check-certificate %s 2>&1',
            $wget_bin,
            escapeshellarg($tmp_path),
            escapeshellarg($url)
        ), $out, $ret_var);
        if ($ret_var === 0 && file_exists($tmp_path) && filesize($tmp_path) > 0) {
            $download_ok = true;
        } else {
            $download_error = implode("\n", $out);
        }
    }
} elseif ($curl_bin) {
    echo "Using curl: $curl_bin\n";
    $out = [];
    $ret_var = 0;
    exec(sprintf(
        '%s -L -C - --retry 3 --connect-timeout 30 --max-time 1800 -k -o %s %s 2>&1',
        $curl_bin,
        escapeshellarg($tmp_path),
        escapeshellarg($url)
    ), $out, $ret_var);
    $download_ok = ($ret_var === 0 && file_exists($tmp_path) && filesize($tmp_path) > 0);
    if (!$download_ok) $download_error = implode("\n", $out);
} else {
    // PHP 自带 curl 作为最后手段
    echo "Using PHP curl fallback...\n";
    $fp = fopen($tmp_path, 'w');
    if ($fp) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1800);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 KVM-Image-Downloader');
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576);
        $download_ok = curl_exec($ch);
        if (!$download_ok) $download_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
    } else {
        $download_error = '无法写入临时文件: ' . $tmp_path;
    }
}

// ========== 第三步：处理结果 ==========
if ($download_ok) {
    // 重命名为最终文件名
    if (file_exists($target_path)) {
        @unlink($target_path); // 删除旧的
    }
    if (!@rename($tmp_path, $target_path)) {
        // rename 失败，尝试 copy
        if (!@copy($tmp_path, $target_path)) {
            echo "WARNING: cannot move $tmp_path to $target_path\n";
            $target_path = $tmp_path;
        } else {
            @unlink($tmp_path);
        }
    }

    $final_size = @filesize($target_path);
    echo "DOWNLOAD OK: $target_path (" . round($final_size / 1024 / 1024, 2) . " MB)\n";

    // 如果是 qcow2，验证一下
    if ($format === 'qcow2') {
        $info_out = [];
        @exec('qemu-img info ' . escapeshellarg($target_path) . ' 2>&1', $info_out);
        echo "qemu-img info:\n" . implode("\n", $info_out) . "\n";
    }

    // 设置权限让 libvirt 能访问
    @chmod($target_path, 0644);
    @chown($target_path, 'root');
    @exec('chown root:qemu ' . escapeshellarg($target_path) . ' 2>/dev/null');

    // 更新数据库
    Database::update('vm_images', [
        'download_status' => 'completed',
        'download_progress' => 100,
        'file_size' => $final_size,
        'iso_path' => $target_path,
        'image_format' => $format,
        'downloaded_at' => date('Y-m-d H:i:s'),
        'download_error' => '',
        'status' => 'active',
    ], 'id = ?', [$image_id]);

    echo "DONE. Image #$image_id ready at $target_path\n";
    exit(0);
} else {
    @unlink($tmp_path);
    echo "DOWNLOAD FAILED: " . trim($download_error) . "\n";

    Database::update('vm_images', [
        'download_status' => 'failed',
        'download_error' => substr(trim($download_error), 0, 490) ?: '下载失败，请检查网络或URL',
    ], 'id = ?', [$image_id]);

    exit(1);
}
