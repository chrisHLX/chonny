# WoW: Forever market research (2026-11-07)

Research for a possible Forever addon or app that shows current auction prices beside a forecast of
what will be in demand later. Written three days after launch (2026-11-04). Nothing here is price
data: no level-60 economy exists yet. What it does have is every Forever recipe and reagent, taken
from the game's own data files, and the dated content calendar.

**How sure each part is.** Recipe facts (what uses what, how many) come straight from the client
data on wago.tools (product `wowf`, build 1.60.1) and are solid. Dates come from Blizzard. Vendor
prices, auction house structure and drop sources come from fan sites written during the beta and are
marked as such. The watchlist at the end is a set of guesses to test, not a finding.

## The question

You can buy now and sell later for two separate reasons, and the addon should keep them apart:

1. **Inflation.** Gold enters the game through quests, loot and vendor trash, and only some of it
   leaves through repairs, training and vendors. As players reach 60 and farm, there is more gold
   per player, so most prices rise together. That is why "buy anything now" broadly works.
2. **Real demand.** Some materials will be used far more later than now: raid consumables, PvP
   consumables, enchants and profession levelling. These rise faster than everything else. This is
   the part worth forecasting, and the part this research covers.

The way to separate them is to divide an item's price by a basket index of many items (see "How the
addon could work"). An item whose price rises while its real price stays flat was inflation only.

## What you can buy now, while everyone levels

Right now the materials on the auction house are the ones levelling players gather on the way up.
The ones worth holding are those whose supply falls once players pass their zones, while a
consumable that people use at 60 keeps the demand going. Every herb, ore and cloth has an item level
matching the zones it comes from, so the client data says which tier is flowing now. The list below
is every levelling-tier material (item level 52 or less) that a consumable used at 60 needs, with
the consumable's effect from the game files (`tools/forever-market/levelling_now.py`).

**Materials from the 15 to 25 zones, flowing now:**

| Material | Used at 60 in |
|---|---|
| Swiftthistle | Swiftness Potion (+50% run speed, PvP), Thistle Tea (100 energy, rogues) |
| Briarthorn | Swiftness Potion |
| Oily Blackmouth (fished) → Blackmouth Oil | Free Action Potion, 2 Oil each (immune to stuns and slows) |
| Stranglekelp | Free Action Potion, Nature Protection Potion |
| Grave Moss | Caustic Smog Potion (new, thrown, PvP), Shadow Oil, Shadow Protection Potion |

**Materials from the 30 to 47 zones, flowing over the next few weeks:**

| Material | Used at 60 in |
|---|---|
| Fadeleaf | Potion of Predatory Senses (new, finds stealthed players), Lesser Invisibility |
| Goldthorn | Restorative Potion (cleanses, PvP), Elixir of Greater Agility (+25), Arcane Elixir, Predatory Senses |
| Khadgar's Whisker | Elixir of Greater Intellect (+25), Superior Spellblasting Potion (+33 spell damage), Magic Resistance Potion |
| Wintersbite | Distilled Firewater (+35 attack power), Superior Spellblasting, Elixir of Frost Power |
| Firebloom | Brilliant Wizard Oil (×3, the top caster weapon oil), Wizard Oil, Superior Frenzy Potion, Cleric's Elixir |
| Purple Lotus | Brilliant Mana Oil (×3, the top healer oil), Greater Cleric's Elixir, Elixir of Greater Spirit, Dreamless Sleep |
| Arthas' Tears | Greater Cleric's Elixir (×3, +40 healing), Greater Mageblood Elixir, Elixir of Greater Spirit, Superior Mender's |
| Sungrass | Elixir of Greater Fortitude (×4, +400 health), Elixir of the Phalanx (×4), Superior Mana Potion, Greater Agility, Greater Strength |
| Blindweed | Limited Invulnerability Potion (PvP), Major Frenzy Potion (+80 attack power), Greater Mageblood, Superior Frenzy |
| Ghost Mushroom | Limited Invulnerability Potion, Caustic Smog Potion, Elixir of Shadow Power (×3) |
| Elemental Fire / Earth / Water | Greater Fire, Nature, Frost Protection Potions (fire resistance for Onyxia); Earth also in Restorative Potion |
| Stonescale Eel → Stonescale Oil | Greater Stoneshield Potion, Flask of the Titans (×10) |
| Dream Dust, Large Radiant Shard, Greater Nether Essence | 17, 7 and 6 enchants still used at 60 (Greater Stamina on boots, bracers and shield; Greater Agility bracer; Speed boots). They come from disenchanting level 40 to 50 gear, so supply falls once people stop running those dungeons |

