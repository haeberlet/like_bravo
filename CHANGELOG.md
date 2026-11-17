# Changelog

All notable changes to Like & Bravo. The version numbers follow
[Semantic Versioning](https://semver.org): the last number for fixes, the middle
one for new features, the first one for changes that need action by the
webmaster.

## Unreleased

First public release, planned as 1.0.0.

### Rating

* "Like", or "Like" and "Bravo", instead of Piwigo's stars, chosen per album in
  the tab "Member rating": Inherited, Piwigo stars, No rating, Like only,
  Like + Bravo. Sub-albums inherit the setting.
* Built on Piwigo's rating system: a like is stored as a rating of 2, a bravo as
  4. "Best rated" keeps working, and switching an album loses no vote.
* Score from the gallery average of liked photos, on Piwigo's scale from 0 to 5
  (see [docs/rating.md](docs/rating.md)).
* Album setting "No rating": no stars, no buttons, photos left out of "Best
  rated".

### Photo page

* Widget with like and bravo buttons and counters; a click on the active button
  takes the vote back.
* Names of the voters: short form on hover, full list on click. Shown to nobody,
  to members or to all visitors.
* Place of the widget: right below the photo (Bootstrap Darkroom) or with the
  photo information.

### Album pages

* Likes and bravos below the thumbnails, with voting by click; a badge on the
  photo in Modus with justified rows. Also for thumbnails loaded later while
  scrolling.
* Filter in the toolbar for members: photos I like, photos I gave a bravo,
  photos without my vote. Combinable, kept for the session, also for previous
  and next on the photo page.
* Personal pages "My likes" and "My bravos" in the menu block "Explore".

### Settings

* Icons for Like, Bravo and "Remove my vote" from a built-in set.
* Members may rate their own photos (on by default); guests may like (off by
  default).
* Texts changeable per language with LocalFiles Editor.

### Languages

* English, German, French, Spanish, Italian.
