<?php

namespace Neuron\Orm;

use PDO;
use ReflectionClass;
use ReflectionProperty;
use Neuron\Orm\Attributes\{Table, BelongsTo, HasMany, HasOne, BelongsToMany};
use Neuron\Orm\Relations\{BelongsToRelation, HasManyRelation, HasOneRelation, BelongsToManyRelation};
use Neuron\Orm\Exceptions\{ModelException, RelationException};
use Neuron\Orm\Query\QueryBuilder;

/**
 * Base model class with attribute-based relation support.
 *
 * Models extending this class can use PHP 8 attributes to define
 * database relations in a Rails-like manner.
 *
 * @package Neuron\Orm
 */
abstract class Model
{
	/**
	 * Database connection
	 */
	protected static ?PDO $_pdo = null;

	/**
	 * Loaded relations cache
	 */
	protected array $_loadedRelations = [];

	/**
	 * Relation metadata cache
	 */
	protected static array $_relationCache = [];

	/**
	 * Set the PDO connection for all models.
	 *
	 * @param PDO $pdo
	 * @return void
	 */
	public static function setPdo( PDO $pdo ): void
	{
		self::$_pdo = $pdo;
	}

	/**
	 * Get the PDO connection.
	 *
	 * @return PDO
	 * @throws ModelException
	 */
	protected static function getPdo(): PDO
	{
		if( !self::$_pdo )
		{
			throw new ModelException( 'PDO connection not set. Call Model::setPdo() first.' );
		}

		return self::$_pdo;
	}

	/**
	 * Get the table name for this model from the Table attribute.
	 *
	 * @return string
	 * @throws ModelException
	 */
	public static function getTableName(): string
	{
		$reflection = new ReflectionClass( static::class );
		$attributes = $reflection->getAttributes( Table::class );

		if( empty( $attributes ) )
		{
			throw new ModelException(
				sprintf( 'Model %s must have a Table attribute.', static::class )
			);
		}

		$tableAttribute = $attributes[0]->newInstance();
		return $tableAttribute->name;
	}

	/**
	 * Get the primary key column name.
	 *
	 * @return string
	 */
	public static function getPrimaryKey(): string
	{
		$reflection = new ReflectionClass( static::class );
		$attributes = $reflection->getAttributes( Table::class );

		if( empty( $attributes ) )
		{
			return 'id';
		}

		$tableAttribute = $attributes[0]->newInstance();
		return $tableAttribute->primaryKey ?? 'id';
	}

	/**
	 * Get an attribute value from the model.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function getAttribute( string $key ): mixed
	{
		// Try with underscore prefix (snake_case)
		$property = "_{$key}";
		if( property_exists( $this, $property ) )
		{
			$reflection = new ReflectionProperty( $this, $property );
			return $reflection->getValue( $this );
		}

		// Try camelCase conversion (_author_id -> _authorId)
		$camelCaseKey = str_replace( '_', '', lcfirst( ucwords( $key, '_' ) ) );
		$property = "_{$camelCaseKey}";
		if( property_exists( $this, $property ) )
		{
			$reflection = new ReflectionProperty( $this, $property );
			return $reflection->getValue( $this );
		}

		// Try without underscore
		if( property_exists( $this, $key ) )
		{
			$reflection = new ReflectionProperty( $this, $key );
			return $reflection->getValue( $this );
		}

		return null;
	}

	/**
	 * Set an attribute value on the model.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return void
	 */
	public function setAttribute( string $key, mixed $value ): void
	{
		$property = "_{$key}";

		if( property_exists( $this, $property ) )
		{
			// Use reflection to set private properties
			$reflection = new ReflectionProperty( $this, $property );
			$reflection->setValue( $this, $value );
			return;
		}

		// Try without underscore
		if( property_exists( $this, $key ) )
		{
			$reflection = new ReflectionProperty( $this, $key );
			$reflection->setValue( $this, $value );
		}
	}