**Weak despite their level requirement.** Forever added level-45 elixirs made from mid herbs:
Elixir of Strength, Intellect and Spirit (+10 each), Elixir of Fortitude (+200 health). Their
"Greater" versions give 25 or 400, so these probably serve levelling, not 60. That leaves Wild
Steelbloom and Kingsblood with little demand at 60.

**Not obtainable yet.** Death Lotus, Demonsage and Marefoil (item level 60, only in the
December 9 flasks) can't be bought now, so they don't fit a buy-now plan. Their companion herbs in
those flasks (Golden Sansam, Dreamfoil, Mountain Silversage, Icecap) are 50 to 60 materials: few
players gather them yet, so there is little supply to buy cheap today.

## Beyond herbs

The same check for everything else, now also counting crafted level-55+ gear, bags and anything
learned at 275+ as a buyer at 60, and following intermediates (Runecloth to Bolt of Runecloth to a
robe). Script: `tools/forever-market/non_herb.py`. The number is how many level-60 recipes need the
material, which measures breadth, not volume.

**Meat for the new buff foods (flowing now, zones 20 to 35).** Forever added level-45 foods that give
+20 to a main stat, as strong as vanilla's best: Swiftstrike Steak and Flank au Poivre (+20
Agility, Tiger Meat and Red Wolf Meat), Bear Bruscitti and Steaming Stag Steak (+20 Strength, Big
Bear Meat and Stag Meat), Savory Turtle Stew (+20 Stamina, Turtle Meat), Prehistoric Pulled Raptor,
Soaring Pamplona and Bat Hachee (+20 Intellect, Raptor Flesh, Buzzard Wing, Meaty Bat Wing). The
level-55 feasts use Turtle Meat, Buzzard Wing and Raptor Flesh too. Every raider eats one each raid.
The meat drops from beasts in the zones people are levelling through this week, and their recipes
bind on pickup and need Cooking 275, so few cooks will make them early. Of everything here, this
looks the closest to the herb case: supply now, demand at 60, supply gone later.

**Cloth, metal and leather (mostly from 45 to 60 zones, so later).**

| Material | Level-60 recipes | What drives it |
|---|---|---|
| Thorium Bar | 123 | Plate and weapons, grenades for every class, Radiant and Dark Iron fire-resistance plate for Onyxia, engineering |
| Runecloth | 95 | Runecloth bags, robes, Heavy Runecloth Bandages, grenades |
| Rugged Hide / Leather | 75 | Leather and mail gear, armor kits |
| Ironweb Spider Silk | 49 | Tailoring and leatherworking gear, bags |
| Felcloth | 41 | Robes, Felcloth and Mooncloth bags |
| Dense Stone | 33 | Grinding stones, Dense Sharpening Stones and Weightstones, blasting powder |
| Mithril Bar | 31 | Engineering parts (cylinders, tubes, the Dragonling) |
| Dark Iron Bar | 26 | Dark Iron fire-resistance plate, Dark Iron Bomb (from Blackrock Depths) |

**Elementals.** Elemental Earth and Air (21 and 19 recipes, mostly through the Truesilver Transformer
into engineering trinkets and the Flame, Ice and Shadow Reflectors). Heart of Fire (15, the fire
resistance plate). Elemental Fire for Greater Fire Protection Potions. All of it points at Onyxia on
December 9.

