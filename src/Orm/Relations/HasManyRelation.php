<?php

namespace Neuron\Orm\Relations;

use PDO;
use Neuron\Orm\Model;

/**
 * Implements has-many relationship logic.
 *
 * @package Neuron\Orm\Relations
 */
class HasManyRelation extends Relation
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
	 * Load the related models.
	 *
	 * @return array
	 */
	public function load(): Model|array|null
	{
		// Get the local key value from parent
		$localKeyValue = $this->_parent->getAttribute( $this->_localKey );

		if( !$localKeyValue )
		{
			return [];
		}

		// Get the table name from the related model
		$relatedModel = $this->_relatedModel;
		$tableName = $relatedModel::getTableName();

		// Query for the related models
		$stmt = $this->_pdo->prepare(
			"SELECT * FROM {$tableName} WHERE {$this->_foreignKey} = ?"
		);
		$stmt->execute( [ $localKeyValue ] );
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

		// Group the related models by foreign key
		$relatedModels = [];
		foreach( $rows as $row )
		{
			$foreignKeyValue = $row[ $this->_foreignKey ];
			if( !isset( $relatedModels[ $foreignKeyValue ] ) )
			{
				$relatedModels[ $foreignKeyValue ] = [];
			}
			$relatedModels[ $foreignKeyValue ][] = $relatedModel::fromArray( $row );
		}

		// Assign the related models to the parent models
		foreach( $models as $model )
		{
			$localKeyValue = $model->getAttribute( $this->_localKey );
			$model->setLoadedRelation(
				$this->_foreignKey,
				$relatedModels[ $localKeyValue ] ?? []
			);
		}
	}
}
