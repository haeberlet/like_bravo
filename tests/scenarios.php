<?php
/**
 * Scenario tests against a running Piwigo: switching albums between stars
 * and thumbs, photos in several albums, album deletion, deactivation,
 * uninstall and reinstall, votes of guests and "generic" accounts, albums
 * without rating.
 *
 * ONLY FOR A TEST GALLERY. The run replaces all ratings and album modes while
 * it works and uninstalls the plugin once. Existing ratings, modes and
 * settings are saved first and put back at the end, also if the run aborts.
 * Needs at least three photos in the gallery.
 *
 *   php tests/scenarios.php      (inside the Piwigo container)
 */

if (PHP_SAPI !== 'cli')
{
  die('Hacking attempt!');
}

chdir(dirname(__DIR__, 3));
define('PHPWG_ROOT_PATH','./'); define('IN_ADMIN', true);
$_SERVER['REMOTE_ADDR']='192.168.7.33'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_URI']='/admin.php'; $_SERVER['SCRIPT_NAME']='/admin.php';
include_once('./include/common.inc.php');
include_once('./admin/include/functions.php');
include_once('./admin/include/plugins.class.php');
include_once('./include/functions_rate.inc.php');
include_once('./include/ws_core.inc.php');
include_once(LIKE_BRAVO_PATH.'include/score.inc.php');
include_once(LIKE_BRAVO_PATH.'include/ws.inc.php');
error_reporting(E_ALL); ini_set('display_errors', 1);
$user = build_user($conf['webmaster_id'], true);

$photos = query2array('SELECT id FROM '.IMAGES_TABLE.' ORDER BY id LIMIT 3', null, 'id');
if (count($photos) < 3)
{
  die("at least three photos are needed\n");
}
list($a, $b, $c) = array_map('intval', $photos);

$fail = 0;
function ok($label, $expected, $actual) { global $fail; $good = $expected == $actual; if (!$good) $fail++; echo ($good ? 'PASS ' : 'FAIL ').$label.($good ? '' : '  expected '.json_encode($expected).' got '.json_encode($actual))."\n"; }
function scores() { $s = query2array('SELECT id, rating_score FROM '.IMAGES_TABLE.' WHERE rating_score IS NOT NULL', 'id', 'rating_score'); ksort($s); return array_map('floatval', $s); }
function rates_sig() { return md5(json_encode(query2array('SELECT user_id, element_id, rate FROM '.RATE_TABLE.' ORDER BY element_id, user_id'))); }
function core_scores() { // independent re-implementation of the core formula over all rates
  $by = array(); foreach (query2array('SELECT element_id e, rate r FROM '.RATE_TABLE) as $x) $by[$x['e']][] = (int)$x['r'];
  if (!$by) return array(); $n = 0; $s = 0; foreach ($by as $r) { $n += count($r); $s += array_sum($r); } $m = $s/$n; $c = $n/count($by);
  $out = array(); foreach ($by as $id => $r) $out[$id] = round(($c*$m + array_sum($r))/($c + count($r)), 2); ksort($out); return $out; }
function vote($img, $uid, $rate) { single_insert(RATE_TABLE, array('user_id'=>$uid,'anonymous_id'=>'10.0.0','element_id'=>$img,'rate'=>$rate,'date'=>'2026-11-15')); }
function mode($cat, $m) { like_bravo_set_category_mode($cat, $m); update_rating_score(); }

// ---- snapshot of whatever is in the test database ----
$snap = array(
  'rates' => query2array('SELECT * FROM '.RATE_TABLE),
  'modes' => query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE),
  'conf' => $conf['like_bravo'],
  );
echo "snapshot: ".count($snap['rates'])." rates, ".count($snap['modes'])." modes\n";
register_shutdown_function('restore_snapshot');
pwg_query('DELETE FROM '.RATE_TABLE); pwg_query('DELETE FROM '.LIKE_BRAVO_MODES_TABLE); like_bravo_get_explicit_modes(true);
conf_update_param('like_bravo', like_bravo_default_conf(), true);

