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

if (!@include_once(__DIR__."/../functions.php")) throw new Exception("Compat: functions.php is missing. Failed to include functions.php");
if (!@include_once(__DIR__."/../cachers.php")) throw new Exception("Compat: cachers.php is missing. Failed to include cachers.php");
if (!@include_once(__DIR__."/../objects/Game.php")) throw new Exception("Compat: Game.php is missing. Failed to include Game.php");
if (!@include_once(__DIR__."/../objects/Build.php")) throw new Exception("Compat: Build.php is missing. Failed to include Build.php");
if (!@include_once(__DIR__."/../objects/MyBBThread.php")) throw new Exception("Compat: MyBBThread.php is missing. Failed to include MyBBThread.php");
if (!@include_once(__DIR__."/../html/HTML.php")) throw new Exception("Compat: HTML.php is missing. Failed to include HTML.php");


/*
TODO: Login system
TODO: Log commands with run time and datetime
*/


function runFunctions() : void
{
	global $get, $a_panel;

	if (array_key_exists($get['a'], $a_panel))
	{
		$ret = runFunctionWithCronometer($get['a']);

		if (!empty($a_panel[$get['a']]['success']))
			echo "<p><b>Debug mode:</b> {$a_panel[$get['a']]['success']} ({$ret}s).</p>";
	}
}