	/**
	 * Magic method to access relations.
	 *
	 * @param string $name
	 * @return mixed
	 * @throws RelationException
	 */
	public function __get( string $name )
	{
		// Check if it's a loaded relation
		if( isset( $this->_loadedRelations[ $name ] ) )
		{
			return $this->_loadedRelations[ $name ];
		}

		// Check if it's a relation property
		$relationMetadata = $this->getRelationMetadata( $name );

		if( $relationMetadata )
		{
			// Load the relation
			$relation = $this->createRelation( $name, $relationMetadata );
			$this->_loadedRelations[ $name ] = $relation->load();
			return $this->_loadedRelations[ $name ];
		}

		// Try to access the property directly
		$property = "_{$name}";
		if( property_exists( $this, $property ) )
		{
			return $this->$property;
		}

		throw new RelationException(
			sprintf( 'Property or relation %s not found on %s', $name, static::class )
		);
	}

	/**
	 * Get relation metadata for a property.
	 *
	 * @param string $propertyName
	 * @return array|null
	 */
	protected function getRelationMetadata( string $propertyName ): ?array
	{
		$cacheKey = static::class . '::' . $propertyName;

		if( isset( self::$_relationCache[ $cacheKey ] ) )
		{
			return self::$_relationCache[ $cacheKey ];
		}

		$reflection = new ReflectionClass( static::class );

		// Try with underscore prefix first
		$property = "_{$propertyName}";
		if( !$reflection->hasProperty( $property ) )
		{
			// Try without underscore
			$property = $propertyName;
			if( !$reflection->hasProperty( $property ) )
			{
				self::$_relationCache[ $cacheKey ] = null;
				return null;
			}
		}

		$reflectionProperty = $reflection->getProperty( $property );

		// Check for relation attributes
		$attributes = $reflectionProperty->getAttributes();

		foreach( $attributes as $attribute )
		{
			$attributeName = $attribute->getName();
			$instance = $attribute->newInstance();

			$metadata = [
				'type' => null,
				'attribute' => $instance,
				'property' => $propertyName
			];

			if( $instance instanceof BelongsTo )
			{
				$metadata['type'] = 'belongsTo';
			}
			elseif( $instance instanceof HasMany )
			{
				$metadata['type'] = 'hasMany';
			}
			elseif( $instance instanceof HasOne )
			{
				$metadata['type'] = 'hasOne';
			}
			elseif( $instance instanceof BelongsToMany )
			{
				$metadata['type'] = 'belongsToMany';
			}

			if( $metadata['type'] )
			{
				self::$_relationCache[ $cacheKey ] = $metadata;
				return $metadata;
			}
		}

		self::$_relationCache[ $cacheKey ] = null;
		return null;
	}

	/**
	 * Create a relation instance.
	 *
	 * @param string $propertyName
	 * @param array $metadata
	 * @return BelongsToRelation|HasManyRelation|HasOneRelation|BelongsToManyRelation
	 * @throws RelationException
	 */
	protected function createRelation( string $propertyName, array $metadata )
	{
		$pdo = self::getPdo();
		$attribute = $metadata['attribute'];

		return match( $metadata['type'] )
		{
			'belongsTo' => new BelongsToRelation(
				$pdo,
				$this,
				$attribute->relatedModel,
				$attribute->foreignKey ?? $propertyName . '_id',
				$attribute->ownerKey
			),
			'hasMany' => new HasManyRelation(
				$pdo,
				$this,
				$attribute->relatedModel,
				$attribute->foreignKey ?? $this->getForeignKeyName(),
				$attribute->localKey
			),
			'hasOne' => new HasOneRelation(
				$pdo,
				$this,
				$attribute->relatedModel,
				$attribute->foreignKey ?? $this->getForeignKeyName(),
				$attribute->localKey
			),
			'belongsToMany' => new BelongsToManyRelation(
				$pdo,
				$this,
				$attribute->relatedModel,
				$attribute->pivotTable ?? $this->getPivotTableName( $attribute->relatedModel ),
				$attribute->foreignPivotKey ?? $this->getForeignKeyName(),
				$attribute->relatedPivotKey ?? $this->getRelatedForeignKeyName( $attribute->relatedModel ),
				$attribute->parentKey,
				$attribute->relatedKey
			),
			default => throw new RelationException( 'Unknown relation type: ' . $metadata['type'] )
		};
	}