$t1 = create_virtual_category('cl_test_T1')['id'];
$t2 = create_virtual_category('cl_test_T2', $t1)['id'];
$t3 = create_virtual_category('cl_test_T3')['id'];
mass_inserts(IMAGE_CATEGORY_TABLE, array('image_id','category_id'), array(
  array('image_id'=>$a,'category_id'=>$t2), array('image_id'=>$b,'category_id'=>$t2),
  array('image_id'=>$b,'category_id'=>$t3), array('image_id'=>$c,'category_id'=>$t3)));
echo "albums T1=$t1 > T2=$t2, T3=$t3; photo A in T2, B in T2+T3, C in T3\n\n";

echo "== 1. star ratings, no album in a thumbs mode\n";
foreach (array(9001=>5, 9002=>4, 9003=>3, 9004=>1, 9005=>0) as $u=>$r) vote($a, $u, $r);
vote($b, 9001, 3); vote($c, 9001, 5); vote($c, 9002, 2);
update_rating_score(); $stars = scores(); $sig = rates_sig();
ok('plugin star scores equal the core formula', core_scores(), $stars);

echo "== 2. stars -> thumbs: T1 becomes like + bravo (T2 inherits)\n";
mode($t1, LIKE_BRAVO_MODE_BOTH);
ok('photo A: mode like + bravo through inheritance', 2, like_bravo_get_image_mode($a));
ok('photo A: 3 = like, 4-5 = bravo, 0-1 dormant', array('likes'=>1,'bravos'=>2,'dormant'=>2), like_bravo_get_image_votes($a, 2));
$s = scores();
ok('photo A: 10 points, average of A and B is 6', 3.13, $s[$a]);
ok('photo B: one like, 2 points', 1.25, $s[$b]);
ok('photo C stays a star photo, mean from star photos only', 3.5, $s[$c]);
ok('stored ratings untouched', $sig, rates_sig());

echo "== 3. photo in several albums: the mode allowing more rating wins\n";
mode($t3, LIKE_BRAVO_MODE_LIKE);
ok('photo B in like + bravo (T2) and like (T3): like + bravo', 2, like_bravo_get_image_mode($b));
ok('photo C only in T3: like', 1, like_bravo_get_image_mode($c));
$s = scores();
ok('photo C: 5 and 2 both count as likes, 4 points, average 16/3', 2.14, $s[$c]);
mode($t2, LIKE_BRAVO_MODE_STARS);
ok('T2 explicitly stars stops inheritance: photo A is a star photo', 0, like_bravo_get_image_mode($a));
ok('photo B now stars (T2) and like (T3): like', 1, like_bravo_get_image_mode($b));
ok('photo B: bravo is not offered', array('none','like'), like_bravo_allowed_levels(like_bravo_get_image_mode($b)));
$s = scores();
ok('photo A back to its star score, alone among star photos', core_scores_of(array($a)), $s[$a]);
mode($t2, null);
ok('T2 back to inherited: photo A like + bravo again', 2, like_bravo_get_image_mode($a));

echo "== 4. member votes, then thumbs -> stars\n";
like_bravo_set_user_level($b, 9010, 'bravo'); like_bravo_set_user_level($a, 9010, 'like'); like_bravo_set_user_level($a, 9011, 'like');
like_bravo_set_user_level($a, 9011, 'none');
ok('bravo stored as 4, like as 2, removed vote leaves no row',
  array(array('user_id'=>'9010','element_id'=>(string)$a,'rate'=>'2'), array('user_id'=>'9010','element_id'=>(string)$b,'rate'=>'4')),
  query2array('SELECT user_id, element_id, rate FROM '.RATE_TABLE.' WHERE user_id IN (9010, 9011) ORDER BY element_id'));
$sig = rates_sig(); $thumbs = scores();
mode($t1, null); mode($t3, null);
ok('all albums back to stars: scores are the core scores of all ratings', core_scores(), scores());
ok('stored ratings untouched', $sig, rates_sig());
mode($t1, LIKE_BRAVO_MODE_BOTH); mode($t3, LIKE_BRAVO_MODE_LIKE);
ok('switching back to thumbs restores the same thumbs scores', $thumbs, scores());

