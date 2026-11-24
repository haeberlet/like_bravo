<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Pure business rules of Like & Bravo: no database access, no globals.
 * Everything in this file is covered by tests/run.php.
 */

// Album modes, as stored in the modes table. "Inherited" is not a stored
// value, it is the absence of an explicit mode. The numbers are no order:
// the conflict rule is like_bravo_mode_rank().
define('LIKE_BRAVO_MODE_STARS', 0);  // Piwigo stars, also the default
define('LIKE_BRAVO_MODE_LIKE', 1);
define('LIKE_BRAVO_MODE_BOTH', 2);
define('LIKE_BRAVO_MODE_OFF', 3);    // no rating at all

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
    LIKE_BRAVO_MODE_STARS,
    LIKE_BRAVO_MODE_OFF,
    LIKE_BRAVO_MODE_LIKE,
    LIKE_BRAVO_MODE_BOTH,
    );
}

/**
 * @param int $mode
 * @return bool true for the modes with like / bravo instead of stars
 */
function like_bravo_is_thumbs_mode($mode)
{
  return in_array((int)$mode, array(LIKE_BRAVO_MODE_LIKE, LIKE_BRAVO_MODE_BOTH), true);
}

/**
 * @return string[] names of the icons selectable for like and bravo;
 *    the drawings live in include/icons.inc.php
 */
