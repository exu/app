<?php

/*
 * Wall notifications allows us to manage notifications about new messages
 * and replies on users Walls
 * 
 * Interface
 *  single instance of WallNotifications to interact with
 *  WallNotificationEntity - single notification data, 
 *    only one exists for every notification event, but is linked from
 *    every user who is interested in specific notification
 * 
 */


class WallNotifications {
	private $removedEntities;
	
	public function __construct($isMain = true) {
		$this->app = F::App();
		$this->removedEntities = array();
		//$db = $this->getDB(true);
	}
	
	/*
	 * Public Interface
	 */
	
	public function getWikiNotifications($userId, $wikiId, $readSlice = 5, $countonly = false ) {
		/* if since == null, get all notifications */	
		/* possibly ignore $wiki at one point and fetch notifications from all wikis */

		$memcSync = $this->getCache($userId, $wikiId);
		$list = $this->getData($memcSync, $userId, $wikiId);
		
		if(empty($list)) {
			return array();
		}
		
		$read = array();
		$unread = array();
		foreach(array_reverse($list['notification']) as $listval) {	
			if(!empty($listval)) {
				if(!$countonly) {
					$grouped = $this->groupEntity($list['relation'][ $listval ]['list']);					
				} else {
					$grouped = array();
				}
				if(!empty($grouped) || $countonly) {
					if($list['relation'][ $listval ]['read']){
						if(count($read) < $readSlice){
							$read[] = array(
								"grouped" => $grouped,
								"count" => empty($list['relation'][ $listval ]['count']) ? count($list['relation'][ $listval ]['list']) : $list['relation'][ $listval ]['count'] 
							);
						} elseif( $readSlice > 0 ) {
							// so we have more read notifications that we need for display
							// remove them
							$this->remNotificationsForUniqueID( $userId, $wikiId, $listval );
						}
					} else {
						$unread[] = array(
							"grouped" => $grouped,
							"count" => empty($list['relation'][ $listval ]['count']) ? count($list['relation'][ $listval ]['list']) : $list['relation'][ $listval ]['count']
						);
					}
				}
			}
		}
		
		// we are only ever asked for notifications for Wikis that are on WikiList
		// so if there are no unread notifications for that Wiki it should not
		// be on that list (this will save us some work for checking & fetching
		// notifications that are in fact, empty, since current Wiki is
		// an exception and is always checked this works for read notification
		// as well)
		if( count($unread) == 0 ) {
			$this->remWikiFromList( $userId, $wikiId );
		}
		
		$user = User::newFromId( $userId );
		if( in_array( 'sysop', $user->getEffectiveGroups() ) ) { //TODO: ???
			$wna = new WallNotificationsAdmin;
			$unread = array_merge( $wna->getAdminNotifications( $wikiId, $userId ), $unread );
		}

		$wno = new WallNotificationsOwner;
		$unread = array_merge( $wno->getOwnerNotifications( $wikiId, $userId ), $unread );

		$unread = $this->sortByTimestamp($unread);
		
		$out = array(
			'unread'=> $unread,
			'unread_count' => count($unread),
			'read' => $read,
			'read_count' => count($read)
		);
		return $out; 
	}

	public function getCounts($userId, $forceCurrentWiki = true) {
		$wikiList = $this->getWikiList($userId, $forceCurrentWiki);
		
		$output = array();
		
		foreach($wikiList as $wiki) {
			$wiki['unread'] = $this->getCount($userId, $wiki['id']);
			
			// show only Wikis with unread notifications
			// current Wiki is an exception (show always)
			if( $wiki['unread'] > 0 || $wiki['id'] == $this->app->wg->CityId )
				$output[] = $wiki;
		}
		return $output;
	}
	
	private function sortByTimestamp($array) {
		uasort($array, array($this, 'sortByTimestampCB'));
		
		return $array;
	}
	
	private function sortByTimestampCB($a, $b) {
		if( !empty($a['grouped']) && !empty($b['grouped']) ) {
			if( $a['grouped'][0]->data->timestamp > $b['grouped'][0]->data->timestamp ) {
				return -1;
			}
		}
		return 1;
	}
	
