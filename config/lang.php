<?php
/**
 * 多语言国际化（i18n）系统
 * 
 * 使用方式：
 *   __('nav.home')           → 获取翻译
 *   __('nav.home', 'Home')   → 获取翻译，无匹配时返回默认值
 *   set_lang('en')           → 切换语言
 *   get_lang()               → 获取当前语言
 *   get_available_langs()    → 获取所有可用语言
 */

// 可用语言列表（社区版仅保留简体中文）
define('AVAILABLE_LANGS', [
    'zh-CN' => ['name' => '简体中文', 'native' => '简体中文', 'icon' => '🇨🇳'],
]);

// 默认语言
define('DEFAULT_LANG', 'zh-CN');

// 语言包缓存
$_lang_data = [];
$_current_lang = null;

/**
 * 获取当前语言代码
 */
function get_lang() {
    global $_current_lang;
    if ($_current_lang !== null) return $_current_lang;
    $_current_lang = DEFAULT_LANG;
    return $_current_lang;
}

/**
 * 设置语言
 */
function set_lang($lang) {
    global $_current_lang, $_lang_data;
    if (!isset(AVAILABLE_LANGS[$lang])) return false;
    
    $_current_lang = $lang;
    $_SESSION['lang'] = $lang;
    setcookie('lang', $lang, time() + 86400 * 365, '/');
    $_lang_data = []; // 清除缓存
    return true;
}

/**
 * 获取所有可用语言
 */
function get_available_langs() {
    return AVAILABLE_LANGS;
}

/**
 * 加载语言包
 */
function _load_lang($lang) {
    global $_lang_data;
    if (isset($_lang_data[$lang])) return $_lang_data[$lang];
    
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    if (file_exists($file)) {
        $_lang_data[$lang] = require $file;
    } else {
        $_lang_data[$lang] = [];
    }
    return $_lang_data[$lang];
}

/**
 * 翻译函数 - 核心函数
 * 
 * @param string $key 翻译键名，支持点号分隔，如 'nav.home'
 * @param string|null $default 无匹配时的默认值
 * @return string
 */
function __($key, $default = null) {
    $lang = get_lang();
    $data = _load_lang($lang);
    
    // 支持点号分隔的键名
    $value = $data;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            // 回退到默认语言
            if ($lang !== DEFAULT_LANG) {
                $fallback = _load_lang(DEFAULT_LANG);
                $value = $fallback;
                foreach (explode('.', $key) as $seg) {
                    if (!is_array($value) || !array_key_exists($seg, $value)) {
                        return $default !== null ? $default : $key;
                    }
                    $value = $value[$seg];
                }
                return is_string($value) ? $value : ($default !== null ? $default : $key);
            }
            return $default !== null ? $default : $key;
        }
        $value = $value[$segment];
    }
    
    return is_string($value) ? $value : ($default !== null ? $default : $key);
}

/**
 * 带参数的翻译（支持占位符替换）
 * 
 * @param string $key 翻译键名
 * @param array $params 替换参数 ['name' => '张三']
 * @return string
 */
function __p($key, $params = []) {
    $text = __($key);
    foreach ($params as $k => $v) {
        $text = str_replace(':' . $k, $v, $text);
    }
    return $text;
}

/**
 * 获取当前语言的 HTML lang 属性值
 */
function lang_html_attr() {
    return 'zh-CN';
}

/**
 * 渲染语言切换器 HTML（社区版仅一种语言，返回空）
 */
function lang_switcher_html($style = 'dropdown') {
    return '';
}

/**
 * 语言切换器 CSS（社区版仅一种语言，返回空）
 */
function lang_switcher_css() {
    return '';
}
