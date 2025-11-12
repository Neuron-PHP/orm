<?php

namespace Neuron\Orm\Attributes;

use Attribute;

/**
 * Maps a property to a database column.
 *
 * @package Neuron\Orm\Attributes
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
	/**
	 * Constructor
	 *
	 * @param string|null $name The database column name (if different from property name)
	 * @param string|null $type The data type (string, int, float, bool, datetime, json)
	 * @param bool $nullable Whether the column can be null
	 */
	public function __construct(
		public readonly ?string $name = null,
		public readonly ?string $type = null,
		public readonly bool $nullable = false
	) {}
}
