<?php

namespace Neuron\Orm\Query;

use Closure;
use Generator;
use PDO;
use Neuron\Orm\Model;
use Neuron\Orm\Exceptions\ModelException;

/**
 * Fluent query builder for models.
 *
 * Provides a Rails-like interface for querying models with support
 * for eager loading relations.
 *
 * @package Neuron\Orm\Query
 */
class QueryBuilder
{
	private PDO $_pdo;
	private string $_modelClass;
	private string $_table;
	private ?string $_tableAlias = null;
	private array $_select = ['*'];
	private bool $_distinct = false;
	private array $_joins = [];
	private array $_wheres = [];
	private array $_bindings = [];
	private array $_with = [];
	private ?int $_limit = null;
	private ?int $_offset = null;
	private array $_orderBy = [];
	private array $_groupBy = [];
	private array $_having = [];

	/**
	 * Constructor
	 *
	 * @param PDO $pdo Database connection
	 * @param string $modelClass Model class name
	 */
	public function __construct( PDO $pdo, string $modelClass )
	{
		$this->_pdo = $pdo;
		$this->_modelClass = $modelClass;
		$this->_table = $modelClass::getTableName();
	}

	/**
	 * Add a where clause.
	 *
	 * @param string $column
	 * @param mixed $operator
	 * @param mixed|null $value
	 * @return $this
	 */
	public function where( string|Closure $column, mixed $operator = null, mixed $value = null ): self
	{
		return $this->addWhere( 'AND', $column, $operator, $value, func_num_args() );
	}

	/**
	 * Add an OR where clause.
	 *
	 * @param string $column
	 * @param mixed $operator
	 * @param mixed|null $value
	 * @return $this
	 */
	public function orWhere( string|Closure $column, mixed $operator = null, mixed $value = null ): self
	{
		return $this->addWhere( 'OR', $column, $operator, $value, func_num_args() );
	}

	/**
	 * Normalise and store a where clause.
	 *
	 * Handles the three call shapes:
	 *   where( Closure )               -> a parenthesised nested group
	 *   where( $column, $value )       -> column = value, or IS NULL when value is null
	 *   where( $column, $op, $value )  -> column op value, or IS [NOT] NULL when value is null
	 *
	 * @param string $boolean AND or OR
	 * @param string|Closure $column
	 * @param mixed $operator
	 * @param mixed $value
	 * @param int $argCount Number of arguments the caller actually passed
	 * @return $this
	 */
	protected function addWhere( string $boolean, string|Closure $column, mixed $operator, mixed $value, int $argCount ): self
	{
		if( $column instanceof Closure )
		{
			return $this->whereGroup( $boolean, $column );
		}

		// Two-argument form: the second argument is the value, the operator is '='.
		if( $argCount < 3 )
		{
			$value    = $operator;
			$operator = '=';
		}

		// A null value means an IS NULL / IS NOT NULL test; binding null to '=' or
		// '!=' would compare against NULL and never match a row.
		if( $value === null )
		{
			return $this->addNullWhere(
				$boolean,
				$column,
				in_array( $operator, [ '!=', '<>' ], true ) ? 'IS NOT NULL' : 'IS NULL'
			);
		}

		$this->_wheres[] = [
			'kind'     => 'basic',
			'column'   => $column,
			'operator' => $operator,
			'value'    => $value,
			'type'     => $boolean
		];

		return $this;
	}

	/**
	 * Store a parenthesised group of clauses built by a callback.
	 *
	 * The callback receives a fresh builder; only its where clauses are kept, which
	 * is what makes `a AND ( b OR c )` expressible.
	 *
	 * @param string $boolean AND or OR
	 * @param Closure $callback
	 * @return $this
	 */
	protected function whereGroup( string $boolean, Closure $callback ): self
	{
		$nested = new self( $this->_pdo, $this->_modelClass );

		$callback( $nested );

		if( !empty( $nested->_wheres ) )
		{
			$this->_wheres[] = [
				'kind'   => 'group',
				'wheres' => $nested->_wheres,
				'type'   => $boolean
			];
		}

		return $this;
	}

