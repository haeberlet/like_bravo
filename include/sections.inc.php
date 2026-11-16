<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/score.inc.php');

/**
 * Personal pages "My likes" and "My bravos": entries in the menu block
 * "Explore" and the photo sets behind them. Which pages exist and which
 * photos belong to them is decided in functions_logic.inc.php.
 */

/**
 * @return array section name => level, pages the current visitor can open;
 *    empty for guests and "generic" accounts, whose votes are shared
 */
function like_bravo_available_sections()
{
  if (like_bravo_is_anonymous())
  {
    return array();
  }

  return like_bravo_sections(like_bravo_get_explicit_modes());
}

/**
 * @param string $level 'like' or 'bravo'
 * @return array 'NAME' and 'TITLE' of the page, translated
 */
function like_bravo_section_texts($level)
{
  if ('bravo' == $level)
  {
    return array(
      'NAME' => l10n('My bravos'),
      'TITLE' => l10n('display the photos you gave a bravo'),
      );
  }

  return array(
    'NAME' => l10n('My likes'),
    'TITLE' => l10n('display the photos you like'),
    );
}

/**
 * Photos the current member voted for with the given level and may see,
 * the latest votes first.
 *
 * @param string $level 'like' or 'bravo'
 * @return int[] image ids
 */
function like_bravo_get_my_images($level)
{
  global $user;

  $query = '
SELECT DISTINCT r.element_id, r.rate, r.date
  FROM '.RATE_TABLE.' AS r
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = r.element_id
  WHERE r.user_id = '.(int)$user['id'].'
    '.get_sql_condition_FandF(
      array(
        'forbidden_categories' => 'ic.category_id',
        'visible_categories' => 'ic.category_id',
        'visible_images' => 'r.element_id',
        ),
      'AND'
      ).'
  ORDER BY r.date DESC, r.element_id DESC
;';

  return like_bravo_filter_voted(query2array($query), like_bravo_get_all_image_modes(), $level);
}

/**
 * Event handler (blockmanager_apply): adds the personal pages to the menu
 * block "Explore", next to the favorites.
 *
 * @param array $arr the menu, in the first element
 */
function like_bravo_blockmanager_apply($arr)
{
  $menu = $arr[0];

  if ('menubar' != $menu->get_id() or null == ($block = $menu->get_block('mbSpecials')))
  {
    return;
  }

  $entries = array();
  foreach (like_bravo_available_sections() as $section => $level)
  {
    $entries[$section] = array_merge(
      like_bravo_section_texts($level),
      array('URL' => make_index_url(array('section' => $section)))
      );
  }

  if (empty($entries) or !is_array($block->data))
  {
    return;
  }

  // after "Your favorites" if the block has it, else in front
  $position = array_search('favorites', array_keys($block->data));
  $position = (false === $position) ? 0 : $position + 1;

  $block->data = array_merge(
    array_slice($block->data, 0, $position, true),
    $entries,
    array_slice($block->data, $position, null, true)
    );
}

/**
 * Event handler (loc_end_section_init): turns the page into "My likes" or
 * "My bravos" if its URL asks for it. The core does not know these sections
 * and has prepared the gallery home instead.
 */
function like_bravo_section_init()
{
  global $conf, $page, $tokens;

  if ('categories' != $page['section'] or isset($page['category']) or !is_array($tokens))
  {
    return;
  }

  // on a picture page the first part of the URL is the photo
  $position = ('picture' == script_basename()) ? 1 : 0;
  $all_sections = like_bravo_sections(array(LIKE_BRAVO_MODE_BOTH));

  if (!isset($tokens[$position]) or !isset($all_sections[ $tokens[$position] ]))
  {
    return;
  }

  $section = $tokens[$position];
  $available = like_bravo_available_sections();

  if (like_bravo_is_anonymous())
  {
    access_denied();
  }
  if (!isset($available[$section]))
  {
    page_not_found('This page is not available', get_gallery_home_url());
  }

  $texts = like_bravo_section_texts($available[$section]);

  $page['section'] = $section;
  unset($page['is_homepage'], $page['flat']);

  $page['items'] = like_bravo_get_my_images($available[$section]);
  $page['title'] = '<a href="'.duplicate_index_url(array('start' => 0)).'">'.$texts['NAME'].'</a>';
  $page['section_title'] = '<a href="'.get_gallery_home_url().'">'.l10n('Home').'</a>'
    .$conf['level_separator'].$page['title'];
  $page['meta_robots'] = array('noindex' => 1, 'nofollow' => 1);

  $page['body_classes'] = array_values(array_diff($page['body_classes'], array('section-categories')));
  $page['body_classes'][] = 'section-'.$section;
  $page['body_data']['section'] = $section;
}