**Enchanting materials.** Lesser Eternal Essence (78, mostly through Enchanted Leather into gear),
Dream Dust (45, enchants and Enchanted Thorium), Vision Dust (Sigil of Innovation and the Thick armor
kits), Greater Nether Essence and Large Radiant Shard. They come from disenchanting, so their supply
rises with the gear people get.

**Gems.** Star Ruby (27), Aquamarine, Black and Golden Pearl, Jade, all for crafted gear and scopes.

**Fish.** Stonescale Eel (45 zones) for Greater Stoneshield and Flask of the Titans. Raw Nightfin
Snapper, Sunscale Salmon, Glossy Mightfish, Spotted Yellowtail and Summer Bass for spell damage,
attack power and crit foods.

**Recipe items.** 248 recipe items that teach a level-60 recipe can be traded. The low ones drop or
sell while levelling (Recipe: Free Action Potion, Swiftness Potion, Thistle Tea, Magic Resistance
Potion, Potion of Predatory Senses), and their value rises as crafters reach the skill. Most new
Forever recipes bind on pickup, so they can't be flipped.

**Bags.** The opposite case: everyone buys them once, early, so demand peaks now and falls.

**Timing.** Low materials currently have a second buyer: players levelling professions through
that tier. That demand passes as the population moves up, so the cheapest point may come a few weeks
from now, between profession levelling moving on and the demand at 60 arriving. That dip is
exactly what the addon's price chart should catch.

## What Forever changes for the economy

- **2,129 craftable recipes in the client, 866 of them new to Forever.** Every profession gained
  recipes (Leatherworking 238 new, Blacksmithing 177, Tailoring 164, Enchanting 80, Engineering 73,
  Alchemy 70, Cooking 47, First Aid 17). The data files also hold leftover Season of Discovery
  recipes whose products don't exist in Forever; those are filtered out.
- **Healing potions moved to First Aid.** The standard healing potions are First Aid recipes made
  from a herb plus vendor spices (Major Healing Potion: Golden Sansam, 3 Soothing Spices, Crystal
  Vial). Alchemy instead gets "Discolored" healing potions made with a herb and a Murloc Eye. Nearly
  every character levels First Aid, so herb demand now comes from far more players than alchemists.
- **New endgame flasks only work in the new raid zones.** Flask of Natural Accuracy, Aggression,
  Precision and Swiftness give Stamina everywhere, and their hit, crit, haste or anti-dodge bonus
  only "while in Mount Hyjal, Hyjal Summit, and the Barrow Deeps". Each takes 1 Death Lotus, 12 to 18
  Demonsage or Marefoil, 6 to 9 of an older herb, and an Imbued Vial. They exist for the raids that
  open on December 9.
- **Anyone can throw grenades.** EZ-Thro Thorium Grenade, EZ-Thro Dark Bomb and EZ-Thro Mana Bomb
  have no Engineering requirement ("Anyone can use grenades with EZ-Thro!"). The plain Thorium
  Grenade still needs Engineering 260. An EZ-Thro grenade is the normal recipe plus an EZ-Thro Shell
  (2 Thorium Bars, 1 Pristine Scale). A stun grenade every class can use is a large new PvP demand
  for Thorium Bar, Dense Blasting Powder and Runecloth.
- **New PvP potions.** Caustic Smog Potion (thrown, area Nature damage), Potion of Predatory Senses
  (stealth detection, a heat trail on moving stealthed enemies), Potion of Venomous Blood, and
  Superior and Major Frenzy and Spellblasting potions (attack power, spell damage).
