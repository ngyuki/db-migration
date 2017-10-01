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
  files                                    Definitation files. First argument is meaned schema.

Options:
      --noview                             No migration View.
  -i, --include[=INCLUDE]                  Target tables pattern (enable comma separated value) (multiple values allowed)
  -e, --exclude[=EXCLUDE]                  Except tables pattern (enable comma separated value) (multiple values allowed)
  -m, --migration[=MIGRATION]              Specify migration directory.
  -w, --where[=WHERE]                      Where condition. (multiple values allowed)
  -g, --ignore[=IGNORE]                    Ignore column. (multiple values allowed)
      --table-directory[=TABLE-DIRECTORY]  Specify separative directory name for tables.
      --view-directory[=VIEW-DIRECTORY]    Specify separative directory name for views.
      --csv-encoding[=CSV-ENCODING]        Specify CSV encoding. [default: "SJIS-win"]
      --yml-inline[=YML-INLINE]            Specify YML inline nest level. [default: 4]
      --yml-indent[=YML-INDENT]            Specify YML indent size. [default: 4]
  -h, --help                               Display this help message
  -q, --quiet                              Do not output any message
  -V, --version                            Display this application version
      --ansi                               Force ANSI output
      --no-ansi                            Disable ANSI output
  -n, --no-interaction                     Do not ask any interactive question
  -v|vv|vvv, --verbose                     Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

files 引数でエクスポートするファイルを指定します。

`--help` 以下は dbal 標準のプションです。

#### files

1つ目のファイルは DDL として出力されます。主にテーブル定義と外部キー制約です。
2つ目以降のファイルは DML として出力されます。要するにレコード配列です。

ファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql プレーンな SQL で出力します
- .php php の配列で出力します。ファイルが既に存在し、Closure を返すファイルの場合はスキップされます
- .json json 形式で出力します
- .yaml yaml 形式で出力します
- .csv csv 形式で出力します（DML のみ）
- 上記以外は例外

#### --include, --exclude

出力対象テーブルを指定します。正規表現です。

評価順位は `include` -> `exclude` です。`exclude` で指定したテーブルが出力されることはありません。
なお、`include` 未指定時はすべてのテーブルが対象になります。

このオプションは DDL のみに適用されます。

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

#### --table-directory, view-directory

テーブルとビューの出力ディレクトリを指定します。
指定した場合、大本のスキーマファイル（第1引数）には include を示す識別子が含められ、内容としては含まれません。
テーブル・ビューごとにファイルを出力したい場合に便利ですが、下記の制約があります。

- php: 制約はありません。php ネイティブの `inhclude` を用いて出力されます
- json: 文字列として出力し、あとからパースされます。したがって（まずあり得ないでしょうか）特殊なプレフィックス（!include）で始まるjsonファイルで誤作動する可能性があります
- yaml: php-yaml 拡張が必要です。インストールされていない場合このオプションは無視されます
- csv: そもそも対応していません

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
指定されると basename が `--exclude` に追加されたような動作になります。

このオプションは下記 `dbal:migrate` との親和性のために存在します。
実質的には `--exclude` で指定した場合と全く同じです。

### dbal:migrate

cli-config.php で設定された connection と、指定したファイルで一時スキーマとの比較を取ります。

引数は下記。

```
Arguments:
  files                                    SQL files

Options:
      --target[=TARGET]                    Specify target DSN (default cli-config)
      --source[=SOURCE]                    Specify source DSN (default cli-config, temporary database)
  -d, --dsn[=DSN]                          Specify destination DSN (default create temporary database) suffix based on cli-config
  -s, --schema[=SCHEMA]                    Specify destination database name (default `md5(filemtime(files))`)
  -t, --type[=TYPE]                        Migration SQL type (ddl, dml. default both)
      --noview                             No migration View.
  -i, --include[=INCLUDE]                  Target tables pattern (enable comma separated value) (multiple values allowed)
  -e, --exclude[=EXCLUDE]                  Except tables pattern (enable comma separated value) (multiple values allowed)
  -w, --where[=WHERE]                      Where condition. (multiple values allowed)
  -g, --ignore[=IGNORE]                    Ignore column for DML. (multiple values allowed)
  -m, --migration[=MIGRATION]              Specify migration directory.
      --no-insert                          Not contains INSERT DML
      --no-delete                          Not contains DELETE DML
      --no-update                          Not contains UPDATE DML
      --format[=FORMAT]                    Format output SQL (none, pretty, format, highlight or compress. default pretty) [default: "pretty"]
  -o, --omit=OMIT                          Omit size for long SQL
      --bulk-insert                        Enable bulk insert
      --csv-encoding[=CSV-ENCODING]        Specify CSV encoding. [default: "SJIS-win"]
      --table-directory[=TABLE-DIRECTORY]  Specify separative directory name for tables.
      --view-directory[=VIEW-DIRECTORY]    Specify separative directory name for views.
  -c, --check                              Check only (Dry run. force no-interaction)
  -f, --force                              Force continue, ignore errors
  -r, --rebuild                            Rebuild destination database
  -k, --keep                               Not drop destination database
      --init                               Initialize database (Too Dangerous)
  -h, --help                               Display this help message
  -q, --quiet                              Do not output any message
  -V, --version                            Display this application version
      --ansi                               Force ANSI output
      --no-ansi                            Disable ANSI output
  -n, --no-interaction                     Do not ask any interactive question
  -v|vv|vvv, --verbose                     Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

#### files

1つ目のファイルは DDL として入力されます。主にテーブル定義と外部キー制約です。
2つ目以降のファイルは DML として出力されます。要するにレコード配列です。

ファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql プレーンな SQL として入力されます。ファイル内容がそのまま実行されるため、レコード配列である必要はありません
- .php php の配列として入力されます。Closure を返す場合、その実行結果が行配列として読み込まれます。その際の引数は `\Doctrine\DBAL\Connection` です
- .json json 形式として入力されます
- .yaml yaml 形式として入力されます
- .csv csv 形式として入力されます（DML のみ）


`--help` 以下は dbal 標準のプションです。

オプションは基本的に名前のとおりですが、いくつか難解なオプションがあるので説明を加えます。

#### --target

マイグレーション対象のデーターベースを DSN で指定します。
未指定時は `cli-config` で指定されたデータベースになります。

DSN は `rdbms://user:pass@hostname/dbname?option=value` のような URL 形式で指定します。
要素は適宜省略できます。省略された要素は cli-config.php のものが使われます。

