<?php

namespace Amp\Postgres;

use Amp\AsyncGenerator;
use Amp\Promise;
use Amp\Sql\FailureException;
use Amp\Sql\Result;
use function Amp\await;

final class PgSqlResultSet implements Result, \IteratorAggregate
{
    private static Internal\ArrayParser $parser;

    private AsyncGenerator $generator;

    private int $rowCount;

    /** @var Promise<Result|null> */
    private Promise $nextResult;

    /**
     * @param resource $handle PostgreSQL result resource.
     * @param Promise<Result|null> $nextResult
     */
    public function __construct($handle, Promise $nextResult)
    {
        $fieldNames = [];
        $fieldTypes = [];
        $numFields = \pg_num_fields($handle);
        for ($i = 0; $i < $numFields; ++$i) {
            $fieldNames[] = \pg_field_name($handle, $i);
            $fieldTypes[] = \pg_field_type_oid($handle, $i);
        }

        $this->rowCount = \pg_num_rows($handle);
        $this->nextResult = $nextResult;

        $this->generator = new AsyncGenerator(static function () use ($handle, $fieldNames, $fieldTypes): \Generator {
            $position = 0;

            try {
                while (++$position <= \pg_num_rows($handle)) {
                    $result = \pg_fetch_array($handle, null, \PGSQL_NUM);

                    if ($result === false) {
                        throw new FailureException(\pg_result_error($handle));
                    }

                    yield self::processRow($fieldNames, $fieldTypes, $result);
                }
            } finally {
                \pg_free_result($handle);
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function continue(): ?array
    {
        return $this->generator->continue();
    }

    /**
     * @inheritDoc
     */
    public function dispose(): void
    {
        $this->generator->dispose();
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Iterator
    {
        return $this->generator->getIterator();
    }

    /**
     * @inheritDoc
     */
    public function getNextResult(): ?Result
    {
        return await($this->nextResult);
    }

    /**
     * @return int Number of rows returned.
     */
    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * @param array<int, string> $fieldNames
     * @param array<int, int> $fieldTypes
     * @param array<int, mixed> $result
     *
     * @return array<string, mixed>
     * @throws ParseException
     */
    private static function processRow(array $fieldNames, array $fieldTypes, array $result): array
    {
        $columnCount = \count($result);
        for ($column = 0; $column < $columnCount; ++$column) {
            if ($result[$column] === null) {
                continue;
            }

            $result[$column] = self::cast($fieldTypes, $column, $result[$column]);
        }

        return \array_combine($fieldNames, $result);
    }

    /**
     * @see https://github.com/postgres/postgres/blob/REL_10_STABLE/src/include/catalog/pg_type.h for OID types.
     *
     * @param array<int, int> $fieldTypes
     * @param int $column
     * @param string $value
     *
     * @return array|bool|float|int Cast value.
     *
     * @throws ParseException
     */
    private static function cast(array $fieldTypes, int $column, string $value)
    {
        switch ($fieldTypes[$column]) {
            case 16: // bool
                return $value === 't';

            case 20: // int8
            case 21: // int2
            case 23: // int4
            case 26: // oid
            case 27: // tid
            case 28: // xid
                return (int) $value;

            case 700: // real
            case 701: // double-precision
                return (float) $value;

            case 1000: // boolean[]
                return self::$parser->parse($value, function (string $value): bool {
                    return $value === 't';
                });

            case 1005: // int2[]
            case 1007: // int4[]
            case 1010: // tid[]
            case 1011: // xid[]
            case 1016: // int8[]
            case 1028: // oid[]
                return self::$parser->parse($value, function (string $value): int {
                    return (int) $value;
                });

            case 1021: // real[]
            case 1022: // double-precision[]
                return self::$parser->parse($value, function (string $value): float {
                    return (float) $value;
                });

            case 1020: // box[] (semi-colon delimited)
                return self::$parser->parse($value, null, ';');

            case 199:  // json[]
            case 629:  // line[]
            case 651:  // cidr[]
            case 719:  // circle[]
            case 775:  // macaddr8[]
            case 791:  // money[]
            case 1001: // bytea[]
            case 1002: // char[]
            case 1003: // name[]
            case 1006: // int2vector[]
            case 1008: // regproc[]
            case 1009: // text[]
            case 1013: // oidvector[]
            case 1014: // bpchar[]
            case 1015: // varchar[]
            case 1019: // path[]
            case 1023: // abstime[]
            case 1024: // realtime[]
            case 1025: // tinterval[]
            case 1027: // polygon[]
            case 1034: // aclitem[]
            case 1040: // macaddr[]
            case 1041: // inet[]
            case 1115: // timestamp[]
            case 1182: // date[]
            case 1183: // time[]
            case 1185: // timestampz[]
            case 1187: // interval[]
            case 1231: // numeric[]
            case 1263: // cstring[]
            case 1270: // timetz[]
            case 1561: // bit[]
            case 1563: // varbit[]
            case 2201: // refcursor[]
            case 2207: // regprocedure[]
            case 2208: // regoper[]
            case 2209: // regoperator[]
            case 2210: // regclass[]
            case 2211: // regtype[]
            case 2949: // txid_snapshot[]
            case 2951: // uuid[]
            case 3221: // pg_lsn[]
            case 3643: // tsvector[]
            case 3644: // gtsvector[]
            case 3645: // tsquery[]
            case 3735: // regconfig[]
            case 3770: // regdictionary[]
            case 3807: // jsonb[]
            case 3905: // int4range[]
            case 3907: // numrange[]
            case 3909: // tsrange[]
            case 3911: // tstzrange[]
            case 3913: // daterange[]
            case 3927: // int8range[]
            case 4090: // regnamespace[]
            case 4097: // regrole[]
                return self::$parser->parse($value);

            default:
                return $value;
        }
    }
}

(function () {
    self::$parser = new Internal\ArrayParser;
})->bindTo(null, PgSqlResultSet::class)();