	/**
	 * Store an IS NULL / IS NOT NULL clause.
	 *
	 * @param string $boolean AND or OR
	 * @param string $column
	 * @param string $operator IS NULL or IS NOT NULL
	 * @return $this
	 */
	protected function addNullWhere( string $boolean, string $column, string $operator ): self
	{
		$this->_wheres[] = [
			'kind'     => 'null',
			'column'   => $column,
			'operator' => $operator,
			'value'    => null,
			'type'     => $boolean
		];

		return $this;
	}

	/**
	 * Add a raw SQL where clause.
	 *
	 * The expression is injected verbatim, so never build it from user input;
	 * pass values through $bindings instead.
	 *
	 * @param string $sql Raw SQL predicate, using ? placeholders
	 * @param array $bindings Values for the placeholders
	 * @return $this
	 */
	public function whereRaw( string $sql, array $bindings = [] ): self
	{
		return $this->addRawWhere( 'AND', $sql, $bindings );
	}

	/**
	 * Add a raw SQL where clause joined with OR.
	 *
	 * @param string $sql Raw SQL predicate, using ? placeholders
	 * @param array $bindings Values for the placeholders
	 * @return $this
	 */
	public function orWhereRaw( string $sql, array $bindings = [] ): self
	{
		return $this->addRawWhere( 'OR', $sql, $bindings );
	}

	/**
	 * @param string $boolean AND or OR
	 * @param string $sql
	 * @param array $bindings
	 * @return $this
	 */
	protected function addRawWhere( string $boolean, string $sql, array $bindings ): self
	{
		$this->_wheres[] = [
			'kind'     => 'raw',
			'sql'      => $sql,
			'bindings' => array_values( $bindings ),
			'type'     => $boolean
		];

		return $this;
	}

	/**
	 * Compare two columns against each other.
	 *
	 * Both sides are treated as SQL identifiers or expressions, not as bound
	 * values, which is what where() cannot express.
	 *
	 * @param string $first
	 * @param string $operator
	 * @param string $second
	 * @return $this
	 */
	public function whereColumn( string $first, string $operator, string $second ): self
	{
		return $this->addRawWhere( 'AND', "{$first} {$operator} {$second}", [] );
	}

	/**
	 * Add a WHERE column IS NULL clause.
	 *
	 * @param string $column
	 * @return $this
	 */
	public function whereNull( string $column ): self
	{
		return $this->addNullWhere( 'AND', $column, 'IS NULL' );
	}

	/**
	 * Add an OR column IS NULL clause.
	 *
	 * @param string $column
	 * @return $this
	 */
	public function orWhereNull( string $column ): self
	{
		return $this->addNullWhere( 'OR', $column, 'IS NULL' );
	}

	/**
	 * Add a WHERE column IS NOT NULL clause.
	 *
	 * @param string $column
	 * @return $this
	 */
	public function whereNotNull( string $column ): self
	{
		return $this->addNullWhere( 'AND', $column, 'IS NOT NULL' );
	}

	/**
	 * Add an OR column IS NOT NULL clause.
	 *
	 * @param string $column
	 * @return $this
	 */
	public function orWhereNotNull( string $column ): self
	{
		return $this->addNullWhere( 'OR', $column, 'IS NOT NULL' );
	}

	/**
	 * Add a WHERE IN clause.
	 *
	 * @param string $column
	 * @param array $values
	 * @return $this
	 */
	public function whereIn( string $column, array $values ): self
	{
		return $this->addInWhere( 'AND', $column, $values, false );
	}

	/**
	 * Add a WHERE NOT IN clause.
	 *
	 * @param string $column
	 * @param array $values
	 * @return $this
	 */
	public function whereNotIn( string $column, array $values ): self
	{
		return $this->addInWhere( 'AND', $column, $values, true );
	}

	/**
	 * Add an OR WHERE IN clause.
	 *
	 * @param string $column
	 * @param array $values
	 * @return $this
	 */
	public function orWhereIn( string $column, array $values ): self
	{
		return $this->addInWhere( 'OR', $column, $values, false );
	}

