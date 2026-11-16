<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Rating scores and votes in the database. The formulas live in
 * functions_logic.inc.php.
 */

/**
 * @return array image_id => mode for all photos not in the mode stars
 *    (thumbs modes and no rating)
 */
function like_bravo_get_all_image_modes()
{
  $explicit_modes = like_bravo_get_explicit_modes();

  if (empty($explicit_modes))
  {
    return array();
  }

  // albums without any setting rank lowest and never decide a photo's mode,
  // so only albums with a setting of their own or of a parent are needed
  $query = '
SELECT id, uppercats
  FROM '.CATEGORIES_TABLE.'
;';
  $cat_modes = array();
  foreach (query2array($query, 'id', 'uppercats') as $cat_id => $uppercats)
  {
    $resolved = like_bravo_resolve_mode($uppercats, $explicit_modes);
    if (null !== $resolved['source'])
    {
      $cat_modes[(int)$cat_id] = $resolved;
    }
  }

  if (empty($cat_modes))
  {
    return array();
  }

  $query = '
SELECT image_id, category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.implode(',', array_keys($cat_modes)).')
;';
  $modes_of = array();
  foreach (query2array($query) as $row)
  {
    $modes_of[(int)$row['image_id']][] = $cat_modes[(int)$row['category_id']];
  }

  $image_modes = array();
  foreach ($modes_of as $image_id => $resolved_modes)
  {
    $mode = like_bravo_combine_modes($resolved_modes);
    if (LIKE_BRAVO_MODE_STARS != $mode)
    {
      $image_modes[$image_id] = $mode;
    }
  }

  return $image_modes;
}

/**
 * @return bool true if anonymous visitors may vote on thumbs photos and
 *    their votes count
 */
function like_bravo_guests_take_part_now()
{
  global $conf;

  return like_bravo_guests_take_part($conf['like_bravo']['allow_guests'], $conf['rate_anonymous']);
}

/**
 * @return bool true if the current visitor is anonymous in the sense of
 *    the core rating: below the status "normal" (guest or "generic")
 */
function like_bravo_is_anonymous()
{
  return !is_autorize_status(ACCESS_CLASSIC);
}

/**
 * SQL expression telling whether a row of the rate table is the rate of an
 * anonymous visitor: stored for the guest account or for a "generic" account.
 *
 * @param string $rate_alias alias of the rate table
 * @param string $infos_alias alias of the left joined user_infos table
 * @return string
 */
function like_bravo_sql_is_guest($rate_alias, $infos_alias)
{
  global $conf;

  return '('.$rate_alias.'.user_id = '.(int)$conf['guest_id']
    .' OR ('.$infos_alias.'.status IS NOT NULL AND '.$infos_alias.'.status IN (\'guest\', \'generic\')))';
}

/**
 * Recomputes images.rating_score for the whole gallery and writes the
 * scores that changed.
 *
 * @return array as like_bravo_compute_scores()
 */
function like_bravo_recompute_scores()
{
  global $conf;

  $query = '
SELECT r.element_id, r.rate, '.like_bravo_sql_is_guest('r', 'ui').' AS is_guest, COUNT(*) AS n
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
  GROUP BY r.element_id, r.rate, is_guest
;';
  $results = like_bravo_compute_scores(
    query2array($query),
    like_bravo_get_all_image_modes(),
    like_bravo_guests_take_part_now()
    );

  $query = '
SELECT id, rating_score
  FROM '.IMAGES_TABLE.'
  WHERE rating_score IS NOT NULL
;';
  $current = query2array($query, 'id', 'rating_score');

  $updates = array();
  foreach ($results as $id => $result)
  {
    if (null === $result['score'])
    {
      continue;
    }
    if (!isset($current[$id]) or (float)$current[$id] != $result['score'])
    {
      $updates[] = array('id' => $id, 'rating_score' => $result['score']);
    }
    unset($current[$id]);
  }

  mass_updates(
    IMAGES_TABLE,
    array('primary' => array('id'), 'update' => array('rating_score')),
    $updates
    );

  // what is left has a score but no counting rate any more
  if (!empty($current))
  {
    $query = '
UPDATE '.IMAGES_TABLE.'
  SET rating_score = NULL
  WHERE id IN ('.implode(',', array_map('intval', array_keys($current))).')
;';
    pwg_query($query);
  }

  return $results;
}

/**
 * Event handler replacing the core computation of update_rating_score().
 * Like the core it always recomputes the whole gallery.
 *
 * @param mixed $alt_result
 * @param int|false $element_id
 * @return array score, average and count of $element_id
 */
