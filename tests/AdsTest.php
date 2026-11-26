<?php

declare(strict_types=1);

namespace NolvikoExt\Ads\Tests;

use NolvikoExt\Ads\AdsConfig;
use NolvikoExt\Ads\AdsRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Run from the workspace root (not part of the ZIP):
 *   php dev/vendor/bin/phpunit --bootstrap dev/vendor/autoload.php extensions-src/nolviko/ads/tests
 */
final class AdsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/src/AdsConfig.php';
        require_once dirname(__DIR__) . '/src/AdsRenderer.php';
    }

    public function testPositionsAudienceDatesAndNonce(): void
    {
        $config = $this->config([
            ['name' => 'Posts', 'position' => 'between_posts', 'after' => 3, 'repeat' => true, 'type' => 'code',
                'code' => '<ins class="adsbygoogle"></ins>'
                    . '<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>'],
            ['name' => 'Menu', 'position' => 'below_menu', 'type' => 'image', 'image' => 'https://cdn.test/a.png',
                'link' => 'https://shop.test/', 'alt' => 'Shop "now"', 'audience' => 'guests', 'end' => '2026-12-31'],
        ]);

        self::assertSame('', $this->render($config, 'thread.post.after', ['position' => 2])->html);
        $html = $this->render($config, 'thread.post.after', ['position' => 6])->html;
        self::assertStringContainsString('<script nonce="N0">(adsbygoogle', $html);
        self::assertStringContainsString('<span class="nv-ad-label">Ad</span>', $html);

        $menu = $this->render($config, 'layout.content.before', [])->html;
        self::assertStringContainsString('href="/click/' . $config['ads'][1]['id'] . '"', $menu);
        self::assertStringContainsString('alt="Shop &quot;now&quot;"', $menu);
        self::assertStringContainsString('rel="sponsored noopener"', $menu);
        self::assertSame('', $this->render($config, 'layout.content.before', [], 'member')->html);
        self::assertSame('', $this->render($config, 'layout.content.before', [], 'guest', '/', '2027-01-01')->html);
        self::assertSame('', $this->render($config, 'thread.post.after', ['position' => 3], 'hidden')->html);
    }

    public function testNoAdsOnScreensWithoutContent(): void
    {
        $config = $this->config([['position' => 'below_menu', 'type' => 'text', 'title' => 'T', 'link' => '/x']]);
        $config['excluded'] = ['/calendar'];

        foreach (
            ['/login', '/register', '/account/security', '/admin', '/ext/nolviko/ads/admin', '/conversations/4',
            '/calendar/2026-11'] as $path
        ) {
            self::assertSame('', $this->render($config, 'layout.content.before', [], 'guest', $path)->html, $path);
        }
        self::assertNotSame('', $this->render($config, 'layout.content.before', [], 'guest', '/threads/a-1')->html);
    }

    public function testListSlotsUseListItemsAndRotationIsWeighted(): void
    {
        $config = $this->config([
            ['name' => 'A', 'position' => 'forum_index', 'after' => 2, 'type' => 'text', 'title' => 'A',
                'link' => '/a'],
            ['name' => 'B', 'position' => 'forum_index', 'after' => 2, 'type' => 'text', 'title' => 'B', 'link' => '/b',
                'weight' => 3],
        ]);

        $first = $this->render($config, 'forum.row.after', ['position' => 2], 'guest', '/', '2026-11-26', 0.1)->html;
        $last = $this->render($config, 'forum.row.after', ['position' => 2], 'guest', '/', '2026-11-26', 0.9)->html;
        self::assertStringStartsWith('<li class="nv-ad nv-ad-forum_index"', $first);
        self::assertStringContainsString('<strong>A</strong>', $first);
        self::assertStringContainsString('<strong>B</strong>', $last);
    }

    public function testCleaningRefusesUnsafeValues(): void
    {
        $ad = AdsConfig::cleanAd(['image' => 'javascript:alert(1)', 'link' => 'https://ok.test/a b', 'after' => 999,
            'position' => 'sidebar', 'start' => '26.11.2026']);

        self::assertSame('', $ad['image']);
        self::assertSame('', $ad['link']);
        self::assertSame(100, $ad['after']);
        self::assertSame('below_menu', $ad['position']);
        self::assertSame('', $ad['start']);
        self::assertSame(1, preg_match('/^[a-f0-9]{12}$/', $ad['id']));
    }

    /** @param list<array<string, mixed>> $ads */
    private function config(array $ads): array
    {
        return ['ads' => array_map([AdsConfig::class, 'cleanAd'], $ads), 'label' => true, 'excluded' => []];
    }

    private function render(
        array $config,
        string $slot,
        array $context,
        string $viewer = 'guest',
        string $path = '/threads/a-1',
        string $today = '2026-11-26',
        float $random = 0.5,
    ): \Nolviko\Core\View\SafeHtml {
        return AdsRenderer::render(
            $config,
            $slot,
            $context,
            $viewer,
            $path,
            'N0',
            $today,
            'Ad',
            static fn(string $id): string => '/click/' . $id,
            static fn(): float => $random,
        );
    }
}
