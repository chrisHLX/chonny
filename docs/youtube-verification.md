# YouTube uploads: adding testers, and opening it to everyone

Written 2026-10-29 for submitting MindCollector to Google. The steps in the Google Cloud console have
to be done under the Google account that owns the project (`mindcollector-youtube`); everything that
can be prepared outside it is here, ready to paste.

## Where it stands

- The website offers YouTube to admins and to the accounts in `YOUTUBE_TESTERS` (production: `8`).
  `YOUTUBE_PUBLIC` is off. `YouTubeService::availableTo()` decides.
- Google lets a channel link only if its Google account is a **test user** on the project, because
  the project has not been verified.
- Everyone else is told it is for testers for now and to ask on Discord (`YouTubeService::testersOnly()`):
  a box on Your games > Upload, a row in the app's Videos tab (0.6.4 on), and a line on `/wow/app`.

## Adding a tester today

1. **Their Google account:** Google Cloud console > Google Auth Platform > Audience > Test users >
   Add users, and enter the Gmail address their YouTube channel is on. Up to 100.
2. **Their MindCollector account:** find their user id (Admin > Matches lists players by email), then
   on the server add it to `YOUTUBE_TESTERS` in `.env`, comma-separated: `YOUTUBE_TESTERS=8,24`.
   Config is not cached on production, so it takes effect on the next page load. No deploy needed.
3. They open Your games > Upload, click **Connect YouTube**, and accept Google's screen. Google warns
   that the app is unverified; they click Continue.

If the project's publishing status is **Testing**, Google expires each link after 7 days and the
player has to click Connect YouTube again. Setting it to **In production** (step 1 below) stops that,
and test users keep working while verification runs.

## Opening it to everyone

### 1. The consent screen (Google Auth Platform)

| Field | What to enter |
|---|---|
| App name | MindCollector |
| User support email | your address |
| App home page | https://mindcollector.com |
| Privacy policy | https://mindcollector.com/privacy |
| Terms of service | https://mindcollector.com/terms |
| Authorised domain | mindcollector.com |
| Developer contact | your address |
| Data access (scopes) | `https://www.googleapis.com/auth/youtube.upload`, nothing else |
| Publishing status | In production |

An app logo is optional, and adding one asks Google to check the branding as well, so leave it off
unless you want that.

**Before submitting:** prove you own mindcollector.com in
[Google Search Console](https://search.google.com/search-console) with the same Google account. Google
checks the home page and privacy policy are on a domain you have verified.

What the pages already do (deployed with this change):
- The home page links the privacy policy and the terms (its footer).
- `/privacy` section 4 says what is asked for, what is stored, how it is used and how to revoke it,
  and states the Limited Use commitment Google asks for.
- `/wow/app` says the app uploads only the games the player picks, straight from their PC.

### 2. Why the app needs the scope (paste into the form)

> MindCollector is a Windows app and website for World of Warcraft arena players. If the player turns
> recording on, the app records their own arena games on their PC with OBS. With youtube.upload, the
> app uploads only the recordings the player picks in its "Upload to YouTube" window, to the player's
> own channel, with a title and chapter markers written from that game's combat log. The player
> chooses who can see each video (Unlisted unless they pick Public or Private). The video goes
> directly from the player's PC to YouTube; our server never receives it. Afterwards the app shows the
> player the status of the videos it uploaded (still processing, who can watch it, views).
>
> Uploading is the only thing the app does with the player's channel. It does not read, change or
> delete anything else, so it asks for youtube.upload alone, the narrowest scope that allows an upload.

### 3. The demo video Google asks for

Record the screen in English, upload it to YouTube as unlisted, and put the link in the form. Google
wants to see the consent screen with the app's name and the browser's address bar (which shows the
client id), and the feature using the permission.

1. mindcollector.com's home page, scrolling to the footer's **Privacy policy** link.
2. Signed in: Your games > **Upload** > the YouTube box > **Connect YouTube**.
3. Google's screen: the address bar visible, "MindCollector" as the app, the upload permission listed.
   Accept.
4. Back on Your games: the box says it is linked.
5. The app: Videos > **YouTube: upload and share…**, tick one game, Unlisted, **Upload**, the progress
   bar to the end.
6. YouTube Studio: the new video with the title and chapters the app wrote.
7. Its page on mindcollector.com (`/wow/videos/{id}`) playing it with the timeline.
8. Your games > **Disconnect YouTube**, then Google account > Security > third-party access, showing
   MindCollector no longer listed.

### 4. YouTube's own limits (the YouTube Data API, separate from step 1)

- **Quota.** The project gets 10,000 units a day by default, and one upload costs about 1,600, so
  roughly six uploads a day across every player together. Check the current figure in the console
  (APIs & Services > YouTube Data API v3 > Quotas). More is applied for with YouTube's
  [audit and quota extension form](https://support.google.com/youtube/contact/yt_api_form), which
  reviews the app against the YouTube API Services developer policies.
- **Two gaps to settle before that audit.** This is how I read YouTube's developer policies; check
  the current wording when filling in the form.
  1. Disconnecting deletes the token, but the player's video records (id, title, privacy, views) stay
     so their pages keep playing. The policies expect a player's API data to be deleted once they
     revoke access. The choice: delete the rows on disconnect (their video pages and library entries
     go with them), or keep only what the policies allow.
  2. Stored YouTube data has to be refreshed or deleted within 30 days. Titles, privacy and views are
     refreshed only when a page is opened (`YouTubeVideoService::refresh`). A daily refresh beside
     the leaderboard cron line would cover it.

### 5. The switch

Once Google has verified the app (and the quota is enough for the players using it), set
`YOUTUBE_PUBLIC=true` in production's `.env`. Everyone signed in is then offered Connect YouTube, and
the testers-only box and row disappear by themselves.
