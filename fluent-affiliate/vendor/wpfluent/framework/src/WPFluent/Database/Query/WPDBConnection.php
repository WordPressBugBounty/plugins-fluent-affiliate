<?php

/*
 * WPDB Connection
 */

namespace FluentAffiliate\Framework\Database\Query;

use Closure;
use Exception;
use RuntimeException;
use DateTimeInterface;
use FluentAffiliate\Framework\Foundation\App;
use FluentAffiliate\Framework\Database\Schema;
use FluentAffiliate\Framework\Database\QueryException;
use FluentAffiliate\Framework\Database\UniqueConstraintViolationException;
use FluentAffiliate\Framework\Database\ConnectionInterface;
use FluentAffiliate\Framework\Database\MultipleColumnsSelectedException;
use FluentAffiliate\Framework\Database\Events\QueryExecuted;
use FluentAffiliate\Framework\Database\Query\Expression;
use FluentAffiliate\Framework\Database\Query\Processors\Processor;
use FluentAffiliate\Framework\Database\Query\Processors\MySqlProcessor;
use FluentAffiliate\Framework\Database\Query\Processors\SQLiteProcessor;
use FluentAffiliate\Framework\Database\Query\Builder as QueryBuilder;
use FluentAffiliate\Framework\Database\Query\Grammars\Grammar;
use FluentAffiliate\Framework\Database\Query\Grammars\MySqlGrammar;
use FluentAffiliate\Framework\Database\Query\Grammars\SQLiteGrammar;
use FluentAffiliate\Framework\Database\Concerns\ManagesTransactions;
use FluentAffiliate\Framework\Database\DetectsLostConnections;
use FluentAffiliate\Framework\Database\DetectsConcurrencyErrors;

use FluentAffiliate\Framework\Database\Events\TransactionBeginning;
use FluentAffiliate\Framework\Database\Events\TransactionCommitted;
use FluentAffiliate\Framework\Database\Events\TransactionCommitting;
use FluentAffiliate\Framework\Database\Events\TransactionRolledBack;

class WPDBConnection implements ConnectionInterface
{
    use DetectsLostConnections, DetectsConcurrencyErrors, ManagesTransactions;

    /**
     * $wpdb Global $wpdb instance
     * @var Object
     */
    protected $wpdb;

    /**
     * The name of the connected database.
     *
     * @var string
     */
    protected $database;

    /**
     * The table prefix for the connection.
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * The database connection configuration options.
     *
     * @var array
     */
    protected $config = [];

    /**
     * The query grammar implementation.
     *
     * @var \FluentAffiliate\Framework\Database\Query\Grammars\Grammar
     */
    protected $queryGrammar;

    /**
     * The query post processor implementation.
     *
     * @var \FluentAffiliate\Framework\Database\Query\Processors\Processor
     */
    protected $postProcessor;

    /**
     * The number of active transactions.
     *
     * @var int
     */
    protected $transactions = 0;

    /**
     * The transaction manager instance.
     *
     * @var \FluentAffiliate\Framework\Database\DatabaseTransactionsManager|null
     */
    protected $transactionsManager;

    /**
     * All of the callbacks that should be invoked before a transaction is started.
     *
     * @var \Closure[]
     */
    protected $beforeStartingTransaction = [];

    /**
     * The event dispatcher.
     *
     * @var \FluentAffiliate\Framework\Events\Dispatcher
     */
    protected $event = null;

    /**
     * Create a new database connection instance.
     *
     * @param \wpdb $wpdb The WordPress database instance.
     * @return void
     */
    public function __construct($wpdb)
    {
        $this->setupWpdbInstance($wpdb);

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();

        $this->event = App::make('events');
    }

    /**
     * Populate $wpdb instance.
     *
     * Whether $wpdb prints SQL errors is left to WordPress (WP_DEBUG and
     * WP_DEBUG_DISPLAY): $wpdb is shared by every plugin on the site.
     *
     * @param  $wpdb Global $wpdb instance
     * @return Null
     */
    protected function setupWpdbInstance($wpdb)
    {
        $this->wpdb = $wpdb;

        $this->registerSqliteFunctions();
    }

