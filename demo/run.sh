#!/bin/bash

cd $(dirname $(dirname $(readlink -f $0)))

function techo {
  echo -e "\e[0;42m$*\e[0m"
}

techo "import current database definitation. (imitate running database)"
vendor/bin/doctrine-dbal dbal:migrate demo/current/* --init

techo "generate current database definitation and records. (as sql)"
vendor/bin/doctrine-dbal dbal:generate /tmp/schema.sql /tmp/RecordTable.sql -m migration -v

techo "generate current database definitation and records. (as yml)"
vendor/bin/doctrine-dbal dbal:generate /tmp/schema.yml /tmp/RecordTable.yml -m migration -v

techo "migrate latest database definitation. (exe no-interaction)"
vendor/bin/doctrine-dbal dbal:migrate demo/latest/* --no-interaction -m demo/migration -v -k

techo "confirm no diff"
vendor/bin/doctrine-dbal dbal:migrate demo/latest/* -m migration --check