	/**
	 * @param string $boolean AND or OR
	 * @param string $column
	 * @param array $values
	 * @param bool $not
	 * @return $this
	 */
	protected function addInWhere( string $boolean, string $column, array $values, bool $not ): self
	{
		// An empty IN () is not valid SQL. An empty NOT IN excludes nothing, so the
		// clause is dropped either way and the caller's other constraints still apply.
		if( empty( $values ) )
		{
			return $this;
		}

		$this->_wheres[] = [
			'kind'     => 'in',
			'column'   => $column,
			'operator' => $not ? 'NOT IN' : 'IN',
			'value'    => array_values( $values ),
			'not'      => $not,
			'type'     => $boolean
		];

		return $this;
	}

	/**
	 * Specify relations to eager load.
	 *
	 * @param array|string $relations
	 * @return $this
	 */
	public function with( array|string $relations ): self
	{
		if( is_string( $relations ) )
		{
			$relations = [ $relations ];
		}

		$this->_with = array_merge( $this->_with, $relations );

		return $this;
	}

	/**
	 * Set result limit.
	 *
	 * @param int $limit
	 * @return $this
	 */
	public function limit( int $limit ): self
	{
		$this->_limit = $limit;
		return $this;
	}

	/**
	 * Set result offset.
	 *
	 * @param int $offset
	 * @return $this
	 */
	public function offset( int $offset ): self
	{
		$this->_offset = $offset;
		return $this;
	}

	/**
	 * Add an order by clause.
	 *
	 * @param string $column
	 * @param string $direction
	 * @return $this
	 */
	public function orderBy( string $column, string $direction = 'ASC' ): self
	{
		$this->_orderBy[] = [
			'column' => $column,
			'direction' => strtoupper( $direction )
		];

		return $this;
	}

	/**
	 * Add a GROUP BY clause.
	 *
	 * @param string|array $columns Column name(s) to group by
	 * @return $this
	 */
	public function groupBy( string|array $columns ): self
	{
		$columns = is_array( $columns ) ? $columns : [ $columns ];
		$this->_groupBy = array_merge( $this->_groupBy, $columns );

		return $this;
	}

	/**
	 * Add a HAVING clause, for filtering on aggregates after GROUP BY.
	 *
	 * @param string $column Column or aggregate expression
	 * @param mixed $operator
	 * @param mixed|null $value
	 * @return $this
	 */
	public function having( string $column, mixed $operator = null, mixed $value = null ): self
	{
		$argCount = func_num_args();

		if( $argCount < 3 )
		{
			$value    = $operator;
			$operator = '=';
		}

		$this->_having[] = [
			'kind'     => 'basic',
			'column'   => $column,
			'operator' => $operator,
			'value'    => $value,
			'type'     => 'AND'
		];

		return $this;
	}

	/**
	 * Add a raw HAVING clause.
	 *
	 * The expression is injected verbatim, so never build it from user input;
	 * pass values through $bindings instead.
	 *
	 * @param string $sql Raw SQL predicate, using ? placeholders
	 * @param array $bindings Values for the placeholders
	 * @return $this
	 */
	public function havingRaw( string $sql, array $bindings = [] ): self
	{
		$this->_having[] = [
			'kind'     => 'raw',
			'sql'      => $sql,
			'bindings' => array_values( $bindings ),
			'type'     => 'AND'
		];

		return $this;
	}

	/**
	 * Set the columns to select.
	 *
	 * @param string|array $columns Column name(s) to select
	 * @return $this
	 */
	public function select( string|array $columns ): self
	{
		$this->_select = is_array( $columns ) ? $columns : [ $columns ];

		return $this;
	}

	/**
	 * Add columns to the existing select list.
	 *
	 * @param string|array $columns Column name(s) to add
	 * @return $this
	 */
	public function addSelect( string|array $columns ): self
	{
		$columns = is_array( $columns ) ? $columns : [ $columns ];

		// Remove default '*' if adding specific columns
		if( $this->_select === ['*'] )
		{
			$this->_select = [];
		}

		$this->_select = array_merge( $this->_select, $columns );

		return $this;
	}

