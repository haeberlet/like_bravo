<?php
/**
 * Scenario tests against a running Piwigo: switching albums between stars
 * and thumbs, photos in several albums, album deletion, deactivation,
 * uninstall and reinstall, votes of guests and "generic" accounts, albums
 * without rating, the data of the feedback for owners (time of votes,
 * edited comments, clean-up).
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
include_once('./include/functions_comment.inc.php');
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
  'since' => $conf['like_bravo_feedback_since'],
  'discussion_since' => isset($conf['like_bravo_discussion_since']) ? $conf['like_bravo_discussion_since'] : null,
  'feedback' => array(),
  );
foreach (feedback_tables() as $table) $snap['feedback'][$table] = query2array('SELECT * FROM '.$table);
// also on disk: if the run dies while restoring, the data can still be put back by hand
file_put_contents('/tmp/like_bravo_scenarios_snapshot.json', json_encode($snap));
echo "snapshot: ".count($snap['rates'])." rates, ".count($snap['modes'])." modes, saved in /tmp/like_bravo_scenarios_snapshot.json\n";
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
ok('feedback tables removed', 0, count(array_filter(feedback_tables(), function($t) { return count(query2array("SHOW TABLES LIKE '".$t."'")); })));
ok('plugin settings removed', 0, count(query2array("SELECT * FROM ".CONFIG_TABLE." WHERE param LIKE 'like_bravo%'")));
ok('plugin no longer registered', 0, count(query2array("SELECT * FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'")));
ok('all ratings are still there', $n_rates, count(query2array('SELECT * FROM '.RATE_TABLE)));
ok('scores are the core scores', core_scores(), scores());

echo "== 8. install again\n";
$errors = (new plugins())->perform_action('activate', 'like_bravo');
ok('fresh install without errors', array(), $errors);
ok('table is back and empty', array(1, 0), array(count(query2array("SHOW TABLES LIKE '".LIKE_BRAVO_MODES_TABLE."'")), count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))));
ok('plugin active', 'active', query2array("SELECT state FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'", null, 'state')[0]);
ok('feedback tables are back, empty, start of feedback and of "Also commented" set', array(4, 0, true, true), array(
  count(array_filter(feedback_tables(), function($t) { return count(query2array("SHOW TABLES LIKE '".$t."'")); })),
  array_sum(array_map(function($t) { return count(query2array('SELECT * FROM '.$t)); }, feedback_tables())),
  !empty($conf['like_bravo_feedback_since']), !empty($conf['like_bravo_discussion_since'])));

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

echo "== 13. likes and bravos of the thumbnails\n";
include_once(LIKE_BRAVO_PATH.'include/thumbnails.inc.php');
$conf['like_bravo'] = like_bravo_default_conf();
pwg_query('DELETE FROM '.RATE_TABLE);
mode($t1, LIKE_BRAVO_MODE_BOTH); mode($t4, LIKE_BRAVO_MODE_OFF); // photo A like + bravo, C without rating
vote($a, $member['anna'], 2); vote($a, $member['ben'], 4); vote($a, $me, 5); vote($a, $member['carl'], 1);
vote($c, $member['anna'], 4);
visitor($me, '198.51.100.20');
$tn_in = array(array('id'=>$a, 'NAME'=>'A'), array('id'=>$c, 'NAME'=>'C'));
$tn_pics = array(array('id'=>$a, 'added_by'=>$me), array('id'=>$c, 'added_by'=>$me));
$tn = like_bravo_index_thumbnails($tn_in, $tn_pics);
ok('only the like photo gets a display, not the photo without rating', array(true, false), array(isset($tn[0]['LIKE_BRAVO']), isset($tn[1]['LIKE_BRAVO'])));
ok('other template variables kept', array('A', 'C'), array_column($tn, 'NAME'));
$h = $tn[0]['LIKE_BRAVO'];
ok('own bravo active, one like, two bravos, dormant vote left out', array(1, 1, 1, 1),
  array(preg_match('#data-level="bravo"#', $h), preg_match('#like-bravo-count-likes"[^>]*>1<#', $h),
        preg_match('#like-bravo-count-bravos"[^>]*>2<#', $h), preg_match('#class="like-bravo-button like-bravo-bravo is-active"#', $h)));
ok('two buttons with a token, no names', array(2, 1, 0),
  array(substr_count($h, '<button'), preg_match('#data-token="[0-9a-f]+"#', $h), substr_count($h, 'data-summary')));
$conf['like_bravo']['allow_own_photos'] = false;
$h = like_bravo_index_thumbnails($tn_in, $tn_pics)[0]['LIKE_BRAVO'];
ok('own photo without own votes allowed: numbers only', array(0, 2, 'none'),
  array(substr_count($h, '<button'), substr_count($h, 'like-bravo-symbol'), preg_match('#data-level="(\w+)"#', $h, $mm) ? $mm[1] : ''));
$conf['like_bravo']['allow_own_photos'] = true;
visitor($guest, '203.0.113.7');
$h = like_bravo_index_thumbnails($tn_in, $tn_pics)[0]['LIKE_BRAVO'];
ok('guest without guest votes: numbers only, no token', array(0, 0), array(substr_count($h, '<button'), substr_count($h, 'data-token')));
$conf['like_bravo']['allow_guests'] = true;
$h = like_bravo_index_thumbnails($tn_in, $tn_pics)[0]['LIKE_BRAVO'];
ok('guest with guest votes: buttons', 2, substr_count($h, '<button'));
$conf['like_bravo'] = like_bravo_default_conf();
mode($t1, LIKE_BRAVO_MODE_LIKE);
visitor($me, '198.51.100.20');
$h = like_bravo_index_thumbnails($tn_in, $tn_pics)[0]['LIKE_BRAVO'];
ok('like only: one button, bravo counts as like', array(1, 0, 1),
  array(substr_count($h, '<button'), substr_count($h, 'like-bravo-count-bravos'), preg_match('#like-bravo-count-likes"[^>]*>3<#', $h)));
$conf['like_bravo']['thumbnails'] = false;
ok('switched off: nothing added', $tn_in, like_bravo_index_thumbnails($tn_in, $tn_pics));
$conf['like_bravo'] = like_bravo_default_conf();
mode($t1, null); mode($t4, null);

echo "== 14. filter of album pages\n";
include_once(LIKE_BRAVO_PATH.'include/filter.inc.php');
$conf['like_bravo'] = like_bravo_default_conf();
pwg_query('DELETE FROM '.RATE_TABLE);
mode($t1, LIKE_BRAVO_MODE_BOTH); mode($t4, LIKE_BRAVO_MODE_OFF); // A and B like + bravo, C without rating
vote($a, $me, 2); vote($b, $me, 5); vote($c, $me, 4); vote($a, $member['anna'], 5);
visitor($me, '198.51.100.20');
$_SESSION = array(); $script_name = $_SERVER['SCRIPT_NAME'];
function filtered($script, $get, $items, $image_id = null) { // runs the filter on a page with these photos
  global $page, $template;
  $_SERVER['SCRIPT_NAME'] = '/'.$script.'.php'; $_GET = $get; $template->index_buttons = array();
  $page = array('section' => 'categories', 'items' => $items, 'infos' => array());
  if (null !== $image_id) $page['image_id'] = $image_id;
  like_bravo_filter_section_init();
  return $page['items']; }
function filter_buttons() { global $template; $html = implode('', array_merge(array(), ...array_values($template->index_buttons)));
  preg_match_all('#like-bravo-filter-(\w+)( is-active)?"#', $html, $m); $r = array();
  foreach ($m[1] as $i => $g) $r[$g] = '' !== $m[2][$i]; return $r; }
$all = array($a, $b, $c);
ok('no filter: all photos, three icons in one group, none active', array($all, array('like'=>false, 'bravo'=>false, 'none'=>false)),
  array(filtered('index', array(), $all), filter_buttons()));
ok('one toolbar entry for the group', 1, count($template->index_buttons[BUTTONS_RANK_NEUTRAL]));
ok('my likes', array($a), filtered('index', array('lb_filter' => 'like'), $all));
ok('icon active, kept for the session', array(array('like'=>true, 'bravo'=>false, 'none'=>false), 'like'),
  array(filter_buttons(), pwg_get_session_var('like_bravo_filter')));
ok('next page without parameter: filter from the session', array($a), filtered('index', array(), $all));
ok('likes or without my vote: a bravo is left out, the photo without rating stays', array($a, $c),
  filtered('index', array('lb_filter' => 'none-like'), $all));
ok('icon links switch one group each', array(1, 1, 1), array_map(function($p) use ($template) { return preg_match($p, implode('', $template->index_buttons[BUTTONS_RANK_NEUTRAL])); },
  array('#lb_filter=none"[^>]*like-bravo-filter-like is-active#', '#lb_filter="[^>]*like-bravo-filter-bravo"#', '#lb_filter=like"[^>]*like-bravo-filter-none is-active#')));
filtered('index', array('lb_filter' => 'bravo'), $all);
ok('photo page: previous and next in the filter, shown photo kept', array($b, $c), filtered('picture', array(), $all, $c));
ok('photo page: no parameter read there', 'bravo', (filtered('picture', array('lb_filter' => 'like'), $all, $b) and pwg_get_session_var('like_bravo_filter')));
ok('empty result: message', array(array(), 1), array(filtered('index', array('lb_filter' => 'like'), array($c)), count($page['infos'])));
ok('all three groups: no filter, session cleared', array($all, null), array(filtered('index', array('lb_filter' => 'like-bravo-none'), $all), pwg_get_session_var('like_bravo_filter')));
ok('page without like photos and no filter: no icons', array(), (filtered('index', array(), array($c)) and false) ?: filter_buttons());
filtered('index', array('lb_filter' => 'like'), $all);
ok('personal pages are not filtered', $all, (function() use ($all) { global $page; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_GET = array();
  $page = array('section' => 'my_bravos', 'items' => $all); like_bravo_filter_section_init(); return $page['items']; })());
visitor($guest, '203.0.113.7');
ok('guests: no filter', $all, filtered('index', array(), $all));
visitor($me, '198.51.100.20');
mode($t1, LIKE_BRAVO_MODE_LIKE);
filtered('index', array('lb_filter' => ''), $all);
ok('no like + bravo album: no bravo icon', array('like'=>false, 'none'=>false), filter_buttons());
mode($t1, null); mode($t4, null);
ok('no album in a thumbs mode: nothing filtered, no icons', array($all, array()),
  array(filtered('index', array('lb_filter' => 'like'), $all), filter_buttons()));
$_SERVER['SCRIPT_NAME'] = $script_name; $_GET = array(); $_SESSION = array();

echo "== 15. feedback: time of votes, edited comments, clean-up\n";
// T1 like + bravo again: photo A is a thumbs photo
$conf['like_bravo'] = like_bravo_default_conf(); $conf['like_bravo']['allow_guests'] = true; $conf['rate_anonymous'] = true;
$conf['comments_validation'] = false; // edits are saved at once
mode($t1, LIKE_BRAVO_MODE_BOTH);
pwg_query('DELETE FROM '.RATE_TABLE); foreach (feedback_tables() as $table) pwg_query('DELETE FROM '.$table);
$fb = (int)register_user('lb_test_feedback', 'lb'.uniqid(), '', false, $errors);
$fb2 = (int)register_user('lb_test_feedback2', 'lb'.uniqid(), '', false, $errors);
$owner = (int)$webmaster['id'];

visitor($fb, '203.0.113.7'); ws_vote($a, 'like');
ok('member like: a time under the key of the rate row', rate_keys($a), array_column(vote_times($a), 'key'));
pwg_query('UPDATE '.LIKE_BRAVO_VOTE_TIME_TABLE." SET changed_at = '2026-01-01 00:00:00'");
ws_vote($a, 'like');
ok('the same vote again keeps its time', array("$fb|203.0.113|2026-01-01 00:00:00"), array_column(vote_times($a), 'row'));
visitor($fb, '198.51.100.5'); ws_vote($a, 'like');
ok('same vote from another address: time kept, key follows the rate row', array("$fb|198.51.100|2026-01-01 00:00:00"), array_column(vote_times($a), 'row'));
ws_vote($a, 'bravo');
ok('like -> bravo: a new time, unread again', array(true), array_map(function($r) { return $r['t'] > '2026-01-01 00:00:00'; }, vote_times($a)));
insert_row(LIKE_BRAVO_FEEDBACK_READ_TABLE, array('user_id'=>$owner, 'kind'=>'vote', 'ref_id'=>$a, 'rater_id'=>$fb, 'rater_anonymous_id'=>'', 'read_at'=>'2026-11-23 12:00:00'));
ws_vote($a, 'none');
ok('vote taken back: no time, no read mark', array(0, 0), array(count(vote_times($a)), read_marks('vote', $a)));

visitor($guest, '203.0.113.7'); ws_vote($a, 'like');
visitor($guest, '198.51.100.5'); ws_vote($a, 'like');
ok('two guests: one time each, under the key of their rate rows', rate_keys($a), array_column(vote_times($a), 'key'));
visitor($guest, '203.0.113.7'); ws_vote($a, 'none');
ok('one guest takes the like back: only the other time is left', array("$guest|198.51.100"), array_column(vote_times($a), 'key'));
visitor($guest, '198.51.100.5');
ok('a refused vote stores no time', array('error 403', 1), array(ws_vote($c, 'like'), count(vote_times($a)) + count(vote_times($c))));

// comments of the member $fb on photo A, written on 20.11.
single_insert(COMMENTS_TABLE, array('image_id'=>$a, 'date'=>'2026-11-20 10:00:00', 'author'=>'lb_test_feedback', 'author_id'=>$fb, 'anonymous_id'=>'203.0.113',
  'content'=>'lb_test_comment', 'validated'=>'true', 'validation_date'=>'2026-11-20 10:00:00'));
$cid = (int)pwg_db_insert_id(COMMENTS_TABLE);
ok('a new comment is no edit', 'validate', trigger_change('user_comment_check', 'validate', array('image_id'=>$a, 'author'=>'lb_test_feedback', 'content'=>'lb_test_comment new')));
ok('nothing noted for a new comment', 0, count(query2array('SELECT * FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE)));
visitor($fb, '203.0.113.7');
$action = edit_comment($cid, $a, 'lb_test_comment edited', '');
ok('edit by its author: saved, noted, shown as edited', array('validate', 1, true), array($action, count(query2array('SELECT * FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' WHERE comment_id = '.$cid)), comment_edited($cid)));
pwg_query('UPDATE '.COMMENTS_TABLE." SET validation_date = '2026-11-20 10:00:00' WHERE id = ".$cid);
pwg_query('DELETE FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE);
$action = edit_comment($cid, $a, 'lb_test_comment refused', 'http://exa mple');
ok('edit refused by the core (invalid website): not shown as edited', array('reject', false), array($action, comment_edited($cid)));
insert_row(LIKE_BRAVO_FEEDBACK_READ_TABLE, array('user_id'=>$owner, 'kind'=>'comment', 'ref_id'=>$cid, 'rater_id'=>0, 'rater_anonymous_id'=>'', 'read_at'=>'2026-11-23 12:00:00'));
visitor($owner, '203.0.113.7');
delete_user_comment($cid);
ok('comment deleted: its edit and read marks are gone', array(0, 0), array(count(query2array('SELECT * FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' WHERE comment_id = '.$cid)), read_marks('comment', $cid)));

// photo deleted: the core removes it with its votes and comments, then tells the plugins
$gone = 9999999;
insert_row(LIKE_BRAVO_VOTE_TIME_TABLE, array('image_id'=>$gone, 'user_id'=>$fb, 'anonymous_id'=>'', 'changed_at'=>'2026-11-23 12:00:00'));
insert_row(LIKE_BRAVO_FEEDBACK_READ_TABLE, array('user_id'=>$owner, 'kind'=>'vote', 'ref_id'=>$gone, 'rater_id'=>$fb, 'rater_anonymous_id'=>'', 'read_at'=>'2026-11-23 12:00:00'));
insert_row(LIKE_BRAVO_COMMENT_EDIT_TABLE, array('comment_id'=>$gone, 'edited_at'=>'2026-11-23 12:00:00'));
insert_row(LIKE_BRAVO_FEEDBACK_READ_TABLE, array('user_id'=>$owner, 'kind'=>'comment', 'ref_id'=>$gone, 'rater_id'=>0, 'rater_anonymous_id'=>'', 'read_at'=>'2026-11-23 12:00:00'));
trigger_notify('delete_elements', array($gone));
ok('photo deleted: times, edits and read marks of it are gone', array(0, 0, 0, 0), array(count(vote_times($gone)), read_marks('vote', $gone),
  count(query2array('SELECT * FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE)), read_marks('comment', $gone)));

// member deleted: the core keeps the votes, the plugin keeps their times
visitor($fb2, '203.0.113.9'); ws_vote($a, 'like');
insert_row(LIKE_BRAVO_FEEDBACK_USER_TABLE, array('user_id'=>$fb2, 'read_all_before'=>'2026-11-23 12:00:00'));
insert_row(LIKE_BRAVO_FEEDBACK_READ_TABLE, array('user_id'=>$fb2, 'kind'=>'vote', 'ref_id'=>$b, 'rater_id'=>$fb, 'rater_anonymous_id'=>'', 'read_at'=>'2026-11-23 12:00:00'));
visitor($owner, '203.0.113.7');
delete_user($fb2);
ok('member deleted: own read marks gone, vote and its time kept', array(0, 0, 1, 1), array(
  count(query2array('SELECT * FROM '.LIKE_BRAVO_FEEDBACK_USER_TABLE.' WHERE user_id = '.$fb2)),
  count(query2array('SELECT * FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE user_id = '.$fb2)),
  count(query2array('SELECT * FROM '.RATE_TABLE.' WHERE user_id = '.$fb2)),
  count(query2array('SELECT * FROM '.LIKE_BRAVO_VOTE_TIME_TABLE.' WHERE user_id = '.$fb2))));

echo "== 16. feedback list of an owner\n";
include_once(LIKE_BRAVO_PATH.'include/feedback.inc.php');
// photos A and C belong to the owner: A like + bravo (T1), C a star photo (T3); B belongs to Alice
$conf['like_bravo'] = like_bravo_default_conf(); $conf['like_bravo']['allow_guests'] = true; $conf['rate_anonymous'] = true; $conf['comments_validation'] = false;
mode($t1, LIKE_BRAVO_MODE_BOTH);
pwg_query('DELETE FROM '.RATE_TABLE); foreach (feedback_tables() as $table) pwg_query('DELETE FROM '.$table);
$snap['added_by'] = query2array('SELECT id, added_by FROM '.IMAGES_TABLE." WHERE id IN ($a, $b, $c)", 'id', 'added_by');
foreach (array('owner', 'alice', 'bob', 'karl') as $name) $fbu[$name] = (int)register_user('lb_test_'.$name, 'lb'.uniqid(), '', false, $errors);
$own = $fbu['owner']; $al = $fbu['alice']; $bo = $fbu['bob']; $ca = $fbu['karl'];
pwg_query('UPDATE '.IMAGES_TABLE." SET added_by = $own WHERE id IN ($a, $c)"); pwg_query('UPDATE '.IMAGES_TABLE." SET added_by = $al WHERE id = $b");
pwg_query('UPDATE '.USER_INFOS_TABLE." SET registration_date = '2026-01-01 00:00:00' WHERE user_id = $own");
conf_update_param('like_bravo_feedback_since', '2026-01-01 00:00:00', true);

visitor($al, '203.0.113.21'); ws_vote($a, 'like');
visitor($bo, '203.0.113.22'); ws_vote($a, 'bravo'); ws_vote($b, 'like');
visitor($own, '203.0.113.23'); ws_vote($a, 'like');
visitor($guest, '198.51.100.7'); ws_vote($a, 'like');
vote($a, $ca, 1); vote($c, $al, 4); // dormant vote of Carl, star vote of Alice
pwg_query('UPDATE '.LIKE_BRAVO_VOTE_TIME_TABLE." SET changed_at = CASE user_id WHEN $al THEN '2026-11-20 10:00:00' WHEN $bo THEN '2026-11-20 11:00:00' ELSE '2026-11-20 09:00:00' END");
$long = 'lb_test_ Das Licht im Hintergrund ist wunderbar und die Farben leuchten richtig schön warm.';
$cm = array();
foreach (array(
    'alice' => array($a, $al, 'lb_test_alice', $long, '2026-11-20 12:00:00'),
    'bob_star' => array($c, $bo, 'lb_test_bob', 'lb_test_ on a star photo', '2026-11-20 08:00:00'),
    'bob_waiting' => array($a, $bo, 'lb_test_bob', 'lb_test_ not validated', null),
    'owner' => array($a, $own, 'lb_test_owner', 'lb_test_ my own', '2026-11-20 13:00:00'),
    'guest' => array($a, $guest, 'Hans', 'lb_test_ from a guest', '2026-11-20 07:00:00'),
    'carl_b' => array($b, $ca, 'lb_test_carl', 'lb_test_ on photo B', '2026-11-20 14:00:00'),
    ) as $key => $x)
{
  single_insert(COMMENTS_TABLE, array('image_id'=>$x[0], 'date'=>'2026-11-20 06:00:00', 'author'=>$x[2], 'author_id'=>$x[1], 'anonymous_id'=>'203.0.113',
    'content'=>$x[3], 'validated'=> null === $x[4] ? 'false' : 'true', 'validation_date'=>$x[4]));
  $cm[$key] = (int)pwg_db_insert_id(COMMENTS_TABLE);
}
ok('list: newest first; own votes and comments, dormant and star votes, other photos and waiting comments left out', array(
  "comment|member|lb_test_alice|$a|unread", "bravo|member|lb_test_bob|$a|unread", "like|member|lb_test_alice|$a|unread",
  "like|guest||$a|unread", "comment|member|lb_test_bob|$c|unread", "comment|guest||$a|unread"), fb_sig($own));
$list = like_bravo_feedback_list($own);
ok('votes carry the current totals of the photo (own vote counted, dormant not)', array(3, 1, LIKE_BRAVO_MODE_BOTH), array($list['entries'][1]['likes'], $list['entries'][1]['bravos'], $list['entries'][1]['mode']));
ok('comment: excerpt, not edited, time of validation', array('Das Licht im Hintergrund ist wunderbar und die Farben…', false, '2026-11-20 12:00:00'),
  array(like_bravo_excerpt(substr($long, 9)), $list['entries'][0]['edited'], $list['entries'][0]['time']));
ok('vote with a time of its own', array('2026-11-20 11:00:00', false), array($list['entries'][1]['time'], $list['entries'][1]['date_only']));
ok('unread count agrees with the list', 6, like_bravo_feedback_unread_count($own));

ok('mark read: Alice\'s like', true, like_bravo_feedback_mark_read($own, 'vote', $a, $al, ''));
ok('mark read: the guest\'s like, by its identifier', true, like_bravo_feedback_mark_read($own, 'vote', $a, $guest, '198.51.100'));
ok('mark read: refused for an entry of another owner', false, like_bravo_feedback_mark_read($own, 'vote', $b, $bo, ''));
ok('mark read: refused for a comment waiting for validation', false, like_bravo_feedback_mark_read($own, 'comment', $cm['bob_waiting']));
ok('two entries read', array(4, "like|member|lb_test_alice|$a"), array(like_bravo_feedback_unread_count($own), fb_sig($own)[2]));
pwg_query('UPDATE '.LIKE_BRAVO_FEEDBACK_READ_TABLE." SET read_at = '2026-11-21 00:00:00'"); // read yesterday
visitor($al, '203.0.113.21'); ws_vote($a, 'bravo');
ok('Alice changes like -> bravo: newest, unread again', array("bravo|member|lb_test_alice|$a|unread", 5), array(fb_sig($own)[0], like_bravo_feedback_unread_count($own)));
visitor($bo, '203.0.113.22'); ws_vote($a, 'none');
ok('Bob takes his bravo back: the entry is gone', 0, count(array_filter(fb_sig($own), function($e) { return 0 === strpos($e, 'bravo|member|lb_test_bob'); })));

$conf['like_bravo']['show_names'] = 'off';
ok('"Names of voters" off: votes without names, comments keep theirs', array("bravo|hidden||$a|unread", "comment|member|lb_test_alice|$a|unread"), array_slice(fb_sig($own), 0, 2));
$conf['like_bravo']['show_names'] = 'members';
$conf['like_bravo']['allow_guests'] = false;
ok('guests no longer take part: their votes are left out, their comments stay', array(false, true),
  array(in_array("like|guest||$a", fb_sig($own), true), in_array("comment|guest||$a|unread", fb_sig($own), true)));
$conf['like_bravo']['allow_guests'] = true;

ok('filter unread agrees with the count', like_bravo_feedback_unread_count($own), count(like_bravo_feedback_list($own, 1, 50, true)['entries']));
ok('filter votes / comments', array(array('bravo', 'like'), array('comment')),
  array(array_values(array_unique(array_column(like_bravo_feedback_list($own, 1, 50, false, 'votes')['entries'], 'kind'))),
    array_values(array_unique(array_column(like_bravo_feedback_list($own, 1, 50, false, 'comments')['entries'], 'kind')))));
$p1 = like_bravo_feedback_list($own, 1, 2); $p3 = like_bravo_feedback_list($own, 3, 2);
ok('pages of 2: page 1 full with more, page 3 the last', array(2, true, 1, false), array(count($p1['entries']), $p1['has_more'], count($p3['entries']), $p3['has_more']));
ok('pages together are the whole list', fb_sig($own), array_merge(fb_sig($own, 1, 2), fb_sig($own, 2, 2), fb_sig($own, 3, 2)));
$all = like_bravo_feedback_list($own, 1, 50)['entries'];
$after2 = like_bravo_feedback_cursor(like_bravo_feedback_cursor_text($all[1]['time'], $all[1]['read_key']));
ok('cursor: the entries after the second are the rest of the list', array_slice(fb_sig($own, 1, 50), 2), fb_sig($own, 1, 50, false, 'all', $after2));
$past_end = like_bravo_feedback_list($own, 1, 50, false, 'all', like_bravo_feedback_cursor(like_bravo_feedback_cursor_text(end($all)['time'], end($all)['read_key'])));
ok('cursor after the last entry: nothing more', array(array(), false), array($past_end['entries'], $past_end['has_more']));
$unread = like_bravo_feedback_list($own, 1, 50, true)['entries'];
$u_rest = array_slice(fb_sig($own, 1, 50, true), 2);
$u_key = $unread[0]['read_key'];
like_bravo_feedback_mark_read($own, $u_key['kind'], $u_key['ref_id'], $u_key['rater_id'], $u_key['rater_anonymous_id']);
ok('filter unread: one marked read on top, the cursor still skips nothing', $u_rest,
  fb_sig($own, 1, 50, true, 'all', like_bravo_feedback_cursor(like_bravo_feedback_cursor_text($unread[1]['time'], $unread[1]['read_key']))));
pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE user_id = '.$own." AND kind = '".$u_key['kind']."' AND ref_id = ".$u_key['ref_id']
  .' AND rater_id = '.$u_key['rater_id']." AND rater_anonymous_id = '".pwg_db_real_escape_string($u_key['rater_anonymous_id'])."'");
ok('the read mark of the test is gone again', count($unread), count(like_bravo_feedback_list($own, 1, 50, true)['entries']));

mode($t1, LIKE_BRAVO_MODE_LIKE);
ok('album switched to like only: Alice\'s bravo shows as a like', "like|member|lb_test_alice|$a|unread", fb_sig($own)[0]);
mode($t1, null);
ok('album switched to stars: no vote entries, comments stay', array('comment'), array_values(array_unique(array_map(function($e) { return explode('|', $e)[0]; }, fb_sig($own)))));
mode($t1, LIKE_BRAVO_MODE_BOTH);

like_bravo_feedback_mark_read($own, 'comment', $cm['alice']);
pwg_query('UPDATE '.LIKE_BRAVO_FEEDBACK_READ_TABLE." SET read_at = '2026-11-21 00:00:00'");
visitor($al, '203.0.113.21');
edit_comment($cm['alice'], $a, 'lb_test_ edited: even better', '');
$edited = array_values(array_filter(like_bravo_feedback_list($own)['entries'], function($e) use ($cm) { return 'comment' === $e['kind'] and $e['comment_id'] == $cm['alice']; }))[0];
ok('Alice edits her comment: unread again, edited, new text', array('lb_test_alice', true, true, 'edited: even better'),
  array($edited['name'], $edited['unread'], $edited['edited'], substr($edited['excerpt'], 9)));

like_bravo_feedback_mark_all_read($own);
ok('mark all as read: nothing unread, single marks dropped', array(0, 0, array()),
  array(like_bravo_feedback_unread_count($own), count(query2array('SELECT * FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE user_id = '.$own)),
    array_filter(array_column(like_bravo_feedback_list($own)['entries'], 'unread'))));
sleep(1); // times have whole seconds
visitor($ca, '203.0.113.24'); ws_vote($a, 'like');
ok('a new like after "mark all as read" is unread', array(1, "like|member|lb_test_karl|$a|unread"), array(like_bravo_feedback_unread_count($own), fb_sig($own)[0]));

pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_USER_TABLE.' WHERE user_id = '.$own);
conf_update_param('like_bravo_feedback_since', '2099-01-01 00:00:00', true);
ok('member without "mark all as read": what came before the start of feedback is read', 0, like_bravo_feedback_unread_count($own));
ok('a member without photos has no feedback', array(0, array()), array(like_bravo_feedback_unread_count($ca), like_bravo_feedback_list($ca)['entries']));

echo "== 17. web service of the feedback\n";
include_once(LIKE_BRAVO_PATH.'include/feedback_ws.inc.php');
$_SESSION = array();
conf_update_param('like_bravo_feedback_since', '2026-01-01 00:00:00', true);
single_insert(COMMENTS_TABLE, array('image_id'=>$a, 'date'=>'2026-11-20 06:00:00', 'author'=>'lb_test_alice', 'author_id'=>$al, 'anonymous_id'=>'203.0.113',
  'content'=>'lb_test_ Tom & Jerry <b>bold</b> "quoted"', 'validated'=>'true', 'validation_date'=>'2026-11-20 05:00:00'));
$cm['escape'] = (int)pwg_db_insert_id(COMMENTS_TABLE);

visitor($guest, '198.51.100.7');
ok('guests have no feedback', 'error 401', ws_feedback('list'));
visitor($own, '203.0.113.23');
ok('a wrong token is refused', array('error 403', 'error 403', 'error 403'),
  array(ws_feedback('list', array(), 'wrong'), ws_feedback('read', array('kind'=>'vote', 'ref_id'=>$a, 'rater_id'=>$al), 'wrong'), ws_feedback('all', array(), 'wrong')));
$r = ws_feedback('list');
ok('list: same entries as the data layer, and the unread count', array(count(like_bravo_feedback_list($own)['entries']), like_bravo_feedback_unread_count($own)),
  array(count($r['entries']), $r['unread']));
$first = $r['entries'][0];
ok('text of a like: the name in bold, as the language file says', sprintf(htmlspecialchars(l10n('%s likes your photo')), '<strong>lb_test_karl</strong>'), $first['html']);
if (isset($pwg_loaded_plugins['community'])) {
  // a home album of Karl (plugin Community): his name leads there; not to an album the owner may not see
  $home = create_virtual_category('cl_test_home_karl')['id'];
  pwg_query('UPDATE '.CATEGORIES_TABLE.' SET community_user = '.$ca.' WHERE id = '.$home);
  associate_images_to_categories(array($a), array($home)); invalidate_user_cache(); // Piwigo hides empty albums from members
  set_make_full_url();
  $home_url = make_index_url(array('category' => array('id' => $home, 'name' => 'cl_test_home_karl', 'permalink' => null)));
  unset_make_full_url();
  visitor($own, '203.0.113.23'); $user = build_user($own, true); // with the albums he may not see, as on a real page
  ok('the name of a member leads to his home album', sprintf(htmlspecialchars(l10n('%s likes your photo')), '<strong><a class="lb-bell-author" href="'.htmlspecialchars($home_url).'" data-ajax="false">lb_test_karl</a></strong>'),
    ws_feedback('list')['entries'][0]['html']);
  pwg_query('UPDATE '.CATEGORIES_TABLE.' SET status = \'private\' WHERE id = '.$home); invalidate_user_cache();
  visitor($own, '203.0.113.23'); $user = build_user($own, true);
  ok('no link to a home album the owner may not see', sprintf(htmlspecialchars(l10n('%s likes your photo')), '<strong>lb_test_karl</strong>'), ws_feedback('list')['entries'][0]['html']);
  pwg_query('UPDATE '.CATEGORIES_TABLE.' SET community_user = NULL WHERE id = '.$home);
  pwg_query('UPDATE '.CATEGORIES_TABLE.' SET status = \'public\' WHERE id = '.$home);
  pwg_query('DELETE FROM '.IMAGE_CATEGORY_TABLE.' WHERE category_id = '.$home); invalidate_user_cache();
  visitor($own, '203.0.113.23'); $user = build_user($own, true);
  ok('no link to an empty home album: Piwigo hides it from members', sprintf(htmlspecialchars(l10n('%s likes your photo')), '<strong>lb_test_karl</strong>'), ws_feedback('list')['entries'][0]['html']);
  associate_images_to_categories(array($a), array($home)); invalidate_user_cache();
  visitor($own, '203.0.113.23'); $user = build_user($own, true);
  ok('without a home album: no link', sprintf(htmlspecialchars(l10n('%s likes your photo')), '<strong>lb_test_karl</strong>'), ws_feedback('list')['entries'][0]['html']);
}
else {
  echo "SKIP home albums: the plugin Community is not active\n";
}
ok('photo page as full URL, square thumbnail, alt text', array(true, true, true, true),
  array(0 === strpos($first['url'], 'http'), false !== strpos($first['url'], 'picture.php?/'.$a), false !== strpos($first['thumb'], '-sq.'), '' !== $first['thumb_alt']));
ok('totals of the photo with a text for screen readers', array(true, true, true),
  array(isset($first['likes'], $first['bravos']), $first['with_bravo'], '' !== $first['totals_text']));
$escaped = array_values(array_filter($r['entries'], function($e) use ($cm) { return 'comment' === $e['kind'] and $e['key']['ref_id'] == $cm['escape']; }))[0];
ok('comment: escaped excerpt in italics, link to the comments', array(true, true),
  array(false !== strpos($escaped['html'], '<em>lb_test_ Tom &amp; Jerry bold &quot;quoted&quot;</em>'), '#comments' === substr($escaped['url'], -9)));
list($db_now) = pwg_db_fetch_row(pwg_query('SELECT NOW()'));
ok('older entries show the weekday in the last seven days, else the date',
  'week' === like_bravo_feedback_day('2026-11-20 05:00:00', $db_now)
    ? sprintf(l10n('%1$s, %2$d %3$s'), $lang['day'][5], 20, $lang['month'][11])
    : format_date('2026-11-20 05:00:00', array('day', 'month', 'year')),
  $escaped['when']);
ok('every entry knows its group of days', true, !in_array(false, array_map(function($e) { return in_array($e['day'], array('today', 'yesterday', 'week', 'date'), true); }, $r['entries'])));
ok('icons of like and bravo come along', array(true, true), array(0 === strpos($r['icons']['like'], '<svg'), 0 === strpos($r['icons']['bravo'], '<svg')));
$older = ws_feedback('list', array('before'=>$first['cursor']));
ok('cursor of the first entry: the others, the first not again', array(count($r['entries']) - 1, false),
  array(count($older['entries']), in_array($first['key'], array_column($older['entries'], 'key'))));
ok('a broken cursor is refused', 'error '.WS_ERR_INVALID_PARAM, ws_feedback('list', array('before'=>'1|2')));
ok('filters: unread only, comments only', array(true, array('comment')),
  array(count(ws_feedback('list', array('unread'=>true))['entries']) == $r['unread'], array_values(array_unique(array_column(ws_feedback('list', array('kind'=>'comments'))['entries'], 'kind')))));

$before = like_bravo_feedback_badge_count($own);
$read = ws_feedback('read', array('kind'=>$first['key']['kind'], 'ref_id'=>$first['key']['ref_id'], 'rater_id'=>$first['key']['rater_id'], 'rater_anonymous_id'=>$first['key']['rater_anonymous_id']));
ok('mark read: one unread less, also for the bell', array($before - 1, $before - 1), array($read['unread'], like_bravo_feedback_badge_count($own)));
ok('mark read: unknown entry, unknown kind', array('error 404', 'error 404'),
  array(ws_feedback('read', array('kind'=>'vote', 'ref_id'=>$b, 'rater_id'=>$bo)), ws_feedback('read', array('kind'=>'image', 'ref_id'=>$a))));
visitor($bo, '203.0.113.22'); ws_vote($a, 'bravo'); visitor($own, '203.0.113.23');
ok('the bell keeps its number for a minute, a fresh count sees the new bravo', array($before - 1, $before), array(like_bravo_feedback_badge_count($own), like_bravo_feedback_badge_count($own, 0)));
ok('mark all as read', array(0, 0), array(ws_feedback('all')['unread'], like_bravo_feedback_badge_count($own)));
$_SESSION = array();

echo "== 18. page \"All feedback\"\n";
include_once(LIKE_BRAVO_PATH.'include/feedback_page.inc.php');
$cur = '2026-11-20 05:00:00|comment|7|0|';
ok('page parameters: defaults, unknown values ignored', array(array('unread'=>false, 'kind'=>'all', 'before'=>''), array('unread'=>true, 'kind'=>'comments', 'before'=>$cur), array('unread'=>false, 'kind'=>'all', 'before'=>'')),
  array(like_bravo_feedback_page_params(array()), like_bravo_feedback_page_params(array('before'=>$cur, 'unread'=>'1', 'kind'=>'comments')),
    like_bravo_feedback_page_params(array('before'=>'<script>', 'kind'=>'<script>'))));
ok('page URL keeps only what differs from the defaults', array(make_index_url(array('section'=>'feedback')), make_index_url(array('section'=>'feedback')).'&amp;unread=1&amp;kind=votes&amp;before='.urlencode($cur)),
  array(like_bravo_feedback_page_url(array('before'=>'', 'unread'=>false, 'kind'=>'all')), like_bravo_feedback_page_url(array('before'=>$cur, 'unread'=>true, 'kind'=>'votes'))));
$page_before = $page; $tokens_before = isset($tokens) ? $tokens : null; $script_name = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_NAME'] = '/index.php'; $tokens = array('feedback'); $page = array('section'=>'categories', 'body_classes'=>array('section-categories'), 'body_data'=>array());
visitor($own, '203.0.113.23');
like_bravo_feedback_section_init();
ok('index.php?/feedback becomes the page of a member', array('feedback', array(), true), array($page['section'], $page['items'], in_array('section-feedback', $page['body_classes'])));
$_SESSION = array(); $_GET = array();
like_bravo_feedback_index();
$html = $template->get_template_vars('CONTENT');
$first20 = like_bravo_feedback_list($own, 1, LIKE_BRAVO_FEEDBACK_PER_PAGE);
$first_items = like_bravo_feedback_items($first20['entries']);
ok('content: one entry per feedback, each with its cursor, filters, mark all, link to older ones if there are more', array(count($first20['entries']), count($first20['entries']), 2, 1, $first20['has_more'] ? 1 : 0, 0),
  array(substr_count($html, 'class="lb-bell-entry'), substr_count($html, 'data-cursor="'), substr_count($html, 'class="lb-feedback-filter"'), substr_count($html, 'lb-bell-markall'),
    substr_count($html, 'class="lb-feedback-more"'), substr_count($html, 'lb-feedback-newest')));
ok('content: one heading and one list per group of days, newest first', array(count(array_unique(array_column($first_items, 'day'))), count(array_unique(array_column($first_items, 'day')))),
  array(substr_count($html, 'class="lb-feedback-day"'), substr_count($html, 'class="lb-bell-list lb-feedback-list"')));
$counts = like_bravo_feedback_counts($own);
ok('counts: all and unread agree with the list and the bell', array(count(like_bravo_feedback_list($own, 1, 100)['entries']), like_bravo_feedback_unread_count($own)), array($counts['all'], $counts['unread']));
ok('counts of one kind', count(like_bravo_feedback_list($own, 1, 100, false, 'comments')['entries']), like_bravo_feedback_counts($own, 'comments')['all']);
$tally = like_bravo_feedback_tally($own);
$by_kind = array_count_values(array_column(like_bravo_feedback_list($own, 1, 100)['entries'], 'kind'));
ok('tally: likes, bravos and comments as the list shows them', array(isset($by_kind['like']) ? $by_kind['like'] : 0, isset($by_kind['bravo']) ? $by_kind['bravo'] : 0, isset($by_kind['comment']) ? $by_kind['comment'] : 0, true),
  array(array_sum(array_column($tally['photos'], 'likes')), array_sum(array_column($tally['photos'], 'bravos')), array_sum(array_column($tally['photos'], 'comments')), $tally['with_bravo']));
ok('tally of the last days: not more than all', true, array_sum(array_map('array_sum', like_bravo_feedback_tally($own, 7)['photos'])) <= array_sum(array_map('array_sum', $tally['photos'])));
$top = like_bravo_feedback_top_photos($tally, 2);
ok('top photos: at most two, most feedback first, with link and thumbnail', array(true, true, true),
  array(count($top) <= 2, count($top) < 2 or array_sum(array($top[0]['likes'], $top[0]['bravos'], $top[0]['comments'])) >= array_sum(array($top[1]['likes'], $top[1]['bravos'], $top[1]['comments'])),
    empty($top) or (false !== strpos($top[0]['url'], 'picture.php') and false !== strpos($top[0]['thumb'], '-sq.'))));
ok('content: overview with the unread number, filters with numbers', array(true, 2),
  array(false !== strpos($html, '<span class="lb-feedback-big" data-lb-unread>'.like_bravo_feedback_badge_count($own).'</span>'), substr_count($html, 'class="lb-feedback-count"')));
ok('content: active filters "all"', 2, substr_count($html, 'class="is-active"'));
$_GET = array('kind'=>'comments');
like_bravo_feedback_index();
$html = $template->get_template_vars('CONTENT');
ok('filter comments: only comments', array(count(like_bravo_feedback_list($own, 1, 30, false, 'comments')['entries']), 0),
  array(substr_count($html, 'class="lb-bell-entry'), substr_count($html, 'lb-bell-total-like')));
$_GET = array('unread'=>'1');
like_bravo_feedback_index();
ok('filter unread after "mark all": the message for an empty filter', 1, substr_count($template->get_template_vars('CONTENT'), htmlspecialchars(l10n('No entries match the filter.'))));
$tokens = array('my_likes'); $page = array('section'=>'categories', 'body_classes'=>array(), 'body_data'=>array());
like_bravo_feedback_section_init();
ok('other sections are left alone', 'categories', $page['section']);
$page = $page_before; $tokens = $tokens_before; $_GET = array(); $_SESSION = array(); $_SERVER['SCRIPT_NAME'] = $script_name;
$template->assign('CONTENT', '');

echo "== 19. \"Also commented\": comments on photos of others the member commented on\n";
// Bob owns no photo. B belongs to Alice (T2, T4), C to the owner (T4), A to the owner (T2); all also in their own albums
$snap['level'] = query2array('SELECT id, level FROM '.IMAGES_TABLE." WHERE id IN ($a, $b, $c)", 'id', 'level');
foreach (feedback_tables() as $table) if (LIKE_BRAVO_VOTE_TIME_TABLE !== $table) pwg_query('DELETE FROM '.$table);
pwg_query('UPDATE '.USER_INFOS_TABLE." SET registration_date = '2026-01-01 00:00:00' WHERE user_id IN ($bo, $al)");
conf_update_param('like_bravo_feedback_since', '2026-01-01 00:00:00', true);
conf_update_param('like_bravo_discussion_since', '2026-01-01 00:00:00', true);
$conf['comments_validation'] = false; $_SESSION = array();
$dc = array();
foreach (array( // photo, author, name, written, validated (null: waiting)
    'karl_before' => array($b, $ca, 'lb_test_karl', '2026-11-21 08:00:00', '2026-11-21 08:00:00'),
    'owner_b' => array($b, $own, 'lb_test_owner', '2026-11-21 08:30:00', '2026-11-21 08:30:00'),
    'bob1' => array($b, $bo, 'lb_test_bob', '2026-11-21 09:00:00', '2026-11-21 09:00:00'),
    'bob_c' => array($c, $bo, 'lb_test_bob', '2026-11-21 09:30:00', '2026-11-21 09:30:00'),
    'alice_b' => array($b, $al, 'lb_test_alice', '2026-11-21 10:00:00', '2026-11-21 10:00:00'),
    'owner_c' => array($c, $own, 'lb_test_owner', '2026-11-21 10:30:00', '2026-11-21 10:30:00'),
    'guest_b' => array($b, $guest, 'Hans', '2026-11-21 11:00:00', '2026-11-21 11:00:00'),
    'bob2' => array($b, $bo, 'lb_test_bob', '2026-11-21 12:00:00', '2026-11-21 12:00:00'),
    'karl_b' => array($b, $ca, 'lb_test_karl', '2026-11-21 13:00:00', '2026-11-21 13:00:00'),
    'karl_waiting' => array($b, $ca, 'lb_test_karl', '2026-11-21 13:30:00', null),
    'karl_a' => array($a, $ca, 'lb_test_karl', '2026-11-21 14:00:00', '2026-11-21 14:00:00'),
    ) as $key => $x)
{
  single_insert(COMMENTS_TABLE, array('image_id'=>$x[0], 'date'=>$x[3], 'author'=>$x[2], 'author_id'=>$x[1], 'anonymous_id'=>'203.0.113',
    'content'=>'lb_test_ '.$key, 'validated'=> null === $x[4] ? 'false' : 'true', 'validation_date'=>$x[4]));
  $dc[$key] = (int)pwg_db_insert_id(COMMENTS_TABLE);
}
visitor($bo, '203.0.113.22');
ok('Bob: comments after his first one on photos of others; not his own, not before, not waiting; not on A (his comment there waits)', array(
  "discussion|member|lb_test_karl|$b|unread", "discussion|guest||$b|unread", "discussion|member|lb_test_owner|$c|unread", "discussion|member|lb_test_alice|$b|unread"), fb_sig($bo));
$list = like_bravo_feedback_list($bo)['entries'];
ok('entry: owner of the photo, read key of the comment, time of validation, not edited', array('lb_test_alice', $al, array('kind'=>'comment', 'ref_id'=>$dc['karl_b'], 'rater_id'=>0, 'rater_anonymous_id'=>''), '2026-11-21 13:00:00', false),
  array($list[0]['owner_name'], $list[0]['owner_id'], $list[0]['read_key'], $list[0]['time'], $list[0]['edited']));
$items = like_bravo_feedback_items($list);
$karl_text = sprintf(htmlspecialchars(l10n('%1$s commented on a photo by %2$s that you commented on too:')), '<strong>lb_test_karl</strong>', '<strong>lb_test_alice</strong>');
ok('text: author and owner in bold, the excerpt in italics', $karl_text.' <em>lb_test_ karl_b</em>', $items[0]['html']);
ok('text: a guest', sprintf(htmlspecialchars(l10n('A guest commented on a photo by %2$s that you commented on too:')), '', '<strong>lb_test_alice</strong>').' <em>lb_test_ guest_b</em>', $items[1]['html']);
ok('text: the owner himself, his name once', sprintf(htmlspecialchars(l10n('%1$s commented on their own photo that you commented on too:')), '<strong>lb_test_owner</strong>', '').' <em>lb_test_ owner_c</em>', $items[2]['html']);
ok('item: to the comments, no totals', array('#comments', false, 'discussion'), array(substr($items[0]['url'], -9), isset($items[0]['likes']), $items[0]['kind']));
ok('counts and filters: "Also commented" yes, comments on his photos none', array(4, 4, 4, 0, 0),
  array(like_bravo_feedback_unread_count($bo), like_bravo_feedback_counts($bo, 'discussions')['all'], count(like_bravo_feedback_list($bo, 1, 50, false, 'discussions')['entries']),
    like_bravo_feedback_counts($bo, 'comments')['all'], like_bravo_feedback_counts($bo, 'votes')['all']));
ok('tally and top photos leave "Also commented" out', array(array(), array()), array(like_bravo_feedback_tally($bo)['photos'], like_bravo_feedback_top_photos(like_bravo_feedback_tally($bo))));

ok('mark read: an entry "Also commented"; not a comment before his first one', array(true, false, 3),
  array(like_bravo_feedback_mark_read($bo, 'comment', $dc['guest_b']), like_bravo_feedback_mark_read($bo, 'comment', $dc['karl_before']), like_bravo_feedback_unread_count($bo)));
pwg_query('UPDATE '.LIKE_BRAVO_FEEDBACK_READ_TABLE." SET read_at = '2026-11-22 00:00:00'"); // read the day after
like_bravo_feedback_mark_read($bo, 'comment', $dc['karl_b']);
pwg_query('UPDATE '.LIKE_BRAVO_FEEDBACK_READ_TABLE." SET read_at = '2026-11-22 00:00:00'");
visitor($ca, '203.0.113.24'); edit_comment($dc['karl_b'], $b, 'lb_test_ karl_b edited', ''); visitor($bo, '203.0.113.22');
$edited = like_bravo_feedback_list($bo)['entries'][0];
ok('Karl edits his comment: new text, but the time it was written, still read, not edited', array($dc['karl_b'], 'lb_test_ karl_b edited', '2026-11-21 13:00:00', false, false, true),
  array($edited['comment_id'], $edited['excerpt'], $edited['time'], $edited['unread'], $edited['edited'], comment_edited($dc['karl_b'])));
pwg_query('UPDATE '.COMMENTS_TABLE." SET validated = 'true', validation_date = '2026-11-21 15:00:00' WHERE id = ".$dc['karl_waiting']);
ok('a moderated comment comes once it is validated, at the time of validation', array("discussion|member|lb_test_karl|$b|unread", '2026-11-21 15:00:00'),
  array(fb_sig($bo)[0], like_bravo_feedback_list($bo)['entries'][0]['time']));

// the test photos are also in their own albums of the gallery: those are forbidden by hand
$user = build_user($bo, true);
$user['forbidden_categories'] = implode(',', array_merge(array_filter(explode(',', $user['forbidden_categories'])),
  query2array('SELECT category_id FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id = '.$c, null, 'category_id')));
ok('all albums of C forbidden: C is gone, B stays (in other albums)', array(false, true),
  array(in_array("discussion|member|lb_test_owner|$c|unread", fb_sig($bo), true), in_array("discussion|guest||$b", fb_sig($bo), true)));
pwg_query('UPDATE '.CATEGORIES_TABLE." SET status = 'private' WHERE id = $t4"); invalidate_user_cache();
$user = build_user($webmaster['id'], true);
ok('what another member may see comes from his user cache: T4 private is forbidden to Bob', true, in_array($t4, like_bravo_feedback_viewer($bo)['forbidden']));
pwg_query('UPDATE '.CATEGORIES_TABLE." SET status = 'public' WHERE id = $t4"); invalidate_user_cache(); visitor($bo, '203.0.113.22');
pwg_query('UPDATE '.IMAGES_TABLE." SET level = 4 WHERE id = $b");
ok('photo B private (privacy level 4): its entries are gone', array("discussion|member|lb_test_owner|$c|unread"), fb_sig($bo));
pwg_query('UPDATE '.IMAGES_TABLE.' SET level = '.(int)$snap['level'][$b].' WHERE id = '.$b);

pwg_query('UPDATE '.IMAGES_TABLE." SET added_by = 999999 WHERE id = $b");
$gone = like_bravo_feedback_items(like_bravo_feedback_list($bo)['entries']);
ok('owner of B gone: sentence without owner', sprintf(htmlspecialchars(l10n('%1$s commented on a photo that you commented on too:')), '<strong>lb_test_karl</strong>', '').' <em>lb_test_ karl_waiting</em>', $gone[0]['html']);
pwg_query('UPDATE '.IMAGES_TABLE." SET added_by = $al WHERE id = $b");

$alice_b = array_filter(like_bravo_feedback_list($al, 1, 100)['entries'], function($e) use ($b) { return $e['image_id'] == $b and isset($e['comment_id']); });
ok('Alice, owner of B and commenting there: only comments on her photo, none twice', array(array('comment'), count($alice_b)),
  array(array_values(array_unique(array_column($alice_b, 'kind'))), count(array_unique(array_column($alice_b, 'comment_id')))));

$alice_unread = like_bravo_feedback_unread_count($al);
conf_update_param('like_bravo_discussion_since', '2026-11-21 12:30:00', true);
ok('start of "Also commented": what came before is read; the other feedback stays as it was', array(array("discussion|member|lb_test_karl|$b|unread", "discussion|member|lb_test_karl|$b"), $alice_unread),
  array(array_slice(fb_sig($bo), 0, 2), like_bravo_feedback_unread_count($al)));
ok('... and only the newer ones are unread', 1, like_bravo_feedback_unread_count($bo));
conf_update_param('like_bravo_discussion_since', '2026-01-01 00:00:00', true);
like_bravo_feedback_mark_all_read($bo);
ok('mark all as read: also "Also commented"', 0, like_bravo_feedback_unread_count($bo));
pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_USER_TABLE.' WHERE user_id = '.$bo);

visitor($bo, '203.0.113.22'); @delete_user_comment(array($dc['bob_c']));
ok('Bob deletes one of his comments on C: the entry stays (his comment of section 16 is older)', true, in_array($c, array_column(like_bravo_feedback_list($bo)['entries'], 'image_id')));
@delete_user_comment(array($cm['bob_star']));
ok('Bob deletes his last comment on C: its entries are gone', false, in_array($c, array_column(like_bravo_feedback_list($bo)['entries'], 'image_id')));

// the owner: votes and comments on his photos, "Also commented" on B
$sig = fb_sig($own, 1, 100);
ok('owner: all three kinds in one list', array('bravo', 'comment', 'discussion', 'like'),
  array_values(array_intersect(array('bravo', 'comment', 'discussion', 'like'), array_unique(array_map(function($e) { return explode('|', $e)[0]; }, $sig)))));
$walk = array(); $cursor = null;
do {
  $chunk = like_bravo_feedback_list($own, 1, 3, false, 'all', $cursor);
  foreach ($chunk['entries'] as $e) $walk[] = $e['kind'].'|'.$e['who'].'|'.$e['name'].'|'.$e['image_id'].($e['unread'] ? '|unread' : '');
  $last = end($chunk['entries']);
  $cursor = $last ? like_bravo_feedback_cursor(like_bravo_feedback_cursor_text($last['time'], $last['read_key'])) : null;
} while ($chunk['has_more'] and count($walk) < 200);
ok('cursor over all three kinds, three at a time: the whole list, nothing twice', $sig, $walk);
ok('owner filters: comments only on his photos, "Also commented" apart', array(array('comment'), array('discussion')),
  array(array_values(array_unique(array_column(like_bravo_feedback_list($own, 1, 100, false, 'comments')['entries'], 'kind'))),
    array_values(array_unique(array_column(like_bravo_feedback_list($own, 1, 100, false, 'discussions')['entries'], 'kind')))));
$tally = like_bravo_feedback_tally($own);
ok('owner tally: comments on his photos only', count(like_bravo_feedback_list($own, 1, 100, false, 'comments')['entries']), array_sum(array_column($tally['photos'], 'comments')));
ok('owner counts: all = votes + comments + "Also commented"', like_bravo_feedback_counts($own)['all'],
  like_bravo_feedback_counts($own, 'votes')['all'] + like_bravo_feedback_counts($own, 'comments')['all'] + like_bravo_feedback_counts($own, 'discussions')['all']);
$_SESSION = array();

$conf['rate_anonymous'] = $rate_anonymous_before;
mode($t1, null);

restore_snapshot();
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";

function restore_snapshot() {
  global $snap, $conf; static $done = false; if ($done) return; $done = true;
  if (!count(query2array("SELECT id FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo' AND state = 'active'"))) (new plugins())->perform_action('activate', 'like_bravo');
  $ids = query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'", null, 'id');
  if ($ids) delete_categories($ids);
  foreach (query2array("SELECT id FROM ".USERS_TABLE." WHERE username LIKE 'lb_test_%'", null, 'id') as $id) delete_user($id);
  pwg_query('DELETE FROM '.COMMENTS_TABLE." WHERE content LIKE 'lb_test_%'");
  if (!empty($snap['added_by'])) foreach ($snap['added_by'] as $id => $by) pwg_query('UPDATE '.IMAGES_TABLE.' SET added_by = '.(int)$by.' WHERE id = '.(int)$id);
  if (!empty($snap['level'])) foreach ($snap['level'] as $id => $level) pwg_query('UPDATE '.IMAGES_TABLE.' SET level = '.(int)$level.' WHERE id = '.(int)$id);
  // core data first, then the plugin's tables; each step on its own, so that one failure
  // does not keep the others from running; insert_row(): mass_inserts() turns '' into NULL
  $steps = array(
    'rates' => function() use ($snap) { pwg_query('DELETE FROM '.RATE_TABLE); foreach ($snap['rates'] as $row) insert_row(RATE_TABLE, $row); },
    'modes' => function() use ($snap) { pwg_query('DELETE FROM '.LIKE_BRAVO_MODES_TABLE); foreach ($snap['modes'] as $row) insert_row(LIKE_BRAVO_MODES_TABLE, $row); },
    'settings' => function() use ($snap) { conf_update_param('like_bravo', $snap['conf'], true); conf_update_param('like_bravo_feedback_since', $snap['since'], true);
      if (null === $snap['discussion_since']) conf_delete_param('like_bravo_discussion_since'); else conf_update_param('like_bravo_discussion_since', $snap['discussion_since'], true); },
    'feedback' => function() use ($snap) { foreach ($snap['feedback'] as $table => $rows) { pwg_query('DELETE FROM '.$table); foreach ($rows as $row) insert_row($table, $row); } },
    );
  foreach ($steps as $name => $step) {
    try { $step(); }
    catch (Throwable $e) { echo "\nRESTORE FAILED ($name): ".$e->getMessage()."\n  data kept in /tmp/like_bravo_scenarios_snapshot.json\n"; return; }
  }
  @unlink('/tmp/like_bravo_scenarios_snapshot.json');
  like_bravo_get_explicit_modes(true);
  remove_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  add_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  update_rating_score();
  echo "\nrestored: ".count(query2array('SELECT * FROM '.RATE_TABLE))." rates, ".count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))." modes, test albums left: "
    .count(query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'")).", test users left: "
    .count(query2array("SELECT id FROM ".USERS_TABLE." WHERE username LIKE 'lb_test_%'")).", test comments left: "
    .count(query2array("SELECT id FROM ".COMMENTS_TABLE." WHERE content LIKE 'lb_test_%'")).", plugin: "
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
function feedback_tables() { return array(LIKE_BRAVO_VOTE_TIME_TABLE, LIKE_BRAVO_FEEDBACK_USER_TABLE, LIKE_BRAVO_FEEDBACK_READ_TABLE, LIKE_BRAVO_COMMENT_EDIT_TABLE); }
function rate_keys($img) { $k = array(); foreach (query2array('SELECT user_id u, anonymous_id a FROM '.RATE_TABLE.' WHERE element_id = '.(int)$img) as $x) $k[] = $x['u'].'|'.$x['a']; sort($k); return $k; }
function vote_times($img) { $rows = array(); // 'key' => "user|identifier", 'row' => "user|identifier|time", 't' => time
  foreach (query2array('SELECT user_id u, anonymous_id a, changed_at t FROM '.LIKE_BRAVO_VOTE_TIME_TABLE.' WHERE image_id = '.(int)$img.' ORDER BY user_id, anonymous_id') as $x)
    $rows[] = array('key'=>$x['u'].'|'.$x['a'], 'row'=>$x['u'].'|'.$x['a'].'|'.$x['t'], 't'=>$x['t']);
  usort($rows, function($p, $q) { return strcmp($p['key'], $q['key']); }); return $rows; }
function read_marks($kind, $ref) { return count(query2array('SELECT * FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE." WHERE kind = '".$kind."' AND ref_id = ".(int)$ref)); }
function edit_comment($cid, $img, $content, $website) { // update_user_comment() as on the photo page, with a valid form key
  $key = get_ephemeral_key(0, $img); usleep(200000);
  return @update_user_comment(array('comment_id'=>$cid, 'image_id'=>$img, 'content'=>$content, 'website_url'=>$website), $key); }
function comment_edited($cid) { $r = query2array('SELECT e.edited_at, c.validation_date FROM '.COMMENTS_TABLE.' c LEFT JOIN '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' e ON e.comment_id = c.id WHERE c.id = '.(int)$cid);
  return like_bravo_comment_is_edited($r[0]['edited_at'], $r[0]['validation_date']); }
function insert_row($table, $row) { // single_insert() would store '' as NULL
  pwg_query('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).") VALUES ('".implode("', '", array_map('pwg_db_real_escape_string', $row))."')"); }
function fb_sig($owner, $page = 1, $per_page = 20, $unread = false, $kind = 'all', $before = null) { // "kind|who|name|photo[|unread]" of each entry
  return array_map(function($e) { return $e['kind'].'|'.$e['who'].'|'.$e['name'].'|'.$e['image_id'].($e['unread'] ? '|unread' : ''); },
    like_bravo_feedback_list($owner, $page, $per_page, $unread, $kind, $before)['entries']); }
function ws_feedback($method, $params = array(), $token = null) { // feedback methods as the current visitor, with the defaults of the service
  $defaults = array('list' => array('page'=>1, 'per_page'=>20, 'unread'=>false, 'kind'=>'all', 'before'=>''), 'read' => array('rater_id'=>0, 'rater_anonymous_id'=>''), 'all' => array());
  $functions = array('list' => 'like_bravo_ws_feedback_list', 'read' => 'like_bravo_ws_feedback_mark_read', 'all' => 'like_bravo_ws_feedback_mark_all_read');
  $params = array_merge($defaults[$method], $params, array('pwg_token' => null === $token ? get_pwg_token() : $token)); $service = null;
  $r = @$functions[$method]($params, $service); // @: PwgError sets an HTTP status, impossible on the command line
  return $r instanceof PwgError ? 'error '.$r->code() : $r; }
