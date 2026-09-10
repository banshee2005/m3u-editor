<?php

namespace App\Services;

use App\Models\EpisodeKeywordRule;
use App\Support\RuleExpressionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Synthesizes a stable XMLTV episode identity for programmes that match a
 * user's episode keyword rules (NextPVR-style expressions, e.g.
 * `title like '%CFL Football%'`).
 *
 * Providers frequently omit <episode-num> for live sports, so every airing of
 * a game (live, next-day, replays, and every channel that carries it) looks
 * like a distinct programme to downstream DVRs like NextPVR. This prevents
 * those DVRs from de-duplicating recordings.
 *
 * The service gives each *game* a stable xmltv_ns episode value derived from
 * its normalized title and the game date embedded in the description. Because
 * the value is deterministic, all airings of the same game resolve to the same
 * episode (so "avoid duplicate recordings" can collapse them), while different
 * games resolve to different episodes.
 */
final class EpisodeKeywordRuleService
{
    /**
     * @param  Collection<int, EpisodeKeywordRule>  $rules
     */
    public function __construct(private readonly Collection $rules) {}

    /**
     * Build a service scoped to a playlist's owner. Playlist-specific rules win
     * over the user's unscoped (global) rules when both match. Rules are loaded
     * once per EPG generation pass so the streaming programme loop stays
     * N+1-free.
     */
    public static function forPlaylist(int $userId, ?int $playlistId): self
    {
        $query = EpisodeKeywordRule::query()
            ->where('user_id', $userId)
            ->where('enabled', true);

        if ($playlistId !== null) {
            $query->where(fn ($q) => $q->whereNull('playlist_id')->orWhere('playlist_id', $playlistId));
        } else {
            $query->whereNull('playlist_id');
        }

        // Most specific first: playlist-bound rules before unscoped rules.
        $rules = $query
            ->orderByRaw('CASE WHEN playlist_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->get();

        return new self($rules);
    }

    public function hasRules(): bool
    {
        return $this->rules->isNotEmpty();
    }

    /**
     * Synthesize an episode identity for a programme, or return null when no
     * rule matches. Programmes that already carry a real episode identity are
     * the caller's responsibility to skip before calling this.
     *
     * The returned title is the programme title with broadcast markers stripped
     * (e.g. "ᴸᶦᵛᵉ"/"ᴺᵉʷ"). NextPVR builds its episode identity from title +
     * season + episode, so emitting the normalized title here is what lets all
     * airings of a game collapse into one recording.
     *
     * @param  array{title?: mixed, desc?: mixed}  $programme
     * @return array{episode_nums: list<array{system: string, value: string}>, date: string|null, title: string}|null
     */
    public function synthesizeForProgramme(array $programme): ?array
    {
        $title = trim((string) ($programme['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $desc = trim((string) ($programme['desc'] ?? ''));
        $fields = [
            'title' => $title,
            'description' => $desc,
        ];

        $rule = $this->rules->first(function (EpisodeKeywordRule $rule) use ($fields): bool {
            try {
                return RuleExpressionParser::matches($rule->expression, $fields);
            } catch (InvalidArgumentException $e) {
                Log::warning('Skipping malformed episode keyword rule', [
                    'rule_id' => $rule->id,
                    'expression' => $rule->expression,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        });

        if ($rule === null) {
            return null;
        }

        $date = self::extractGameDate($desc);
        $identityKey = self::buildIdentityKey($title, $desc, $date);

        return [
            'episode_nums' => [[
                'system' => 'xmltv_ns',
                'value' => self::episodeValue($identityKey, $date),
            ], [
                'system' => 'dd_progid',
                'value' => self::ddProgidValue($identityKey),
            ]],
            'date' => $date,
            'title' => self::stripBroadcastMarkers($title),
        ];
    }

    /**
     * Build a stable, per-game identity key from a programme's title and
     * description.
     *
     * Requirements:
     *  - Same game, any airing (live, next-day, replay, any channel) → same key.
     *  - Different game → different key, even when the title is identical
     *    (teams play each other multiple times per season).
     *
     * The title is normalized (lowercased, broadcast markers stripped) so that
     * "LIVE", "NEW", and unadorned listings of the same game produce the same
     * key. When the provider embeds a game date in the description — which it
     * does for the user's feed — that date is identical for every airing of the
     * game and distinct across games, so it is the primary disambiguator. When
     * no date is present, a normalized fragment of the description is included
     * instead so a same-title rematch still resolves to a different episode.
     */
    public static function buildIdentityKey(string $title, string $desc, ?string $date): string
    {
        $key = self::normalizeTitle($title);

        if ($date !== null) {
            return $key.'|'.$date;
        }

        return $key.'|'.self::normalizeDescription($desc);
    }

    /**
     * Build the xmltv_ns episode value ("S.E.") for an identity key.
     *
     * The season is the game's year (or the current year when the provider
     * gave no date); the episode is a stable 32-bit hash of the identity key.
     * The pair is identical for every airing of a game and distinct across
     * games, which is all a DVR's duplicate check needs.
     */
    public static function episodeValue(string $identityKey, ?string $date): string
    {
        $year = $date !== null ? substr($date, 0, 4) : (string) date('Y');
        $episode = sprintf('%u', crc32($identityKey));

        return "{$year}.{$episode}.";
    }

    /**
     * Build a dd_progid-style unique identifier for NextPVR.
     *
     * NextPVR derives its internal unique_id from <episode-num system="dd_progid">
     * entries. The format is "EP{high32}.{low32}" where the values come from a
     * stable hash of the identity key. This gives every airing of the same game
     * the same unique_id, allowing "avoid duplicate recordings" to collapse them.
     */
    public static function ddProgidValue(string $identityKey): string
    {
        $hash = crc32($identityKey);
        // Split the 32-bit hash into two 16-bit halves for dd_progid format.
        $high = ($hash >> 16) & 0xFFFF;
        $low = $hash & 0xFFFF;

        return sprintf('EP%05d.%04d', $high, $low);
    }

    /**
     * Extract the game date from a description. Providers embed it at the end
     * of the description (e.g. "... (Football) - 2026-08-23"). The last
     * YYYY-MM-DD occurrence wins so mid-description dates don't win over the
     * trailing game date.
     */
    public static function extractGameDate(string $desc): ?string
    {
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $desc, $matches, PREG_SET_ORDER) > 0) {
            $last = end($matches);

            return "{$last[1]}-{$last[2]}-{$last[3]}";
        }

        return null;
    }

    /**
     * Normalize a title for identity comparison: strip broadcast markers and
     * lowercase the result.
     */
    public static function normalizeTitle(string $title): string
    {
        return mb_strtolower(self::stripBroadcastMarkers($title));
    }

    /**
     * Strip broadcast markers providers append to listings, preserving the
     * original casing so the result can be used as a display title. Markers
     * include the Unicode phonetic/superscript runs used for "LIVE"/"NEW"
     * (e.g. "ᴸᶦᵛᵉ"/"ᴺᵉʷ") as well as plain ASCII suffixes.
     */
    public static function stripBroadcastMarkers(string $title): string
    {
        // Unicode phonetic-extension blocks + modifier letters (ᴸᶦᵛᵉ / ᴺᵉʷ, ...).
        $normalized = preg_replace('/\s*[\x{1D00}-\x{1DBF}\x{02B0}-\x{02FF}]+$/u', '', $title) ?? $title;

        // Plain ASCII suffixes.
        $normalized = preg_replace('/\s*-\s*(LIVE|NEW|REPLAY|ENCORE)$/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+(LIVE|NEW|REPLAY|ENCORE)$/iu', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Normalize a description for identity fallback: lowercase, collapse
     * whitespace, and cap the length so minor provider edits don't churn the
     * identity key when no game date is available.
     */
    public static function normalizeDescription(string $desc): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $desc) ?? $desc;

        return mb_substr(mb_strtolower(trim($normalized)), 0, 300);
    }
}
