<?php

namespace Coins\Media;

/**
 * How a coin image downloaded from bank.gov.ua is identified and named locally.
 *
 * Two NBU quirks drive this:
 *
 * - **Every image URL carries a `?v=N` cache-buster that NBU bumps site-wide** (17 → 18 → 19 so
 *   far). Deduplicating on the raw URL re-downloaded the whole catalog's images after each bump, so
 *   the identity of an image is its URL *without* the query string.
 * - **Commemorative-coin photos all share two basenames**: `/media/coins/{nbuId}/avers.jpg` and
 *   `/revers.jpg`. Saved under that basename they collide in the uploads month folder, and
 *   `convert-to-webp.php` used to resolve such a collision by pointing the newcomer at the other
 *   coin's file. Local names therefore carry the NBU id (`nbu-1722-avers.jpg`).
 */
final class NbuImageSource
{
    /** Post meta on an attachment holding its (normalised) source URL. */
    public const META_KEY = '_coin_source_url';

    public static function normalize(string $url): string
    {
        $url = preg_replace('~[?#].*$~', '', trim($url));

        return (string) $url;
    }

    public static function localFilename(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/media/coins/(\d+)/([^/]+)$~', $path, $m)) {
            return 'nbu-' . $m[1] . '-' . $m[2];
        }

        return basename($path);
    }
}
