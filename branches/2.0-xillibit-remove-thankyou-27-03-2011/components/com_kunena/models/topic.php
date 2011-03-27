<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

kimport ( 'kunena.model' );
kimport('kunena.forum.category.helper');
kimport('kunena.forum.topic.helper');
kimport('kunena.forum.message.helper');
kimport('kunena.user.helper');
kimport('kunena.forum.topic.poll.helper');

/**
 * Topic Model for Kunena
 *
 * @package		Kunena
 * @subpackage	com_kunena
 * @since		2.0
 */
class KunenaModelTopic extends KunenaModel {
	protected $topics = false;
	protected $messages = false;
	protected $items = false;

	protected function populateState() {
		$app = JFactory::getApplication ();
		$me = KunenaUserHelper::get();
		$config = KunenaFactory::getConfig ();
		$active = $app->getMenu ()->getActive ();
		$active = $active ? (int) $active->id : 0;
		$layout = $this->getWord ( 'layout', 'default' );
		if ($layout == 'default') $layout = $app->getUserState( 'com_kunena.topic_layout', 'default' );
		$this->setState ( 'layout', !$layout ? 'default' : $layout );

		$template = KunenaFactory::getTemplate();
		$profile_location = $template->params->get('avatarPosition', 'left');
		$profile_direction = $profile_location == 'left' || $profile_location == 'right' ? 'vertical' : 'horizontal';
		$this->setState ( 'profile.location', $profile_location );
		$this->setState ( 'profile.direction', $profile_direction );

		$catid = $this->getInt ( 'catid', 0 );
		$this->setState ( 'item.catid', $catid );

		$id = $this->getInt ( 'id', 0 );
		$this->setState ( 'item.id', $id );

		$id = $this->getInt ( 'mesid', 0 );
		$this->setState ( 'item.mesid', $id );

		$access = KunenaFactory::getAccessControl();
		$value = $access->getAllowedHold($me, $catid);
		$this->setState ( 'hold', $value );

		$value = $this->getInt ( 'limit', 0 );
		if ($value < 1) $value = $config->messages_per_page;
		$this->setState ( 'list.limit', $value );

		$value = $this->getUserStateFromRequest ( "com_kunena.topic_{$active}_{$layout}_list_ordering", 'filter_order', 'time', 'cmd' );
		//$this->setState ( 'list.ordering', $value );

		$value = $this->getInt ( 'limitstart', 0 );
		if ($value < 0) $value = 0;
		$this->setState ( 'list.start', $value );

		$value = $this->getUserStateFromRequest ( "com_kunena.topic_{$active}_{$layout}_list_direction", 'filter_order_Dir', '', 'word' );
		if (!$value) {
			if ($me->ordering != '0') {
				$value = $me->ordering == '1' ? 'desc' : 'asc';
			} else {
				$value = $config->default_sort == 'asc' ? 'asc' : 'desc';
			}
		}
		if ($value != 'asc')
			$value = 'desc';
		$this->setState ( 'list.direction', $value );
	}

	public function getCategory() {
		return KunenaForumCategoryHelper::get($this->getState ( 'item.catid'));
	}

	public function getTopic() {
		$topic = KunenaForumTopicHelper::get($this->getState ( 'item.id'));
		$ids = array();
		// If topic has been moved, find the new topic
		while ($topic->moved_id) {
			if (isset($ids[$topic->moved_id])) {
				// Break on loops
				return false;
			}
			$ids[$topic->moved_id] = 1;
			$topic = KunenaForumTopicHelper::get($topic->moved_id);
		}
		// If topic doesn't exist, check if there's a message with the same id
		if (! $topic->exists()) {
			$message = KunenaForumMessageHelper::get($this->getState ( 'item.id'));
			if ($message->exists()) {
				$topic = KunenaForumTopicHelper::get($message->thread);
			}
		}
		$this->topic = $topic;
		return $topic;
	}

