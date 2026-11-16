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
  foreach (array('icon_like', 'icon_bravo', 'icon_undo', 'show_names', 'names_limit', 'position') as $key)
  {
    $posted[$key] = (isset($_POST[$key]) and is_string($_POST[$key])) ? stripslashes($_POST[$key]) : '';
  }
  $posted['allow_own_photos'] = isset($_POST['allow_own_photos']);
  $posted['allow_guests'] = isset($_POST['allow_guests']);

  $old = $conf['like_bravo'];
  conf_update_param('like_bravo', like_bravo_normalize_conf($posted), true);

  // guest votes count or not: the scores of thumbs photos change
  if ($conf['like_bravo']['allow_guests'] != $old['allow_guests'])
  {
    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();
  }

  $template->assign('save_success', l10n('Your configuration settings are saved'));
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
  'LIKE_BRAVO_SHOW_NAMES' => array(
    'off' => l10n('to nobody'),
    'members' => l10n('to signed-in members'),
    'all' => l10n('to all visitors'),
    ),
  'LIKE_BRAVO_POSITIONS' => array(
    'photo' => l10n('below the photo, without heading'),
    'info' => l10n('with the photo information'),
    ),
  'LIKE_BRAVO_RATE_ANONYMOUS' => (bool)$conf['rate_anonymous'],
  'LIKE_BRAVO_CSS' => 'plugins/' . LIKE_BRAVO_ID . '/css/admin.css',
  'F_ACTION' => LIKE_BRAVO_ADMIN,
  'PWG_TOKEN' => get_pwg_token(),
  'ADMIN_PAGE_TITLE' => 'Like &amp; Bravo',
  ));

$template->set_filename('like_bravo_content', realpath(LIKE_BRAVO_PATH . 'admin/template/settings.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'like_bravo_content');