前述の「原則として～」を**覆す唯一のオプション**です。
このオプションを指定しないかぎり比較元は常に cli-config の connection です。

#### --source

一時スキーマデーターベースを作成する場所を DSN で指定します。
未指定時は `cli-config` で指定された場所＋ランダムスキーマになります。

「cli-config は運用環境へ向いているが、比較用一時スキーマはローカルに構築したい」のような場合に使用します。

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

#### --table-directory, view-directory

generate と同じです。テーブルとビューが格納されているディレクトリを指定します。

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
ここで指定されたディレクトリ名の basename でテーブルが作成され、中のファイル（.sql, .php）が実行されます。

- migrations
  - 20170818.sql: `UPDATE t_table SET surrogate_key = CONCAT(pk1, "-", pk2)`
  - 20170819-1.sql: `UPDATE t_table SET new_column = UPPER(old_column)`
  - 20170819-2.sql: `INSERT INTO t_table_children SELECT children_id FROM t_table`
  - 20170821-1.php: `<?php $connection->delete('t_table1', ['delete_flg'=>1]); return 'DELETE t_table2 WHERE delete_flg = 1';`

このようなディレクトリ構成のときに `-m migrations` を渡すと `migrations` テーブルが自動で作成され、中のファイルが実行されます。ファイル名はなんでも構いません。
.sql はそのまま流されますが、 .php は eval されます。
その際、返り値がある場合は SQL 文として解釈し実行します。配列の場合は SQL 文の配列とみなします（実際には配列を返すことのほうが多いでしょう）。
また、 `$connection` というローカル変数が使用できます。これは `Doctrine\DBAL\Connection` のインスタンスで、マイグレーション対象コネクションです。
このインスタンスを使用してクエリを実行することも可能です。
さらに `Closure` を返した場合はそのクロージャが実行されます。引数は `Doctrine\DBAL\Connection` です。

まとめると .php の動作は下記です。

```php
<?php
// 返り値を利用するパターン（単一文字列でも配列でも良い）
return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];
```

```php
<?php
// ローカル変数の $connection を直接利用するパターン
$connection->insert('t_table', [/* data array */]);
```

```php
<?php
// クロージャを返すパターン
return function ($connection) {
    // 引数の $connection を利用できる
    $connection->insert('t_table', [/* data array */]);
    // クロージャが返した文字列配列も実行される
    return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];
};
```

上記の通り、クロージャ利用パターンの優位性はありません。クロージャパターンでできることはベタコードと return で実現可能です。
これは将来的に持ち回しがしやすくなることを想定して実装されています。
（例えばクロージャ単位でトランザクションをかける、DIを利用して引数を可変にする、などです）

一度当てたファイルは `migrations` テーブルに記録され、再マイグレーションしても実行されません。

このオプションは下記のような DDL, DML 差分でまかないきれない変更を救うときに有用です。

- 「新しく人口キー（既存レコードのとの組み合わせ）」が増えた
- 新カラムに既存データに基づくデフォルト値を入れたい
- 1対1テーブルが1対多に変更された（単一カラム管理から行管理になった）

このような変更は DDL, DML では管理しきれないため、差分適用後に何らかの SQL を実行する必要があります。
そういった事象を救うためのオプションです。

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

### DSN のまとめ

DSN の指定がいくつかありますが、整理すると下記のようになります

| オプション   | 説明
|:-            |:-
| target       | マイグレーション対象を指定します。省略時は cli-config のコネクションです。 ** このオプションを指定しない限り、変更が施されるのは常に cli-config ** です。使用されるケースはあまりないと思います。
| source       | マイグレーションの元を指定します。省略時は cli-config + ランダムスキーマ名です。 ** 指定場所は files 引数で初期化されます ** 。 cli-config は本運用環境へ向いていることが多いため、比較用一時スキーマを別の場所に構築したい場合に使用します。
| dsn          | マイグレーションの元を指定します。上記の source と似ていますが、files 引数で初期化されません。本当の意味で ** 動いているDBから動いているDBへの比較 ** です。本番環境と検証環境を比較したりする場合に使用します。

### Popular Usage

要約すると、よくある使い方は下記のようになります。

- vendor/bin/doctrine-dbal dbal:generate database.sql records.sql
  - cli-config の接続先を database.sql へ出力します。records.sql にはレコードが出力されます。
- vendor/bin/doctrine-dbal dbal:migrate sqlfiles
  - cli-config の接続先を sqlfiles にマイグレーションします。cli-config 先にランダムな一時スキーマが生成されます。
- vendor/bin/doctrine-dbal dbal:migrate sqlfiles --target remotehost/dbname
  - remotehost/dbname を sqlfiles にマイグレーションします。cli-config 先にランダムな一時スキーマが生成されます。
- vendor/bin/doctrine-dbal dbal:migrate sqlfiles --source localhost/dbname
  - cli-config の接続先を sqlfiles にマイグレーションします。localhost/dbname に一時スキーマが生成されます。
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
