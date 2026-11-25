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
 * a thumbs mode, the time up to which everything counts as read, and what
 * he may see of the photos of others (for "Also commented").
 *
 * @param int $owner_id
 * @return array 'photos' => int[], 'thumbs' => image_id => mode,
 *    'read_before' => string, 'discussion_read_before' => string,
 *    'viewer' => array 'forbidden' => int[], 'level' => int
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

  $read_before = like_bravo_feedback_read_before(
    $row ? $row['read_all_before'] : null,
    $row ? $row['registration_date'] : null,
    isset($conf['like_bravo_feedback_since']) ? $conf['like_bravo_feedback_since'] : null
    );

  return array(
    'photos' => $photos,
    'thumbs' => $thumbs,
    'read_before' => $read_before,
    'discussion_read_before' => like_bravo_discussion_read_before($read_before,
      isset($conf['like_bravo_discussion_since']) ? $conf['like_bravo_discussion_since'] : null),
    'viewer' => like_bravo_feedback_viewer($owner_id),
    );
}

/**
 * What a member may see, as the core decides it for his pages: the albums
 * forbidden to him and his privacy level. Taken from the current visitor
 * when it is him, from his user cache otherwise.
 *
 * @param int $user_id
 * @return array 'forbidden' => int[], 'level' => int
 */
function like_bravo_feedback_viewer($user_id)
{
  global $user;

  $data = (isset($user['id'], $user['forbidden_categories'], $user['level']) and (int)$user['id'] === (int)$user_id)
    ? $user
    : getuserdata((int)$user_id, true);

  if (!is_array($data))
  {
    // no such member: nothing of others is visible
    return array('forbidden' => array(), 'level' => -1);
  }

  return array(
    'forbidden' => array_values(array_filter(array_map('intval', explode(',', (string)$data['forbidden_categories'])))),
    'level' => isset($data['level']) ? (int)$data['level'] : 0,
    );
}

/**
 * SQL condition: the member may see the photo $image_alias.id now, through
 * at least one album he is allowed and his privacy level, as on his pages.
 *
 * @param array $viewer as like_bravo_feedback_viewer()
 * @param string $image_alias alias of the images table
 * @return string
 */
