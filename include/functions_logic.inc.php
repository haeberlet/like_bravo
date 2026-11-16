<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Pure business rules of Like & Bravo: no database access, no globals.
 * Everything in this file is covered by tests/run.php.
 */

// Album modes. The numeric order is the conflict rule: the highest mode wins.
// "Inherited" is not a stored value, it is the absence of an explicit mode.
define('LIKE_BRAVO_MODE_INACTIVE', 0);
define('LIKE_BRAVO_MODE_LIKE', 1);
define('LIKE_BRAVO_MODE_BOTH', 2);

// Values written to the native rate table, which are also the points a vote
// is worth. With the six stars of Piwigo (0 to 5) a like is the third star
// and a bravo the fifth: the top star stays free for a later star rating.
define('LIKE_BRAVO_RATE_LIKE', 2);
define('LIKE_BRAVO_RATE_BRAVO', 4);

/**
 * @return int[] all modes that can be stored for an album
 */
function like_bravo_valid_modes()
{
  return array(
    LIKE_BRAVO_MODE_INACTIVE,
    LIKE_BRAVO_MODE_LIKE,
    LIKE_BRAVO_MODE_BOTH,
    );
}

/**
 * @return string[] names of the icons selectable for like and bravo;
 *    the drawings live in include/icons.inc.php
 */
function like_bravo_vote_icon_names()
{
  return array('thumb', 'heart', 'smile', 'star', 'sparkle', 'trophy', 'medal');
}

/**
 * @return string[] names of the icons selectable for "remove my vote";
 *    'none' means no separate icon (click the active icon again)
 */
function like_bravo_undo_icon_names()
{
  return array('none', 'ban', 'cross');
}

/**
 * @return array default plugin configuration
 */
function like_bravo_default_conf()
{
  return array(
    'icon_like' => 'thumb',
    'icon_bravo' => 'star',
    'icon_undo' => 'none',
    'allow_own_photos' => true,
    'allow_guests' => false,
    );
}

/**
 * Fills a stored configuration with defaults, drops unknown keys and
 * replaces invalid values by their default.
 *
 * @param mixed $stored value read from the config table or built from a form
 * @return array
 */
function like_bravo_normalize_conf($stored)
{
  $defaults = like_bravo_default_conf();

  if (!is_array($stored))
  {
    $stored = array();
  }

  $conf = array_merge($defaults, array_intersect_key($stored, $defaults));

  foreach (array('icon_like', 'icon_bravo') as $key)
  {
    if (!in_array($conf[$key], like_bravo_vote_icon_names(), true))
    {
      $conf[$key] = $defaults[$key];
    }
  }
  if (!in_array($conf['icon_undo'], like_bravo_undo_icon_names(), true))
  {
    $conf['icon_undo'] = $defaults['icon_undo'];
  }

  $conf['allow_own_photos'] = (bool)$conf['allow_own_photos'];
  $conf['allow_guests'] = (bool)$conf['allow_guests'];

  return $conf;
}

/**
 * Whether anonymous visitors (guests and "generic" accounts) take part on
 * thumbs photos: they may vote and their votes count. The plugin option
 * only works together with the Piwigo option "Rating by guests".
 *
 * @param bool $allow_guests plugin option
 * @param bool $rate_anonymous $conf['rate_anonymous'] of Piwigo
 * @return bool
 */
function like_bravo_guests_take_part($allow_guests, $rate_anonymous)
{
  return (bool)$allow_guests and (bool)$rate_anonymous;
}

/**
 * Identifier Piwigo stores with every rate, the only thing that tells two
 * anonymous visitors apart. Same rule as rate_picture() in the core: the
 * address is split at dots and, with more than three parts, the last one is
 * dropped. All of an IPv4 /24 network share one identifier; an IPv6 address
 * has no dots and is kept whole.
 *
 * @param string $remote_addr
 * @return string
 */
function like_bravo_anonymous_id($remote_addr)
{
  $parts = explode('.', (string)$remote_addr);

  if (count($parts) > 3)
  {
    array_pop($parts);
  }

  return implode('.', $parts);
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
function like_bravo_resolve_mode($uppercats, $explicit_modes)
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

  return array('mode' => LIKE_BRAVO_MODE_INACTIVE, 'source' => null);
}

/**
 * Mode of a photo from the effective modes of all its albums.
 * The highest mode wins; a photo without album is inactive.
 *
 * @param int[] $modes
 * @return int
 */
function like_bravo_combine_modes($modes)
{
  return empty($modes) ? LIKE_BRAVO_MODE_INACTIVE : (int)max($modes);
}

