<?php

namespace Neuron\Orm\Relations;

use PDO;
use Neuron\Orm\Model;

/**
 * Base class for all relation types.
 *
 * @package Neuron\Orm\Relations
 */
abstract class Relation
{
	protected PDO $_pdo;
	protected Model $_parent;
	protected string $_relatedModel;

	/**
	 * Constructor
	 *
	 * @param PDO $pdo Database connection
	 * @param Model $parent Parent model instance
	 * @param string $relatedModel Related model class name
	 */
	public function __construct( PDO $pdo, Model $parent, string $relatedModel )
	{
		$this->_pdo = $pdo;
		$this->_parent = $parent;
		$this->_relatedModel = $relatedModel;
	}

	/**
	 * Load the relation (lazy loading).
	 *
	 * @return Model|array|null
	 */
	abstract public function load(): Model|array|null;

	/**
	 * Eager load relations for multiple models.
	 *
	 * @param array $models Array of parent models
	 * @return void
	 */
	abstract public function eagerLoad( array $models ): void;

	/**
	 * Get the related model class name.
	 *
	 * @return string
	 */
	public function getRelatedModel(): string
	{
		return $this->_relatedModel;
	}

	/**
	 * Handle dependent cascade when parent is destroyed.
	 *
	 * @param \Neuron\Orm\DependentStrategy $strategy
	 * @return void
	 */
	abstract public function handleDependent( \Neuron\Orm\DependentStrategy $strategy ): void;
}