	private function getCount($userId, $wikiId) {
		// fixme
		// should not to do the whole work of WikiNotifications
		$notifs = $this->getWikiNotifications($userId, $wikiId, 5, true );
		return $notifs['unread_count'];
	}
	
	private function getWikiList($userId, $forceCurrentWiki = true) {
		// $forceCurrentWiki = true - always return current wiki as part of the list
		// $forceCurrentWiki = false - return only wikis that ever recived notifications
		
		$key = $this->getKey($userId, 'LIST');
		$val = $this->app->getGlobal('wgMemc')->get($key);
		
		if(empty($val)) {
			$val = $this->loadWikiListFromDB($userId);
			$this->app->getGlobal('wgMemc')->set($key, $val);
		}

		// make sure that current Wiki is on the list, as first entry, sort the rest
		asort($val);
		if($forceCurrentWiki === true) {
			unset($val[ $this->app->wg->CityId ]);
			$output = array( array( 
				'id' => $this->app->wg->CityId, 
				'wgServer' => $this->getWgServer($this->app->wg->CityId),
				'sitename' => $this->app->wg->sitename) );
		} else {
			$output = array();
		}
		foreach($val as $wikiId => $wikiSitename) {
			$output[] = array( 
				'id' => $wikiId, 
				'wgServer' => $this->getWgServer($wikiId),
				'sitename' => $wikiSitename
			);
		}
		return $output;
			
	}

	
	private function getWgServer($id) {
		$url = WikiFactory::getVarValueByName("wgServer", $id );
		if (!empty($this->app->wg->DevelEnvironment)) {
			$url = str_replace('wikia.com', $this->app->wg->DevelEnvironmentName.'.wikia-dev.com',$url);
		}
		
		//HACK for preview
		//TODO: create helper general function for
		$hosts = array('verify', 'preview', 'sandbox-s1'); 
		foreach($hosts as $host) {
			$prefix = 'http://'.$host.'.';
			if(strpos($this->app->wg->Server, $prefix)  !== false ) {
				$url = str_replace('http://', $prefix, $url );
			}			
		}
		
		return $url;
	}
	

	private function addWikiToList($userId, $wikiId, $wikiSitename) {
		$key = $this->getKey($userId, 'LIST');
		$val = $this->app->getGlobal('wgMemc')->get($key);
		
		if(empty($val)) {
			$val = $this->loadWikiListFromDB($userId);
		}
		
		$val[$wikiId] = $wikiSitename;
		
		$this->app->getGlobal('wgMemc')->set($key, $val);
		
	}

	private function remWikiFromList($userId, $wikiId) {
		// TODO / FIXME
		// Currently there is a race condition in WikiList.
		// Access to memcache key is not synchronized,
		// waiting for new memcache implementation code
		// that supports update-if-key-did-not-change
		
		$key = $this->getKey($userId, 'LIST');
		$val = $this->app->getGlobal('wgMemc')->get($key);
		
		if(empty($val)) {
			// removing Wiki from list is just speed optimization
			// if list is not cached in memory there is no
			// need to recreate it from DB
		} else {
			unset( $val[$wikiId] );
			$this->app->getGlobal('wgMemc')->set($key, $val);
		}
	}
	
	private function loadWikiListFromDB($userId) {
		$db = $this->getDB(false);
		$res = $db->select('wall_notification',
			array('distinct wiki_id'),
			array(
				'user_id' => $userId,
			),
			__METHOD__
		);
		$output = array();
		while($row = $db->fetchRow($res)) {
			$output[ $row['wiki_id'] ] = $sitename = WikiFactory::getWikiByID( $row['wiki_id'] )->city_title;
		}
		return $output;
	}
	
	protected function groupEntity($list){
		$grouped = array();
		foreach(array_reverse($list) as $obj ) {
			$notif = F::build('WallNotificationEntity', array($obj['entityKey']), 'getById');
			if(!empty($notif))
				$grouped[] = $notif;
		}
		return $grouped;
	}

	public function addNotification($notif) {
		if(!empty($notif)) {
			$this->notifyEveryone($notif);
		}
	}

	/*
	 * Helper functions
	 */
	