echo "== 5. album deletion\n";
delete_categories(array($t3));
ok('mode of the deleted album is gone', array($t1), array_keys(like_bravo_get_explicit_modes(true)));
ok('photo C only left in a star album: star photo', 0, like_bravo_get_image_mode($c));
ok('photo B still like + bravo through T2', 2, like_bravo_get_image_mode($b));
$s = scores();
ok('photo C got a star score right after the deletion', core_scores_of(array($c)), $s[$c]);
$before_delete = scores();

echo "== 6. deactivate and activate\n";
$errors = (new plugins())->perform_action('deactivate', 'like_bravo');
ok('deactivate without errors', array(), $errors);
ok('scores handed back to the core formula', core_scores(), scores());
ok('album modes are kept while inactive', 1, count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE)));
ok('stored ratings untouched', $sig, rates_sig());
$errors = (new plugins())->perform_action('activate', 'like_bravo');
ok('activate without errors', array(), $errors);
ok('thumbs scores are back after activation', $before_delete, scores());
add_event_handler('update_rating_score', 'like_bravo_update_rating_score');

echo "== 7. uninstall\n";
$n_rates = count(query2array('SELECT * FROM '.RATE_TABLE));
$errors = (new plugins())->perform_action('uninstall', 'like_bravo');
ok('uninstall without errors', array(), $errors);
ok('plugin table removed', 0, count(query2array("SHOW TABLES LIKE '".LIKE_BRAVO_MODES_TABLE."'")));
ok('plugin settings removed', 0, count(query2array("SELECT * FROM ".CONFIG_TABLE." WHERE param LIKE 'like_bravo%'")));
ok('plugin no longer registered', 0, count(query2array("SELECT * FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'")));
ok('all ratings are still there', $n_rates, count(query2array('SELECT * FROM '.RATE_TABLE)));
ok('scores are the core scores', core_scores(), scores());

echo "== 8. install again\n";
$errors = (new plugins())->perform_action('activate', 'like_bravo');
ok('fresh install without errors', array(), $errors);
ok('table is back and empty', array(1, 0), array(count(query2array("SHOW TABLES LIKE '".LIKE_BRAVO_MODES_TABLE."'")), count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))));
ok('plugin active', 'active', query2array("SELECT state FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'", null, 'state')[0]);

echo "== 9. guests\n";
// T1 like + bravo: photos A and B are thumbs photos, photo C is a star photo
pwg_query('DELETE FROM '.RATE_TABLE);
$conf['like_bravo'] = like_bravo_default_conf();
$rate_anonymous_before = $conf['rate_anonymous'];
remove_event_handler('update_rating_score', 'like_bravo_update_rating_score'); // lost in this process by the uninstall above
add_event_handler('update_rating_score', 'like_bravo_update_rating_score');
mode($t1, LIKE_BRAVO_MODE_BOTH);
$webmaster = $user; $guest = (int)$conf['guest_id'];
$errors = array();
$generic = (int)register_user('lb_test_generic', 'lb'.uniqid(), '', false, $errors);
pwg_query('UPDATE '.USER_INFOS_TABLE." SET status = 'generic' WHERE user_id = ".$generic);

$conf['like_bravo']['allow_guests'] = false; $conf['rate_anonymous'] = true;
visitor($guest, '203.0.113.7');
ok('plugin option off: guest is refused', 'error 401', ws_vote($a, 'like'));
visitor($generic, '203.0.113.7');
ok('plugin option off: "generic" account is refused', 'error 401', ws_vote($a, 'like'));
$conf['like_bravo']['allow_guests'] = true; $conf['rate_anonymous'] = false;
visitor($guest, '203.0.113.7');
ok('"Rating by guests" off in Piwigo: guest is refused', 'error 401', ws_vote($a, 'like'));
ok('nothing was stored', 0, count(guest_rows()));

