<?php
/**
 * Tests for the pure business rules. Needs no Piwigo and no database.
 *
 *   php tests/run.php
 */

if (PHP_SAPI !== 'cli')
{
  die('Hacking attempt!');
}

define('PHPWG_ROOT_PATH', './');
require dirname(__DIR__) . '/include/functions_logic.inc.php';

$failures = 0;
$checks = 0;

function check($label, $expected, $actual)
{
  global $failures, $checks;

  $checks++;
  if ($expected !== $actual)
  {
    $failures++;
    echo 'FAIL  ' . $label . "\n"
      . '      expected ' . var_export($expected, true) . "\n"
      . '      actual   ' . var_export($actual, true) . "\n";
  }
}

const STARS = LIKE_BRAVO_MODE_STARS;
const OFF = LIKE_BRAVO_MODE_OFF;
const LIKE = LIKE_BRAVO_MODE_LIKE;
const BRAVO = LIKE_BRAVO_MODE_BOTH;

// --- album mode inheritance -------------------------------------------------
// tree: 1 > 5 > 12 > 30
$explicit = array(1 => LIKE, 12 => BRAVO);

check('own explicit mode wins',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12', $explicit));
check('inherits from nearest parent with a mode',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12,30', $explicit));
check('skips parents without a mode',
  array('mode' => LIKE, 'source' => 1),
  like_bravo_resolve_mode('1,5', $explicit));
check('root album with explicit mode',
  array('mode' => LIKE, 'source' => 1),
  like_bravo_resolve_mode('1', $explicit));
check('nothing set anywhere is stars',
  array('mode' => STARS, 'source' => null),
  like_bravo_resolve_mode('2,7', $explicit));
check('explicit stars stop inheritance',
  array('mode' => STARS, 'source' => 5),
  like_bravo_resolve_mode('1,5,40', array(1 => BRAVO, 5 => STARS)));
check('child can re-enable below a stars album',
  array('mode' => LIKE, 'source' => 40),
  like_bravo_resolve_mode('1,5,40', array(1 => BRAVO, 5 => STARS, 40 => LIKE)));
check('modes read as strings from the database',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12', array('12' => '2')));
check('empty uppercats',
  array('mode' => STARS, 'source' => null),
  like_bravo_resolve_mode('', $explicit));
check('album id 10 does not match id 1',
  array('mode' => STARS, 'source' => null),
  like_bravo_resolve_mode('10', array(1 => LIKE)));

// --- mode of a photo --------------------------------------------------------
// resolved modes: with a setting (source = album id) or the default (source null)
function set_($mode) { return array('mode' => $mode, 'source' => 1); }
$default = array('mode' => STARS, 'source' => null);

check('photo without album is stars', STARS, like_bravo_combine_modes(array()));
check('single album', LIKE, like_bravo_combine_modes(array(set_(LIKE))));
check('like + bravo beats like and stars', BRAVO, like_bravo_combine_modes(array(set_(STARS), set_(BRAVO), set_(LIKE))));
check('like beats stars', LIKE, like_bravo_combine_modes(array(set_(STARS), set_(LIKE))));
check('like beats the default', LIKE, like_bravo_combine_modes(array($default, set_(LIKE))));
check('only default albums: stars', STARS, like_bravo_combine_modes(array($default, $default)));
check('single album without rating', OFF, like_bravo_combine_modes(array(set_(OFF))));
check('no rating beats the default', OFF, like_bravo_combine_modes(array($default, set_(OFF))));
check('no rating beats the default, any order', OFF, like_bravo_combine_modes(array(set_(OFF), $default)));
check('explicit stars beat no rating', STARS, like_bravo_combine_modes(array(set_(OFF), set_(STARS))));
check('like beats no rating', LIKE, like_bravo_combine_modes(array(set_(OFF), set_(LIKE))));
check('like + bravo beats no rating', BRAVO, like_bravo_combine_modes(array(set_(BRAVO), set_(OFF), $default)));
check('mode as string from the database', OFF, like_bravo_combine_modes(array($default, array('mode' => '3', 'source' => 4))));
check('rank: default < off < stars < like < like + bravo',
  array(0, 1, 2, 3, 4),
  array_map('like_bravo_mode_rank', array($default, set_(OFF), set_(STARS), set_(LIKE), set_(BRAVO))));
check('no rating is a valid mode', true, in_array(OFF, like_bravo_valid_modes(), true));
check('thumbs modes', array(false, false, true, true),
  array_map('like_bravo_is_thumbs_mode', array(STARS, OFF, LIKE, BRAVO)));
check('no rating stops inheritance',
  array('mode' => OFF, 'source' => 5),
  like_bravo_resolve_mode('1,5,40', array(1 => BRAVO, 5 => OFF)));

// --- how stored rates count -------------------------------------------------
foreach (array(0, 1) as $rate)
{
  check("rate $rate is dormant in like + bravo", null, like_bravo_classify_rate($rate, BRAVO));
  check("rate $rate is dormant in like", null, like_bravo_classify_rate($rate, LIKE));
}
check('2 is a like in like + bravo', 'like', like_bravo_classify_rate(2, BRAVO));
check('3 is a like in like + bravo', 'like', like_bravo_classify_rate(3, BRAVO));
check('4 is a bravo in like + bravo', 'bravo', like_bravo_classify_rate(4, BRAVO));
check('5 is a bravo in like + bravo', 'bravo', like_bravo_classify_rate(5, BRAVO));
foreach (array(2, 3, 4, 5) as $rate)
{
  check("$rate is a like in like", 'like', like_bravo_classify_rate($rate, LIKE));
}
check('nothing counts in stars', null, like_bravo_classify_rate(5, STARS));
check('nothing counts without rating', null, like_bravo_classify_rate(4, OFF));
check('counts without rating',
  array('likes' => 0, 'bravos' => 0, 'dormant' => 3),
  like_bravo_count_votes(array(2, 4, 5), OFF));
check('rate as string from the database', 'bravo', like_bravo_classify_rate('5', BRAVO));

check('counts in like + bravo',
  array('likes' => 3, 'bravos' => 3, 'dormant' => 2),
  like_bravo_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), BRAVO));
check('counts in like',
  array('likes' => 6, 'bravos' => 0, 'dormant' => 2),
  like_bravo_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), LIKE));
