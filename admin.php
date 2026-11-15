<?php
defined('CLUB_LIKES_PATH') or die('Hacking attempt!');

// admin.php?page=plugin-club_likes        -> settings
// admin.php?page=plugin-club_likes-album  -> "Member rating" tab of an album

check_status(ACCESS_ADMINISTRATOR);

if (isset($_GET['tab']) and 'album' == $_GET['tab'])
{
  include(CLUB_LIKES_PATH . 'admin/album.php');
}
else
{
  include(CLUB_LIKES_PATH . 'admin/settings.php');
}
