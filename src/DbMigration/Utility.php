<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;

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
}
