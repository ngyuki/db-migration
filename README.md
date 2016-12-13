DB Migration
====

## Description

動いているデータベースとデータベース定義ファイルを比較して、ddl, dml を出力・実行します。

## Demo

```bash
$ mysql -e "DROP DATABASE IF EXISTS test_demo_migration;CREATE DATABASE test_demo_migration;"
$ vendor/bin/doctrine-dbal dbal:import demo/current/*
$ vendor/bin/doctrine-dbal dbal:generate /tmp/schema.yml /tmp/RecordTable.yml -v
$ vendor/bin/doctrine-dbal dbal:migrate demo/latest/* --check -v
```

```sql
-- test_demo_migration_b8b061c18f740758d6e3f84c3c42e8a4 is created.
-- diff DDL
CREATE TABLE CreatedTable (
  id INT UNSIGNED NOT NULL,
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB;

DROP TABLE DroppedTable;

DROP INDEX dropped_index ON AlteredTable;

ALTER TABLE AlteredTable
  ADD created_column VARCHAR(32) NOT NULL COLLATE utf8_bin,
  DROP dropped_column,
  CHANGE altered_column altered_column VARCHAR(64) NOT NULL COLLATE utf8_bin;

CREATE INDEX created_index ON AlteredTable (
  created_column
);

-- diff DML
-- AlteredTable  is skipped by no record.
-- CreatedTable  is skipped by no record.
-- RecordTable   has diff:
/* current record:
  'id' => '3',
  'name' => 'deleted',
  'value' => '1',
*/
DELETE FROM `RecordTable` WHERE (`id` = '3');

/* current record:
  'name' => 'updated',
  'value' => '1',
*/
UPDATE `RecordTable` SET
  `name` = 'updated2',
  `value` = '2'
WHERE (`id` = '2');

INSERT INTO `RecordTable` (`id`, `name`, `value`) VALUES
  ('4', 'inserted', '1');

-- test_demo_migration_b8b061c18f740758d6e3f84c3c42e8a4 is dropped.
```

## Usage

原則として比較元は常に cli-config.php で設定された connection になります。
比較先は引数で指定します。

コマンドは下記2つです。

### dbal:generate

cli-config.php で設定された connection のスキーマとレコードを、指定したファイルに出力します。

引数は下記。

```
Arguments:
 files                  Definitation files. First argument is meaned schema.

Options:
 -w, --where[=WHERE]    Where condition. (multiple values allowed)
 -g, --ignore[=IGNORE]  Ignore column. (multiple values allowed)
 -h, --help             Display this help message
 -q, --quiet            Do not output any message
 -V, --version          Display this application version
     --ansi             Force ANSI output
     --no-ansi          Disable ANSI output
 -n, --no-interaction   Do not ask any interactive question
 -v|vv|vvv, --verbose   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

files 引数でエクスポートするファイルを指定します。

`--help` 以下は dbal 標準のプションです。

#### files

1つ目のファイルは DDL として出力されます。主にテーブル定義と外部キー制約です。
2つ目以降のファイルは DDL として出力されます。要するにレコード配列です。

ファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql プレーンな SQL で出力します
- .php php の配列で出力します
- .json json 形式で出力します
- .yaml yaml 形式で出力します
- 上記以外は例外

#### --where (-w)

DML 差分対象の WHERE 文を指定します。
このオプションで指定したレコードがエクスポートされます。

`-w table.column = 99` のように指定するとそのテーブルのみで適用されます。
`-w column = 99` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

#### --ignore (-g)

無視のカラム名を指定します。
このオプションで指定したカラムは空文字列で出力されるようになります。

`-g table.column` のように指定するとそのテーブルのみで適用されます。
`-g column` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

### dbal:migrate

cli-config.php で設定された connection と、指定したファイルで一時スキーマとの比較を取ります。

引数は下記。

```
Arguments:
 files                 SQL files

Options:
 --target              Specify target DSN (default cli-config)
 --dsn (-d)            Specify destination DSN (default `md5(filemtime(files))`) suffix based on cli-config
 --schema (-s)         Specify destination DSN (default `md5(filemtime(files))`) suffix based on cli-config
 --type (-t)           Migration SQL type (ddl, dml. default both)
 --include (-i)        Target tables (enable comma separated value) (multiple values allowed)
 --exclude (-e)        Except tables (enable comma separated value) (multiple values allowed)
 --where (-w)          Where condition.
 --ignore (-g)         Ignore column for DML.
 --omit (-o)           Omit size for long SQL
 --check (-c)          Check only (Dry run. force no-interaction)
 --force (-f)          Force continue, ignore errors
 --rebuild (-r)        Rebuild destination database
 --keep (-k)           Not drop destination database
 --init                Initialize database (Too Dangerous)
 --help (-h)           Display this help message
 --quiet (-q)          Do not output any message
 --verbose (-v|vv|vvv) Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)        Display this application version
 --ansi                Force ANSI output
 --no-ansi             Disable ANSI output
 --no-interaction (-n) Do not ask any interactive question
```

files 引数でインポートする sql ファイルを指定します。

`--help` 以下は dbal 標準のプションです。

オプションは基本的に名前のとおりですが、いくつか難解なオプションがあるので説明を加えます。

#### --target

マイグレーション対象のデーターベースを DSN で指定します。
未指定時は `cli-config` で指定されたデータベースになります。

DSN は `rdbms://user:pass@hostname/dbname?option=value` のような URL 形式で指定します。
要素は適宜省略できます。省略された要素は cli-config.php のものが使われます。