- **Profession stations at 300.** Each profession gets a placeable station (Alchemy Laboratory,
  Master Forge, Arcane Forge, Loom, Sewing Machine, Iron Oven, Anarchist's Workbench, Plague
  Doctor's Laboratory) that "the most advanced recipes" require. Each needs one rare drop (Lich's
  Index Finger, Golem Heart, Maker's Spark, Bottled Screams, Undeath Engine, Essence of Anarchy).
  Every crafter who reaches 300 wants one once.
- **Hard price ceilings.** A reagent vendor (Mister Graphed, Powderfuse Port) reportedly sells Black
  Lotus and Arcane Crystal at a fixed 50 gold with no limit (beta reports, not confirmed in play).
  Neither can sell above that, so holding them can never earn more than the gap to 50g.
- **Vendor-only inputs.** The Imbued Vial, needed by the new flasks and top potions, is reportedly
  sold only by Alchemy and Herbalism suppliers and is made by no recipe. Its cost is a fixed sink.
  Exquisite Spices (feasts) describes itself as "a highly regulated commodity; buying or selling
  directly would be unwise", which suggests it can't be traded.
- **No realms.** There is one auction house per region, ruleset and faction (fan sites disagree on
  six or eight per region, and on whether rulesets share). That makes a shared price far more
  meaningful than on per-realm Classic: everyone in a market sees the same auction house.
- **No WoW Token.** Gold only comes from play (and from gold sellers, which Blizzard is banning).

## The calendar of demand

| When | What opens | Demand it creates |
|---|---|---|
| Now to mid-December | Levelling, dungeons, professions to 300 | Levelling materials by tier; first dungeon consumables |
| **December 9** | Barrow Deeps (10), Hyjal Summit (20), Onyxia's Lair (40, attunement) | Natural flasks (Death Lotus, Demonsage, Marefoil), elixirs, food, mana and healing potions every raid week; enchants for raid gear; **fire resistance for Onyxia** |
| Winter 2026 | Hardcore ruleset | Probably its own fresh market, with the same curve starting again from zero (unconfirmed) |
| Spring 2027 | 2 raids (10 and 20), 2 dungeons, legendary questline, **PvP season refresh** | PvP consumables spike at the season start; new gear means re-enchanting |
| Summer 2027 | Revamped raid, new raid, **PvP season refresh, professions and Legacy update** | Same again, plus the risk that new recipes change what is used |

Raids reset weekly. Onyxia's Lair needs fire resistance; Blizzard's raid post says to build it
before entering, which points at Greater Fire Protection Potion (Elemental Fire, Dreamfoil), Enchant
Cloak - Greater Fire Resistance (4 Essence of Fire, 3 Nexus Crystal, 8 Large Brilliant Shard) and
crafted fire resistance gear.

## What gets used later, by reason

The tables count how many endgame recipes use a reagent. That is breadth, not volume: a reagent in
one flask that every raider drinks weekly matters more than one in ten enchants nobody picks.

### Raid power (from December 9, weekly)

| Reagent | Endgame recipes | What uses it |
|---|---|---|
| Dreamfoil | 23 | Major Mana Potion, all five Greater Protection potions, Flask of Distilled Wisdom and Supreme Power (30 each), Flask of Natural Precision (9), Elixirs of the Owl, Sages, Whale, Cunning, Major Mender's and Spellblasting potions |
| Gromsblood | 12 | Flask of the Titans (30), Mighty Rage Potion, Elixirs of the Grizzly, Ferocity, Brute Force, Greater Strength |
| Plaguebloom | 10 | Mongoose, Brute Force, Mageblood, Major Troll's Blood, Major Frenzy Potion |
| Mountain Silversage | 10 | Flask of Supreme Power and Petrification (10 each), Natural Aggression (6), Mongoose, Owl, Greater Arcane Elixir |
| Sungrass | 9 | Elixir of the Phalanx, Greater Defense, Greater Strength, Superior Mana and Healing potions |
| Golden Sansam | 8 | Flask of Natural Accuracy (9), Major Rejuvenation, Elixirs of Nature Power, Sages, Wicked Regeneration, **Major Healing Potion (First Aid)** |
| Icecap | 7 | Flask of Chromatic Resistance (30), Distilled Wisdom (10), Natural Swiftness (6), Major Mana Potion, Living Action Potion |
| Death Lotus | 7 | All four Natural flasks, Enchant Gloves - Natural Power (4) and Lotus Claw |
| Demonsage / Marefoil | 2 / 3 | Natural flasks only (12 to 18 each), Marefoil also in the Stealthman 52 scope |

### PvP (honor ranks and battlegrounds, all the time; spikes at each season refresh)

| Consumable | Made from |
|---|---|
| Free Action Potion | 2 Blackmouth Oil, 1 Stranglekelp, Leaded Vial |
| Swiftness Potion | Swiftthistle, Briarthorn, Empty Vial |
| Limited Invulnerability Potion | 2 Blindweed, Ghost Mushroom, Crystal Vial |
| Living Action Potion | 2 Icecap, 2 Mountain Silversage, 2 Heart of the Wild |
| Greater Stoneshield Potion | 3 Stonescale Oil, Thorium Ore |
| EZ-Thro Thorium Grenade (any class) | Thorium Widget, 3 Thorium Bar, 3 Dense Blasting Powder, 3 Runecloth, EZ-Thro Shell (2 Thorium Bar, Pristine Scale) |
| Caustic Smog Potion | 2 Ghost Mushroom, 2 Grave Moss |
| Potion of Predatory Senses | 2 Fadeleaf, 2 Goldthorn |
| Superior / Major Frenzy Potion | Blindweed with Firebloom, or Plaguebloom |
| Major Healing Potion (First Aid) | Golden Sansam, 3 Soothing Spices |
| Heavy Runecloth Bandage | 2 Runecloth |

Runecloth appears in 12 endgame recipes (7 of them PvP), Thorium Bar in 10, Dense Blasting Powder
in 8, Blindweed in 6 (4 PvP).

### Enchants (per gear piece; again with every new tier)

| Reagent | Enchants using it |
|---|---|
| Large Brilliant Shard | 46 |
| Illusion Dust | 32 |
| Greater Eternal Essence | 30 |
| Nexus Crystal | 24 |
| Dream Dust | 17 (the 250 to 290 tier) |
| Essence of Air, Water, Fire, Earth, Undeath | 5 to 9 each |
| Righteous Orb | 8, including Crusader and the new Grand Crusader, Grand Sorcerer and Grand Inquisitor |
| Blood of Heroes | 6, all the new "Grand" enchants |

Enchanting materials come from disenchanting gear, so their supply grows as more players run
dungeons and raids. Demand grows too, but their price is more two-sided than the herbs'.

### Levelling professions (front-loaded, then a steady trickle from alts)

Levelling demand peaks while most of the population is crossing each skill tier, which is now. It
then comes from alts. Every profession burns its tier's materials (copper, then tin and bronze, and
so on). Two Forever-specific points:

