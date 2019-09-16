<?php
/*
		RPCS3.net Compatibility List (https://github.com/AniLeo/rpcs3-compatibility)
		Copyright (C) 2017 AniLeo
		https://github.com/AniLeo or ani-leo@outlook.com

		This program is free software; you can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation; either version 2 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU General Public License for more details.

		You should have received a copy of the GNU General Public License along
		with this program; if not, write to the Free Software Foundation, Inc.,
		51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

// Calls for the file that contains the needed functions
if(!@include_once("functions.php")) throw new Exception("Compat: functions.php is missing. Failed to include functions.php");
if (!@include_once(__DIR__."/objects/Game.php")) throw new Exception("Compat: Game.php is missing. Failed to include Game.php");


function cacheBuilds($full = false) {
	$db = getDatabase();
	$cr = curl_init();

	if (!$full) {
		set_time_limit(60*5); // 5 minute limit
		// Get date from last merged PR. Subtract 1 day to it and check new merged PRs since then.
		// Note: If master builds are disabled we need to remove WHERE type = 'branch'
		$mergeDateQuery = mysqli_query($db, "SELECT DATE_SUB(`merge_datetime`, INTERVAL 1 DAY) AS `date` FROM `builds` WHERE `type` = 'branch' ORDER BY `merge_datetime` DESC LIMIT 1;");
		$row = mysqli_fetch_object($mergeDateQuery);
		$date = date_format(date_create($row->date), 'Y-m-d');
	} elseif ($full) {
		// This can take a while to do...
		set_time_limit(60*60); // 1 hour limit
		// Start from indicated date (2015-08-10 for first PR with AppVeyor CI)
		$date = '2018-06-02';
	}

	// Get number of PRs (GitHub Search API)
	// repo:rpcs3/rpcs3, is:pr, is:merged, merged:>$date, sort=updated (asc)
	// TODO: Sort by merged date whenever it's available on the GitHub API
	$url = "https://api.github.com/search/issues?q=repo:rpcs3/rpcs3+is:pr+is:merged+sort:updated-asc+merged:%3E{$date}";
	$search = curlJSON($url, $cr)['result'];

	// API Call Failed or no PRs to cache, end here
	// TODO: Log and handle API call fails differently depending on the fail
	if (!isset($search->total_count) || $search->total_count == 0) {
		mysqli_close($db);
		curl_close($cr);
		return;
	}

	$page_limit = 30; // Search API page limit: 30
	$pages = (int)(ceil($search->total_count / $page_limit));
	$a_PR = array();	// List of iterated PRs
	$i = 1;	// Current page

	// Loop through all pages and get PR information
	while ($i <= $pages) {

		$a = 0; // Current PR (page relative)

		// Define PR limit for current page
		$pr_limit = ($i == $pages) ? ($search->total_count - (($pages-1)*$page_limit)) : $page_limit;

		$i++; // Prepare for next page

		while ($a < $pr_limit) {

			$pr = $search->items[$a]->number;
			$a++;	// Prepare for next PR

			// If PR was already checked in this run, skip it
			if (in_array($pr, $a_PR)) {
				continue;
			}
			$a_PR[]  = $pr;

			// Check if PR is already cached
			$PRQuery = mysqli_query($db, "SELECT * FROM `builds` WHERE `pr` = {$pr} LIMIT 1; ");

			// If PR is already cached and we're not in full mode, skip
			if (mysqli_num_rows($PRQuery) > 0 && !$full) {
				continue;
			}

			// Grab pull request information from GitHub REST API (v3)
			$pr_info = curlJSON("https://api.github.com/repos/rpcs3/rpcs3/pulls/{$pr}", $cr)['result'];

			// Check if we aren't rate limited
			if (!array_key_exists('merge_commit_sha', $pr_info)) {
				continue;
			}

			// Merge time, Creation Time, Commit SHA, Author
			$merge_datetime = $pr_info->merged_at;
			$start_datetime = $pr_info->created_at;
			$commit = $pr_info->merge_commit_sha;
			$author = $pr_info->user->login;

			// Additions, Deletions, Changed Files
			$additions = $pr_info->additions;
			$deletions = $pr_info->deletions;
			$changed_files = $pr_info->changed_files;

			// Currently unused
			$type = "branch";

			$aid = cacheContributor($author);
			// Checking author information failed
			// TODO: This should probably be logged, as other API call fails
			if ($aid == 0) {
				echo "Error: Checking author information failed";
				continue;
			}

			// Windows build metadata
			$info_release_win = curlJSON("https://api.github.com/repos/rpcs3/rpcs3-binaries-win/releases/tags/build-{$commit}", $cr)['result'];

			// Linux build metadata
			$info_release_linux = curlJSON("https://api.github.com/repos/rpcs3/rpcs3-binaries-linux/releases/tags/build-{$commit}", $cr)['result'];

			// Error message found: Build doesn't exist in rpcs3-binaries-win or rpcs3-binaries-linux yet, continue to check the next one
			if (isset($info_release_win->message) || isset($info_release_linux->message)) {
				continue;
			}

			// Version name
			$version = $info_release_win->name;

			// Simple sanity check: If version name doesn't contain a slash then the current entry is invalid
			if (!(strpos($version, '-') !== false)) {
				continue;
			}

			// Filename
			$filename_win = $info_release_win->assets[0]->name;
			$filename_linux = $info_release_linux->assets[0]->name;
			if (empty($filename_win) || empty($filename_linux)) {
				continue;
			}

			// Checksum and Size
			$fileinfo_win = explode(';', $info_release_win->body);
			$checksum_win = strtoupper($fileinfo_win[0]);
			$size_win = floatval(preg_replace("/[^0-9.,]/", "", $fileinfo_win[1]));
			if (strpos($fileinfo_win[1], "MB") !== false) {
				$size_win *= 1024 * 1024;
			} elseif (strpos($fileinfo_win[1], "KB") !== false) {
				$size_win *= 1024;
			}

			$fileinfo_linux = explode(';', $info_release_linux->body);
			$checksum_linux = strtoupper($fileinfo_linux[0]);
			$size_linux = floatval(preg_replace("/[^0-9.,]/", "", $fileinfo_linux[1]));
			if (strpos($fileinfo_linux[1], "MB") !== false) {
				$size_linux *= 1024 * 1024;
			} elseif (strpos($fileinfo_linux[1], "KB") !== false) {
				$size_linux *= 1024;
			}


			if (mysqli_num_rows(mysqli_query($db, "SELECT * FROM `builds` WHERE `pr` = {$pr} LIMIT 1; ")) == 1) {
				$cachePRQuery = mysqli_query($db, "UPDATE `builds` SET
				`commit` = '".mysqli_real_escape_string($db, $commit)."',
				`type` = '{$type}',
				`author` = '".mysqli_real_escape_string($db, $aid)."',
				`start_datetime` = '{$start_datetime}',
				`merge_datetime` = '{$merge_datetime}',
				`version` = '".mysqli_real_escape_string($db, $version)."',
				`additions` = '{$additions}',
				`deletions` = '{$deletions}',
				`changed_files` = '{$changed_files}',
				`size_win` = '".mysqli_real_escape_string($db, $size_win)."',
				`checksum_win` = '".mysqli_real_escape_string($db, $checksum_win)."',
				`filename_win` = '".mysqli_real_escape_string($db, $filename_win)."',
				`size_linux` = '".mysqli_real_escape_string($db, $size_linux)."',
				`checksum_linux` = '".mysqli_real_escape_string($db, $checksum_linux)."',
				`filename_linux` = '".mysqli_real_escape_string($db, $filename_linux)."'
				WHERE `pr` = '{$pr}' LIMIT 1;");
			} else {
				$cachePRQuery = mysqli_query($db, "INSERT INTO `builds`
				(`pr`, `commit`, `type`, `author`, `start_datetime`, `merge_datetime`, `version`, `additions`, `deletions`, `changed_files`, `size_win`, `checksum_win`, `filename_win`, `size_linux`, `checksum_linux`, `filename_linux`)
				VALUES ('{$pr}', '".mysqli_real_escape_string($db, $commit)."', '{$type}', '".mysqli_real_escape_string($db, $aid)."', '{$start_datetime}', '{$merge_datetime}',
				'".mysqli_real_escape_string($db, $version)."', '{$additions}', '{$deletions}', '{$changed_files}',
				'".mysqli_real_escape_string($db, $size_win)."', '".mysqli_real_escape_string($db, $checksum_win)."', '".mysqli_real_escape_string($db, $filename_win)."',
				'".mysqli_real_escape_string($db, $size_linux)."', '".mysqli_real_escape_string($db, $checksum_linux)."', '".mysqli_real_escape_string($db, $filename_linux)."'); ");
			}

			// Recache commit => pr cache
			cacheCommitCache();

		}

		if ($i <= $pages)
			$search = curlJSON("{$url}&page={$i}", $cr)['result'];

	}
	mysqli_close($db);
	curl_close($cr);
}


function cacheInitials() {
	$db = getDatabase();

	// Pack and Vol.: Idolmaster
	// GOTY: Batman
	$words_blacklisted = array("demo", "pack", "vol.", "goty");
	$words_whitelisted = array("hd");

	$q_initials = mysqli_query($db, "SELECT DISTINCT(`game_title`), `alternative_title` FROM `game_list`;");

	while ($row = mysqli_fetch_object($q_initials)) {
		$a_titles[] = $row->game_title;
		if (!is_null($row->alternative_title))
			$a_titles[] = $row->alternative_title;
	}

	foreach ($a_titles as $title) {

		// Original title
		$original = $title;

		// For games with semi-colons: replace those with spaces
		// Science Adventure Games (Steins;Gate/Chaos;Head/Robotics;Notes...)
		$title = str_replace(';', ' ', $title);

		// For games with double dots: replace those with spaces
		$title = str_replace(':', ' ', $title);

		// For games with double slashes: replace those with spaces
		$title = str_replace('//', ' ', $title);

		// For games with single slashes: replace those with spaces
		$title = str_replace('/', ' ', $title);

		// For games with hyphen: replace those with spaces
		$title = str_replace('-', ' ', $title);

		// For games starting with a dot: remove it (.detuned and .hack//Versus)
		if (strpos($title, '.') === 0)
			$title = substr($title, 1);

		// Divide game title by spaces between words
		$words = explode(' ', $title);

		// Variable to store initials result
		$initials = "";

		foreach ($words as $word) {
			// Skip empty words
			if (empty($word))
				continue;

			// Include whitelisted words and skip
			if (in_array(strtolower($word), $words_whitelisted)) {
				$initials .= $word;
				continue;
			}

			// Skip blacklisted words without including
			if (in_array(strtolower($word), $words_blacklisted))
				continue;

			// Handle roman numerals
			// Note: This catches some false positives, but the result is better than without this step
			if (preg_match("/^M{0,4}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/", $word)) {
				$initials .= $word;
				continue;
			}

			// If the first character is alphanumeric then add it to the initials, else ignore
			if (ctype_alnum($word[0])) {
				$initials .= $word[0];

				// If the next character is a digit, add next characters to initials
				// until an non-alphanumeric character is hit
				// For games like Disgaea D2 and Idolmaster G4U!
				if (strlen($word) > 1 && ctype_digit($word[1])) {
					$len = strlen($word);
					for ($i = 1; $i < $len; $i++)
						if (ctype_alnum($word[$i]))
							$initials .= $word[$i];
						else
							break;
				}
			}
			// Any word that doesn't have a-z A-Z
			// For games with numbers like 15 or 1942
			elseif (!ctype_alpha($word)) {
				$len = strlen($word);
				// While character is a number, add it to initials
				for ($i = 0; $i < $len; $i++)
					if (ctype_digit($word[$i]))
						$initials .= $word[$i];
					else
						break;
			}
		}

		// We don't care about games with less than 2 initials
		if (strlen($initials) > 1) {
			$original = mysqli_real_escape_string($db, $original);

			// Check if value is already cached (two games can have the same initials so we use game_title)
			$q_check = mysqli_query($db, "SELECT * FROM `initials_cache`
				WHERE `game_title` = '{$original}' LIMIT 1; ");

			// If value isn't cached, then cache it
			if (mysqli_num_rows($q_check) === 0) {
				mysqli_query($db, "INSERT INTO `initials_cache` (`game_title`, `initials`)
				VALUES ('{$original}', '".mysqli_real_escape_string($db, $initials)."'); ");
			} else {
				// If value is cached but differs from newly calculated initials, update it
				$row = mysqli_fetch_object($q_check);
				if ($row->initials != $initials) {
					mysqli_query($db, "UPDATE `initials_cache`
					SET `initials` = '".mysqli_real_escape_string($db, $initials)."'
					WHERE `game_title` = '{$original}' LIMIT 1;");
				}
			}
		}

	}
	mysqli_close($db);
}


function cacheLibraryStatistics() {
	global $a_filter;

	$db = getDatabase();

	// Get all game IDs in the database
	$a_games = array();
	$query = mysqli_query($db, "SELECT * FROM `game_id`; ");

	while($row = mysqli_fetch_object($query))
		$a_games[] = $row->gid;
	$all = sizeof($a_games);

	mysqli_close($db);

	$f_ps3tdb = fopen(__DIR__.'/ps3tdb.txt', 'r');

	if (!$f_ps3tdb)
		return;

	$tested = 0;
	$untested = 0;

	while (($line = fgets($f_ps3tdb)) !== false) {
		// Type: mb_substr($line, 0, 4)
		if (in_array(mb_substr($line, 0, 4), $a_filter)) {
			// GameID: mb_substr($line, 0, 9)
			in_array(mb_substr($line, 0, 9), $a_games) ? $tested++ : $untested++;
		}
	}

	// Closes ps3tdb.txt file resource
	fclose($f_ps3tdb);

	// Open tested.txt and write number of tested games in one line
	$f_tested = fopen(__DIR__.'/cache/tested.txt', 'w');
	fwrite($f_tested, $tested);
	fclose($f_tested);

	// Open untested.txt and write number of untested games in one line
	$f_untested = fopen(__DIR__.'/cache/untested.txt', 'w');
	fwrite($f_untested, $untested);
	fclose($f_untested);

	// Open all.txt and write number of all Game IDs in database in one line
	$f_all = fopen(__DIR__.'/cache/all.txt', 'w');
	fwrite($f_all, $all);
	fclose($f_all);
}


function cacheStatusModules() {
	$f_status = fopen(__DIR__.'/cache/mod.status.count.php', 'w');
	fwrite($f_status, "\n<!-- START: Status Module -->\n<!-- This file is automatically generated -->\n".generateStatusModule()."\n<!-- END: Status Module -->\n");
	fclose($f_status);

	$f_status = fopen(__DIR__.'/cache/mod.status.nocount.php', 'w');
	fwrite($f_status, "\n<!-- START: Status Module -->\n<!-- This file is automatically generated -->\n".generateStatusModule(false)."\n<!-- END: Status Module -->\n");
	fclose($f_status);
}


// Fetch all used commits => pull requests from builds table
// and store on cache/a_commits.json
// Since this is rather static data, we're caching it to a file
// Saves up a lot of execution time
function cacheCommitCache() {
	$db = getDatabase();

	$a_cache = array();

	// This is faster than verifying one by one per row on storeResults()
	$q_builds = mysqli_query($db, "SELECT DISTINCT `pr`, `commit` FROM `builds`
	LEFT JOIN `game_list` ON SUBSTR(`commit`, 1, 7) = SUBSTR(`build_commit`, 1, 7)
	WHERE `build_commit` IS NOT NULL
	ORDER BY `merge_datetime` DESC;");

	while ($row = mysqli_fetch_object($q_builds)) {
		$a_cache[substr($row->commit, 0, 7)] = array($row->commit, $row->pr);
	}

	$f_commits = fopen(__DIR__.'/cache/a_commits.json', 'w');
	fwrite($f_commits, json_encode($a_cache));
	fclose($f_commits);

	mysqli_close($db);

	return $a_cache;
}


function cacheStatusCount() {
	$db = getDatabase();

	$a_cache = array();

	// Fetch general count per status
	$q_status = mysqli_query($db, "SELECT status+0 AS sid, count(*) AS c FROM game_list WHERE `network` = 0 OR (`network` = 1 && `status` <= 2) GROUP BY status;");

	$a_cache[0][0] = 0;

	while ($row = mysqli_fetch_object($q_status)) {
		$a_cache[0][$row->sid] = (int)$row->c;
		$a_cache[0][0] += $a_cache[0][$row->sid];
	}

	$a_cache[1] = $a_cache[0];

	$f_count = fopen(__DIR__.'/cache/a_count.json', 'w');
	fwrite($f_count, json_encode($a_cache));
	fclose($f_count);

	mysqli_close($db);
}


function cacheContributor($username) {
	$db = getDatabase();

	$info_contributor = curlJSON("https://api.github.com/users/{$username}")['result'];

	// If message is set, API call did not go well. Ignore caching.
	if (!isset($info_contributor->message)) {

		$aid = $info_contributor->id;
		$q_contributor = mysqli_query($db, "SELECT * FROM contributors WHERE id = ".mysqli_real_escape_string($db, $aid)." LIMIT 1; ");

		if (mysqli_num_rows($q_contributor) === 0) {
			// Contributor not yet cached on contributors table.
			mysqli_query($db, "INSERT INTO `contributors` (`id`, `username`) VALUES (".mysqli_real_escape_string($db, $aid).", '".mysqli_real_escape_string($db, $username)."');");
		} elseif (mysqli_fetch_object($q_contributor)->username != $username) {
			// Contributor on contributors table but changed GitHub username.
			mysqli_query($db, "UPDATE `contributors` SET `username` = '".mysqli_real_escape_string($db, $username)."' WHERE `id` = ".mysqli_real_escape_string($db, $aid).";");
		}

	}

	mysqli_close($db);

	return !isset($info_contributor->message) ? $aid : 0;
}


function cacheWikiIDs() {
	$db = getDatabase();

	$a_cache = file_exists(__DIR__.'/../cache/a_commits.json') ? json_decode(file_get_contents(__DIR__.'/../cache/a_commits.json'), true) : cacheCommitCache();

	$q_games = mysqli_query($db, "SELECT * FROM `game_list`;");
	$a_games = Game::queryToGames($q_games);

	$q_wiki = mysqli_query($db, "SELECT `page_id`, `page_title`, `rev_id`, `rev_len`, CONVERT(`old_text` USING utf8mb4) AS `text` FROM `rpcs3_wiki`.`page`
	LEFT JOIN `rpcs3_wiki`.`revision` ON `page_latest` = `rev_id`
	LEFT JOIN `rpcs3_wiki`.`text` ON `rev_text_id` = `old_id`
	WHERE page_namespace = 0; ");
	$a_wiki = array();
	while ($row = mysqli_fetch_object($q_wiki))
		$a_wiki[] = array($row->page_id, $row->text);

	$a_found = array();

	// For every game
	// For every ID
	// Look for the ID on all wiki pages
	foreach ($a_games as $game) {
		foreach ($game->IDs as $id) {
			foreach ($a_wiki as $wiki) {
				if (strpos($wiki[1], $id[0]) !== false) {
					$a_found[] = array($game->title, $wiki[0]);
					break 2;
				}
			}
		}
	}

	// Maybe delete all pages beforehand? Probably not needed as Wiki pages shouldn't be changing IDs.
	foreach ($a_found as $entry) {
		$q_update = mysqli_query($db, "UPDATE `game_list` SET `wiki`={$entry[1]} WHERE (`game_title`='".mysqli_real_escape_string($db, $entry[0])."');");
	}

	mysqli_close($db);
}


function cacheGameLatestVer() {
	$db = getDatabase();

	$q_ids = mysqli_query($db, "SELECT * FROM `game_id` WHERE `latest_ver` IS NULL;");
	while ($row = mysqli_fetch_object($q_ids)) {
		// Get latest game update ver for this game
		$updateVer = getLatestGameUpdateVer($row->gid);

		// If we failed to get the latest version from the API
		if (is_null($updateVer)) {
			echo "<b>Error:</b> Could not fetch game latest version for {$row->gid}.<br><br>";
			continue;
		}

		// Insert Game and Thread IDs on the ID table
		$q_insert = mysqli_query($db, "UPDATE `game_id` SET `latest_ver`='".mysqli_real_escape_string($db, $updateVer)."' WHERE `gid`='{$row->gid}';");
	}
	mysqli_close($db);
}
