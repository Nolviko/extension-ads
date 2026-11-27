<?php

declare(strict_types=1);

namespace NolvikoExt\Ads;

use Closure;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Modules\Users\Http\RequestSubject;

/**
 * Picks and renders the ad for one slot (2026-11-26). Rules follow the established forum ad managers (phpBB Ads
 * Management, XenForo / Siropu, Discourse): position with "after N" or "every N", audience, device, dates and a
 * weight for rotation. No ads on screens without content (sign-in, registration, account, admin, private
 * messages), as AdSense policy asks. Ad code is the administrator's own HTML; its `<script>` tags get the page's
 * CSP nonce. The output is the same for every guest on a page (rotation changes at most once a minute through the
 * render cache).
 */
final class AdsRenderer
{
    public const EXCLUDED = ['/login', '/register', '/logout', '/password', '/account', '/admin', '/conversations',
        '/install', '/verify-email', '/moderation', '/drafts', '/appearance'];

    /**
     * @param array<string, mixed> $config AdsConfig::$data
     * @param 'guest'|'member'|'hidden' $viewer `hidden`: the member's group may hide ads.
     * @param array<string, mixed> $context The slot context (`position` for list slots).
     * @param callable(string): string $clickUrl Ad ID => click address.
     * @param callable(): float $random 0..1, for weighted rotation.
     * @param list<int> $groups The viewer's groups (1.1.0; guests are in the Guests group).
     */
    public static function render(
        array $config,
        string $slot,
        array $context,
        string $viewer,
        string $path,
        string $nonce,
        string $today,
        string $label,
        callable $clickUrl,
        callable $random,
        array $groups = [],
    ): SafeHtml {
        if ($viewer === 'hidden' || self::excluded($path, $config['excluded'] ?? [])) {
            return new SafeHtml('');
        }
        $position = is_int($context['position'] ?? null) ? $context['position'] : null;
        $candidates = [];
        foreach ($config['ads'] ?? [] as $ad) {
            [$adSlot, $counted] = AdsConfig::POSITIONS[$ad['position']] ?? [null, false];
            if (
                $adSlot !== $slot || !$ad['enabled'] || ($ad['start'] !== '' && $today < $ad['start'])
                || ($ad['end'] !== '' && $today > $ad['end'])
                || ($ad['audience'] === 'guests' && $viewer !== 'guest')
                || ($ad['audience'] === 'members' && $viewer !== 'member')
                || !self::groupAllows($ad, $groups)
                || ($counted && ($position === null || ($ad['repeat'] ? $position % $ad['after'] !== 0
                    : $position !== $ad['after'])))
            ) {
                continue;
            }
            $candidates[] = $ad;
        }
        if ($candidates === []) {
            return new SafeHtml('');
        }
        if ($slot === 'layout.head') {
            return new SafeHtml(implode("\n", array_map(
                static fn(array $ad): string => self::withNonce($ad['code'], $nonce),
                array_filter($candidates, static fn(array $ad): bool => $ad['type'] === 'code'),
            )) . "\n");
        }

        $ad = self::pick($candidates, $random());
        $inner = match ($ad['type']) {
            'image' => $ad['image'] === '' ? '' : '<a href="' . self::e($clickUrl($ad['id']))
                . '" rel="sponsored noopener" target="_blank"><img src="' . self::e($ad['image']) . '" alt="'
                . self::e($ad['alt']) . '" loading="lazy" decoding="async"></a>',
            'text' => '<a class="nv-ad-text" href="' . self::e($clickUrl($ad['id']))
                . '" rel="sponsored noopener" target="_blank"><strong>' . self::e($ad['title']) . '</strong>'
                . ($ad['body'] === '' ? '' : '<span>' . self::e($ad['body']) . '</span>') . '</a>',
            default => self::withNonce($ad['code'], $nonce),
        };
        if ($inner === '') {
            return new SafeHtml('');
        }
        $tag = in_array($slot, ['forum.row.after', 'forum.thread.after'], true) ? 'li' : 'div';
        $classes = 'nv-ad nv-ad-' . $ad['position'] . ($ad['device'] === 'all' ? '' : ' nv-ad-' . $ad['device']);

        return new SafeHtml('<' . $tag . ' class="' . $classes . '" data-ad="' . self::e($ad['id']) . '">'
            . (($config['label'] ?? true) ? '<span class="nv-ad-label">' . self::e($label) . '</span>' : '')
            . $inner . '</' . $tag . '>' . "\n");
    }

    /**
     * The slot output for the current request: guest, member, or a member whose group has `nolviko.ads.hide`.
     * `layout.head` also carries the extension's small stylesheet.
     *
     * @param array<string, mixed> $config
     * @param Closure(): RequestSubject $subjects
     */
    public static function forRequest(
        array $config,
        string $slot,
        array $context,
        CspNonce $nonce,
        ThemeRequest $request,
        Closure $subjects,
        Router $router,
        MessageTranslator $translator,
    ): SafeHtml {
        $current = $request->request;
        if ($current === null) {
            return new SafeHtml('');
        }
        $subject = $subjects()->of($current);
        $viewer = $subject->userId === null ? 'guest' : ($subject->allows('nolviko.ads.hide') ? 'hidden' : 'member');
        $ad = self::render(
            $config,
            $slot,
            $context,
            $viewer,
            $current->getPathInfo(),
            $nonce->value,
            date('Y-m-d'),
            $translator->translate('nolviko.ads.label'),
            static fn(string $id): string => $router->generate('nolviko.ads.click', ['ad' => $id]),
            static fn(): float => mt_rand() / mt_getrandmax(),
            $subject->groupIds,
        );

        return $slot === 'layout.head' && $viewer !== 'hidden'
            ? new SafeHtml(self::style($nonce->value)->html . $ad->html) : $ad;
    }

