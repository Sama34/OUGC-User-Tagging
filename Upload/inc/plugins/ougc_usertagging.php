<?php

/***************************************************************************
 *
 *   OUGC User Tagging plugin (/inc/plugins/ougc_customrep.php)
 *	 Author: Omar Gonzalez
 *   Copyright: © 2012 Omar Gonzalez
 *   
 *   Website: http://community.mybb.com/user-25096.html
 *
 *   Allow users to tag users inside posts.
 *
 ***************************************************************************
 
****************************************************************************
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
****************************************************************************/

// Die if IN_MYBB is not defined, for security reasons.
defined('IN_MYBB') or die('Direct initialization of this file is not allowed.');

// Add our hook
if(!defined('IN_ADMINCP') && defined('THIS_SCRIPT'))
{
	global $mybb;

	$page = str_replace('.php', '', THIS_SCRIPT);

	if(THIS_SCRIPT == 'xmlhttp.php' && $mybb->input['action'] == 'edit_post' && $mybb->input['do'] == 'update_post')
	{
		$plugins->add_hook('parse_message_end', 'ougc_usertagging_parse_message');
	}
	elseif(in_array($page, array('editpost', 'private', 'newreply', 'newthread', 'showthread')))
	{
		$plugins->add_hook('parse_message_end', 'ougc_usertagging_parse_message');
		$plugins->add_hook('pre_output_page', 'ougc_usertagging_end');
	}
}

$plugins->add_hook('datahandler_post_update', 'ougc_usertagging_post_update');
$plugins->add_hook('datahandler_post_insert_thread_post', 'ougc_usertagging_post_insert');
$plugins->add_hook('datahandler_post_insert_post', 'ougc_usertagging_post_insert');

// MyAlerts integration
$plugins->add_hook('editpost_do_editpost_end', 'ougc_usertagging_alert');
$plugins->add_hook('newreply_do_newreply_end', 'ougc_usertagging_alert');
$plugins->add_hook('newthread_do_newthread_end', 'ougc_usertagging_alert');
$plugins->add_hook('myalerts_alerts_output_start', 'ougc_usertagging_alert_display');
$plugins->add_hook('myalerts_possible_settings', 'ougc_usertagging_alert_settings');

// Tag Regex *
define('OUGC_USERTAGGING_REGEX', '#(\@(")?)(\w{1,20})[\@|"]#i'); // usertagging

/*
	'#(\@(")?)(.+?)[\@|"]#i' - usertagging
	'/@"([^<]+?)"|@([^\s<)]+)/' - gpl
	'/@([A-Za-z0-9_]{1,15})/' - http://stackoverflow.com/questions/4424179/twitter-username-regex-validation
	'/(^|[^a-z0-9_])[@@]([a-z0-9_]{1,20})([@@\xC0-\xD6\xD8-\xF6\xF8-\xFF]?)/iu'
	'/@"([^<]+?)"|@([^\s<)]+)/' - http://mods.mybb.com/view/mention
*/

// Plugin API
function ougc_usertagging_info()
{
	return array(
		'name'          => 'OUGC User Tagging',
		'description'   => 'Allow users to tag users inside posts.',
		'website'		=> 'http://mods.mybb.com/profile/25096',
		'author'		=> 'Omar Gonzalez',
		'authorsite'	=> 'http://community.mybb.com/user-25096.html',
		'version'		=> '1.0',
		'compatibility'	=> '16*',
		'guid'			=> ''
	);
}

// _install
function ougc_usertagging_install()
{
	global $db;

	$method = 'add_column';
	if($db->field_exists('usertags', 'posts'))
	{
		$method = 'modify_column';
	}
	$db->$method('posts', 'usertags', 'TEXT NOT NULL');
}

// _is_installed
function ougc_usertagging_is_installed()
{
	global $db;

	return $db->field_exists('usertags', 'posts');
}

// _uninstall
function ougc_usertagging_uninstall()
{
	global $db;

	$db->drop_column('posts', 'usertags');
}

// Post parsing
function ougc_usertagging_parse_message(&$message)
{
	if(my_strpos($message, '@') !== false)
	{
		$message = preg_replace_callback(OUGC_USERTAGGING_REGEX, 'ougc_usertagging_parse_user', $message);

		global $mybb;

		if((bool)$mybb->input['ajax'] || THIS_SCRIPT == 'xmlhttp.php')
		{
			ougc_usertagging_end($message);
		}
	}
}

// Parse a username *
function ougc_usertagging_parse_user($match)
{
	if(empty($match[3]) || $match[1] != '@"')
	{
		return (!empty($match[0]) ? $match[0] : '');
	}

	global $ougc_usertagging;

	return '<--@'.$ougc_usertagging->set_tag($match[3]).'-->';
}

// Replace all placeholders *
function ougc_usertagging_end(&$page)
{
	global $ougc_usertagging;

	if(!($tags = $ougc_usertagging->get_tags()))
	{
		return;
	}

	global $db;

	// Get tagged users
	$query = $db->simple_select('users', 'uid, username, usergroup, displaygroup', 'username IN (\''.implode('\', \'', array_map(array($db, 'escape_string'),  array_keys($tags))).'\')');

	$replacements = array('search' => array(), 'replace' => array());
	if($db->num_rows($query))
	{
		// Lets build the array replacements
		while($user = $db->fetch_array($query))
		{
			$user['username'] = htmlspecialchars_uni($user['username']);
			$username = format_name($user['username'], $user['usergroup'], $user['displaygroup']);

			$replacements['search'][(int)$user['uid']] = '<--@'.$user['username'].'-->';
			$replacements['replace'][(int)$user['uid']] = '@'.build_profile_link($username, $user['uid']);

			$ougc_usertagging->unset_tag($user['username']);
		}
	}

	$leftovers = $ougc_usertagging->get_tags();
	foreach($leftovers as $username)
	{
		$replacements['search'][] = '<--@'.$username.'-->';
		$replacements['replace'][] = '@'.$username;
	}

	if($replacements['search'] && $replacements['replace'])
	{
		$page = str_replace($replacements['search'], $replacements['replace'], $page);
	}
}