check('no votes',
  array('likes' => 0, 'bravos' => 0, 'dormant' => 0),
  like_bravo_count_votes(array(), BRAVO));

// --- score ------------------------------------------------------------------
check('a like is 2 points', 2, like_bravo_points(1, 0));
check('a bravo is 4 points', 4, like_bravo_points(0, 1));
check('3 likes and 2 bravos', 14, like_bravo_points(3, 2));
check('no votes, no points', 0, like_bravo_points(0, 0));

check('no points give no score', null, like_bravo_score(0, 10));
check('average points give half of the maximum', 2.5, like_bravo_score(10, 10));
check('half of the average', 1.67, like_bravo_score(5, 10));      // 25/15
check('twice the average', 3.33, like_bravo_score(20, 10));       // 100/30
check('four times the average', 4.0, like_bravo_score(40, 10));   // 200/50
check('same ratio, same score in a larger gallery', like_bravo_score(20, 10), like_bravo_score(600, 300));

$previous = 0;
$monotonic = true;
for ($points = 1; $points <= 1000; $points++)
{
  $score = like_bravo_score($points, 10);
  if ($score < $previous or $score >= 5)
  {
    $monotonic = false;
  }
  $previous = $score;
}
check('score never decreases and stays below 5', true, $monotonic);

// --- vote levels ------------------------------------------------------------
check('levels in stars', array(), like_bravo_allowed_levels(STARS));
check('levels without rating', array(), like_bravo_allowed_levels(OFF));
check('levels in like', array('none', 'like'), like_bravo_allowed_levels(LIKE));
check('levels in like + bravo', array('none', 'like', 'bravo'), like_bravo_allowed_levels(BRAVO));
check('like is stored as 2', 2, like_bravo_level_to_rate('like'));
check('bravo is stored as 4', 4, like_bravo_level_to_rate('bravo'));
check('none is not stored', null, like_bravo_level_to_rate('none'));
check('unknown level is not stored', null, like_bravo_level_to_rate('dislike'));
check('a stored like reads back as like',
  'like', like_bravo_classify_rate(like_bravo_level_to_rate('like'), BRAVO));
check('a stored bravo reads back as bravo',
  'bravo', like_bravo_classify_rate(like_bravo_level_to_rate('bravo'), BRAVO));

// --- configuration ----------------------------------------------------------
$defaults = like_bravo_default_conf();
check('missing configuration gives defaults', $defaults, like_bravo_normalize_conf(false));
check('stored values are kept',
  'smile', like_bravo_normalize_conf(array('icon_like' => 'smile'))['icon_like']);
check('missing keys are filled',
  $defaults['icon_bravo'], like_bravo_normalize_conf(array('icon_like' => 'smile'))['icon_bravo']);
check('default icons: heart for like, trophy for bravo',
  array('heart', 'trophy'), array($defaults['icon_like'], $defaults['icon_bravo']));
check('clapping hands can be chosen',
  'clap', like_bravo_normalize_conf(array('icon_bravo' => 'clap'))['icon_bravo']);
check('unknown keys are dropped',
  false, array_key_exists('foo', like_bravo_normalize_conf(array('foo' => 'bar'))));
check('score constant of earlier versions is dropped',
  false, array_key_exists('score_constant', like_bravo_normalize_conf(array('score_constant' => 7))));
check('normalizing twice changes nothing',
  like_bravo_normalize_conf(array('icon_like' => 'heart', 'allow_guests' => 1)),
  like_bravo_normalize_conf(like_bravo_normalize_conf(array('icon_like' => 'heart', 'allow_guests' => 1))));

check('unknown like icon falls back',
  $defaults['icon_like'], like_bravo_normalize_conf(array('icon_like' => '../x'))['icon_like']);
check('undo icon is not a vote icon',
  $defaults['icon_bravo'], like_bravo_normalize_conf(array('icon_bravo' => 'ban'))['icon_bravo']);
check('valid icons are kept',
  array('smile', 'medal', 'cross'),
  array_values(array_intersect_key(
    like_bravo_normalize_conf(array('icon_like' => 'smile', 'icon_bravo' => 'medal', 'icon_undo' => 'cross')),
    array('icon_like' => 1, 'icon_bravo' => 1, 'icon_undo' => 1))));
check('label settings of earlier versions are dropped',
  false, isset(like_bravo_normalize_conf(array('label_bravo' => 'Super'))['label_bravo']));
check('default icons are selectable', true,
  in_array($defaults['icon_like'], like_bravo_vote_icon_names())
  and in_array($defaults['icon_bravo'], like_bravo_vote_icon_names())
  and in_array($defaults['icon_undo'], like_bravo_undo_icon_names()));

check('guests are not allowed by default', false, $defaults['allow_guests']);
check('guest option is stored as a boolean',
  true, like_bravo_normalize_conf(array('allow_guests' => '1'))['allow_guests']);

