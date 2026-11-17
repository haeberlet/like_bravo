<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/score.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/icons.inc.php');

/**
 * Event handler (loc_end_section_init): filter of signed-in members on the
 * photos of album pages, by their own votes. The filter is chosen with the
 * icons in the toolbar and kept for the session, like the sort order. It
 * also applies to the photo page, so previous and next stay in the filter.
 */
function like_bravo_filter_section_init()
{
  global $page, $user;

  $script = script_basename();

  if (('index' != $script and 'picture' != $script) or like_bravo_is_anonymous())
  {
    return;
  }

  // the personal pages are filtered by definition
  if (isset($page['section']) and in_array($page['section'], array('my_likes', 'my_bravos'), true))
  {
    return;
  }

  // no album in a thumbs mode: no votes to filter by
  $sections = like_bravo_sections(like_bravo_get_explicit_modes());
  if (empty($sections))
  {
    return;
  }

  if ('index' == $script and isset($_GET['lb_filter']))
  {
    $filter = like_bravo_parse_filter($_GET['lb_filter']);
    if (empty($filter))
    {
      pwg_unset_session_var('like_bravo_filter');
    }
    else
    {
      pwg_set_session_var('like_bravo_filter', implode('-', $filter));
    }
  }
  else
  {
    $filter = like_bravo_parse_filter(pwg_get_session_var('like_bravo_filter', ''));
  }

  if (empty($page['items']) or ('picture' == $script and empty($filter)))
  {
    return;
  }

  $items = array_map('intval', $page['items']);
  $modes = like_bravo_get_image_modes($items);

  if (!empty($filter))
  {
    $query = '
SELECT element_id, rate
  FROM '.RATE_TABLE.'
  WHERE user_id = '.(int)$user['id'].'
    AND element_id IN ('.implode(',', $items).')
;';
    $own_rates = query2array($query, 'element_id', 'rate');

    // the photo being shown stays, even if it just left the filter
    $keep = ('picture' == $script and !empty($page['image_id'])) ? (int)$page['image_id'] : null;

    $page['items'] = like_bravo_filter_items($items, $modes, $own_rates, $filter, $keep);
  }

  if ('index' != $script)
  {
    return;
  }

  // icons only where there is something to filter, or a filter to switch off
  if (empty($filter) and empty(array_filter($modes, 'like_bravo_is_thumbs_mode')))
  {
    return;
  }

  like_bravo_add_filter_buttons($filter, isset($sections['my_bravos']));

  if (empty($page['items']))
  {
    $page['infos'][] = l10n('No photos match the filter.');
  }
}

/**
 * Adds the filter icons to the toolbar of the album page, as one group in
 * a common frame. In the collapsed menus of Bootstrap Darkroom and Modus the
 * group is one line headed "Filter"; the meaning of each icon is in its
 * tooltip.
 *
 * @param string[] $filter active groups
 * @param bool $with_bravo whether any album is in the mode like + bravo
 */
function like_bravo_add_filter_buttons($filter, $with_bravo)
{
  global $conf, $template;

  $settings = $conf['like_bravo'];
  $buttons = array(
    'like' => array(like_bravo_icon($settings['icon_like']), l10n('Filter: photos I like')),
    'bravo' => array(like_bravo_icon($settings['icon_bravo']), l10n('Filter: photos I gave a bravo')),
    'none' => array(like_bravo_icon('ban'), l10n('Filter: photos without my like or bravo')),
    );
  if (!$with_bravo)
  {
    unset($buttons['bravo']);
  }

  $base_url = duplicate_index_url(array(), array('start'));
  $title = htmlspecialchars(l10n('Filter'));

  $links = '';
  foreach ($buttons as $group => $button)
  {
    $active = in_array($group, $filter, true);
    $url = add_url_params($base_url, array('lb_filter' => implode('-', like_bravo_toggle_filter($filter, $group))));
    $label = htmlspecialchars($button[1]);

    $links.= '<a href="'.$url.'" rel="nofollow"'
      .' class="like-bravo-filter like-bravo-filter-'.$group.($active ? ' is-active' : '').'"'
      .' title="'.$label.'" aria-label="'.$label.'" aria-pressed="'.($active ? 'true' : 'false').'">'
      .$button[0].'</a>';
  }

  // nav-link: colors of Bootstrap Darkroom; pwg-button-text: shown by Modus
  // in its collapsed menu, hidden everywhere else
  $template->add_index_button(
    '<span class="like-bravo-filters nav-link" role="group" aria-label="'.$title.'">'
      .'<span class="like-bravo-filters-title pwg-button-text d-lg-none">'.$title.'</span>'
      .'<span class="like-bravo-filters-icons">'.$links.'</span>'
      .'</span>',
    BUTTONS_RANK_NEUTRAL
    );

  $template->func_combine_css(array('path' => 'plugins/'.LIKE_BRAVO_ID.'/css/like_bravo.css'));
}