	public function getMessages() {
		if ($this->messages === false) {
			$layout = $this->getState ('layout');
			$threaded = ($layout == 'indented' || $layout == 'threaded');
			$this->messages = KunenaForumMessageHelper::getMessagesByTopic($this->getState ( 'item.id'),
				$this->getState ( 'list.start'), $this->getState ( 'list.limit'), $this->getState ( 'list.direction'), $this->getState ( 'hold'), $threaded);

			// First collect ids and users
			$userlist = array();
			$this->threaded = array();
			foreach($this->messages AS $message){
				if ($threaded) {
					// Threaded ordering
					if (isset($this->messages[$message->parent])) {
						$this->threaded[$message->parent][] = $message->id;
					} else {
						$this->threaded[0][] = $message->id;
					}
				}
				$userlist[intval($message->userid)] = intval($message->userid);
				$userlist[intval($message->modified_by)] = intval($message->modified_by);
			}
			if (!isset($this->messages[$this->getState ( 'item.mesid')])) $this->setState ( 'item.mesid', reset($this->messages)->id);
			if ($threaded) {
				if (!isset($this->messages[$this->topic->first_post_id]))
					$this->messages = $this->getThreadedOrdering(0, array('edge'));
				else
					$this->messages = $this->getThreadedOrdering();
			}

			// Prefetch all users/avatars to avoid user by user queries during template iterations
			KunenaUserHelper::loadUsers($userlist);

			// Get attachments
			KunenaForumMessageAttachmentHelper::getByMessage($this->messages);
		}

		return $this->messages;
	}

	protected function getThreadedOrdering($parent = 0, $indent = array()) {
		$list = array();
		if (count($indent) == 1 && $this->getTopic()->getTotal() > $this->getState('list.start')+$this->getState('list.limit')) {
			$last = -1;
		} else {
			$last = end($this->threaded[$parent]);
		}
		foreach ($this->threaded[$parent] as $mesid) {
			$message = $this->messages[$mesid];
			$skip = $message->id != $this->topic->first_post_id && $message->parent != $this->topic->first_post_id && !isset($this->messages[$message->parent]);
			if ($mesid != $last) {
				// Default sibling edge
				$indent[] = 'crossedge';
			} else {
				// Last sibling edge
				$indent[] = 'lastedge';
			}
			end($indent);
			$key = key($indent);
			if ($skip) {
				$indent[] = 'gap';
			}
			$list[$mesid] = $this->messages[$mesid];
			$list[$mesid]->indent = $indent;
			if (empty($this->threaded[$mesid])) {
				// No children node
				$list[$mesid]->indent[] = ($mesid == $message->thread) ? 'single' : 'leaf';
			} else {
				// Has children node
				$list[$mesid]->indent[] = ($mesid == $message->thread) ? 'root' : 'node';
			}

			if (!empty($this->threaded[$mesid])) {
				// Fix edges
				if ($mesid != $last) {
					$indent[$key] = 'edge';
				} else {
					$indent[$key] = 'empty';
				}
				if ($skip) {
					$indent[$key+1] = 'empty';
				}
				$list += $this->getThreadedOrdering($mesid, $indent);
			}
			if ($skip) {
				array_pop($indent);
			}
			array_pop($indent);
		}
		return $list;
	}

	public function getTotal() {
		return $this->getTopic()->getTotal();
	}

	public function getModerators() {
		$moderators = $this->getCategory()->getModerators(false);
		if ( !empty($moderators) ) KunenaUserHelper::loadUsers($moderators);
		return $moderators;
	}

	public function getPoll() {
		return $this->getTopic()->getPoll();
	}

	public function getPollUserCount() {
		return $this->getPoll()->getUserCount();
	}

	public function getPollUsers() {
		return $this->getPoll()->getUsers();
	}

	public function getMyVotes() {
		return $this->getPoll()->getMyVotes();
	}
}