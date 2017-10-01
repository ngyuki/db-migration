<?php
namespace ryunosuke\Test\DbMigration;

use ryunosuke\DbMigration\Exportion;
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

    function test_var_export()
    {
        $value = [
            'array'       => [1, 2, 3,],
            'hash'        => [
                'a' => 'A',
                'b' => 'B',
            ],
            'empty'       => [],
            'emptyempty'  => [[]],
            'emptyempty1' => [[[1]]],
            'nest'        => [
                'hash'  => [
                    'a'    => 'A',
                    'b'    => 'B',
                    'hash' => [
                        'x' => 'X',
                    ],
                ],
                'array' => [
                    [1, 2, 3, ['X']]
                ],
            ],
            'null'        => null,
            'int'         => 123,
            'string'      => 'ABC',
            'object'      => new \DateTime(),
        ];
        $a1 = var_export($value, true);
        $a2 = Utility::var_export($value, true);
        $this->assertEquals(eval("return $a1;"), eval("return $a2;"));

        $a = Utility::var_export([
            'stub' => 'Stub',
            'expo' => new Exportion(sys_get_temp_dir(), 'test.php', [
                'a' => 'a',
            ], function ($data) { return Utility::var_export($data, true); }),
        ], true);
        $this->assertEquals("[
    'stub' => 'Stub',
    'expo' => include 'test.php',
]", $a);
        $this->assertStringEqualsFile(sys_get_temp_dir() . '/test.php', "[
    'a' => 'a',
]");

        $this->expectOutputRegex('#123#');
        Utility::var_export('123');
    }

    function test_yaml_emit()
    {
        $actual = Utility::yaml_emit(['a' => ['a' => 'A']], [
            'indent' => 4,
            'inline' => 1
        ]);
        $this->assertEquals("a: { a: A }
", $actual);

        $actual = Utility::yaml_emit([
            'a' => 'a',
            'b' => new \stdClass()
        ], [
            'callback' => [
                'stdClass' => function ($data) {
                    return [
                        'tag'  => '!hoge',
                        'data' => 'data',
                    ];
                },
            ],
        ]);
        $this->assertEquals("---
a: a
b: !hoge data
...
", $actual);
    }

    function test_yaml_parse()
    {
        $actual = Utility::yaml_parse("a: { a: A }", ['builtin' => false]);
        $this->assertEquals(['a' => ['a' => 'A']], $actual);

        $actual = Utility::yaml_parse("a: { a: A }", ['builtin' => true]);
        $this->assertEquals(['a' => ['a' => 'A']], $actual);

        $actual = Utility::yaml_parse("a: { b: !hoge data }", [
            'callback' => [
                '!hoge' => function ($data) {
                    return strtoupper($data);
                },
            ],
        ]);
        $this->assertEquals(['a' => ['b' => 'DATA']], $actual);
    }

    function test_json_encode()
    {
        $actual = Utility::json_encode(['a' => ['a' => 'A']]);
        $this->assertEquals('{
    "a": {
        "a": "A"
    }
}', $actual);
    }

    function test_json_decode()
    {
        $actual = Utility::json_decode('{
    "a": {
        "a": "!hoge: data"
    }
}', [
            'callback' => [
                '!hoge' => function ($value) {
                    return strtoupper($value);
                }
            ]
        ]);
        $this->assertEquals([
            'a' => [
                'a' => 'DATA',
            ],
        ], $actual);
    }

    function test_array_diff_exists()
    {
        $actual = Utility::array_diff_exists([
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
            'x' => 'X',
        ], [
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
            'y' => 'Y',
        ]);
        $this->assertEquals(['x' => 'X'], $actual);
    }

    function test_file_put_contents()
    {
        $path = sys_get_temp_dir() . '/dir1/dir2/dir3/hoge.txt';
        Utility::file_put_contents($path, 'hoge');
        $this->assertStringEqualsFile($path, 'hoge');
    }

    function test_mb_convert_variables()
    {
        $string = 'あ';

        $actual = Utility::mb_convert_variables(
            'UTF-8',
            'UTF-8',
            $string
        );
        $this->assertEquals($actual, 'UTF-8');
        $this->assertEquals('あ', $string);

        $actual = Utility::mb_convert_variables(
            'SJIS',
            'UTF-8',
            $string
        );
        $this->assertEquals($actual, 'UTF-8');
        $this->assertEquals('あ', mb_convert_encoding($string, 'UTF-8', 'SJIS'));
    }
}
