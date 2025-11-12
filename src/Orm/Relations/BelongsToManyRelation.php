<?php

namespace Neuron\Orm\Relations;

use PDO;
use Neuron\Orm\Model;

/**
 * Implements belongs-to-many relationship logic (many-to-many with pivot table).
 *
 * @package Neuron\Orm\Relations
 */
class BelongsToManyRelation extends Relation
{
	private string $_pivotTable;
	private string $_foreignPivotKey;
	private string $_relatedPivotKey;
	private string $_parentKey;
	private string $_relatedKey;

	/**
	 * Constructor
	 *
	 * @param PDO $pdo Database connection
	 * @param Model $parent Parent model instance
	 * @param string $relatedModel Related model class name
	 * @param string $pivotTable Pivot table name
	 * @param string $foreignPivotKey Foreign key in pivot table for parent model
	 * @param string $relatedPivotKey Foreign key in pivot table for related model
	 * @param string $parentKey Parent key column name
	 * @param string $relatedKey Related key column name
	 */
	public function __construct(
		PDO $pdo,
		Model $parent,
		string $relatedModel,
		string $pivotTable,
		string $foreignPivotKey,
		string $relatedPivotKey,
		string $parentKey = 'id',
		string $relatedKey = 'id'
	)
	{
		parent::__construct( $pdo, $parent, $relatedModel );
		$this->_pivotTable = $pivotTable;
		$this->_foreignPivotKey = $foreignPivotKey;
		$this->_relatedPivotKey = $relatedPivotKey;
		$this->_parentKey = $parentKey;
		$this->_relatedKey = $relatedKey;
	}

	/**
	 * Load the related models.
	 *
	 * @return Model|array|null
	 */
	public function load(): Model|array|null
	{
		// Get the parent key value
		$parentKeyValue = $this->_parent->getAttribute( $this->_parentKey );

		if( !$parentKeyValue )
		{
			return [];
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for the related models through pivot table
		$sql = "SELECT r.* FROM {$tableName} r
				INNER JOIN {$this->_pivotTable} p ON r.{$this->_relatedKey} = p.{$this->_relatedPivotKey}
				WHERE p.{$this->_foreignPivotKey} = ?";

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( [ $parentKeyValue ] );
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$results = [];
		foreach( $rows as $row )
		{
			$results[] = $relatedModel::fromArray( $row );
		}

		return $results;
	}

	/**
	 * Eager load relations for multiple models.
	 *
	 * @param array $models Array of parent models
	 * @return void
	 */
	public function eagerLoad( array $models ): void
	{
		if( empty( $models ) )
		{
			return;
		}

		// Collect all parent key values
		$parentKeyValues = [];
		foreach( $models as $model )
		{
			$value = $model->getAttribute( $this->_parentKey );
			if( $value )
			{
				$parentKeyValues[] = $value;
			}
		}

		if( empty( $parentKeyValues ) )
		{
			return;
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for all related models through pivot table
		$placeholders = implode( ',', array_fill( 0, count( $parentKeyValues ), '?' ) );
		$sql = "SELECT r.*, p.{$this->_foreignPivotKey}
				FROM {$tableName} r
				INNER JOIN {$this->_pivotTable} p ON r.{$this->_relatedKey} = p.{$this->_relatedPivotKey}
				WHERE p.{$this->_foreignPivotKey} IN ({$placeholders})";

		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $parentKeyValues );
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		// Group the related models by parent key
		$relatedModels = [];
		foreach( $rows as $row )
		{
			$parentKeyValue = $row[ $this->_foreignPivotKey ];
			if( !isset( $relatedModels[ $parentKeyValue ] ) )
			{
				$relatedModels[ $parentKeyValue ] = [];
			}

			// Remove the pivot key from the row before creating model
			unset( $row[ $this->_foreignPivotKey ] );
			$relatedModels[ $parentKeyValue ][] = $relatedModel::fromArray( $row );
		}

		// Assign the related models to the parent models
		foreach( $models as $model )
		{
			$parentKeyValue = $model->getAttribute( $this->_parentKey );
			$model->setLoadedRelation(
				$this->_pivotTable,
				$relatedModels[ $parentKeyValue ] ?? []
			);
		}
	}
}
