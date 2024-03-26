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
use SMF\Db\Schema\v3_0\LogGroupRequests;
use SMF\Maintenance\Migration\MigrationBase;

class CreateLogGroupRequests extends MigrationBase
{
	/*******************
	 * Public properties
	 *******************/

	/**
	 * {@inheritDoc}
	 */
	public string $name = 'Adding support for logging who fulfils a group request';

	/*********************
	 * Internal properties
	 *********************/

	/**
	 *
	 */
	protected array $newColumns = ['status', 'id_member_acted', 'member_name_acted', 'time_acted', 'act_reason'];

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
		$existing_columns = Db::$db->list_columns('{db_prefix}log_group_requests');
		$existing_indexes = Db::$db->list_indexes('{db_prefix}log_group_requests');

		$logGroupRequestsTable = new LogGroupRequests();

		foreach ($logGroupRequestsTable->columns as $column) {
			// Column exists, don't need to do this.
			if (in_array($column->name, $this->newColumns) && in_array($column->name, $existing_columns)) {
				continue;
			}

			$column->add('{db_prefix}log_group_requests');
		}

		Db::$db->remove_index('{db_prefix}log_group_requests', 'id_member');

		foreach ($logGroupRequestsTable->indexes as $idx) {
			// Column exists, don't need to do this.
			if ($idx->name == 'idx_id_member' && in_array($idx->name, $existing_indexes)) {
				continue;
			}

			$idx->add('{db_prefix}log_group_requests');
		}

		return true;
	}
}

?>