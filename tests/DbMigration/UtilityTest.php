<?php
namespace ryunosuke\Test\DbMigration;

use ryunosuke\DbMigration\Utility;

class UtilityTest extends AbstractTestCase
{
    function test_quote()
    {
        $this->assertEquals("NULL", Utility::quote($this->connection, null));
        $this->assertEquals("'123'", Utility::quote($this->connection, 123));
        $this->assertEquals("'abc'", Utility::quote($this->connection, 'abc'));
        $this->assertEquals(["NULL", "'123'", "'abc'"], Utility::quote($this->connection, [null, 123, 'abc']));
    }

    function test_quoteIdentifier()
    {
        $this->assertEquals("``", Utility::quoteIdentifier($this->connection, null));
        $this->assertEquals("`123`", Utility::quoteIdentifier($this->connection, 123));
        $this->assertEquals("`abc`", Utility::quoteIdentifier($this->connection, 'abc'));
        $this->assertEquals(["``", "`123`", "`abc`"], Utility::quoteIdentifier($this->connection, [null, 123, 'abc']));
    }
}