- First Aid now levels through healing potions, so it eats Peacebloom, Briarthorn, Bruiseweed,
  Liferoot, Sungrass and Golden Sansam as well as cloth.
- Alchemy's trainer path runs through the Discolored healing potions, so Murloc Eye is a steady
  levelling input.

The script's levelling path is only rough, because the client data doesn't say a recipe's required
skill or where it is learned. Reading the trainer window in game would fix that (see open questions).

## Your three examples, checked

| Item | Endgame use | Levelling use |
|---|---|---|
| Swiftthistle | Swiftness Potion (PvP) | Elixir of Minor and Lesser Agility, Swim Speed, Holy Protection Potion, Thistle Tea, the Fermenter |
| Blackmouth Oil | Free Action Potion (2 each) | Water Breathing, Water Walking, Swim Speed, Enchant Gloves - Fishing |
| Stranglekelp | Free Action Potion (1 each) | Lesser Mana Potion, Mana Potion, Elixir of Agility, Lesser Defense, Nature Protection |

All three are right, and the reason is more specific than "prices go up". Their only endgame use is
two PvP potions, Free Action and Swiftness, that players drink constantly. Meanwhile their supply
comes from players passing through low-level zones (Blackmouth Oil is made from Oily Blackmouth,
which you fish). As the population moves to 60, fewer people gather in those zones, while PvP and
alt demand stays. Low-level materials that feed an endgame consumable are the strongest
"buy now, hold" pattern, because supply falls while demand holds. Herbs stack to 20 in Forever, so
bank space limits how much you can hold.