	/**
	 * Get the foreign key name for this model.
	 *
	 * @return string
	 */
	protected function getForeignKeyName(): string
	{
		$className = (new ReflectionClass( $this ))->getShortName();
		return strtolower( $className ) . '_id';
	}

	/**
	 * Get the foreign key name for a related model.
	 *
	 * @param string $relatedModel
	 * @return string
	 */
	protected function getRelatedForeignKeyName( string $relatedModel ): string
	{
		$className = (new ReflectionClass( $relatedModel ))->getShortName();
		return strtolower( $className ) . '_id';
	}

	/**
	 * Get the pivot table name for a many-to-many relationship.
	 *
	 * @param string $relatedModel
	 * @return string
	 */
	protected function getPivotTableName( string $relatedModel ): string
	{
		$models = [
			strtolower( (new ReflectionClass( $this ))->getShortName() ),
			strtolower( (new ReflectionClass( $relatedModel ))->getShortName() )
		];
		sort( $models );
		return implode( '_', $models );
	}

	/**
	 * Set a loaded relation (used by eager loading).
	 *
	 * @param string $name
	 * @param mixed $value
	 * @return void
	 */
	public function setLoadedRelation( string $name, mixed $value ): void
	{
		$this->_loadedRelations[ $name ] = $value;
	}

	/**
	 * Eager load relations.
	 *
	 * @param array $relations Relation names to load
	 * @param array $models Array of models to load relations for
	 * @return void
	 */
	public static function loadRelations( array $relations, array $models ): void
	{
		foreach( $relations as $relationName )
		{
			if( empty( $models ) )
			{
				continue;
			}

			$firstModel = $models[0];
			$metadata = $firstModel->getRelationMetadata( $relationName );

			if( !$metadata )
			{
				continue;
			}

			$relation = $firstModel->createRelation( $relationName, $metadata );
			$relation->eagerLoad( $models );
		}
	}

	/**
	 * Create a new query builder for this model.
	 *
	 * @return QueryBuilder
	 */
	public static function query(): QueryBuilder
	{
		return new QueryBuilder( self::getPdo(), static::class );
	}

	/**
	 * Find a model by primary key.
	 *
	 * @param int $id
	 * @return static|null
	 */
	public static function find( int $id ): ?static
	{
		return static::query()->find( $id );
	}

	/**
	 * Get all models.
	 *
	 * @return array
	 */
	public static function all(): array
	{
		return static::query()->all();
	}

	/**
	 * Start a query with a where clause.
	 *
	 * @param string $column
	 * @param mixed $operator
	 * @param mixed|null $value
	 * @return QueryBuilder
	 */
	public static function where( string $column, mixed $operator, mixed $value = null ): QueryBuilder
	{
		return static::query()->where( $column, $operator, $value );
	}

	/**
	 * Eager load relations.
	 *
	 * @param array|string $relations
	 * @return QueryBuilder
	 */
	public static function with( array|string $relations ): QueryBuilder
	{
		return static::query()->with( $relations );
	}

	/**
	 * Set result limit.
	 *
	 * @param int $limit
	 * @return QueryBuilder
	 */
	public static function limit( int $limit ): QueryBuilder
	{
		return static::query()->limit( $limit );
	}

	/**
	 * Set result offset.
	 *
	 * @param int $offset
	 * @return QueryBuilder
	 */
	public static function offset( int $offset ): QueryBuilder
	{
		return static::query()->offset( $offset );
	}