    /** @param array<string, mixed> $ad @param list<int> $groups */
    public static function groupAllows(array $ad, array $groups): bool
    {
        $chosen = array_intersect($ad['groups'] ?? [], $groups) !== [];

        return match ($ad['groups_mode'] ?? 'all') {
            'only' => $chosen,
            'except' => !$chosen,
            default => true,
        };
    }

    /**
     * The admin preview of one ad (1.1.0): image and text ads as visitors see them, in a phone-wide and a
     * desktop-wide frame; code ads are shown as text and never run in the administrator's session.
     *
     * @param array<string, mixed> $ad
     */
    public static function preview(array $ad, string $label, string $codeNote): string
    {
        $inner = match ($ad['type']) {
            'image' => $ad['image'] === '' ? '' : '<a href="#" rel="sponsored"><img src="' . self::e($ad['image'])
                . '" alt="' . self::e($ad['alt']) . '"></a>',
            'text' => '<a class="nv-ad-text" href="#" rel="sponsored"><strong>' . self::e($ad['title'])
                . '</strong>' . ($ad['body'] === '' ? '' : '<span>' . self::e($ad['body']) . '</span>') . '</a>',
            default => '<div class="nv-prev-code"><p>' . self::e($codeNote) . '</p><pre><code>'
                . self::e(mb_substr($ad['code'], 0, 4000)) . '</code></pre></div>',
        };
        $ad = '<div class="nv-ad"><span class="nv-ad-label">' . self::e($label) . '</span>' . $inner . '</div>';

        return '<div class="nv-prev"><div class="nv-prev-frame nv-prev-phone"><span class="nv-prev-line"></span>'
            . $ad . '<span class="nv-prev-line"></span></div><div class="nv-prev-frame nv-prev-desktop">'
            . '<span class="nv-prev-line"></span>' . $ad . '<span class="nv-prev-line"></span></div></div>';
    }

    /** The preview frames' stylesheet, with the admin page's nonce. */
    public static function previewStyle(string $nonce): string
    {
        return '<style nonce="' . self::e($nonce) . '">.nv-prev{display:flex;flex-wrap:wrap;gap:16px;'
            . 'align-items:flex-start}.nv-prev-frame{border:1px solid var(--line,#ddd);border-radius:12px;padding:12px;'
            . 'background:var(--surface,#fff);overflow:hidden}.nv-prev-phone{width:360px;max-width:100%}'
            . '.nv-prev-desktop{flex:1 1 480px;min-width:0}.nv-prev-line{display:block;height:10px;margin:8px 0;'
            . 'border-radius:5px;background:var(--line,#e5e5e5)}.nv-prev .nv-ad{margin:10px auto;text-align:center}'
            . '.nv-prev img{max-width:100%;height:auto}.nv-prev .nv-ad-label{display:block;font-size:11px;'
            . 'text-transform:uppercase;opacity:.6}.nv-prev .nv-ad-text{display:inline-flex;flex-direction:column;'
            . 'padding:10px 14px;border:1px solid var(--line,#ddd);border-radius:10px}.nv-prev-code{border:2px'
            . ' dashed var(--line,#ccc);border-radius:8px;padding:10px;text-align:start}.nv-prev-code pre{'
            . 'white-space:pre-wrap;word-break:break-all;max-height:200px;overflow:auto;font-size:12px}</style>';
    }

    /** The extension's small stylesheet (device rules and spacing), with the page nonce. */
    public static function style(string $nonce): SafeHtml
    {
        return new SafeHtml('<style nonce="' . self::e($nonce) . '">.nv-ad{margin:14px auto;text-align:center;'
            . 'max-width:100%;overflow:hidden;list-style:none}.nv-ad img{max-width:100%;height:auto}'
            . '.nv-ad-label{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;opacity:.6;'
            . 'margin-bottom:4px}.nv-ad-text{display:inline-flex;flex-direction:column;gap:2px;padding:10px 14px;'
            . 'border:1px solid var(--line,#ddd);border-radius:10px}.forum-index .nv-ad,.thread-list .nv-ad,'
            . '.posts .nv-ad{padding:10px}@media (max-width:767px){.nv-ad-desktop{display:none}}'
            . '@media (min-width:768px){.nv-ad-mobile{display:none}}</style>' . "\n");
    }

    /** @param list<string> $extra */
    public static function excluded(string $path, array $extra): bool
    {
        if (preg_match('~(?:^|/)admin(?:/|$)~', $path) === 1) {
            return true;
        }
        foreach ([...self::EXCLUDED, ...$extra] as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function withNonce(string $code, string $nonce): string
    {
        return (string) preg_replace(
            '/<script\b(?![^>]*\bnonce\s*=)/i',
            '<script nonce="' . self::e($nonce) . '"',
            $code
        );
    }

    /** @param non-empty-list<array<string, mixed>> $ads */
    private static function pick(array $ads, float $random): array
    {
        $total = array_sum(array_column($ads, 'weight'));
        $target = $random * $total;
        foreach ($ads as $ad) {
            $target -= $ad['weight'];
            if ($target < 0) {
                return $ad;
            }
        }

        return $ads[count($ads) - 1];
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