// Update a post tags
function ougc_usertagging_post_update(&$dh)
{
	global $ougc_usertagging, $db;
	$new_ids = $old_ids = array();

	// Get new post tags
	$new_message = preg_replace_callback(OUGC_USERTAGGING_REGEX, 'ougc_usertagging_parse_user', $dh->data['message']);

	$query = $db->simple_select('users', 'uid', 'username IN (\''.implode('\', \'', array_map(array($db, 'escape_string'),  array_keys($ougc_usertagging->get_tags()))).'\')');
	while($uid = $db->fetch_field($query, 'uid'))
	{
		if(($uid = (int)$uid) != $mybb->user['uid'])
		{
			$new_ids[$uid] = $uid;
		}
	}

	$ougc_usertagging->reset_tags();

	// Get the old ones
	$old_post = get_post($dh->data['pid']);
	$uids = explode(',', $old_post['usertags']);
	if(isset($uids[1]) || (!isset($uids[1]) && $uids[0] != 0))
	{
		foreach($uids as $uid)
		{
			$old_ids[(int)$uid] = (int)$uid;
		}
	}

	foreach($old_ids as $uid)
	{
		isset($new_ids[$uid]) or ($ougc_usertagging->remove_tags[(int)$uid] = (int)$uid);
	}

	foreach($new_ids as $uid)
	{
		isset($old_ids[$uid]) or ($ougc_usertagging->add_tags[(int)$uid] = (int)$uid);
	}

	#$uids = implode(',', array_merge(array_values($new_ids), $old_ids));
	$uids = implode(',', array_values($new_ids));
	$dh->post_update_data['usertags'] = $db->escape_string($uids);

	$ougc_usertagging->reset_tags();
}

// Insert a post and add tags
function ougc_usertagging_post_insert(&$dh)
{
	global $db, $ougc_usertagging, $plugins;

	// Get new post tags
	$new_message = preg_replace_callback(OUGC_USERTAGGING_REGEX, 'ougc_usertagging_parse_user', $dh->data['message']);

	$query = $db->simple_select('users', 'uid', 'username IN (\''.implode('\', \'', array_map(array($db, 'escape_string'),  array_keys($ougc_usertagging->get_tags()))).'\')');
	while($uid = $db->fetch_field($query, 'uid'))
	{
		$ougc_usertagging->add_tags[(int)$uid] = (int)$uid;
	}

	$ougc_usertagging->reset_tags();
}

// Insert a new reply, send an alert
function ougc_usertagging_alert()
{
	global $postinfo, $Alerts, $ougc_usertagging;

	if(THIS_SCRIPT == 'newreply.php')
	{
		$postinfo = $GLOBALS['thread_info'];
	}
	elseif(THIS_SCRIPT == 'editpost.php')
	{
		$pid = $GLOBALS['posthandler']->pid;
	}

	// post is validated, check at approving-draf
	if($postinfo['visible'] == 1 && !empty($Alerts) && $Alerts instanceof Alerts && $ougc_usertagging->add_tags)
	{
		global $mybb;
		isset($pid) or ($pid = $postinfo['pid']);

		$uids = array_values($ougc_usertagging->add_tags);
		$Alerts->addMassAlert($uids, 'ougc_usertagging', $pid, $mybb->user['uid']);
	}
}

// Format the alert
function ougc_usertagging_alert_display(&$alert)
{
	global $mybb;

	if($alert['type'] == 'ougc_usertagging' && !empty($mybb->user['myalerts_settings']['ougc_usertagging']))
	{
		global $lang;
		isset($lang->ougc_usertagging_alert) or $lang->ougc_usertagging_alert = '{1} has tagged you in a post.';

		$postlink = $mybb->settings['bburl'].'/'.get_post_link($alert['tid']).'#pid'.$alert['tid'];

		$alert['message'] = $lang->sprintf($lang->ougc_usertagging_alert, $alert['user'], $postlink);
		$alert['rowType'] = 'ougc_usertagging';
	}
}

// Possible alert settings
function ougc_usertagging_alert_settings(&$settings)
{
	global $lang;

	isset($lang->myalerts_setting_ougc_usertagging) or $lang->myalerts_setting_ougc_usertagging = 'Receive an alert when tagged in a post?';

	$settings[] = 'ougc_usertagging';
}

// Our funny class :P
class OUGC_UserTagging
{
	
	// Current page tags
	private $tags = array();

	// Current array of users removed from a post
	public $remove_tags = array();

	// Current array of users tagged in a post
	public $add_tags = array();

	// Set a tag to be parsed
	function set_tag($username)
	{
		return ($this->tags[$username] = $username);
	}

	// Get all tags
	function get_tags()
	{
		return $this->tags;
	}

	// Unset a tag
	function unset_tag($username)
	{
		unset($this->tags[$username]);
	}

	// Reset (remove) all tags
	function reset_tags()
	{
		$this->tags = array();
	}
}
$GLOBALS['ougc_usertagging'] = new OUGC_UserTagging;

// * Partial or full code taken from Tomm M.'s Post Tagging module for MyNetwork.
//    http://resources.xekko.co.uk/mynetwork/features/post-tagging.html