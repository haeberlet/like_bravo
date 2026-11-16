<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

/**
 * Event handler (loc_begin_picture): ignores the native star rating form
 * (picture.php?...&action=rate) on all photos that are not star photos.
 */
function like_bravo_block_native_rate()
{
  global $page;

  if (isset($_GET['action']) and 'rate' == $_GET['action']
      and isset($page['image_id'])
      and LIKE_BRAVO_MODE_STARS != like_bravo_get_image_mode($page['image_id']))
  {
    unset($_GET['action']);
  }
}

/**
 * Event handler (loc_end_picture): on photos in a thumbs mode, hides the
 * star rating of the theme and adds the like / bravo widget; on photos in
 * the mode no rating, only hides the star rating.
 */
function like_bravo_picture()
{
  global $conf, $page, $picture, $template, $user;

  if (!isset($page['image_id']))
  {
    return;
  }

  $image_id = (int)$page['image_id'];
  $mode = like_bravo_get_image_mode($image_id);

  if (LIKE_BRAVO_MODE_STARS == $mode)
  {
    return;
  }

  // every theme shows its stars only if these two variables are set
  $template->clear_assign('rating');
  $template->clear_assign('rate_summary');

  if (!like_bravo_is_thumbs_mode($mode))
  {
    return;
  }

  $settings = $conf['like_bravo'];
  $votes = like_bravo_get_image_votes($image_id, $mode);

  $anonymous = like_bravo_is_anonymous();
  $can_vote = (!$anonymous or like_bravo_guests_take_part_now());
  if ($can_vote and !$anonymous and !$settings['allow_own_photos']
      and isset($picture['current']['added_by'])
      and $picture['current']['added_by'] == $user['id'])
  {
    $can_vote = false;
  }

  $labels = array(
    'like' => l10n('Like'),
    'bravo' => l10n('Bravo'),
    );

  // names of the voters, only for visitors who may see them
  $show_names = like_bravo_names_visible();
  $summaries = $show_names
    ? like_bravo_summary_texts(like_bravo_get_voters($image_id, $mode))
    : array('like' => '', 'bravo' => '');

  $buttons = array();
  if ($can_vote)
  {
    foreach (like_bravo_allowed_levels($mode) as $level)
    {
      if ('none' != $level)
      {
        $buttons[$level] = array(
          'LABEL' => $labels[$level],
          'ICON' => like_bravo_icon($settings['icon_'.$level]),
          );
      }
    }
  }

  $template->assign('like_bravo_widget', array(
    'TITLE' => l10n('Member rating'),
    'IMAGE_ID' => $image_id,
    'SHOW_BRAVOS' => LIKE_BRAVO_MODE_BOTH == $mode,
    'LIKES_TEXT' => like_bravo_count_text($votes['likes'], '%d like', '%d likes'),
    'BRAVOS_TEXT' => like_bravo_count_text($votes['bravos'], '%d bravo', '%d bravos'),
    'SHOW_NAMES' => $show_names,
    'LIKES_SUMMARY' => $summaries['like'],
    'BRAVOS_SUMMARY' => $summaries['bravo'],
    'LIKES_COUNT' => $votes['likes'],
    'BRAVOS_COUNT' => $votes['bravos'],
    'VOTERS_URL' => get_root_url().'ws.php?format=json&method=likeBravo.getVoters',
    'LEVEL' => $can_vote
      ? like_bravo_get_user_level($image_id, $user['id'], $mode, $anonymous ? like_bravo_current_anonymous_id() : null)
      : 'none',
    'BUTTONS' => $buttons,
    'UNDO_ICON' => ($can_vote and 'none' != $settings['icon_undo']) ? like_bravo_icon($settings['icon_undo']) : '',
    'PWG_TOKEN' => $can_vote ? get_pwg_token() : '',
    'WS_URL' => get_root_url().'ws.php?format=json&method=likeBravo.setVote',
    'CSS_PATH' => 'plugins/'.LIKE_BRAVO_ID.'/css/like_bravo.css',
    'JS_PATH' => 'plugins/'.LIKE_BRAVO_ID.'/js/like_bravo.js',
    ));

  $template->set_filename('like_bravo_widget', realpath(LIKE_BRAVO_PATH . 'template/widget.tpl'));
  $html = $template->parse('like_bravo_widget', true);

  if ('smartpocket' == $user['theme'])
  {
    // SmartPocket has no plugin slot on the picture page
    $template->assign('LIKE_BRAVO_WIDGET', $html);
    $template->set_prefilter('picture', 'like_bravo_smartpocket_prefilter');
  }
  else
  {
    $template->concat('PLUGIN_PICTURE_AFTER', $html);
  }
}

/**
 * Template prefilter: gives the SmartPocket picture page a place for the
 * widget, right after the photo information list.
 *
 * @param string $content template source
 * @return string
 */
function like_bravo_smartpocket_prefilter($content)
{
  $search = '{if isset($COMMENT_COUNT)}';
  $pos = strpos($content, $search);

  if (false === $pos or false === strpos($content, 'id="PictureInfo"'))
  {
    return $content;
  }

  return substr($content, 0, $pos)
    .'{if isset($LIKE_BRAVO_WIDGET)}{$LIKE_BRAVO_WIDGET}{/if}'."\n"
    .substr($content, $pos);
}
