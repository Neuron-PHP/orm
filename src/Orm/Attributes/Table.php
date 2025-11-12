<?php

namespace Neuron\Orm\Attributes;

use Attribute;

/**
 * Specifies the database table name for a model.
 *
 * @package Neuron\Orm\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
	/**
	 * Constructor
	 *
	 * @param string $name The database table name
	 * @param string|null $primaryKey The primary key column name (default: 'id')
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $primaryKey = 'id'
	) {}
}
