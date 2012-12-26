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
	// Hooks and definitions
	if(THIS_SCRIPT == 'xmlhttp.php')
	{
		$plugins->add_hook('parse_message_end', 'ougc_usertagging_parse_message');
	}
	elseif(in_array(str_replace('.php', '', THIS_SCRIPT), array('editpost', 'private', 'newreply', 'newthread', 'showthread')))
	{
		$plugins->add_hook('pre_output_page', 'ougc_usertagging_end');
		$plugins->add_hook('parse_message_end', 'ougc_usertagging_parse_message');
	}
	$plugins->add_hook('datahandler_post_update', 'ougc_usertagging_post_update');
	$plugins->add_hook('datahandler_post_insert_thread_post', 'ougc_usertagging_post_insert');
	$plugins->add_hook('datahandler_post_insert_post', 'ougc_usertagging_post_insert');
}

// Tag Regex *
define('OUGC_USERTAGGING_REGEXREGEX', '#(\@(")?)(\w{1,20})[\@|"]#i'); // usertagging

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

	$mthod = 'add_column';
	if($db->field_exists('usertags', 'posts'))
	{
		$mthod = 'modify_column';
	}
	$db->$mthod('posts', 'usertags', 'TEXT NOT NULL');
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

// Parse a message
function ougc_usertagging_parse_message(&$message)
{
	// *
	if(my_strpos($message, '@') !== false)
	{
		global $mybb;
		$message = preg_replace_callback(OUGC_USERTAGGING_REGEXREGEX, 'ougc_usertagging_parse_user', $message);
		
		if((bool)$mybb->input['ajax'] || THIS_SCRIPT == 'xmlhttp.php')
		{
			ougc_usertagging_end($message);
		}
	}
}

// Parse a username *
function ougc_usertagging_parse_user($match)
{
	// *
	if(empty($match[3]) || $match[1] != '@"')
	{
		return (!empty($match[0]) ? $match[0] : '');
	}

	global $ougc_usertagging;

	return '<--@'.$ougc_usertagging->set_tag($match[3]).'-->';
}

// Replace all placeholders
function ougc_usertagging_end(&$page)
{
	global $ougc_usertagging, $plugins;

	// *
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

	$plugins->run_hooks('ougc_usertagging_end', $replacements);

	if($replacements['search'] && $replacements['replace'])
	{
		$page = str_replace($replacements['search'], $replacements['replace'], $page);
	}
}

// Update a post tags
function ougc_usertagging_post_update(&$dh)
{
	global $ougc_usertagging, $db;
	$new_ids = $old_ids = $cache_ids = array();

	// Get new post tags
	$new_message = preg_replace_callback(OUGC_USERTAGGING_REGEXREGEX, 'ougc_usertagging_parse_user', $dh->data['message']);

	$query = $db->simple_select('users', 'uid', 'username IN (\''.implode('\', \'', array_map(array($db, 'escape_string'),  array_keys($ougc_usertagging->get_tags()))).'\')');
	while($uid = $db->fetch_field($query, 'uid'))
	{
		$new_ids[(int)$uid] = (int)$uid;
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
		isset($new_ids[$uid]) or $ougc_usertagging->remove_tag($uid);
	}

	foreach($new_ids as $uid)
	{
		isset($old_ids[$uid]) or $ougc_usertagging->add_tag($uid);
	}

	$dh->post_update_data['usertags'] = implode(',', array_values($new_ids));
}

// Insert a post and add tags
function ougc_usertagging_post_insert(&$dh)
{
	global $db, $ougc_usertagging, $plugins;
	$uids = array();

	// Get new post tags
	$new_message = preg_replace_callback(OUGC_USERTAGGING_REGEXREGEX, 'ougc_usertagging_parse_user', $dh->data['message']);

	$query = $db->simple_select('users', 'uid', 'username IN (\''.implode('\', \'', array_map(array($db, 'escape_string'),  array_keys($ougc_usertagging->get_tags()))).'\')');
	while($uid = $db->fetch_field($query, 'uid'))
	{
		$uids[(int)$uid] = (int)$uid;
	}

	foreach($uids as $uid)
	{
		$ougc_usertagging->add_tag($uid);
	}

	$dh->post_insert_data['usertags'] = implode(',', array_values($uids));
}

// Our funny class :P
class OUGC_UserTagging
{
	private $tags = array();

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

	// Add a tag hook
	function add_tag($uid)
	{
		global $plugins;

		$plugins->run_hooks('ougc_usertagging_add_tag', $uid);
	}

	// Remove a tag hook
	function remove_tag($uid)
	{
		global $plugins;

		$plugins->run_hooks('ougc_usertagging_remove_tag', $uid);
	}
}
$GLOBALS['ougc_usertagging'] = new OUGC_UserTagging;

// * Partial or full code taken from Tomm M.'s Post Tagging module for MyNetwork.
//    http://resources.xekko.co.uk/mynetwork/features/post-tagging.html