// --- anonymous visitors -----------------------------------------------------
check('guests take part: plugin option and Piwigo option', true, like_bravo_guests_take_part(true, true));
check('guests do not take part without the plugin option', false, like_bravo_guests_take_part(false, true));
check('guests do not take part without "Rating by guests"', false, like_bravo_guests_take_part(true, false));
check('guests do not take part with both options off', false, like_bravo_guests_take_part(false, false));

check('IPv4: last part is dropped', '203.0.113', like_bravo_anonymous_id('203.0.113.7'));
check('IPv4: same /24 network, same identifier',
  like_bravo_anonymous_id('203.0.113.7'), like_bravo_anonymous_id('203.0.113.250'));
check('IPv4: other network, other identifier',
  false, like_bravo_anonymous_id('203.0.113.7') == like_bravo_anonymous_id('203.0.114.7'));
check('IPv6: whole address', '2001:db8::1', like_bravo_anonymous_id('2001:db8::1'));
check('IPv6: neighbours are different visitors',
  false, like_bravo_anonymous_id('2001:db8::1') == like_bravo_anonymous_id('2001:db8::2'));
check('IPv4 in IPv6: last part is dropped', '::ffff:1.2.3', like_bravo_anonymous_id('::ffff:1.2.3.4'));
check('missing address', '', like_bravo_anonymous_id(null));

// --- scores of the whole gallery --------------------------------------------
function rows($element_id, $rates, $is_guest = 0)
{
  $rows = array();
  foreach (array_count_values($rates) as $rate => $n)
  {
    $rows[] = array('element_id' => (string)$element_id, 'rate' => (string)$rate, 'is_guest' => (string)$is_guest, 'n' => (string)$n);
  }
  return $rows;
}

// photos 1 and 2 are star photos: m = 4, C = 4
$star_rows = array_merge(rows(1, array(5, 5, 5, 5)), rows(2, array(3, 3, 3, 3)));
$star_only = like_bravo_compute_scores($star_rows, array());
check('star photo: core bayesian score, high',
  array('score' => 4.5, 'average' => 5.0, 'count' => 4), $star_only[1]);
check('star photo: core bayesian score, low',
  array('score' => 3.5, 'average' => 3.0, 'count' => 4), $star_only[2]);

// same result as the Piwigo core formula, computed independently
$core = function($by_item) {
  $count = 0; $sum = 0;
  foreach ($by_item as $rates) { $count += count($rates); $sum += array_sum($rates); }
  $m = $sum / $count; $c = $count / count($by_item);
  $scores = array();
  foreach ($by_item as $id => $rates) { $scores[$id] = round(($c * $m + array_sum($rates)) / ($c + count($rates)), 2); }
  return $scores;
};
$sample = array(11 => array(0, 5, 4), 12 => array(2), 13 => array(5, 5, 4, 4, 3, 1), 14 => array(3, 3));
$sample_rows = array();
foreach ($sample as $id => $rates) { $sample_rows = array_merge($sample_rows, rows($id, $rates)); }
check('star scores equal the core formula',
  $core($sample), array_map(function($r) { return $r['score']; }, like_bravo_compute_scores($sample_rows, array())));

// photos 3, 4 and 5 are thumbs photos with 2, 8 and 20 points: average 10
$thumbs_rows = array_merge(
  rows(3, array(2)),                         // a new like
  rows(4, array(3, 3, 5, 0, 1)),             // older star rates: two likes, a bravo, two dormant
  rows(5, array(4, 4, 4, 4, 4)));
$thumbs_modes = array(3 => BRAVO, 4 => BRAVO, 5 => BRAVO);
$mixed = like_bravo_compute_scores(array_merge($star_rows, $thumbs_rows), $thumbs_modes);
check('thumbs votes do not move star scores',
  array($star_only[1], $star_only[2]), array($mixed[1], $mixed[2]));
check('thumbs photo below the average',
  array('score' => 0.83, 'average' => null, 'count' => 1, 'likes' => 1, 'bravos' => 0), $mixed[3]);   // 10/12
check('thumbs photo near the average, dormant rates left out',
  array('score' => 2.22, 'average' => null, 'count' => 3, 'likes' => 2, 'bravos' => 1), $mixed[4]);   // 40/18
check('thumbs photo with twice the average',
  array('score' => 3.33, 'average' => null, 'count' => 5, 'likes' => 0, 'bravos' => 5), $mixed[5]);   // 100/30
check('star votes do not move thumbs scores',
  array($mixed[3], $mixed[4], $mixed[5]),
  array_values(like_bravo_compute_scores($thumbs_rows, $thumbs_modes)));

// ten times as many voters: same scores
$large_rows = array();
foreach ($thumbs_rows as $row)
{
  $row['n'] = (string)(10 * $row['n']);
  $large_rows[] = $row;
}
check('scores do not depend on the size of the gallery',
  array(0.83, 2.22, 3.33),
  array_values(array_column(like_bravo_compute_scores($large_rows, $thumbs_modes), 'score')));

check('same votes in like mode count as likes only',
  array('score' => 2.5, 'average' => null, 'count' => 3, 'likes' => 3, 'bravos' => 0),              // P 2, 6, 10: average 6
  like_bravo_compute_scores($thumbs_rows, array(3 => LIKE, 4 => LIKE, 5 => LIKE))[4]);
$with_unrated = like_bravo_compute_scores(array_merge($thumbs_rows, rows(6, array(0, 1))), $thumbs_modes + array(6 => BRAVO));
check('thumbs photo with dormant votes only has no score',
  array('score' => null, 'average' => null, 'count' => 0, 'likes' => 0, 'bravos' => 0), $with_unrated[6]);