/**
 * How a stored rate counts in a given mode.
 *
 * Like + Bravo: 2-3 = like, 4-5 = bravo. Like: 2-5 = like.
 * Rates 0-1 are dormant: kept in the database but not counted.
 *
 * @param int $rate
 * @param int $mode
 * @return string|null 'like', 'bravo' or null if the rate does not count
 */
function like_bravo_classify_rate($rate, $mode)
{
  $rate = (int)$rate;

  if ($mode == LIKE_BRAVO_MODE_INACTIVE or $rate < LIKE_BRAVO_RATE_LIKE)
  {
    return null;
  }

  if ($mode == LIKE_BRAVO_MODE_BOTH and $rate >= LIKE_BRAVO_RATE_BRAVO)
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
function like_bravo_count_votes($rates, $mode)
{
  $counts = array('likes' => 0, 'bravos' => 0, 'dormant' => 0);

  foreach ($rates as $rate)
  {
    $level = like_bravo_classify_rate($rate, $mode);
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
 * Points of a photo in a thumbs mode: 2 for every like, 4 for every bravo,
 * whatever star value the vote was stored with.
 *
 * @param int $likes
 * @param int $bravos
 * @return int
 */
function like_bravo_points($likes, $bravos)
{
  return LIKE_BRAVO_RATE_LIKE * $likes + LIKE_BRAVO_RATE_BRAVO * $bravos;
}

/**
 * Score of a photo in a thumbs mode: 5 * P / (P + A), with P = points of the
 * photo and A = average points of all rated thumbs photos of the gallery.
 *
 * A photo with average points gets 2.5, with twice the average 3.33, with
 * four times the average 4. The score rises with every vote and stays below
 * 5, like a star rating score, and the scale is the same in a small and in
 * a large gallery.
 *
 * @param int $points
 * @param float $average_points
 * @return float|null null without any counting vote (photo is "not rated")
 */
function like_bravo_score($points, $average_points)
{
  if ($points <= 0)
  {
    return null;
  }

  return round(5 * $points / ($points + $average_points), 2);
}

/**
 * @param int $mode
 * @return string[] vote levels a member may set in this mode
 */
function like_bravo_allowed_levels($mode)
{
  switch ($mode)
  {
    case LIKE_BRAVO_MODE_LIKE:
      return array('none', 'like');
    case LIKE_BRAVO_MODE_BOTH:
      return array('none', 'like', 'bravo');
    default:
      return array();
  }
}

/**
 * @param string $level 'like' or 'bravo'
 * @return int|null value for the rate table, null for any other level
 */
function like_bravo_level_to_rate($level)
{
  switch ($level)
  {
    case 'like':
      return LIKE_BRAVO_RATE_LIKE;
    case 'bravo':
      return LIKE_BRAVO_RATE_BRAVO;
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
 * Photos in a thumbs mode get like_bravo_score(); the average is taken from
 * thumbs photos with at least one counting vote. Rates of anonymous
 * visitors count there only if $count_guests is set, else they are dormant.
 *
 * @param array $rate_rows rows of 'element_id', 'rate', 'is_guest' (rate of
 *    an anonymous visitor) and 'n' (number of identical rates)
 * @param array $image_modes image_id => mode, photos in a thumbs mode;
 *    any photo not listed is a star photo
 * @param bool $count_guests as like_bravo_guests_take_part()
 * @return array image_id => 'score' (float|null), 'average' (float|null),
 *    'count' (int); thumbs photos also have 'likes' and 'bravos'
 */
function like_bravo_compute_scores($rate_rows, $image_modes, $count_guests=false)
{
  $stars = array();
  $thumbs = array();

  foreach ($rate_rows as $row)
  {
    $id = (int)$row['element_id'];
    $n = (int)$row['n'];
    $mode = isset($image_modes[$id]) ? (int)$image_modes[$id] : LIKE_BRAVO_MODE_INACTIVE;

    if (LIKE_BRAVO_MODE_INACTIVE == $mode)
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
    if ($count_guests or empty($row['is_guest']))
    {
      $level = like_bravo_classify_rate($row['rate'], $mode);
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

  $points = array();
  foreach ($thumbs as $id => $item)
  {
    $points[$id] = like_bravo_points($item['likes'], $item['bravos']);
  }
  $rated = array_filter($points);
  $average_points = empty($rated) ? 0 : array_sum($rated) / count($rated);

  foreach ($thumbs as $id => $item)
  {
    $results[$id] = array(
      'score' => like_bravo_score($points[$id], $average_points),
      'average' => null,
      'count' => $item['likes'] + $item['bravos'],
      'likes' => $item['likes'],
      'bravos' => $item['bravos'],
      );
  }

  return $results;
}
