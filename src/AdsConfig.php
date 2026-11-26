<?php

declare(strict_types=1);

namespace NolvikoExt\Ads;

use Nolviko\Core\Http\CspNonce;
use RuntimeException;
use Throwable;

/**
 * Ads, ads.txt and the ad settings (2026-11-26) in one JSON file in the extension's private storage directory:
 * pages read it on every view, so no database query is needed to show an ad, and the Content-Security-Policy hosts
 * are known while the extension registers. Every value is cleaned on load and on save.
 */
final class AdsConfig
{
    /** Position => [theme slot, takes "after N" / "every N"]. */
    public const POSITIONS = [
        'below_menu' => ['layout.content.before', false],
        'content_end' => ['layout.content.after', false],
        'forum_index' => ['forum.row.after', true],
        'thread_list' => ['forum.thread.after', true],
        'between_posts' => ['thread.post.after', true],
        'thread_end' => ['thread.posts.after', false],
        'head' => ['layout.head', false],
    ];

    public const TYPES = ['code', 'image', 'text'];

    public const AUDIENCES = ['all', 'guests', 'members'];

    public const DEVICES = ['all', 'mobile', 'desktop'];

    /** Google AdSense / Ad Manager hosts (Google's CSP guidance), added when the AdSense option is on. */
    public const ADSENSE_HOSTS = [
        'script-src' => ['https://pagead2.googlesyndication.com', 'https://*.googlesyndication.com',
            'https://*.doubleclick.net', 'https://*.google.com', 'https://*.gstatic.com',
            'https://*.adtrafficquality.google', 'https://*.googletagservices.com'],
        'frame-src' => ['https://*.googlesyndication.com', 'https://*.doubleclick.net', 'https://*.google.com',
            'https://*.adtrafficquality.google'],
        'img-src' => ['https://*.googlesyndication.com', 'https://*.doubleclick.net', 'https://*.google.com',
            'https://*.gstatic.com', 'https://*.adtrafficquality.google'],
        'connect-src' => ['https://*.googlesyndication.com', 'https://*.doubleclick.net', 'https://*.google.com',
            'https://*.adtrafficquality.google'],
    ];

    public const MAX_ADS = 100;

    private const FILE = '/ads.json';

    /** @var array{ads: list<array<string, mixed>>, ads_txt: string, label: bool, adsense: bool,
     *     hosts: list<string>, excluded: list<string>} */
    public array $data;

    private function __construct(private readonly string $directory, array $data)
    {
        $this->data = self::clean($data);
    }

    public static function load(string $directory): self
    {
        try {
            $raw = is_file($directory . self::FILE) ? file_get_contents($directory . self::FILE) : false;
            $data = is_string($raw) ? json_decode($raw, true, 8) : [];
        } catch (Throwable) {
            $data = [];
        }

        return new self($directory, is_array($data) ? $data : []);
    }

    public function save(array $data): void
    {
        $this->data = self::clean($data);
        $temporary = $this->directory . '/.ads-' . bin2hex(random_bytes(6)) . '.json';
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR);
        if (
            file_put_contents($temporary, $json . "\n", LOCK_EX) === false
            || !rename($temporary, $this->directory . self::FILE)
        ) {
            @unlink($temporary);
            throw new RuntimeException('nolviko_ads_unwritable');
        }
    }

    /** @return array<string, list<string>> CSP directive => hosts, for ExtensionContext::contentSecurity(). */
    public function sources(): array
    {
        $sources = $this->data['adsense'] ? self::ADSENSE_HOSTS : [];
        foreach ($this->data['hosts'] as $host) {
            foreach (['script-src', 'frame-src', 'img-src', 'connect-src'] as $directive) {
                $sources[$directive][] = $host;
            }
        }

        return array_map(static fn(array $list): array => array_values(array_unique($list)), $sources);
    }

    /** @return array<string, mixed> One ad with every field cleaned; invalid input falls back to defaults. */
    public static function cleanAd(array $ad): array
    {
        $text = static fn(string $key, int $max): string => mb_substr(trim(is_string($ad[$key] ?? null)
            ? str_replace("\r\n", "\n", $ad[$key]) : ''), 0, $max);
        $choice = static fn(string $key, array $allowed): string => in_array($ad[$key] ?? null, $allowed, true)
            ? $ad[$key] : $allowed[0];
        $id = is_string($ad['id'] ?? null) && preg_match('/^[a-f0-9]{12}$/D', $ad['id']) === 1 ? $ad['id']
            : bin2hex(random_bytes(6));
        $date = static fn(string $key): string => is_string($ad[$key] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ad[$key]) === 1 ? $ad[$key] : '';
        $url = static function (string $key) use ($text): string {
            $value = $text($key, 2000);

            return preg_match('~^(https?://[^\s"<>]+|/[^\s"<>]*)$~iD', $value) === 1 ? $value : '';
        };

        return [
            'id' => $id,
            'name' => $text('name', 120) !== '' ? $text('name', 120) : $id,
            'enabled' => ($ad['enabled'] ?? true) === true,
            'position' => $choice('position', array_keys(self::POSITIONS)),
            'after' => max(1, min(100, (int) ($ad['after'] ?? 1))),
            'repeat' => ($ad['repeat'] ?? false) === true,
            'type' => $choice('type', self::TYPES),
            'code' => $text('code', 20000),
            'image' => $url('image'),
            'link' => $url('link'),
            'alt' => $text('alt', 200),
            'title' => $text('title', 120),
            'body' => $text('body', 500),
            'audience' => $choice('audience', self::AUDIENCES),
            'device' => $choice('device', self::DEVICES),
            'start' => $date('start'),
            'end' => $date('end'),
            'weight' => max(1, min(10, (int) ($ad['weight'] ?? 1))),
        ];
    }

    private static function clean(array $data): array
    {
        $ads = [];
        foreach (is_array($data['ads'] ?? null) ? array_slice($data['ads'], 0, self::MAX_ADS) : [] as $ad) {
            if (is_array($ad)) {
                $ads[] = self::cleanAd($ad);
            }
        }
        $hosts = [];
        foreach (is_array($data['hosts'] ?? null) ? $data['hosts'] : [] as $host) {
            if (is_string($host) && CspNonce::validSource('script-src', strtolower(trim($host)))) {
                $hosts[] = strtolower(trim($host));
            }
        }
        $excluded = [];
        foreach (is_array($data['excluded'] ?? null) ? $data['excluded'] : [] as $path) {
            if (is_string($path) && preg_match('~^/[^\s]{0,200}$~D', trim($path)) === 1) {
                $excluded[] = trim($path);
            }
        }
        $adsTxt = is_string($data['ads_txt'] ?? null) ? str_replace("\r\n", "\n", $data['ads_txt']) : '';

        return [
            'ads' => $ads,
            'ads_txt' => mb_substr((string) preg_replace('/[^\P{C}\n\t]/u', '', $adsTxt), 0, 20000),
            'label' => ($data['label'] ?? true) === true,
            'adsense' => ($data['adsense'] ?? false) === true,
            'hosts' => array_slice(array_values(array_unique($hosts)), 0, 16),
            'excluded' => array_slice(array_values(array_unique($excluded)), 0, 50),
        ];
    }
}