check('and does not lower the average',
  array($mixed[3], $mixed[4], $mixed[5]), array($with_unrated[3], $with_unrated[4], $with_unrated[5]));

// photo 7: one member like, guests: two bravos, a like and a dormant rate; photo 8: one member bravo
$guest_rows = array_merge(rows(7, array(2)), rows(7, array(4, 4, 2, 1), 1), rows(8, array(4)));
$guest_modes = array(7 => BRAVO, 8 => BRAVO);
check('guest rates do not count on thumbs photos',
  array('score' => 2.0, 'average' => null, 'count' => 1, 'likes' => 1, 'bravos' => 0),               // P 2, average 3: 10/5
  like_bravo_compute_scores($guest_rows, $guest_modes)[7]);
check('guest rates count on thumbs photos if guests take part',
  array('score' => 3.0, 'average' => null, 'count' => 4, 'likes' => 2, 'bravos' => 2),               // P 12, average 8: 60/20
  like_bravo_compute_scores($guest_rows, $guest_modes, true)[7]);
check('guest rates still count on star photos',
  5, like_bravo_compute_scores($guest_rows, array())[7]['count']);
check('switching a photo to thumbs and back loses nothing',
  $star_only, like_bravo_compute_scores($star_rows, array(1 => STARS)));
check('no rates at all', array(), like_bravo_compute_scores(array(), array(1 => BRAVO)));
check('a single rated thumbs photo is its own average',
  2.5, like_bravo_compute_scores(rows(9, array(2, 2, 2, 4)), array(9 => BRAVO))[9]['score']);

// photo 20 without rating: star rates and likes / bravos are kept but count nowhere
$off_rows = array_merge($star_rows, $thumbs_rows, rows(20, array(5, 5, 4, 2, 0)));
$off_scores = like_bravo_compute_scores($off_rows, $thumbs_modes + array(20 => OFF));
check('photo without rating has no score', false, isset($off_scores[20]));
check('photo without rating does not move star scores',
  array($star_only[1], $star_only[2]), array($off_scores[1], $off_scores[2]));
check('photo without rating does not move thumbs scores',
  array($mixed[3], $mixed[4], $mixed[5]), array($off_scores[3], $off_scores[4], $off_scores[5]));
check('photo without rating: guest rates do not count either',
  false, isset(like_bravo_compute_scores(rows(20, array(4), 1), array(20 => OFF), true)[20]));
check('all photos without rating: no scores at all',
  array(), like_bravo_compute_scores($star_rows, array(1 => OFF, 2 => OFF)));

// --- names of voters --------------------------------------------------------
check('names: default is members only', 'members', $defaults['show_names']);
check('names: default short form has 3 names', 3, $defaults['names_limit']);
check('names: unknown audience falls back', 'members', like_bravo_normalize_conf(array('show_names' => 'everybody'))['show_names']);
check('names: valid audience is kept', 'all', like_bravo_normalize_conf(array('show_names' => 'all'))['show_names']);
check('names: limit from a form field', 5, like_bravo_normalize_conf(array('names_limit' => '5'))['names_limit']);
check('names: limit 0 falls back', 3, like_bravo_normalize_conf(array('names_limit' => 0))['names_limit']);
check('names: limit 11 falls back', 3, like_bravo_normalize_conf(array('names_limit' => 11))['names_limit']);
check('names: non-numeric limit falls back', 3, like_bravo_normalize_conf(array('names_limit' => 'x'))['names_limit']);

check('names off: member sees none', false, like_bravo_may_see_names('off', false));
check('names off: guest sees none', false, like_bravo_may_see_names('off', true));
check('names for members: member sees them', true, like_bravo_may_see_names('members', false));
check('names for members: guest sees none', false, like_bravo_may_see_names('members', true));
check('names for all: member sees them', true, like_bravo_may_see_names('all', false));
check('names for all: guest sees them', true, like_bravo_may_see_names('all', true));
check('names: unknown setting shows none', false, like_bravo_may_see_names('', false));

function voter($user_id, $name, $date, $rate, $is_guest = 0)
{
  return array('user_id' => (string)$user_id, 'name' => $name, 'date' => $date, 'rate' => (string)$rate, 'is_guest' => (string)$is_guest);
}
function names($group)
{
  return array_column($group['voters'], 'name');
}

$voter_rows = array(
  voter(1, 'Anna', '2026-11-01', 2),
  voter(2, 'martin', '2026-11-10', 3),
  voter(3, 'Jürgen', '2026-11-10', 2),
  voter(4, 'Thomas', '2026-11-05', 4),
  voter(5, 'Zoe', '2026-11-12', 5),
  voter(6, 'Carl', '2026-11-12', 1),        // dormant
  voter(7, null, '2026-11-11', 2),          // account is gone
  voter(9, 'guest', '2026-11-12', 2, 1),
  voter(9, 'guest', '2026-11-12', 4, 1),
  voter(9, 'guest', '2026-11-12', 4, 1),
  );
$groups = like_bravo_group_voters($voter_rows, BRAVO, 1, true);
check('voters: own vote first, then newest, same day by name',
  array('Anna', 'Jürgen', 'martin'), names($groups['like']));
check('voters: own vote is marked',
  array(true, false, false), array_column($groups['like']['voters'], 'own'));
check('voters: bravos newest first', array('Zoe', 'Thomas'), names($groups['bravo']));
check('voters: guests and lost accounts are numbers',
  array(1, 1, 0, 2),
  array($groups['like']['unnamed'], $groups['like']['guests'], $groups['bravo']['unnamed'], $groups['bravo']['guests']));