    /**
     * Register a PHP-backed SOUNDEX() function on the SQLite connection so
     * phonetic ("sounds like") queries work, mirroring MySQL's native
     * equivalent.
     *
     * SQLite doesn't ship the function; PHP provides it natively, so we bind
     * it as a UDF. Using PHP's soundex() here matches the term that
     * SQLiteGrammar encodes with the same soundex() on the binding side.
     *
     * No-ops on MySQL and silently skips if the underlying PDO is unreachable,
     * so a missing SQLite layer never breaks booting.
     *
     * @return void
     */
    protected function registerSqliteFunctions()
    {
        if (! $this->isSqlite()) {
            return;
        }

        if (! ($pdo = $this->resolveSqlitePdo())) {
            return;
        }

        try {
            $pdo->sqliteCreateFunction('soundex', 'soundex', 1);
        } catch (\Throwable $e) {
            // Leave the function unregistered rather than break booting;
            // whereSoundsLike() will only surface a SQL error if it is
            // actually used on this connection.
        }
    }

    /**
     * Resolve the real PDO handle behind the WordPress SQLite layer.
     *
     * Supports the official "SQLite Database Integration" plugin
     * (WP_SQLite_Translator::get_pdo()), a dbh that is itself a PDO
     * (WP-SQLite-DB's PDOEngine), and the shared $GLOBALS['@pdo'] cache.
     *
     * @return \PDO|null
     */
    protected function resolveSqlitePdo()
    {
        $dbh = $this->wpdb->dbh ?? null;

        if ($dbh && method_exists($dbh, 'get_pdo')) {
            $pdo = $dbh->get_pdo();
        } elseif ($dbh instanceof \PDO) {
            $pdo = $dbh;
        } elseif (isset($GLOBALS['@pdo'])) {
            $pdo = $GLOBALS['@pdo'];
        } else {
            $pdo = null;
        }

        return $pdo instanceof \PDO ? $pdo : null;
    }

