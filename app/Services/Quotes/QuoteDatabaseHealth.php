<?php

namespace App\Services\Quotes;

use Illuminate\Database\ConnectionInterface;
use Throwable;

class QuoteDatabaseHealth
{
    /**
     * @param  array<int, string>  $requiredTables
     */
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly array $requiredTables = [],
    ) {}

    /**
     * @return array{ok:bool, connection:string|null, schema:string, select_1:bool, tables:array<string,bool>, error:string|null}
     */
    public function check(): array
    {
        $tables = [];

        try {
            $this->database->selectOne('select 1 as ok');
            $schema = (string) (config('database.connections.quote_db.search_path') ?: 'public');

            foreach ($this->requiredTables() as $table) {
                $tables[$table] = $this->database->getSchemaBuilder()->hasTable($table);
            }

            return [
                'ok' => ! in_array(false, $tables, true),
                'connection' => $this->database->getName(),
                'schema' => $schema,
                'select_1' => true,
                'tables' => $tables,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'ok' => false,
                'connection' => $this->database->getName(),
                'schema' => (string) (config('database.connections.quote_db.search_path') ?: 'public'),
                'select_1' => false,
                'tables' => $tables,
                'error' => 'QUOTE_DATABASE_UNAVAILABLE',
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        if ($this->requiredTables !== []) {
            return $this->requiredTables;
        }

        return [
            (string) config('quote_engine.tables.aircraft'),
            (string) config('quote_engine.tables.national_airports'),
            (string) config('quote_engine.tables.international_airports'),
            (string) config('quote_engine.tables.blocked_dates'),
            (string) config('quote_engine.tables.reservations'),
        ];
    }
}
