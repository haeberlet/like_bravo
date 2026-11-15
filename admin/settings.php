<?php
defined('CLUB_LIKES_PATH') or die('Hacking attempt!');

include_once(CLUB_LIKES_PATH . 'include/icons.inc.php');

// +-----------------------------------------------------------------------+
// | Save                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_POST['submit']))
{
  check_pwg_token();

  // Piwigo adds slashes to all request variables
  $posted = array();
  foreach (array('icon_like', 'icon_bravo', 'icon_undo', 'label_title', 'label_like', 'label_bravo', 'score_constant') as $key)
  {
    $posted[$key] = (isset($_POST[$key]) and is_string($_POST[$key])) ? stripslashes($_POST[$key]) : '';
  }
  $posted['score_constant'] = str_replace(',', '.', trim($posted['score_constant']));
  $posted['allow_own_photos'] = isset($_POST['allow_own_photos']);

  if (!club_likes_is_valid_score_constant($posted['score_constant']))
  {
    $page['errors'][] = l10n('The score constant must be a number greater than 0.');
    $posted['score_constant'] = $conf['club_likes']['score_constant'];
  }

  $old_constant = $conf['club_likes']['score_constant'];
  conf_update_param('club_likes', club_likes_normalize_conf($posted), true);

  if ($conf['club_likes']['score_constant'] != $old_constant)
  {
    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();
  }

  if (empty($page['errors']))
  {
    $template->assign('save_success', l10n('Your configuration settings are saved'));
  }
}

// +-----------------------------------------------------------------------+
// | Form                                                                  |
// +-----------------------------------------------------------------------+

$vote_icons = array();
foreach (club_likes_vote_icon_names() as $name)
{
  $vote_icons[$name] = club_likes_icon($name, 24);
}

$undo_icons = array();
foreach (club_likes_undo_icon_names() as $name)
{
  $undo_icons[$name] = club_likes_icon($name, 24);
}

$template->assign(array(
  'club_likes' => $conf['club_likes'],
  'CLUB_LIKES_VOTE_ICONS' => $vote_icons,
  'CLUB_LIKES_UNDO_ICONS' => $undo_icons,
  'CLUB_LIKES_CSS' => 'plugins/' . CLUB_LIKES_ID . '/css/admin.css',
  'F_ACTION' => CLUB_LIKES_ADMIN,
  'PWG_TOKEN' => get_pwg_token(),
  'ADMIN_PAGE_TITLE' => 'Club Likes',
  ));

$template->set_filename('club_likes_content', realpath(CLUB_LIKES_PATH . 'admin/template/settings.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'club_likes_content');