function like_bravo_feedback_visible_sql($viewer, $image_alias)
{
  return $image_alias.'.level <= '.(int)$viewer['level'].'
    AND EXISTS (SELECT 1 FROM '.IMAGE_CATEGORY_TABLE.' AS ic
      WHERE ic.image_id = '.$image_alias.'.id'
      .(empty($viewer['forbidden']) ? '' : ' AND ic.category_id NOT IN ('.implode(',', array_map('intval', $viewer['forbidden'])).')').')';
}

/**
 * All feedback entries of a member as one SQL query, one row per entry:
 * - a vote: a like or bravo (rate 2 or more) of another account on one of
 *   his photos in a thumbs mode; votes of anonymous visitors only while
 *   guests take part;
 * - a comment: a validated comment of another account on one of his photos,
 *   whatever the mode;
 * - "Also commented" (a comment with discussion = 1): a validated comment of
 *   another account on a photo of someone else that he commented on, written
 *   after his first comment there, on a photo he may see now (see
 *   KONZEPT-AUCH-KOMMENTIERT). Like a comment on his photos, its read mark
 *   is the comment; a comment is never both for the same member.
 *
 * Columns: kind ('vote', 'comment'), image_id, ref_id (photo for a vote,
 * comment id for a comment), rater_id, rater_anonymous_id (key of the read
 * mark: the identifier for anonymous voters, '' otherwise), rate, name,
 * is_guest, t (time of the entry), date_only (vote without a time of its
 * own), read_at, content, edited_at, author_id (account of the voter or of
 * the author of the comment), discussion (0 or 1), owner_id and owner_name
 * (for "Also commented": who added the photo, the name null if the account
 * is gone), is_read.
 *
 * @param int $owner_id
 * @param array $scope as like_bravo_feedback_scope()
 * @return string
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
    rd.read_at, NULL AS content, NULL AS edited_at, r.user_id AS author_id,
    0 AS discussion, NULL AS owner_id, NULL AS owner_name
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

  $is_guest = '(c.author_id IS NULL OR c.author_id = '.(int)$conf['guest_id']
    .' OR (ui.status IS NOT NULL AND ui.status IN (\'guest\', \'generic\')))';

  if (!empty($scope['photos']))
  {
    $parts[] = '
SELECT \'comment\' AS kind, c.image_id, c.id AS ref_id,
    0 AS rater_id, \'\' AS rater_anonymous_id, NULL AS rate,
    c.author AS name, '.$is_guest.' AS is_guest,
    c.validation_date AS t,
    0 AS date_only,
    rd.read_at, c.content, ce.edited_at, c.author_id,
    0 AS discussion, NULL AS owner_id, NULL AS owner_name
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

  // "Also commented". Time: the validation, as for comments on his photos;
  // an edit sets the validation again, so an edited comment keeps the time
  // it was written and does not come back as unread.
  $parts[] = '
SELECT \'comment\' AS kind, c.image_id, c.id AS ref_id,
    0 AS rater_id, \'\' AS rater_anonymous_id, NULL AS rate,
    c.author AS name, '.$is_guest.' AS is_guest,
    CASE WHEN ce.comment_id IS NULL THEN c.validation_date ELSE c.date END AS t,
    0 AS date_only,
    rd.read_at, c.content, ce.edited_at, c.author_id,
    1 AS discussion, i.added_by AS owner_id, o.'.$username.' AS owner_name
  FROM (
    SELECT image_id, MIN(date) AS first_at
      FROM '.COMMENTS_TABLE.'
      WHERE author_id = '.$owner_id.'
        AND validated = \'true\'
      GROUP BY image_id
    ) AS mine
    JOIN '.COMMENTS_TABLE.' AS c ON c.image_id = mine.image_id
    JOIN '.IMAGES_TABLE.' AS i ON i.id = c.image_id
    LEFT JOIN '.USERS_TABLE.' AS o ON o.'.$user_key.' = i.added_by
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = c.author_id
    LEFT JOIN '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' AS ce ON ce.comment_id = c.id
    LEFT JOIN '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' AS rd ON rd.user_id = '.$owner_id.'
      AND rd.kind = \'comment\' AND rd.ref_id = c.id
      AND rd.rater_id = 0 AND rd.rater_anonymous_id = \'\'
  WHERE i.added_by != '.$owner_id.'
    AND c.validated = \'true\'
    AND c.validation_date IS NOT NULL
    AND (c.author_id IS NULL OR c.author_id != '.$owner_id.')
    AND c.date > mine.first_at
    AND '.like_bravo_feedback_visible_sql($scope['viewer'], 'i');

  // same rule as like_bravo_feedback_is_read(), with the start of each kind
  $before = function($time) {
    return '' === (string)$time ? 'FALSE' : 'f.t <= \''.pwg_db_real_escape_string($time).'\'';
  };
  $is_read = '(CASE WHEN f.discussion = 1 THEN '.$before($scope['discussion_read_before'])
    .' ELSE '.$before($scope['read_before']).' END)'
    .' OR (f.read_at IS NOT NULL AND f.read_at >= f.t)';

  return '
SELECT f.*, ('.$is_read.') AS is_read
  FROM ('.implode('
  UNION ALL', $parts).'
  ) AS f';
}

/**
 * SQL condition of a filter by kind on the rows of like_bravo_feedback_sql()
 * (alias e).
 *
 * @param string $kind 'all', 'votes', 'comments' (on his photos) or
 *    'discussions' ("Also commented")
 * @return string '' for all
 */
function like_bravo_feedback_kind_sql($kind)
{
  switch ($kind)
  {
    case 'votes':
      return 'e.kind = \'vote\'';
    case 'comments':
      return 'e.kind = \'comment\' AND e.discussion = 0';
    case 'discussions':
      return 'e.discussion = 1';
    default:
      return '';
  }
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
 * @param string $kind 'all', 'votes', 'comments' or 'discussions'
 * @param array|null $before cursor (like_bravo_feedback_cursor()): only the
 *    entries older than it, from the first of them; $page is then ignored.
 *    Unlike pages, a cursor skips nothing when entries are marked as read
 *    under the filter "unread" between two calls.
 * @return array 'entries' => list of entries (see like_bravo_feedback_entry()),
 *    'page' => int, 'per_page' => int, 'has_more' => bool
 */
function like_bravo_feedback_list($owner_id, $page=1, $per_page=20, $unread_only=false, $kind='all', $before=null)
{
  global $conf;

  $page = null === $before ? max(1, (int)$page) : 1;
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
  $kind_where = like_bravo_feedback_kind_sql($kind);
  if ('' !== $kind_where)
  {
    $where[] = $kind_where;
  }
  if (null !== $before)
  {
    // the order of the list below
    $where[] = '(e.t, e.kind, e.ref_id, e.rater_id, e.rater_anonymous_id) < (\''.pwg_db_real_escape_string($before['time']).'\', \''
      .pwg_db_real_escape_string($before['kind']).'\', '.(int)$before['ref_id'].', '.(int)$before['rater_id'].', \''
      .pwg_db_real_escape_string($before['rater_anonymous_id']).'\')';
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
 * @return array|null 'kind' => 'like', 'bravo', 'comment' or 'discussion'
 *    ("Also commented"), 'image_id', 'who', 'name' (see
 *    like_bravo_feedback_who()), 'author_id', 'time', 'date_only',
 *    'unread', 'read_key' => array('kind', 'ref_id', 'rater_id',
 *    'rater_anonymous_id'); for votes 'mode' and 'likes', 'bravos' of the
 *    photo; for comments and "Also commented" 'comment_id', 'excerpt',
 *    'edited'; for "Also commented" 'owner_id' and 'owner_name' (null if
 *    the account is gone)
 */
function like_bravo_feedback_entry($row, $scope, $names_visible, $totals)
{
  $image_id = (int)$row['image_id'];
  $discussion = !empty($row['discussion']);
  $entry = array(
    'image_id' => $image_id,
    'author_id' => (int)$row['author_id'],
    'time' => $row['t'],
    'date_only' => (bool)$row['date_only'],
    'unread' => !like_bravo_feedback_is_read($row['t'],
      $discussion ? $scope['discussion_read_before'] : $scope['read_before'], $row['read_at']),
    'read_key' => array(
      'kind' => $row['kind'],
      'ref_id' => (int)$row['ref_id'],
      'rater_id' => (int)$row['rater_id'],
      'rater_anonymous_id' => (string)$row['rater_anonymous_id'],
      ),
    );

  if ($discussion)
  {
    // edits do not show: the entry keeps the time the comment appeared
    return array_merge($entry, like_bravo_feedback_who(!empty($row['is_guest']), $row['name'], true), array(
      'kind' => 'discussion',
      'comment_id' => (int)$row['ref_id'],
      'excerpt' => like_bravo_excerpt($row['content']),
      'edited' => false,
      'owner_id' => (int)$row['owner_id'],
      'owner_name' => (null === $row['owner_name'] or '' === (string)$row['owner_name']) ? null : (string)$row['owner_name'],
      ));
  }

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
 * Text of an entry as HTML: the names in bold, the beginning of a comment in
 * italics. Each case is a whole sentence of its own in the language files,
 * because languages build them differently (German "gefallen" asks for the
 * dative: "Einem Gast gefällt dein Foto"). Sentences take the author as
 * %1$s (or %s) and, for "Also commented", the owner of the photo as %2$s.
 *
 * @param array $entry as like_bravo_feedback_entry(), with 'author_url' and
 *    'owner_url' for links to home albums
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
    'discussion' => array(
      'member' => '%1$s commented on a photo by %2$s that you commented on too:',
      'guest' => 'A guest commented on a photo by %2$s that you commented on too:',
      'former' => 'A former member commented on a photo by %2$s that you commented on too:',
      'member_gone' => '%1$s commented on a photo that you commented on too:',
      'guest_gone' => 'A guest commented on a photo that you commented on too:',
      'former_gone' => 'A former member commented on a photo that you commented on too:',
      'self' => '%1$s commented on their own photo that you commented on too:',
      ),
    );

  $group = $edited ? 'edited' : $entry['kind'];
  $who = 'discussion' === $group
    ? like_bravo_discussion_case($entry['who'], $entry['author_id'], $entry['owner_id'], $entry['owner_name'])
    : $entry['who'];
  if (!isset($keys[$group][$who]))
  {
    $who = 'former';
  }
  $html = htmlspecialchars(l10n($keys[$group][$who]));

  $author = '';
  if ('member' === $entry['who'])
  {
    $author = like_bravo_feedback_name_html($entry['name'], isset($entry['author_url']) ? $entry['author_url'] : null);
  }
  $owner = '';
  if ('discussion' === $group and null !== $entry['owner_name'])
  {
    $owner = like_bravo_feedback_name_html($entry['owner_name'], isset($entry['owner_url']) ? $entry['owner_url'] : null);
  }
  if ('' !== $author or '' !== $owner)
  {
    $html = sprintf($html, $author, $owner);
  }

  if ('comment' === $entry['kind'] or 'discussion' === $entry['kind'])
  {
    $html.= ' <em>'.htmlspecialchars($entry['excerpt']).'</em>';
  }

  return $html;
}

/**
 * Name of a member in the text of an entry: bold, with a link to his home
 * album if there is one.
 *
 * @param string $name
 * @param string|null $url
 * @return string HTML
 */
function like_bravo_feedback_name_html($name, $url)
{
  $html = htmlspecialchars($name);
  if (!empty($url))
  {
    $html = '<a class="lb-bell-author" href="'.htmlspecialchars($url).'" data-ajax="false">'.$html.'</a>';
  }

  return '<strong>'.$html.'</strong>';
}

/**
 * Short text for the time of an entry: "just now", "5 min ago", "2 h ago",
 * "today", "yesterday, 17:12", "Saturday, 21 November" or the date.
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
      return $date_only ? l10n('yesterday') : sprintf(l10n('yesterday, %s'), date('H:i', strtotime($time)));
    case 'week':
      global $lang;
      $then = strtotime($time);
      return sprintf(l10n('%1$s, %2$d %3$s'), $lang['day'][(int)date('w', $then)], (int)date('j', $then), $lang['month'][(int)date('n', $then)]);
    default:
      return format_date($time, array('day', 'month', 'year'));
  }
}

/**
 * Entries of the feedback as the bell and the page "All feedback" show
 * them: text, short time, photo page and square thumbnail; for votes the
 * totals of the photo with a text for screen readers.
 *
 * @param array $entries as like_bravo_feedback_list()['entries']
 * @return array list of arrays 'key', 'kind', 'unread', 'html', 'when',
 *    'time', 'day' (see like_bravo_feedback_day()), 'cursor', 'image_id',
 *    'url', 'thumb', 'thumb_alt'; for votes also
 *    'likes', 'bravos', 'with_bravo', 'totals_text'; 'kind' is 'like',
 *    'bravo', 'comment' or 'discussion'
 */
function like_bravo_feedback_items($entries)
{
  include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');

  $images = like_bravo_feedback_photos(array_column($entries, 'image_id'));
  $members = array();
  foreach ($entries as $entry)
  {
    if ('member' === $entry['who'])
    {
      $members[] = $entry['author_id'];
    }
    if ('discussion' === $entry['kind'] and null !== $entry['owner_name'])
    {
      $members[] = $entry['owner_id'];
    }
  }
  $albums = like_bravo_home_album_urls($members);
  list($now) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));

  $items = array();
  foreach ($entries as $entry)
  {
    if (!isset($images[$entry['image_id']]))
    {
      continue;
    }
    $photo = $images[$entry['image_id']];
    if ('member' === $entry['who'] and isset($albums[$entry['author_id']]))
    {
      $entry['author_url'] = $albums[$entry['author_id']];
    }
    if ('discussion' === $entry['kind'] and null !== $entry['owner_name'] and isset($albums[$entry['owner_id']]))
    {
      $entry['owner_url'] = $albums[$entry['owner_id']];
    }

    $item = array(
      'key' => $entry['read_key'],
      'kind' => $entry['kind'],
      'unread' => $entry['unread'],
      'html' => like_bravo_feedback_html($entry),
      'when' => like_bravo_feedback_when_text($entry['time'], $now, $entry['date_only']),
      'time' => $entry['time'],
      'day' => like_bravo_feedback_day($entry['time'], $now),
      'cursor' => like_bravo_feedback_cursor_text($entry['time'], $entry['read_key']),
      'image_id' => (int)$entry['image_id'],
      'url' => $photo['url'].(isset($entry['comment_id']) ? '#comments' : ''),
      'thumb' => $photo['thumb'],
      'thumb_alt' => $photo['name'],
      );

    if (isset($entry['likes']))
    {
      $item['likes'] = $entry['likes'];
      $item['bravos'] = $entry['bravos'];
      $item['with_bravo'] = (LIKE_BRAVO_MODE_BOTH == $entry['mode']);
      $item['totals_text'] = like_bravo_count_text($entry['likes'], '%d like', '%d likes')
        .($item['with_bravo'] ? ', '.like_bravo_count_text($entry['bravos'], '%d bravo', '%d bravos') : '');
    }

    $items[] = $item;
  }

  return $items;
}

/**
 * Photo page, square thumbnail and name of some photos.
 *
 * @param int[] $image_ids
 * @return array id => array 'url', 'thumb', 'name'
 */
function like_bravo_feedback_photos($image_ids)
{
  $image_ids = array_unique(array_map('intval', $image_ids));
  if (empty($image_ids))
  {
    return array();
  }

  $photos = array();
  foreach (query2array('
SELECT id, file, name, path, representative_ext, width, height, rotation
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;') as $image)
  {
    $photos[(int)$image['id']] = array(
      'url' => make_picture_url(array('image_id' => $image['id'], 'image_file' => $image['file'])),
      'thumb' => DerivativeImage::url(IMG_SQUARE, new SrcImage($image)),
      'name' => trigger_change('render_element_name',
        empty($image['name']) ? get_name_from_file($image['file']) : $image['name']),
      );
  }

  return $photos;
}

/**
 * Number of entries of the feedback of a member, all and unread, for one
 * kind or all.
 *
 * @param int $owner_id
 * @param string $kind 'all', 'votes', 'comments' or 'discussions'
 * @return array 'all' => int, 'unread' => int
 */
function like_bravo_feedback_counts($owner_id, $kind='all')
{
  $scope = like_bravo_feedback_scope($owner_id);
  $sql = like_bravo_feedback_sql($owner_id, $scope);
  if (null === $sql)
  {
    return array('all' => 0, 'unread' => 0);
  }

  $kind_where = like_bravo_feedback_kind_sql($kind);
  $where = '' === $kind_where ? '' : '
  WHERE '.$kind_where;

  list($all, $unread) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*), COALESCE(SUM(NOT e.is_read), 0)
  FROM ('.$sql.') AS e'.$where.'
;'));

  return array('all' => (int)$all, 'unread' => (int)$unread);
}

/**
 * Likes, bravos and comments others gave to each photo of a member, as the
 * feedback counts them: votes on photos in a thumbs mode, as the mode of the
 * album shows them.
 *
 * @param int $owner_id
 * @param int $days only the last days, 0 for all
 * @return array 'photos' => image id => array 'likes', 'bravos', 'comments';
 *    'with_bravo' => bool, some photo is in an album with bravos
 */
function like_bravo_feedback_tally($owner_id, $days=0)
{
  $scope = like_bravo_feedback_scope($owner_id);
  $result = array('photos' => array(), 'with_bravo' => in_array(LIKE_BRAVO_MODE_BOTH, $scope['thumbs']));
  $sql = like_bravo_feedback_sql($owner_id, $scope);
  if (null === $sql)
  {
    return $result;
  }

  $rows = query2array('
SELECT e.image_id, e.kind, e.rate, COUNT(*) AS n
  FROM ('.$sql.') AS e
  WHERE e.discussion = 0'
  .($days > 0 ? '
    AND e.t >= NOW() - INTERVAL '.(int)$days.' DAY' : '').'
  GROUP BY e.image_id, e.kind, e.rate
;');

  foreach ($rows as $row)
  {
    $id = (int)$row['image_id'];
    if (!isset($result['photos'][$id]))
    {
      $result['photos'][$id] = array('likes' => 0, 'bravos' => 0, 'comments' => 0);
    }
    if ('comment' === $row['kind'])
    {
      $result['photos'][$id]['comments'] += (int)$row['n'];
      continue;
    }
    $level = like_bravo_classify_rate($row['rate'], isset($scope['thumbs'][$id]) ? $scope['thumbs'][$id] : LIKE_BRAVO_MODE_STARS);
    if ('like' === $level or 'bravo' === $level)
    {
      $result['photos'][$id][$level.'s'] += (int)$row['n'];
    }
  }

  return $result;
}

/**
 * The photos of a tally with the most feedback.
 *
 * @param array $tally as like_bravo_feedback_tally()
 * @param int $limit
 * @return array list of arrays 'url', 'thumb', 'name', 'likes', 'bravos',
 *    'comments'
 */
function like_bravo_feedback_top_photos($tally, $limit=3)
{
  $counts = $tally['photos'];
  uksort($counts, function($a, $b) use ($counts) {
    $diff = array_sum($counts[$b]) - array_sum($counts[$a]);
    return 0 !== $diff ? $diff : $b - $a;
  });
  $counts = array_slice($counts, 0, $limit, true);

  $top = array();
  $photos = like_bravo_feedback_photos(array_keys($counts));
  foreach ($counts as $id => $count)
  {
    if (isset($photos[$id]))
    {
      $top[] = array_merge($photos[$id], $count);
    }
  }

  return $top;
}

/**
 * Home albums of members, as the plugin Community keeps them (a user album
 * per member, marked in the column community_user of the albums), for a
 * link on the name of a member in the feedback. Only while Community is
 * active, and only albums the current visitor may see.
 *
 * @param int[] $user_ids
 * @return array user id => URL of the album
 */
function like_bravo_home_album_urls($user_ids)
{
  global $pwg_loaded_plugins;

  $user_ids = array_unique(array_filter(array_map('intval', $user_ids)));
  if (empty($user_ids) or !isset($pwg_loaded_plugins['community']))
  {
    return array();
  }

  $urls = array();
  $albums = query2array('
SELECT id, name, permalink, community_user
  FROM '.CATEGORIES_TABLE.'
  WHERE community_user IN ('.implode(',', $user_ids).')
'.get_sql_condition_FandF(array('forbidden_categories' => 'id', 'visible_categories' => 'id'), 'AND').'
  ORDER BY id
;');
  foreach ($albums as $album)
  {
    if (!isset($urls[(int)$album['community_user']]))
    {
      $urls[(int)$album['community_user']] = make_index_url(array('category' => $album));
    }
  }

  return $urls;
}