	public function notifyEveryone($notification) {
		$users = array();
		
		if(empty($notification->data_noncached) || empty($notification->data_noncached->parent_title_dbkey)) {
			$title = "";
		} else {
			$title = $notification->data_noncached->parent_title_dbkey;
		}
		
		$users = $this->getWatchlist($notification->data->wall_username, $title);
	
		//FB:#11089
		$users[$notification->data->wall_userid] = $notification->data->wall_userid;
		
		if(!empty($users[$notification->data->msg_author_id])){
			unset($users[$notification->data->msg_author_id]);	
		} 

		$title = Title::newFromText($notification->data->wall_username. '/' . $notification->data->title_id, NS_USER_WALL ); 
		$this->sendEmails(array_keys($users), $notification );
		$this->addNotificationLinks($users, $notification);
	}
	
	protected function createKeyForMailNotification($watcher, $notification) {
		$key = 'mail-notification-';

		if($notification->isMain()) {
			$key .= 'new-';
			if($watcher == $notification->data->wall_userid ) {
				$key .= 'your';
			} else {
				$key .= 'someone';
			}
		} else {
			$key .= 'reply-';
			
			if( $watcher == $notification->data->parent_user_id ) {
				$key .= 'your';
			} elseif( $notification->data->msg_author_id == $notification->data->parent_user_id && $notification->data->msg_author_id != 0 ) {
				$key .= 'his';
			} else {
				$key .= 'someone';
			}	
		}
		return $key;
	}
	
	protected function sendEmails($watchers, $notification) {
		$watchersOut = array();
		
		$text = strip_tags($notification->data_non_cached->msg_text, '<p><br>');
		$text = substr($text,0,3000).( strlen($text) > 3000 ? '...':'');
		
		$textNoHtml = preg_replace('#<br\s*/?>#i', "\n", $text);
		$textNoHtml = trim(preg_replace('#</?p\s*/?>#i', "\n", $textNoHtml));
		$textNoHtml = substr($textNoHtml,0,3000).( strlen($textNoHtml) > 3000 ? '...':'');
		
		foreach($watchers as $val){
			$watcher = User::newFromId($val);
			if( $watcher->getId() != 0 && ($watcher->getOption('enotifwallthread') )
				|| ($watcher->getOption('enotifmywall') && $notification->data->wall_userid == $watcher->getId())
			) {
				$key = $this->createKeyForMailNotification( $watcher->getId(), $notification );
				
				$watcherName = $watcher->getRealName();
				if(empty($watcherName)) {
					$watcherName = $watcher->getName();	
				}
				
				if( $notification->data->msg_author_username == $notification->data->msg_author_displayname) {
					$author_signature = $notification->data->msg_author_username;
				} else {
					$author_signature = $notification->data->msg_author_displayname . '(' . $notification->data->msg_author_username . ')';
				}
				
				$data = array(
					'$WATCHER' => $watcherName,
					'$WIKI' => $notification->data->wikiname,
					'$PARENT_AUTHOR_NAME' => (empty($notification->data->parent_displayname) ? '':$notification->data->parent_displayname),
					'$AUTHOR_NAME' => $notification->data->msg_author_displayname,
					'$AUTHOR' => $notification->data->msg_author_username,
					'$AUTHOR_SIGNATURE' => $author_signature,
					'$MAIL_SUBJECT' => wfMsg('mail-notification-subject', array(
						'$1' => $notification->data->thread_title,
						'$2' => $notification->data->wikiname
					)),
					'$METATITLE' => $notification->data->thread_title,
					'$MESSAGE_LINK' =>  $notification->data->url,
					'$MESSAGE_NO_HTML' =>  $textNoHtml,
					'$MESSAGE_HTML' =>  $text,
				);
				if($user->getBoolOption('unsubscribed') === true) {
					$this->sendEmail($watcher, $key, $data);	
				}
			}
		}
		
		return true;
	}
	
	protected function sendEmail($watcher, $key, $data ) {
		$from = new MailAddress( $this->app->wg->PasswordSender, 'Wikia' );
		$replyTo = new MailAddress ( $this->app->wg->NoReplyAddress );
		
		$keys = array_keys($data);
		$values =  array_values($data);
		
		$subject = wfMsgForContent($key);
		
		$text = wfMsgForContent('mail-notification-body');
		
		$subject = str_replace($keys, $values, $subject);
		
		$keys[] = '$SUBJECT';
		$values[] = $subject;
		$data['$SUBJECT'] = $subject; 
		$html = wfRenderPartial('WallExternal', 'mail', array('data' => $data));
		$text = str_replace($keys, $values, $text);
		
		return $watcher->sendMail( $data['$MAIL_SUBJECT'], $text, $from, $replyTo, 'WallNotification', $html );
	}