function checkInvalidThreads() : void
{
	global $a_status, $get;

	$invalid = 0;
	$output = "";
	$where = "";
	$a_threads = array();

	// Generate WHERE condition for our query
	// Includes all forum IDs for the game status sections
	$where = '';
	foreach ($a_status as $id => $status)
	{
		foreach ($status["fid"] as $fid)
		{
			if (!empty($where))
				$where .= "||";

			$where .= " `fid` = {$fid} ";
		}
	}

	$db = getDatabase();

	$q_threads = mysqli_query($db, "SELECT `tid`, `subject`, `fid`, `closed`
	FROM `rpcs3_forums`.`mybb_threads`
	WHERE ({$where}) AND `visible` > 0 AND (`closed` = '' OR `closed` = '0'); ");

	$a_games = Game::query_to_games(mysqli_query($db, "SELECT * FROM `game_list`; "));

	mysqli_close($db);

	while ($row = mysqli_fetch_object($q_threads))
	{
		$thread = new MyBBThread($row->tid, $row->fid, $row->subject);

		if (is_null($thread->get_game_id()))
		{
			$html_a = new HTMLA($thread->get_thread_url(), "", "{$row->subject}");
			$html_a->set_target("_blank");

			$output .= "<p>Thread {$html_a->to_string()} is incorrectly formatted.</p>";
			continue;
		}

		$a_threads[$row->tid] = $thread;
	}

	foreach ($a_games as $game)
	{
		foreach ($game->game_item as $item)
		{
			if (!array_key_exists($item->thread_id, $a_threads))
			{
				$output .= "<p class='debug-tvalidity-list'>";
				$output .= "Thread {$item->thread_id}: [{$item->game_id}] {$game->title} doesn't exist.<br>";
				$output .= "</p>";
				$invalid++;
			}
			elseif ($item->game_id !== $a_threads[$item->thread_id]->get_game_id())
			{
				$html_a = new HTMLA($a_threads[$item->thread_id]->get_thread_url(-1), "", "{$item->thread_id}: [{$item->game_id}] {$game->title}");
				$html_a->set_target("_blank");

				$output .= "<p class='debug-tvalidity-list'>";
				$output .= "Thread {$html_a->to_string()} is incorrect.<br>";
				$output .= "- Compat: {$game->title} [{$item->game_id}]<br>";
				$output .= "- Forums: {$a_threads[$item->thread_id]->get_game_id()}<br>";
				$output .= "</p>";
				$invalid++;
			}
			elseif ($game->status !== $a_threads[$item->thread_id]->get_sid())
			{
				$html_a = new HTMLA($a_threads[$item->thread_id]->get_thread_url(-1), "", "{$item->thread_id}: [{$item->game_id}] {$game->title}");
				$html_a->set_target("_blank");

				$output .= "<p class='debug-tvalidity-list'>";
				$output .= "Thread {$html_a->to_string()} is in the wrong section.<br>";
				$output .= "- Compat: {$a_status[$game->status]['name']} <br>";
				$output .= "- Forums: {$a_status[$a_threads[$item->thread_id]->get_sid()]['name']}<br>";
				$output .= "</p>";
				$invalid++;
			}
		}
	}

	if ($invalid > 0)
	{
		echo "<p class='debug-tvalidity-title color-red'>Attention required! {$invalid} Invalid threads detected</p>";

		if ($get['a'] === "checkInvalidThreads")
			echo $output;
	}
	else
	{
		echo "<p class='debug-tvalidity-title color-green'>No invalid threads detected</p>";
	}
}

function compatibilityUpdater() : void
{
	global $a_histdates, $a_status, $get;

	set_time_limit(300);
	$db = getDatabase();

	// Timestamp of the penultimate list update
	end($a_histdates);
	$lastkey = key($a_histdates);
	reset($a_histdates);
	$ts_lastupdate = strtotime("{$a_histdates[$lastkey][0]['y']}-{$a_histdates[$lastkey][0]['m']}-{$a_histdates[$lastkey][0]['d']}");

	// Generate WHERE condition for our query
	// Includes all forum IDs for the game status sections
	$where = '';
	foreach ($a_status as $id => $status)
	{
		foreach ($status["fid"] as $fid)
		{
			if (!empty($where))
				$where .= "||";

			$where .= " `fid` = {$fid} ";
		}
	}

	// Cache commits
	$q_commits = mysqli_query($db, "SELECT `pr`, `commit`, `merge_datetime` FROM `builds` ORDER BY `merge_datetime` DESC;");
	$a_commits = array();
	while ($row = mysqli_fetch_object($q_commits))
		$a_commits[$row->commit] = array("pr" => $row->pr, "merge" => $row->merge_datetime);

	// Get all threads since the end of the last compatibility period
	$a_threads = array();
	$q_threads = mysqli_query($db, "SELECT `tid`, `fid`, `subject`, `lastpost`, `visible` 
	FROM `rpcs3_forums`.`mybb_threads`
	WHERE ({$where}) &&
	(`closed` = '' || `closed` = '0') &&
	`lastpost` > {$ts_lastupdate};");

	while ($row = mysqli_fetch_object($q_threads))
	{
		$thread = new MyBBThread($row->tid, $row->fid, $row->subject);

		// Invalid Game ID
		if (is_null($thread->get_game_id()) || is_null($thread->get_game_title()))
		{
			$html_a = new HTMLA($thread->get_thread_url(), "", $thread->subject);
			$html_a->set_target("_blank");

			echo "<span style='color:red'>Error! Invalid new thread found. See: {$html_a->to_string()}.<br><br></span>";
			continue;
		}
		// Thread with negative visibility (unapproved, deleted)
		else if ($row->visible <= 0)
		{
			$html_a = new HTMLA($thread->get_thread_url(), "", $thread->subject);
			$html_a->set_target("_blank");

			echo "<span style='color:red'>Error! The new thread for {$thread->get_game_id()} is not visible ({$row->visible}). See: {$html_a->to_string()}.<br><br></span>";
			continue;
		}

		$a_threads[] = $thread;
	}

	// Get all games in the database
	$a_games = Game::query_to_games(mysqli_query($db, "SELECT * FROM `game_list`;"));

	// Script data
	$a_inserts = array();
	$a_updates = array();
	// Visited Game IDs
	$a_gameIDs = array();

	echo "<p>"; // Start paragraph

	foreach ($a_threads as $thread)
	{
		// If a thread for this Game ID was already visited, continue to next thread entry
		if (in_array($thread->get_game_id(), $a_gameIDs))
		{
			$html_a = new HTMLA($thread->get_thread_url(), "", $thread->subject);
			$html_a->set_target("_blank");

			echo "Error! A thread for {$thread->get_game_id()} was already visited. {$html_a->to_string()} is a duplicate.<br><br>";
			continue;
		}

		$a_gameIDs[] = $thread->get_game_id();

		// Thread ID validation
		// If game entry exists, get game data
		$tid = null;
		$cur_game = null;
		foreach ($a_games as $game)
		{
			foreach ($game->game_item as $item)
			{
				if ($item->game_id === $thread->get_game_id())
				{
					$tid = $item->thread_id;
					$cur_game = $game;
				}
			}
		}

		// New thread is a duplicate of an existing one
		if (!is_null($tid) && $tid != $thread->tid)
		{
			$html_a_thread1 = new HTMLA($thread->get_thread_url(), "", (string) $thread->tid);
			$html_a_thread2 = new HTMLA("https://forums.rpcs3.net/thread-{$tid}.html", "", (string) $tid);
			$html_a_thread1->set_target("_blank");
			$html_a_thread2->set_target("_blank");

			echo "<span style='color:red'><b>Error!</b> {$thread->subject} ({$html_a_thread1->to_string()}) duplicated thread of ({$html_a_thread2->to_string()}).</span><br><br>";
			continue;
		}

		// New thread for the Game ID
		if (is_null($tid))
		{
			$a_inserts[$thread->tid] = array(
				'thread' => $thread
			);

			// Verify posts
			$q_post = mysqli_query($db, "SELECT `pid`, `dateline`, `message`, `username`
			FROM `rpcs3_forums`.`mybb_posts` WHERE `tid` = {$thread->tid}
			ORDER BY `pid` DESC;");

			while ($post = mysqli_fetch_object($q_post))
			{
				foreach ($a_commits as $commit => $value)
				{
					if (stripos($post->message, (string) substr($commit, 0, 8)) !== false)
					{
						$a_inserts[$thread->tid]['commit'] = $commit;
						$a_inserts[$thread->tid]['pr'] = $value["pr"];
						$a_inserts[$thread->tid]['last_update'] = date('Y-m-d', $post->dateline);
						$a_inserts[$thread->tid]['author'] = $post->username;
						break 2;
					}
				}
			}

			$html_a = new HTMLA($thread->get_thread_url(-1), "", (string) $thread->tid);
			$html_a->set_target("_blank");

			// Invalid report found
			if (!isset($a_inserts[$thread->tid]['commit']) || !isset($a_inserts[$thread->tid]['pr']))
			{
				echo "<b>Error!</b> Invalid report found on tid: {$html_a->to_string()}<br><br>";
				unset($a_inserts[$thread->tid]);
				continue;
			}

			// Valid report found
			$commit        = $a_inserts[$thread->tid]['commit'];
			$date_commit   = "({$a_commits[$commit]["merge"]})";

			echo "<b>New:</b> {$thread->subject} (tid: {$html_a->to_string()}, author: {$a_inserts[$thread->tid]['author']})<br>";
			echo "- Status: <span style='color:#{$a_status[$thread->get_sid()]['color']}'>{$a_status[$thread->get_sid()]['name']} ({$a_inserts[$thread->tid]['last_update']})</span><br>";
			echo "- Commit: <span style='color:green'>{$commit}</span> {$date_commit}<br>";
			echo "<br>";

		}
		elseif ($tid == $thread->tid && ($thread->get_sid() != $cur_game->status || $thread->get_sid() == 3 || $thread->get_sid() == 4 || $thread->get_sid() == 5))
		{
			// Same status updates currently being tested
			// For now only allowed on Intro, Loadable and Nothing games

			// This game entry was already checked before in this script
			// Update with the new information
			if (array_key_exists($cur_game->key, $a_updates))
			{
				// Update status
				if ($a_updates[$cur_game->key]['status'] < $thread->get_sid())
				{
					echo "<b>Error!</b> Smaller status after a status update ({$thread->get_game_id()}, {$a_updates[$cur_game->key]['status']} < {$thread->get_sid()})<br><br>";
					continue;
				}
				elseif (is_null($a_updates[$cur_game->key]['commit']))
				{
					echo "<b>Error!</b> Unreachable code <br><br>";
					dumpVar($a_updates[$cur_game->key]);
					continue;
				}
			}
			else
			{
				$a_updates[$cur_game->key] = array(
					'thread' => $thread,
					'game_title' => $cur_game->title,
					'status' => $thread->get_sid(),
					'commit' => null,
					'pr' => null,
					'action' => 'mov',
					'old_date' => $cur_game->date,
					'old_status' => $cur_game->status,
					'author' => '',
					'pid' => 0
				);
			}

			// Verify posts
			$q_post = mysqli_query($db, "SELECT `pid`, `dateline`, `message`, `username`
			                             FROM `rpcs3_forums`.`mybb_posts` 
			                             WHERE `tid` = {$thread->tid} && `dateline` > {$a_updates[$cur_game->key]['old_date']} 
			                             ORDER BY `pid` DESC;");

			while ($post = mysqli_fetch_object($q_post))
			{
				foreach ($a_commits as $commit => $value)
				{
					// Skip posts with no commits
					if (stripos($post->message, (string) substr($commit, 0, 8)) === false)
						continue;
					
					// If current commit is newer than the previously recorded one, replace
					// TODO: Check distance between commit date and post here
					// TODO: Remove quoted sections from $post->message before checking it
					if (is_null($a_updates[$cur_game->key]['commit']) || 
					    strtotime($a_commits[$a_updates[$cur_game->key]['commit']]["merge"]) < strtotime($value["merge"]))
					{
						$a_updates[$cur_game->key]['commit'] = $commit;
						$a_updates[$cur_game->key]['pr'] = $value["pr"];
						$a_updates[$cur_game->key]['last_update'] = date('Y-m-d', $post->dateline);
						$a_updates[$cur_game->key]['author'] = $post->username;
						$a_updates[$cur_game->key]['pid'] = $post->pid;
						break 2;
					}
				}
			}

			// If the new date is older than the current date (meaning there's no valid report post)
			// Or no new pr was found
			// then ignore this entry and continue
			if (!isset($a_updates[$cur_game->key]['last_update']) ||
			    strtotime($cur_game->date) >= strtotime($a_updates[$cur_game->key]['last_update']) ||
			    is_null($a_updates[$cur_game->key]['pr']))
			{
				unset($a_updates[$cur_game->key]);
				continue;
			}

			// Check if the distance between commit date and post is bigger than 4 weeks
			if (strtotime($a_updates[$cur_game->key]['last_update']) - strtotime($a_commits[$a_updates[$cur_game->key]['commit']]["merge"]) > 4 * 604804)
			{
				echo "<b>Warning:</b> Distance between commit date and post bigger than 4 weeks<br>";
			}

			// Green for existing commit, Red for non-existing commit
			$old_status_commit = !is_null($cur_game->pr) ? 'green' : 'red';
			$commit            = $a_updates[$cur_game->key]['commit'];
			$date_commit       = "({$a_commits[$commit]["merge"]})";
			$old_commit        = !is_null($cur_game->commit) ? substr($cur_game->commit, 0, 8) : "null";

			$html_a = new HTMLA($thread->get_thread_url(-1), "", (string) $thread->tid);
			$html_a->set_target("_blank");

			echo "<b>Mov:</b> {$thread->get_game_id()} - {$cur_game->title} (tid: {$html_a->to_string()}, pid: {$a_updates[$cur_game->key]['pid']}, author: {$a_updates[$cur_game->key]['author']})<br>";
			echo "- Status: <span style='color:#{$a_status[$thread->get_sid()]['color']}'>{$a_status[$thread->get_sid()]['name']} ({$a_updates[$cur_game->key]['last_update']})</span>
						<-- <span style='color:#{$a_status[$cur_game->status]['color']}'>{$a_status[$cur_game->status]['name']} ({$cur_game->date})</span><br>";
			echo "- Commit: <span style='color:green'>{$commit}</span> {$date_commit}
						<-- <span style='color:{$old_status_commit}'>{$old_commit}</span> ({$cur_game->date})<br>";
			echo "<br>";
		}
	}

	echo "</p>"; // End paragraph

	if (isset($_POST['updateCompatibility']))
	{
		// Permissions: Update
		if (array_search("debug.update", $get['w']) === false)
		{
			echo "<p><b>Error:</b> You do not have permission to issue database update commands</p>";
			mysqli_close($db);
			return;
		}

		$cr = curl_init();

		/*
			Inserts
		*/
		foreach ($a_inserts as $tid => $game)
		{
			$db_game_id = mysqli_real_escape_string($db, $game['thread']->get_game_id());
			$db_game_title = mysqli_real_escape_string($db, $game['thread']->get_game_title());

			// Insert new entry on the game list
			mysqli_query($db, "INSERT INTO `game_list` (`game_title`, `build_commit`, `pr`, `last_update`, `status`, `type`) VALUES
			('{$db_game_title}',
			'".mysqli_real_escape_string($db, $game['commit'])."',
			'".mysqli_real_escape_string($db, $game['pr'])."',
			'{$game['last_update']}',
			{$game['thread']->get_sid()},
			{$game['thread']->get_game_type()});");

			// Get the key from the entry that was just inserted
			$q_fetchkey = mysqli_query($db, "SELECT `key` FROM `game_list` WHERE
			`game_title` = '{$db_game_title}' AND
			`build_commit` = '".mysqli_real_escape_string($db, $game['commit'])."' AND
			`pr` = '".mysqli_real_escape_string($db, $game['pr'])."' AND
			`last_update` = '{$game['last_update']}' AND
			`status` = {$game['thread']->get_sid()} AND
			`type` = {$game['thread']->get_game_type()}
			ORDER BY `key` DESC LIMIT 1");

			$row = mysqli_fetch_object($q_fetchkey);

			if (!isset($row->key))
				exit("[COMPAT] Includes: Missing database fields");

			// Sanity check, this should be unreachable
			if (is_null($row->key))
			{
				echo "<b>Fatal error:</b> Could not fetch key. Current game dump: <br><br>";
				dumpVar($game);
				continue;
			}

			// Insert Game and Thread IDs on the ID table
			mysqli_query($db, "INSERT INTO `game_id` (`key`, `gid`, `tid`) VALUES ({$row->key}, '{$db_game_id}', {$tid}); ");

			// Cache the updates for the new ID
			cache_game_updates($cr, $db, $game['thread']->get_game_id());

			// Log change to game history
			mysqli_query($db, "INSERT INTO `game_history` (`game_key`, `new_gid`, `new_status`, `new_date`) VALUES
			({$row->key}, '{$db_game_id}', '{$game['thread']->get_sid()}', '{$game['last_update']}');");
		}

		/*
			Updates
		*/
		foreach ($a_updates as $key => $game)
		{
			// Update entry parameters on game list
			mysqli_query($db, "UPDATE `game_list` SET
			`build_commit` = '".mysqli_real_escape_string($db, $game['commit'])."',
			`pr` = '".mysqli_real_escape_string($db, $game['pr'])."',
			`last_update` = '{$game['last_update']}',
			`status` = '{$game['status']}'
			WHERE `key` = {$key};");

			// Log change to game history
			mysqli_query($db, "INSERT INTO `game_history` (`game_key`, `old_status`, `old_date`, `new_status`, `new_date`) VALUES
			({$key}, '{$game['old_status']}', '{$game['old_date']}', '{$game['status']}', '{$game['last_update']}'); ");
		}

		curl_close($cr);

		// Recache initials cache
		cacheInitials();
		// Recache status modules
		cacheStatusModules();
	}
	else
	{
		// Display update button
		$form = new HTMLForm("", "POST");
		$form->add_button(new HTMLButton("updateCompatibility", "submit", "Update Compatibility"));
		$form->print();
	}

	mysqli_close($db);
}

function refreshBuild() : void
{
	$pr = (isset($_POST["pr"]) && is_numeric($_POST["pr"])) ? (int) $_POST["pr"] : 0;

	$form = new HTMLForm("", "POST");
	$form->add_input(new HTMLInput("pr", "text", "{$pr}", "Pull Request"));
	$form->add_button(new HTMLButton("refreshBuild", "submit", "Refresh"));
	$form->print();

	if (!isset($_POST["refreshBuild"]))
		return;

	cache_build($pr);
}

function mergeGames() : void
{
	global $a_status, $get;

	$gid1 = isset($_POST['gid1']) ? $_POST['gid1'] : "";
	$gid2 = isset($_POST['gid2']) ? $_POST['gid2'] : "";

	$form = new HTMLForm("", "POST");
	$form->add_input(new HTMLInput("gid1", "text", $gid1, "Game ID 1"));
	$form->add_input(new HTMLInput("gid2", "text", $gid2, "Game ID 2"));
	$form->add_button(new HTMLButton("mergeRequest", "submit", "Merge Request"));
	$form->add_button(new HTMLButton("mergeConfirm", "submit", "Merge Confirm"));
	$form->print();

	if (!isset($_POST['mergeRequest']) && !isset($_POST['mergeConfirm']))
		return;

	if (!isGameID($gid1))
	{
		echo "<p><b>Error:</b> Game ID 1 is not a valid Game ID</p>";
		return;
	}
	if (!isGameID($gid2))
	{
		echo "<p><b>Error:</b> Game ID 2 is not a valid Game ID</p>";
		return;
	}

	$db = getDatabase();

	$s_gid1 = mysqli_real_escape_string($db, $_POST['gid1']);
	$s_gid2 = mysqli_real_escape_string($db, $_POST['gid2']);

	$q_game1 = mysqli_query($db, "SELECT * FROM `game_list` WHERE `key` IN(SELECT `key` FROM `game_id` WHERE `gid` = '{$s_gid1}');");
	if (mysqli_num_rows($q_game1) === 0)
	{
		echo "<p><b>Error:</b> Game ID 1 could not be found</p>";
		return;
	}
	$game1 = Game::query_to_games($q_game1)[0];

	$q_game2 = mysqli_query($db, "SELECT * FROM `game_list` WHERE `key` IN(SELECT `key` FROM `game_id` WHERE `gid` = '{$s_gid2}');");
	if (mysqli_num_rows($q_game2) === 0)
	{
		echo "<p><b>Error:</b> Game ID 2 could not be found</p>";
		return;
	}
	$game2 = Game::query_to_games($q_game2)[0];

	if ($game1->key === $game2->key)
	{
		echo "<p><b>Error:</b> Both Game IDs belong to the same Game Entry</p>";
		return;
	}

	if (substr($game1->game_item[0]->game_id, 0, 1) !== substr($game2->game_item[0]->game_id, 0, 1))
	{
		echo "<p><b>Error:</b> Cannot merge entries of different Game Media</p>";
		return;
	}

	echo "<p>"; // Start paragraph

	$alternative1 = !is_null($game1->title2) ? "(alternative: {$game1->title2})" : "";
	$alternative2 = !is_null($game2->title2) ? "(alternative: {$game2->title2})" : "";

	$pr1 = !is_null($game1->pr) ? $game1->pr : "null";
	$pr2 = !is_null($game2->pr) ? $game2->pr : "null";

	echo "<b>Game 1: {$game1->title} {$alternative1} (status: <span style='color:#{$a_status[$game1->status]['color']}'>{$a_status[$game1->status]['name']}</span>, pr: {$pr1}, date: {$game1->date})</b><br>";
		foreach ($game1->game_item as $item)
			echo "- {$item->game_id} (tid: {$item->thread_id})<br>";
	echo "<br>";

	echo "<b>Game 2: {$game2->title} {$alternative2} (status: <span style='color:#{$a_status[$game2->status]['color']}'>{$a_status[$game2->status]['name']}</span>, pr: {$pr2}, date: {$game2->date})</b><br>";
		foreach ($game2->game_item as $item)
			echo "- {$item->game_id} (tid: {$item->thread_id})<br>";
	echo "<br>";

	$time1 = strtotime($game1->date);
	$time2 = strtotime($game2->date);

	// If the most recent entry doesn't have a PR and the oldest one has
	// allow for 1 month tolerance to use the older key if the difference between them is 1 month at max
	if (is_null($game1->pr) && !is_null($game2->pr))
		$time1 -= 2678400;
	if (!is_null($game1->pr) && is_null($game2->pr))
		$time2 -= 2678400;

	if ($time1 === $time2 && $game1->pr !== $game2->pr)
	{
		// If the update date is the same, pick the one with the most recent PR
		// TODO: Check for null cases
		$new = $game1->pr > $game2->pr ? $game1 : $game2;
		$old = $game1->pr > $game2->pr ? $game2 : $game1;
	}
	else if ($game1->pr === $game2->pr)
	{
		// If PRs are the same, pick the one with the oldest update date
		$new = $time1 < $time2 ? $game1 : $game2;
		$old = $time1 < $time2 ? $game2 : $game1;
	}
	else
	{
		// If the update date differs, pick the one with the most recent update date
		$new = $time1 > $time2 ? $game1 : $game2;
		$old = $time1 > $time2 ? $game2 : $game1;
	}

	// Update: Set both game keys to the same previous picked key
	if (isset($_POST['mergeConfirm']))
	{
		// Permissions: debug.update
		if (array_search("debug.update", $get['w']) === false)
		{
			echo "<p><b>Error:</b> You do not have permission to issue database update commands</p>";
			return;
		}

		// Copy alternative title to new entry if necessary
		if (!is_null($old->title2) && is_null($new->title2))
			mysqli_query($db, "UPDATE `game_list` SET `alternative_title` = '".mysqli_real_escape_string($db, $old->title2)."' WHERE `key`='{$new->key}';");

		// Copy network flag to new entry if necessary
		if ($game1->network !== $game2->network)
		{
			$network = (string) ($game1->network === 0 ? $game2->network : $game1->network);
			mysqli_query($db, "UPDATE `game_list` SET `network` = '".mysqli_real_escape_string($db, $network)."' WHERE `key`='{$new->key}';");
		}

		// Copy 3d flag to new entry if necessary
		if ($game1->stereo_3d !== $game2->stereo_3d)
		{
			$stereo_3d = (string) ($game1->stereo_3d === 0 ? $game2->stereo_3d : $game1->stereo_3d);
			mysqli_query($db, "UPDATE `game_list` SET `3d` = '".mysqli_real_escape_string($db, $stereo_3d)."' WHERE `key`='{$new->key}';");
		}

		// Copy move flag to new entry if necessary
		if ($game1->move !== $game2->move)
		{
			$move = (string) ($game1->move === 0 ? $game2->move : $game1->move);
			mysqli_query($db, "UPDATE `game_list` SET `move` = '".mysqli_real_escape_string($db, $move)."' WHERE `key`='{$new->key}';");
		}

		// Move IDs from the older entry to the newer entry
		mysqli_query($db, "UPDATE `game_id` SET `key`='{$new->key}' WHERE (`key`='{$old->key}');");
		// Reassociate old entry history updates to the newer entry
		mysqli_query($db, "UPDATE `game_history` SET `game_key`='{$new->key}' WHERE (`game_key`='{$old->key}');");
		// Delete older entry
		mysqli_query($db, "DELETE FROM `game_list` WHERE (`key`='{$old->key}');");

		// Recache status modules
		cacheStatusModules();

		echo "<b>Games successfully merged!</b><br>";
	}

	echo "</p>"; // End paragraph
}

function flag_build_as_broken() : void
{
	$pr = (isset($_POST["pr"]) && is_numeric($_POST["pr"])) ? (int) $_POST["pr"] : 0;

	$form = new HTMLForm("", "POST");
	$form->add_input(new HTMLInput("pr", "text", "{$pr}", "Pull Request"));
	$form->add_button(new HTMLButton("flag_build_as_broken", "submit", "Flag as broken"));
	$form->print();

	if (!isset($_POST["flag_build_as_broken"]) || $pr === 0)
		return;

	$db = getDatabase();
	$q_build = mysqli_query($db, "SELECT * FROM `builds` WHERE `pr` = {$pr}; ");

	if (mysqli_num_rows($q_build) === 0)
		return;

	$build = Build::query_to_builds($q_build)[0];

	$build_time = strtotime($build->merge);

	// Cannot flag builds older than a week as broken
	if (time() - $build_time > 7 * 24 * 60 * 60)
	{
		echo "<b>The build is older than a week. Cannot flag as broken.</b><br>";
		mysqli_close($db);
		return;
	}

	mysqli_query($db, "UPDATE `builds` SET `broken` = 1 WHERE `pr` = {$pr}; ");
	mysqli_close($db);

	echo "<p>Flagged <b>{$pr}</b> as broken.</p>";
}
