<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/feedback.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

/**
 * Web service methods of the feedback for owners (the bell). Only for
 * signed-in members, each call with the token of the session. Every answer
 * holds the number of unread entries.
 */

/**
 * Registers the methods; called by like_bravo_ws_add_methods().
 *
 * @param PwgServer $service
 */
function like_bravo_feedback_ws_add_methods(&$service)
{
  $service->addMethod(
    'likeBravo.feedbackList',
    'like_bravo_ws_feedback_list',
    array(
      'page' => array('default' => 1, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'per_page' => array('default' => 20, 'maxValue' => 100, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'unread' => array('default' => false, 'type' => WS_TYPE_BOOL),
      'kind' => array('default' => 'all', 'info' => 'all, votes, comments'),
      'before' => array('default' => '', 'info' => 'cursor of the last entry loaded: the entries older than it, page is then ignored'),
      'pwg_token' => array(),
      ),
    'Likes, bravos and comments others gave to the photos of the current member, newest first, with their read status.'
    );

  $service->addMethod(
    'likeBravo.feedbackMarkRead',
    'like_bravo_ws_feedback_mark_read',
    array(
      'kind' => array('info' => 'vote, comment'),
      'ref_id' => array('type' => WS_TYPE_ID),
      'rater_id' => array('default' => 0, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'rater_anonymous_id' => array('default' => ''),
      'pwg_token' => array(),
      ),
    'Marks one entry of the feedback of the current member as read.',
    null,
    array('post_only' => true)
    );

  $service->addMethod(
    'likeBravo.feedbackMarkAllRead',
    'like_bravo_ws_feedback_mark_all_read',
    array(
      'pwg_token' => array(),
      ),
    'Marks all feedback of the current member as read.',
    null,
    array('post_only' => true)
    );
}

/**
 * Checks the visitor and the token of a call.
 *
 * @param array $params
 * @return PwgError|null
 */
function like_bravo_ws_feedback_refused($params)
{
  if (like_bravo_is_anonymous())
  {
    return new PwgError(401, 'Access denied');
  }
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  return null;
}

/**
 * API method
 * @param mixed[] $params
 *    @option int page
 *    @option int per_page
 *    @option bool unread
 *    @option string kind 'all', 'votes' or 'comments'
 *    @option string before cursor of an entry, or ''
 *    @option string pwg_token
 */
function like_bravo_ws_feedback_list($params, &$service)
{
  global $conf, $user;

  if ($refused = like_bravo_ws_feedback_refused($params))
  {
    return $refused;
  }

  $kind = in_array($params['kind'], array('all', 'votes', 'comments'), true) ? $params['kind'] : 'all';
  $before = like_bravo_feedback_cursor((string)$params['before']);
  if (null === $before and '' !== (string)$params['before'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid cursor');
  }
  $list = like_bravo_feedback_list($user['id'], $params['page'], $params['per_page'], (bool)$params['unread'], $kind, $before);

  set_make_full_url();
  $entries = like_bravo_feedback_items($list['entries']);
  unset_make_full_url();

  return array(
    'entries' => $entries,
    'page' => $list['page'],
    'per_page' => $list['per_page'],
    'has_more' => $list['has_more'],
    'unread' => like_bravo_feedback_store_count($user['id'], like_bravo_feedback_unread_count($user['id'])),
    'icons' => array(
      'like' => like_bravo_icon($conf['like_bravo']['icon_like'], 14),
      'bravo' => like_bravo_icon($conf['like_bravo']['icon_bravo'], 14),
      ),
    );
}

/**
 * API method
 * @param mixed[] $params
 *    @option string kind 'vote' or 'comment'
 *    @option int ref_id photo of a vote, id of a comment
 *    @option int rater_id account of a vote, 0 for a comment
 *    @option string rater_anonymous_id identifier of an anonymous voter
 *    @option string pwg_token
 */
function like_bravo_ws_feedback_mark_read($params, &$service)
{
  global $user;

  if ($refused = like_bravo_ws_feedback_refused($params))
  {
    return $refused;
  }

  if (!like_bravo_feedback_mark_read($user['id'], (string)$params['kind'], $params['ref_id'],
      $params['rater_id'], (string)$params['rater_anonymous_id']))
  {
    return new PwgError(404, 'Unknown entry');
  }

  return array('unread' => like_bravo_feedback_store_count($user['id'], like_bravo_feedback_unread_count($user['id'])));
}

/**
 * API method
 * @param mixed[] $params
 *    @option string pwg_token
 */
function like_bravo_ws_feedback_mark_all_read($params, &$service)
{
  global $user;

  if ($refused = like_bravo_ws_feedback_refused($params))
  {
    return $refused;
  }

  like_bravo_feedback_mark_all_read($user['id']);

  return array('unread' => like_bravo_feedback_store_count($user['id'], like_bravo_feedback_unread_count($user['id'])));
}