	/**
	 * Add a raw select expression.
	 *
	 * @param string $expression Raw SQL expression
	 * @return $this
	 */
	public function selectRaw( string $expression ): self
	{
		// Remove default '*' if adding specific columns
		if( $this->_select === ['*'] )
		{
			$this->_select = [];
		}

		$this->_select[] = $expression;

		return $this;
	}

	/**
	 * Add DISTINCT to the query.
	 *
	 * @return $this
	 */
	public function distinct(): self
	{
		$this->_distinct = true;

		return $this;
	}

	/**
	 * Set the table to select from with optional alias.
	 *
	 * @param string $table Table name
	 * @param string|null $alias Optional table alias
	 * @return $this
	 */
	public function from( string $table, ?string $alias = null ): self
	{
		$this->_table = $table;
		$this->_tableAlias = $alias;

		return $this;
	}

	/**
	 * Add an INNER JOIN clause.
	 *
	 * @param string $table Table name (can include alias, e.g., "users u")
	 * @param string $first First column in join condition
	 * @param string $operator Operator (=, !=, <, >, etc.)
	 * @param string $second Second column in join condition
	 * @return $this
	 */
	public function join( string $table, string $first, string $operator, string $second ): self
	{
		return $this->addJoin( 'INNER', $table, $first, $operator, $second );
	}

	/**
	 * Add a LEFT JOIN clause.
	 *
	 * @param string $table Table name (can include alias, e.g., "users u")
	 * @param string $first First column in join condition
	 * @param string $operator Operator (=, !=, <, >, etc.)
	 * @param string $second Second column in join condition
	 * @return $this
	 */
	public function leftJoin( string $table, string $first, string $operator, string $second ): self
	{
		return $this->addJoin( 'LEFT', $table, $first, $operator, $second );
	}

	/**
	 * Add a RIGHT JOIN clause.
	 *
	 * @param string $table Table name (can include alias, e.g., "users u")
	 * @param string $first First column in join condition
	 * @param string $operator Operator (=, !=, <, >, etc.)
	 * @param string $second Second column in join condition
	 * @return $this
	 */
	public function rightJoin( string $table, string $first, string $operator, string $second ): self
	{
		return $this->addJoin( 'RIGHT', $table, $first, $operator, $second );
	}

	/**
	 * Add a CROSS JOIN clause.
	 *
	 * @param string $table Table name (can include alias, e.g., "users u")
	 * @return $this
	 */
	public function crossJoin( string $table ): self
	{
		$this->_joins[] = [
			'type' => 'CROSS',
			'table' => $table,
			'first' => null,
			'operator' => null,
			'second' => null
		];

		return $this;
	}

	/**
	 * Add a join to the query.
	 *
	 * @param string $type Join type (INNER, LEFT, RIGHT)
	 * @param string $table Table name
	 * @param string $first First column in join condition
	 * @param string $operator Operator
	 * @param string $second Second column in join condition
	 * @return $this
	 */
	protected function addJoin( string $type, string $table, string $first, string $operator, string $second ): self
	{
		$this->_joins[] = [
			'type' => $type,
			'table' => $table,
			'first' => $first,
			'operator' => $operator,
			'second' => $second
		];

		return $this;
	}

	/**
	 * Get all results.
	 *
	 * @return array
	 */
	public function get(): array
	{
		$sql = $this->buildSql();

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$models = [];
		foreach( $rows as $row )
		{
			$models[] = $this->_modelClass::fromArray( $row );
		}

		// Eager load relations if specified
		if( !empty( $this->_with ) && !empty( $models ) )
		{
			$this->_modelClass::loadRelations( $this->_with, $models );
		}

		return $models;
	}

