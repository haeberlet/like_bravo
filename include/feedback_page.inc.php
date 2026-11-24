<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/feedback.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

/**
 * The page "All feedback" (index.php?/feedback): all likes, bravos and
 * comments others gave to the member's photos, newest first, with filters
 * that load more entries while scrolling. Built on the server, so it works
 * without JavaScript, with a link to the older entries; js/bell.js marks
 * entries as read, as in the list of the bell, and loads the older entries
 * instead of the link.
 */

define('LIKE_BRAVO_FEEDBACK_PER_PAGE', 20);

/**
 * Text for screen readers of some numbers of feedback.
 *
 * @param array $count 'likes', 'bravos', 'comments'
 * @param bool $with_bravo
 * @return string such as "3 likes, 1 bravo, 2 comments"
 */
function like_bravo_feedback_count_text($count, $with_bravo)
{
  include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');

  return like_bravo_count_text($count['likes'], '%d like', '%d likes')
    .($with_bravo ? ', '.like_bravo_count_text($count['bravos'], '%d bravo', '%d bravos') : '')
    .', '.like_bravo_count_text($count['comments'], '%d comment (feedback)', '%d comments (feedback)');
}

/**
 * Filters and start asked for by the URL.
 *
 * @param array $get
 * @return array 'unread' => bool, 'kind' => 'all', 'votes' or 'comments',
 *    'before' => cursor of the entry to start after (like_bravo_feedback_cursor_text()), or ''
 */
function like_bravo_feedback_page_params($get)
{
  $kind = isset($get['kind']) ? (string)$get['kind'] : 'all';
  $before = isset($get['before']) ? (string)$get['before'] : '';

  return array(
    'unread' => !empty($get['unread']),
    'kind' => in_array($kind, array('all', 'votes', 'comments'), true) ? $kind : 'all',
    'before' => null === like_bravo_feedback_cursor($before) ? '' : $before,
    );
}

/**
 * URL of the page with these filters and this start.
 *
 * @param array $params as like_bravo_feedback_page_params()
 * @return string
 */
function like_bravo_feedback_page_url($params)
{
  $query = array();
  if ($params['unread'])
  {
    $query['unread'] = 1;
  }
  if ('all' !== $params['kind'])
  {
    $query['kind'] = $params['kind'];
  }
  if ('' !== $params['before'])
  {
    $query['before'] = urlencode($params['before']); // add_url_params() does not encode
  }

  $url = make_index_url(array('section' => 'feedback'));

  return empty($query) ? $url : add_url_params($url, $query);
}

/**
 * Event handler (loc_end_section_init): turns the page into "All feedback"
 * if its URL asks for it. Only for signed-in members.
 */
function like_bravo_feedback_section_init()
{
  global $conf, $page, $tokens;

  if ('index' != script_basename() or 'categories' != $page['section'] or isset($page['category'])
      or !is_array($tokens) or !isset($tokens[0]) or 'feedback' !== $tokens[0])
  {
    return;
  }

  if (like_bravo_is_anonymous())
  {
    access_denied();
  }

  $page['section'] = 'feedback';
  unset($page['is_homepage'], $page['flat']);

  $page['items'] = array();
  $page['title'] = '<a href="'.make_index_url(array('section' => 'feedback')).'">'.l10n('All feedback').'</a>';
  $page['section_title'] = '<a href="'.get_gallery_home_url().'">'.l10n('Home').'</a>'
    .$conf['level_separator'].$page['title'];
  $page['meta_robots'] = array('noindex' => 1, 'nofollow' => 1);

  $page['body_classes'] = array_values(array_diff($page['body_classes'], array('section-categories')));
  $page['body_classes'][] = 'section-feedback';
  $page['body_data']['section'] = 'feedback';
}

/**
 * Event handler (loc_end_index): the content of the page "All feedback".
 */
