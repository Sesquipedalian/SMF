<?php

/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2023 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 3.0 Alpha 1
 */

declare(strict_types=1);

namespace SMF\Db\Schema\v3_0;

use SMF\Db\Schema\Column;
use SMF\Db\Schema\Indices;
use SMF\Db\Schema\Table;

/**
 * Defines all the properties for a database table.
 */
class LogScheduledTasks extends Table
{
	/*******************
	 * Public properties
	 *******************/

	/**
	 * @var array
	 *
	 * Data used to populate the table during install.
	 */
	public array $initial_data = [];

	/****************
	 * Public methods
	 ****************/

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->name = 'log_scheduled_tasks';

		$this->columns = [
			new Column(
				name: 'id_log',
				type: 'mediumint',
				auto: true,
			),
			new Column(
				name: 'id_task',
				type: 'smallint',
				not_null: true,
				default: 0,
			),
			new Column(
				name: 'time_run',
				type: 'int',
				not_null: true,
				default: 0,
			),
			new Column(
				name: 'time_taken',
				type: 'float',
				not_null: true,
				default: 0,
			),
		];

		$this->indexes = [
			new Indices(
				type: 'primary',
				columns: [
					'id_log',
				],
			),
		];
	}
}

?>