<?php
defined('LIKE_BRAVO_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

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

  if (!like_bravo_is_valid_score_constant($posted['score_constant']))
  {
    $page['errors'][] = l10n('The score constant must be a number greater than 0.');
    $posted['score_constant'] = $conf['like_bravo']['score_constant'];
  }

  $old_constant = $conf['like_bravo']['score_constant'];
  conf_update_param('like_bravo', like_bravo_normalize_conf($posted), true);

  if ($conf['like_bravo']['score_constant'] != $old_constant)
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
foreach (like_bravo_vote_icon_names() as $name)
{
  $vote_icons[$name] = like_bravo_icon($name, 24);
}

$undo_icons = array();
foreach (like_bravo_undo_icon_names() as $name)
{
  $undo_icons[$name] = like_bravo_icon($name, 24);
}

$template->assign(array(
  'like_bravo' => $conf['like_bravo'],
  'LIKE_BRAVO_VOTE_ICONS' => $vote_icons,
  'LIKE_BRAVO_UNDO_ICONS' => $undo_icons,
  'LIKE_BRAVO_CSS' => 'plugins/' . LIKE_BRAVO_ID . '/css/admin.css',
  'F_ACTION' => LIKE_BRAVO_ADMIN,
  'PWG_TOKEN' => get_pwg_token(),
  'ADMIN_PAGE_TITLE' => 'Like &amp; Bravo',
  ));

$template->set_filename('like_bravo_content', realpath(LIKE_BRAVO_PATH . 'admin/template/settings.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'like_bravo_content');