	protected function getWatchlist($name, $titleDbkey) {
		//TODO: add some caching
		$userTitle = Title::newFromText( $name, NS_USER_WALL );
		
		$dbw = $this->getLocalDB(true);
		$res = $dbw->select( array( 'watchlist' ),
			array( 'wl_user' ),
			array(
				'wl_title' => array($titleDbkey, $userTitle->getDBkey() ),
				'wl_namespace' => array(NS_USER_WALL, NS_USER_WALL_MESSAGE)
			), __METHOD__
		);
		
		$users = array();
		while ($row = $dbw->fetchObject( $res ) ) {
			$userId = intval( $row->wl_user );
			$users[$userId] = $userId;
		}
		return $users;
	}	
		
	protected function addNotificationLinks(Array $userIds, $notification) {
		foreach($userIds as $userId) {
			$this->addNotificationLink($userId, $notification);
			$this->addWikiToList($userId, $this->app->wg->CityId, $this->app->wg->sitename);
		}
	}

	protected function addNotificationLink($userId, $notification) {
		$this->addNotificationLinkInternal(
			$userId,
			$notification->data->wiki,
			$notification->getUniqueId(),
			$notification->getId(), 
			$notification->data->msg_author_id,
			!$notification->isMain()
		);
	}
	
	public function markRead($userId, $wikiId, $id = 0, $ts = 0) {
		$updateDBlist = array(); // we will update database AFTER unlocking
		
		$wasUnread = false; // function returns True if in fact there was unread
							// notification

		$memcSync = $this->getCache($userId, $wikiId);
		do {
			$count = 0; //use to set priority of process 
			if($memcSync->lock()) {
				$data = $this->getData($memcSync, $userId, $wikiId);
			
				if($id == 0 && !empty( $data['relation'] ) ) {
					$ids = array_keys( $data['relation'] );	
				} else {
					$ids = array( $id ); 
				}
				
				foreach($ids as $value) {
					if(!empty($data['relation'][ $value])) {
						if($data['relation'][ $value ]['read'] == false) {
							$wasUnread = true;
							$data['relation'][ $value ]['read'] = true;	

							$updateDBlist[] = array(
										'user_id' => $userId,
										'wiki_id' => $wikiId,
										'unique_id' => $value
							);
							
						}
					}			
				}
			} else {
				$this->sleep($count);
			}
			$count++;
		} while(!isset($data) || !$this->setData($memcSync, $data));
		
		$memcSync->unlock();
		
		foreach($updateDBlist as $value) {
			$this->getDB(true)->update('wall_notification' , array('is_read' =>  1 ), $value, __METHOD__ );
		}
		
		if( $id === 0 ) {
			$user = User::newFromId( $userId );
			if( $user instanceof User &&
			    ( in_array( 'sysop', $user->getEffectiveGroups() ) ||
			      in_array( 'staff', $user->getEffectiveGroups() )	 ) ) {
				$wna = new WallNotificationsAdmin;
				$wasUnread = $wasUnread || $wna->hideAdminNotifications( $wikiId, $userId );
			}
			$wno = new WallNotificationsOwner;
			$wasUnread = $wasUnread || $wno->removeAll( $wikiId, $userId );
		}
		
		return $wasUnread;
	}

	public function markReadAllWikis( $userId ) {
		$list = $this->getWikiList( $userId );
		foreach( $list as $wikiData ) {
			$this->markRead( $userId, $wikiData['id'] );
		}
	}
	
