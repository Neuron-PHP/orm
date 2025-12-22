<?php

namespace Neuron\Orm\Query;

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
	public function where( string $column, mixed $operator, mixed $value = null ): self
	{
		// If only 2 parameters, assume = operator
		if( $value === null )
		{
			$value = $operator;
			$operator = '=';
		}

		$this->_wheres[] = [
			'column' => $column,
			'operator' => $operator,
			'value' => $value,
			'type' => 'AND'
		];

		$this->_bindings[] = $value;

		return $this;
	}

	/**
	 * Add an OR where clause.
	 *
	 * @param string $column
	 * @param mixed $operator
	 * @param mixed|null $value
	 * @return $this
	 */
	public function orWhere( string $column, mixed $operator, mixed $value = null ): self
	{
		// If only 2 parameters, assume = operator
		if( $value === null )
		{
			$value = $operator;
			$operator = '=';
		}

		$this->_wheres[] = [
			'column' => $column,
			'operator' => $operator,
			'value' => $value,
			'type' => 'OR'
		];

		$this->_bindings[] = $value;

		return $this;
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
		if( empty( $values ) )
		{
			return $this;
		}

		$this->_wheres[] = [
			'column' => $column,
			'operator' => 'IN',
			'value' => $values,
			'type' => 'AND'
		];

		// Add all values to bindings
		foreach( $values as $value )
		{
			$this->_bindings[] = $value;
		}

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
		$stmt->execute( $this->_bindings );

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
		$sql = "SELECT COUNT(*) as count FROM {$this->_table}";

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $this->_bindings );

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
		$sql = "DELETE FROM {$this->_table}";

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $this->_bindings );

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
		$sql = "UPDATE {$this->_table} SET {$column} = {$column} + ?";

		$bindings = [ $amount ];

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
			$bindings = array_merge( $bindings, $this->_bindings );
		}

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $bindings );

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
		$sql = "UPDATE {$this->_table} SET {$column} = {$column} - ?";

		$bindings = [ $amount ];

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
			$bindings = array_merge( $bindings, $this->_bindings );
		}

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $bindings );

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
		$stmt->execute( $bindings );

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
		$sql = "SELECT {$function}({$column}) as aggregate FROM {$this->_table}";

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $this->_bindings );

		$result = $stmt->fetch( PDO::FETCH_ASSOC );

		return $result['aggregate'];
	}

	/**
	 * Build the SQL query.
	 *
	 * @return string
	 */
	protected function buildSql(): string
	{
		$columns = implode( ', ', $this->_select );
		$distinct = $this->_distinct ? 'DISTINCT ' : '';

		// Build FROM clause with optional alias
		$from = $this->_tableAlias
			? "{$this->_table} AS {$this->_tableAlias}"
			: $this->_table;

		$sql = "SELECT {$distinct}{$columns} FROM {$from}";

		// Add JOINs
		if( !empty( $this->_joins ) )
		{
			foreach( $this->_joins as $join )
			{
				$sql .= " {$join['type']} JOIN {$join['table']}";

				// CROSS JOIN doesn't have ON condition
				if( $join['type'] !== 'CROSS' )
				{
					$sql .= " ON {$join['first']} {$join['operator']} {$join['second']}";
				}
			}
		}

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
		}

		if( !empty( $this->_groupBy ) )
		{
			$sql .= ' GROUP BY ' . implode( ', ', $this->_groupBy );
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

		// SQLite requires LIMIT when using OFFSET
		if( $this->_offset !== null )
		{
			if( $this->_limit === null )
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
		$clauses = [];

		foreach( $this->_wheres as $index => $where )
		{
			// Handle IN operator differently
			if( $where['operator'] === 'IN' )
			{
				$placeholders = implode( ',', array_fill( 0, count( $where['value'] ), '?' ) );
				$clause = "{$where['column']} IN ({$placeholders})";
			}
			else
			{
				$clause = "{$where['column']} {$where['operator']} ?";
			}

			if( $index > 0 )
			{
				$clause = "{$where['type']} {$clause}";
			}

			$clauses[] = $clause;
		}

		return implode( ' ', $clauses );
	}
}
