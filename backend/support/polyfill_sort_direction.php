<?php

/**
 * illuminate/* v13.x 使用 PHP 8.6 全局枚举 SortDirection。
 * symfony/polyfill-php86 通过 classmap stub 提供，部分部署场景下懒加载会失败；
 * 在自动加载阶段尽早声明，避免 Class "SortDirection" not found。
 */
if (\PHP_VERSION_ID < 80600 && !enum_exists('SortDirection', false)) {
    enum SortDirection
    {
        case Ascending;
        case Descending;
    }
}
