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
class PackageServers extends Table
{
	/*******************
	 * Public properties
	 *******************/

	/**
	 * @var array
	 *
	 * Initial columns for inserts.
	 */
	public array $initial_columns = [
		'name' => 'string',
		'url' => 'string',
		'validation_url' => 'string',
	];

	/**
	 * @var array
	 *
	 * Data used to populate the table during install.
	 */
	public array $initial_data = [
		[
			'name' => 'Simple Machines Third-party Mod Site',
			'url' => 'https://custom.simplemachines.org/packages/mods',
			'validation_url' => 'https://custom.simplemachines.org/api.php?action=validate;version=v1;smf_version={SMF_VERSION}',
		],
		[
			'name' => 'Simple Machines Downloads Site',
			'url' => 'https://download.simplemachines.org/browse.php?api=v1;smf_version={SMF_VERSION}',
			'validation_url' => 'https://download.simplemachines.org/validate.php?api=v1;smf_version={SMF_VERSION}',
		],
	];

	/****************
	 * Public methods
	 ****************/

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->name = 'package_servers';

		$this->columns = [
			new Column(
				name: 'id_server',
				type: 'smallint',
				unsigned: true,
				auto: true,
			),
			new Column(
				name: 'name',
				type: 'varchar',
				size: 255,
				not_null: true,
				default: '',
			),
			new Column(
				name: 'url',
				type: 'varchar',
				size: 255,
				not_null: true,
				default: '',
			),
			new Column(
				name: 'validation_url',
				type: 'varchar',
				size: 255,
				not_null: true,
				default: '',
			),
			new Column(
				name: 'extra',
				type: 'text',
			),
		];

		$this->indexes = [
			new Indices(
				type: 'primary',
				columns: [
					'id_server',
				],
			),
		];
	}
}

?>