check('voters: dormant votes are left out',
  false, in_array('Carl', array_merge(names($groups['like']), names($groups['bravo']))));

$groups = like_bravo_group_voters($voter_rows, BRAVO, null, false);
check('voters: anonymous visitor has no own vote, newest first',
  array('Jürgen', 'martin', 'Anna'), names($groups['like']));
check('voters: guests left out while they do not take part',
  array(0, 0), array($groups['like']['guests'], $groups['bravo']['guests']));

$groups = like_bravo_group_voters($voter_rows, LIKE, 4, true);
check('voters in like mode: everybody is in the like group, own first',
  array('Thomas', 'Zoe', 'Jürgen', 'martin', 'Anna'), names($groups['like']));
check('voters in like mode: no bravo group, guests added up',
  array(array(), 3), array($groups['bravo']['voters'], $groups['like']['guests']));
check('voters: no votes',
  array('voters' => array(), 'unnamed' => 0, 'guests' => 0),
  like_bravo_group_voters(array(), BRAVO, 1, true)['like']);

$summary = like_bravo_summarize_voters($groups['like'], 3);
check('short form: first names', array('Thomas', 'Zoe', 'Jürgen'), names($summary));
check('short form: others include lost accounts, guests apart', array(3, 3), array($summary['others'], $summary['guests']));
$summary = like_bravo_summarize_voters($groups['like'], 10);
check('short form: limit above the number of voters', array(5, 1), array(count($summary['voters']), $summary['others']));
check('short form: limit is at least 1', 1, count(like_bravo_summarize_voters($groups['like'], 0)['voters']));

check('list of none', '', like_bravo_join_list(array(), '%s and %s'));
check('list of one', 'a', like_bravo_join_list(array('a'), '%s and %s'));
check('list of two', 'a and b', like_bravo_join_list(array('a', 'b'), '%s and %s'));
check('list of four', 'a, b, c und d', like_bravo_join_list(array('a', 'b', 'c', 'd'), '%s und %s'));

// --- personal pages ---------------------------------------------------------
check('pages: none without any thumbs album', array(), like_bravo_sections(array()));
check('pages: none if every explicit mode is stars', array(), like_bravo_sections(array(4 => STARS)));
check('pages: likes only with a like album', array('my_likes' => 'like'), like_bravo_sections(array(4 => LIKE, 5 => STARS)));
check('pages: likes and bravos with a like + bravo album',
  array('my_likes' => 'like', 'my_bravos' => 'bravo'), like_bravo_sections(array(4 => STARS, 7 => BRAVO)));
check('pages: none if every explicit mode is no rating', array(), like_bravo_sections(array(4 => OFF, 5 => STARS)));
check('pages: modes read as strings from the database',
  array('my_likes' => 'like', 'my_bravos' => 'bravo'), like_bravo_sections(array('7' => '2')));

// photo 1 like + bravo, photo 2 like, photo 3 like + bravo, photo 4 star photo, photo 5 like + bravo
$my_rates = array(
  array('element_id' => '5', 'rate' => '4'),
  array('element_id' => '4', 'rate' => '5'),
  array('element_id' => '3', 'rate' => '1'),
  array('element_id' => '2', 'rate' => '5'),
  array('element_id' => '1', 'rate' => '2'),
  );
$my_modes = array(1 => BRAVO, 2 => LIKE, 3 => BRAVO, 5 => BRAVO);
check('my likes: order kept, bravo in a like album is a like, no star photo, no dormant vote',
  array(2, 1), like_bravo_filter_voted($my_rates, $my_modes, 'like'));
check('my bravos: a bravo is not on the like page', array(5), like_bravo_filter_voted($my_rates, $my_modes, 'bravo'));
check('my likes: nothing without thumbs photos', array(), like_bravo_filter_voted($my_rates, array(), 'like'));
check('my likes: no rates', array(), like_bravo_filter_voted(array(), $my_modes, 'like'));
check('my likes and bravos: no photo without rating',
  array(array(2), array()),
  array(like_bravo_filter_voted($my_rates, array(1 => OFF, 2 => LIKE, 5 => OFF), 'like'),
        like_bravo_filter_voted($my_rates, array(1 => OFF, 2 => LIKE, 5 => OFF), 'bravo')));

// --- place of the widget ----------------------------------------------------
check('position: default below the photo', 'photo', $defaults['position']);
check('position: unknown value falls back', 'photo', like_bravo_normalize_conf(array('position' => 'top'))['position']);
check('position: info is kept', 'info', like_bravo_normalize_conf(array('position' => 'info'))['position']);
check('below the photo in Bootstrap Darkroom', true, like_bravo_widget_below_photo('photo', 'bootstrap_darkroom'));
check('not below the photo with the option info', false, like_bravo_widget_below_photo('info', 'bootstrap_darkroom'));
check('not below the photo in other themes',
  array(false, false, false, false),
  array_map(function($theme) { return like_bravo_widget_below_photo('photo', $theme); }, array('default', 'elegant', 'modus', 'smartpocket')));

// --- likes and bravos of the thumbnails ------------------------------------
check('thumbnails: shown by default', true, $defaults['thumbnails']);
check('thumbnails: switched off is kept', false, like_bravo_normalize_conf(array('thumbnails' => '')) ['thumbnails']);

