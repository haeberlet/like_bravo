<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Rating scores and votes in the database. The formulas live in
 * functions_logic.inc.php.
 */

/**
 * @return array image_id => mode for all photos in a thumbs mode
 */
function club_likes_get_all_image_modes()
{
  $explicit_modes = club_likes_get_explicit_modes();

  if (empty($explicit_modes))
  {
    return array();
  }

  $query = '
SELECT id, uppercats
  FROM '.CATEGORIES_TABLE.'
;';
  $cat_modes = array();
  foreach (query2array($query, 'id', 'uppercats') as $cat_id => $uppercats)
  {
    $resolved = club_likes_resolve_mode($uppercats, $explicit_modes);
    if (CLUB_LIKES_MODE_INACTIVE != $resolved['mode'])
    {
      $cat_modes[(int)$cat_id] = $resolved['mode'];
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

  return array_map('club_likes_combine_modes', $modes_of);
}

/**
 * Recomputes images.rating_score for the whole gallery and writes the
 * scores that changed.
 *
 * @return array as club_likes_compute_scores()
 */
function club_likes_recompute_scores()
{
  global $conf;

  $query = '
SELECT element_id, rate, (user_id = '.(int)$conf['guest_id'].') AS is_guest, COUNT(*) AS n
  FROM '.RATE_TABLE.'
  GROUP BY element_id, rate, is_guest
;';
  $results = club_likes_compute_scores(
    query2array($query),
    club_likes_get_all_image_modes(),
    $conf['club_likes']['score_constant']
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
function club_likes_update_rating_score($alt_result, $element_id=false)
{
  $results = club_likes_recompute_scores();

  if (false !== $element_id and isset($results[(int)$element_id]))
  {
    return $results[(int)$element_id];
  }

  return array('score' => null, 'average' => null, 'count' => 0);
}

/**
 * Likes and bravos of one photo.
 *
 * @param int $image_id
 * @param int $mode mode of the photo
 * @return array 'likes' => int, 'bravos' => int, 'dormant' => int
 */
function club_likes_get_image_votes($image_id, $mode)
{
  global $conf;

  $query = '
SELECT rate
  FROM '.RATE_TABLE.'
  WHERE element_id = '.(int)$image_id.'
    AND user_id != '.(int)$conf['guest_id'].'
;';

  return club_likes_count_votes(query2array($query, null, 'rate'), $mode);
}

/**
 * Vote of one member on one photo.
 *
 * @param int $image_id
 * @param int $user_id
 * @param int $mode mode of the photo
 * @return string 'none', 'like' or 'bravo'
 */
function club_likes_get_user_level($image_id, $user_id, $mode)
{
  $query = '
SELECT rate
  FROM '.RATE_TABLE.'
  WHERE element_id = '.(int)$image_id.'
    AND user_id = '.(int)$user_id.'
;';
  $rates = query2array($query, null, 'rate');

  $level = empty($rates) ? null : club_likes_classify_rate($rates[0], $mode);

  return null === $level ? 'none' : $level;
}

/**
 * Replaces the vote of a member: removes the stored rate and, unless the
 * level is 'none', stores the new one. Then recomputes the scores.
 *
 * @param int $image_id
 * @param int $user_id
 * @param string $level 'none', 'like' or 'bravo'
 */
function club_likes_set_user_level($image_id, $user_id, $level)
{
  $image_id = (int)$image_id;
  $user_id = (int)$user_id;

  $query = '
DELETE FROM '.RATE_TABLE.'
  WHERE element_id = '.$image_id.'
    AND user_id = '.$user_id.'
;';
  pwg_query($query);

  $rate = club_likes_level_to_rate($level);
  if (null !== $rate)
  {
    // same anonymous_id as the core writes for a member: IP without last part
    $ip_components = explode('.', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    if (count($ip_components) > 3)
    {
      array_pop($ip_components);
    }
    $anonymous_id = substr(implode('.', $ip_components), 0, 45);

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
