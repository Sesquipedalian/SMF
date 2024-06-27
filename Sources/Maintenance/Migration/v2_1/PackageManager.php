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

class PackageManager extends MigrationBase
{
	/*******************
	 * Public properties
	 *******************/

	/**
	 * {@inheritDoc}
	 */
	public string $name = 'Adding new package manager columns';

	/*********************
	 * Internal properties
	 *********************/

	/**
	 *
	 */
	protected array $newColumns = ['credits', 'log_packages'];

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
		$logPackagesTable = new \SMF\Db\Schema\v3_0\LogPackages();

		$existing_columns = Db::$db->list_columns('{db_prefix}' . $logPackagesTable->name);

		foreach ($logPackagesTable->columns as $column) {
			// Column exists, don't need to do this.
			if (in_array($column->name, $this->newColumns) && in_array($column->name, $existing_columns)) {
				continue;
			}

			$logPackagesTable->addColumn($column);
		}

		$this->query('', '
			UPDATE {db_prefix}log_packages
			SET install_state = 0');

		return true;
	}
}

?>