<?php

namespace Neuron\Orm\Relations;

use PDO;
use Neuron\Orm\Model;

/**
 * Implements has-one relationship logic.
 *
 * @package Neuron\Orm\Relations
 */
class HasOneRelation extends Relation
{
	private string $_foreignKey;
	private string $_localKey;

	/**
	 * Constructor
	 *
	 * @param PDO $pdo Database connection
	 * @param Model $parent Parent model instance
	 * @param string $relatedModel Related model class name
	 * @param string $foreignKey Foreign key column name on related table
	 * @param string $localKey Local key column name
	 */
	public function __construct(
		PDO $pdo,
		Model $parent,
		string $relatedModel,
		string $foreignKey,
		string $localKey = 'id'
	)
	{
		parent::__construct( $pdo, $parent, $relatedModel );
		$this->_foreignKey = $foreignKey;
		$this->_localKey = $localKey;
	}

	/**
	 * Load the related model.
	 *
	 * @return Model|null
	 */
	public function load(): Model|array|null
	{
		// Get the local key value from parent
		$localKeyValue = $this->_parent->getAttribute( $this->_localKey );

		if( !$localKeyValue )
		{
			return null;
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for the related model
		$stmt = $this->_pdo->prepare(
			"SELECT * FROM {$tableName} WHERE {$this->_foreignKey} = ? LIMIT 1"
		);
		$stmt->execute( [ $localKeyValue ] );
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

		// Collect all local key values
		$localKeyValues = [];
		foreach( $models as $model )
		{
			$value = $model->getAttribute( $this->_localKey );
			if( $value )
			{
				$localKeyValues[] = $value;
			}
		}

		if( empty( $localKeyValues ) )
		{
			return;
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for all related models
		$placeholders = implode( ',', array_fill( 0, count( $localKeyValues ), '?' ) );
		$stmt = $this->_pdo->prepare(
			"SELECT * FROM {$tableName} WHERE {$this->_foreignKey} IN ({$placeholders})"
		);
		$stmt->execute( $localKeyValues );
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		// Index the related models by foreign key
		$relatedModels = [];
		foreach( $rows as $row )
		{
			$relatedModels[ $row[ $this->_foreignKey ] ] = $relatedModel::fromArray( $row );
		}

		// Assign the related models to the parent models
		foreach( $models as $model )
		{
			$localKeyValue = $model->getAttribute( $this->_localKey );
			if( isset( $relatedModels[ $localKeyValue ] ) )
			{
				$model->setLoadedRelation(
					$this->_foreignKey,
					$relatedModels[ $localKeyValue ]
				);
			}
		}
	}

	/**
	 * Handle dependent cascade when parent is destroyed.
	 *
	 * @param \Neuron\Orm\DependentStrategy $strategy
	 * @return void
	 * @throws \Neuron\Orm\Exceptions\RelationException
	 */
	public function handleDependent( \Neuron\Orm\DependentStrategy $strategy ): void
	{
		$localKeyValue = $this->_parent->getAttribute( $this->_localKey );

		if( !$localKeyValue )
		{
			return;
		}

		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		switch( $strategy )
		{
			case \Neuron\Orm\DependentStrategy::Destroy:
				// Load the related record and call destroy() on it
				$related = $this->load();
				if( $related )
				{
					$related->destroy();
				}
				break;

			case \Neuron\Orm\DependentStrategy::DeleteAll:
				// Execute a DELETE query
				$stmt = $this->_pdo->prepare(
					"DELETE FROM {$tableName} WHERE {$this->_foreignKey} = ?"
				);
				$stmt->execute( [ $localKeyValue ] );
				break;

			case \Neuron\Orm\DependentStrategy::Nullify:
				// Set foreign key to NULL
				$stmt = $this->_pdo->prepare(
					"UPDATE {$tableName} SET {$this->_foreignKey} = NULL WHERE {$this->_foreignKey} = ?"
				);
				$stmt->execute( [ $localKeyValue ] );
				break;

			case \Neuron\Orm\DependentStrategy::Restrict:
				// Check if a related record exists
				$stmt = $this->_pdo->prepare(
					"SELECT COUNT(*) as count FROM {$tableName} WHERE {$this->_foreignKey} = ?"
				);
				$stmt->execute( [ $localKeyValue ] );
				$result = $stmt->fetch( PDO::FETCH_ASSOC );

				if( $result['count'] > 0 )
				{
					throw new \Neuron\Orm\Exceptions\RelationException(
						sprintf(
							'Cannot delete %s because it has an associated %s record',
							get_class( $this->_parent ),
							$relatedModel
						)
					);
				}
				break;
		}
	}
}