## Watchlist to test (hypotheses, not findings)

Buyable now, in rough order:

0. **Meat for the +20 buff foods.** Tiger Meat, Red Wolf Meat, Turtle Meat, Big Bear Meat, Stag
   Meat, Raptor Flesh, Buzzard Wing, Meaty Bat Wing (see "Beyond herbs").
1. **Low PvP potion inputs.** Swiftthistle, Briarthorn, Oily Blackmouth and Blackmouth Oil,
   Stranglekelp, Grave Moss. Supply falls as players leave those zones; Free Action, Swiftness,
   Thistle Tea and Caustic Smog keep demand at 60.
2. **Healer and caster raid herbs from the 40s.** Arthas' Tears, Purple Lotus, Firebloom (Greater
   Cleric's Elixir, Brilliant Mana and Wizard Oils, Greater Mageblood).
3. **Blindweed and Ghost Mushroom.** Limited Invulnerability Potion for PvP, Major Frenzy for raids.
4. **Goldthorn and Fadeleaf.** Restorative Potion and the new Predatory Senses, both PvP.
5. **Elemental Fire and Earth.** Fire protection for Onyxia on December 9; Restorative Potion.
6. **Sungrass, Khadgar's Whisker, Wintersbite.** Greater Fortitude, Phalanx, Greater Intellect,
   Spellblasting.
7. **Mid enchanting mats.** Dream Dust, Large Radiant Shard, Greater Nether Essence.

Later, once they can be bought: Death Lotus, Demonsage, Marefoil (December 9 flasks); Dreamfoil and
Golden Sansam (the widest endgame herbs; Golden Sansam also makes Major Healing Potions for every
First Aid player); grenade inputs (Thorium Bar, Dense Stone, Runecloth, Pristine Scale, because every
class can throw EZ-Thro grenades); profession station components.

Don't hold:

- **Black Lotus and Arcane Crystal.** Capped at the vendor's 50g. Buy below it only to use.
- **Imbued Vial, spices, vials, thread.** Vendor items with a fixed price.

Risks to every hold: Blizzard can change drop rates or recipes (a professions update is on the
summer 2027 roadmap); the auction house takes a deposit and a cut; and gold is worth most early,
when it could instead buy a mount or training.

## How the addon could work

### Getting prices

- **In-game scans.** Forever's auction house uses the retail-style interface, so an addon reads it
  with the retail auction API. Whether a full-house scan (`C_AuctionHouse.ReplicateItems`) is enabled
  is not confirmed: test it with a probe addon, as was done for the combat log.
- **Shared scans.** Because each market is one auction house per region, ruleset and faction, scans
  from many players can be merged into one price per market. The MindCollector desktop app already
  watches the Forever folder, so it could upload the addon's saved scans and write a compact price
  and forecast table back into the addon folder, read at the next login or `/reload` (addons can't
  make web requests).
- **Official API.** No Blizzard auction API for Forever was found. It is worth checking on
  develop.battle.net with real credentials (the local `.env` has none set).

### Forecasting demand

For each item, demand over time is the sum, over every recipe that uses it, of:

> players able to use it (by level and activity) × how often they use it × units per craft

The recipe graph (`tools/forever-market/`) supplies the last part for every item. The calendar
supplies dates when a recipe's users switch on (raids on December 9, PvP seasons). The first two
parts are estimates, so the forecast should show a range and say what it assumed. Population can be
estimated from shared scans (sellers per item over time) or sampled `/who` counts by level, both
untested.

Following VISION.md's validation rule, every forecast is stored when it is made and checked against
the price later. A forecast nobody checks is just a story.

### What it shows

- **Item chart:** price over time, the same price divided by the market basket (the real price), the
  vendor ceiling and floor as flat lines, event markers (Dec 9 raids, PvP season starts), and the
  demand forecast as a shaded band.
- **Outlook board:** items sorted by how much demand is expected to grow against today's price, with
  the reason in plain words ("used in Flask of Natural Aggression ×18; raids open Dec 9").