	public function remNotificationsForUniqueID($userId, $wikiId, $uniqueId, $hide = false) {
		// hide = true - able to restore them later (after reenabling in DB and than rebuilding from DB)
		// hide = false - remove permanently, no ability to restore it

		if( empty( $userId ) ) {
			$users = $this->getUsersWithNotificationsForUniqueID($wikiId, $uniqueId);
		} else {
			$users = array( $userId );
		}
		
		
		foreach( $users as $uId ) {		
		
			if($this->isCachedData($uId, $wikiId)) {
				$memcSync = $this->getCache($uId, $wikiId);
				do {
					$count = 0; //use to set priority of process 
					if($memcSync->lock()) {
						$data = $this->getData($memcSync, $uId, $wikiId);
						$this->remNotificationFromData($data, $uniqueId);
					} else {
						$this->sleep($count);
					}
					$count++;
				} while(!isset($data) || !$this->setData($memcSync, $data));
				
				$memcSync->unlock();
			}
	
			$this->remNotificationsForUniqueIDDB($uId, $wikiId, $uniqueId, $hide);
			
		}

	}
	
	private function getUsersWithNotificationsForUniqueID( $wikiId, $uniqueId ) {
		
		$db = $this->getDB(true);
		
		$res = $db->select('wall_notification',
			array('distinct user_id'),
			array(
				'wiki_id' => $wikiId,
				'unique_id' => $uniqueId
			),
			__METHOD__,
			array( "GROUP BY" => "user_id" )
		);
	
		$users = array();
		while($row = $db->fetchRow($res)) {
			$users[] = $row['user_id'];
		}
		return $users;
	}

	public function unhideNotificationsForUniqueID($wikiId, $uniqueId) {

		$this->unhideNotificationsForUniqueIDDB($wikiId, $uniqueId);
		
		// find which users had notifications for that uniqueId and nuke their
		// in-memory representations
		
		$users = $this->getUsersWithNotificationsForUniqueID( $wikiId, $uniqueId );
		
		foreach( $users as $user ) {
			$this->forceRebuild( $user, $wikiId );
		}

	}
	
	protected function remNotificationsForUniqueIDDB($userId, $wikiId, $uniqueId, $hide = false) {
		$where = array(
			'user_id' => $userId,
			'wiki_id' => $wikiId,
			'unique_id' => $uniqueId
		);
		
		if( $hide === true ) {
			$this->getDB(true)->update('wall_notification' , array('is_hidden' =>  1 ), $where, __METHOD__ );
		} else {
			$this->getDB(true)->delete('wall_notification' , $where, __METHOD__ );
		}
	}

	protected function unhideNotificationsForUniqueIDDB($wikiId, $uniqueId) {
		$where = array(
			'wiki_id' => $wikiId,
			'unique_id' => $uniqueId
		);
		
		$this->getDB(true)->update('wall_notification' , array('is_hidden' => 0 ), $where, __METHOD__ );
	}
	
	protected function remNotificationDB($userId, $wikiId, $uniqueId, $entityId) {
		$where = array(
			'user_id' => $userId,
			'wiki_id' => $wikiId,
			'unique_id' => $uniqueId,
			'entity_id' => $entityId
		);
		
		$this->getDB(true)->delete('wall_notification' , $value, __METHOD__ );
	}

	protected function addNotificationLinkInternal($userId, $wikiId, $uniqueId, $entityKey, $authorId, $isReply ) {
		if($userId < 1) {
			return true;
		}
		$this->storeInDB($userId, $wikiId, $uniqueId, $entityKey, $authorId, $isReply); 
		//id use to prevent having of extra entry after memc fail.   
		
		// if the object is in memory, update it, if not, skip it (it will be rebuild from db at some point anyway)
		if($this->isCachedData($userId, $wikiId)) {
			$memcSync = $this->getCache($userId, $wikiId);
			do {
				$count = 0; //use to set priority of process 
				if($memcSync->lock()) {
					$data = $this->getData($memcSync, $userId, $wikiId);
					$this->addNotificationToData($data, $userId, $wikiId, $uniqueId, $entityKey, $authorId, $isReply );
				} else {
					$this->sleep($count);
				}
				$count++;
			} while(!isset($data) || !$this->setData($memcSync, $data));
			
			$memcSync->unlock();
		}
		
		$this->cleanEntitiesFromDB();
	}
	
	protected function sleep($userId, $wikiId){
		$time = 100000 - $count*1000; //change priority of process with access to resource
		if($time < 0) {
			$time = 0;
		}
		usleep(100000 + $time);		
	}
	
