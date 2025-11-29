<?php

namespace Neuron\Orm;

/**
 * Defines cascade behavior when a parent model is destroyed.
 *
 * Similar to Rails' dependent option for associations.
 *
 * @package Neuron\Orm
 */
enum DependentStrategy: string
{
	/**
	 * Call destroy() on each associated record.
	 * This triggers any callbacks and cascades further.
	 */
	case Destroy = 'destroy';

	/**
	 * Delete all associated records with a single SQL DELETE query.
	 * More efficient than destroy but skips callbacks.
	 */
	case DeleteAll = 'delete_all';

	/**
	 * Set the foreign key to NULL on associated records.
	 * Requires the foreign key column to allow NULL.
	 */
	case Nullify = 'nullify';

	/**
	 * Raise an exception if any associated records exist.
	 * Prevents deletion when associations are present.
	 */
	case Restrict = 'restrict';
}