function rate_row($id, $rate, $user, $anonymous='', $guest=false)
{
  return array('element_id' => (string)$id, 'rate' => (string)$rate, 'user_id' => (string)$user,
    'anonymous_id' => $anonymous, 'is_guest' => $guest ? '1' : '0');
}
$thumb_rows = array(
  rate_row(1, 2, 7), rate_row(1, 4, 8), rate_row(1, 5, 9), rate_row(1, 1, 10),
  rate_row(2, 4, 7), rate_row(2, 2, 8),
  rate_row(3, 4, 7),
  rate_row(4, 2, 2, '10.0.0', true), rate_row(4, 4, 2, '10.0.1', true),
  rate_row(9, 4, 7),
  );
$thumb_modes = array(1 => BRAVO, 2 => LIKE, 3 => STARS, 4 => BRAVO, 5 => OFF, 6 => LIKE);
$thumb_votes = like_bravo_thumbnail_votes($thumb_rows, $thumb_modes, 7, null, false);
check('thumbnails: only thumbs photos, also without votes', array(1, 2, 4, 6), array_keys($thumb_votes));
check('thumbnails: counts and own like, dormant rate left out',
  array('likes' => 1, 'bravos' => 2, 'level' => 'like'), $thumb_votes[1]);
check('thumbnails: bravo in a like album is a like, own vote',
  array('likes' => 2, 'bravos' => 0, 'level' => 'like'), $thumb_votes[2]);
check('thumbnails: guest votes do not count without guests',
  array('likes' => 0, 'bravos' => 0, 'level' => 'none'), $thumb_votes[4]);
check('thumbnails: photo without votes',
  array('likes' => 0, 'bravos' => 0, 'level' => 'none'), $thumb_votes[6]);
check('thumbnails: no own vote for a visitor who cannot vote',
  'none', like_bravo_thumbnail_votes($thumb_rows, $thumb_modes, null, null, false)[1]['level']);
$guest_votes = like_bravo_thumbnail_votes($thumb_rows, $thumb_modes, 2, '10.0.1', true);
check('thumbnails: guests counted, own vote by address',
  array('likes' => 1, 'bravos' => 1, 'level' => 'bravo'), $guest_votes[4]);
check('thumbnails: nothing without thumbs photos', array(), like_bravo_thumbnail_votes($thumb_rows, array(3 => STARS), 7, null, false));

$slot = '{if isset($thumbnail.LIKE_BRAVO)}{$thumbnail.LIKE_BRAVO}{/if}';
$icon = "{if !empty(\$thumbnail.icon_ts)}\n<img src=\"recent.png\">\n{/if}";
check('prefilter: right after the new icon',
  "<a>{\$thumbnail.NAME}</a>\n".$icon.$slot."\n{if isset(\$thumbnail.NB_HITS)}x{/if}",
  like_bravo_thumbnails_prefilter("<a>{\$thumbnail.NAME}</a>\n".$icon."\n{if isset(\$thumbnail.NB_HITS)}x{/if}"));
check('prefilter: same condition in an attribute is left alone',
  '<a class="x{if !empty($thumbnail.icon_ts)} recent{/if}">n</a>'."\n".$icon.$slot,
  like_bravo_thumbnails_prefilter('<a class="x{if !empty($thumbnail.icon_ts)} recent{/if}">n</a>'."\n".$icon));
check('prefilter: Modus symbol instead of the image',
  '<div>{$thumbnail.NAME}{if !empty($thumbnail.icon_ts)}<span class=albSymbol title="{$thumbnail.icon_ts.TITLE}">*</span>{/if}'.$slot.'</div>',
  like_bravo_thumbnails_prefilter('<div>{$thumbnail.NAME}{if !empty($thumbnail.icon_ts)}<span class=albSymbol title="{$thumbnail.icon_ts.TITLE}">*</span>{/if}</div>'));
check('prefilter: attribute and icon block in one template, only the block',
  1, substr_count(like_bravo_thumbnails_prefilter('<a class="{if !empty($thumbnail.icon_ts)} recent{/if}">n</a>'.$icon), $slot));
check('prefilter: template without the new icon is left alone',
  '<li>{$thumbnail.NAME}</li>', like_bravo_thumbnails_prefilter('<li>{$thumbnail.NAME}</li>'));
$modus = like_bravo_thumbnails_prefilter('{if $flow}{modus_thumbs}{else}'.$icon.'{/if}');
check('prefilter Modus: hidden list after the justified rows',
  1, preg_match('#^\{if \$flow\}\{modus_thumbs\}<li class="like-bravo-pending" hidden[^>]*>\{foreach from=\$thumbnails item=thumbnail\}.*data-href="\{\$thumbnail\.URL\}"#', $modus));
check('prefilter Modus: grid layout gets the slot too', 1, substr_count($modus, $icon.$slot));

// --- filter of album pages --------------------------------------------------
check('filter: groups in display order, unknown parts dropped', array('like', 'none'), like_bravo_parse_filter('none-x-like'));
check('filter: empty value is no filter', array(), like_bravo_parse_filter(''));
check('filter: all three groups are no filter', array(), like_bravo_parse_filter('bravo-none-like'));
check('filter: not a string', array(), like_bravo_parse_filter(array('like')));
check('filter toggle: switch on', array('like', 'none'), like_bravo_toggle_filter(array('none'), 'like'));
check('filter toggle: switch off', array('none'), like_bravo_toggle_filter(array('like', 'none'), 'like'));
check('filter toggle: third group switches the filter off', array(), like_bravo_toggle_filter(array('like', 'bravo'), 'none'));
check('filter toggle: from all photos', array('bravo'), like_bravo_toggle_filter(array(), 'bravo'));