	protected function remNotificationFromData(&$data, $uniqueId) {
		if(isset($data['relation'][ $uniqueId ]['last']) && $data['relation'][ $uniqueId ]['last'] > -1) {
			unset( $data['notification'][ $data['relation'][$uniqueId ]['last'] ] );
			unset( $data['relation'][$uniqueId ] );
		}
	}

	protected function addNotificationToData(&$data, $userId, $wikiId, $uniqueId, $entityKey, $authorId, $isReply, $read = false) {
		$data['notification'][] = $uniqueId;
		$addedAtTmp = end( $data['notification'] );
		$addedAt = key( $data['notification'] );
		reset( $data['notification'] );
		if(isset($data['relation'][ $uniqueId ]['last']) && $data['relation'][ $uniqueId ]['last'] > -1) {
			// $data['notification'][ $data['relation'][$uniqueId ]['last'] ] = null;
			
			// remove previous element from the list (to keep proper ordering)
			unset( $data['notification'][ $data['relation'][$uniqueId ]['last'] ] );
		}

		if(empty($data['relation'][ $uniqueId ]['list']) || $data['relation'][ $uniqueId ]['read'] ) {
			// this is new Notification (new thread), so create some basic structure for it
			$data['relation'][ $uniqueId ]['list'] = array();
			$data['relation'][ $uniqueId ]['count'] = 0;
			$data['relation'][ $uniqueId ]['read'] = false;
		}

		// if there is no count for this Thread notification, create it
		if(empty($data['relation'][ $uniqueId ]['count'])) $data['relation'][ $uniqueId ]['count'] = count($data['relation'][ $uniqueId ]['list']);
		
		// keep track of where we are references in Notifications list, so that
		// we can remove that entry and readd it at the end, should the new
		// notification for that thread come in (to keep proper order)
		$data['relation'][ $uniqueId ]['last'] = $addedAt;

		
		// we are reply and currently stored information is not? ignore new notification
		// but only if we are still unread
		if($isReply == true) {
			if($data['relation'][ $uniqueId ]['read'] == false) {
				foreach( $data['relation'][ $uniqueId ]['list'] as $key=>$rel ) {
					if( $rel['isReply'] == false ) { return; }
				}
			} else {
				// we are reply but above wasn't true?
				// so we are adding unread notification to read notifications
				// get rid of all read elements
				
				// keep track of removed elements - we will remove them from db
				// table after we are done updating in-memory structures
				foreach( $data['relation'][ $uniqueId ]['list'] as $key=>$rel ) {
					$this->removedEntities[] = array( 'user_id' => $userId, 'wiki_id' => $wikiId, 'unique_id'=>$uniqueId, 'entity_key' => $rel['entityKey'] );
				} 
				$data['relation'][ $uniqueId ]['list'] = array();
				$data['relation'][ $uniqueId ]['count'] = 0;
			}
		}
		
		// scan relation list, remove element that has the same author
		$found = false;
		
		foreach( $data['relation'][ $uniqueId ]['list'] as $key=>$rel ) {
			if( $rel['authorId'] == $authorId ) {
				unset($data['relation'][ $uniqueId ]['list'][$key]);
				$found = true;

				// keep track of removed elements - we will remove them from db
				// table after we are done updating in-memory structures
				$this->removedEntities[] = array( 'user_id' => $userId, 'wiki_id' => $wikiId, 'unique_id'=>$uniqueId, 'entity_key' => $rel['entityKey'] );
			}
		}
		
		// if we didn't find same author in our list, we need to remove oldest element
		if( $found == false && count($data['relation'][ $uniqueId ]['list']) > 2 ) {
			$first = array_shift($data['relation'][ $uniqueId ]['list']);
			if( $first ) {
				// keep track of removed elements - we will remove them from db
				// table after we are done updating in-memory structures
				$this->removedEntities[] = array( 'user_id' => $userId, 'wiki_id' => $wikiId, 'unique_id'=>$uniqueId, 'entity_key' => $first['entityKey'] );
			}
		}
		
		// add new element
		$data['relation'][ $uniqueId ]['list'][] = array('entityKey' => $entityKey, 'authorId' => $authorId, 'isReply'=>$isReply);
		
		// if this was new author increase author count
		if($found == false) $data['relation'][ $uniqueId ]['count'] += 1;
	
		$data['relation'][ $uniqueId ]['read'] = $read;			
		
	}

