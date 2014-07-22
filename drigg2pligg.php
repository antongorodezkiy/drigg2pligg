<?php

	define('ADD_USERS', false); // enable users import
	define('ADD_LINKS', false); // enable links import
	define('ADD_VOTES', false); // enable votes import
	
	class DriggTable {
		public static $host = 'localhost';
		public static $user = 'root';
		public static $password = 'pass';
		public static $db = 'drigg';
		public static $prefix = '';
	}
	
	class PliggTable {
		public static $host = 'localhost';
		public static $user = 'root';
		public static $password = 'pass';
		public static $db = 'pligg';
		public static $prefix = '';
	}

	date_default_timezone_set("GMT");

	ini_set('display_errors',1);
	error_reporting(E_ALL);

	include_once('vendor/autoload.php');
	
	$logger = new Katzgrau\KLogger\Logger(__DIR__.'/logs/');

	$logger->debug('Drigg2Pligg: started');
	
	$driggDb = new mysqli(DriggTable::$host,DriggTable::$user,DriggTable::$password,DriggTable::$db);
	
	$pliggDb = new mysqli(PliggTable::$host,PliggTable::$user,PliggTable::$password,PliggTable::$db);

//====================== users ====================================
	
	if (ADD_USERS) {
		
		$logger->debug('Drigg2Pligg: adding users');
		
		$drigg_result = $driggDb->query('
			SELECT *
			FROM users
		');
		
		$logger->debug('Drigg: found users - ', array($drigg_result->num_rows));
		
		//$user_ids = array();
		
		$processed = 0;
		while ($row = $drigg_result->fetch_assoc()) {
	
			if (empty($row['mail'])) {
				continue;
			}
			
			$pligg_row = array(
				//'user_id' => $row[''],
				'user_login' => $pliggDb->real_escape_string($row['name']),
				'user_level' => 'normal',
				'user_modification' => date('Y-m-d H:i:s',$row['access']),
				'user_date' => date('Y-m-d H:i:s',$row['created']),
				'user_pass' => $row['pass'],
				'user_email' => $pliggDb->real_escape_string($row['mail']),
				'user_names' => $pliggDb->real_escape_string($row['name']),
				'user_karma' => 0,
				'user_url' => '',
				/*'user_lastlogin' => $row[''],
				'user_facebook' => $row[''],
				'user_twitter' => $row[''],
				'user_linkedin' => $row[''],
				'user_googleplus' => $row[''],
				'user_skype' => $row[''],
				'user_pinterest' => $row[''],*/
				'public_email' => $pliggDb->real_escape_string($row['mail']),
				'user_avatar_source' => $row['picture'],
				/*'user_ip' => $row[''],
				'user_lastip' => $row[''],
				'last_reset_request' => $row[''],
				'last_reset_code' => $row[''],
				'user_location' => $row[''],
				'user_occupation' => $row[''],*/
				'user_categories' => '',
				'user_enabled' => $row['status'],
				'user_language' => $row['language'],
				'data' => $pliggDb->real_escape_string($row['data']),
				/*'user_twitter_id' => $row[''],
				'user_twitter_token' => $row[''],
				'user_twitter_secret' => $row[''],
				'twitter_follow_friends' => $row['']*/
			);
			
			// check
			$existence_result = $pliggDb->query("
				SELECT *
				FROM pligg_users
				WHERE
					user_email = '".$row['mail']."'
			");
			
			if (!$existence_result || !$existence_result->num_rows) {
				$logger->debug('Pligg: adding user ', array($row['mail']));
				
				// write
				$result = $pliggDb->query("
					INSERT INTO pligg_users(".implode(',',array_keys($pligg_row)).")
					VALUES('".implode("','",array_values($pligg_row))."')
				");
	
				if (!$result) {
					$logger->error('Pligg: user exists', array($pliggDb->error));
				}
				
			}
			else {
				
				$user = $existence_result->fetch_assoc();
				//$user_ids[$row['uid']] = $user['user_id'];
				
				//$logger->debug('Pligg: user exists', array($row['mail']));
			}
			
			$processed++;
			//$logger->debug('Pligg: processed users - ', array($processed));
			$logger->debug('Pligg: progress - ', array(round(($processed / $drigg_result->num_rows) * 100)));
		}
	}
	else {
		
		$logger->debug('Drigg2Pligg: skipping adding users');
		
		$user_ids = array();
		
		$sql = '
			SELECT '.DriggTable::$db.'.users.uid, '.PliggTable::$db.'.pligg_users.user_id
			FROM '.DriggTable::$db.'.users
			LEFT JOIN '.PliggTable::$db.'.pligg_users ON '.DriggTable::$db.'.users.mail = '.PliggTable::$db.'.pligg_users.user_email
		';
		$drigg_result = $driggDb->query($sql);
		
		while ($row = $drigg_result->fetch_assoc()) {
			$user_ids[$row['uid']] = $row['user_id'];
		}
	}
	
	
//====================== categories ====================================	
	
	$categories = array(
		'News' => 1,
		'Satire' => 2,
		'Chronik' => 4,
		'Europa' => 5,
		'FunVideos' => 6,
		'Kultur' => 7,
		'Musik' => 8,
		'Oesterreich' => 9,
		'Sport' => 10,
		'Unterhaltung' => 11,
		'Web' => 12,
		'Welt' => 13,
		'Wirtschaft' => 14,
		'Wissenschaft' => 15
	);
	
//====================== links ====================================
	
	if (ADD_LINKS) {
		
		$logger->debug('Drigg2Pligg: adding links');
		
		$sql = '
			SELECT
				drigg_node.*,
				node.*,
				node_revisions.title as node_revisions_title,
				node_revisions.body,
				(
					SELECT group_concat(term_data.name)
					FROM term_data
					LEFT JOIN vocabulary
						ON vocabulary.vid = term_data.vid
					LEFT JOIN term_node
						ON term_node.tid = term_data.tid
					WHERE
						term_node.vid = node.vid
						AND term_node.nid = node.nid
						AND vocabulary.name = "Tags"
				) AS tags,
				(
					SELECT COUNT(votingapi_vote.content_id)
					FROM votingapi_vote
					WHERE
						votingapi_vote.content_id = node.nid
						AND votingapi_vote.content_type = "node"
				) AS votes
			FROM drigg_node
			LEFT JOIN node
				ON node.nid = drigg_node.dnid
			LEFT JOIN node_revisions
				ON node_revisions.vid = node.vid
			
		';
		
		$drigg_result = $driggDb->query($sql);
		
		$logger->debug('Drigg: found links - ', array($drigg_result->num_rows));
	
		$processed_links = 0;
		while ($row = $drigg_result->fetch_assoc()) {
			
			if (empty($row['url'])) {
				continue;
			}
			
			$title = (isset($row['node_revisions_title']) && $row['node_revisions_title'] ? $row['node_revisions_title'] : $row['title'] );
			
			$pligg_row = array(
				//'link_id' => $row[''],
				'link_author' => (isset($user_ids[$row['uid']]) ? $user_ids[$row['uid']] : 0),
				'link_status' => 'published',
				'link_randkey' => 0,
				'link_votes' => $row['votes'],
				//'real_link_votes' => 0,
				//'link_votes_facebook' => 0,
				//'link_votes_twitter' => 0,
				//'link_votes_google' => 0,
				'link_reports' => 0,
				'link_comments' => 0,
				//'link_karma' => $row[''],
				'link_modified' => date('Y-m-d H:i:s',$row['changed']),
				'link_date' => date('Y-m-d H:i:s',$row['promoted_on']),
				'link_published_date' => date('Y-m-d H:i:s',$row['promoted_on']),
				'link_category' => (isset($categories[$row['safe_section']]) ? $categories[$row['safe_section']] : null),
				'link_lang' => 1,
				'link_url' => $pliggDb->real_escape_string($row['url']),
				'link_url_title' => $pliggDb->real_escape_string($row['title']),
				'link_title' => $pliggDb->real_escape_string( $title ),
				'link_title_url' => $pliggDb->real_escape_string($row['title_url']),
				'link_content' => ($row['body'] ? $pliggDb->real_escape_string($row['body']) : null),
				//'link_summary' => $row[''],
				'link_tags' => $row['tags'],
				/*'link_field1' => $row[''],
				'link_field2' => $row[''],
				'link_field3' => $row[''],
				'link_field4' => $row[''],
				'link_field5' => $row[''],
				'link_field6' => $row[''],
				'link_field7' => $row[''],
				'link_field8' => $row[''],
				'link_field9' => $row[''],
				'link_field10' => $row[''],
				'link_field11' => $row[''],
				'link_field12' => $row[''],
				'link_field13' => $row[''],
				'link_field14' => $row[''],
				'link_field15' => $row[''],*/
				'link_group_id' => 0,
				'link_group_status' => 'new',
				'link_out' => 0
			);
			
			// check
			$existence_result = $pliggDb->query("
				SELECT *
				FROM pligg_links
				WHERE link_url = '".$row['url']."'
			");
			
			if (!$existence_result || !$existence_result->num_rows) {
				$logger->debug('Pligg: adding link ', array($row['url']));
				
				// write
				$result = $pliggDb->query("
					INSERT INTO pligg_links(".implode(',',array_keys($pligg_row)).")
					VALUES('".implode("','",array_values($pligg_row))."')
				");
				
				if (!$result) {
					$logger->error('Pligg: user exists', array($pliggDb->error));
				}
			}
			else {
				$logger->debug('Pligg: link exists', array($row['url']));
			}
			
			$processed_links++;
			$logger->debug('Pligg: processed links - ', array($processed_links));
			$logger->debug('Pligg: progress - ', array(round(($processed_links / $drigg_result->num_rows) * 100)));
		}
	}
	else {
		$logger->debug('Drigg2Pligg: skipping adding links');
		
		$links_ids = array();
		
		$sql = '
			SELECT '.DriggTable::$db.'.node.nid, '.PliggTable::$db.'.pligg_links.link_id
			FROM '.DriggTable::$db.'.drigg_node
			LEFT JOIN node
				ON node.nid = drigg_node.dnid
			LEFT JOIN '.PliggTable::$db.'.pligg_links
				ON '.DriggTable::$db.'.drigg_node.url='.PliggTable::$db.'.pligg_links.link_url
		';
		
		$drigg_result = $driggDb->query($sql);
		
		while ($row = $drigg_result->fetch_assoc()) {
			$links_ids[$row['nid']] = $row['link_id'];
		}
	}
	
	
//====================== votes ====================================	
	
	
/*	if (ADD_VOTES) {
		
		$logger->debug('Drigg2Pligg: adding votes');
		
		$sql = '
			SELECT *
			FROM votingapi_vote
			WHERE votingapi_vote.content_type = "node"
		';
		$drigg_result = $driggDb->query($sql);
		
		$logger->debug('Drigg: found votes - ', array($drigg_result->num_rows));
	
		$processed = 0;
		while ($row = $drigg_result->fetch_assoc()) {


			$pligg_row = array(
				//'vote_id' => $row[''],
				'vote_type' => 'links',
				'vote_date' => date('Y-m-d H:i:s',$row['timestamp']),
				'vote_link_id' => $links_ids[$row['content_id']],
				'vote_user_id' => $user_ids[$row['uid']],
				'vote_value' => 10,
				'vote_karma' => 10,
				//'vote_ip ' => null
			);
			


			// write
			$result = $pliggDb->query("
				INSERT INTO pligg_votes(".implode(',',array_keys($pligg_row)).")
				VALUES('".implode("','",array_values($pligg_row))."')
			");

			
			$processed++;
			$logger->debug('Pligg: processed votes - ', array($processed));
			$logger->debug('Pligg: progress - ', array(round(($processed/ $drigg_result->num_rows) * 100)));
		}
	}
	else {
		$logger->debug('Drigg2Pligg: skipping adding votes');
	}*/
	
	
	
	$logger->debug('Drigg2Pligg: stopped');