	/**
	 * Add an order by clause.
	 *
	 * @param string $column
	 * @param string $direction
	 * @return QueryBuilder
	 */
	public static function orderBy( string $column, string $direction = 'ASC' ): QueryBuilder
	{
		return static::query()->orderBy( $column, $direction );
	}

	/**
	 * Create a new model and save it to the database.
	 *
	 * @param array $attributes
	 * @return static
	 * @throws ModelException
	 */
	public static function create( array $attributes ): static
	{
		$instance = static::fromArray( $attributes );
		$instance->save();
		return $instance;
	}

	/**
	 * Save the model to the database (insert or update).
	 *
	 * @return bool
	 * @throws ModelException
	 */
	public function save(): bool
	{
		if( $this->exists() )
		{
			return $this->performUpdate();
		}
		else
		{
			return $this->performInsert();
		}
	}

	/**
	 * Update the model's attributes and save to database.
	 *
	 * @param array $attributes
	 * @return bool
	 * @throws ModelException
	 */
	public function update( array $attributes ): bool
	{
		$this->fill( $attributes );
		return $this->save();
	}

	/**
	 * Fill the model with an array of attributes.
	 *
	 * @param array $attributes
	 * @return self
	 */
	public function fill( array $attributes ): self
	{
		foreach( $attributes as $key => $value )
		{
			$this->setAttribute( $key, $value );
		}

		return $this;
	}

	/**
	 * Check if the model exists in the database.
	 *
	 * @return bool
	 */
	public function exists(): bool
	{
		$primaryKey = static::getPrimaryKey();
		$id = $this->getAttribute( $primaryKey );

		return $id !== null && $id > 0;
	}

