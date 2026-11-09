<?php

namespace KraftInk\Plugins\Stats\Services;

class TrafficClient
{
    public static function dimensions(?string $agent): ?array
    {
        $agent = strtolower(trim(substr((string) $agent, 0, 512)));
        if ($agent === '') {
            return ['browser' => 'unknown', 'os' => 'unknown'];
        }
        if (preg_match('~bot\b|crawl|spider|slurp|headless|facebookexternalhit|^curl/|^wget/|^python-requests/~', $agent)) {
            return null;
        }

        $dimensions = ['browser' => 'other', 'os' => self::os($agent)];
        $browsers = [
            'edge' => '(?:edge|edg|edga|edgios)',
            'opera' => '(?:opera|opr|opios)',
            'samsung' => 'samsungbrowser',
            'firefox' => '(?:firefox|fxios)',
            'chrome' => '(?:chrome|crios|chromium)',
        ];
        if (str_contains($agent, 'safari/')) {
            $browsers['safari'] = 'version';
            $dimensions['browser'] = 'safari';
        }
        foreach ($browsers as $name => $token) {
            if (preg_match('~\b'.$token.'/([0-9]+)~', $agent, $match) !== 1) {
                continue;
            }
            $dimensions['browser'] = $name;
            if (preg_match('/^[1-9][0-9]{0,2}$/D', $match[1]) === 1) {
                $dimensions['browser_version'] = $name.'/'.$match[1];
            }

            break;
        }

        return $dimensions;
    }

    private static function os(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'windows') => 'windows',
            str_contains($agent, 'android') => 'android',
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad'), str_contains($agent, 'ipod') => 'ios',
            str_contains($agent, 'cros ') => 'chromeos',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os x') => 'macos',
            str_contains($agent, 'linux') => 'linux',
            default => 'other',
        };
    }
}
