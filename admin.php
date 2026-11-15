<?php
defined('LIKE_BRAVO_PATH') or die('Hacking attempt!');

// admin.php?page=plugin-like_bravo        -> settings
// admin.php?page=plugin-like_bravo-album  -> "Member rating" tab of an album

check_status(ACCESS_ADMINISTRATOR);

if (isset($_GET['tab']) and 'album' == $_GET['tab'])
{
  include(LIKE_BRAVO_PATH . 'admin/album.php');
}
else
{
  include(LIKE_BRAVO_PATH . 'admin/settings.php');
}
