<?php

namespace Neuron\Orm\Relations;

use PDO;
use Neuron\Orm\Model;

/**
 * Implements belongs-to relationship logic.
 *
 * @package Neuron\Orm\Relations
 */
class BelongsToRelation extends Relation
{
	private string $_foreignKey;
	private string $_ownerKey;

	/**
	 * Constructor
	 *
	 * @param PDO $pdo Database connection
	 * @param Model $parent Parent model instance
	 * @param string $relatedModel Related model class name
	 * @param string $foreignKey Foreign key column name
	 * @param string $ownerKey Owner key column name
	 */
	public function __construct(
		PDO $pdo,
		Model $parent,
		string $relatedModel,
		string $foreignKey,
		string $ownerKey = 'id'
	)
	{
		parent::__construct( $pdo, $parent, $relatedModel );
		$this->_foreignKey = $foreignKey;
		$this->_ownerKey = $ownerKey;
	}

	/**
	 * Load the related model.
	 *
	 * @return Model|null
	 */
	public function load(): Model|array|null
	{
		// Get the foreign key value from parent
		$foreignKeyValue = $this->_parent->getAttribute( $this->_foreignKey );

		if( !$foreignKeyValue )
		{
			return null;
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for the related model
		$stmt = $this->_pdo->prepare(
			"SELECT * FROM {$tableName} WHERE {$this->_ownerKey} = ? LIMIT 1"
		);
		$stmt->execute( [ $foreignKeyValue ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		if( !$row )
		{
			return null;
		}

		return $relatedModel::fromArray( $row );
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

		// Collect all foreign key values
		$foreignKeyValues = [];
		foreach( $models as $model )
		{
			$value = $model->getAttribute( $this->_foreignKey );
			if( $value )
			{
				$foreignKeyValues[] = $value;
			}
		}

		if( empty( $foreignKeyValues ) )
		{
			return;
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for all related models
		$placeholders = implode( ',', array_fill( 0, count( $foreignKeyValues ), '?' ) );
		$stmt = $this->_pdo->prepare(
			"SELECT * FROM {$tableName} WHERE {$this->_ownerKey} IN ({$placeholders})"
		);
		$stmt->execute( $foreignKeyValues );
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		// Index the related models by owner key
		$relatedModels = [];
		foreach( $rows as $row )
		{
			$relatedModels[ $row[ $this->_ownerKey ] ] = $relatedModel::fromArray( $row );
		}

		// Assign the related models to the parent models
		foreach( $models as $model )
		{
			$foreignKeyValue = $model->getAttribute( $this->_foreignKey );
			if( isset( $relatedModels[ $foreignKeyValue ] ) )
			{
				$model->setLoadedRelation(
					$this->_foreignKey,
					$relatedModels[ $foreignKeyValue ]
				);
			}
		}
	}

	/**
	 * Handle dependent cascade when parent is destroyed.
	 *
	 * Note: BelongsTo relationships typically don't use dependent strategies
	 * since the child model doesn't "own" the parent model.
	 *
	 * @param \Neuron\Orm\DependentStrategy $strategy
	 * @return void
	 */
	public function handleDependent( \Neuron\Orm\DependentStrategy $strategy ): void
	{
		// BelongsTo relations don't typically cascade on delete
		// The parent model is owned by another model, not this one
	}
}
