# Like & Bravo

A plugin for [Piwigo](https://piwigo.org) 16 that replaces the star rating by
"Like", or by "Like" and "Bravo", in the albums you choose. Other albums keep
their stars, or have no rating at all.

It is built on Piwigo's own rating system: votes are stored as ordinary ratings,
"Best rated" keeps working, and an album can be switched between stars and likes
at any time without losing a vote.

Members also get a bell that tells them who liked their photos, gave them a bravo
or commented on them, and who else commented on photos they commented on.

* Internal name: `like_bravo` (directory name in `plugins/`)
* Requires Piwigo 16 or later, PHP 8
* Languages: English, German, French, Spanish, Italian

## What visitors get

On the photo page of an album in a like mode, the stars are replaced by a small
widget:

* one button for "Like" and, if the album allows it, one for "Bravo";
* counters such as "12 likes · 3 bravos" instead of a score;
* a click on the active button takes the vote back. There is no "dislike".

Members can see who voted. Hovering over a counter shows a short form ("Liked by
Anna, Ben, Carl and 4 others"), a click on it shows all names.

![Photo page in the theme Bootstrap Darkroom: like and bravo buttons with counters](docs/screenshots/widget-bootstrap-darkroom.png)

A click on a counter lists everybody who voted:

![Photo page with the names of the voters](docs/screenshots/voters.png)

The widget takes its colours from the theme. This is the theme Modus:

![Photo page in the theme Modus](docs/screenshots/widget-modus.png)

On album pages, the thumbnails of these photos show the same icons with the
number of likes and bravos, next to the theme's icon for new photos. A click on an
icon votes or takes the vote back, as on the photo page; the names of the voters
are only on the photo page. The theme Modus, with its justified rows, shows them
as a small badge in the corner of the photo.

In Bootstrap Darkroom the icons sit in the caption, next to the star that marks
new photos:

![Album page in the theme Bootstrap Darkroom: likes and bravos below each thumbnail](docs/screenshots/album-bootstrap-darkroom.png)

In Modus they are a badge in the top right corner of each photo:

![Album page in the theme Modus: likes and bravos as a badge on each thumbnail](docs/screenshots/album-modus.png)

Signed-in members also find three icons in the toolbar of album pages, to filter
the photos by their own votes: the photos they liked, those they gave a bravo, and
all others. The icons can be combined, and the page shows the photos of every
active group. No active icon, or all three, shows all photos. The filter stays on
for the session, like the sort order, and also applies to "previous" and "next"
on the photo page. Photos with stars or without rating count as "all others".

The three icons share a frame, so they read as one group among the theme's own
buttons. Active icons are filled in their colour on a grey background. Here the
filter shows the photos liked or given a bravo, without "all others":

![Toolbar of an album page with the filter group: like and bravo active, "all others" inactive](docs/screenshots/toolbar.png)

In the collapsed menu of narrow windows, the group sits on one line headed
"Filter". Hovering over an icon shows what it filters.

The menu block "Explore" gets two personal pages for signed-in members: "My likes"
and "My bravos", with the latest votes first.

## Feedback bell

Signed-in members see a bell on every page of the gallery. Its red number counts
the likes, bravos and comments that others gave to their photos and that they have
not read yet, and the new comments on photos of others that they commented on. A
photo belongs to the member who added it, for example with the plugin Community.

A click on the bell opens the latest feedback, newest first. Older entries load
while scrolling. Each entry has the thumbnail of the photo and one line of text,
followed by the time and the photo's current likes and bravos:

* "Anna likes your photo" / "Anna gave your photo a bravo"
* "Ben commented on your photo: Lovely light in the …" (the beginning of the
  comment)
* "Ben edited a comment on your photo: …"
* "Carl commented on a photo by Anna that you commented on too: …", or "Anna
  commented on their own photo that you commented on too: …" when the owner of
  the photo wrote the comment

![The open list of the bell in the theme Bootstrap Darkroom, with read and unread entries](docs/screenshots/bell.png)

Unread entries have a blue dot and a light background. A click on the thumbnail
opens the photo page (for a comment, at the comments) and marks the entry as
read. So does a click on the dot, without leaving the list. "Mark all as read"
marks everything at once. A click on the name of a member opens their own album
of the plugin Community, if there is one with photos the owner may see.

The link "All feedback" at the bottom of the list leads to a page with all
feedback without an age limit. It is a feed under the headings Today, Yesterday,
This week and Earlier. Next to the feed are an overview of the last 7 days, the
filters All entries / Unread and All kinds / Likes and bravos / Comments / Also
commented, and the photos with the most feedback. The overview and the photos with
the most feedback count only feedback on the member's own photos. On phones these
sit above the feed.

![Page "All feedback": the feed under the heading Today, next to it the overview, the filters and the photos with the most feedback](docs/screenshots/feedback-page.png)

What counts:

* Of the votes, only likes and bravos. Votes with Piwigo's stars, or on photos
  without rating, do not appear. Comments appear on all photos.
* A changed vote and an edited comment are unread again. A vote taken back, a
  deleted comment or photo disappear. Comments that need approval appear once
  they are approved.
* A member's own votes and comments on their own photos do not appear.
* Also commented: after a member commented on a photo of someone else, every
  later comment of others on it appears, whether it answers them or not (Piwigo
  does not know answers). Comments written before the member's first one do not
  appear, nor do likes and bravos on such photos. The entries disappear when the
  member deletes all their comments on the photo, or when they may no longer see
  the photo. An edited comment does not come back as unread.
* Names follow the setting "Names of voters". Shown "to signed-in members" or "to
  all visitors", members see in the bell who liked their photos or gave them a
  bravo. Shown "to nobody", the entry says "Your photo got a like" or "Your photo
  got a bravo" without a name. Comments always
  show their author. Guests appear as "a guest", votes of deleted members as "a
  former member".
* When the plugin is installed or updated, all feedback given before counts as
  read, so the bell starts at zero.

The bell finds its place in the menu bar of Bootstrap Darkroom, Modus, Elegant and
SmartPocket. In other themes, and where the theme hides its menu, it sits in the
top right corner of the window. It has no settings and sends no emails.

## Requirements

* "Allow rating" must be switched on in the Piwigo configuration.
* The rating values 2 and 4 must be available. They are with Piwigo's default
  values (0 to 5); this only matters if you changed `$conf['rate_items']`.

## Installation

1. Unpack the archive into the `plugins` directory of your gallery. The folder
   must be named `like_bravo`.
2. In the administration, open Plugins and activate "Like & Bravo".

## Choosing the albums

Open an album in the administration and go to the tab "Member rating". Each album
has one of five settings:

| Setting | Effect |
|---|---|
| Inherited (default) | the setting of the nearest parent album that has one; Piwigo's stars if none has |
| Piwigo stars | Piwigo's stars, also if a parent album says otherwise |
| No rating | no stars, no buttons, no score; the photos are left out of "Best rated" |
| Like only | the "Like" button |
| Like + Bravo | the "Like" and the "Bravo" button |

![Tab "Member rating" of an album](docs/screenshots/album-tab.png)

A setting applies to the album and all its sub-albums until a sub-album has its
own. A photo that belongs to several albums follows the setting that allows the
most rating: Like + Bravo before Like only before Piwigo stars before No rating.
Albums without any setting, of their own or inherited, count least: a photo in an
album without rating and in an album without any setting has no rating.

"No rating" needs no change to the Piwigo configuration: "Allow rating" stays on
for the rest of the gallery.

## Settings

Under Plugins, "Like & Bravo" has a settings page:

* **Icons** for Like, Bravo and "Remove my vote", from a built-in set.
* **Place of the widget**: right below the photo, as a compact line without
  heading (default), or with the photo information. Below the photo works in the
  theme Bootstrap Darkroom for now; other themes always show the widget with the
  photo information.
* **Likes and bravos below the thumbnails** (on by default). They need the
  captions below the thumbnails, which Piwigo and some themes can switch off;
  Modus with justified rows shows them as a badge on the photo instead.
* **Members may rate their own photos** (on by default).
* **Guests may like** (off by default). It needs Piwigo's own option "Rating by
  guests". Piwigo tells guests apart by their network address only, so guest votes
  are neither exact nor safe from manipulation; the settings page explains the
  details.
* **Names of voters**: shown to nobody, to signed-in members (default) or to all
  visitors, and how many names the short form holds. Switching this on also shows
  the names for votes cast earlier. Guests never have a name, they appear as a
  number.

![Settings page of the plugin](docs/screenshots/settings.png)

## Changing the texts

Every text of the plugin can be changed per language in
`local/language/<code>.lang.php`, for example with the plugin LocalFilesEditor:

```php
$lang['Member rating'] = 'Club rating';
$lang['Bravo'] = 'Award';
$lang['%d bravo'] = '%d award';
$lang['%d bravos'] = '%d awards';
```

The keys are the English texts in `language/en_UK/plugin.lang.php`.

## How the score is computed

A like is worth 2 points and a bravo 4 points. The score of a photo compares its
points with the average of all liked photos in the gallery, on Piwigo's scale from
0 to 5: a photo with average points scores 2.5, one with four times the average
4.0. Photos with stars keep Piwigo's own score.

The full rule, with the formula, examples and what happens when an album is
switched, is in [docs/rating.md](docs/rating.md).

## Deactivating and uninstalling

* **Deactivating** hands all scores back to Piwigo. Likes then count as a rating
  of 2 and bravos as a rating of 4. The album settings are kept, and activating
  the plugin again restores the like scores. The bell disappears; what has been
  read is kept.
* **Uninstalling** also removes the album settings, the plugin configuration and
  what the bell needs: the time of day of the votes and what has been read. The
  votes stay in Piwigo's rating table, the comments in Piwigo's comments.

## Known limitations

* Piwigo does not tell plugins when a photo is moved or added to another album.
  The score of such a photo follows with the next vote anywhere in the gallery or
  the next change of an album setting.
* The theme SmartPocket shows a fixed list of entries in its menu. "My likes" and
  "My bravos" are missing there; the pages themselves work. Its thumbnails have
  no caption and no toolbar, so they show no likes and bravos and there is no
  filter.
* Without captions below the thumbnails there are no likes and bravos on them,
  for example in Modus with square thumbnails, where the theme switches the
  captions off. Votes are then given on the photo page.
* The bell knows the owner of a photo from Piwigo's field "added by". Photos the
  webmaster uploads for others belong to the webmaster, and so does their
  feedback.
* Piwigo keeps only the day of a vote. The plugin notes the time itself, from its
  installation on; older votes show their day only.
* The French, Spanish and Italian texts have not been reviewed by native
  speakers. Corrections are welcome.

The photo page has been tested with the themes Bootstrap Darkroom, Modus, Elegant
and SmartPocket. Thumbnails loaded later while scrolling, for example by the
plugin RV Thumb Scroller, get their likes and bravos as well.

## License

Copyright (C) 2026 Tilmann Haeberle

This program is free software; you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).