$conf['rate_anonymous'] = true;
ok('guest without a valid token is refused', 'error 403', ws_vote($a, 'like', 'wrong'));
ok('guest likes photo A', array('likes'=>1,'bravos'=>0,'level'=>'like'), ws_vote($a, 'like'));
ok('stored for the guest account with the network as identifier', array("$guest|203.0.113|$a|2"), guest_rows());
ok('native rating stays refused on a thumbs photo', true,
  @like_bravo_ws_invoke_allowed(true, 'pwg.images.rate', array('image_id'=>$a, 'rate'=>5)) instanceof PwgError);

visitor($guest, '203.0.113.200');
ok('second visitor of the same /24 sees the shared vote', 'like', like_bravo_get_user_level($a, $guest, 2, like_bravo_current_anonymous_id()));
ok('and replaces it', array('likes'=>0,'bravos'=>1,'level'=>'bravo'), ws_vote($a, 'bravo'));
ok('still one row for this network', array("$guest|203.0.113|$a|4"), guest_rows());

visitor($guest, '198.51.100.5');
ok('visitor of another network has not voted yet', 'none', like_bravo_get_user_level($a, $guest, 2, like_bravo_current_anonymous_id()));
ok('and gets a vote of his own', array('likes'=>1,'bravos'=>1,'level'=>'like'), ws_vote($a, 'like'));
visitor($guest, '2001:db8::1'); ws_vote($a, 'like');
visitor($guest, '2001:db8::2');
ok('IPv6: every address is a visitor of its own', array('likes'=>2,'bravos'=>2,'level'=>'bravo'), ws_vote($a, 'bravo'));
visitor($guest, '::ffff:1.2.3.4'); ws_vote($a, 'like');
ok('identifiers: IPv4 network, whole IPv6 address, IPv4 in IPv6 without last part',
  array("$guest|198.51.100|$a|2", "$guest|2001:db8::1|$a|2", "$guest|2001:db8::2|$a|4", "$guest|203.0.113|$a|4", "$guest|::ffff:1.2.3|$a|2"),
  guest_rows());

visitor($guest, '198.51.100.5');
ok('guest takes his vote back', array('likes'=>2,'bravos'=>2,'level'=>'none'), ws_vote($a, 'none'));
ok('only his own row is gone',
  array("$guest|2001:db8::1|$a|2", "$guest|2001:db8::2|$a|4", "$guest|203.0.113|$a|4", "$guest|::ffff:1.2.3|$a|2"),
  guest_rows());
ok('taking back twice changes nothing', array('likes'=>2,'bravos'=>2,'level'=>'none'), ws_vote($a, 'none'));

// address change: the cookie written by the core carries the old identifier
visitor($guest, '203.0.113.7'); ws_vote($b, 'like');
$cookie = $_COOKIE['pwg_anonymous_rater'];
ok('core cookie remembers the identifier', '203.0.113', $cookie);
visitor($guest, '192.0.2.9', $cookie);
ok('new address, vote on photo A not shown as own (core reads the address only)', 'none', like_bravo_get_user_level($a, $guest, 2, like_bravo_current_anonymous_id()));
ok('next vote: the core moves the old votes to the new address', array('likes'=>2,'bravos'=>2,'level'=>'bravo'), ws_vote($a, 'bravo'));
ok('no row left under the old identifier, no duplicate',
  array("$guest|192.0.2|$a|4", "$guest|192.0.2|$b|2", "$guest|2001:db8::1|$a|2", "$guest|2001:db8::2|$a|4", "$guest|::ffff:1.2.3|$a|2"),
  guest_rows());

// "generic" account: one user id shared by several visitors
visitor($generic, '203.0.113.7');
ok('"generic" account votes like a guest', array('likes'=>3,'bravos'=>2,'level'=>'like'), ws_vote($a, 'like'));
visitor($generic, '198.51.100.5');
ok('second visitor of the same account has a vote of his own', array('likes'=>3,'bravos'=>3,'level'=>'bravo'), ws_vote($a, 'bravo'));
visitor($generic, '203.0.113.7');
ok('first visitor takes his vote back', array('likes'=>2,'bravos'=>3,'level'=>'none'), ws_vote($a, 'none'));
ok('the vote of the second visitor stays', array("$generic|198.51.100|$a|4"), guest_rows($generic));

