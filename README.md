# DiscordPHP-Bridge-YouTube

The YouTube connector for [DiscordPHP-Bridge](https://github.com/discord-php/DiscordPHP-Bridge):
your stream's live chat in Discord, go-live announcements, and moderating the chat from anywhere
the bot is.

```
#stream-chat  ◄──────────  youtube.com/@yourchannel (live chat)
```

Built on [YouTubePHP](https://github.com/Valgorithms/YoutubePHP), sharing the bot's ReactPHP event
loop.

## Installing it

```php
use Bridge\YouTube\{YouTubeConfig, YouTubeConnector};

if (YouTubeConfig::isConfigured($environment)) {
    $bot->addConnector(new YouTubeConnector(YouTubeConfig::fromEnvironment($environment)));
}
```

The core adds `link`, `here`, `unlink`, `list`, `status` and `reset`; this package adds what is
about YouTube.

### What Google needs

In the [Google Cloud console](https://console.cloud.google.com):

1. A project with the **YouTube Data API v3** enabled.
2. An **OAuth consent screen**, **published**. While it is in *Testing*, Google ends every sign-in
   after seven days, and the bot DMs you when that happens. An unverified app is fine for your own
   channel: Google shows a warning when you sign in, which you can click through.
3. An **OAuth client** of type **TVs and Limited Input devices**. Its id and secret go in `.env`.

```dotenv
YOUTUBE_CLIENT_ID=123456789012-abc.apps.googleusercontent.com
YOUTUBE_CLIENT_SECRET=GOCSPX-...
```

On the first start the bot DMs its owner (`DISCORD_OWNER_ID`) a code to enter at
`google.com/device`, and also logs it. Sign in with the Google account that owns the channel. The bot
writes the refresh token back to `.env` as `YOUTUBE_REFRESH_TOKEN`, so this happens once.

| Setting | Default | |
| --- | --- | --- |
| `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET` | required | The OAuth client. |
| `YOUTUBE_REFRESH_TOKEN` | written by the bot | The sign-in. |
| `YOUTUBE_DAILY_QUOTA` | `10000` | The project's daily units, if Google has granted more. |
| `YOUTUBE_QUOTA_RESERVE` | `500` | Units reading chat leaves for your commands. |
| `YOUTUBE_COMMANDS` | `moderators` | Who in YouTube chat gets answers: `everyone`, `moderators` or `off`. |
| `YOUTUBE_PREFIX` | `!` | What commands in YouTube chat start with. |
| `YOUTUBE_OWNER_CHANNEL_ID` | none | Your channel id: the operator rung in YouTube chat. |
| `YOUTUBE_ANNOUNCE_UNLISTED` | `false` | Announce unlisted and private streams too. |
| `YOUTUBE_CHAT_MODE` | `stream` | `poll`, if streaming chat ever misbehaves. |

### Bridging

```
/youtube link target:me channel:#stream-chat
```

The bridge follows the channel the bot is signed in as: `me`, its handle, its id or its link all
name it. That is YouTube's rule, not this package's. The API reads the live chat of the signed-in
account's own broadcasts, so another channel's chat needs its owner to sign in.

## What it does

**Chat, into Discord.** While the channel is live, every message in its chat arrives in the bridged
Discord channels, under the sender's name and avatar. So do Super Chats and Super Stickers
(`💰 $5.00 Super Chat: great stream`), new members, milestones and gifted memberships. The chat is
streamed: YouTube pushes each message as it is posted, over one long-lived request.

**Not out of Discord.** Messages in Discord are not posted into YouTube chat. Each post costs 50 of
the day's 10,000 quota units, so a busy Discord channel would spend the day's quota in an hour. The
bot posts in YouTube chat only to answer commands, and when you use `say`. On a channel bridged to
Twitch too, YouTube chat still reaches Twitch, because posting there is free.

**Bans.** When a moderator bans someone on YouTube, what they said recently leaves Discord too.
YouTube no longer tells API clients when a single message is deleted, so a deletion made on YouTube
is not mirrored. `/youtube mod delete` is.

**Announcements.** Going live and ending are announced in the bridged channels, from what the
bot already knows, at no extra quota:

```
🔴 Live now: Building a bridge
https://www.youtube.com/watch?v=...
```

Unlisted and private streams are not announced, because their link in a Discord channel would
publish them. Their chat is still relayed. What was announced is kept in `youtube-live.json`, beside
`bridges.json`, so a restart mid-stream does not announce the stream twice.

**Dropped connections.** Chat reconnects by itself, backing off from a second to a minute over about
five minutes, then every five minutes. When the quick retries run out you get a DM with a
**Reconnect now** button, and the DM changes when chat is back.

## The quota

Google gives each project 10,000 units a day, reset at midnight Pacific time. What this spends:

| | Units |
| --- | --- |
| Signing in (loading the channel) | 1, once |
| Checking whether the channel is live | 1 every 2 minutes, 1 every 5 while live: about 720 a day |
| Reading chat | 1 per streaming connection, which lasts until YouTube closes it or it is quiet for 5 minutes |
| A reply, `say`, a ban, a timeout, an unban | 50 each |
| `delete` | 50 per message |

Reading chat stops at the reserve (`YOUTUBE_QUOTA_RESERVE`), so your own commands still work late in
the day. When it stops you get one DM saying chat is paused, and until when. The count is kept in
`youtube-quota.json` beside `bridges.json`, so it survives restarts. `/youtube quota` shows it.

Google does not publish what a streaming connection costs, so it is counted like a list call. The
Quotas page of the Cloud console has the real figure.

## Commands

| | |
| --- | --- |
| `/youtube link` · `here` · `unlink` · `list` · `status` · `reset` | the bridge (admin) |
| `/youtube quota` | today's quota (moderator) |
| `/youtube say <message>` | post in the live chat as the channel (operator) |
| `/youtube mod ban` · `timeout` · `unban` · `delete` | moderate the live chat (moderator) |

Moderation names people as chat shows them, `@handle` or not, or by channel id. The bot learns
who is who from chat itself, so a name works for anyone who has spoken since it started. `unban`
lifts only bans this bot made: YouTube lifts a ban by the ban's own id, which it gives only to
whoever made the ban.

In YouTube chat, commands are answered for moderators and the channel only, by default. Every
answer is a post, and a post costs 50 units, so answering everyone would let anyone spend your
quota with `!bridge help` on a loop. Set `YOUTUBE_COMMANDS=everyone` to answer all, within each
command's cooldown.

## Layout

```
src/Bridge/YouTube/
  YouTubeConnector.php   the client, the watcher and readers, announcements, the owner's DMs
  YouTubeConfig.php      settings, from the environment only
  YouTubeAdapter.php     the catalogue, in YouTube chat
  YouTubeText.php        channel references, and how each kind of chat item reads
  Actions/               quota, say, and moderation
```

```bash
composer test
```

## Licence

MIT.
