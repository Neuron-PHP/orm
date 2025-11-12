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
	private array $_wheres = [];
	private array $_bindings = [];
	private array $_with = [];
	private ?int $_limit = null;
	private ?int $_offset = null;
	private array $_orderBy = [];

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
	 * Build the SQL query.
	 *
	 * @return string
	 */
	protected function buildSql(): string
	{
		$sql = "SELECT * FROM {$this->_table}";

		if( !empty( $this->_wheres ) )
		{
			$sql .= ' WHERE ' . $this->buildWhereClause();
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
			$clause = "{$where['column']} {$where['operator']} ?";

			if( $index > 0 )
			{
				$clause = "{$where['type']} {$clause}";
			}

			$clauses[] = $clause;
		}

		return implode( ' ', $clauses );
	}
}
