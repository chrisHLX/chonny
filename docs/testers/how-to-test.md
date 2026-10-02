# Testing MindCollector: your arena games, reviewed

Thanks for testing. This takes about five minutes to set up. After that you play as normal and
upload your games afterwards.

## 1. Make an account

Go to **mindcollector.com** and sign up. Signing in with Battle.net is quickest.

## 2. Install the addon

1. Unzip `MindCollectorArenaLog.zip`.
2. Put the `MindCollectorArenaLog` folder into
   `World of Warcraft\_retail_\Interface\AddOns\`.
   You should end up with `...\AddOns\MindCollectorArenaLog\MindCollectorArenaLog.toc`.
   If you get a folder inside a folder, move the inner one up.
3. Start WoW, or type `/reload` if it's already running.
4. On the character select screen, open **AddOns** and make sure **MindCollector Arena Log** is
   ticked.

To check it's working, type `/mcarenalog` in game. It answers in chat with whether logging is on
and whether Advanced Combat Logging is on.

## 3. Play

Play rated **3v3** or **Solo Shuffle** as you normally would.

The addon turns combat logging on when you enter an arena and off when you leave, so the log only
holds your games. It also turns on Advanced Combat Logging, which the site needs to see specs, and
tells you in chat the first time it does.

You don't need to type `/combatlog` yourself.

## 4. Upload your games

After you've played:

1. Go to **mindcollector.com** and open **Game Review** in the menu on the left.
2. Click **Choose log file**.
3. Go to `World of Warcraft\_retail_\Logs\` and pick the newest file named
   `WoWCombatLog-` followed by the date.
4. Wait for the bar to finish. Your browser picks out the arena rounds and sends only those, so even
   a big log goes up quickly.

Uploading the same file twice is safe; games already there aren't duplicated.

## 5. Look at your games

- **Game Review** lists each game: rounds won and lost, everyone's damage and healing, and a
  comparison with anyone playing your spec.
- **Your analysis** (linked from Game Review) puts your games together: who you played,
  how experienced they were, and what differed between your wins and losses. Opponents'
  experience can take a minute or two to fill in after an upload.

## 6. Tell us what you find

Anything that looks wrong is the most useful thing you can send: a game that's missing, a result
that's backwards, a spec that's wrong, a number that doesn't match what happened. Say which game
(the date and time shown on the site) and what you expected to see.

## Good to know

- Your games are private to your account. They show the other players' names and specs, so they
  aren't shared publicly.
- WoW keeps adding to its log files. Once you've uploaded a file you can delete it from the `Logs`
  folder to save space.
- If `/mcarenalog` does nothing after a `/reload`, the addon isn't loading. Check it's ticked in the
  AddOns list. If it is, tick **Load out of date AddOns** there too, and let us know your game
  version.
