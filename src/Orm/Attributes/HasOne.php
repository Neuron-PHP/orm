<?php

namespace Neuron\Orm\Attributes;

use Attribute;

/**
 * Defines a has-one relationship (one-to-one).
 *
 * Example: A User has one Profile
 *
 * @package Neuron\Orm\Attributes
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
	/**
	 * Constructor
	 *
	 * @param string $relatedModel The fully qualified class name of the related model
	 * @param string|null $foreignKey The foreign key column name on related table
	 * @param string|null $localKey The local key column name (default: id)
	 */
	public function __construct(
		public readonly string $relatedModel,
		public readonly ?string $foreignKey = null,
		public readonly ?string $localKey = 'id'
	) {}
}