function like_bravo_vote_icon_names()
{
  return array('thumb', 'heart', 'smile', 'star', 'sparkle', 'clap', 'trophy', 'medal');
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
 * @return string[] possible audiences for the names of voters
 */
function like_bravo_show_names_values()
{
  return array('off', 'members', 'all');
}

/**
 * @return string[] possible places of the widget on the photo page
 */
function like_bravo_position_values()
{
  return array('photo', 'info');
}

/**
 * @return string[] themes with a place for the widget right below the photo
 *    (template prefilter in include/picture.inc.php)
 */
function like_bravo_photo_slot_themes()
{
  return array('bootstrap_darkroom');
}

/**
 * Whether the widget is shown right below the photo, in its compact form
 * without heading, instead of with the photo information.
 *
 * @param string $position plugin option, see like_bravo_position_values()
 * @param string $theme theme of the visitor
 * @return bool
 */
function like_bravo_widget_below_photo($position, $theme)
{
  return 'photo' === $position and in_array($theme, like_bravo_photo_slot_themes(), true);
}

/**
 * @return array default plugin configuration
 */
function like_bravo_default_conf()
{
  return array(
    'icon_like' => 'heart',
    'icon_bravo' => 'trophy',
    'icon_undo' => 'none',
    'allow_own_photos' => true,
    'allow_guests' => false,
    'show_names' => 'members',  // who sees the names of voters, see like_bravo_show_names_values()
    'names_limit' => 3,         // names in the short form
    'position' => 'photo',      // place of the widget, see like_bravo_position_values()
    'thumbnails' => true,       // likes and bravos below the thumbnails
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
  $conf['thumbnails'] = (bool)$conf['thumbnails'];

  if (!in_array($conf['show_names'], like_bravo_show_names_values(), true))
  {
    $conf['show_names'] = $defaults['show_names'];
  }
  if (!is_numeric($conf['names_limit']) or $conf['names_limit'] < 1 or $conf['names_limit'] > 10)
  {
    $conf['names_limit'] = $defaults['names_limit'];
  }
  $conf['names_limit'] = (int)$conf['names_limit'];

  if (!in_array($conf['position'], like_bravo_position_values(), true))
  {
    $conf['position'] = $defaults['position'];
  }

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
 * one of the nearest parent album having one, else Piwigo stars.
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

  return array('mode' => LIKE_BRAVO_MODE_STARS, 'source' => null);
}

/**
 * Rank of an effective album mode in the conflict rule for photos in several
 * albums: any setting beats the default of an album without one; among the
 * settings, the one allowing more rating wins.
 *
 *   no setting (stars) < no rating < stars < like < like + bravo
 *
 * @param array $resolved as like_bravo_resolve_mode()
 * @return int
 */
function like_bravo_mode_rank($resolved)
{
  if (null === $resolved['source'])
  {
    return 0;
  }

  switch ((int)$resolved['mode'])
  {
    case LIKE_BRAVO_MODE_OFF:
      return 1;
    case LIKE_BRAVO_MODE_STARS:
      return 2;
    case LIKE_BRAVO_MODE_LIKE:
      return 3;
    case LIKE_BRAVO_MODE_BOTH:
      return 4;
    default:
      return 0;
  }
}

/**
 * Mode of a photo from the effective modes of all its albums, by the rule
 * of like_bravo_mode_rank(). A photo without album has Piwigo stars.
 *
 * @param array[] $resolved_modes as like_bravo_resolve_mode(), one per album
 * @return int
 */
function like_bravo_combine_modes($resolved_modes)
{
  $best = LIKE_BRAVO_MODE_STARS;
  $best_rank = -1;

  foreach ($resolved_modes as $resolved)
  {
    $rank = like_bravo_mode_rank($resolved);
    if ($rank > $best_rank)
    {
      $best = (int)$resolved['mode'];
      $best_rank = $rank;
    }
  }

  return $best;
}

/**
 * How a stored rate counts in a given mode.
 *
 * Like + Bravo: 2-3 = like, 4-5 = bravo. Like: 2-5 = like.
 * Rates 0-1 are dormant: kept in the database but not counted. In the
 * modes stars and no rating, no rate is a like or a bravo.
 *
 * @param int $rate
 * @param int $mode
 * @return string|null 'like', 'bravo' or null if the rate does not count
 */
function like_bravo_classify_rate($rate, $mode)
{
  $rate = (int)$rate;

  if (!like_bravo_is_thumbs_mode($mode) or $rate < LIKE_BRAVO_RATE_LIKE)
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
 * Star photos get the Piwigo core score, a bayesian average
 *   (C * m + sum of rates) / (C + number of rates)
 * with m = average of all rates and C = average number of rates per photo.
 * m and C are taken from star photos only, so likes and bravos elsewhere in
 * the gallery never move the score of a star photo.
 *
 * Photos in a thumbs mode get like_bravo_score(); the average is taken from
 * thumbs photos with at least one counting vote. Rates of anonymous
 * visitors count there only if $count_guests is set, else they are dormant.
 *
 * Photos in the mode no rating get no result: they have no score, and
 * their rates are in neither average.
 *
 * @param array $rate_rows rows of 'element_id', 'rate', 'is_guest' (rate of
 *    an anonymous visitor) and 'n' (number of identical rates)
 * @param array $image_modes image_id => mode, photos not in the mode stars;
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
    $mode = isset($image_modes[$id]) ? (int)$image_modes[$id] : LIKE_BRAVO_MODE_STARS;

    if (LIKE_BRAVO_MODE_STARS == $mode)
    {
      if (!isset($stars[$id]))
      {
        $stars[$id] = array('count' => 0, 'sum' => 0);
      }
      $stars[$id]['count'] += $n;
      $stars[$id]['sum'] += $n * (int)$row['rate'];
      continue;
    }

    if (!like_bravo_is_thumbs_mode($mode))
    {
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

/**
 * Whether a visitor sees the names of voters.
 *
 * @param string $show_names plugin option: 'off', 'members' or 'all'
 * @param bool $is_anonymous visitor is a guest or uses a "generic" account
 * @return bool
 */
function like_bravo_may_see_names($show_names, $is_anonymous)
{
  switch ($show_names)
  {
    case 'all':
      return true;
    case 'members':
      return !$is_anonymous;
    default:
      return false;
  }
}

/**
 * Sorts the votes of one photo into the voters of its likes and bravos.
 *
 * Order of the voters: the visitor's own vote first, then the newest votes,
 * votes of the same day by name (the rate table stores no time of day).
 * Anonymous visitors have no name: they are a number, and only if guests
 * take part. A member whose account is gone is counted as unnamed.
 *
 * @param array $rows rows of 'user_id', 'name' (null if unknown), 'date'
 *    (YYYY-MM-DD), 'rate' and 'is_guest'
 * @param int $mode mode of the photo
 * @param int|null $own_user_id member looking at the photo, null for an
 *    anonymous visitor
 * @param bool $count_guests as like_bravo_guests_take_part()
 * @return array 'like' and 'bravo' => array('voters' => list of
 *    array('name' => string, 'own' => bool), 'unnamed' => int, 'guests' => int)
 */
function like_bravo_group_voters($rows, $mode, $own_user_id, $count_guests)
{
  $groups = array(
    'like' => array('voters' => array(), 'unnamed' => 0, 'guests' => 0),
    'bravo' => array('voters' => array(), 'unnamed' => 0, 'guests' => 0),
    );

  foreach ($rows as $row)
  {
    $level = like_bravo_classify_rate($row['rate'], $mode);
    if (null === $level)
    {
      continue;
    }

    if (!empty($row['is_guest']))
    {
      if ($count_guests)
      {
        $groups[$level]['guests']++;
      }
    }
    elseif (!isset($row['name']) or '' === (string)$row['name'])
    {
      $groups[$level]['unnamed']++;
    }
    else
    {
      $groups[$level]['voters'][] = array(
        'name' => (string)$row['name'],
        'own' => (null !== $own_user_id and (int)$row['user_id'] == (int)$own_user_id),
        'date' => (string)$row['date'],
        );
    }
  }

  foreach ($groups as $level => $group)
  {
    usort($group['voters'], function($a, $b) {
      if ($a['own'] != $b['own'])
      {
        return $a['own'] ? -1 : 1;
      }
      if ($a['date'] != $b['date'])
      {
        return strcmp($b['date'], $a['date']);
      }
      return strcasecmp($a['name'], $b['name']);
    });

    $groups[$level]['voters'] = array_map(function($voter) {
      return array('name' => $voter['name'], 'own' => $voter['own']);
    }, $group['voters']);
  }

  return $groups;
}

/**
 * Short form of one group of voters: the first names and how many more.
 *
 * @param array $group one group of like_bravo_group_voters()
 * @param int $limit highest number of names
 * @return array 'voters' => the first voters, 'others' => int further
 *    members, 'guests' => int
 */
function like_bravo_summarize_voters($group, $limit)
{
  $limit = max(1, (int)$limit);

  return array(
    'voters' => array_slice($group['voters'], 0, $limit),
    'others' => max(0, count($group['voters']) - $limit) + $group['unnamed'],
    'guests' => $group['guests'],
    );
}

/**
 * Joins the items of an enumeration: "a", "a and b", "a, b and c".
 *
 * @param string[] $items
 * @param string $and_pattern translated pattern for the last two, e.g. '%s and %s'
 * @return string
 */
function like_bravo_join_list($items, $and_pattern)
{
  $items = array_values($items);

  if (count($items) < 2)
  {
    return empty($items) ? '' : $items[0];
  }

  $last = array_pop($items);

  return sprintf($and_pattern, implode(', ', $items), $last);
}

/**
 * Personal pages offered to a member: "my_likes" as soon as any album is in
 * a thumbs mode, "my_bravos" only if an album is in the mode like + bravo.
 * The names are also the section part of the page URL.
 *
 * @param array $explicit_modes cat_id => mode, albums with an explicit mode
 * @return array section name => level ('like' or 'bravo')
 */
function like_bravo_sections($explicit_modes)
{
  $modes = array_map('intval', (array)$explicit_modes);
  $sections = array();

  if (in_array(LIKE_BRAVO_MODE_LIKE, $modes, true) or in_array(LIKE_BRAVO_MODE_BOTH, $modes, true))
  {
    $sections['my_likes'] = 'like';
  }
  if (in_array(LIKE_BRAVO_MODE_BOTH, $modes, true))
  {
    $sections['my_bravos'] = 'bravo';
  }

  return $sections;
}

/**
 * Photos of a personal page: those where the member's own rate counts as
 * the given level in the current mode of the photo. A bravo is not a like,
 * so no photo is on both pages; star photos and photos without rating are
 * on neither.
 *
 * @param array $rows the member's rates, rows of 'element_id' and 'rate',
 *    in the order the photos are to be shown
 * @param array $image_modes image_id => mode, photos not in the mode stars
 * @param string $level 'like' or 'bravo'
 * @return int[] image ids, order kept
 */
function like_bravo_filter_voted($rows, $image_modes, $level)
{
  $ids = array();

  foreach ($rows as $row)
  {
    $id = (int)$row['element_id'];
    $mode = isset($image_modes[$id]) ? (int)$image_modes[$id] : LIKE_BRAVO_MODE_STARS;

    if ($level === like_bravo_classify_rate($row['rate'], $mode))
    {
      $ids[] = $id;
    }
  }

  return $ids;
}

/**
 * Likes, bravos and the own vote of several photos at once, for the
 * thumbnails of a page.
 *
 * @param array $rows rates of the photos: 'element_id', 'rate', 'user_id',
 *    'anonymous_id', 'is_guest'
 * @param array $image_modes image_id => mode of every photo to count;
 *    photos missing here or not in a thumbs mode are left out
 * @param int|null $own_user_id user id of the visitor, null if he cannot vote
 * @param string|null $own_anonymous_id for an anonymous visitor: only the
 *    rate stored with this identifier is his own
 * @param bool $count_guests as like_bravo_guests_take_part()
 * @return array image_id => array('likes' => int, 'bravos' => int,
 *    'level' => 'none', 'like' or 'bravo')
 */
function like_bravo_thumbnail_votes($rows, $image_modes, $own_user_id, $own_anonymous_id, $count_guests)
{
  $votes = array();

  foreach ($image_modes as $id => $mode)
  {
    if (like_bravo_is_thumbs_mode($mode))
    {
      $votes[(int)$id] = array('likes' => 0, 'bravos' => 0, 'level' => 'none');
    }
  }

  foreach ($rows as $row)
  {
    $id = (int)$row['element_id'];
    if (!isset($votes[$id]))
    {
      continue;
    }

    $level = like_bravo_classify_rate($row['rate'], $image_modes[$id]);
    if (null === $level)
    {
      continue;
    }

    $own = (null !== $own_user_id and (int)$row['user_id'] == (int)$own_user_id
      and (null === $own_anonymous_id or (string)$row['anonymous_id'] === (string)$own_anonymous_id));
    if ($own)
    {
      $votes[$id]['level'] = $level;
    }

    if (!empty($row['is_guest']) and !$count_guests)
    {
      continue;
    }
    $votes[$id]['like' == $level ? 'likes' : 'bravos']++;
  }

  return $votes;
}

/**
 * Template prefilter for the thumbnails of every theme built on Piwigo's
 * thumbnail template: puts the likes and bravos of a photo right after its
 * "new" icon, so they sit where the theme shows that icon. Templates
 * without that icon are left alone.
 *
 * Modus with justified rows does not use the template for its thumbnails;
 * for it, the votes are written into a hidden list right after the
 * thumbnails, and js/like_bravo.js moves each into its thumbnail.
 *
 * @param string $content template source
 * @return string
 */
function like_bravo_thumbnails_prefilter($content)
{
  $slot = '{if isset($thumbnail.LIKE_BRAVO)}{$thumbnail.LIKE_BRAVO}{/if}';

  // only the block that shows the icon, as an element of any kind (Modus
  // replaces the image by a symbol in a span); Bootstrap Darkroom tests the
  // same condition inside a class attribute, which holds no element
  $pattern = '#\{if !empty\(\$thumbnail\.icon_ts\)\}(?:(?!\{/?if\b)[^<])*<(?:(?!\{/?if\b).)*?\{/if\}#s';
  $content = preg_replace($pattern, '$0'.$slot, $content);

  if (false !== strpos($content, '{modus_thumbs}'))
  {
    $list = '<li class="like-bravo-pending" hidden style="display:none">'
      .'{foreach from=$thumbnails item=thumbnail}{if isset($thumbnail.LIKE_BRAVO)}'
      .'<span data-href="{$thumbnail.URL}">{$thumbnail.LIKE_BRAVO}</span>'
      .'{/if}{/foreach}</li>';
    $content = str_replace('{modus_thumbs}', '{modus_thumbs}'.$list, $content);
  }

  return $content;
}

/**
 * @return string[] groups of the filter on album pages, in display order:
 *    photos the member liked, gave a bravo, and all other photos
 */
function like_bravo_filter_values()
{
  return array('like', 'bravo', 'none');
}

/**
 * Filter from the URL or the session, such as "like-none". All groups
 * together show every photo, the same as no filter.
 *
 * @param mixed $value
 * @return string[] active groups in display order, empty for no filter
 */
function like_bravo_parse_filter($value)
{
  $parts = is_string($value) ? explode('-', $value) : array();
  $filter = array_values(array_intersect(like_bravo_filter_values(), $parts));

  return count($filter) == count(like_bravo_filter_values()) ? array() : $filter;
}

/**
 * The filter after a click on the icon of one group.
 *
 * @param string[] $filter as like_bravo_parse_filter()
 * @param string $group
 * @return string[] as like_bravo_parse_filter()
 */
function like_bravo_toggle_filter($filter, $group)
{
  $filter = in_array($group, $filter, true)
    ? array_diff($filter, array($group))
    : array_merge($filter, array($group));

  return like_bravo_parse_filter(implode('-', $filter));
}

/**
 * Photos of a page that pass the filter: those in at least one active
 * group. A photo is in the group 'like' or 'bravo' if the member's own vote
 * counts as that in the photo's mode; every other photo, star photos and
 * photos without rating included, is in the group 'none'.
 *
 * @param int[] $items photos of the page, in display order
 * @param array $image_modes image_id => mode
 * @param array $own_rates image_id => the member's stored rate
 * @param string[] $filter as like_bravo_parse_filter(), empty = all photos
 * @param int|null $keep photo kept in any case (the one being shown)
 * @return int[] order kept
 */
function like_bravo_filter_items($items, $image_modes, $own_rates, $filter, $keep=null)
{
  if (empty($filter))
  {
    return $items;
  }

  $kept = array();
  foreach ($items as $id)
  {
    $mode = isset($image_modes[$id]) ? $image_modes[$id] : LIKE_BRAVO_MODE_STARS;
    $level = isset($own_rates[$id]) ? like_bravo_classify_rate($own_rates[$id], $mode) : null;

    if (in_array(null === $level ? 'none' : $level, $filter, true)
        or (null !== $keep and (int)$id == (int)$keep))
    {
      $kept[] = $id;
    }
  }

  return $kept;
}

// --- feedback of the owners: time of votes, edited comments ----------------

/**
 * Whether storing a vote gives it a new time. The table rate keeps only
 * the day, so the plugin keeps the time of a vote itself; a vote that is
 * set again unchanged keeps its time, a changed one is unread again.
 *
 * @param int|null $old_rate rate stored before, null if none
 * @param int|null $new_rate rate stored now, null if the vote was taken back
 * @return bool
 */
function like_bravo_vote_changed($old_rate, $new_rate)
{
  if (null === $new_rate)
  {
    return false;
  }

  return null === $old_rate or (int)$old_rate !== (int)$new_rate;
}

/**
 * The comment that an event user_comment_check is about, if it is an edit.
 * The core passes the key comment_id only when a comment is edited, not
 * when it is written.
 *
 * @param string $action 'validate', 'moderate' or 'reject'
 * @param array $comment as passed by the core
 * @return int|null
 */
function like_bravo_edited_comment_id($action, $comment)
{
  if ('reject' === $action or !is_array($comment) or !isset($comment['comment_id']))
  {
    return null;
  }

  $id = $comment['comment_id'];
  if (!is_numeric($id) or (int)$id <= 0 or (string)(int)$id !== (string)$id)
  {
    return null;
  }

  return (int)$id;
}

/**
 * Whether a comment shows as edited. The plugin notes an edit before the
 * core saves it; the core may still refuse it, for example for an invalid
 * website address. A saved edit sets the time of validation again (at
 * once, or when a moderator validates it), a refused one does not.
 *
 * @param string|null $edited_at noted by the plugin, 'Y-m-d H:i:s'
 * @param string|null $validation_date comments.validation_date
 * @return bool
 */
function like_bravo_comment_is_edited($edited_at, $validation_date)
{
  if (empty($edited_at) or empty($validation_date))
  {
    return false;
  }

  return strcmp($validation_date, $edited_at) >= 0;
}

/**
 * Time up to which all feedback of a member counts as read: his "mark all
 * as read", or else when feedback started for him, which is when he
 * registered or when the plugin started its feedback, the later of both.
 *
 * @param string|null $read_all_before stored by "mark all as read"
 * @param string|null $registration_date of the member
 * @param string|null $feedback_since when the plugin started its feedback
 * @return string 'Y-m-d H:i:s', '' if nothing is known
 */
function like_bravo_feedback_read_before($read_all_before, $registration_date, $feedback_since)
{
  if (!empty($read_all_before))
  {
    return (string)$read_all_before;
  }

  return (string)max((string)$registration_date, (string)$feedback_since);
}

/**
 * Whether an entry of the feedback is read: it is not newer than "mark all
 * as read", or it was read on its own after it last changed. A changed vote
 * or an edited comment gets a new time and is unread again.
 *
 * Times have whole seconds: a vote in the very second of "mark all as read"
 * counts as read.
 *
 * @param string $time of the entry, 'Y-m-d H:i:s'
 * @param string $read_before as like_bravo_feedback_read_before()
 * @param string|null $read_at when the entry was read on its own
 * @return bool
 */
function like_bravo_feedback_is_read($time, $read_before, $read_at)
{
  if ('' !== (string)$read_before and strcmp((string)$time, (string)$read_before) <= 0)
  {
    return true;
  }

  return !empty($read_at) and strcmp((string)$read_at, (string)$time) >= 0;
}

/**
 * Beginning of a comment as plain text on one line, cut after about
 * $length characters at a word boundary.
 *
 * @param string $content
 * @param int $length
 * @return string
 */
function like_bravo_excerpt($content, $length=60)
{
  $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$content)));

  if (mb_strlen($text, 'UTF-8') <= $length)
  {
    return $text;
  }

  $cut = mb_substr($text, 0, $length, 'UTF-8');
  $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
  if (false !== $space and $space > $length / 2)
  {
    $cut = mb_substr($cut, 0, $space, 'UTF-8');
  }

  return rtrim($cut, " ,.;:-").'…';
}

/**
 * Who an entry of the feedback names.
 *
 * @param bool $is_guest guest or "generic" account
 * @param string|null $name name of the account, null if it is gone
 * @param bool $names_visible for votes: the plugin option "Names of voters"
 *    allows the owner to see names; comments always show their author
 * @return array 'who' => 'member', 'guest', 'former' or 'hidden';
 *    'name' => string, only for 'member'
 */
function like_bravo_feedback_who($is_guest, $name, $names_visible)
{
  if (!$names_visible)
  {
    return array('who' => 'hidden', 'name' => null);
  }
  if ($is_guest)
  {
    return array('who' => 'guest', 'name' => null);
  }
  if (null === $name or '' === (string)$name)
  {
    return array('who' => 'former', 'name' => null);
  }

  return array('who' => 'member', 'name' => (string)$name);
}

/**
 * How long ago an entry of the feedback is, for a short text: under a
 * minute "just now", then minutes, then hours up to a day, then "yesterday"
 * for the day before, the weekday for the days of the last week, else the
 * date. Votes from before the plugin kept their time (date only) show
 * "today", "yesterday", the weekday or the date.
 *
 * Both times come from the database, so that their time zones agree.
 *
 * @param string $time 'Y-m-d H:i:s'
 * @param string $now 'Y-m-d H:i:s'
 * @param bool $date_only
 * @return array 'unit' => 'now', 'minutes', 'hours', 'today', 'yesterday',
 *    'week' or 'date'; 'value' => number of minutes or hours, else 0
 */
function like_bravo_feedback_when($time, $now, $date_only=false)
{
  $then = strtotime((string)$time);
  $current = strtotime((string)$now);
  if (false === $then or false === $current)
  {
    return array('unit' => 'date', 'value' => 0);
  }

  $day = date('Y-m-d', $then);
  $today = date('Y-m-d', $current);
  $yesterday = date('Y-m-d', strtotime($today.' -1 day'));

  if ($date_only)
  {
    if ($day === $today)
    {
      return array('unit' => 'today', 'value' => 0);
    }
    return array('unit' => $day === $yesterday ? 'yesterday' : like_bravo_feedback_day($time, $now), 'value' => 0);
  }

  $seconds = max(0, $current - $then);
  if ($seconds < 60)
  {
    return array('unit' => 'now', 'value' => 0);
  }
  if ($seconds < 3600)
  {
    return array('unit' => 'minutes', 'value' => intdiv($seconds, 60));
  }
  if ($seconds < 86400)
  {
    return array('unit' => 'hours', 'value' => intdiv($seconds, 3600));
  }
  if ($day === $yesterday)
  {
    return array('unit' => 'yesterday', 'value' => 0);
  }

  return array('unit' => like_bravo_feedback_day($time, $now), 'value' => 0);
}

/**
 * The group of days an entry of the feedback is listed under on the page
 * "All feedback", by calendar day: today, yesterday, the five days before
 * (together the last seven days), or earlier.
 *
 * @param string $time 'Y-m-d H:i:s'
 * @param string $now 'Y-m-d H:i:s'
 * @return string 'today', 'yesterday', 'week' or 'date'
 */
function like_bravo_feedback_day($time, $now)
{
  $then = strtotime((string)$time);
  $current = strtotime((string)$now);
  if (false === $then or false === $current)
  {
    return 'date';
  }

  $day = date('Y-m-d', $then);
  $today = date('Y-m-d', $current);
  if ($day >= $today)
  {
    return 'today';
  }
  if ($day === date('Y-m-d', strtotime($today.' -1 day')))
  {
    return 'yesterday';
  }

  return $day >= date('Y-m-d', strtotime($today.' -6 days')) ? 'week' : 'date';
}

/**
 * Cursor of a feedback entry, for loading the entries older than it: its
 * time and its read key, in the order of the list.
 *
 * @param string $time 'YYYY-MM-DD HH:MM:SS'
 * @param array $key 'kind', 'ref_id', 'rater_id', 'rater_anonymous_id'
 * @return string
 */
function like_bravo_feedback_cursor_text($time, $key)
{
  return $time.'|'.$key['kind'].'|'.(int)$key['ref_id'].'|'.(int)$key['rater_id'].'|'.$key['rater_anonymous_id'];
}

/**
 * Reads a cursor of like_bravo_feedback_cursor_text().
 *
 * @param mixed $value
 * @return array|null 'time', 'kind', 'ref_id', 'rater_id',
 *    'rater_anonymous_id'; null if empty or not a cursor
 */
function like_bravo_feedback_cursor($value)
{
  if (!is_string($value) or '' === $value)
  {
    return null;
  }
  $parts = explode('|', $value, 5);
  if (5 !== count($parts))
  {
    return null;
  }
  list($time, $kind, $ref_id, $rater_id, $anonymous_id) = $parts;
  if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time)
      or !in_array($kind, array('vote', 'comment'), true)
      or !ctype_digit($ref_id) or !ctype_digit($rater_id)
      or strlen($anonymous_id) > 45)
  {
    return null;
  }

  return array(
    'time' => $time,
    'kind' => $kind,
    'ref_id' => (int)$ref_id,
    'rater_id' => (int)$rater_id,
    'rater_anonymous_id' => $anonymous_id,
    );
}