	protected function cleanEntitiesFromDB() {
		foreach( $this->removedEntities as $val ) {
			$this->getDB(true)->delete('wall_notification' , $val, __METHOD__ );
		}
		$this->removedEntities = array();
	}
	
	protected function isCachedData($userId, $wikiId) {
		$key = $this->getKey($userId, $wikiId);
		$val = F::App()->getGlobal('wgMemc')->get($key);
		
		if(empty($val) && !is_array($val)) {
			return False;
		}
		
		return True;
	}

	protected function getData($cache, $userId, $wiki) {
		$val = $cache->get();
		
		if(empty($val) && !is_array($val)) {
			$val = $this->rebuildData($userId, $wiki);
			
			// this normally would be unnessesery (after all everything should be
			// already removed from DB if we are just recreating our structures)
			// however because this "cleaning" functionality was added later it's
			// possible that we will find data to remove in rebuild process
			$this->cleanEntitiesFromDB();
		}
		
		return $val;
	}
	
	protected function setData($cache, $data) {
		//$cache->delete();
		return $cache->set( $data );
	}
	
	public function forceRebuild($userId, $wikiId) {
		$memcSync = $this->getCache($userId, $wikiId);
		$memcSync->delete();
	}
	

	public function rebuildData($userId, $wikiId) {
		$data = array(
			'notification' => array(),
			'relation' => array()
		);

		//return $data;
		
		//TODO: solve problem with master slave replication
		$dbData = $this->getBackupData($userId, $wikiId);
		
		foreach($dbData as $key => $value) {
			$this->addNotificationToData($data, $userId, $wikiId, $value['unique_id'], $value['entity_key'], $value['author_id'], $value['is_reply'], $value['is_read']);
		}
		
		return $data;
	}
	
	protected function getBackupData($userId, $wikiId, $master = false, $fromId = 0) {
		$uniqueIds = array();
		// select distinct Unique_id from wall_notification where user_id = 1 and wiki_id = 1 order by id
		$db = $this->getDB(true);
		$res = $db->select('wall_notification',
			array('distinct unique_id'),
			array(
				'user_id' => $userId,
				'wiki_id' => $wikiId,
				'is_hidden' => 0
			),
			__METHOD__,
			array( 
				"ORDER BY" => "id desc" ,
				"LIMIT" => 50
			)
		);
		
		while($row = $db->fetchRow($res)) {
			$uniqueIds[] = $row['unique_id'];
		}
		
		$out = array();
		if(!empty($uniqueIds)) {
			$res = $db->select('wall_notification',
				array('id', 'is_read', 'is_reply', 'unique_id', 'entity_key', 'author_id'),
				//array('id', 'unique_id', 'entity_key', 'author_id'),
				array(
					'user_id' => $userId,
					'wiki_id' => $wikiId,
					'unique_id' => $uniqueIds,
					'is_hidden' => 0
				),
				__METHOD__,
				array( "ORDER BY" => "id" )
			);

			while($row = $db->fetchRow($res)) {
				$out[] = $row;
			}
		}

		return $out;
	}
	
	public function storeInDB($userId, $wikiId,$uniqueId, $entityKey, $authorId, $isReply){
		$this->getDB(true)->insert( 'wall_notification', array(
			'user_id' => $userId, 
			'wiki_id' => $wikiId, 
			'unique_id' =>$uniqueId,
			'author_id' => $authorId,
			'entity_key' => $entityKey,
			'is_read' => 0,
			'is_reply' => $isReply,
			'is_hidden' => 0
		), __METHOD__ );
		$this->getDB(true)->commit();
	}
	
	protected function getCache($userId, $wikiId) {
		return F::build('MemcacheSync', array($this->app->wg->Memc, $this->getKey($userId, $wikiId)));
	}
	
	public function getDB($master = false){
		return wfGetDB( $master ? DB_MASTER:DB_SLAVE, array(), $this->app->wg->ExternalDatawareDB );
	}
	
	public function getLocalDB($master = false){
		return wfGetDB( $master ? DB_MASTER:DB_SLAVE, array() );
	}
	
	public function getKey( $userId, $wikiId ){
		return $this->app->runFunction( 'wfSharedMemcKey', __CLASS__, $userId, $wikiId. 'v28' );
	}
}