// counting and score
$user = $webmaster; $_SERVER['REMOTE_ADDR'] = '192.168.7.33';
like_bravo_set_user_level($a, 9010, 'like');
$s = scores();
ok('guests take part: member and guest votes count', array('likes'=>3,'bravos'=>3,'dormant'=>0), like_bravo_get_image_votes($a, 2));
ok('photo A: 18 points, average of A and B is 10', 3.21, $s[$a]);
ok('photo B: one guest like, 2 points', 0.83, $s[$b]);
$sig = rates_sig();
$conf['like_bravo']['allow_guests'] = false; update_rating_score(); $s = scores();
ok('option off: only the member vote counts', array('likes'=>1,'bravos'=>0,'dormant'=>0), like_bravo_get_image_votes($a, 2));
ok('photo A: one like, the only rated thumbs photo', 2.5, $s[$a]);
ok('photo B: guest votes only, not rated', false, isset($s[$b]));
ok('stored ratings untouched', $sig, rates_sig());
$conf['like_bravo']['allow_guests'] = true; $conf['rate_anonymous'] = false; update_rating_score(); $s = scores();
ok('"Rating by guests" off in Piwigo: guest votes are dormant as well', array(2.5, false), array($s[$a], isset($s[$b])));
$conf['rate_anonymous'] = true; update_rating_score(); $s = scores();
ok('both options on again: scores are back', array(3.21, 0.83), array($s[$a], $s[$b]));

// star photo: native guest rating as in the core
visitor($guest, '203.0.113.7');
ok('plugin method refused on a star photo', 'error 403', ws_vote($c, 'like'));
@rate_picture($c, 4);
ok('native guest rating on a star photo', array("$guest|203.0.113|$c|4"), array_values(array_filter(guest_rows(), function($r) use ($c) { return "|$c|" == substr($r, -strlen("|$c|4"), -1); })));
$s = scores();
ok('and it counts for the star score', 4.0, $s[$c]);
$user = $webmaster; $conf['rate_anonymous'] = $rate_anonymous_before;

echo "== 10. names of voters\n";
pwg_query('DELETE FROM '.RATE_TABLE);
$conf['like_bravo'] = like_bravo_default_conf();
$conf['rate_anonymous'] = true;
$member = array();
foreach (array('anna', 'ben', 'carl', 'dora') as $name) $member[$name] = (int)register_user('lb_test_'.$name, 'lb'.uniqid(), '', false, $errors);
$me = $webmaster['id']; $my_name = $webmaster['username'];
foreach (array(array($member['anna'], 2, '2026-11-01'), array($member['ben'], 3, '2026-11-10'), array($member['dora'], 2, '2026-11-12'),
    array($member['carl'], 4, '2026-11-05'), array($me, 5, '2026-11-02'), array($member['anna'], 5, '2026-11-12', $c)) as $v)
  single_insert(RATE_TABLE, array('user_id'=>$v[0], 'anonymous_id'=>'10.0.0', 'element_id'=>isset($v[3]) ? $v[3] : $a, 'rate'=>$v[1], 'date'=>$v[2]));
single_insert(RATE_TABLE, array('user_id'=>$guest, 'anonymous_id'=>'203.0.113', 'element_id'=>$a, 'rate'=>2, 'date'=>'2026-11-12'));
update_rating_score();

$user = $webmaster; $_SERVER['REMOTE_ADDR'] = '192.168.7.33';
$groups = like_bravo_get_voters($a, 2);
ok('likes: newest first', array('lb_test_dora', 'lb_test_ben', 'lb_test_anna'), array_column($groups['like']['voters'], 'name'));
ok('bravos: own vote first', array($my_name, 'lb_test_carl'), array_column($groups['bravo']['voters'], 'name'));
ok('guest vote is not listed while guests do not take part', 0, $groups['like']['guests']);
ok('default: member gets the complete lists, bravos first',
  array(array('level'=>'bravo', 'title'=>l10n('Bravo'), 'names'=>array($my_name, 'lb_test_carl'), 'extra'=>array()),
        array('level'=>'like', 'title'=>l10n('Like'), 'names'=>array('lb_test_dora', 'lb_test_ben', 'lb_test_anna'), 'extra'=>array())),
  ws_voters($a)['lists']);
