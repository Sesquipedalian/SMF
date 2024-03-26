<?php

/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2024 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 3.0 Alpha 1
 */

declare(strict_types=1);

namespace SMF\Maintenance\Migration\v2_1;

use SMF\Db\DatabaseApi as Db;
use SMF\Maintenance\Migration\MigrationBase;

class CategoryDescrptions extends MigrationBase
{
	/*******************
	 * Public properties
	 *******************/

	/**
	 * {@inheritDoc}
	 */
	public string $name = 'Adding support for category descriptions';

	/****************
	 * Public methods
	 ****************/

	/**
	 * {@inheritDoc}
	 */
	public function isCandidate(): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute(): bool
	{
		$categories_table = new \SMF\Db\Schema\v3_0\Categories();
		$existing_columns = Db::$db->list_columns('{db_prefix}' . $categories_table->name);

		foreach ($categories_table->columns as $column) {
			// Column exists, don't need to do this.
			if ($column->name === 'description' && in_array($column->name, $existing_columns)) {
				continue;
			}

			$column->add('{db_prefix}' . $categories_table->name);
		}

		return true;
	}
}

?>