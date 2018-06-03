<?php
require __DIR__ . '/../vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    setlocale(LC_CTYPE, 'C');
}

if (!defined('YAML_UTF8_ENCODING')) {
    define('YAML_UTF8_ENCODING', 1);
}
if (!defined('YAML_LN_BREAK')) {
    define('YAML_LN_BREAK', 2);
}

if (!function_exists('yaml_parse')) {
    function yaml_parse($input, $pos, &$ndocs, $callbacks)
    {
        throw new DomainException(var_export(compact('input', 'pos', 'ndocs', 'callbacks'), true));
    }
}

if (!function_exists('yaml_emit')) {
    function yaml_emit($data, $encoding, $linebreak, $callbacks)
    {
        throw new DomainException(var_export(compact('data', 'encoding', 'linebreak', 'callbacks'), true));
    }
}
