<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Pure business rules of Club Likes: no database access, no globals.
 * Everything in this file is covered by tests/run.php.
 */

// Album modes. The numeric order is the conflict rule: the highest mode wins.
// "Inherited" is not a stored value, it is the absence of an explicit mode.
define('CLUB_LIKES_MODE_INACTIVE', 0);
define('CLUB_LIKES_MODE_LIKE', 1);
define('CLUB_LIKES_MODE_LIKE_BRAVO', 2);

// Values written to the native rate table.
define('CLUB_LIKES_RATE_LIKE', 3);
define('CLUB_LIKES_RATE_BRAVO', 5);

/**
 * @return int[] all modes that can be stored for an album
 */
function club_likes_valid_modes()
{
  return array(
    CLUB_LIKES_MODE_INACTIVE,
    CLUB_LIKES_MODE_LIKE,
    CLUB_LIKES_MODE_LIKE_BRAVO,
    );
}

/**
 * @return string[] names of the icons selectable for like and bravo;
 *    the drawings live in include/icons.inc.php
 */
function club_likes_vote_icon_names()
{
  return array('thumb', 'heart', 'smile', 'star', 'sparkle', 'trophy', 'medal');
}

/**
 * @return string[] names of the icons selectable for "remove my vote";
 *    'none' means no separate icon (click the active icon again)
 */
function club_likes_undo_icon_names()
{
  return array('none', 'ban', 'cross');
}

/**
 * @return array default plugin configuration
 */
function club_likes_default_conf()
{
  return array(
    'icon_like' => 'thumb',
    'icon_bravo' => 'star',
    'icon_undo' => 'none',
    'label_title' => '',    // empty labels = translated default
    'label_like' => '',
    'label_bravo' => '',
    'score_constant' => 5.0,
    'allow_own_photos' => true,
    );
}

/**
 * Fills a stored configuration with defaults, drops unknown keys and
 * replaces invalid values by their default.
 *
 * @param mixed $stored value read from the config table or built from a form
 * @return array
 */
function club_likes_normalize_conf($stored)
{
  $defaults = club_likes_default_conf();

  if (!is_array($stored))
  {
    $stored = array();
  }

  $conf = array_merge($defaults, array_intersect_key($stored, $defaults));

  foreach (array('icon_like', 'icon_bravo') as $key)
  {
    if (!in_array($conf[$key], club_likes_vote_icon_names(), true))
    {
      $conf[$key] = $defaults[$key];
    }
  }
  if (!in_array($conf['icon_undo'], club_likes_undo_icon_names(), true))
  {
    $conf['icon_undo'] = $defaults['icon_undo'];
  }

  foreach (array('label_title', 'label_like', 'label_bravo') as $key)
  {
    $label = is_string($conf[$key]) ? trim(strip_tags($conf[$key])) : '';
    $conf[$key] = function_exists('mb_substr') ? mb_substr($label, 0, 40) : substr($label, 0, 40);
  }

  if (!club_likes_is_valid_score_constant($conf['score_constant']))
  {
    $conf['score_constant'] = $defaults['score_constant'];
  }
  $conf['score_constant'] = (float)$conf['score_constant'];
  $conf['allow_own_photos'] = (bool)$conf['allow_own_photos'];

  return $conf;
}

/**
 * @param mixed $value
 * @return bool true for a number greater than 0 and at most 1000
 */
function club_likes_is_valid_score_constant($value)
{
  return is_numeric($value) and $value > 0 and $value <= 1000;
}

/**
 * Resolves the effective mode of an album: its own explicit mode, else the
 * one of the nearest parent album having one, else inactive.
 *
 * @param string $uppercats categories.uppercats of the album: ids from the
 *    root down to the album itself, comma separated
 * @param array $explicit_modes cat_id => mode, albums with an explicit mode
 * @return array 'mode' => int, 'source' => int|null id of the album the mode
 *    comes from (the album itself when set explicitly, null when none)
 */
function club_likes_resolve_mode($uppercats, $explicit_modes)
{
  $ids = array_filter(explode(',', (string)$uppercats), 'strlen');

  foreach (array_reverse($ids) as $id)
  {
    $id = (int)$id;
    if (isset($explicit_modes[$id]))
    {
      return array('mode' => (int)$explicit_modes[$id], 'source' => $id);
    }
  }

  return array('mode' => CLUB_LIKES_MODE_INACTIVE, 'source' => null);
}

/**
 * Mode of a photo from the effective modes of all its albums.
 * The highest mode wins; a photo without album is inactive.
 *
 * @param int[] $modes
 * @return int
 */
function club_likes_combine_modes($modes)
{
  return empty($modes) ? CLUB_LIKES_MODE_INACTIVE : (int)max($modes);
}

/**
 * How a stored rate counts in a given mode.
 *
 * Like + Bravo: 3 = like, 4-5 = bravo. Like: 3-5 = like.
 * Rates 0-2 are dormant: kept in the database but not counted.
 *
 * @param int $rate
 * @param int $mode
 * @return string|null 'like', 'bravo' or null if the rate does not count
 */
