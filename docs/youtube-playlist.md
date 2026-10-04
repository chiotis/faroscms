# The YouTube playlist block

The **YouTube playlist** block (`playlist`, in the Media group of the block picker) shows the videos of a playlist drawn by the site itself, so the layout, the pictures and the text follow the theme instead of YouTube's player.

## Where the videos come from

| | Without a key | With a key (Settings > APIs) |
|---|---|---|
| Source | the playlist's public feed (`youtube.com/feeds/videos.xml`) | the YouTube Data API v3 |
| How many | the 15 newest | up to 50 |
| Each video has | title, description, date, views | title, description, date, views and **length** |
| Private or deleted videos | not in the feed | left out |
| Cost | none | about 3 units of the key's 10,000 a day, once for as long as the playlist is kept |

Only **public and unlisted** playlists can be shown. A key that is wrong, over its quota or not allowed makes the block use the feed instead (a person who is signed in sees why, above the videos); a playlist the API does not know is not searched for in the feed.

The key is entered in *Settings > APIs > YouTube*, with *Save and test the key*. It is kept in the system database like the other secrets, is never sent to the browser or written to the activity log, and a blank box keeps the one saved. Keys limited to websites do not work: the site asks YouTube from the server, so limit the key to the YouTube Data API (and to the server's address if it is fixed).

## Keeping the answer

A playlist is fetched when a page needs it and kept in the system database for 1, 6 (default), 24 or 72 hours (*Settings > APIs*). A visitor never waits for a copy that is kept. When YouTube cannot be asked, the last copy is shown (marked for people who are signed in) and the site waits five minutes before asking again; with nothing kept, a visitor sees no section and a person who is signed in sees why.

## What a visitor loads

By default **nothing is asked of YouTube until a visitor plays a video**. The pictures are fetched once by the server, kept in `storage/cache/youtube/` for 30 days and served from `/_yt/<video id>.jpg?s=<signature>`; the signature stops the route being used to fetch other pictures from YouTube through the site. The player is `youtube-nocookie.com` and is created only when a video is played. *Pictures: From YouTube* loads them from YouTube instead, which tells Google about every visitor when the page opens.

## What the editor can set

Layout: grid (2 to 4 columns), list with descriptions, a player with the list beside it (choosing a video in the list plays it in the player; without JavaScript the items are links to YouTube), or a scrolling strip. Number of videos (1 to 50), order (as in the playlist, newest, oldest, most viewed), picture shape, how a video plays (a large viewer, in place, or on YouTube), pictures (kept here or from YouTube), and whether to show descriptions, the length, the views, the date and numbers, with an optional link to the playlist. The heading can be the playlist's own title.

## Notes

- A playlist's feed is a public address that YouTube has kept for many years but does not document; the key avoids relying on it.
- YouTube's terms for its API ask for data not to be kept for long: pictures are refreshed every 30 days and playlists at most every 3 days.
- Files: `src/YouTubePlaylist.php` (fetching and keeping), `src/YouTubeThumbs.php` (the pictures), `themes/default/blocks/playlist/`, and the APIs tab of `admin/templates/settings.twig`. Tests: `tests/unit/youtube-playlist.php` and `tests/http/youtube_playlist_test.py` (they do not reach the internet).