function like_bravo_feedback_index()
{
  global $conf, $page, $template, $user;

  if (!isset($page['section']) or 'feedback' !== $page['section'])
  {
    return;
  }

  $params = like_bravo_feedback_page_params($_GET);
  $list = like_bravo_feedback_list($user['id'], 1, LIKE_BRAVO_FEEDBACK_PER_PAGE, $params['unread'], $params['kind'],
    like_bravo_feedback_cursor($params['before']));

  // nothing older, e.g. an old link after entries were taken back: back to the newest
  if (empty($list['entries']) and '' !== $params['before'])
  {
    redirect(like_bravo_feedback_page_url(array_merge($params, array('before' => ''))));
  }

  $counts = like_bravo_feedback_counts($user['id'], $params['kind']);
  $filters = array();
  foreach (array(
      'status' => array('all' => 'All entries', 'unread' => 'Unread'),
      'kind' => array('all' => 'All kinds', 'votes' => 'Likes and bravos', 'comments' => 'Comments'),
      ) as $group => $choices)
  {
    foreach ($choices as $value => $label)
    {
      $target = 'status' === $group
        ? array_merge($params, array('unread' => 'unread' === $value, 'before' => ''))
        : array_merge($params, array('kind' => $value, 'before' => ''));
      $active = 'status' === $group ? ($params['unread'] === ('unread' === $value)) : ($params['kind'] === $value);
      $filters[$group][] = array(
        'LABEL' => l10n($label),
        'URL' => like_bravo_feedback_page_url($target),
        'ACTIVE' => $active,
        'COUNT' => 'status' === $group ? $counts[$value] : null,
        // js/bell.js keeps the number of all unread entries up to date
        'LIVE' => 'status' === $group and 'unread' === $value and 'all' === $params['kind'],
        );
    }
  }

  $filtered = ($params['unread'] or 'all' !== $params['kind']);

  // the entries under headings of days; js/bell.js goes on with the same headings
  $days = array(
    'today' => l10n('Today'),
    'yesterday' => l10n('Yesterday'),
    'week' => l10n('This week'),
    'date' => l10n('Earlier'),
    );
  $groups = array();
  foreach (like_bravo_feedback_items($list['entries']) as $item)
  {
    $item['KEY_JSON'] = json_encode($item['key']);
    $last = count($groups) - 1;
    if ($last < 0 or $groups[$last]['DAY'] !== $item['day'])
    {
      $groups[] = array('DAY' => $item['day'], 'TITLE' => $days[$item['day']], 'ITEMS' => array());
      $last++;
    }
    $groups[$last]['ITEMS'][] = $item;
  }
  $last_group = end($groups);

  // the side column: the last seven days and the photos with the most feedback
  $week = array('likes' => 0, 'bravos' => 0, 'comments' => 0);
  $tally = like_bravo_feedback_tally($user['id'], 7);
  foreach ($tally['photos'] as $count)
  {
    foreach ($count as $name => $n)
    {
      $week[$name] += $n;
    }
  }
  $week['text'] = like_bravo_feedback_count_text($week, $tally['with_bravo']);
  $top = like_bravo_feedback_top_photos(like_bravo_feedback_tally($user['id']));
  foreach ($top as $i => $photo)
  {
    $top[$i]['text'] = like_bravo_feedback_count_text($photo, $tally['with_bravo']);
  }

  $template->set_filename('like_bravo_feedback', realpath(LIKE_BRAVO_PATH.'template/feedback.tpl'));
  $template->assign('like_bravo_feedback', array(
    'GROUPS' => $groups,
    'DAYS_JSON' => json_encode($days),
    'ICONS' => array(
      'like' => like_bravo_icon($conf['like_bravo']['icon_like'], 14),
      'bravo' => like_bravo_icon($conf['like_bravo']['icon_bravo'], 14),
      'comment' => like_bravo_icon('comment', 14),
      ),
    'FILTERS' => $filters,
    'UNREAD' => like_bravo_feedback_badge_count($user['id']),
    'WEEK' => $week,
    'WITH_BRAVO' => $tally['with_bravo'],
    'TOP' => $top,
    'EMPTY' => l10n($filtered ? 'No entries match the filter.' : 'No feedback on your photos yet.'),
    'UNREAD_ONLY' => $params['unread'],
    'KIND' => $params['kind'],
    'PER_PAGE' => LIKE_BRAVO_FEEDBACK_PER_PAGE,
    'U_NEWEST' => '' !== $params['before'] ? like_bravo_feedback_page_url(array_merge($params, array('before' => ''))) : null,
    'U_OLDER' => $list['has_more'] && $last_group
      ? like_bravo_feedback_page_url(array_merge($params, array('before' => end($last_group['ITEMS'])['cursor'])))
      : null,
    ));

  $template->func_combine_css(array('path' => 'plugins/'.LIKE_BRAVO_ID.'/css/bell.css'));
  $template->assign_var_from_handle('CONTENT', 'like_bravo_feedback');
}
