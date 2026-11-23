<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/score.inc.php');

/**
 * Feedback for owners (the bell): likes, bravos and comments that others
 * gave to the photos of a member. Read live from the core tables rate and
 * comments, with the plugin's times of votes and its read marks.
 *
 * Votes and comments are one list, built by one query (like_bravo_feedback_sql),
 * so that the list, its filters, its pages and the number of unread entries
 * always agree.
 */

/**
 * What the feedback of a member is about: his photos, the modes of those in
 * a thumbs mode, and the time up to which everything counts as read.
 *
 * @param int $owner_id
 * @return array 'photos' => int[], 'thumbs' => image_id => mode,
 *    'read_before' => string
 */
function like_bravo_feedback_scope($owner_id)
{
  global $conf;

  $owner_id = (int)$owner_id;

  $photos = array_map('intval', query2array('
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE added_by = '.$owner_id.'
;', null, 'id'));

  $thumbs = empty($photos)
    ? array()
    : array_filter(like_bravo_get_image_modes($photos), 'like_bravo_is_thumbs_mode');

  $row = pwg_db_fetch_assoc(pwg_query('
SELECT f.read_all_before, ui.registration_date
  FROM '.USER_INFOS_TABLE.' AS ui
    LEFT JOIN '.LIKE_BRAVO_FEEDBACK_USER_TABLE.' AS f ON f.user_id = ui.user_id
  WHERE ui.user_id = '.$owner_id.'
;'));

  return array(
    'photos' => $photos,
    'thumbs' => $thumbs,
    'read_before' => like_bravo_feedback_read_before(
      $row ? $row['read_all_before'] : null,
      $row ? $row['registration_date'] : null,
      isset($conf['like_bravo_feedback_since']) ? $conf['like_bravo_feedback_since'] : null
      ),
    );
}

/**
 * All feedback entries of a member as one SQL query, one row per entry:
 * - a vote: a like or bravo (rate 2 or more) of another account on one of
 *   his photos in a thumbs mode; votes of anonymous visitors only while
 *   guests take part;
 * - a comment: a validated comment of another account on one of his photos,
 *   whatever the mode.
 *
 * Columns: kind ('vote', 'comment'), image_id, ref_id (photo for a vote,
 * comment id for a comment), rater_id, rater_anonymous_id (key of the read
 * mark: the identifier for anonymous voters, '' otherwise), rate, name,
 * is_guest, t (time of the entry), date_only (vote without a time of its
 * own), read_at, content, edited_at, is_read.
 *
 * @param int $owner_id
 * @param array $scope as like_bravo_feedback_scope()
 * @return string|null null if the member has no photo with feedback
 */
function like_bravo_feedback_sql($owner_id, $scope)
{
  global $conf;

  $owner_id = (int)$owner_id;
  $parts = array();
  $username = $conf['user_fields']['username'];
  $user_key = $conf['user_fields']['id'];

  if (!empty($scope['thumbs']))
  {
    $is_guest = like_bravo_sql_is_guest('r', 'ui');
    $read_key = 'CASE WHEN '.$is_guest.' THEN r.anonymous_id ELSE \'\' END';

    $parts[] = '
SELECT \'vote\' AS kind, r.element_id AS image_id, r.element_id AS ref_id,
    r.user_id AS rater_id, '.$read_key.' AS rater_anonymous_id, r.rate,
    u.'.$username.' AS name, '.$is_guest.' AS is_guest,
    COALESCE(vt.changed_at, CAST(r.date AS DATETIME)) AS t,
    vt.changed_at IS NULL AS date_only,
    rd.read_at, NULL AS content, NULL AS edited_at
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USERS_TABLE.' AS u ON u.'.$user_key.' = r.user_id
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
    LEFT JOIN '.LIKE_BRAVO_VOTE_TIME_TABLE.' AS vt ON vt.image_id = r.element_id
      AND vt.user_id = r.user_id AND vt.anonymous_id = r.anonymous_id
    LEFT JOIN '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' AS rd ON rd.user_id = '.$owner_id.'
      AND rd.kind = \'vote\' AND rd.ref_id = r.element_id
      AND rd.rater_id = r.user_id AND rd.rater_anonymous_id = '.$read_key.'
  WHERE r.element_id IN ('.implode(',', array_keys($scope['thumbs'])).')
    AND r.rate >= '.LIKE_BRAVO_RATE_LIKE.'
    AND r.user_id != '.$owner_id
      .(like_bravo_guests_take_part_now() ? '' : '
    AND NOT '.$is_guest);
  }

  if (!empty($scope['photos']))
  {
    $is_guest = '(c.author_id IS NULL OR c.author_id = '.(int)$conf['guest_id']
      .' OR (ui.status IS NOT NULL AND ui.status IN (\'guest\', \'generic\')))';

    $parts[] = '
SELECT \'comment\' AS kind, c.image_id, c.id AS ref_id,
    0 AS rater_id, \'\' AS rater_anonymous_id, NULL AS rate,
    c.author AS name, '.$is_guest.' AS is_guest,
    c.validation_date AS t,
    0 AS date_only,
    rd.read_at, c.content, ce.edited_at
  FROM '.COMMENTS_TABLE.' AS c
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = c.author_id
    LEFT JOIN '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' AS ce ON ce.comment_id = c.id
    LEFT JOIN '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' AS rd ON rd.user_id = '.$owner_id.'
      AND rd.kind = \'comment\' AND rd.ref_id = c.id
      AND rd.rater_id = 0 AND rd.rater_anonymous_id = \'\'
  WHERE c.image_id IN ('.implode(',', $scope['photos']).')
    AND c.validated = \'true\'
    AND c.validation_date IS NOT NULL
    AND (c.author_id IS NULL OR c.author_id != '.$owner_id.')';
  }

  if (empty($parts))
  {
    return null;
  }

  // same rule as like_bravo_feedback_is_read()
  $read_before = pwg_db_real_escape_string($scope['read_before']);
  $is_read = ('' === $scope['read_before'] ? 'FALSE' : 'f.t <= \''.$read_before.'\'')
    .' OR (f.read_at IS NOT NULL AND f.read_at >= f.t)';

  return '
SELECT f.*, ('.$is_read.') AS is_read
  FROM ('.implode('
  UNION ALL', $parts).'
  ) AS f';
}

/**
 * Number of unread entries of a member.
 *
 * @param int $owner_id
 * @return int
 */
function like_bravo_feedback_unread_count($owner_id)
{
  $sql = like_bravo_feedback_sql($owner_id, like_bravo_feedback_scope($owner_id));
  if (null === $sql)
  {
    return 0;
  }

  list($count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ('.$sql.') AS e
  WHERE NOT e.is_read
;'));

  return (int)$count;
}

/**
 * One page of the feedback of a member, newest first.
 *
 * @param int $owner_id
 * @param int $page from 1
 * @param int $per_page 1 to 100
 * @param bool $unread_only
 * @param string $kind 'all', 'votes' or 'comments'
 * @return array 'entries' => list of entries (see like_bravo_feedback_entry()),
 *    'page' => int, 'per_page' => int, 'has_more' => bool
 */
function like_bravo_feedback_list($owner_id, $page=1, $per_page=20, $unread_only=false, $kind='all')
{
  global $conf;

  $page = max(1, (int)$page);
  $per_page = min(100, max(1, (int)$per_page));
  $result = array('entries' => array(), 'page' => $page, 'per_page' => $per_page, 'has_more' => false);

  $scope = like_bravo_feedback_scope($owner_id);
  $sql = like_bravo_feedback_sql($owner_id, $scope);
  if (null === $sql)
  {
    return $result;
  }

  $where = array();
  if ($unread_only)
  {
    $where[] = 'NOT e.is_read';
  }
  if ('votes' === $kind)
  {
    $where[] = 'e.kind = \'vote\'';
  }
  elseif ('comments' === $kind)
  {
    $where[] = 'e.kind = \'comment\'';
  }

  $rows = query2array('
SELECT e.*
  FROM ('.$sql.') AS e'
  .(empty($where) ? '' : '
  WHERE '.implode(' AND ', $where)).'
  ORDER BY e.t DESC, e.kind DESC, e.ref_id DESC, e.rater_id DESC, e.rater_anonymous_id DESC
  LIMIT '.(($page - 1) * $per_page).', '.($per_page + 1).'
;');

  if (count($rows) > $per_page)
  {
    $result['has_more'] = true;
    $rows = array_slice($rows, 0, $per_page);
  }

  $names_visible = like_bravo_may_see_names($conf['like_bravo']['show_names'], false);
  $totals = like_bravo_feedback_totals($rows, $scope['thumbs']);

  foreach ($rows as $row)
  {
    $entry = like_bravo_feedback_entry($row, $scope, $names_visible, $totals);
    if (null !== $entry)
    {
      $result['entries'][] = $entry;
    }
  }

  return $result;
}

/**
 * Current likes and bravos of the photos of some vote entries, counted as
 * on the photo page.
 *
 * @param array $rows rows of like_bravo_feedback_sql()
 * @param array $thumbs image_id => mode
 * @return array image_id => array('likes' => int, 'bravos' => int)
 */
function like_bravo_feedback_totals($rows, $thumbs)
{
  $modes = array();
  foreach ($rows as $row)
  {
    if ('vote' === $row['kind'] and isset($thumbs[(int)$row['image_id']]))
    {
      $modes[(int)$row['image_id']] = $thumbs[(int)$row['image_id']];
    }
  }

  if (empty($modes))
  {
    return array();
  }

  $query = '
SELECT r.element_id, r.rate, r.user_id, r.anonymous_id,
    '.like_bravo_sql_is_guest('r', 'ui').' AS is_guest
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
  WHERE r.element_id IN ('.implode(',', array_keys($modes)).')
;';

  $totals = array();
  foreach (like_bravo_thumbnail_votes(query2array($query), $modes, null, null, like_bravo_guests_take_part_now()) as $id => $votes)
  {
    $totals[$id] = array('likes' => $votes['likes'], 'bravos' => $votes['bravos']);
  }

  return $totals;
}

/**
 * One entry of the feedback, as the list and the bell show it.
 *
 * @param array $row of like_bravo_feedback_sql()
 * @param array $scope as like_bravo_feedback_scope()
 * @param bool $names_visible names of voters for the owner
 * @param array $totals as like_bravo_feedback_totals()
 * @return array|null 'kind' => 'like', 'bravo' or 'comment', 'image_id',
 *    'who', 'name' (see like_bravo_feedback_who()), 'time', 'date_only',
 *    'unread', 'read_key' => array('kind', 'ref_id', 'rater_id',
 *    'rater_anonymous_id'); for votes 'mode' and 'likes', 'bravos' of the
 *    photo; for comments 'comment_id', 'excerpt', 'edited'
 */
function like_bravo_feedback_entry($row, $scope, $names_visible, $totals)
{
  $image_id = (int)$row['image_id'];
  $entry = array(
    'image_id' => $image_id,
    'time' => $row['t'],
    'date_only' => (bool)$row['date_only'],
    'unread' => !like_bravo_feedback_is_read($row['t'], $scope['read_before'], $row['read_at']),
    'read_key' => array(
      'kind' => $row['kind'],
      'ref_id' => (int)$row['ref_id'],
      'rater_id' => (int)$row['rater_id'],
      'rater_anonymous_id' => (string)$row['rater_anonymous_id'],
      ),
    );

  if ('comment' === $row['kind'])
  {
    return array_merge($entry, like_bravo_feedback_who(!empty($row['is_guest']), $row['name'], true), array(
      'kind' => 'comment',
      'comment_id' => (int)$row['ref_id'],
      'excerpt' => like_bravo_excerpt($row['content']),
      'edited' => like_bravo_comment_is_edited($row['edited_at'], $row['t']),
      ));
  }

  $mode = isset($scope['thumbs'][$image_id]) ? $scope['thumbs'][$image_id] : LIKE_BRAVO_MODE_STARS;
  $level = like_bravo_classify_rate($row['rate'], $mode);
  if (null === $level)
  {
    return null;
  }

  $total = isset($totals[$image_id]) ? $totals[$image_id] : array('likes' => 0, 'bravos' => 0);

  return array_merge($entry, like_bravo_feedback_who(!empty($row['is_guest']), $row['name'], $names_visible), array(
    'kind' => $level,
    'mode' => $mode,
    'likes' => $total['likes'],
    'bravos' => $total['bravos'],
    ));
}

/**
 * Marks one entry of the feedback of a member as read, if it is one of his
 * entries.
 *
 * @param int $owner_id
 * @param string $kind 'vote' or 'comment'
 * @param int $ref_id photo of a vote, id of a comment
 * @param int $rater_id account of a vote, 0 for a comment
 * @param string $rater_anonymous_id identifier of an anonymous voter, '' else
 * @return bool false if there is no such entry
 */
function like_bravo_feedback_mark_read($owner_id, $kind, $ref_id, $rater_id=0, $rater_anonymous_id='')
{
  $owner_id = (int)$owner_id;
  if (!in_array($kind, array('vote', 'comment'), true))
  {
    return false;
  }

  $sql = like_bravo_feedback_sql($owner_id, like_bravo_feedback_scope($owner_id));
  if (null === $sql)
  {
    return false;
  }

  $key = array(
    'kind' => $kind,
    'ref_id' => (int)$ref_id,
    'rater_id' => (int)$rater_id,
    'rater_anonymous_id' => pwg_db_real_escape_string((string)$rater_anonymous_id),
    );

  $found = pwg_db_fetch_row(pwg_query('
SELECT 1
  FROM ('.$sql.') AS e
  WHERE e.kind = \''.$key['kind'].'\'
    AND e.ref_id = '.$key['ref_id'].'
    AND e.rater_id = '.$key['rater_id'].'
    AND e.rater_anonymous_id = \''.$key['rater_anonymous_id'].'\'
  LIMIT 1
;'));
  if (!$found)
  {
    return false;
  }

  pwg_query('
INSERT INTO '.LIKE_BRAVO_FEEDBACK_READ_TABLE.'
  (user_id, kind, ref_id, rater_id, rater_anonymous_id, read_at)
  VALUES ('.$owner_id.', \''.$key['kind'].'\', '.$key['ref_id'].', '.$key['rater_id'].', \''.$key['rater_anonymous_id'].'\', NOW())
  ON DUPLICATE KEY UPDATE read_at = NOW()
;');

  return true;
}

/**
 * Marks all feedback of a member as read, also the entries not loaded. The
 * marks of single entries are no longer needed then: everything they cover
 * is older.
 *
 * @param int $owner_id
 */
function like_bravo_feedback_mark_all_read($owner_id)
{
  $owner_id = (int)$owner_id;

  pwg_query('
INSERT INTO '.LIKE_BRAVO_FEEDBACK_USER_TABLE.'
  (user_id, read_all_before)
  VALUES ('.$owner_id.', NOW())
  ON DUPLICATE KEY UPDATE read_all_before = NOW()
;');
  pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE user_id = '.$owner_id.';');
}

/**
 * Number of unread entries for the bell, kept in the session for a minute
 * so that pages do not query on every call. Own read actions store the new
 * number at once (like_bravo_feedback_store_count).
 *
 * @param int $owner_id
 * @param int $max_age seconds
 * @return int
 */
function like_bravo_feedback_badge_count($owner_id, $max_age=60)
{
  $cached = pwg_get_session_var('like_bravo_feedback_unread', null);
  if (is_array($cached) and isset($cached['user'], $cached['count'], $cached['at'])
      and (int)$cached['user'] === (int)$owner_id and time() - (int)$cached['at'] < $max_age)
  {
    return (int)$cached['count'];
  }

  return like_bravo_feedback_store_count($owner_id, like_bravo_feedback_unread_count($owner_id));
}

/**
 * @param int $owner_id
 * @param int $count
 * @return int $count
 */
function like_bravo_feedback_store_count($owner_id, $count)
{
  pwg_set_session_var('like_bravo_feedback_unread', array('user' => (int)$owner_id, 'count' => (int)$count, 'at' => time()));

  return (int)$count;
}

/**
 * Text of an entry as HTML: the name in bold, the beginning of a comment in
 * italics. Each case is a whole sentence of its own in the language files,
 * because languages build them differently (German "gefallen" asks for the
 * dative: "Einem Gast gefällt dein Foto").
 *
 * @param array $entry as like_bravo_feedback_entry()
 * @return string
 */
function like_bravo_feedback_html($entry)
{
  $edited = ('comment' === $entry['kind'] and !empty($entry['edited']));

  $keys = array(
    'like' => array(
      'member' => '%s likes your photo',
      'guest' => 'A guest likes your photo',
      'former' => 'A former member likes your photo',
      'hidden' => 'Your photo got a like',
      ),
    'bravo' => array(
      'member' => '%s gave your photo a bravo',
      'guest' => 'A guest gave your photo a bravo',
      'former' => 'A former member gave your photo a bravo',
      'hidden' => 'Your photo got a bravo',
      ),
    'comment' => array(
      'member' => '%s commented on your photo:',
      'guest' => 'A guest commented on your photo:',
      'former' => 'A former member commented on your photo:',
      ),
    'edited' => array(
      'member' => '%s edited a comment on your photo:',
      'guest' => 'A guest edited a comment on your photo:',
      'former' => 'A former member edited a comment on your photo:',
      ),
    );

  $group = $edited ? 'edited' : $entry['kind'];
  $who = isset($keys[$group][$entry['who']]) ? $entry['who'] : 'former';
  $html = htmlspecialchars(l10n($keys[$group][$who]));

  if ('member' === $who)
  {
    $html = sprintf($html, '<strong>'.htmlspecialchars($entry['name']).'</strong>');
  }

  if ('comment' === $entry['kind'])
  {
    $html.= ' <em>'.htmlspecialchars($entry['excerpt']).'</em>';
  }

  return $html;
}

/**
 * Short text for the time of an entry: "just now", "5 min ago", "2 h ago",
 * "today", "yesterday" or the date.
 *
 * @param string $time
 * @param string $now from the database, see like_bravo_feedback_when()
 * @param bool $date_only
 * @return string
 */
function like_bravo_feedback_when_text($time, $now, $date_only)
{
  $when = like_bravo_feedback_when($time, $now, $date_only);

  switch ($when['unit'])
  {
    case 'now':
      return l10n('just now');
    case 'minutes':
      return sprintf(l10n('%d min ago'), $when['value']);
    case 'hours':
      return sprintf(l10n('%d h ago'), $when['value']);
    case 'today':
      return l10n('today');
    case 'yesterday':
      return l10n('yesterday');
    default:
      return format_date($time, array('day', 'month', 'year'));
  }
}