	/**
	 * Get all results as raw arrays without hydrating into models.
	 *
	 * This is useful for queries with aggregate functions, computed columns,
	 * or when you need the raw database results without model overhead.
	 *
	 * @return array Array of associative arrays
	 */
	public function getRaw(): array
	{
		$sql = $this->buildSql();

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Iterate the results one hydrated model at a time.
	 *
	 * Unlike get(), no array of every row is built, which is what makes large
	 * result sets affordable. Relations are not eager loaded: with() needs the
	 * full set up front, so accessing a relation inside the loop would query per
	 * row. Use get() when you need with().
	 *
	 * @return Generator Yields one model per row
	 */
	public function cursor(): Generator
	{
		foreach( $this->cursorRaw() as $row )
		{
			yield $this->_modelClass::fromArray( $row );
		}
	}

	/**
	 * Iterate the raw result rows one at a time.
	 *
	 * The streaming counterpart to getRaw(), for aggregates, computed columns and
	 * joined projections that do not map onto the model.
	 *
	 * @return Generator Yields one associative array per row
	 */
	public function cursorRaw(): Generator
	{
		$sql = $this->buildSql();

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		try
		{
			while( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
			{
				yield $row;
			}
		}
		finally
		{
			// Reached on an early break as well as normal exhaustion, so the
			// statement never stays open holding the result set.
			$stmt->closeCursor();
		}
	}

	/**
	 * The PDO driver name for the current connection.
	 *
	 * @return string
	 */
	protected function driverName(): string
	{
		return (string)$this->_pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
	}

	/**
	 * Bind values with their PHP types and execute the statement.
	 *
	 * Passing an array straight to PDOStatement::execute() binds every value as a
	 * string. A string compared against a column is coerced by that column's type,
	 * but an expression such as COUNT(*) in a HAVING clause has no type to coerce
	 * against, so the comparison silently fails. Binding by type avoids that.
	 *
	 * @param \PDOStatement $stmt
	 * @param array $bindings Positional values, in placeholder order
	 * @return void
	 */
	protected function bindAndExecute( \PDOStatement $stmt, array $bindings ): void
	{
		$position = 1;

		foreach( $bindings as $value )
		{
			$stmt->bindValue( $position++, $value, $this->paramTypeOf( $value ) );
		}

		$stmt->execute();
	}

	/**
	 * Map a PHP value onto the PDO parameter type that preserves its semantics.
	 *
	 * @param mixed $value
	 * @return int One of the PDO::PARAM_* constants
	 */
	protected function paramTypeOf( mixed $value ): int
	{
		return match( true )
		{
			is_int( $value )  => PDO::PARAM_INT,
			is_bool( $value ) => PDO::PARAM_BOOL,
			is_null( $value ) => PDO::PARAM_NULL,
			default           => PDO::PARAM_STR
		};
	}

	/**
	 * Get the first result.
	 *
	 * @return Model|null
	 */
	public function first(): ?Model
	{
		$this->limit( 1 );
		$results = $this->get();

		return $results[0] ?? null;
	}

	/**
	 * Find a model by primary key.
	 *
	 * @param int $id
	 * @return Model|null
	 */
	public function find( int $id ): ?Model
	{
		$primaryKey = $this->_modelClass::getPrimaryKey();
		return $this->where( $primaryKey, $id )->first();
	}

	/**
	 * Get all results (alias for get).
	 *
	 * @return array
	 */
	public function all(): array
	{
		return $this->get();
	}

	/**
	 * Count results.
	 *
	 * @return int
	 */
	public function count(): int
	{
		$this->_bindings = [];

		$sql = "SELECT COUNT(*) as count FROM " . $this->buildFromClause();

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		$result = $stmt->fetch( PDO::FETCH_ASSOC );

		return (int)$result['count'];
	}

	/**
	 * Delete records matching the query.
	 *
	 * @return int Number of rows deleted
	 */
	public function delete(): int
	{
		$this->_bindings = [];

		$sql = "DELETE FROM {$this->_table}";

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		return $stmt->rowCount();
	}

	/**
	 * Atomically increment a column's value.
	 *
	 * This method performs an atomic UPDATE query that increments the column value
	 * by the specified amount. This avoids race conditions that occur with the
	 * fetch-increment-save pattern under concurrent requests.
	 *
	 * @param string $column The column to increment
	 * @param int $amount The amount to increment by (default: 1)
	 * @return int Number of rows updated
	 */
	public function increment( string $column, int $amount = 1 ): int
	{
		$this->_bindings = [];

		$sql = "UPDATE {$this->_table} SET {$column} = {$column} + ?";

		$bindings = [ $amount ];

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
			$bindings = array_merge( $bindings, $this->_bindings );
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $bindings );

		return $stmt->rowCount();
	}

	/**
	 * Atomically decrement a column's value.
	 *
	 * This method performs an atomic UPDATE query that decrements the column value
	 * by the specified amount. This avoids race conditions that occur with the
	 * fetch-decrement-save pattern under concurrent requests.
	 *
	 * @param string $column The column to decrement
	 * @param int $amount The amount to decrement by (default: 1)
	 * @return int Number of rows updated
	 */
	public function decrement( string $column, int $amount = 1 ): int
	{
		$this->_bindings = [];

		$sql = "UPDATE {$this->_table} SET {$column} = {$column} - ?";

		$bindings = [ $amount ];

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
			$bindings = array_merge( $bindings, $this->_bindings );
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $bindings );

		return $stmt->rowCount();
	}