    /**
     * Set the query grammar to the default implementation.
     *
     * @return void
     */
    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \FluentAffiliate\Framework\Database\Query\Grammars\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->isSqlite() ? new SQLiteGrammar : new MySqlGrammar;
    }

    /**
     * Set the query post processor to the default implementation.
     *
     * @return void
     */
    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }

    /**
     * Get the default post processor instance.
     *
     * @return \FluentAffiliate\Framework\Database\Query\Processors\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return $this->isSqlite() ? new SQLiteProcessor : new MySqlProcessor;
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param \Closure|\FluentAffiliate\Framework\Database\Query\Builder|string $table
     * @param string|null $as
     * @return \FluentAffiliate\Framework\Database\Query\Builder
     */
    public function table($table, $as = null)
    {
        return $this->query()->from($table, $as);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \FluentAffiliate\Framework\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param string $query
     * @param array $bindings
     * @return mixed
     */
    public function selectOne($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings);

            $result = $this->wpdb->get_row($query);

            if ($result === false || $this->wpdb->last_error) {
                throw $this->queryException(
                    $query, $bindings, $this->wpdb->last_error
                );
            }

            return $result;
        });
    }

    /**
     * Run a select statement and return the first column of the first row.
     *
     * @param string $query
     * @param array $bindings
     * @return mixed
     *
     * @throws \FluentAffiliate\Framework\Database\MultipleColumnsSelectedException
     */
    public function scalar($query, $bindings = [])
    {
        $record = $this->selectOne($query, $bindings);

        if (is_null($record)) {
            return null;
        }

        $record = (array)$record;

        if (count($record) > 1) {
            throw new MultipleColumnsSelectedException(
                'The query returned more than one column.'
            );
        }

        return reset($record);
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return array
     */
    public function select($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings);

            $result = $this->wpdb->get_results($query);

            if ($result === false || $this->wpdb->last_error) {
                throw $this->queryException(
                    $query, $bindings, $this->wpdb->last_error
                );
            }

            return $result;
        });
    }

    /**
     * The query as the database receives it: its values filled in and
     * escaped as they are when it runs, and each % as written.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string
     */
    public function toRawSql($query, array $bindings = [])
    {
        return $this->wpdb->remove_placeholder_escape(
            $this->bindParams($query, $bindings)
        );
    }

    /**
     * Escape a value for use in SQL, as WordPress escapes it.
     *
     * @param  string|float|int|bool|null  $value
     * @param  bool  $binary
     * @return string
     *
     * @throws \RuntimeException
     */
    public function escape($value, $binary = false)
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($binary) {
            return "x'" . bin2hex($value) . "'";
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            throw new RuntimeException('The database connection does not support escaping arrays.');
        }

        $value = (string) $value;

        if (strpos($value, "\0") !== false) {
            throw new RuntimeException('Strings with null bytes cannot be escaped. Use the binary escape option.');
        }

        if (preg_match('//u', $value) === false) {
            throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
        }

        return "'" . $this->wpdb->remove_placeholder_escape($this->wpdb->_real_escape($value)) . "'";
    }

    /**
     * Bind the parameters into SQL query
     *
     * @param $query
     * @param $bindings
     * @return string
     */
    protected function bindParams(string $query, array $bindings)
    {
        $bindings = $this->prepareBindings($bindings);

        [$sql, $values] = $this->compileQuery($query, $bindings, '%s', true);

        if (empty($values)) {
            return $this->compileQuery($query, $bindings, '%s', false)[0];
        }

        if ($this->wpdb->dbh instanceof \mysqli) {
            return $this->wpdb->prepare($sql, ...$values);
        }

        return vsprintf($sql, array_map(function ($value) {
            if (is_string($value)) return "'" . esc_sql($value) . "'";
            return (string) $value;
        }, $values));
    }

    /**
     * Rewrite a query for wpdb, outside quoted values and comments only:
     * a "quoted" identifier gets backticks, each ? placeholder becomes
     * $placeholder (or NULL for a null binding, which a prepared %s or a
     * bound parameter would turn into ''), and with $escapePercent every
     * % is doubled for wpdb->prepare(). A " or ? inside 'a value', a
     * `name` or a comment is left as it is.
     *
     * @param  string  $query
     * @param  array   $bindings
     * @param  string  $placeholder  '%s' for wpdb->prepare(), '?' for mysqli.
     * @param  bool    $escapePercent
     * @return array{0:string,1:array}  The query and the bindings its placeholders take.
     */
    protected function compileQuery(string $query, array $bindings, string $placeholder, bool $escapePercent): array
    {
        $bindings = array_values($bindings);
        $percent = $escapePercent ? '%%' : '%';
        $length = strlen($query);
        $sql = '';
        $values = [];
        $index = 0;

        for ($i = 0; $i < $length; $i++) {
            // Copy the run of characters that need no rewriting at once.
            if ($run = strcspn($query, "'`-#/?\"%", $i)) {
                $sql .= substr($query, $i, $run);

                if (($i += $run) >= $length) {
                    break;
                }
            }

            $char = $query[$i];

            if ($char === "'" || $char === '`') {
                $end = $this->endOfQuoted($query, $i, $char);
            } elseif ($char === '-' && preg_match('/\G--(\s|$)/', $query, $m, 0, $i)) {
                $end = $this->endOfLine($query, $i);
            } elseif ($char === '#') {
                $end = $this->endOfLine($query, $i);
            } elseif ($char === '/' && substr($query, $i, 2) === '/*') {
                $end = ($close = strpos($query, '*/', $i + 2)) === false ? $length - 1 : $close + 1;
            } else {
                $end = null;
            }

            if ($end !== null) {
                $sql .= str_replace('%', $percent, substr($query, $i, $end - $i + 1));
                $i = $end;
            } elseif ($char === '?') {
                if (array_key_exists($index, $bindings) && $bindings[$index] === null) {
                    $sql .= 'NULL';
                } else {
                    $sql .= $placeholder;

                    if (array_key_exists($index, $bindings)) {
                        $values[] = $bindings[$index];
                    }
                }

                $index++;
            } elseif ($char === '"') {
                $sql .= '`';
            } elseif ($char === '%') {
                $sql .= $percent;
            } else {
                $sql .= $char;
            }
        }

        return [$sql, $values];
    }

    /**
     * Find the closing quote of the value or name that opens at $start. A
     * doubled quote ('' or ``) and, in a value, a backslash escape stay
     * inside it.
     *
     * @param  string  $query
     * @param  int     $start
     * @param  string  $quote
     * @return int
     */
    protected function endOfQuoted(string $query, int $start, string $quote): int
    {
        $length = strlen($query);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($query[$i] === '\\' && $quote === "'") {
                $i++;
            } elseif ($query[$i] === $quote) {
                if (($query[$i + 1] ?? '') !== $quote) {
                    return $i;
                }

                $i++;
            }
        }

        return $length - 1;
    }

    /**
     * Find the end of the line comment that starts at $start.
     *
     * @param  string  $query
     * @param  int     $start
     * @return int
     */
    protected function endOfLine(string $query, int $start): int
    {
        $end = strpos($query, "\n", $start);

        return $end === false ? strlen($query) - 1 : $end;
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array $bindings
     * @return \Generator
     * @throws \FluentAffiliate\Framework\Database\QueryException
     */
    public function cursor($query, $bindings = [])
    {
        // If not mysqli (e.g., SQLite), fallback to standard select
        if (!$this->wpdb->dbh instanceof \mysqli) {
            foreach ($this->select($query, $bindings) as $row) {
                yield $row;
            }
            return;
        }

        [$preparedQuery, $bindings] = $this->compileQuery(
            $query, $this->prepareBindings($bindings), '?', false
        );

        $this->wpdb->flush();
        $this->wpdb->insert_id = 0;
        $this->wpdb->check_current_query = true;

        if (!$this->wpdb->check_connection()) {
            throw new QueryException(
                $query, $bindings, new Exception(
                    $this->wpdb->last_error ?: 'Error reconnecting to database.'
                )
            );
        }

        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            $this->wpdb->timer_start();
        }

        $statement = $this->wpdb->dbh->prepare($preparedQuery);

        if ($statement === false) {
            throw new QueryException(
                $query, $bindings, new Exception(
                    'Failed to prepare statement: ' . $this->wpdb->dbh->error
                )
            );
        }

        if (!empty($bindings)) {
            $types = '';
            foreach ($bindings as $binding) {
                if (is_int($binding)) {
                    $types .= 'i';
                } elseif (is_double($binding)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $statement->bind_param($types, ...$bindings);
        }

        if ($statement->execute()) {
            $result = $statement->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    yield (object) $row;
                }
                $result->free();
            } else {
                if ($statement->errno) {
                    throw $this->queryException(
                        $query, $bindings, $statement->error, $statement->errno
                    );
                }
            }
            $statement->close();
            return;
        }

        // Error handling if statement execution fails
        if ($statement->error || $statement->errno) {
            $err = $statement->error
                ? $statement->error
                : 'Mysqli Error No: ' . $statement->errno;

            $this->wpdb->last_error = $err;

            throw $this->queryException(
                $query, $bindings, $err, $statement->errno
            );
        }
    }

    /**
     * Raw cursor query for MySQLi (non-prepared).
     * 
     * @param  string $query
     * @param  array  $bindings
     * @return \Generator
     * @throws \FluentAffiliate\Framework\Database\QueryException
     */
    public function rawCursor($query, $bindings = [])
    {
        $query = $this->bindParams($query, $bindings);

        if (!$this->wpdb->dbh instanceof \mysqli) {
            foreach ($this->select($query) as $row) { yield $row; }
            return;
        }

        $stmt = $this->wpdb->dbh->query($query, MYSQLI_USE_RESULT);

        if ($stmt instanceof \mysqli_result) {
            try {
                while ($row = $stmt->fetch_assoc()) {
                    yield (object) $row;
                }
            } finally {
                $stmt->free();
            }
        } elseif ($this->wpdb->dbh->error) {
            throw new QueryException(
                $query, $bindings, new Exception($this->wpdb->dbh->error)
            );
        }
    }

    /**
     * Run an insert statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function update($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings, true);

            $result = $this->unprepared($query);

            if ($result === false || $this->wpdb->last_error) {
                throw $this->queryException(
                    $query, $bindings, $this->wpdb->last_error
                );
            }

            return $result;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings, true);

            $result = $this->wpdb->query($query);

            if ($result === false || $this->wpdb->last_error) {
                throw $this->queryException(
                    $query, $bindings, $this->wpdb->last_error
                );
            }

            return intval($result);
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     * @return bool
     */
    public function unprepared($query)
    {
        return $this->wpdb->query($query);
    }

    /**
     * Execute the given callback in "dry run" mode.
     *
     * @param \Closure $callback
     * @return array
     */
    public function pretend(Closure $callback)
    {
        // ...
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            // We need to transform all instances of DateTimeInterface into
            // the actual date string. Each query grammar maintains its
            // own date string format so we'll just ask the grammar
            // for the format to get from the date.
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int)$value;
            }
        }

        return $bindings;
    }

    /**
     * Run a SQL statement and log its execution context.
     * 
     * @param  string $query
     * @param  array $bindings
     * @param  \Closure $callback
     * @return mixed
     */
    public function run($query, $bindings, $callback)
    {
        $start = microtime(true);

        try {
            return $callback($query, $bindings);
        } finally {
            $time = $this->getElapsedTime($start);
            $this->event->dispatch(
                new QueryExecuted($query, $bindings, $time, $this)
            );
        }
    }

    /**
     * Get a new raw query expression.
     *
     * @param mixed $value
     * @return \FluentAffiliate\Framework\Database\Query\Expression
     */
    public function raw($value)
    {
        return new Expression($value);
    }

    /**
     * Get the query grammar used by the connection.
     *
     * @return \FluentAffiliate\Framework\Database\Query\Grammars\Grammar
     */
    public function getQueryGrammar()
    {
        $this->queryGrammar->setTablePrefix($this->wpdb);

        // The grammar escapes values through the connection.
        $this->queryGrammar->setConnection($this);

        return $this->queryGrammar;
    }

    /**
     * Set the query grammar used by the connection.
     *
     * @param \FluentAffiliate\Framework\Database\Query\Grammars\Grammar $grammar
     * @return $this
     */
    public function setQueryGrammar(Grammar $grammar)
    {
        $this->queryGrammar = $grammar;

        return $this;
    }

    /**
     * Get the query post processor used by the connection.
     *
     * @return \FluentAffiliate\Framework\Database\Query\Processors\Processor
     */
    public function getPostProcessor()
    {
        return $this->postProcessor;
    }

    /**
     * Set the query post processor used by the connection.
     *
     * @param \FluentAffiliate\Framework\Database\Query\Processors\Processor $processor
     * @return $this
     */
    public function setPostProcessor(Processor $processor)
    {
        $this->postProcessor = $processor;

        return $this;
    }

    /**
     * Return the last insert id
     *
     * @param string $args
     *
     * @return int
     */
    public function lastInsertId($args)
    {
        return $this->wpdb->insert_id;
    }

    /**
     * Return self as PDO, the Processor instance uses it.
     *
     * @return \FluentAffiliate\Framework\Database\Query\WPDBConnection
     */
    public function getPdo()
    {
        return $this;
    }

    /**
     * Returns the $wpdb object.
     *
     * @return Object $wpdb
     */
    public function getWPDB()
    {
        return $this->wpdb;
    }

    /**
     * Get the database connection name.
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->isSqlite() ? 'sqlite' : 'mysql';
    }

    /**
     * Get the name of the connected database.
     *
     * @return string
     */
    public function getDatabaseName()
    {
        if ($this->isSqlite()) {
            return 'sqlite';
        }

        return $this->wpdb->dbname;
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     *
     * @phpstan-ignore-next-line
     */
    public function getServerVersion(): string
    {
        return $this->getWPDB()->db_version();
    }

    /**
     * Get the column listing for a given table.
     *
     * @param string $table
     * @return array
     */
    public function getColumnListing($table)
    {
        return Schema::getColumns($table);
    }

    /**
     * Alias for getColumnListing.
     *
     * @param  string  $t
     * @return array
     */
    public function getColumns($t)
    {
        return $this->getColumnListing($t);
    }

    /**
     * Determine if the connected database is a sqlite database.
     *
     * @return bool
     */
    public function isSqlite()
    {
        return Schema::isSqlite();
    }

    /**
     * Determine if the connected database is a mariadb database.
     *
     * @return bool
     */
    public function isMaria()
    {
        return Schema::isMaria();
    }

    /**
     * Register a hook to be run just before a database transaction is started.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function beforeStartingTransaction(Closure $callback)
    {
        $this->beforeStartingTransaction[] = $callback;

        return $this;
    }

    /**
     * Register a database query listener with the connection.
     *
     * @param \Closure $callback
     * @return void
     */
    public function listen(Closure $callback)
    {
        $this->event->listen(QueryExecuted::class, $callback);
    }

    /**
     * Fire an event for this connection.
     *
     * @param  string  $event
     * @return array|null
     */
    protected function fireConnectionEvent($event)
    {
        if (!$this->event) {
            return;
        }

        switch ($event) {
            case 'beganTransaction':
                $payload = new TransactionBeginning($this);
                break;
            case 'committed':
                $payload = new TransactionCommitted($this);
                break;
            case 'committing':
                $payload = new TransactionCommitting($this);
                break;
            case 'rollingBack':
                $payload = new TransactionRolledBack($this);
                break;
            default:
                $payload = null;
                break;
        }

        if ($payload !== null) {
            return $this->event->dispatch($payload);
        }
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param int $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Get the table prefix for the connection.
     * 
     * @return [type] [description]
     */
    public function getTablePrefix()
    {
        if (!$this->tablePrefix) {
            $this->tablePrefix = $this->queryGrammar->getTablePrefix();
        }

        return $this->tablePrefix;
    }

    /**
     * Get the table name with the table prefix.
     * 
     * @param  string $table
     * @return string       
     */
    public function getTableName($table)
    {
        return $this->getTablePrefix() . $table;
    }

    /**
     * Build the exception for a failed query: a unique key it broke
     * gives a UniqueConstraintViolationException, so createOrFirst() can
     * find the row another request created; anything else a
     * QueryException.
     *
     * @param  string   $query
     * @param  array    $bindings
     * @param  string   $message
     * @param  int|null $errno The MySQL error number, when known.
     * @return \FluentAffiliate\Framework\Database\QueryException
     */
    protected function queryException($query, $bindings, $message, $errno = null)
    {
        $previous = new Exception($message);

        return $this->isUniqueConstraintViolation($message, $errno)
            ? new UniqueConstraintViolationException($query, $bindings, $previous)
            : new QueryException($query, $bindings, $previous);
    }

    /**
     * Whether a failed query broke a unique key. On MySQL the error
     * number (1062) decides; otherwise the message does, in the exact
     * form MySQL or SQLite writes it, since the SQLite plugin's message
     * also carries the whole query and its values.
     *
     * @param  string   $message
     * @param  int|null $errno
     * @return bool
     */
    protected function isUniqueConstraintViolation($message, $errno = null)
    {
        if (is_null($errno) && $this->wpdb->dbh instanceof \mysqli) {
            $errno = $this->wpdb->dbh->errno;
        }

        if ($errno) {
            return (int) $errno === 1062;
        }

        // MySQL: "Duplicate entry 'a' for key 'sku'", the whole message.
        // SQLite: "SQLSTATE[23000]: Integrity constraint violation: 19
        // UNIQUE constraint failed: wp_items.sku".
        return (bool) preg_match(
            "/^Duplicate entry '.*' for key '|Integrity constraint violation: 19 UNIQUE constraint failed/s",
            (string) $message
        );
    }
}