$and = l10n('%s and %s');
ok('short form with three names',
  sprintf(l10n('Liked by %s'), sprintf($and, 'lb_test_dora, lb_test_ben', 'lb_test_anna')), like_bravo_summary_text('like', $groups['like']));
ok('short form names the visitor as "you"',
  sprintf(l10n('Bravo from %s'), sprintf($and, l10n('you (voter)'), 'lb_test_carl')), like_bravo_summary_text('bravo', $groups['bravo']));
$conf['like_bravo']['names_limit'] = 2;
ok('short form with two names and the rest as a number',
  sprintf(l10n('Liked by %s'), sprintf($and, 'lb_test_dora, lb_test_ben', sprintf(l10n('%d other voter'), 1))), like_bravo_summary_text('like', $groups['like']));
$conf['like_bravo']['names_limit'] = 3;
$r = ws_vote($a, 'like');
ok('a vote answers with the new short forms',
  array(array('likes'=>4, 'bravos'=>1, 'level'=>'like'), true, sprintf(l10n('Bravo from %s'), 'lb_test_carl')),
  array(array_intersect_key($r, array('likes'=>1, 'bravos'=>1, 'level'=>1)), 0 === strpos($r['summaries']['like'], sprintf(l10n('Liked by %s'), l10n('you (voter)'))), $r['summaries']['bravo']));
ok('star photo has no names', 'error 403', ws_voters($c));
ok('unknown photo', 'error 404', ws_voters(999999));

$conf['like_bravo']['allow_guests'] = true;
ok('guests take part: the guest vote is a number in the list',
  array(sprintf(l10n('+ %d guest'), 1)), ws_voters($a)['lists'][1]['extra']);
ok('and in the short form', true,
  false !== strpos(like_bravo_summary_text('like', like_bravo_get_voters($a, 2)['like']), sprintf(l10n('%d guest voter'), 1)));

visitor($guest, '203.0.113.7');
ok('names for members: guest gets no names', 'error 401', ws_voters($a));
ok('names for members: guest vote answers without short forms', false, isset(ws_vote($a, 'like')['summaries']));
visitor($generic, '203.0.113.7');
ok('names for members: "generic" account gets no names', 'error 401', ws_voters($a));
$conf['like_bravo']['show_names'] = 'all';
visitor($guest, '203.0.113.7');
ok('names for all: guest gets the names, newest first (the vote just cast)',
  array(array('lb_test_carl'), array($my_name, 'lb_test_dora', 'lb_test_ben', 'lb_test_anna')),
  array_column(ws_voters($a)['lists'], 'names'));
$conf['like_bravo']['show_names'] = 'off';
ok('names off: guest gets no names', 'error 401', ws_voters($a));
$user = $webmaster; $_SERVER['REMOTE_ADDR'] = '192.168.7.33';
ok('names off: member gets no names', 'error 401', ws_voters($a));
ok('names off: a vote answers without short forms', false, isset(ws_vote($a, 'none')['summaries']));
$conf['rate_anonymous'] = $rate_anonymous_before;

echo "== 11. my likes, my bravos\n";
include_once(LIKE_BRAVO_PATH.'include/sections.inc.php');
// photo A (like + bravo through T1): own like from section 10 taken back there; photo B in the same album
pwg_query('DELETE FROM '.RATE_TABLE.' WHERE user_id = '.(int)$me);
foreach (array(array($a, 2, '2026-11-03'), array($b, 5, '2026-11-08'), array($c, 5, '2026-11-09')) as $v)
  single_insert(RATE_TABLE, array('user_id'=>$me, 'anonymous_id'=>'10.0.0', 'element_id'=>$v[0], 'rate'=>$v[1], 'date'=>$v[2]));