前述の「原則として～」を**覆す唯一のオプション**です。
このオプションを指定しないかぎり比較元は常に cli-config の connection です。

#### --dsn (-d)

基本的には SQL ファイルの更新日時を元にしたランダムな一時スキーマを作成し、そこへファイルをインポートしてから差分を取りますが、このオプションを指定するとスキーマを作成せず、指定した DSN へ接続してそことの差分を取ります。
要するに「動いている DB から動いている DB」へマイグレーションするオプションです。

DSN は `rdbms://user:pass@hostname/dbname?option=value` のような URL 形式で指定します。
要素は適宜省略できます。省略された要素は cli-config.php のものが使われます。

このオプションが指定された場合、引数の files は不要です。

#### --schema (-s)

上記の通り、比較対象として一時スキーマを作成しますが、そのスキーマ名を指定するオプションです。

`-s hoge_migration` のように指定すると hoge_migration スキーマが作成され、そこに SQL ファイルが流し込まれます。
ただし、スキーマが既に存在するときは `-rebuild` オプションに従います。

省略すると SQL ファイルの更新日時を元にしたランダムな一時スキーマ名になります。

#### --include, --exclude

差分対象テーブルを指定します。正規表現です。

評価順位は `include` -> `exclude` です。`exclude` で指定したテーブルが出力されることはありません。
なお、`include` 未指定時はすべてのテーブルが対象になります。

このオプションは特別なことをしない限り **DML のみに適用**されます。
ALTER 文などの DDL は普通に出力されます（後述参照）。

#### --where (-w)

DML 差分対象の WHERE 文を指定します。
このオプションで指定したレコードが DML 差分対象になります。

`-w table.column = 99` のように指定するとそのテーブルのみで適用されます。
`-w column = 99` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

#### --ignore (-g)

DML 差分対象のカラム名を指定します。
このオプションで指定したカラムが DML 差分対象になります。
具体的には UPDATE の比較対象としてそのカラムを使うか否か、です。

`-g table.column` のように指定するとそのテーブルのみで適用されます。
`-g column` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

#### --rebuild (-r)

一時スキーマが存在する場合、それをドロップした後に SQL ファイルを流し込みます。
逆にこのオプションを指定しなかった場合、SQL ファイルは使用されませんし、一時スキーマに変更も加えません。

#### --keep (-k)

ファイルから生成した一時スキーマを消さずに維持します。
いちいち生成・削除を繰り返していると重いので検証作業中などはこのオプションを指定したほうが良いです。

#### --init

比較元と比較先を同一とみなしてマイグレーションします。
比較対象は一度 DROP してから再構築されるため、要するに完全初期化用オプションです。

**とても危険なオプションです。**

#### --no-interaction (-n)

指定すると確認メッセージを出さずに SQL を直接実行します。

### Popular Usage

要約すると、よくある使い方は下記のようになります。

- vendor/bin/doctrine-dbal dbal:generate database.sql records.sql
  - cli-config の接続先を database.sql へ出力します。records.sql にはレコードが出力されます。
- vendor/bin/doctrine-dbal dbal:migrate sqlfiles
  - cli-config の接続先を sqlfiles にマイグレーションします。cli-config 先にランダムな一時スキーマが生成されます。
- vendor/bin/doctrine-dbal dbal:migrate sqlfiles --target remotehost/dbname
  - remotehost/dbname を sqlfiles にマイグレーションします。cli-config 先にランダムな一時スキーマが生成されます。
- vendor/bin/doctrine-dbal dbal:migrate --dsn localhost/temporary
  - cli-config の接続先を localhost/temporary にマイグレーションします。localhost/temporary に変更は一切加わりません。
- vendor/bin/doctrine-dbal dbal:migrate --schema temporary
  - cli-config の接続先を sqlfiles にマイグレーションします。 cli-config 先に temporary という一時スキーマが生成されます。

## Install

```json
{
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/arima-ryunosuke/db-migration"
        }
    ],
    "require": {
        "ryunosuke/db-migration": "dev-master"
    }
}
```

```bash
$ cd project_root
$ composer install
$ cp vendor/ryunosuke/db-migration/cli-config.php.example cli-config.php
$ vi cli-config.php
$ # sh vendor/ryunosuke/db-migration/demo/run.sh
```

`doctrine` に依存しており、最新版を使用します。
mysql に特化した[拙作の fork リポジトリ](https://github.com/arima-ryunosuke/dbal)があるので良かったら使って下さい。下記のような変更を加えています。

* DDL 差分でテーブルを指定できるように
* TEXT 型を変更したら差分が出るように
* カラム追加時に FIRST/AFTER を付加するように
* Table Option (ストレージエンジンとか、テーブルコメントとか)の変更差分を出力するように
* Spatial 型を追加

composer.json を工夫すれば doctrine/dbal と入れ替えて使用できますが、ここでは詳細は省きます。

## Licence

[MIT](https://raw.githubusercontent.com/arima-ryunosuke/db-migration/master/LICENSE)

## Author

[arima-ryunosuke](https://github.com/arima-ryunosuke)
