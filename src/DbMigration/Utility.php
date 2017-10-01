<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Symfony\Component\Yaml\Yaml;

class Utility
{
    public static function quote(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quote($connection, $v);
            }
            return $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        return $connection->quote($value);
    }

    public static function quoteIdentifier(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quoteIdentifier($connection, $v);
            }
            return $value;
        }

        return $connection->quoteIdentifier($value);
    }

    public static function var_export($value, $return = false)
    {
        $INDENT = 4;

        $export = function ($value, $nest = 0) use (&$export, $INDENT) {
            if (is_array($value)) {
                if ($value === array_values($value)) {
                    return '[' . implode(', ', array_map($export, $value)) . ']';
                }

                $maxlen = max(array_map('strlen', array_keys($value)));
                $spacer = str_repeat(' ', ($nest + 1) * $INDENT);

                $kvl = '';
                foreach ($value as $k => $v) {
                    $align = str_repeat(' ', $maxlen - strlen($k));
                    $kvl .= $spacer . var_export($k, true) . $align . ' => ' . $export($v, $nest + 1) . ",\n";
                }
                return '[' . "\n" . $kvl . str_repeat(' ', $nest * $INDENT) . ']';
            }
            else if (is_null($value)) {
                return 'null';
            }
            else if ($value instanceof Exportion) {
                $fname = $value->export();
                return "include " . var_export($fname, true);
            }
            else if (is_object($value)) {
                return get_class($value) . '::__set_state(' . $export((array) $value, $nest) . ')';
            }
            else {
                return var_export($value, true);
            }
        };

        $result = $export($value, 0);
        if ($return) {
            return $result;
        }
        echo $result;
    }

    public static function yaml_emit($value, $options = array())
    {
        $options = array_replace(array(
            'builtin'  => false,
            'inline'   => null,
            'indent'   => null,
            'callback' => array(),
        ), $options);

        if (function_exists('yaml_emit') && ($options['builtin'] || $options['callback'])) {
            return yaml_emit($value, YAML_UTF8_ENCODING, YAML_LN_BREAK, $options['callback']);
        }
        else {
            return Yaml::dump($value, $options['inline'], $options['indent']);
        }
    }

    public static function yaml_parse($input, $options = array())
    {
        $options = array_replace(array(
            'builtin'  => false,
            'callback' => array(),
        ), $options);

        if (function_exists('yaml_parse') && ($options['builtin'] || $options['callback'])) {
            return yaml_parse($input, 0, $ndocs, $options['callback']);
        }
        else {
            return Yaml::parse($input);
        }
    }

    public static function json_encode($value, $options = 0)
    {
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $options |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $options |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_PRETTY_PRINT')) {
            $options |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $options);
    }

    public static function json_decode($value, $options = array())
    {
        $options = array_replace(array(
            'callback' => array(),
        ), $options);

        $result = json_decode($value, true);
        foreach ($options['callback'] as $prefix => $callback) {
            array_walk_recursive($result, function (&$value) use ($prefix, $callback) {
                if (preg_match('#' . $prefix . ': (.*)#', $value, $m)) {
                    $value = call_user_func($callback, $m[1]);
                }
            });
        }
        return $result;
    }

    public static function array_diff_exists($array1, $array2)
    {
        foreach ($array1 as $key => $val) {
            if (array_key_exists($key, $array2) && $val === $array2[$key]) {
                unset($array1[$key]);
            }
        }

        return $array1;
    }

    public static function file_put_contents($filename, $data)
    {
        $dirname = dirname($filename);
        is_dir($dirname) or mkdir($dirname, 0777, true);
        return file_put_contents($filename, $data);
    }

    public static function mb_convert_variables($to_encoding, $from_encoding, &$vars)
    {
        if ($to_encoding === $from_encoding) {
            return $from_encoding;
        }
        return mb_convert_variables($to_encoding, $from_encoding, $vars);
    }
}