$user = $webmaster;
ok('both pages are offered with a like + bravo album', array('my_likes'=>'like', 'my_bravos'=>'bravo'), like_bravo_available_sections());
ok('my likes: photo A', array($a), like_bravo_get_my_images('like'));
ok('my bravos: photo B, not the star photo C', array($b), like_bravo_get_my_images('bravo'));
mode($t1, LIKE_BRAVO_MODE_LIKE);
ok('like album only: no bravo page', array('my_likes'=>'like'), like_bravo_available_sections());
ok('my likes: the bravo now counts as a like, latest vote first', array($b, $a), like_bravo_get_my_images('like'));
mode($t1, null);
ok('no thumbs album: no pages', array(), like_bravo_available_sections());
mode($t1, LIKE_BRAVO_MODE_BOTH);
visitor($guest, '203.0.113.7');
ok('guest: no pages', array(), like_bravo_available_sections());
visitor($generic, '203.0.113.7');
ok('"generic" account: no pages', array(), like_bravo_available_sections());
$user = $webmaster; $_SERVER['REMOTE_ADDR'] = '192.168.7.33';

echo "== 12. no rating\n";
$conf['like_bravo'] = like_bravo_default_conf();
include_once(LIKE_BRAVO_PATH.'include/picture.inc.php');
pwg_query('DELETE FROM '.RATE_TABLE);
$t4 = create_virtual_category('cl_test_T4')['id'];
mass_inserts(IMAGE_CATEGORY_TABLE, array('image_id','category_id'), array(
  array('image_id'=>$b,'category_id'=>$t4), array('image_id'=>$c,'category_id'=>$t4)));
mode($t1, null);
vote($a, 9001, 5); vote($a, 9002, 3); vote($b, 9001, 4); vote($b, 9002, 2); vote($c, 9001, 5);
update_rating_score(); $sig = rates_sig();
mode($t4, LIKE_BRAVO_MODE_OFF);
echo "T4=$t4 without rating; photo B in T2 (no setting) + T4, C in T4 + its own albums\n";
ok('photo B: no rating beats an album without setting', LIKE_BRAVO_MODE_OFF, like_bravo_get_image_mode($b));
ok('photo C: no rating', LIKE_BRAVO_MODE_OFF, like_bravo_get_image_mode($c));
ok('photo A unaffected: star photo', LIKE_BRAVO_MODE_STARS, like_bravo_get_image_mode($a));
$s = scores();
ok('photos without rating have no score', array(false, false), array(isset($s[$b]), isset($s[$c])));
ok('photo A: star score from star photos only (alone: m = 4, C = 2)', 4.0, $s[$a]);
ok('native rating refused', true,
  @like_bravo_ws_invoke_allowed(true, 'pwg.images.rate', array('image_id'=>$b, 'rate'=>5)) instanceof PwgError);
$page['image_id'] = $b; $_GET['action'] = 'rate'; like_bravo_block_native_rate();
ok('native rating form on the picture page ignored', false, isset($_GET['action']));
unset($page['image_id']);
ok('plugin vote refused', 'error 403', ws_vote($b, 'like'));
ok('no names', 'error 403', ws_voters($b));
ok('no personal pages from an album without rating', array(), like_bravo_available_sections());
ok('photo B listed as not a star photo', LIKE_BRAVO_MODE_OFF, like_bravo_get_all_image_modes()[$b]);
mode($t2, LIKE_BRAVO_MODE_STARS);
ok('explicit stars beat no rating: photo B is a star photo', LIKE_BRAVO_MODE_STARS, like_bravo_get_image_mode($b));
ok('photo B gets its star score back', true, isset(scores()[$b]));
mode($t2, null); mode($t1, LIKE_BRAVO_MODE_BOTH);
ok('like + bravo beats no rating: photo B', LIKE_BRAVO_MODE_BOTH, like_bravo_get_image_mode($b));
ok('photo B: one like and one bravo count', array('likes'=>1,'bravos'=>1,'dormant'=>0), like_bravo_get_image_votes($b, LIKE_BRAVO_MODE_BOTH));
mode($t1, null); mode($t4, null);
ok('all back to stars: core scores of all ratings', core_scores(), scores());
ok('stored ratings untouched', $sig, rates_sig());