	/**
	 * Atomically update column values.
	 *
	 * This method performs an atomic UPDATE query that sets multiple columns to new values.
	 * This avoids race conditions that occur with the fetch-modify-save pattern under
	 * concurrent requests.
	 *
	 * @param array $attributes Associative array of column => value pairs
	 * @return int Number of rows updated
	 */
	public function update( array $attributes ): int
	{
		if( empty( $attributes ) )
		{
			return 0;
		}

		$this->_bindings = [];

		$setClauses = [];
		$bindings = [];

		foreach( $attributes as $column => $value )
		{
			$setClauses[] = "{$column} = ?";
			$bindings[] = $value;
		}

		$sql = "UPDATE {$this->_table} SET " . implode( ', ', $setClauses );

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
			$bindings = array_merge( $bindings, $this->_bindings );
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $bindings );

		return $stmt->rowCount();
	}

	/**
	 * Get the sum of a column.
	 *
	 * @param string $column
	 * @return mixed
	 */
	public function sum( string $column ): mixed
	{
		return $this->aggregate( 'SUM', $column );
	}

	/**
	 * Get the average of a column.
	 *
	 * @param string $column
	 * @return mixed
	 */
	public function avg( string $column ): mixed
	{
		return $this->aggregate( 'AVG', $column );
	}

	/**
	 * Get the maximum value of a column.
	 *
	 * @param string $column
	 * @return mixed
	 */
	public function max( string $column ): mixed
	{
		return $this->aggregate( 'MAX', $column );
	}

	/**
	 * Get the minimum value of a column.
	 *
	 * @param string $column
	 * @return mixed
	 */
	public function min( string $column ): mixed
	{
		return $this->aggregate( 'MIN', $column );
	}

	/**
	 * Execute an aggregate function.
	 *
	 * @param string $function
	 * @param string $column
	 * @return mixed
	 */
	protected function aggregate( string $function, string $column ): mixed
	{
		$this->_bindings = [];

		$sql = "SELECT {$function}({$column}) as aggregate FROM " . $this->buildFromClause();

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$this->bindAndExecute( $stmt, $this->_bindings );

		$result = $stmt->fetch( PDO::FETCH_ASSOC );

		return $result['aggregate'];
	}

	/**
	 * Build the table reference: the table with its optional alias, followed by
	 * any JOINs. Shared by every statement that reads from the table so aliases
	 * and joins cannot drift between them.
	 *
	 * @return string
	 */
	protected function buildFromClause(): string
	{
		$from = $this->_tableAlias
			? "{$this->_table} AS {$this->_tableAlias}"
			: $this->_table;

		foreach( $this->_joins as $join )
		{
			$from .= " {$join['type']} JOIN {$join['table']}";

			// CROSS JOIN doesn't have ON condition
			if( $join['type'] !== 'CROSS' )
			{
				$from .= " ON {$join['first']} {$join['operator']} {$join['second']}";
			}
		}

		return $from;
	}

	/**
	 * Build the SQL query.
	 *
	 * @return string
	 */
	protected function buildSql(): string
	{
		$this->_bindings = [];

		$columns = implode( ', ', $this->_select );
		$distinct = $this->_distinct ? 'DISTINCT ' : '';

		$sql = "SELECT {$distinct}{$columns} FROM " . $this->buildFromClause();

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		if( !empty( $this->_groupBy ) )
		{
			$sql .= ' GROUP BY ' . implode( ', ', $this->_groupBy );
		}

		if( !empty( $this->_having ) )
		{
			$sql .= ' HAVING ' . $this->buildHavingClause();
		}

		if( !empty( $this->_orderBy ) )
		{
			$sql .= ' ORDER BY ';
			$orderClauses = [];
			foreach( $this->_orderBy as $order )
			{
				$orderClauses[] = "{$order['column']} {$order['direction']}";
			}
			$sql .= implode( ', ', $orderClauses );
		}

		if( $this->_limit !== null )
		{
			$sql .= " LIMIT {$this->_limit}";
		}

		if( $this->_offset !== null )
		{
			// SQLite will not parse OFFSET without a preceding LIMIT, and accepts -1
			// as "no limit". Postgres and MySQL both reject a negative LIMIT, so the
			// placeholder is only emitted for the driver that needs it.
			if( $this->_limit === null && $this->driverName() === 'sqlite' )
			{
				$sql .= " LIMIT -1";
			}

			$sql .= " OFFSET {$this->_offset}";
		}

		return $sql;
	}

	/**
	 * Build the WHERE clause.
	 *
	 * @return string
	 */
	protected function buildWhereClause(): string
	{
		return $this->compileWheres( $this->_wheres );
	}

	/**
	 * Compile a list of where clauses into SQL, appending their bound values to
	 * $this->_bindings in the order the placeholders appear.
	 *
	 * Bindings are collected here rather than when the clause is registered so that
	 * nested groups and the HAVING clause stay in step with the generated SQL.
	 *
	 * @param array $wheres
	 * @return string
	 */
	protected function compileWheres( array $wheres ): string
	{
		$clauses = [];

		foreach( $wheres as $index => $where )
		{
			$clause = $this->compileWhere( $where );

			if( $clause === '' )
			{
				continue;
			}

			// The first clause carries no AND/OR; it is the start of the expression.
			if( $index > 0 )
			{
				$clause = "{$where['type']} {$clause}";
			}

			$clauses[] = $clause;
		}

		return implode( ' ', $clauses );
	}

	/**
	 * Compile a single where clause and collect its bindings.
	 *
	 * @param array $where
	 * @return string
	 */
	protected function compileWhere( array $where ): string
	{
		// Entries predating the 'kind' key are plain column/operator/value clauses.
		switch( $where['kind'] ?? 'basic' )
		{
			case 'group':
				$inner = $this->compileWheres( $where['wheres'] );

				return $inner === '' ? '' : "({$inner})";

			case 'raw':
				foreach( $where['bindings'] as $binding )
				{
					$this->_bindings[] = $binding;
				}

				return $where['sql'];

			case 'in':
				$placeholders = implode( ',', array_fill( 0, count( $where['value'] ), '?' ) );

				foreach( $where['value'] as $value )
				{
					$this->_bindings[] = $value;
				}

				return "{$where['column']} {$where['operator']} ({$placeholders})";

			case 'null':
				return "{$where['column']} {$where['operator']}";

			default:
				$this->_bindings[] = $where['value'];

				return "{$where['column']} {$where['operator']} ?";
		}
	}

	/**
	 * Compile the HAVING clause and collect its bindings.
	 *
	 * @return string
	 */
	protected function buildHavingClause(): string
	{
		return $this->compileWheres( $this->_having );
	}
}