	/**
	 * Convert the model to an array.
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		$data = [];
		$reflection = new ReflectionClass( $this );

		foreach( $reflection->getProperties() as $property )
		{
			// Skip static properties
			if( $property->isStatic() )
			{
				continue;
			}

			$propertyName = $property->getName();

			// Skip relations cache and internal properties
			if( $propertyName === '_loadedRelations' || $propertyName === '_relationCache' || !str_starts_with( $propertyName, '_' ) )
			{
				continue;
			}

			// Skip properties with relation attributes
			$hasRelation = false;
			foreach( $property->getAttributes() as $attribute )
			{
				$attrName = $attribute->getName();
				if( in_array( $attrName, [
					BelongsTo::class,
					HasMany::class,
					HasOne::class,
					BelongsToMany::class
				] ) )
				{
					$hasRelation = true;
					break;
				}
			}

			if( $hasRelation )
			{
				continue;
			}

			// Convert property name to snake_case column name
			$columnName = $this->propertyToColumn( $propertyName );
			$value = $property->getValue( $this );

			// Skip null values for auto-increment IDs on insert
			if( $columnName === static::getPrimaryKey() && $value === null )
			{
				continue;
			}

			$data[ $columnName ] = $value;
		}

		return $data;
	}

	/**
	 * Convert property name to database column name.
	 *
	 * @param string $propertyName
	 * @return string
	 */
	protected function propertyToColumn( string $propertyName ): string
	{
		// Remove leading underscore
		$name = ltrim( $propertyName, '_' );

		// Convert camelCase to snake_case
		$name = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $name ) );

		return $name;
	}

	/**
	 * Perform an insert operation.
	 *
	 * @return bool
	 * @throws ModelException
	 */
	protected function performInsert(): bool
	{
		$pdo = self::getPdo();
		$table = static::getTableName();
		$data = $this->toArray();

		$columns = array_keys( $data );
		$placeholders = array_fill( 0, count( $columns ), '?' );

		$sql = sprintf(
			"INSERT INTO %s (%s) VALUES (%s)",
			$table,
			implode( ', ', $columns ),
			implode( ', ', $placeholders )
		);

		$stmt = $pdo->prepare( $sql );
		$result = $stmt->execute( array_values( $data ) );

		if( $result )
		{
			$lastId = $pdo->lastInsertId();
			if( $lastId )
			{
				$this->setAttribute( static::getPrimaryKey(), (int)$lastId );
			}
		}

		return $result;
	}

	/**
	 * Perform an update operation.
	 *
	 * @return bool
	 * @throws ModelException
	 */
	protected function performUpdate(): bool
	{
		$pdo = self::getPdo();
		$table = static::getTableName();
		$primaryKey = static::getPrimaryKey();
		$data = $this->toArray();

		// Remove primary key from data
		$id = $data[ $primaryKey ];
		unset( $data[ $primaryKey ] );

		$setClauses = [];
		foreach( array_keys( $data ) as $column )
		{
			$setClauses[] = "{$column} = ?";
		}

		$sql = sprintf(
			"UPDATE %s SET %s WHERE %s = ?",
			$table,
			implode( ', ', $setClauses ),
			$primaryKey
		);

		$bindings = array_values( $data );
		$bindings[] = $id;

		$stmt = $pdo->prepare( $sql );
		return $stmt->execute( $bindings );
	}

	/**
	 * Delete the model from the database with dependent cascade.
	 *
	 * @return bool
	 * @throws ModelException
	 * @throws RelationException
	 */
	public function destroy(): bool
	{
		if( !$this->exists() )
		{
			return false;
		}

		// Handle dependent relations first
		$this->handleDependentRelations();

		// Delete the model
		$pdo = self::getPdo();
		$table = static::getTableName();
		$primaryKey = static::getPrimaryKey();
		$id = $this->getAttribute( $primaryKey );

		$sql = "DELETE FROM {$table} WHERE {$primaryKey} = ?";
		$stmt = $pdo->prepare( $sql );

		return $stmt->execute( [ $id ] );
	}

	/**
	 * Delete one or more models by ID.
	 *
	 * @param int|array $ids
	 * @return int Number of records deleted
	 * @throws ModelException
	 */
	public static function destroyMany( int|array $ids ): int
	{
		if( !is_array( $ids ) )
		{
			$ids = [ $ids ];
		}

		$count = 0;
		foreach( $ids as $id )
		{
			$model = static::find( $id );
			if( $model && $model->destroy() )
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Delete the model without handling dependents (simple delete).
	 *
	 * @return bool
	 * @throws ModelException
	 */
	public function delete(): bool
	{
		if( !$this->exists() )
		{
			return false;
		}

		$pdo = self::getPdo();
		$table = static::getTableName();
		$primaryKey = static::getPrimaryKey();
		$id = $this->getAttribute( $primaryKey );

		$sql = "DELETE FROM {$table} WHERE {$primaryKey} = ?";
		$stmt = $pdo->prepare( $sql );

		return $stmt->execute( [ $id ] );
	}

	/**
	 * Handle dependent relations before destroying the model.
	 *
	 * @return void
	 * @throws RelationException
	 */
	protected function handleDependentRelations(): void
	{
		$reflection = new ReflectionClass( $this );

		foreach( $reflection->getProperties() as $property )
		{
			$attributes = $property->getAttributes();

			foreach( $attributes as $attribute )
			{
				$instance = $attribute->newInstance();

				// Check if it has a dependent strategy
				if( !property_exists( $instance, 'dependent' ) || !$instance->dependent )
				{
					continue;
				}

				// Get the relation metadata
				$propertyName = ltrim( $property->getName(), '_' );
				$metadata = $this->getRelationMetadata( $propertyName );

				if( !$metadata )
				{
					continue;
				}

				// Create and handle the relation
				$relation = $this->createRelation( $propertyName, $metadata );
				$relation->handleDependent( $instance->dependent );
			}
		}
	}

	/**
	 * Create model instance from array data.
	 * This should be implemented by child classes.
	 *
	 * @param array $data
	 * @return static
	 */
	abstract public static function fromArray( array $data ): static;
}
