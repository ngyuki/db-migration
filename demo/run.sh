#!/bin/bash

cd $(dirname $(dirname $(readlink -f $0)))

function techo {
  echo -e "\e[0;42m$*\e[0m"
}

techo "create target database schema."
mysql -e "DROP DATABASE IF EXISTS test_demo_migration;CREATE DATABASE test_demo_migration;"

techo "import current database definitation. (imitate running database)"
vendor/bin/doctrine-dbal dbal:import demo/current/*

techo "generate current database definitation and records. (as sql)"
vendor/bin/doctrine-dbal dbal:generate /tmp/schema.sql /tmp/RecordTable.sql -v

techo "generate current database definitation and records. (as yml)"
vendor/bin/doctrine-dbal dbal:generate /tmp/schema.yml /tmp/RecordTable.yml -v

techo "migrate latest database definitation. (exe no-interaction)"
vendor/bin/doctrine-dbal dbal:migrate demo/latest/* --no-interaction -v -k

techo "confirm no diff"
vendor/bin/doctrine-dbal dbal:migrate demo/latest/* --check
