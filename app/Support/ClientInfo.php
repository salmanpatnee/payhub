<?php

namespace App\Support;

/**
 * A short, human label for a browser user agent, such as "Chrome on Windows, desktop". Only the
 * browser family, system family and form factor are kept, never the raw user agent.
 */
class ClientInfo
{
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return 'Unknown browser';
        }

        $browser = match (true) {
            (bool) preg_match('~Edg(e|A|iOS)?/~i', $ua) => 'Edge',
            (bool) preg_match('~OPR/|Opera~i', $ua) => 'Opera',
            (bool) preg_match('~SamsungBrowser/~i', $ua) => 'Samsung Internet',
            (bool) preg_match('~(Firefox|FxiOS)/~i', $ua) => 'Firefox',
            (bool) preg_match('~(Chrome|CriOS|Chromium)/~i', $ua) => 'Chrome',
            (bool) preg_match('~Safari/~i', $ua) => 'Safari',
            default => 'Unknown browser',
        };

        $system = match (true) {
            (bool) preg_match('~iPhone|iPad|iPod~i', $ua) => 'iOS',
            (bool) preg_match('~Android~i', $ua) => 'Android',
            (bool) preg_match('~Windows~i', $ua) => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~i', $ua) => 'macOS',
            (bool) preg_match('~Linux|X11|CrOS~i', $ua) => 'Linux',
            default => null,
        };

        // An iPad's user agent also says "Mobile", so tablets are checked first.
        $device = match (true) {
            (bool) preg_match('~iPad|Tablet~i', $ua) => 'tablet',
            (bool) preg_match('~Mobi|iPhone|iPod~i', $ua) => 'mobile',
            (bool) preg_match('~Android~i', $ua) => 'tablet', // an Android browser without "Mobile" is a tablet
            default => 'desktop',
        };

        return $browser.($system ? " on {$system}" : '').", {$device}";
    }
}
