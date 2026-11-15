<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(CLUB_LIKES_PATH . 'include/score.inc.php');
include_once(CLUB_LIKES_PATH . 'include/icons.inc.php');

/**
 * Event handler (loc_begin_picture): ignores the native star rating form
 * (picture.php?...&action=rate) on photos in a thumbs mode.
 */
function club_likes_block_native_rate()
{
  global $page;

  if (isset($_GET['action']) and 'rate' == $_GET['action']
      and isset($page['image_id'])
      and CLUB_LIKES_MODE_INACTIVE != club_likes_get_image_mode($page['image_id']))
  {
    unset($_GET['action']);
  }
}

/**
 * Event handler (loc_end_picture): on photos in a thumbs mode, hides the
 * star rating of the theme and adds the like / bravo widget.
 */
function club_likes_picture()
{
  global $conf, $page, $picture, $template, $user;

  if (!isset($page['image_id']))
  {
    return;
  }

  $image_id = (int)$page['image_id'];
  $mode = club_likes_get_image_mode($image_id);

  if (CLUB_LIKES_MODE_INACTIVE == $mode)
  {
    return;
  }

  // every theme shows its stars only if these two variables are set
  $template->clear_assign('rating');
  $template->clear_assign('rate_summary');

  $settings = $conf['club_likes'];
  $votes = club_likes_get_image_votes($image_id, $mode);

  $can_vote = !is_a_guest();
  if ($can_vote and !$settings['allow_own_photos']
      and isset($picture['current']['added_by'])
      and $picture['current']['added_by'] == $user['id'])
  {
    $can_vote = false;
  }

  $labels = array(
    'like' => '' != $settings['label_like'] ? $settings['label_like'] : l10n('Like'),
    'bravo' => '' != $settings['label_bravo'] ? $settings['label_bravo'] : l10n('Bravo'),
    );

  $buttons = array();
  if ($can_vote)
  {
    foreach (club_likes_allowed_levels($mode) as $level)
    {
      if ('none' != $level)
      {
        $buttons[$level] = array(
          'LABEL' => $labels[$level],
          'ICON' => club_likes_icon($settings['icon_'.$level]),
          );
      }
    }
  }

  $template->assign('club_likes_widget', array(
    'TITLE' => '' != $settings['label_title'] ? $settings['label_title'] : l10n('Member rating'),
    'IMAGE_ID' => $image_id,
    'SHOW_BRAVOS' => CLUB_LIKES_MODE_LIKE_BRAVO == $mode,
    'LIKES_TEXT' => club_likes_count_text($votes['likes'], '%d like', '%d likes'),
    'BRAVOS_TEXT' => club_likes_count_text($votes['bravos'], '%d bravo', '%d bravos'),
    'LEVEL' => $can_vote ? club_likes_get_user_level($image_id, $user['id'], $mode) : 'none',
    'BUTTONS' => $buttons,
    'UNDO_ICON' => ($can_vote and 'none' != $settings['icon_undo']) ? club_likes_icon($settings['icon_undo']) : '',
    'PWG_TOKEN' => $can_vote ? get_pwg_token() : '',
    'WS_URL' => get_root_url().'ws.php?format=json&method=clubLikes.setVote',
    'CSS_PATH' => 'plugins/'.CLUB_LIKES_ID.'/css/club_likes.css',
    'JS_PATH' => 'plugins/'.CLUB_LIKES_ID.'/js/club_likes.js',
    ));

  $template->set_filename('club_likes_widget', realpath(CLUB_LIKES_PATH . 'template/widget.tpl'));
  $html = $template->parse('club_likes_widget', true);

  if ('smartpocket' == $user['theme'])
  {
    // SmartPocket has no plugin slot on the picture page
    $template->assign('CLUB_LIKES_WIDGET', $html);
    $template->set_prefilter('picture', 'club_likes_smartpocket_prefilter');
  }
  else
  {
    $template->concat('PLUGIN_PICTURE_AFTER', $html);
  }
}

/**
 * Counter text such as "0 likes", "1 like", "2 likes". Singular only for
 * exactly one, which is right for German and English; Piwigo's own rule
 * would give "0 Like" in German. js/club_likes.js uses the same rule.
 *
 * @param int $count
 * @param string $singular_key
 * @param string $plural_key
 * @return string
 */
function club_likes_count_text($count, $singular_key, $plural_key)
{
  return sprintf(l10n(1 == $count ? $singular_key : $plural_key), $count);
}

/**
 * Template prefilter: gives the SmartPocket picture page a place for the
 * widget, right after the photo information list.
 *
 * @param string $content template source
 * @return string
 */
function club_likes_smartpocket_prefilter($content)
{
  $search = '{if isset($COMMENT_COUNT)}';
  $pos = strpos($content, $search);

  if (false === $pos or false === strpos($content, 'id="PictureInfo"'))
  {
    return $content;
  }

  return substr($content, 0, $pos)
    .'{if isset($CLUB_LIKES_WIDGET)}{$CLUB_LIKES_WIDGET}{/if}'."\n"
    .substr($content, $pos);
}
