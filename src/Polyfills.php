<?php
// PHP 7.3 相容 polyfill：str_contains()/str_starts_with()/str_ends_with() 是 PHP 8.0+
// 才有的內建函式，7.3 沒有。用 function_exists() 包起來，這樣同一份程式碼未來如果又跑回
// PHP 8+（例如開發機的 XAMPP），不會因為重複宣告內建函式而 Fatal error。
//
// 純程序化函式、不在 App\ 命名空間下，PSR-4 autoload（App\ → src/）不會自動載入它，
// 必須在每個執行入口（config/bootstrap.php 與 scripts/*.php）明確 require_once。

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
