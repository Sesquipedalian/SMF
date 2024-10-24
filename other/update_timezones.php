<?php

/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2024 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1.5
 *
 * This is an internal development file. It should NOT be included in any SMF
 * distribution packages.
 *
 * This file exists to make it easier for devs to update Subs-Timezones.php and
 * Timezone.english.php when a new version of the IANA's time zone database is
 * released.
 *
 * Run this file from the command line in order to perform the update, then
 * review any changes manually before commiting.
 *
 * In particular, review the following:
 *
 * 1. If new $txt or $tztxt strings were added to the language file, check that
 *    they are spelled correctly and make sense.
 *
 * 2. If the TZDB added an entirely new time zone, a new chunk of fallback code
 *    will be added to get_tzid_fallbacks(), with an "ADD INFO HERE" comment
 *    above it.
 *
 *     - Replace "ADD INFO HERE" with something meaningful before commiting,
 *       such as a comment about when the new time zone was added to the TZDB
 *       and which existing time zone it diverged from. This info can be found
 *       at https://data.iana.org/time-zones/tzdb/NEWS.
 *
 * 3. When this script suggests a fallback tzid in the fallback code, it will
 *    insert an "OPTIONS" comment above that suggestion listing other tzids that
 *    could be used instead.
 *
 *     - If you like the automatically suggested tzid, just delete the comment.
 *
 *     - If you prefer one of the other options, change the suggested tzid to
 *       that other option, and then delete the comment.
 *
 *     - All "OPTIONS" comments should be removed before commiting.
 *
 * 4. Newly created time zones are also appended to their country's list in the
 *    get_sorted_tzids_for_country() function.
 *
 *     - Adjust the position of the new tzid in that list by comparing the
 *       city's population with the populations of the other listed cities.
 *       A quick Google or Wikipedia search is your friend here.
 *
 * 5. If a new "meta-zone" is required, new entries for it will be added to
 *    get_tzid_metazones() and to the $tztxt array in the language file.
 *
 *     - The new entry in get_tzid_metazones() will have an "OPTIONS" comment
 *       listing all the tzids in this new meta-zone. Feel free to use any of
 *       them as the representative tzid for the meta-zone. All "OPTIONS"
 *       comments should be removed before commiting.
 *
 *     - Also feel free to edit the $tztxt key for the new meta-zone. Just make
 *       sure to use the same key in both files.
 *
 *     - The value of the $tztxt string in the language file will probably need
 *       to be changed, because only a human can know what it should really be.
 */

define('SMF', 1);
define('SMF_USER_AGENT', 'SMF');

(new TimezoneUpdater())->execute();

/**
 * Updates SMF's time zone data.
 */
class TimezoneUpdater
{
	/*************************************************************************/
	// Settings

	/**
	 * Git tag of the earliest version of the TZDB to check against.
	 *
	 * This can be set to the TZDB version that was included in the earliest
	 * version of PHP that SMF supports, e.g. 2015g (a.k.a. 2015.7) for PHP 7.0.
	 * Leave blank to use the earliest release available.
	 */
	const TZDB_PREV_TAG = '2015g';

	/**
	 * Git tag of the most recent version of the TZDB to check against.
	 * Leave blank to use the latest release of the TZDB.
	 */
	const TZDB_CURR_TAG = '';

	/**
	 * URL where we can get a list of tagged releases of the TZDB.
	 */
	const TZDB_TAGS_URL = 'https://api.github.com/repos/eggert/tz/tags?per_page=1000';

	/**
	 * URL template to fetch raw data files for the TZDB
	 */
	const TZDB_FILE_URL = 'https://raw.githubusercontent.com/eggert/tz/{COMMIT}/{FILE}';

	/**
	 * URL where we can get nice English labels for tzids.
	 */
	const CLDR_TZNAMES_URL = 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-dates-full/main/en/timeZoneNames.json';

	/**
	 * Used in places where an earliest date is required.
	 *
	 * To support 32-bit PHP builds, use '1901-12-13 20:45:52 UTC'
	 */
	const DATE_MIN = '-292277022657-01-27 08:29:52 UTC';

	/**
	 * Used in places where a latest date is required.
	 */
	const DATE_MAX = 'January 1 + 2 years UTC';

	// End of settings
	/*************************************************************************/

	/**
	 * The path to the local SMF repo's working tree.
	 */
	public $boarddir;

	/**
	 * The path to the Sources directory.
	 */
	public $sourcedir;

	/**
	 * The path to the languages directory.
	 */
	public $langdir;

	/**
	 * Git commit hash associated with TZDB_PREV_TAG.
	 */
	public $prev_commit;

	/**
	 * Git commit hash associated with TZDB_CURR_TAG.
	 */
	public $curr_commit;

	/**
	 * This keeps track of whether any files actually changed.
	 */
	public $files_updated = false;

	/**
	 * Tags from the TZDB's GitHub repository.
	 */
	public $tzdb_tags = array();

	/**
	 * A multidimensional array of time zone identifiers,
	 * grouped into different information blocks.
	 */
	public $tz_data = array();

	/**
	 * Compiled information about all time zones in the TZDB.
	 */
	public $zones = array();

	/**
	 * Compiled information about all time zone transitions.
	 *
	 * This is similar to return value of PHP's timezone_transitions_get(),
	 * except that the array is built from the TZDB source as it existed at
	 * whatever version is defined as 'current' via self::TZDB_CURR_TAG.
	 */
	public $transitions;

	/**
	 * Info about any new meta-zones.
	 */
	public $new_metazones = array();

	/****************
	 * Public methods
	 ****************/

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->boarddir = realpath(dirname(__DIR__));
		$this->sourcedir = $this->boarddir . '/Sources';
		$this->langdir = $this->boarddir . '/Themes/default/languages';

		require_once($this->sourcedir . '/Subs.php');

