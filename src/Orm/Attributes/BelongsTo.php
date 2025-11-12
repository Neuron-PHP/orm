<?php

namespace Neuron\Orm\Attributes;

use Attribute;

/**
 * Defines a belongs-to relationship (many-to-one).
 *
 * Example: A Post belongs to a User
 *
 * @package Neuron\Orm\Attributes
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo
{
	/**
	 * Constructor
	 *
	 * @param string $relatedModel The fully qualified class name of the related model
	 * @param string|null $foreignKey The foreign key column name (default: {relation}_id)
	 * @param string|null $ownerKey The owner key column name (default: id)
	 */
	public function __construct(
		public readonly string $relatedModel,
		public readonly ?string $foreignKey = null,
		public readonly ?string $ownerKey = 'id'
	) {}
}
