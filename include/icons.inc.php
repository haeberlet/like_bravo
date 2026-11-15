<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Inline SVG icons, drawn on a 24x24 grid with strokes in currentColor so
 * they take the text color of whatever theme they are placed in.
 *
 * @return array icon name => inner SVG markup
 */
function club_likes_icon_shapes()
{
  return array(
    'thumb' =>
      '<path d="M7 10v10H3V10z"/>'
      .'<path d="M7 10l4-7a2.5 2.5 0 0 1 2.5 2.5V9h5.1a2 2 0 0 1 2 2.4l-1.6 7a2 2 0 0 1-2 1.6H7"/>',
    'heart' =>
      '<path d="M12 20.5l-1.4-1.3C5.4 14.5 2 11.4 2 7.6 2 4.5 4.4 2.5 7.2 2.5c1.7 0 3.4.8 4.8 2.4'
      .' 1.4-1.6 3.1-2.4 4.8-2.4 2.8 0 5.2 2 5.2 5.1 0 3.8-3.4 6.9-8.6 11.6z"/>',
    'smile' =>
      '<circle cx="12" cy="12" r="9.5"/>'
      .'<path d="M8 14c1 1.5 2.4 2.2 4 2.2s3-.7 4-2.2"/>'
      .'<path d="M9 9.3v.9M15 9.3v.9"/>',
    'star' =>
      '<path d="M12 2.5l2.9 6.1 6.6.9-4.8 4.6 1.2 6.6-5.9-3.2-5.9 3.2 1.2-6.6-4.8-4.6 6.6-.9z"/>',
    'sparkle' =>
      '<path d="M10 3l1.9 5.6L17.5 10.5l-5.6 1.9L10 18l-1.9-5.6L2.5 10.5l5.6-1.9z"/>'
      .'<path d="M18.5 15v5M16 17.5h5"/>',
    'trophy' =>
      '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/>'
      .'<path d="M7 6H4v1a3 3 0 0 0 3 3M17 6h3v1a3 3 0 0 1-3 3"/>'
      .'<path d="M12 14v4M8 20h8"/>',
    'medal' =>
      '<circle cx="12" cy="15.5" r="5"/>'
      .'<path d="M9 11L6 3h4l2 4 2-4h4l-3 8"/>',
    'ban' =>
      '<circle cx="12" cy="12" r="9"/>'
      .'<path d="M5.6 5.6l12.8 12.8"/>',
    'cross' =>
      '<path d="M6 6l12 12M18 6L6 18"/>',
    );
}

/**
 * @param string $name icon name
 * @param int $size width and height in pixels
 * @return string complete <svg> element, empty string for an unknown icon
 */
function club_likes_icon($name, $size=20)
{
  $shapes = club_likes_icon_shapes();

  if (!isset($shapes[$name]))
  {
    return '';
  }

  $size = (int)$size;

  return '<svg class="club-likes-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
    .' width="'.$size.'" height="'.$size.'" fill="none" stroke="currentColor" stroke-width="2"'
    .' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    .$shapes[$name]
    .'</svg>';
}
