<?php

namespace Neuron\Orm\Attributes;

use Attribute;

/**
 * Defines a belongs-to-many relationship (many-to-many).
 *
 * Example: A Post belongs to many Categories
 *
 * @package Neuron\Orm\Attributes
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
	/**
	 * Constructor
	 *
	 * @param string $relatedModel The fully qualified class name of the related model
	 * @param string|null $pivotTable The pivot/junction table name
	 * @param string|null $foreignPivotKey The foreign key in pivot table for this model
	 * @param string|null $relatedPivotKey The foreign key in pivot table for related model
	 * @param string|null $parentKey The parent key column name (default: id)
	 * @param string|null $relatedKey The related key column name (default: id)
	 * @param DependentStrategy|null $dependent Cascade behavior when parent is destroyed
	 */
	public function __construct(
		public readonly string $relatedModel,
		public readonly ?string $pivotTable = null,
		public readonly ?string $foreignPivotKey = null,
		public readonly ?string $relatedPivotKey = null,
		public readonly ?string $parentKey = 'id',
		public readonly ?string $relatedKey = 'id',
		public readonly ?\Neuron\Orm\DependentStrategy $dependent = null
	) {}
}