restore_snapshot();
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";

function restore_snapshot() {
  global $snap, $conf; static $done = false; if ($done) return; $done = true;
  if (!count(query2array("SELECT id FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo' AND state = 'active'"))) (new plugins())->perform_action('activate', 'like_bravo');
  $ids = query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'", null, 'id');
  if ($ids) delete_categories($ids);
  foreach (query2array("SELECT id FROM ".USERS_TABLE." WHERE username LIKE 'lb_test_%'", null, 'id') as $id) delete_user($id);
  pwg_query('DELETE FROM '.RATE_TABLE); pwg_query('DELETE FROM '.LIKE_BRAVO_MODES_TABLE);
  if ($snap['rates']) mass_inserts(RATE_TABLE, array_keys($snap['rates'][0]), $snap['rates']);
  if ($snap['modes']) mass_inserts(LIKE_BRAVO_MODES_TABLE, array_keys($snap['modes'][0]), $snap['modes']);
  conf_update_param('like_bravo', $snap['conf'], true);
  like_bravo_get_explicit_modes(true);
  remove_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  add_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  update_rating_score();
  echo "\nrestored: ".count(query2array('SELECT * FROM '.RATE_TABLE))." rates, ".count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))." modes, test albums left: "
    .count(query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'")).", test users left: "
    .count(query2array("SELECT id FROM ".USERS_TABLE." WHERE username LIKE 'lb_test_%'")).", plugin: "
    .json_encode(query2array("SELECT state FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'", null, 'state'))."\n";
}

function core_scores_of($ids) { // core formula restricted to the given star photos, returns the score of the first
  $by = array(); foreach (query2array('SELECT element_id e, rate r FROM '.RATE_TABLE.' WHERE element_id IN ('.implode(',', star_ids()).')') as $x) $by[$x['e']][] = (int)$x['r'];
  $n = 0; $s = 0; foreach ($by as $r) { $n += count($r); $s += array_sum($r); } $m = $s/$n; $c = $n/count($by);
  $r = $by[$ids[0]]; return round(($c*$m + array_sum($r))/($c + count($r)), 2); }
function star_ids() { $modes = like_bravo_get_all_image_modes(); $ids = array(); foreach (query2array('SELECT DISTINCT element_id e FROM '.RATE_TABLE, null, 'e') as $id) if (empty($modes[$id])) $ids[] = (int)$id; return $ids; }
function visitor($uid, $ip, $cookie = null) { // the next calls come from this account, address and browser
  global $user; $user = build_user($uid, false); $_SERVER['REMOTE_ADDR'] = $ip;
  if (null === $cookie) unset($_COOKIE['pwg_anonymous_rater']); else $_COOKIE['pwg_anonymous_rater'] = $cookie; }
function ws_vote($img, $level, $token = null) { // likeBravo.setVote as the current visitor
  $params = array('image_id'=>$img, 'level'=>$level, 'pwg_token'=> null === $token ? get_pwg_token() : $token); $service = null;
  $r = @like_bravo_ws_set_vote($params, $service); // @: the core sets a cookie, impossible on the command line
  return $r instanceof PwgError ? 'error '.$r->code() : $r; }
function guest_rows($uid = null) { global $conf; // "user|identifier|photo|rate" of one anonymous account
  $rows = array(); foreach (query2array('SELECT user_id u, anonymous_id a, element_id e, rate r FROM '.RATE_TABLE.' WHERE user_id = '.(int)(null === $uid ? $conf['guest_id'] : $uid).'') as $x) $rows[] = implode('|', $x);
  sort($rows); return $rows; }
function ws_voters($img) { // likeBravo.getVoters as the current visitor
  $params = array('image_id'=>$img); $service = null;
  $r = @like_bravo_ws_get_voters($params, $service);
  return $r instanceof PwgError ? 'error '.$r->code() : $r; }
