<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

/**
 * Event handler (loc_end_index_thumbnails): adds the likes and bravos to the
 * thumbnails of photos in a thumbs mode. Names of voters are kept for the
 * photo page.
 *
 * @param array $tpl_thumbnails_var template variables of the thumbnails
 * @param array $pictures rows of the images table, same photos
 * @return array
 */
function like_bravo_index_thumbnails($tpl_thumbnails_var, $pictures)
{
  global $conf, $template, $user;

  $settings = $conf['like_bravo'];

  if (!$settings['thumbnails'] or empty($tpl_thumbnails_var))
  {
    return $tpl_thumbnails_var;
  }

  $image_ids = array();
  foreach ($tpl_thumbnails_var as $thumbnail)
  {
    if (isset($thumbnail['id']))
    {
      $image_ids[] = (int)$thumbnail['id'];
    }
  }

  $modes = array_filter(like_bravo_get_image_modes($image_ids), 'like_bravo_is_thumbs_mode');

  if (empty($modes))
  {
    return $tpl_thumbnails_var;
  }

  $anonymous = like_bravo_is_anonymous();
  $count_guests = like_bravo_guests_take_part_now();
  $can_vote = (!$anonymous or $count_guests);

  $query = '
SELECT r.element_id, r.rate, r.user_id, r.anonymous_id,
    '.like_bravo_sql_is_guest('r', 'ui').' AS is_guest
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.USER_INFOS_TABLE.' AS ui ON ui.user_id = r.user_id
  WHERE r.element_id IN ('.implode(',', array_keys($modes)).')
;';
  $votes = like_bravo_thumbnail_votes(
    query2array($query),
    $modes,
    $can_vote ? $user['id'] : null,
    ($can_vote and $anonymous) ? like_bravo_current_anonymous_id() : null,
    $count_guests
    );

  // an anonymous account is shared, none of its photos is "own"
  $added_by = array();
  if (!$anonymous and !$settings['allow_own_photos'])
  {
    foreach ($pictures as $picture)
    {
      $added_by[(int)$picture['id']] = (int)$picture['added_by'];
    }
  }

  $common = array(
    'url' => get_root_url().'ws.php?format=json&method=likeBravo.setVote',
    'token' => $can_vote ? get_pwg_token() : '',
    'icons' => array(
      'like' => like_bravo_icon($settings['icon_like'], 16),
      'bravo' => like_bravo_icon($settings['icon_bravo'], 16),
      ),
    );

  foreach ($tpl_thumbnails_var as $i => $thumbnail)
  {
    $id = isset($thumbnail['id']) ? (int)$thumbnail['id'] : 0;
    if (!isset($votes[$id]))
    {
      continue;
    }

    $own_photo = (isset($added_by[$id]) and $added_by[$id] == $user['id']);

    $tpl_thumbnails_var[$i]['LIKE_BRAVO'] = like_bravo_thumbnail_html(
      $id, $modes[$id], $votes[$id], $can_vote and !$own_photo, $common);
  }

  $template->func_combine_css(array('path' => 'plugins/'.LIKE_BRAVO_ID.'/css/like_bravo.css'));
  $template->func_combine_script(array(
    'id' => 'like_bravo',
    'load' => 'footer',
    'path' => 'plugins/'.LIKE_BRAVO_ID.'/js/like_bravo.js',
    ));
  $template->set_prefilter('index_thumbnails', 'like_bravo_thumbnails_prefilter');

  return $tpl_thumbnails_var;
}

/**
 * Likes and bravos of one thumbnail: icon and number per level. The icons
 * are buttons for visitors who may vote.
 *
 * @param int $image_id
 * @param int $mode thumbs mode of the photo
 * @param array $votes as like_bravo_thumbnail_votes()
 * @param bool $can_vote
 * @param array $common 'url', 'token', 'icons'
 * @return string
 */
function like_bravo_thumbnail_html($image_id, $mode, $votes, $can_vote, $common)
{
  $levels = array('like' => 'likes');
  if (LIKE_BRAVO_MODE_BOTH == $mode)
  {
    $levels['bravo'] = 'bravos';
  }
  $labels = array('like' => l10n('Like'), 'bravo' => l10n('Bravo'));
  $keys = array(
    'like' => array('%d like', '%d likes'),
    'bravo' => array('%d bravo', '%d bravos'),
    );

  $level = $can_vote ? $votes['level'] : 'none';

  $html = '<span class="like-bravo like-bravo-thumb" data-image-id="'.(int)$image_id.'"'
    .' data-level="'.$level.'"';
  if ($can_vote)
  {
    $html.= ' data-url="'.htmlspecialchars($common['url']).'"'
      .' data-token="'.htmlspecialchars($common['token']).'"'
      .' data-str-error="'.htmlspecialchars(l10n('Your vote could not be saved.')).'"';
  }
  foreach ($keys as $key_level => $pair)
  {
    $html.= ' data-str-'.$key_level.'="'.htmlspecialchars(l10n($pair[0])).'"'
      .' data-str-'.$key_level.'s="'.htmlspecialchars(l10n($pair[1])).'"';
  }
  $html.= '>';

  foreach ($levels as $vote_level => $count_key)
  {
    $label = htmlspecialchars($labels[$vote_level]);
    $active = ($vote_level === $level);

    if ($can_vote)
    {
      $html.= '<button type="button" data-role="none"'
        .' class="like-bravo-button like-bravo-'.$vote_level.($active ? ' is-active' : '').'"'
        .' data-level="'.$vote_level.'" aria-pressed="'.($active ? 'true' : 'false').'"'
        .' title="'.$label.'" aria-label="'.$label.'">'
        .$common['icons'][$vote_level].'</button>';
    }
    else
    {
      $html.= '<span class="like-bravo-symbol like-bravo-'.$vote_level.'" title="'.$label.'">'
        .$common['icons'][$vote_level].'</span>';
    }

    $count = (int)$votes[$count_key];
    $html.= '<span class="like-bravo-number like-bravo-count-'.$count_key.'"'
      .' title="'.htmlspecialchars(like_bravo_count_text($count, $keys[$vote_level][0], $keys[$vote_level][1])).'">'
      .$count.'</span>';
  }

  return $html.'</span>';
}
