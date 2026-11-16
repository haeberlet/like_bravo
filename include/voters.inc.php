<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/score.inc.php');

/**
 * Names of the voters of a photo: database access and translated texts.
 * Order, short form and enumeration live in functions_logic.inc.php.
 */

/**
 * @return bool true if the current visitor sees the names of voters
 */
function like_bravo_names_visible()
{
  global $conf;

  return like_bravo_may_see_names($conf['like_bravo']['show_names'], like_bravo_is_anonymous());
}

/**
 * Voters of one photo, as seen by the current visitor.
 *
 * @param int $image_id
 * @param int $mode mode of the photo
 * @return array as like_bravo_group_voters()
 */
function like_bravo_get_voters($image_id, $mode)
{
  global $conf, $user;

  $query = '
SELECT r.user_id, u.'.$conf['user_fields']['username'].' AS name, r.date, r.rate,
    '.like_bravo_sql_is_guest('r', 'ui').' AS is_guest
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USERS_TABLE.' AS u ON u.'.$conf['user_fields']['id'].' = r.user_id
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
  WHERE r.element_id = '.(int)$image_id.'
;';

  return like_bravo_group_voters(
    query2array($query),
    $mode,
    like_bravo_is_anonymous() ? null : $user['id'],
    like_bravo_guests_take_part_now()
    );
}

/**
 * Counter text such as "0 likes", "1 like", "2 likes". Singular only for
 * exactly one, which is right for German and English; Piwigo's own rule
 * would give "0 Like" in German. js/like_bravo.js uses the same rule.
 *
 * @param int $count
 * @param string $singular_key
 * @param string $plural_key
 * @return string
 */
function like_bravo_count_text($count, $singular_key, $plural_key)
{
  return sprintf(l10n(1 == $count ? $singular_key : $plural_key), $count);
}

/**
 * Short form of the voters of one level, such as
 * "Liked by you, Anna, Ben and 3 others" or "Bravo from Carl and 2 guests".
 *
 * @param string $level 'like' or 'bravo'
 * @param array $group one group of like_bravo_group_voters()
 * @return string plain text, empty without any vote
 */
function like_bravo_summary_text($level, $group)
{
  global $conf;

  $summary = like_bravo_summarize_voters($group, $conf['like_bravo']['names_limit']);

  $items = array();
  foreach ($summary['voters'] as $voter)
  {
    $items[] = $voter['own'] ? l10n('you (voter)') : $voter['name'];
  }
  if ($summary['others'] > 0)
  {
    $items[] = like_bravo_count_text($summary['others'], '%d other voter', '%d other voters');
  }
  if ($summary['guests'] > 0)
  {
    $items[] = like_bravo_count_text($summary['guests'], '%d guest voter', '%d guest voters');
  }

  if (empty($items))
  {
    return '';
  }

  return sprintf(
    l10n('bravo' == $level ? 'Bravo from %s' : 'Liked by %s'),
    like_bravo_join_list($items, l10n('%s and %s'))
    );
}

/**
 * @param array $groups as like_bravo_group_voters()
 * @return array 'like' and 'bravo' => short form, see like_bravo_summary_text()
 */
function like_bravo_summary_texts($groups)
{
  return array(
    'like' => like_bravo_summary_text('like', $groups['like']),
    'bravo' => like_bravo_summary_text('bravo', $groups['bravo']),
    );
}

/**
 * Complete lists of voters, ready for display: bravos first.
 *
 * @param array $groups as like_bravo_group_voters()
 * @return array list of array('level' => string, 'title' => string,
 *    'names' => string[], 'extra' => string[] lines for voters without name);
 *    levels without any vote are left out
 */
function like_bravo_voter_lists($groups)
{
  $titles = array('bravo' => l10n('Bravo'), 'like' => l10n('Like'));

  $lists = array();
  foreach ($titles as $level => $title)
  {
    $group = $groups[$level];

    $extra = array();
    if ($group['unnamed'] > 0)
    {
      $extra[] = like_bravo_count_text($group['unnamed'], '+ %d other', '+ %d others');
    }
    if ($group['guests'] > 0)
    {
      $extra[] = like_bravo_count_text($group['guests'], '+ %d guest', '+ %d guests');
    }

    if (empty($group['voters']) and empty($extra))
    {
      continue;
    }

    $lists[] = array(
      'level' => $level,
      'title' => $title,
      'names' => array_column($group['voters'], 'name'),
      'extra' => $extra,
      );
  }

  return $lists;
}
