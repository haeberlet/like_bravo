<?php
defined('CLUB_LIKES_PATH') or die('Hacking attempt!');

include_once(PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php');

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
$cat_id = (int)$_GET['cat_id'];

$query = '
SELECT id, name, uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$cat_id.'
;';
$category = pwg_db_fetch_assoc(pwg_query($query));

if (!isset($category['id']))
{
  die('unknown album');
}

$mode_labels = array(
  CLUB_LIKES_MODE_INACTIVE => l10n('Inactive (Piwigo stars)'),
  CLUB_LIKES_MODE_LIKE => l10n('Like only'),
  CLUB_LIKES_MODE_LIKE_BRAVO => l10n('Like + Bravo'),
  );

// +-----------------------------------------------------------------------+
// | Save                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_POST['submit']))
{
  check_pwg_token();

  $posted = isset($_POST['mode']) ? $_POST['mode'] : '';

  if ('inherit' === $posted)
  {
    $new_mode = null;
  }
  elseif (preg_match('/^\d$/', $posted) and in_array((int)$posted, club_likes_valid_modes(), true))
  {
    $new_mode = (int)$posted;
  }
  else
  {
    $page['errors'][] = l10n('Invalid mode');
  }

  if (empty($page['errors']))
  {
    club_likes_set_category_mode($cat_id, $new_mode);

    // photos of this album and its sub-albums may now count differently
    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();

    $template->assign('save_success', l10n('Your configuration settings are saved'));
  }
}

// +-----------------------------------------------------------------------+
// | Tabs and title, same as the core album pages                          |
// +-----------------------------------------------------------------------+

$admin_album_base_url = get_root_url().'admin.php?page=album-'.$cat_id;

$tabsheet = new tabsheet();
$tabsheet->set_id('album');
$tabsheet->select('club_likes');
$tabsheet->assign();

$category_name = trigger_change(
  'render_category_name',
  $category['name'],
  'get_cat_display_name_cache'
  );

$template->assign(array(
  'ADMIN_PAGE_TITLE' => l10n('Edit album').' <strong>'.$category_name.'</strong>',
  'ADMIN_PAGE_OBJECT_ID' => '#'.$category['id'],
  ));

// +-----------------------------------------------------------------------+
// | Form                                                                  |
// +-----------------------------------------------------------------------+

$explicit_modes = club_likes_get_explicit_modes();
$inherited = club_likes_get_inherited_mode($category['uppercats']);

$inherited_from = null;
if (null !== $inherited['source'])
{
  $query = '
SELECT name
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int)$inherited['source'].'
;';
  $names = query2array($query, null, 'name');

  $inherited_from = array(
    'NAME' => trigger_change('render_category_name', $names[0], 'get_cat_display_name_cache'),
    'URL' => CLUB_LIKES_ADMIN.'-album&amp;cat_id='.(int)$inherited['source'],
    );
}

$template->assign(array(
  'CLUB_LIKES_SELECTED' => isset($explicit_modes[$cat_id]) ? (string)$explicit_modes[$cat_id] : 'inherit',
  'CLUB_LIKES_MODES' => $mode_labels,
  'CLUB_LIKES_INHERITED_LABEL' => $mode_labels[ $inherited['mode'] ],
  'CLUB_LIKES_INHERITED_FROM' => $inherited_from,
  'F_ACTION' => CLUB_LIKES_ADMIN.'-album&amp;cat_id='.$cat_id,
  'PWG_TOKEN' => get_pwg_token(),
  ));

$template->set_filename('club_likes_content', realpath(CLUB_LIKES_PATH . 'admin/template/album.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'club_likes_content');