function club_likes_classify_rate($rate, $mode)
{
  $rate = (int)$rate;

  if ($mode == CLUB_LIKES_MODE_INACTIVE or $rate < CLUB_LIKES_RATE_LIKE)
  {
    return null;
  }

  if ($mode == CLUB_LIKES_MODE_LIKE_BRAVO and $rate > CLUB_LIKES_RATE_LIKE)
  {
    return 'bravo';
  }

  return 'like';
}

/**
 * @param int[] $rates all stored rates of one photo
 * @param int $mode
 * @return array 'likes' => int, 'bravos' => int, 'dormant' => int
 */
function club_likes_count_votes($rates, $mode)
{
  $counts = array('likes' => 0, 'bravos' => 0, 'dormant' => 0);

  foreach ($rates as $rate)
  {
    $level = club_likes_classify_rate($rate, $mode);
    if ('like' == $level)
    {
      $counts['likes']++;
    }
    elseif ('bravo' == $level)
    {
      $counts['bravos']++;
    }
    else
    {
      $counts['dormant']++;
    }
  }

  return $counts;
}

/**
 * Score of a photo in a thumbs mode: 5 * P / (P + k), P = likes + 2 * bravos.
 * Rises with every vote and stays below 5, like a star rating score.
 *
 * @param int $likes
 * @param int $bravos
 * @param float $k score constant (points needed to reach half of the maximum)
 * @return float|null null without any counting vote (photo is "not rated")
 */
function club_likes_score($likes, $bravos, $k)
{
  $points = $likes + 2 * $bravos;

  if ($points <= 0)
  {
    return null;
  }

  return round(5 * $points / ($points + $k), 2);
}

/**
 * @param int $mode
 * @return string[] vote levels a member may set in this mode
 */
function club_likes_allowed_levels($mode)
{
  switch ($mode)
  {
    case CLUB_LIKES_MODE_LIKE:
      return array('none', 'like');
    case CLUB_LIKES_MODE_LIKE_BRAVO:
      return array('none', 'like', 'bravo');
    default:
      return array();
  }
}

/**
 * @param string $level 'like' or 'bravo'
 * @return int|null value for the rate table, null for any other level
 */
function club_likes_level_to_rate($level)
{
  switch ($level)
  {
    case 'like':
      return CLUB_LIKES_RATE_LIKE;
    case 'bravo':
      return CLUB_LIKES_RATE_BRAVO;
    default:
      return null;
  }
}

/**
 * Computes the rating score of every rated photo.
 *
 * Star photos (inactive mode) get the Piwigo core score, a bayesian average
 *   (C * m + sum of rates) / (C + number of rates)
 * with m = average of all rates and C = average number of rates per photo.
 * m and C are taken from star photos only, so likes and bravos elsewhere in
 * the gallery never move the score of a star photo.
 *
 * Photos in a thumbs mode get club_likes_score(). Rates of guests do not
 * count there: likes and bravos are bound to a member account.
 *
 * @param array $rate_rows rows of 'element_id', 'rate', 'is_guest' and 'n'
 *    (number of identical rates)
 * @param array $image_modes image_id => mode, photos in a thumbs mode;
 *    any photo not listed is a star photo
 * @param float $k score constant for thumbs photos
 * @return array image_id => 'score' (float|null), 'average' (float|null),
 *    'count' (int); thumbs photos also have 'likes' and 'bravos'
 */
function club_likes_compute_scores($rate_rows, $image_modes, $k)
{
  $stars = array();
  $thumbs = array();

  foreach ($rate_rows as $row)
  {
    $id = (int)$row['element_id'];
    $n = (int)$row['n'];
    $mode = isset($image_modes[$id]) ? (int)$image_modes[$id] : CLUB_LIKES_MODE_INACTIVE;

    if (CLUB_LIKES_MODE_INACTIVE == $mode)
    {
      if (!isset($stars[$id]))
      {
        $stars[$id] = array('count' => 0, 'sum' => 0);
      }
      $stars[$id]['count'] += $n;
      $stars[$id]['sum'] += $n * (int)$row['rate'];
      continue;
    }

    if (!isset($thumbs[$id]))
    {
      $thumbs[$id] = array('likes' => 0, 'bravos' => 0);
    }
    if (empty($row['is_guest']))
    {
      $level = club_likes_classify_rate($row['rate'], $mode);
      if ('like' == $level)
      {
        $thumbs[$id]['likes'] += $n;
      }
      elseif ('bravo' == $level)
      {
        $thumbs[$id]['bravos'] += $n;
      }
    }
  }

  $results = array();

  if (!empty($stars))
  {
    $all_count = array_sum(array_column($stars, 'count'));
    $all_sum = array_sum(array_column($stars, 'sum'));
    $rate_avg = $all_sum / $all_count;
    $count_avg = $all_count / count($stars);

    foreach ($stars as $id => $item)
    {
      $results[$id] = array(
        'score' => round(($count_avg * $rate_avg + $item['sum']) / ($count_avg + $item['count']), 2),
        'average' => round($item['sum'] / $item['count'], 2),
        'count' => $item['count'],
        );
    }
  }

  foreach ($thumbs as $id => $item)
  {
    $results[$id] = array(
      'score' => club_likes_score($item['likes'], $item['bravos'], $k),
      'average' => null,
      'count' => $item['likes'] + $item['bravos'],
      'likes' => $item['likes'],
      'bravos' => $item['bravos'],
      );
  }

  return $results;
}