// 1 liked, 2 bravo, 3 not voted, 4 star photo with a star rate, 5 no rating with an old rate,
// 6 dormant rate, 7 bravo stored in a like album
$f_items = array(7, 1, 2, 3, 4, 5, 6);
$f_modes = array(1 => BRAVO, 2 => BRAVO, 3 => BRAVO, 4 => STARS, 5 => OFF, 6 => BRAVO, 7 => LIKE);
$f_rates = array(1 => '2', 2 => '5', 4 => '4', 5 => '4', 6 => '1', 7 => '4');
check('filter: none is all photos, order kept', $f_items, like_bravo_filter_items($f_items, $f_modes, $f_rates, array()));
check('filter: my likes, a bravo in a like album is a like', array(7, 1), like_bravo_filter_items($f_items, $f_modes, $f_rates, array('like')));
check('filter: my bravos', array(2), like_bravo_filter_items($f_items, $f_modes, $f_rates, array('bravo')));
check('filter: likes or bravos', array(7, 1, 2), like_bravo_filter_items($f_items, $f_modes, $f_rates, array('like', 'bravo')));
check('filter: without my vote, star photos and photos without rating included', array(3, 4, 5, 6),
  like_bravo_filter_items($f_items, $f_modes, $f_rates, array('none')));
check('filter: likes or without my vote', array(7, 1, 3, 4, 5, 6), like_bravo_filter_items($f_items, $f_modes, $f_rates, array('like', 'none')));
check('filter: photo being shown is kept', array(2, 3), like_bravo_filter_items($f_items, $f_modes, $f_rates, array('bravo'), 3));
check('filter: photos missing in the modes are star photos', array(8), like_bravo_filter_items(array(8), array(), array(8 => '5'), array('none')));

// --- feedback: time of votes, edited comments -------------------------------
check('vote time: a new vote gets a time', true, like_bravo_vote_changed(null, 2));
check('vote time: like -> bravo gets a new time', true, like_bravo_vote_changed(2, 4));
check('vote time: stored rates come as strings', true, like_bravo_vote_changed('2', 4));
check('vote time: the same vote again keeps its time', false, like_bravo_vote_changed('4', 4));
check('vote time: a vote taken back has no time', false, like_bravo_vote_changed(4, null));
check('vote time: nothing to take back', false, like_bravo_vote_changed(null, null));

check('edited comment: id of an edit', 17, like_bravo_edited_comment_id('validate', array('comment_id' => '17', 'content' => 'x')));
check('edited comment: a moderated edit counts', 17, like_bravo_edited_comment_id('moderate', array('comment_id' => 17)));
check('edited comment: a rejected edit does not', null, like_bravo_edited_comment_id('reject', array('comment_id' => 17)));
check('edited comment: a new comment has no comment_id', null, like_bravo_edited_comment_id('validate', array('image_id' => 3, 'content' => 'x')));
check('edited comment: no id from the request but a number', null, like_bravo_edited_comment_id('validate', array('comment_id' => '17 OR 1=1')));
check('edited comment: no id 0', null, like_bravo_edited_comment_id('validate', array('comment_id' => '0')));
check('edited comment: no array, no id', null, like_bravo_edited_comment_id('validate', 'x'));

check('edited comment: saved at once (same second)', true, like_bravo_comment_is_edited('2026-11-23 10:00:00', '2026-11-23 10:00:00'));
check('edited comment: validated later by a moderator', true, like_bravo_comment_is_edited('2026-11-23 10:00:00', '2026-11-24 08:00:00'));
check('edited comment: refused by the core, validation stays older', false, like_bravo_comment_is_edited('2026-11-23 10:00:00', '2026-11-20 09:00:00'));
check('edited comment: waiting for a moderator', false, like_bravo_comment_is_edited('2026-11-23 10:00:00', null));
check('edited comment: never edited', false, like_bravo_comment_is_edited(null, '2026-11-20 09:00:00'));

// --- feedback: read or unread, who is named ---------------------------------
check('read before: "mark all as read" wins', '2026-11-25 08:00:00',
  like_bravo_feedback_read_before('2026-11-25 08:00:00', '2026-11-30 00:00:00', '2026-11-23 22:00:00'));
check('read before: without it, the later of registration and start of feedback', '2026-11-23 22:00:00',
  like_bravo_feedback_read_before(null, '2025-03-01 10:00:00', '2026-11-23 22:00:00'));
check('read before: a member registered after the start', '2026-12-01 09:00:00',
  like_bravo_feedback_read_before(null, '2026-12-01 09:00:00', '2026-11-23 22:00:00'));
check('read before: nothing known', '', like_bravo_feedback_read_before(null, null, null));

check('read: older than "mark all as read"', true, like_bravo_feedback_is_read('2026-11-24 10:00:00', '2026-11-25 08:00:00', null));
check('read: in the same second as "mark all as read"', true, like_bravo_feedback_is_read('2026-11-25 08:00:00', '2026-11-25 08:00:00', null));
check('unread: newer, not read on its own', false, like_bravo_feedback_is_read('2026-11-26 10:00:00', '2026-11-25 08:00:00', null));
check('read: read on its own after it came', true, like_bravo_feedback_is_read('2026-11-26 10:00:00', '2026-11-25 08:00:00', '2026-11-26 11:00:00'));
check('unread again: changed after it was read', false, like_bravo_feedback_is_read('2026-11-27 09:00:00', '2026-11-25 08:00:00', '2026-11-26 11:00:00'));
check('unread: nothing known, not read', false, like_bravo_feedback_is_read('2026-11-26 10:00:00', '', null));