		// Set some globals for the sake of functions in other files.
		$GLOBALS['boarddir'] = $this->boarddir;
		$GLOBALS['sourcedir'] = $this->sourcedir;
		$GLOBALS['langdir'] = $this->langdir;
		$GLOBALS['modSettings'] = array('default_timezone' => 'UTC');
		$GLOBALS['txt'] = array('etc' => 'etc.');
		$GLOBALS['tztxt'] = array();
	}

	/**
	 * Does the job.
	 */
	public function execute()
	{
		$this->fetch_tzdb_updates();
		$this->update_subs_timezones();
		$this->update_timezones_langfile();

		// Changed in unexpected ways?
		if (!empty($this->tz_data['changed']['wtf']))
		{
			echo 'The following time zones changed in unexpected ways. Please review them manually to figure out what to do.' . "\n\t" . implode("\n\t", $this->tz_data['changed']['wtf']) . "\n\n";
		}

		// Say something when finished.
		echo 'Done. ', $this->files_updated ? 'Please review all changes manually.' : 'No changes were made.', "\n";
	}

	/******************
	 * Internal methods
	 ******************/

	/**
	 * Builds an array of information about the time zone identifiers in
	 * two different versions of the TZDB, including information about
	 * what changed between the two.
	 *
	 * The data is saved in $this->tz_data.
	 */
	private function fetch_tzdb_updates(): void
	{
		$fetched = array();

		$this->fetch_tzdb_tags();

		foreach (array('prev', 'curr') as $build)
		{
			if ($build == 'prev')
			{
				$tag = isset($this->tzdb_tags[self::TZDB_PREV_TAG]) ? self::TZDB_PREV_TAG : array_key_first($this->tzdb_tags);
				$this->prev_commit = $this->tzdb_tags[$tag];
			}
			else
			{
				$tag = isset($this->tzdb_tags[self::TZDB_CURR_TAG]) ? self::TZDB_CURR_TAG : array_key_last($this->tzdb_tags);
				$this->curr_commit = $this->tzdb_tags[$tag];
			}

			$backzone_exists = $tag >= '2014g';

			list($fetched['zones'], $fetched['links']) = $this->get_primary_zones($this->tzdb_tags[$tag]);

			$fetched['backward_links'] = $this->get_backlinks($this->tzdb_tags[$tag]);

			list($fetched['backzones'], $fetched['backzone_links']) = $backzone_exists ? $this->get_backzones($this->tzdb_tags[$tag]) : array(array(), array());

			$this->tz_data[$build]['all'] = array_unique(array_merge(
				$fetched['zones'],
				array_keys($fetched['links']),
				array_values($fetched['links']),
				array_keys($fetched['backward_links']),
				array_values($fetched['backward_links']),
				array_keys($fetched['backzone_links']),
				array_values($fetched['backzone_links'])
			));

			$this->tz_data[$build]['links'] = array_merge(
				$fetched['backzone_links'],
				$fetched['backward_links'],
				$fetched['links']
			);

			$this->tz_data[$build]['canonical'] = array_diff(
				$this->tz_data[$build]['all'],
				array_keys($this->tz_data[$build]['links'])
			);

			$this->tz_data[$build]['backward_links'] = $fetched['backward_links'];
			$this->tz_data[$build]['backzones'] = $fetched['backzones'];
			$this->tz_data[$build]['backzone_links'] = $fetched['backzone_links'];
		}

		$this->tz_data['changed'] = array(
			'new' => array_diff($this->tz_data['curr']['all'], $this->tz_data['prev']['all']),
			'renames' => array(),
			'additions' => array(),
			'wtf' => array(),
		);

		// Figure out which new tzids are renames of old tzids.
		foreach ($this->tz_data['changed']['new'] as $tzid)
		{
			// Get any tzids that link to this one.
			$linked_tzids = array_keys($this->tz_data['curr']['links'], $tzid);

			// If this tzid is itself a link, get its target.
			if (isset($this->tz_data['curr']['links'][$tzid]))
				$linked_tzids[] = $this->tz_data['curr']['links'][$tzid];

			// No links, so skip.
			if (empty($linked_tzids))
				continue;

			$linked_tzids = array_unique($linked_tzids);

			// Try filtering out backzones in order to find to one unambiguous link.
			if (count($linked_tzids) > 1)
			{
				$not_backzones = array_diff($linked_tzids, $this->tz_data['curr']['backzones']);

				if (count($not_backzones) !== 1)
				{
					$this->tz_data['changed']['wtf'][] = $tzid;
					continue;
				}

				$linked_tzids = $not_backzones;
			}

			$this->tz_data['changed']['renames'][reset($linked_tzids)] = $tzid;
		}

		$this->tz_data['changed']['additions'] = array_diff(
			$this->tz_data['changed']['new'],
			$this->tz_data['changed']['renames'],
			$this->tz_data['changed']['wtf']
		);
	}

	/**
	 * Updates the contents of SMF's Subs-Timezones.php with any changes
	 * required to reflect changes in the TZDB.
	 *
	 * - Handles renames of tzids (e.g. Europe/Kiev -> Europe/Kyiv)
	 *   fully automatically.
	 *
	 * - If a new tzid has been created, adds fallback code for it in
	 *   get_tzid_fallbacks(), and appends it to the list of tzids for
	 *   its country in get_sorted_tzids_for_country().
	 *
	 * - Checks the rules defined in existing fallback code to make sure
	 *   they are still accurate, and updates any that are not. This is
	 *   necessary because new versions of the TZDB sometimes contain
	 *   corrections to previous data.
	 */
	private function update_subs_timezones(): void
	{
		$file_contents = file_get_contents($this->sourcedir . '/Subs-Timezones.php');

		// Handle any renames.
		foreach ($this->tz_data['changed']['renames'] as $old_tzid => $new_tzid)
		{
			// Rename it in get_tzid_metazones()
			if (!preg_match('~\n\h+\K\'' . $new_tzid . '\'(?=\s+=>\s+\'\w+\',)~', $file_contents))
			{
				$file_contents = preg_replace('~\n\h+\K\'' . $old_tzid . '\'(?=\s+=>\s+\'\w+\',)~', "'$new_tzid'", $file_contents);

				if (preg_match('~\n\h+\K\'' . $new_tzid . '\'(?=\s+=>\s+\'\w+\',)~', $file_contents))
				{
					echo "Renamed $old_tzid to $new_tzid in get_tzid_metazones().\n\n";

					$this->files_updated = true;
				}
			}

			// Rename it in get_sorted_tzids_for_country()
			if (!preg_match('~\n\h+\K\'' . $new_tzid . '\'(?=,\n)~', $file_contents))
			{
				$file_contents = preg_replace('~\n\h+\K\'' . $old_tzid . '\'(?=,\n)~', "'$new_tzid'", $file_contents);

				if (preg_match('~\n\h+\K\'' . $new_tzid . '\'(?=,\n)~', $file_contents))
				{
					echo "Renamed $old_tzid to $new_tzid in get_sorted_tzids_for_country().\n\n";

					$this->files_updated = true;
				}
			}

			// Ensure the fallback code is added.
			$insert_before = '(?=\n\h+// 2. Newly created time zones.)';
			$code = $this->generate_rename_fallback_code(array($old_tzid => $new_tzid));

			$search_for = preg_quote(substr(trim($code), 0, strpos(trim($code), "\n")), '~');
			$search_for = preg_replace('~\s+~', '\s+', $search_for);

			if (!preg_match('~' . $search_for . '~', $file_contents))
			{
				$file_contents = preg_replace('~' . $insert_before . '~', $code, $file_contents);

				if (preg_match('~' . $search_for . '~', $file_contents))
				{
					echo "Added fallback code for $new_tzid in get_tzid_fallbacks().\n\n";

					$this->files_updated = true;
				}
			}
		}

		// Insert fallback code for any additions.
		if (!empty($this->tz_data['changed']['additions']))
		{
			$fallbacks = $this->build_fallbacks();

			foreach ($this->tz_data['changed']['additions'] as $tzid)
			{
				// Ensure it is present in get_sorted_tzids_for_country()
				if (!preg_match('~\n\h+\K\'' . $tzid . '\'(?=,\n)~', $file_contents))
				{
					$cc = $this->get_cc_for_tzid($tzid, $this->curr_commit);

					$file_contents = preg_replace("~('$cc'\s*=>\s*array\((?:\s*'[^']+',)*\n)(\h*)(\),)~", '$1$2' . "\t'$tzid',\n" . '$2$3', $file_contents);

					if (preg_match('~\n\h+\K\'' . $tzid . '\'(?=,\n)~', $file_contents))
					{
						echo "Added $tzid to $cc in get_sorted_tzids_for_country().\n\n";

						$this->files_updated = true;
					}
				}

				// Ensure the fallback code is added.
				$insert_before = '(?=\s+\);\s+\$missing\s+=\s+)';
				$code = $this->generate_full_fallback_code(array($tzid => $fallbacks[$tzid]));

				$search_for = preg_quote(substr(trim($code), 0, strpos(trim($code), "\n")), '~');
				$search_for = preg_replace('~\s+~', '\s+', $search_for);

				// Not present at all.
				if (!preg_match('~' . $search_for . '~', $file_contents))
				{
					$file_contents = preg_replace('~' . $insert_before . '~', "\n\n\t\t// ADD INFO HERE\n" . rtrim($code), $file_contents, 1);

					if (preg_match('~' . $search_for . '~', $file_contents))
					{
						echo "Added fallback code for $tzid in get_tzid_fallbacks().\nACTION NEEDED: Review the fallback code for $tzid.\n\n";

						$this->files_updated = true;
					}
				}
				// Check whether our fallback rules are out of date.
				else
				{
					// First, parse the existing code into usable chunks.
					$search_for = str_replace('array\(', 'array(\((?'.'>[^()]|(?1))*\)),', $search_for);

					preg_match('~' . $search_for . '~', $file_contents, $matches);

					if (empty($matches[1]))
						continue;

					$existing_code = $matches[0];
					$existing_inner = $matches[1];

					preg_match_all('~(?:\h*//[^\n]*\n)*\h*array(\((?'.'>[^()]|(?1))*\)),~', $existing_inner, $matches);
					$existing_entries = $matches[0];

					// Now do the same with the generated code.
					preg_match('~' . $search_for . '~', $code, $matches);
					$new_inner = $matches[1];

					preg_match_all('~(?:\h*//[^\n]*\n)*\h*array(\((?'.'>[^()]|(?1))*\)),~', $new_inner, $matches);
					$new_entries = $matches[0];

					// This is what we will ultimately save.
					$final_entries = array();
					foreach ($new_entries as $new_entry_num => $new_entry)
					{
						if (strpos($new_entry, 'PHP_INT_MIN') !== false)
						{
							$final_entries[] = $new_entry;
							continue;
						}

						preg_match('~strtotime\(\'([^\']*)\'\)~', $new_entry, $m);
						$new_ts = $m[1];

						preg_match('~\'tzid\' => \'([^\']*)\'~', $new_entry, $m);
						$new_alt_tzid = $m[1];

						preg_match('~(//[^\n]*\n\h*)*(?=\'tzid\')~', $new_entry, $m);
						$new_alt_tzid_comment = $m[0];

						foreach ($existing_entries as $existing_entry_num => $existing_entry)
						{
							if (strpos($existing_entry, 'PHP_INT_MIN') !== false)
								continue;

							preg_match('~strtotime\(\'([^\']*)\'\)~', $existing_entry, $m);
							$existing_ts = $m[1];

							preg_match('~\'tzid\' => \'([^\']*)\'~', $existing_entry, $m);
							$existing_alt_tzid = $m[1];

							preg_match('~(//[^\n]*\n\h*)*(?=\'tzid\')~', $existing_entry, $m);
							$existing_alt_tzid_comment = $m[0];

							// Found an entry with the same timestamp.
							if ($existing_ts === $new_ts)
							{
								// Modify the existing entry rather than creating a new one.
								$final_entry = $existing_entry;

								// Do we need to change the tzid?
								if (strpos($new_alt_tzid_comment, $existing_alt_tzid) === false)
								{
									$final_entry = str_replace("'tzid' => '$existing_alt_tzid',", "'tzid' => '$new_alt_tzid',", $final_entry);
								}

								// Add or update the options comment.
								if (strpos($existing_alt_tzid_comment, '// OPTIONS: ') === false)
								{
									// Only insert options comment if we changed the tzid.
									if (strpos($new_alt_tzid_comment, $existing_alt_tzid) === false)
									{
										$final_entry = preg_replace("/'tzid' => '([^']*)',/", $new_alt_tzid_comment . "'tzid' => '$1',", $final_entry);
									}
								}
								else
								{
									$final_entry = preg_replace('~//\s*OPTIONS[^\n]+\n\h*~', $new_alt_tzid_comment, $final_entry);
								}

								$final_entries[] = $final_entry;

								continue 2;
							}
							// No existing entry has the same time stamp, so insert
							// a new entry at the correct position in the code.
							elseif (strtotime($existing_ts) > strtotime($new_ts))
							{
								$final_entries[] = $new_entry;

								continue 2;
							}
						}
					}

					$final_inner = "(\n" . implode("\n", $final_entries) . "\n\t\t)";

					if ($existing_inner !== $final_inner)
					{
						$final_code = str_replace($existing_inner, $final_inner, $existing_code);
						$file_contents = str_replace($existing_code, $final_code, $file_contents);

						$this->files_updated = true;

						echo "Fallback code for $tzid has been updated in get_tzid_fallbacks().\nACTION NEEDED: Review the fallback code for $tzid.\n\n";
					}
				}
			}
		}

		// Save the changes we've made so far.
		file_put_contents($this->sourcedir . '/Subs-Timezones.php', $file_contents);

		// Any new meta-zones to add?
		$file_contents = $this->update_metazones($file_contents);

		// Save the changes again.
		file_put_contents($this->sourcedir . '/Subs-Timezones.php', $file_contents);
	}

	/**
	 * This figures out if we need any new meta-zones. If we do, this (1) populates
	 * $this->new_metazones variable for use in update_language_file(), and
	 * (2) inserts the new meta-zones into $file_contents for Subs-Timezones.php.
	 *
	 * @param string $file_contents String content of Subs-Timezones.php.
	 * @return string Modified copy of $file_contents.
	 */
	private function update_metazones(string $file_contents): string
	{
		include($this->sourcedir . '/Subs-Timezones.php');
		include($this->langdir . '/Timezones.english.php');

		$metazones = get_tzid_metazones();
		$canonical_non_metazones = array_diff($this->tz_data['curr']['canonical'], array_keys($metazones));

		$this->build_zones();

		array_walk(
			$this->zones,
			function(&$zone)
			{
				unset($zone['new']);
			}
		);

		$this->build_timezone_transitions();

		$not_in_a_metazone = array();

		// Check for time zones that aren't covered by any existing metazone.
		// Go one year at a time to avoid false positives on places that simply
		// started or stopped using DST and that are covered by existing metazones
		// both before and after they changed their DST practices.
		for ($year = date_create(self::DATE_MAX . ' - 7 years')->format('Y'); $year <= date_create(self::DATE_MAX)->format('Y'); $year++)
		{
			$start_date = new \DateTimeImmutable($year . '-01-01T00:00:00+0000');
			$end_date = new \DateTimeImmutable(($year + 1) . '-01-01T00:00:00+0000');

			$timezones_when = array_keys(smf_list_timezones($start_date->getTimestamp()));

			$tzones = array();
			$tzones_loose = array();

			$not_in_a_metazone[$year] = array();

			foreach (array_merge(array_keys($metazones), $timezones_when, $canonical_non_metazones) as $tzid)
			{
				if (is_int($tzid))
					continue;

				$tzinfo = array();
				$tzinfo_loose = array();

				foreach ($this->transitions[$tzid] as $transition_num => $transition)
				{
					if ($this->transitions[$tzid][$transition_num]['ts'] > $end_date->getTimestamp())
					{
						continue;
					}

					if (isset($this->transitions[$tzid][$transition_num + 1]) && $this->transitions[$tzid][$transition_num + 1]['ts'] < $start_date->getTimestamp())
					{
						continue;
					}

					$this_transition = $this->transitions[$tzid][$transition_num];

					if ($this_transition['ts'] < $start_date->getTimestamp())
					{
						$this_transition['ts'] = $start_date->getTimestamp();
						$this_transition['time'] = $start_date->format('Y-m-d\TH:i:sO');
					}

					$tzinfo[] = $this_transition;
					$tzinfo_loose[] = array_diff_key($this_transition, array('ts' => 0, 'time' => 0));
				}

				$tzkey = serialize($tzinfo);
				$tzkey_loose = serialize($tzinfo_loose);

				if (!isset($tzones[$tzkey]))
				{
					// Don't bother with a new metazone if two places use all the same tzinfo except the clock switch is at a slightly different time (e.g. America/Moncton vs. America/Halifax in 2005)
					if (isset($tzones_loose[$tzkey_loose]))
					{
						$close_enough = true;
						$close_enough_hours = 3;

						foreach ($tzones_loose[$tzkey_loose] as $tzkey_similar)
						{
							$tzinfo_similar = unserialize($tzkey_similar);

							for ($i = 0; $i < count($tzinfo_similar); $i++)
							{
								$close_enough &= abs($tzinfo_similar[$i]['ts'] - $tzinfo[$i]['ts']) < 3600 * $close_enough_hours;
							}
						}
					}

					if (empty($close_enough) && in_array($tzid, $canonical_non_metazones))
					{
						if (($tzid === 'UTC' || strpos($tzid, '/') !== false) && strpos($tzid, 'Etc/') !== 0 && !in_array($tzid, $timezones_when))
						{
							$not_in_a_metazone[$year][$tzkey][] = $tzid;
						}
					}
					else
					{
						$tzones[$tzkey] = $tzid;
						$tzones_loose[$tzkey_loose][] = $tzkey;
					}
				}
			}

			// More filtering is needed.
			foreach ($not_in_a_metazone[$year] as $tzkey => $tzids)
			{
				// A metazone is not justified if it contains only one tzid.
				if (count($tzids) <= 1)
				{
					unset($not_in_a_metazone[$year][$tzkey]);
					continue;
				}

				// Even if no single existing metazone covers all of this set, maybe a combo of existing metazones do.
				$tzinfo = unserialize($tzkey);

				$tzid = reset($tzids);

				// Build a list of possible fallback zones for this zone.
				$possible_fallback_zones = $this->build_possible_fallback_zones($tzid);

				// Build a preliminary list of fallbacks.
				$fallbacks[$tzid] = array();

				$prev_fallback_tzid = '';
				foreach ($this->zones[$tzid]['entries'] as $entry_num => $entry)
				{
					if ($entry['format'] == '-00')
					{
						$prev_fallback_tzid = '';
						continue;
					}

					foreach ($this->find_fallbacks($possible_fallback_zones, $entry, $tzid, $prev_fallback_tzid, $not_in_a_metazone[$year]) as $fallback)
					{
						$prev_fallback_tzid = $fallback['tzid'];
						$fallbacks[$tzid][] = $fallback;
					}
				}

				$remove_earlier = false;
				for ($i = count($fallbacks[$tzid]) - 1; $i >= 0; $i--)
				{
					if ($fallbacks[$tzid][$i]['tzid'] === '')
						$remove_earlier = true;

					if ($remove_earlier)
					{
						unset($fallbacks[$tzid][$i]);
						continue;
					}

					$date_fallback = new DateTime($fallbacks[$tzid][$i]['ts']);

					if ($date_fallback->getTimestamp() > $end_date->getTimestamp())
						continue;

					if ($date_fallback->getTimestamp() < $start_date->getTimestamp())
					{
						$fallbacks[$tzid][$i]['ts'] = $start_date->format('Y-m-d\TH:i:sO');
						$remove_earlier = true;
					}
				}

				if (array_column($fallbacks[$tzid], 'ts') === array_column($tzinfo, 'time'))
					unset($not_in_a_metazone[$year][$tzkey]);
			}

			// If there's nothing left, move on.
			if (empty($not_in_a_metazone[$year]))
			{
				unset($not_in_a_metazone[$year]);
				continue;
			}
		}

		foreach ($not_in_a_metazone as $year => $possibly_should_become_metazone)
		{
			// Which tzids actually should be grouped into a metazone?
			foreach ($possibly_should_become_metazone as $tzkey => $tzids)
			{
				// If there's only one tzid, it doesn't need a new metazone.
				if (count($tzids) < 2)
					continue;

				// Sort for stability. Use get_sorted_tzids_for_country() data to guess
				// which tzid might be a good representative for the others.
				$sorted_tzids = array();
				foreach ($tzids as $tzid)
				{
					$cc = $this->get_cc_for_tzid($tzid, $this->curr_commit);

					if (isset($sorted_tzids[$cc]))
						continue;

					if (preg_match("~('$cc'\s*=>\s*array\((?:\s*'[^']+',)*\n)(\h*)(\),)~", $file_contents, $matches))
					{
						eval('$sorted_tzids = array_merge($sorted_tzids, array(' . $matches[0] . '));');
					}

					$sorted_tzids[$cc] = array_intersect($sorted_tzids[$cc], $tzids);
				}
				ksort($sorted_tzids);

				$tzids = array();

				foreach ($sorted_tzids as $cc => $cc_tzids)
					$tzids = array_merge($tzids, $cc_tzids);

				// Now that we've sorted, set up the new metazone data.
				$tzid = reset($tzids);

				$this->new_metazones[implode(',', $tzids)] = array(
					'tzid' => $tzid,
					'options' => $tzids,
					'tztxt_key' => str_replace('/', '_', $tzid),
					// This one might change below.
					'uses_dst' => false,
				);
			}
		}

		// Do we need any new metazones?
		if (!empty($this->new_metazones))
		{
			// Any new metazones to create?
			preg_match('/\h*\$tzid_metazones\h*=\h*array\h*\([^)]*\);/', $file_contents, $matches);
			$existing_tzid_metazones_code = $matches[0];

			// Need some more info about this new metazone.
			foreach ($this->new_metazones as &$metazone)
			{
				$tzid = $metazone['tzid'];

				// Does it use DST?
				foreach ($this->transitions[$tzid] as $transition)
				{
					if (!empty($transition['isdst']))
					{
						$metazone['uses_dst'] = true;
						continue 2;
					}
				}

				// Metazones distinguish between North and South America.
				if (strpos($metazone['tztxt_key'], 'America_') === 0)
				{
					// Check the TZDB source file first.
					if ($this->zones[$tzid]['file'] === 'northamerica')
					{
						$metazone['tztxt_key'] = 'North_' . $metazone['tztxt_key'];
					}
					elseif ($this->zones[$tzid]['file'] === 'southamerica')
					{
						$metazone['tztxt_key'] = 'South_' . $metazone['tztxt_key'];
					}
					// If source was one of the backward or backzone files, guess based on latitude and/or country code.
					elseif ($this->zones[$tzid]['latitude'] > 13)
					{
						$metazone['tztxt_key'] = 'North_' . $metazone['tztxt_key'];
					}
					elseif ($this->zones[$tzid]['latitude'] > 7 && in_array($this->get_cc_for_tzid($tzid, $this->curr_commit), array('NI', 'CR', 'PA')))
					{
						$metazone['tztxt_key'] = 'North_' . $metazone['tztxt_key'];
					}
					else
					{
						$metazone['tztxt_key'] = 'South_' . $metazone['tztxt_key'];
					}
				}
			}

			$lines = explode("\n", $existing_tzid_metazones_code);
			$prev_line_number = 0;
			$added = array();
			foreach ($lines as $line_number => $line)
			{
				if (preg_match("~(\h*)'([\w/]+)'\h*=>\h*'\w+',~", $line, $matches))
				{
					$whitespace = $matches[1];
					$line_tzid = $matches[2];

					foreach ($this->new_metazones as $metazone)
					{
						$tzid = $metazone['tzid'];

						if (in_array($tzid, $added))
							continue;

						if ($tzid < $line_tzid)
						{
							$insertion = ($prev_line_number > 0 ? "\n" : '') . "\n" . $whitespace . '// ' . ($metazone['uses_dst'] ? 'Uses DST' : 'No DST');

							if (isset($metazone['options']))
							{
								$insertion .= "\n" . $whitespace . '// OPTIONS: ' . implode(', ', $metazone['options']);
							}

							$insertion .= "\n" . $whitespace . "'$tzid' => '" . $metazone['tztxt_key'] . "',";

							$lines[$prev_line_number] .= $insertion;

							$added[] = $tzid;

							echo "Created new metazone for $tzid in get_tzid_metazones().\n";
							echo "ACTION NEEDED: Review the automatically generated \$tztxt key, '" . $metazone['tztxt_key'] . "'.\n\n";

							$this->files_updated = true;

							if (count($added) === count($this->new_metazones))
								break 2;
						}
					}

					$prev_line_number = $line_number;
				}
			}

			$file_contents = str_replace($existing_tzid_metazones_code, implode("\n", $lines), $file_contents);
		}

		return $file_contents;
	}

	/**
	 * Updates the contents of SMF's Timezones.english.php with any
	 * changes required to reflect changes in the TZDB.
	 *
	 * - Handles renames of tzids (e.g. Europe/Kiev -> Europe/Kyiv)
	 *   fully automatically. For this situation, no further developer
	 *   work should be needed.
	 *
	 * - If a new tzid has been created, adds a new $txt string for it.
	 *   We try to fetch a label from the CLDR project, or generate a
	 *   preliminary label if the CLDR has not yet been updated to
	 *   include the new tzid.
	 *
	 * - Makes sure that $txt['iso3166'] is up to date, just in case a
	 *   new country has come into existence since the last update.
	 */
	private function update_timezones_langfile(): void
	{
		// Perform any renames.
		$file_contents = file_get_contents($this->langdir . '/Timezones.english.php');

		foreach ($this->tz_data['changed']['renames'] as $old_tzid => $new_tzid)
		{
			if (strpos($file_contents, "\$txt['$new_tzid']") === false)
			{
				$file_contents = str_replace("\$txt['$old_tzid']", "\$txt['$new_tzid']", $file_contents);

				if (strpos($file_contents, "\$txt['$new_tzid']") !== false)
				{
					echo "Renamed \$txt['$old_tzid'] to \$txt['$new_tzid'] in Timezones.english.php.\n\n";

					$this->files_updated = true;
				}
			}
		}

		// Get $txt and $tztxt as real variables so that we can work with them.
		eval(substr($file_contents, 5, -2));

		// Add any new metazones.
		if (!empty($this->new_metazones))
		{
			foreach ($this->new_metazones as $metazone)
			{
				if (isset($tztxt[$metazone['tztxt_key']]))
					continue;

				// Get a label from the CLDR.
				list($label) = $this->get_tzid_label($metazone['tzid']);

				$label .= ' %1$s Time';

				$tztxt[$metazone['tztxt_key']] = $label;

				echo "Added \$tztxt['" . $metazone['tztxt_key'] . "'] to Timezones.english.php.\n";
				echo "ACTION NEEDED: Review the metazone label text, '$label'.\n\n";

				$this->files_updated = true;
			}

			// Sort the strings into our preferred order.
			uksort(
				$tztxt,
				function($a, $b)
				{
					$first = array('daylight_saving_time_false', 'daylight_saving_time_true', 'generic_timezone', 'GMT', 'UTC');

					if (in_array($a, $first) && !in_array($b, $first))
						return -1;

					if (!in_array($a, $first) && in_array($b, $first))
						return 1;

					if (in_array($a, $first) && in_array($b, $first))
						return array_search($a, $first) <=> array_search($b, $first);

					return $a <=> $b;
				}
			);
		}

		// Add any new tzids.
		$new_tzids = array_diff($this->tz_data['changed']['additions'], array_keys($txt));

		if (!empty($new_tzids))
		{
			foreach ($new_tzids as $tzid)
			{
				$added_txt_msg = "Added \$txt['$tzid'] to Timezones.english.php.\n";

				// Get a label from the CLDR.
				list($label, $msg) = $this->get_tzid_label($tzid);

				$txt[$tzid] = $label;

				$added_txt_msg .= $msg;

				// If this tzid is a new metazone, use the label for that, too.
				if (isset($this->new_metazones[$tzid]))
					$this->new_metazones[$tzid]['label'] = $label . ' %1$s Time';

				echo $added_txt_msg . "\n";
				$this->files_updated = true;
			}

			ksort($txt);
		}

		// Ensure $txt['iso3166'] is up to date.
		$iso3166_tab = $this->fetch_tzdb_file('iso3166.tab', $this->curr_commit);

		foreach (explode("\n", $iso3166_tab) as $line)
		{
			$line = trim(substr($line, 0, strcspn($line, '#')));

			if (empty($line))
				continue;

			list($cc, $label) = explode("\t", $line);

			$label = strtr($label, array('&' => 'and', 'St ' => 'St. '));

			// Skip if already present.
			if (isset($txt['iso3166'][$cc]))
				continue;

			$txt['iso3166'][$cc] = $label;

			echo "Added \$txt['iso3166']['$cc'] to Timezones.english.php.\n\n";
			$this->files_updated = true;
		}

		ksort($txt['iso3166']);

		// Rebuild the file content.
		$lines = array(
			'<' . '?php',
			current(preg_grep('~^// Version:~', explode("\n", $file_contents))),
			'',
			'global $tztxt;',
			'',
		);

		foreach ($tztxt as $key => $value)
		{
			if ($key === 'daylight_saving_time_false')
			{
				$lines[] = '// Standard Time or Daylight Saving Time';
			}
			elseif ($key === 'generic_timezone')
			{
				$lines[] = '';
				$lines[] = '// Labels for "meta-zones"';
			}

			$value = addcslashes($value, "'");

			$lines[] = "\$tztxt['$key'] = '$value';";
		}

		$lines[] = '';
		$lines[] = '// Location names.';

		foreach ($txt as $key => $value)
		{
			if ($key === 'iso3166')
				continue;

			$value = addcslashes($value, "'");

			$lines[] = "\$txt['$key'] = '$value';";
		}

		$lines[] = '';
		$lines[] = '// Countries';

		foreach ($txt['iso3166'] as $key => $value)
		{
			$value = addcslashes($value, "'");

			$lines[] = "\$txt['iso3166']['$key'] = '$value';";
		}

		$lines[] = '';
		$lines[] = '?>';

		// Save the changes.
		file_put_contents($this->langdir . '/Timezones.english.php', implode("\n", $lines));
	}

	/**
	 * Returns a list of Git tags and the associated commit hashes for
	 * each release of the TZDB available on GitHub.
	 */
	private function fetch_tzdb_tags(): void
	{
		foreach (json_decode(fetch_web_data(self::TZDB_TAGS_URL), true) as $tag)
			$this->tzdb_tags[$tag['name']] = $tag['commit']['sha'];

		ksort($this->tzdb_tags);
	}

	/**
	 * Builds an array of canonical and linked time zone identifiers.
	 *
	 * Canoncial tzids are a simple list, while linked tzids are given
	 * as 'link' => 'target' key-value pairs, where 'target' is a
	 * canonical tzid and 'link' is a compatibility tzid that uses the
	 * same time zone rules as its canonical target.
	 *
	 * @param string $commit Git commit hash of a specific TZDB version.
	 * @return array Canonical and linked time zone identifiers.
	 */
	private function get_primary_zones(string $commit = 'main'): array
	{
		$canonical = array();
		$links = array();

		$filenames = array(
			'africa',
			'antarctica',
			'asia',
			'australasia',
			'etcetera',
			'europe',
			// 'factory',
			'northamerica',
			'southamerica',
		);

		foreach ($filenames as $filename)
		{
			$file_contents = $this->fetch_tzdb_file($filename, $commit);

			foreach (explode("\n", $file_contents) as $line)
			{
				$line = trim(substr($line, 0, strcspn($line, '#')));

				if (strpos($line, 'Zone') !== 0 && strpos($line, 'Link') !== 0)
					continue;

				$parts = array_values(array_filter(preg_split("~\h+~", $line)));

				if ($parts[0] === 'Zone')
				{
					$canonical[] = $parts[1];
				}
				elseif ($parts[0] === 'Link')
				{
					$links[$parts[2]] = $parts[1];
				}
			}
		}

		return array($canonical, $links);
	}

	/**
	 * Builds an array of backward compatibility time zone identifiers.
	 *
	 * These supplement the linked tzids supplied by get_primary_zones()
	 * and are formatted the same way (i.e. 'link' => 'target')
	 *
	 * @param string $commit Git commit hash of a specific TZDB version.
	 * @return array Linked time zone identifiers.
	 */
	private function get_backlinks(string $commit): array
	{
		$backlinks = array();

		$file_contents = $this->fetch_tzdb_file('backward', $commit);

		foreach (explode("\n", $file_contents) as $line)
		{
			$line = trim(substr($line, 0, strcspn($line, '#')));

			if (strpos($line, "Link") !== 0)
				continue;

			$parts = array_values(array_filter(preg_split("~\h+~", $line)));

			if (!isset($backlinks[$parts[2]]))
				$backlinks[$parts[2]] = array();

			$backlinks[$parts[2]] = $parts[1];
		}

		return $backlinks;
	}

	/**
	 * Similar to get_primary_zones() in all respects, except that it
	 * returns the pre-1970 data contained in the TZDB's backzone file
	 * rather than the main data files.
	 *
	 * @param string $commit Git commit hash of a specific TZDB version.
	 * @return array Canonical and linked time zone identifiers.
	 */
	private function get_backzones(string $commit): array
	{
		$backzones = array();
		$backzone_links = array();

		$file_contents = $this->fetch_tzdb_file('backzone', $commit);

		foreach (explode("\n", $file_contents) as $line)
		{
			$line = str_replace('#PACKRATLIST zone.tab ', '', $line);

			$line = trim(substr($line, 0, strcspn($line, '#')));

			if (strpos($line, "Zone") === 0)
			{
				$parts = array_values(array_filter(preg_split("~\h+~", $line)));
				$backzones[] = $parts[1];
			}
			elseif (strpos($line, "Link") === 0)
			{
				$parts = array_values(array_filter(preg_split("~\h+~", $line)));
				$backzone_links[$parts[2]] = $parts[1];
			}
		}

		$backzones = array_unique($backzones);
		$backzone_links = array_unique($backzone_links);

		return array($backzones, $backzone_links);
	}

	/**
	 * Simply fetches the full contents of a file for the specified
	 * version of the TZDB.
	 *
	 * @param string $filename File name.
	 * @param string $commit Git commit hash of a specific TZDB version.
	 * @return string The content of the file.
	 */
	private function fetch_tzdb_file(string $filename, string $commit): string
	{
		 static $files;

		 if (empty($files[$commit]))
		 	$files[$commit] = array();

		 if (empty($files[$commit][$filename]))
		 {
		 	$files[$commit][$filename] = fetch_web_data(strtr(self::TZDB_FILE_URL, array('{COMMIT}' => $commit, '{FILE}' => $filename)));
		 }

		 return $files[$commit][$filename];
	}

	/**
	 * Gets the ISO-3166 country code for a time zone identifier as
	 * defined in the specified version of the TZDB.
	 *
	 * @param string $tzid A time zone identifier string.
	 * @param string $commit Git commit hash of a specific TZDB version.
	 * @return string A two-character country code, or '??' if not found.
	 */
	private function get_cc_for_tzid(string $tzid, string $commit): string
	{
		preg_match('~^(\w\w)\h+[+\-\d]+\h+' . $tzid . '~m', $this->fetch_tzdb_file('zone.tab', $commit), $matches);

		return isset($matches[1]) ? $matches[1] : '??';
	}

	/**
	 * Returns a nice English label for the given time zone identifier.
	 *
	 * @param string $tzid A time zone identifier.
	 * @return array The label text, and possibly an "ACTION NEEDED" message.
	 */
	private function get_tzid_label(string $tzid): array
	{
		static $cldr_json;

		if (empty($cldr_json))
			$cldr_json = json_decode(fetch_web_data(self::CLDR_TZNAMES_URL), true);

		$sub_array = $cldr_json['main']['en']['dates']['timeZoneNames']['zone'];

		$tzid_parts = explode('/', $tzid);

		foreach ($tzid_parts as $part)
		{
			if (!isset($sub_array[$part]))
			{
				$sub_array = array('exemplarCity' => false);
				break;
			}

			$sub_array = $sub_array[$part];
		}

		$label = $sub_array['exemplarCity'];
		$msg = '';

		// If tzid is not yet in the CLDR, make a preliminary label for now.
		if ($label === false)
		{
			$label = str_replace(array('St_', '_'), array('St. ', ' '), substr($tzid, strrpos($tzid, '/') + 1));

			$msg = "ACTION NEEDED: Check that the label is spelled correctly, etc.\n";
		}

		return array($label, $msg);
	}

	/**
	 * Builds fallback information for new time zones.
	 *
	 * @return array Fallback info for the new time zones.
	 */
	private function build_fallbacks(): array
	{
		$date_min = new \DateTime(self::DATE_MIN);

		$this->build_zones();

		// See if we can find suitable fallbacks for each newly added zone.
		$fallbacks = array();
		foreach ($this->tz_data['changed']['additions'] as $tzid)
		{
			// Build a list of possible fallback zones for this zone.
			$possible_fallback_zones = $this->build_possible_fallback_zones($tzid);

			// Build a preliminary list of fallbacks.
			$fallbacks[$tzid] = array();

			$prev_fallback_tzid = '';
			foreach ($this->zones[$tzid]['entries'] as $entry_num => $entry)
			{
				if ($entry['format'] == '-00')
				{
					$fallbacks[$tzid][] = array(
						'ts' => 'PHP_INT_MIN',
						'tzid' => '',
					);

					$prev_fallback_tzid = '';

					continue;
				}

				foreach ($this->find_fallbacks($possible_fallback_zones, $entry, $tzid, $prev_fallback_tzid, $this->tz_data['changed']['new']) as $fallback)
				{
					$prev_fallback_tzid = $fallback['tzid'];
					$fallbacks[$tzid][] = $fallback;
				}
			}

			// Walk through the preliminary list and amalgamate any we can.
			// Go in reverse order, because things tend to work out better that way.
			$remove_earlier = false;
			for ($i = count($fallbacks[$tzid]) - 1; $i > 0; $i--)
			{
				if ($fallbacks[$tzid][$i]['tzid'] === '')
					$remove_earlier = true;

				if ($fallbacks[$tzid][$i]['ts'] === 'PHP_INT_MIN')
				{
					if (empty($fallbacks[$tzid][$i - 1]['tzid']))
						$fallbacks[$tzid][$i - 1]['tzid'] = $fallbacks[$tzid][$i]['tzid'];

					$remove_earlier = true;
				}

				if ($remove_earlier)
				{
					unset($fallbacks[$tzid][$i]);
					continue;
				}

				// If there are no options available, we can do nothing more.
				if (empty($fallbacks[$tzid][$i]['options']) || empty($fallbacks[$tzid][$i - 1]['options']))
				{
					continue;
				}

				// Which options work for both the current and previous entry?
				$shared_options = array_intersect(
					$fallbacks[$tzid][$i]['options'],
					$fallbacks[$tzid][$i - 1]['options']
				);

				// No shared options means we can't amalgamate these entries.
				if (empty($shared_options))
					continue;

				// We don't want canonical tzids unless absolutely necessary.
				$temp = $shared_options;
				foreach ($temp as $option)
				{
					if (isset($this->zones[$option]['canonical']))
					{
						// Filter out the canonical tzid.
						$shared_options = array_filter(
							$shared_options,
							function ($tzid) use ($option)
							{
								return $tzid !== $this->zones[$option]['canonical'];
							}
						);

						// If top choice is the canonical tzid, replace it with the link.
						// This check is probably redundant, but it doesn't hurt.
						if ($fallbacks[$tzid][$i]['tzid'] === $this->zones[$option]['canonical'])
							$fallbacks[$tzid][$i]['tzid'] = $option;

						if ($fallbacks[$tzid][$i - 1]['tzid'] === $this->zones[$option]['canonical'])
							$fallbacks[$tzid][$i - 1]['tzid'] = $option;
					}
				}

				// If the previous entry's top choice isn't in the list of shared options,
				// change it to one that is.
				if (!empty($shared_options) && !in_array($fallbacks[$tzid][$i - 1]['tzid'], $shared_options))
				{
					$fallbacks[$tzid][$i - 1]['tzid'] = reset($shared_options);
				}

				// Reduce the options for the previous entry down to only those that are
				// in the current list of shared options.
				$fallbacks[$tzid][$i - 1]['options'] = $shared_options;

				// We no longer need this one.
				unset($fallbacks[$tzid][$i]);
			}
		}

		return $fallbacks;
	}

	/**
	 * Finds a viable fallback for an entry in a time zone's list of
	 * transition rule changes. In some cases, the returned value will
	 * consist of a series of fallbacks for different times during the
	 * overall period of the entry.
	 *
	 * @param array $pfzs Array returned from build_possible_fallback_zones()
	 * @param array $entry An element from $this->zones[$tzid]['entries']
	 * @param string $tzid A time zone identifier
	 * @param string $prev_fallback_tzid A time zone identifier
	 * @param array $skip_tzids Tzids that should not be used as fallbacks.
	 * @return array Fallback data for the entry.
	 */
	private function find_fallbacks(array $pfzs, array $entry, string $tzid, string $prev_fallback_tzid, array $skip_tzids): array
	{
		static $depth = 0;

		$fallbacks = array();

		unset($entry['from'], $entry['from_suffix'], $entry['until'], $entry['until_suffix']);

		$entry_id = md5(json_encode($entry));

		$date_min = new \DateTime(self::DATE_MIN);
		$ts_min = $date_min->format('Y-m-d\TH:i:sO');

		$date_from = new \DateTime($entry['from_utc']);
		$date_until = new \DateTime($entry['until_utc']);

		// Our first test should be the zone we used for the last one.
		// This helps reduce unnecessary switching between zones.
		$ordered_pfzs = $pfzs;
		if (!empty($prev_fallback_tzid) && isset($pfzs[$prev_fallback_tzid]))
		{
			$prev_fallback_zone = $ordered_pfzs[$prev_fallback_tzid];

			unset($ordered_pfzs[$prev_fallback_tzid]);

			$ordered_pfzs = array_merge(array($prev_fallback_zone), $ordered_pfzs);
		}

		$fallback_found = false;
		$earliest_fallback_timestamp = strtotime('now');

		$i = 0;
		while (!$fallback_found && $i < 50)
		{
			foreach ($ordered_pfzs as $pfz)
			{
				if (in_array($pfz['tzid'], $skip_tzids))
					continue;

				if (isset($fallbacks[$entry_id]['options']) && in_array($pfz['tzid'], $fallbacks[$entry_id]['options']))
				{
					continue;
				}

				foreach ($pfz['entries'] as $pfz_entry_num => $pfz_entry)
				{
					$pfz_date_from = new \DateTime($pfz_entry['from_utc']);
					$pfz_date_until = new \DateTime($pfz_entry['until_utc']);

					// Offset and rules must match.
					if ($entry['stdoff'] !== $pfz_entry['stdoff'])
						continue;

					if ($entry['rules'] !== $pfz_entry['rules'])
						continue;

					// Before the start of our range, so move on to the next entry.
					if ($date_from->getTimestamp() >= $pfz_date_until->getTimestamp())
						continue;

					// After the end of our range, so move on to the next possible fallback zone.
					if ($date_from->getTimestamp() < $pfz_date_from->getTimestamp())
					{
						// Remember this in case we need to try again for transitions away from LMT.
						$earliest_fallback_timestamp = min($earliest_fallback_timestamp, $pfz_date_from->getTimestamp());

						continue 2;
					}

					// If this possible fallback ends before our existing options, skip it.
					if (!empty($fallbacks[$entry_id]) && $pfz_date_until->getTimestamp() < $fallbacks[$entry_id]['end'])
					{
						continue;
					}

					// At this point, we know we've found one.
					$fallback_found = true;

					// If there is no fallback for this entry yet, create one.
					if (empty($fallbacks[$entry_id]))
					{
						$fallbacks[$entry_id] = array(
							'ts' => $date_from->format('Y-m-d\TH:i:sO'),
							'end' => min($date_until->getTimestamp(), $pfz_date_until->getTimestamp()),
							'tzid' => $pfz['tzid'],
							'options' => array(),
						);
					}

					// Append to the list of options.
					$fallbacks[$entry_id]['options'][] = $pfz['tzid'];

					if (isset($pfz['canonical']))
						$fallbacks[$entry_id]['options'][] = $pfz['canonical'];

					if (isset($pfz['links']))
					{
						$fallbacks[$entry_id]['options'] = array_merge($fallbacks[$entry_id]['options'], $pfz['links']);
					}

					// Only a partial overlap.
					if ($date_until->getTimestamp() > $pfz_date_until->getTimestamp() && $depth < 10)
					{
						$depth++;

						$partial_entry = $entry;
						$partial_entry['from_utc'] = $pfz_date_until->format('c');

						$fallbacks = array_merge($fallbacks, $this->find_fallbacks($pfzs, $partial_entry, $tzid, $pfz['tzid'], $skip_tzids));

						$depth--;
					}

					break;
				}
			}

			if (!$fallback_found)
			{
				// If possible, move the timestamp forward and try again.
				if ($date_from->format('Y-m-d\TH:i:sO') !== $ts_min && $date_from->getTimestamp() < $earliest_fallback_timestamp)
				{
					$fallbacks[] = array(
						'ts' => $date_from->format('Y-m-d\TH:i:sO'),
						'tzid' => '',
						'options' => array(),
					);

					$prev_fallback_tzid = '';

					$date_from->setTimestamp($earliest_fallback_timestamp);
				}
				// We've run out of options.
				else
				{
					$fallbacks[$entry_id] = array(
						'ts' => $date_from->format('Y-m-d\TH:i:sO'),
						'tzid' => '',
						'options' => array(),
					);

					$fallback_found = true;
				}
			}
		}

		foreach ($fallbacks as &$fallback)
		{
			$fallback['options'] = array_unique($fallback['options']);

			if ($fallback['ts'] <= $ts_min)
				$fallback['ts'] = 'PHP_INT_MIN';
		}

		return $fallbacks;
	}

	/**
	 * Compiles information about all time zones in the TZDB, including
	 * transitions, location data, what other zones it links to or that
	 * link to it, and whether it is new (where "new" means not present
	 * in the earliest version of the TZDB that we are considering).
	 */
	private function build_zones(): void
	{
		if (!empty($this->zones))
			return;

		$date_min = new \DateTime(self::DATE_MIN);
		$date_max = new \DateTime(self::DATE_MAX);

		$links = array();

		$filenames = array(
			'africa',
			'antarctica',
			'asia',
			'australasia',
			'etcetera',
			'europe',
			// 'factory',
			'northamerica',
			'southamerica',
			'backward',
			'backzone',
		);

		// Populate $this->zones with TZDB data.
		foreach ($filenames as $filename)
		{
			$tzid = '';

			foreach (explode("\n", $this->fetch_tzdb_file($filename, $this->curr_commit)) as $line_num => $line)
			{
				$line = rtrim(substr($line, 0, strcspn($line, '#')));

				if ($line === '')
					continue;

				// Line starts a new zone record.
				if (preg_match('/^Zone\h+(\w+(\/[\w+\-]+)*)/', $line, $matches))
				{
					$tzid = $matches[1];
				}
				// Line provides a link.
				elseif (strpos($line, 'Link') === 0)
				{
					// No longer in a zone record.
					$tzid = '';

					$parts = array_values(array_filter(preg_split("~\h+~", $line)));
					$links[$parts[2]] = $parts[1];
				}
				// Line provides a rule.
				elseif (strpos($line, 'Rule') === 0)
				{
					// No longer in a zone record.
					$tzid = '';
				}
				// Line is not a continuation of the current zone record.
				elseif (!empty($tzid) && !preg_match('/^\h+([+\-]?\d{1,2}:\d{2}|0\h+)/', $line))
				{
					$tzid = '';
				}

				// If in a zone record, do stuff.
				if (!empty($tzid))
				{
					$data = trim(preg_replace('/^Zone\h+\w+(\/[\w+\-]+)*\h+/', '', $line));

					$parts = array_combine(
						array('stdoff', 'rules', 'format', 'until'),
						array_pad(preg_split("~\h+~", $data, 4), 4, '')
					);

					if (strpos($parts['stdoff'], ':') === false)
						$parts['stdoff'] .= ':00';

					$this->zones[$tzid]['entries'][] = $parts;

					$this->zones[$tzid]['file'] = $filename;
				}
			}
		}

		// Add a 'from' date to every entry of every zone.
		foreach ($this->zones as $tzid => &$record)
		{
			$record['tzid'] = $tzid;

			foreach ($record['entries'] as $entry_num => &$entry)
			{
				// Until is when the current entry ends.
				if (empty($entry['until']))
				{
					$entry['until'] = $date_max->format('Y-m-d\TH:i:s');
					$entry['until_suffix'] = 'u';
				}
				else
				{
					// Rewrite date into PHP-parseable format.
					$entry['until'] = $this->rewrite_date_string($entry['until']);

					// Find the suffix. Determines which zone the until timestamp is in.
					preg_match('/\d+:\d+(|[wsugz])$/', $entry['until'], $matches);

					// Now set the until values.
					if (!empty($matches[1]))
					{
						$entry['until_suffix'] = $matches[1];

						$entry['until'] = substr($entry['until'], 0, strrpos($entry['until'], $entry['until_suffix']));
					}
					else
					{
						$entry['until_suffix'] = '';
					}

					$entry['until'] = date_format(new \DateTime($entry['until']), 'Y-m-d\TH:i:s');
				}

				// From is just a copy of the previous entry's until.
				if ($entry_num === 0)
				{
					$entry['from'] = $date_min->format('Y-m-d\TH:i:s');
					$entry['from_suffix'] = 'u';
				}
				else
				{
					$entry['from'] = $record['entries'][$entry_num - 1]['until'];
					$entry['from_suffix'] = $record['entries'][$entry_num - 1]['until_suffix'];
				}
			}
		}

		// Set coordinates and country codes for each zone.
		foreach (explode("\n", $this->fetch_tzdb_file('zone.tab', $this->curr_commit)) as $line_num => $line)
		{
			$line = rtrim(substr($line, 0, strcspn($line, '#')));

			if ($line === '')
				continue;

			$parts = array_combine(
				array('country_code', 'coordinates', 'tzid', 'comments'),
				array_pad(preg_split("~\h~", $line, 4), 4, '')
			);

			if (!isset($this->zones[$parts['tzid']]))
				continue;

			$this->zones[$parts['tzid']]['country_code'] = $parts['country_code'];

			list($latitude, $longitude) = preg_split('/\b(?=[+\-])/', $parts['coordinates']);

			foreach (array('latitude', 'longitude') as $varname)
			{
				$deg_len = $varname === 'latitude' ? 3 : 4;

				$deg = substr($$varname, 0, $deg_len);
				$min = substr($$varname, $deg_len, 2);
				$sec = substr($$varname, $deg_len + 2);
				$frac = (int) $min / 60 + (int) $sec / 3600;

				$this->zones[$parts['tzid']][$varname] = (float) $deg + $frac;
			}
		}

		// Ensure all zones have coordinates.
		foreach ($this->zones as $tzid => &$record)
		{
			// The vast majority of zones.
			if (isset($record['longitude']))
				continue;

			// Etc/* can be given fake coordinates.
			if (count($record['entries']) === 1)
			{
				$this->zones[$tzid]['latitude'] = 0;
				$this->zones[$tzid]['longitude'] = (int) ($record['entries'][0]['stdoff']) * 15;
			}

			// Still nothing? Must be a backzone that isn't in zone.tab.
			// As of version 2022d, only case is Asia/Hanoi.
			if (!isset($record['longitude']))
				unset($this->zones[$tzid]);
		}

		// From this point forward, handle links like canonical zones.
		foreach ($links as $link_name => $target)
		{
			// Links can point to other links. We want the true canonical.
			while (isset($links[$target]))
				$target = $links[$target];

			if (!isset($this->zones[$link_name]))
			{
				$this->zones[$link_name] = $this->zones[$target];
				$this->zones[$link_name]['tzid'] = $link_name;
				unset($this->zones[$link_name]['links']);
			}

			$this->zones[$link_name]['canonical'] = $target;
			$this->zones[$target]['links'][] = $link_name;

			$this->zones[$target]['links'] = array_unique($this->zones[$target]['links']);
		}

		// Mark new zones as such.
		foreach ($this->tz_data['changed']['new'] as $tzid)
		{
			$this->zones[$tzid]['new'] = true;
		}

		// Set UTC versions of every entry's 'from' and 'until' dates.
		$this->build_timezone_transitions(true);
	}

	/**
	 * Populates $this->transitions with time zone transition information
	 * similar to PHP's timezone_transitions_get(), except that the array
	 * is built from the TZDB source as it existed at whatever version is
	 * defined as 'current' via self::TZDB_CURR_TAG & $this->curr_commit.
	 *
	 * Also updates the entries for every tzid in $this->zones with
	 * unambigous UTC timestamps for their start and end values.
	 *
	 * @param bool $rebuild If true, force a rebuild.
	 */
	private function build_timezone_transitions(bool $rebuild = false): void
	{
		static $zones_hash = '';

		if (md5(json_encode($this->zones)) !== $zones_hash)
			$rebuild = true;

		$zones_hash = md5(json_encode($this->zones));

		if (!empty($this->transitions) && !$rebuild)
			return;

		$utc = new \DateTimeZone('UTC');
		$date_min = new \DateTime(self::DATE_MIN);
		$date_max = new \DateTime(self::DATE_MAX);

		foreach ($this->zones as $tzid => &$zone)
		{
			// Shouldn't happen, but just in case...
			if (empty($zone['entries']))
				continue;

			$this->transitions[$tzid] = array();

			$zero = 0;
			$prev_offset = 0;
			$prev_std_offset = 0;
			$prev_save = 0;
			$prev_isdst = false;
			$prev_abbr = '';
			$prev_rules = '-';

			foreach ($zone['entries'] as $entry_num => $entry)
			{
				// Determine the standard time offset for this entry.
				$stdoff_parts = array_map('intval', explode(':', $entry['stdoff']));
				$stdoff_parts = array_pad($stdoff_parts, 3, 0);
				$std_offset = abs($stdoff_parts[0]) * 3600 + $stdoff_parts[1] * 60 + $stdoff_parts[2];

				if (substr($entry['stdoff'], 0, 1) === '-')
					$std_offset *= -1;

				// Entries never have gaps, so the end of one is the start of the next.
				$entry_start = new \DateTime($entry['from'], $utc);
				$entry_end = new \DateTime($entry['until'], $utc);

				$unadjusted_date_strings = array(
					'entry_start' => $entry_start->format('Y-m-d\TH:i:s'),
					'entry_end' => $entry_end->format('Y-m-d\TH:i:s'),
				);

				switch ($entry['from_suffix'])
				{
					case 'u':
					case 'g':
					case 'z':
						break;

					case 's':
						$entry_start->setTimestamp($entry_start->getTimestamp() - $prev_std_offset);
						break;

					default:
						$entry_start->setTimestamp($entry_start->getTimestamp() - $prev_offset);
						break;
				}

				switch ($entry['until_suffix'])
				{
					case 'u':
					case 'g':
					case 'z':
						$entry_end_offset_var = 'zero';
						break;

					case 's':
						$entry_end_offset_var = 'prev_std_offset';
						break;

					default:
						$entry_end_offset_var = 'prev_offset';
						break;
				}

				// For convenience elsewhere, provide UTC timestamps for the entry boundaries.
				$zone['entries'][$entry_num]['from_utc'] = $entry_start->format('Y-m-d\TH:i:sO');

				if (isset($zone['entries'][$entry_num - 1]))
				{
					$zone['entries'][$entry_num - 1]['until_utc'] = $entry_start->format('Y-m-d\TH:i:sO');
				}


				// No DST rules.
				if ($entry['rules'] == '-')
				{
					$ts = $entry_start->getTimestamp();
					$time = $entry_start->format('Y-m-d\TH:i:sO');
					$offset = $std_offset;
					$isdst = false;
					$abbr = $entry['format'] === '%z' ? sprintf("%+03d", strtr($offset, [':00' => '', ':' => ''])) : sprintf($entry['format'], 'S');
					$save = 0;
					$unadjusted_date_string = $unadjusted_date_strings['entry_start'];

					// Some abbr values use '+00/+01' instead of sprintf formats.
					if (strpos($abbr, '/') !== false)
						$abbr = substr($abbr, 0, strpos($abbr, '/'));

					// Skip if these values are identical to the previous values.
					// ... with an exception for Europe/Lisbon, which is a special snowflake.
					if ($offset === $prev_offset && $isdst === $prev_isdst && $abbr === $prev_abbr && $abbr !== 'LMT')
					{
						continue;
					}

					$this->transitions[$tzid][$ts] = compact('ts', 'time', 'offset', 'isdst', 'abbr');

					$prev_offset = $offset;
					$prev_std_offset = $std_offset;
					$prev_save = $save == 0 ? 0 : $save / 3600 . ':' . sprintf('%02d', $save % 3600);
					$prev_isdst = $isdst;
					$prev_abbr = $abbr;
					$entry_end_offset = $$entry_end_offset_var;
				}
				// Simple DST rules.
				elseif (preg_match('/^-?\d+(:\d+)*$/', $entry['rules']))
				{
					$rules_parts = array_map('intval', explode(':', $entry['rules']));
					$rules_parts = array_pad($rules_parts, 3, 0);
					$rules_offset = abs($rules_parts[0]) * 3600 + $rules_parts[1] * 60 + $rules_parts[2];

					if (substr($entry['rules'], 0, 1) === '-')
						$rules_offset *= -1;

					$ts = $entry_start->getTimestamp();
					$time = $entry_start->format('Y-m-d\TH:i:sO');
					$offset = $std_offset + $rules_offset;
					$isdst = true;
					$abbr = $entry['format'] === '%z' ? sprintf("%+03d", strtr($offset, [':00' => '', ':' => ''])) : sprintf($entry['format'], 'D');
					$save = $rules_offset;
					$unadjusted_date_string = $unadjusted_date_strings['entry_start'];

					// Some abbr values use '+00/+01' instead of sprintf formats.
					if (strpos($abbr, '/') !== false)
						$abbr = substr($abbr, strpos($abbr, '/'));

					// Skip if these values are identical to the previous values.
					if ($offset === $prev_offset && $isdst === $prev_isdst && $abbr === $prev_abbr)
						continue;

					$this->transitions[$tzid][$ts] = compact('ts', 'time', 'offset', 'isdst', 'abbr');

					$prev_offset = $offset;
					$prev_std_offset = $std_offset;
					$prev_save = $save == 0 ? 0 : $save / 3600 . ':' . sprintf('%02d', $save % 3600);
					$prev_isdst = $isdst;
					$prev_abbr = $abbr;
					$entry_end_offset = $$entry_end_offset_var;
				}
				// Complex DST rules
				else
				{
					$default_letter = '-';
					$default_save = 0;

					$rule_transitions = $this->get_applicable_rule_transitions($entry['rules'], $unadjusted_date_strings, (int) $std_offset, (string) $prev_save);

					// Figure out the state when the entry starts.
					foreach ($rule_transitions as $date_string => $info)
					{
						if ($date_string >= $unadjusted_date_strings['entry_start'])
							break;

						$default_letter = $info['letter'];
						$default_save = $info['save'];

						if ($std_offset === $prev_std_offset && $prev_rules === $entry['rules'])
						{
							$prev_save = $info['save'];

							if ($prev_save != 0)
							{
								$prev_save_parts = array_map('intval', explode(':', $prev_save));
								$prev_save_parts = array_pad($prev_save_parts, 3, 0);
								$prev_save_offset = abs($prev_save_parts[0]) * 3600 + $prev_save_parts[1] * 60 + $prev_save_parts[2];

								if (substr($prev_save, 0, 1) === '-')
									$prev_save_offset *= -1;
							}
							else
							{
								$prev_save_offset = 0;
							}

							$prev_offset = $prev_std_offset + $prev_save_offset;
						}

						unset($rule_transitions[$date_string]);
					}

					// Add a rule transition at entry start, if not already present.
					if (!in_array($unadjusted_date_strings['entry_start'], array_column($rule_transitions, 'unadjusted_date_string')))
					{
						if ($default_letter === '-')
						{
							foreach ($rule_transitions as $date_string => $info)
							{
								if ($info['save'] == $default_save)
								{
									$default_letter = $info['letter'];
									break;
								}
							}
						}

						$rule_transitions[$unadjusted_date_strings['entry_start']] = array(
							'letter' => $default_letter,
							'save' => $default_save,
							'at_suffix' => $entry['from_suffix'],
							'unadjusted_date_string' => $unadjusted_date_strings['entry_start'],
							'adjusted_date_string' => $entry_start->format('Y-m-d\TH:i:sO'),
						);

						ksort($rule_transitions);
					}
					// Ensure entry start rule transition uses correct UTC time.
					else
					{
						$rule_transitions[$unadjusted_date_strings['entry_start']]['adjusted_date_string'] = $entry_start->format('Y-m-d\TH:i:sO');
					}

					// Create the transitions
					foreach ($rule_transitions as $date_string => $info)
					{
						if (!empty($info['adjusted_date_string']))
						{
							$transition_date = new \DateTime($info['adjusted_date_string']);
						}
						else
						{
							$transition_date = new \DateTime($date_string, $utc);

							if (empty($info['at_suffix']) || $info['at_suffix'] === 'w')
							{
								$transition_date->setTimestamp($transition_date->getTimestamp() - $prev_offset);
							}
							elseif ($info['at_suffix'] === 's')
							{
								$transition_date->setTimestamp($transition_date->getTimestamp() - $prev_std_offset);
							}
						}

						$save_parts = array_map('intval', explode(':', $info['save']));
						$save_parts = array_pad($save_parts, 3, 0);
						$save_offset = abs($save_parts[0]) * 3600 + $save_parts[1] * 60 + $save_parts[2];

						if (substr($info['save'], 0, 1) === '-')
							$save_offset *= -1;

						// Populate the transition values.
						$ts = $transition_date->getTimestamp();
						$time = $transition_date->format('Y-m-d\TH:i:sO');
						$offset = $std_offset + $save_offset;
						$isdst = $save_offset != 0;
						$abbr = $entry['format'] === '%z' ? sprintf("%+03d", strtr($offset, [':00' => '', ':' => ''])) : (sprintf($entry['format'], $info['letter'] === '-' ? '' : $info['letter']));
						$save = $save_offset;
						$unadjusted_date_string = $info['unadjusted_date_string'];

						// Some abbr values use '+00/+01' instead of sprintf formats.
						if (strpos($abbr, '/') !== false)
						{
							$abbrs = explode('/', $abbr);
							$abbr = $isdst ? $abbrs[1] : $abbrs[0];
						}

						// Skip if these values are identical to the previous values.
						if ($offset === $prev_offset && $isdst === $prev_isdst && $abbr === $prev_abbr)
							continue;

						// Don't create a redundant transition for the entry's end.
						if ($ts >= $entry_end->getTimestamp() - $$entry_end_offset_var)
							break;

						// Remember for the next iteration.
						$prev_offset = $offset;
						$prev_std_offset = $std_offset;
						$prev_save = $save == 0 ? 0 : $save / 3600 . ':' . sprintf('%02d', $save % 3600);
						$prev_isdst = $isdst;
						$prev_abbr = $abbr;
						$entry_end_offset = $$entry_end_offset_var;

						// This can happen in some rare cases.
						if ($ts < $entry_start->getTimestamp())
						{
							// Update the transition for the entry start, if it exists.
							if (isset($this->transitions[$tzid][$entry_start->getTimestamp()]))
							{
								$this->transitions[$tzid][$entry_start->getTimestamp()] = array_merge(
									$this->transitions[$tzid][$entry_start->getTimestamp()],
									compact('offset', 'isdst', 'abbr')
								);
							}

							continue;
						}

						// Create the new transition.
						$this->transitions[$tzid][$ts] = compact('ts', 'time', 'offset', 'isdst', 'abbr');
					}
				}

				if (!empty($entry_end_offset))
					$entry_end->setTimestamp($entry_end->getTimestamp() - $entry_end_offset);

				$prev_rules = $entry['rules'];
			}

			// Ensure the transitions are in the correct chronological order
			ksort($this->transitions[$tzid]);

			// Work around a data error in versions 2021b - 2022c of the TZDB.
			if ($tzid === 'Africa/Freetown')
			{
				$last_transition = end($this->transitions[$tzid]);

				if ($last_transition['time'] === '1941-12-07T01:00:00+0000' && $last_transition['abbr'] === '+01')
				{
					$this->transitions[$tzid][$last_transition['ts']] = array_merge(
						$last_transition,
						array(
							'offset' => 0,
							'isdst' => false,
							'abbr' => 'GMT',
						)
					);
				}
			}

			// Use numeric keys.
			$this->transitions[$tzid] = array_values($this->transitions[$tzid]);

			// Give the final entry an 'until_utc' date.
			$zone['entries'][$entry_num]['until_utc'] = self::DATE_MAX;
		}
	}

	/**
	 * Identifies time zones that might work as fallbacks for a given tzid.
	 *
	 * @param array $new_tzid A time zone identifier
	 * @return array A subset of $this->zones that might work as fallbacks for $new_tzid
	 */
	private function build_possible_fallback_zones($new_tzid): array
	{
		$new_zone = $this->zones[$new_tzid];

		// Build a list of possible fallback zones to check for this zone.
		$possible_fallback_zones = $this->zones;

		// Filter and sort $possible_fallback_zones.
		// We do this for performance purposes, because we are more likely to find
		// a suitable fallback nearby than far away.
		foreach ($possible_fallback_zones as $tzid => $record)
		{
			// Obviously the new ones can't be fallbacks. That's the whole point of
			// this exercise, after all.
			if (!empty($record['new']))
			{
				unset($possible_fallback_zones[$tzid]);
				continue;
			}

			// Obviously won't work if it's on the other side of the planet.
			$possible_fallback_zones[$tzid]['distance'] = $this->get_distance_from($possible_fallback_zones[$tzid], $this->zones[$new_tzid]);

			if ($possible_fallback_zones[$tzid]['distance'] > 6 * 15)
			{
				unset($possible_fallback_zones[$tzid]);
				continue;
			}
		}

		// Rank the possible fallbacks so that the (probably) best one is first.
		// A human should still check our suggestion, though.
		uasort(
			$possible_fallback_zones,
			function ($a, $b) use ($new_zone)
			{
				$cc = $new_zone['country_code'];

				if (!isset($a['country_code']))
					$a['country_code'] = 'ZZ';

				if (!isset($b['country_code']))
					$b['country_code'] = 'ZZ';

				// Prefer zones in the same country.
				if ($a['country_code'] === $cc && $b['country_code'] !== $cc)
					return -1;

				if ($a['country_code'] !== $cc && $b['country_code'] === $cc)
					return 1;

				// Legacy zones make good fallbacks, because they are rarely used.
				if ($a['country_code'] === 'ZZ' && $b['country_code'] !== 'ZZ')
					return -1;

				if ($a['country_code'] !== 'ZZ' && $b['country_code'] === 'ZZ')
					return 1;

				if (strpos($a['tzid'], '/') === false && strpos($b['tzid'], '/') !== false)
					return -1;

				if (strpos($a['tzid'], '/') !== false && strpos($b['tzid'], '/') === false)
					return 1;

				// Prefer links over canonical zones.
				if (isset($a['canonical']) && !isset($b['canonical']))
					return -1;

				if (!isset($a['canonical']) && isset($b['canonical']))
					return 1;

				// Prefer nearby zones over distant zones.
				if ($a['distance'] > $b['distance'])
					return 1;

				if ($a['distance'] < $b['distance'])
					return -1;

				// This is unlikely, but as a last resort use alphabetical sorting.
				return $a['tzid'] > $b['tzid'] ? 1 : -1;
			}
		);

		// Obviously, a time zone can't fall back to itself.
		unset($possible_fallback_zones[$new_tzid]);

		return $possible_fallback_zones;
	}

	/**
	 * Gets rule-based transitions for a time zone entry.
	 *
	 * @param string $rule_name The name of a time zone rule.
	 * @param array $unadjusted_date_strings Dates for $entry_start and $entry_end.
	 * @param int $std_offset The standard time offset for this time zone entry.
	 * @param string $prev_save The daylight saving value that applied just before $entry_start.
	 * @return array Transition rules.
	 */
	private function get_applicable_rule_transitions(string $rule_name, array $unadjusted_date_strings, int $std_offset, string $prev_save): array
	{
		static $rule_transitions = array();

		$utc = new \DateTimeZone('UTC');
		$date_max = new \DateTime(self::DATE_MAX);

		if (!isset($rule_transitions[$rule_name]))
		{
			$rules = $this->get_rules();

			foreach ($rules[$rule_name] as $rule_num => $rule)
			{
				preg_match('/(\d+(?::\d+)*)([wsugz]|)$/', $rule['at'], $matches);
				$rule['at'] = $matches[1];
				$rule['at_suffix'] = $matches[2];

				$year_from = $rule['from'];

				if ($rule['to'] === 'max')
				{
					$year_to = $date_max->format('Y');
				}
				elseif ($rule['to'] === 'only')
				{
					$year_to = $year_from;
				}
				else
					$year_to = $rule['to'];

				for ($year = $year_from; $year <= $year_to; $year++)
				{
					$transition_date_string = $this->rewrite_date_string(
						implode(' ', array(
							$year,
							$rule['in'],
							$rule['on'],
							$rule['at'] . (strpos($rule['at'], ':') === false ? ':00' : ''),
						))
					);

					$transition_date = new \DateTime($transition_date_string, $utc);

					$rule_transitions[$rule_name][$transition_date->format('Y-m-d\TH:i:s')] = array(
						'letter' => $rule['letter'],
						'save' => $rule['save'],
						'at_suffix' => $rule['at_suffix'],
						'unadjusted_date_string' => $transition_date->format('Y-m-d\TH:i:s'),
					);
				}
			}

			$temp = array();
			foreach ($rule_transitions[$rule_name] as $date_string => $info)
			{
				if (!empty($info['at_suffix']) && $info['at_suffix'] !== 'w')
				{
					$temp[$date_string] = $info;
					$prev_save = $info['save'];
					continue;
				}

				$transition_date = new \DateTime($date_string, $utc);

				$save_parts = array_map('intval', explode(':', $prev_save));
				$save_parts = array_pad($save_parts, 3, 0);
				$save_offset = abs($save_parts[0]) * 3600 + $save_parts[1] * 60 + $save_parts[2];

				if (substr($prev_save, 0, 1) === '-')
					$save_offset *= -1;

				$temp[$transition_date->format('Y-m-d\TH:i:s')] = $info;
				$prev_save = $info['save'];
			}
			$rule_transitions[$rule_name] = $temp;

			ksort($rule_transitions[$rule_name]);
		}

		$applicable_transitions = array();

		foreach ($rule_transitions[$rule_name] as $date_string => $info)
		{
			// After end of entry, so discard it.
			if ($date_string > $unadjusted_date_strings['entry_end'])
				continue;

			// Keep exactly one that preceeds the start of the entry,
			// so that we can know the state at the start of the entry.
			if ($date_string < $unadjusted_date_strings['entry_start'])
				array_shift($applicable_transitions);

			$applicable_transitions[$date_string] = $info;
		}

		return $applicable_transitions;
	}

	/**
	 * Compiles all the daylight saving rules in the TZDB.
	 *
	 * @return array Compiled rules, indexed by rule name.
	 */
	private function get_rules(): array
	{
		static $rules = array();

		if (!empty($rules))
			return $rules;

		$filenames = array(
			'africa',
			'antarctica',
			'asia',
			'australasia',
			'etcetera',
			'europe',
			'northamerica',
			'southamerica',
			'backward',
			'backzone',
		);

		// Populate $rules with TZDB data.
		foreach ($filenames as $filename)
		{
			$tzid = '';

			foreach (explode("\n", $this->fetch_tzdb_file($filename, $this->curr_commit)) as $line_num => $line)
			{
				$line = rtrim(substr($line, 0, strcspn($line, '#')));

				if ($line === '')
					continue;

				if (strpos($line, 'Rule') === 0)
				{
					if (strpos($line, '"') !== false)
					{
						preg_match_all('/"[^"]*"/', $line, $matches);

						$patterns = array();
						$replacements = array();
						foreach ($matches[0] as $key => $value)
						{
							$patterns[$key] = '/' . preg_quote($value, '/') . '/';
							$replacements[$key] = md5($value);
						}

						$line = preg_replace($patterns, $replacements, $line);

						$parts = preg_split('/\h+/', $line);

						foreach ($parts as &$part)
						{
							$r_keys = array_keys($replacements, $part);

							if (!empty($r_keys))
								$part = $matches[0][$r_keys[0]];
						}
					}
					else
						$parts = preg_split('/\h+/', $line);

					$parts = array_combine(array('rule', 'name', 'from', 'to', 'type', 'in', 'on', 'at', 'save', 'letter'), $parts);

					$parts['file'] = $filename;

					// These are useless.
					unset($parts['rule'], $parts['type']);

					$rules[$parts['name']][] = $parts;
				}
			}
		}

		return $rules;
	}

	/**
	 * Calculates the distance between the locations of two time zones.
	 *
	 * This somewhat simplistically treats locations on opposite sides of the
	 * antimeridian as maximally distant from each other. But since the antimeridian
	 * is approximately the track of the International Date Line, and locations on
	 * opposite sides of the IDL can't be fallbacks for each other, it's sufficient.
	 * In the unlikely edge case that that we ever need to find a fallback for, say,
	 * a newly created time zone for an island in Kiribati, the worst that could
	 * happen is that we might overlook some better option and therefore end up
	 * suggesting a generic Etc/* time zone as a fallback.
	 *
	 * @param array $this_zone One element from the $this->zones array.
	 * @param array $from_zone Another element from the $this->zones array.
	 * @return float The distance (in degrees) between the two locations.
	 */
	private function get_distance_from($this_zone, $from_zone): float
	{
		foreach (array('latitude', 'longitude') as $varname)
		{
			if (!isset($this_zone[$varname]))
			{
				echo $this_zone['tzid'], " has no $varname.\n";
				return 0;
			}
		}

		$lat_diff = abs($this_zone['latitude'] - $from_zone['latitude']);
		$lng_diff = abs($this_zone['longitude'] - $from_zone['longitude']);

		return sqrt($lat_diff ** 2 + $lng_diff ** 2);
	}

	/**
	 * Rewrites date strings from TZDB format to a PHP-parseable format.
	 *
	 * @param string $date_string A date string in TZDB format.
	 * @return string A date string that can be parsed by strtotime()
	 */
	private function rewrite_date_string(string $date_string): string
	{
		$month = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
		$weekday = 'Sun|Mon|Tue|Wed|Thu|Fri|Sat';

		$replacements = array(
			'/^\h*(\d{4})\h*$/' => '$1-01-01',

			"/(\d{4})\h+($month)\h+last($weekday)/" => 'last $3 of $2 $1,',

			"/(\d{4})\h+($month)\h+($weekday)>=(\d+)/" => '$2 $4 $1 this $3,',

			"/(\d{4})\h+($month)\h*$/" => '$2 $1',

			"/(\d{4})\h+($month)\h+(\d+)/" => '$2 $3 $1,',
		);

		if (strpos($date_string, '<=') !== false)
		{
			$date_string = preg_replace_callback(
				"/(\d{4})\h+($month)\h+($weekday)<=(\d+)/",
				function ($matches)
				{
					$d = new \DateTime($matches[2] . ' ' . $matches[4] . ' ' . $matches[1]);
					$d->add(new \DateInterval('P1D'));
					return $d->format('M j Y') . ' previous ' . $matches[3];
				},
				$date_string
			);
		}
		else
			$date_string = preg_replace(array_keys($replacements), $replacements, $date_string);

		$date_string = rtrim($date_string, ', ');

		// Some rules use '24:00' or even '25:00'
		if (preg_match('/\b(\d+)((?::\d+)+)\b/', $date_string, $matches))
		{
			if ($matches[1] > 23)
			{
				$d = new \DateTime(str_replace($matches[0], ($matches[1] % 24) . $matches[2], $date_string));
				$d->add(new \DateInterval('PT' . ($matches[1] - ($matches[1] % 24)) . 'H'));
				$date_string = $d->format('M j Y, G:i:s');
			}
		}

		return $date_string;
	}

	/**
	 * Generates PHP code to insert into get_tzid_fallbacks() for renamed tzids.
	 *
	 * @param array $renamed_tzids Key-value pairs of renamed tzids.
	 * @return string PHP code to insert into get_tzid_fallbacks()
	 */
	private function generate_rename_fallback_code(array $renamed_tzids): string
	{
		$generated = array();

		foreach ($renamed_tzids as $old_tzid => $new_tzid)
			$generated[$new_tzid] = array(array('ts' => 'PHP_INT_MIN', 'tzid' => $old_tzid));

		return preg_replace(
			array(
				'~\b\d+ =>\s+~',
				'~\barray\s+\(~',
				'~\s+=>\s+array\b~',
				"~'PHP_INT_MIN'~",
				'~  ~',
				'~^~m',
				'~^\s+array\(\n~',
				'~\s+\)$~',
			),
			array(
				'',
				'array(',
				' => array',
				'PHP_INT_MIN',
				"\t",
				"\t",
				'',
				'',
			),
			var_export($generated, true) . "\n"
		);
	}

	/**
	 * Generates PHP code to insert into get_tzid_fallbacks() for new tzids.
	 * Uses the fallback data created by build_fallbacks() to do so.
	 *
	 * @param array $fallbacks Fallback info for tzids.
	 * @return string PHP code to insert into get_tzid_fallbacks()
	 */
	private function generate_full_fallback_code(array $fallbacks): string
	{
		$generated = '';

		foreach ($fallbacks as $tzid => &$entries)
		{
			foreach ($entries as &$entry)
			{
				if (!empty($entry['options']))
				{
					$entry = array(
						'ts' => $entry['ts'],
						'// OPTIONS: ' . implode(', ', $entry['options']),
						'tzid' => $entry['tzid'],
					);
				}

				unset($entry['options']);
			}

			$generated .= preg_replace(
				array(
					'~\b\d+ =>\s+~',
					'~\barray\s+\(~',
					'~\s+=>\s+array\b~',
					"~'PHP_INT_MIN'~",
					"~'ts' => '([^']+)',~",
					"~'(// OPTIONS: [^'\\n]*)',~",
					'~  ~',
					'~^~m',
					'~^\s+array\(\n~',
					'~\s+\)$~',
				),
				array(
					'',
					'array(',
					' => array',
					'PHP_INT_MIN',
					"'ts' => strtotime('$1'),",
					'$1',
					"\t",
					"\t",
					'',
					'',
				),
				var_export(array($tzid => $entries), true) . "\n"
			);
		}

		return $generated;
	}
}


/**
 * A cheap stand-in for the real loadLanguage() function.
 * This one will only load the Timezones language file, which is all we need.
 */
function loadLanguage($template_name, $lang = '', $fatal = true, $force_reload = false)
{
	global $txt, $tztxt;

	if ($template_name !== 'Timezones')
		return;

	include($GLOBALS['langdir'] . '/Timezones.english.php');
}

?>