function like_bravo_update_rating_score($alt_result, $element_id=false)
{
  $results = like_bravo_recompute_scores();

  if (false !== $element_id and isset($results[(int)$element_id]))
  {
    return $results[(int)$element_id];
  }

  return array('score' => null, 'average' => null, 'count' => 0);
}

/**
 * Likes and bravos of one photo. Rates of anonymous visitors are counted
 * only while guests take part.
 *
 * @param int $image_id
 * @param int $mode mode of the photo
 * @return array 'likes' => int, 'bravos' => int, 'dormant' => int
 */
function like_bravo_get_image_votes($image_id, $mode)
{
  $query = '
SELECT r.rate
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
  WHERE r.element_id = '.(int)$image_id;

  if (!like_bravo_guests_take_part_now())
  {
    $query.= '
    AND NOT '.like_bravo_sql_is_guest('r', 'ui');
  }
  $query.= '
;';

  return like_bravo_count_votes(query2array($query, null, 'rate'), $mode);
}

/**
 * Vote of one member or of one anonymous visitor on one photo.
 *
 * @param int $image_id
 * @param int $user_id
 * @param int $mode mode of the photo
 * @param string|null $anonymous_id for an anonymous visitor: only the rate
 *    stored with this identifier is his own
 * @return string 'none', 'like' or 'bravo'
 */
function like_bravo_get_user_level($image_id, $user_id, $mode, $anonymous_id=null)
{
  $query = '
SELECT rate
  FROM '.RATE_TABLE.'
  WHERE element_id = '.(int)$image_id.'
    AND user_id = '.(int)$user_id;

  if (null !== $anonymous_id)
  {
    $query.= '
    AND anonymous_id = \''.pwg_db_real_escape_string($anonymous_id).'\'';
  }
  $query.= '
;';
  $rates = query2array($query, null, 'rate');

  $level = empty($rates) ? null : like_bravo_classify_rate($rates[0], $mode);

  return null === $level ? 'none' : $level;
}

/**
 * @return string identifier the core stores with the rates of this visitor
 */
function like_bravo_current_anonymous_id()
{
  return substr(like_bravo_anonymous_id(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
}

/**
 * Replaces the vote of the current anonymous visitor (guest or "generic"
 * account). All visitors of such an account share one user id, so only the
 * row with the visitor's own identifier is touched.
 *
 * A vote is stored by rate_picture() of the core, which also follows a
 * visitor whose address changed (cookie pwg_anonymous_rater).
 *
 * @param int $image_id
 * @param string $level 'none', 'like' or 'bravo'
 * @return bool false if the core refused the rate
 */
function like_bravo_set_anonymous_level($image_id, $level)
{
  global $user;

  $image_id = (int)$image_id;
  include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');

  $rate = like_bravo_level_to_rate($level);
  if (null !== $rate)
  {
    return false !== rate_picture($image_id, $rate);
  }

  $query = '
DELETE FROM '.RATE_TABLE.'
  WHERE element_id = '.$image_id.'
    AND user_id = '.(int)$user['id'].'
    AND anonymous_id = \''.pwg_db_real_escape_string(like_bravo_current_anonymous_id()).'\'
;';
  pwg_query($query);

  update_rating_score($image_id);

  return true;
}

/**
 * Replaces the vote of a member: removes the stored rate and, unless the
 * level is 'none', stores the new one. Then recomputes the scores.
 *
 * @param int $image_id
 * @param int $user_id
 * @param string $level 'none', 'like' or 'bravo'
 */
function like_bravo_set_user_level($image_id, $user_id, $level)
{
  $image_id = (int)$image_id;
  $user_id = (int)$user_id;

  $query = '
DELETE FROM '.RATE_TABLE.'
  WHERE element_id = '.$image_id.'
    AND user_id = '.$user_id.'
;';
  pwg_query($query);

  $rate = like_bravo_level_to_rate($level);
  if (null !== $rate)
  {
    // the core writes the identifier for members as well
    $anonymous_id = like_bravo_current_anonymous_id();

    $query = '
INSERT INTO '.RATE_TABLE.'
  (user_id, anonymous_id, element_id, rate, date)
  VALUES
  ('.$user_id.', \''.pwg_db_real_escape_string($anonymous_id).'\', '.$image_id.', '.(int)$rate.', NOW())
;';
    pwg_query($query);
  }

  include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
  update_rating_score($image_id);
}