- **Tooltip in game:** current price, 7-day trend, the outlook in one line, and a "capped at 50g"
  note where a vendor ceiling exists.

### What already exists

- **WoW Forever Tracker** (CurseForge, 2.2K downloads, updated 2026-11-05): shared auction scans,
  price and supply in tooltips, 7- and 30-day trends, sales per day, undercut warnings, watchlist
  with phone alerts. No forecasts.
- **AHledger** (ahledger.com): a Forever auction house and price site.

Both track prices. Neither says why an item will be wanted or when. The recipe graph, the calendar
and the inflation-adjusted price are the parts no one has.

## Open questions

1. Does `C_AuctionHouse.ReplicateItems` work in Forever? (Probe addon.)
2. Where do Death Lotus, Demonsage and Marefoil grow, and how common are they? (Play or Wowhead
   Forever once it has drop data.)
3. Is the Black Lotus and Arcane Crystal vendor live at 50g with no limit? (Visit Powderfuse Port.)
4. How many auction houses per region, and do rulesets share? (Two fan sites disagree.)
5. Which recipes need the 300 stations, and what do they make?
6. Is Hardcore a separate market?
7. Each recipe's required skill and source (trainer, vendor, drop): read the trainer window in game,
   because the client data doesn't hold it.

## Re-running the numbers

```bash
# download the tables from wago.tools (product wowf) into a folder, then:
python tools/forever-market/recipes.py <folder>   # builds graph.json
python tools/forever-market/demand.py <folder>    # levelling path and endgame recipes, demand.json
python tools/forever-market/rank.py <folder>      # reagents by endgame and PvP recipe count
python tools/forever-market/levelling_now.py <folder> 52   # levelling-tier materials and their uses at 60
cd tools/forever-market && python non_herb.py <folder> 52   # non-herb materials, gear included; tradeable recipes
```

`levelling_now.py` counts a consumable as used at 60 if it needs level 35 or more, or if it is on a
hand-written list of low-level consumables that stay in use (Free Action, Swiftness, grenades,
protection potions). That list is a judgment call, so correct it from play.

Tables: `SkillLine`, `SkillLineAbility`, `SpellReagents`, `SpellEffect`, `SpellName`, `Spell`,
`ItemSparse`, `Item`, `ItemEffect`, `ItemXItemEffect`, from
`https://wago.tools/db2/<Table>/csv?product=wowf`. `SpellEffect` sometimes returns a Cloudflare 525;
retrying works.

## Sources

- Recipe and item data: wago.tools DB2 export, product `wowf` (downloaded 2026-11-07)
- [Blizzard: Forever raid overview](https://news.blizzard.com/en-gb/article/24303009/world-of-warcraft-forever-raid-overviewlocations-raid-sizes-and-attunement)
- Roadmap: [Icy Veins](https://www.icy-veins.com/wow-forever/roadmap), [Maxroll](https://maxroll.gg/wow-forever/news/wow-forever-roadmap-new-zones-raids-dungeons-and-more)
- Black Lotus vendor: [Blizzard forums](https://us.forums.blizzard.com/en/wow/t/what-the-heck-a-vendor-who-sells-black-lotus-is-in-the-game/2354675), [wowclassicforever.info](https://wowclassicforever.info/news/black-market-auction-house/)
- Imbued Vial and Merchant's Favor: [wow-professions.com](https://www.wow-professions.com/forever/merchants-favor-durotar-supply-azeroth-commerce), [Mobalytics alchemy guide](https://mobalytics.gg/wow-forever/guides/alchemy-profession-guide)
- Auction house structure: [AHledger, no realms](https://ahledger.com/guides/no-realms-what-it-changes), [Forever Guides](https://theforeverguides.com/gold-making/auction-house/), [AzerothVault](https://azerothvault.com/forever)
- Economy and gold sinks: [timesaver.gg](https://timesaver.gg/blog/wow-forever-gold-inflation-economy-explained) (a gold seller, so biased)
- [WoW Forever Tracker on CurseForge](https://www.curseforge.com/wow/addons/wow-forever-tracker)
