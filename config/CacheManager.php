<?php
/**
 * Redis缓存管理器
 * 提供多层缓存支持：Redis + APCu + 文件缓存
 */

class CacheManager {
    private static $redis = null;
    private static $apcu_enabled = false;
    
    /**
     * 初始化缓存
     */
    public static function init() {
        // 检查APCu是否可用
        self::$apcu_enabled = function_exists('apcu_fetch');
        
        // 检查Redis是否可用
        if (extension_loaded('redis')) {
            try {
                self::$redis = new Redis();
                $config = config('redis') ?? [];
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 6379;
                $timeout = $config['timeout'] ?? 2;
                
                if (self::$redis->connect($host, $port, $timeout)) {
                    if (!empty($config['password'])) {
                        self::$redis->auth($config['password']);
                    }
                    if (!empty($config['database'])) {
                        self::$redis->select($config['database']);
                    }
                } else {
                    self::$redis = null;
                }
            } catch (Exception $e) {
                self::$redis = null;
            }
        }
    }
    
    /**
     * 获取缓存
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null) {
        // 先从APCu获取（本地内存缓存）
        if (self::$apcu_enabled) {
            $val = apcu_fetch($key);
            if ($val !== false) return $val;
        }
        
        // 再从Redis获取
        if (self::$redis !== null) {
            try {
                $val = self::$redis->get($key);
                if ($val !== false) {
                    $decoded = json_decode($val, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // 同步到APCu
                        if (self::$apcu_enabled) {
                            apcu_add($key, $decoded, 60);
                        }
                        return $decoded;
                    }
                    return $val;
                }
            } catch (Exception $e) {}
        }
        
        return $default;
    }
    
    /**
     * 设置缓存
     * @param string $key 缓存键
     * @param mixed $value 值
     * @param int $ttl 过期时间（秒），0表示永不过期
     * @return bool
     */
    public static function set($key, $value, $ttl = 3600) {
        $encoded = is_scalar($value) ? $value : json_encode($value);
        
        // 设置到APCu
        if (self::$apcu_enabled) {
            apcu_store($key, $value, min($ttl, 3600));
        }
        
        // 设置到Redis
        if (self::$redis !== null) {
            try {
                if ($ttl > 0) {
                    return self::$redis->setex($key, $ttl, $encoded);
                } else {
                    return self::$redis->set($key, $encoded);
                }
            } catch (Exception $e) {}
        }
        
        return false;
    }
    
    /**
     * 删除缓存
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete($key) {
        if (self::$apcu_enabled) {
            apcu_delete($key);
        }
        
        if (self::$redis !== null) {
            try {
                return self::$redis->del($key);
            } catch (Exception $e) {}
        }
        
        return false;
    }
    
    /**
     * 缓存标签删除（批量删除）
     * @param string $tag 标签名
     * @return bool
     */
    public static function deleteByTag($tag) {
        if (self::$redis !== null) {
            try {
                $keys = self::$redis->keys($tag . '_*');
                if (!empty($keys)) {
                    return self::$redis->del($keys);
                }
            } catch (Exception $e) {}
        }
        return false;
    }
    
    /**
     * 检查缓存是否存在
     * @param string $key 缓存键
     * @return bool
     */
    public static function exists($key) {
        if (self::$apcu_enabled && apcu_exists($key)) {
            return true;
        }
        
        if (self::$redis !== null) {
            try {
                return self::$redis->exists($key);
            } catch (Exception $e) {}
        }
        
        return false;
    }
    
    /**
     * 批量获取缓存
     * @param array $keys 键数组
     * @return array
     */
    public static function mget($keys) {
        $result = [];
        
        if (self::$redis !== null) {
            try {
                $values = self::$redis->mget($keys);
                foreach ($keys as $i => $key) {
                    $val = $values[$i];
                    if ($val !== false) {
                        $decoded = json_decode($val, true);
                        $result[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $val;
                    }
                }
            } catch (Exception $e) {}
        }
        
        return $result;
    }
    
    /**
     * 缓存预热（批量设置）
     * @param array $data 键值对数组
     * @param int $ttl 过期时间
     */
    public static function preload($data, $ttl = 3600) {
        foreach ($data as $key => $value) {
            self::set($key, $value, $ttl);
        }
    }
    
    /**
     * 获取缓存状态
     * @return array
     */
    public static function getStatus() {
        return [
            'redis_enabled' => self::$redis !== null,
            'apcu_enabled' => self::$apcu_enabled,
        ];
    }
}

// 初始化缓存
CacheManager::init();

/**
 * 简化缓存函数
 */
function cache($key, $value = null, $ttl = 3600) {
    if ($value === null) {
        return CacheManager::get($key);
    }
    return CacheManager::set($key, $value, $ttl);
}

function cache_delete($key) {
    return CacheManager::delete($key);
}