check('excerpt: short text as it is', 'Schönes Licht!', like_bravo_excerpt('  Schönes   Licht! '));
check('excerpt: one line, no tags', 'Schönes Licht und tolle Farben', like_bravo_excerpt("Schönes <b>Licht</b>\nund tolle Farben"));
check('excerpt: cut at a word boundary', 'Das Licht im Hintergrund ist wunderbar und die Farben…',
  like_bravo_excerpt('Das Licht im Hintergrund ist wunderbar und die Farben leuchten richtig schön warm.'));
check('excerpt: multibyte characters are not split', 'ÄÖÜäöüß…', like_bravo_excerpt('ÄÖÜäöüßÄÖÜäöüß', 7));
check('excerpt: emoji are not split', '📷🌅📷…', like_bravo_excerpt('📷🌅📷🌅📷🌅', 3));

$cursor_key = array('kind' => 'vote', 'ref_id' => 43, 'rater_id' => 2, 'rater_anonymous_id' => '192.168.7');
check('cursor: time and read key in one string', '2026-11-24 09:33:49|vote|43|2|192.168.7', like_bravo_feedback_cursor_text('2026-11-24 09:33:49', $cursor_key));
check('cursor: read back', array('time' => '2026-11-24 09:33:49') + $cursor_key, like_bravo_feedback_cursor('2026-11-24 09:33:49|vote|43|2|192.168.7'));
check('cursor: a comment, no anonymous id', array('time' => '2026-11-20 05:00:00', 'kind' => 'comment', 'ref_id' => 7, 'rater_id' => 0, 'rater_anonymous_id' => ''),
  like_bravo_feedback_cursor('2026-11-20 05:00:00|comment|7|0|'));
check('cursor: empty or broken is none', array(null, null, null, null, null, null), array(like_bravo_feedback_cursor(''), like_bravo_feedback_cursor(null),
  like_bravo_feedback_cursor('2026-11-20|vote|1|0|'), like_bravo_feedback_cursor('2026-11-20 05:00:00|image|1|0|'),
  like_bravo_feedback_cursor("2026-11-20 05:00:00|vote|1' OR 1|0|"), like_bravo_feedback_cursor('2026-11-20 05:00:00|vote|1|0')));

check('who: a member with name', array('who' => 'member', 'name' => 'Alice'), like_bravo_feedback_who(false, 'Alice', true));
check('who: a guest', array('who' => 'guest', 'name' => null), like_bravo_feedback_who(true, 'guest', true));
check('who: a member whose account is gone', array('who' => 'former', 'name' => null), like_bravo_feedback_who(false, null, true));
check('who: names hidden by "Names of voters"', array('who' => 'hidden', 'name' => null), like_bravo_feedback_who(false, 'Alice', false));
check('who: names hidden, also for guests', array('who' => 'hidden', 'name' => null), like_bravo_feedback_who(true, null, false));

$now = '2026-11-24 09:30:00';
check('when: under a minute', array('unit' => 'now', 'value' => 0), like_bravo_feedback_when('2026-11-24 09:29:10', $now));
check('when: a time slightly ahead of the database is "now"', array('unit' => 'now', 'value' => 0), like_bravo_feedback_when('2026-11-24 09:30:05', $now));
check('when: minutes', array('unit' => 'minutes', 'value' => 59), like_bravo_feedback_when('2026-11-24 08:30:30', $now));
check('when: hours, also into the day before', array('unit' => 'hours', 'value' => 23), like_bravo_feedback_when('2026-11-23 10:00:00', $now));
check('when: yesterday, a day or more ago', array('unit' => 'yesterday', 'value' => 0), like_bravo_feedback_when('2026-11-23 08:00:00', $now));
check('when: in the last seven days is the weekday', array('unit' => 'week', 'value' => 0), like_bravo_feedback_when('2026-11-22 23:00:00', $now));
check('when: the seventh day back is still in the week', array('unit' => 'week', 'value' => 0), like_bravo_feedback_when('2026-11-18 00:00:00', $now));
check('when: older is a date', array('unit' => 'date', 'value' => 0), like_bravo_feedback_when('2026-11-17 23:59:59', $now));
check('when: date only, today', array('unit' => 'today', 'value' => 0), like_bravo_feedback_when('2026-11-24 00:00:00', $now, true));
check('when: date only, yesterday', array('unit' => 'yesterday', 'value' => 0), like_bravo_feedback_when('2026-11-23 00:00:00', $now, true));
check('when: date only, in the week', array('unit' => 'week', 'value' => 0), like_bravo_feedback_when('2026-11-20 00:00:00', $now, true));
check('when: date only, older', array('unit' => 'date', 'value' => 0), like_bravo_feedback_when('2026-11-12 00:00:00', $now, true));
check('day groups: today, yesterday, the last seven days, earlier', array('today', 'today', 'yesterday', 'week', 'week', 'date', 'date'),
  array(like_bravo_feedback_day('2026-11-24 00:00:00', $now), like_bravo_feedback_day('2026-11-24 09:30:05', $now), like_bravo_feedback_day('2026-11-23 23:59:59', $now),
    like_bravo_feedback_day('2026-11-22 12:00:00', $now), like_bravo_feedback_day('2026-11-18 00:00:00', $now), like_bravo_feedback_day('2026-11-17 23:59:59', $now),
    like_bravo_feedback_day('', $now)));
check('when: unreadable time', array('unit' => 'date', 'value' => 0), like_bravo_feedback_when('', $now));

echo ($checks - $failures) . '/' . $checks . " checks passed\n";
exit($failures > 0 ? 1 : 0);
