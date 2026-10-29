<?php

namespace FluentAffiliate\Framework\Database\Query\Processors;

class SQLiteProcessor extends Processor
{
    /**
     * Process the results of a columns query.
     *
     * @param  array  $results
     * @param  string  $sql
     * @return array
     */
    public function processColumns($results, $sql = '')
    {
        $hasPrimaryKey = array_sum(array_column($results, 'primary')) === 1;

        return array_map(function ($result) use ($hasPrimaryKey, $sql) {
            $result = (object) $result;

            $type = strtolower($result->type);

            $safeName = preg_quote($result->name, '/');

            // Read this column's collation from the CREATE TABLE sql: after the
            // column name, skip its type, a size like (10,2) and any DEFAULT,
            // CHECK or AS part, then take the word after COLLATE. '"price"
            // numeric(10,2) default (0) collate binary' gives 'binary'; a
            // column without COLLATE gives null.
            $collation = preg_match(
                '/\b' . $safeName . '\b[^,(]+(?:\([^()]+\)[^,]*)?(?:(?:default|check|as)\s*(?:\(.*?\))?[^,]*)*collate\s+["\'`]?(\w+)/i',
                $sql,
                $matches
            ) === 1 ? strtolower($matches[1]) : null;

            $isGenerated = in_array($result->extra, [2, 3]);

            // For a generated column, read the expression inside AS (...):
            // '"total" integer as (price * qty) stored' gives 'price * qty'.
            // Between the name and AS, a size like (10,2) is skipped whole,
            // so 'decimal(10,2) as (...)' works too. Brackets inside the
            // expression work up to two levels deep, as in
            // 'as (lower(replace(title, ' ', '-')))'.
            $expression = $isGenerated && preg_match(
                '/\b' . $safeName . '\b(?:[^,()]|\([^()]*\))+?\s+as\s+\(((?:[^()]+|\((?:[^()]+|\([^()]*\))*\))*)\)/i',
                $sql,
                $matches
            ) === 1 ? $matches[1] : null;

            $generationType = null;
            
            if ($isGenerated) {
                switch ((int) $result->extra) {
                    case 3:
                        $generationType = 'stored';
                        break;
                    case 2:
                        $generationType = 'virtual';
                        break;
                    default:
                        $generationType = null;
                }
            }

            return [
                'name' => $result->name,
                'type_name' => strtok($type, '(') ?: '',
                'type' => $type,
                'collation' => $collation,
                'nullable' => (bool) $result->nullable,
                'default' => $result->default,
                'auto_increment' => $hasPrimaryKey && $result->primary && $type === 'integer',
                'comment' => null,
                'generation' => $isGenerated ? [
                    'type' => $generationType,
                    'expression' => $expression,
                ] : null,
            ];
        }, $results);
    }

    /**
     * Process the results of an indexes query.
     *
     * @param  array  $results
     * @return array
     */
    public function processIndexes($results)
    {
        $primaryCount = 0;

        $indexes = array_map(function ($result) use (&$primaryCount) {
            $result = (object) $result;

            if ($isPrimary = (bool) $result->primary) {
                $primaryCount += 1;
            }

            return [
                'name' => strtolower($result->name),
                'columns' => explode(',', $result->columns),
                'type' => null,
                'unique' => (bool) $result->unique,
                'primary' => $isPrimary,
            ];
        }, $results);

        if ($primaryCount > 1) {
            $indexes = array_filter($indexes, fn ($index) => $index['name'] !== 'primary');
        }

        return $indexes;
    }

    /**
     * Process the results of a foreign keys query.
     *
     * @param  array  $results
     * @return array
     */
    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => null,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => null,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
