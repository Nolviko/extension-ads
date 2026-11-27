<?php

declare(strict_types=1);

/*
 * Nolviko Ads extension (2026-11-26, PLG-06): ads in fixed places, filled from the admin panel without editing
 * code. Ad code (AdSense and others), image and text ads; position, audience, device, dates and rotation; ads.txt.
 */

use Doctrine\DBAL\Connection;
use Nolviko\Core\Container\Lazy;
use Nolviko\Core\Extensions\ExtensionContext;
use Nolviko\Core\Extensions\ExtensionProvider;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use Nolviko\Modules\Users\Http\RequestSubject;
use NolvikoExt\Ads\AdminHandler;
use NolvikoExt\Ads\AdsConfig;
use NolvikoExt\Ads\AdsRenderer;
use NolvikoExt\Ads\ClickHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/AdsConfig.php';
require_once __DIR__ . '/AdsRenderer.php';
require_once __DIR__ . '/ClickHandler.php';
require_once __DIR__ . '/AdminHandler.php';

return new class implements ExtensionProvider {
    public function register(ExtensionContext $context): void
    {
        $directory = $context->storage();
        $config = AdsConfig::load($directory);
        $clicks = $context->table('clicks');
        $sources = $config->sources();
        if ($sources !== []) {
            $context->contentSecurity($sources);
        }

        $context->service(
            'nolviko.ads.render',
            static fn(
                CspNonce $nonce,
                ThemeRequest $request,
                Closure $subjects,
                Router $router,
                MessageTranslator $translator,
            ): Closure => static fn(string $slot, array $slotContext): SafeHtml => AdsRenderer::forRequest(
                $config->data,
                $slot,
                $slotContext,
                $nonce,
                $request,
                $subjects,
                $router,
                $translator,
            ),
            [CspNonce::class, ThemeRequest::class, new Lazy(RequestSubject::class), Router::class,
                MessageTranslator::class],
        );
        $render = $context->lazy('nolviko.ads.render');
        $used = ['layout.head' => true];
        foreach ($config->data['ads'] as $ad) {
            if ($ad['enabled']) {
                $used[AdsConfig::POSITIONS[$ad['position']][0]] = true;
            }
        }
        if (count($used) > 1) {
            foreach (array_keys($used) as $slot) {
                // Same output for every guest on a page: the guest render cache stays on.
                $context->slot($slot, static fn(array $slotContext): SafeHtml => $render()($slot, $slotContext), true);
            }
        }

        $adsTxt = $config->data['ads_txt'];
        $context->notFound(static fn(Request $request): ?Response => $request->getPathInfo() === '/ads.txt'
            && trim($adsTxt) !== '' ? new Response(rtrim($adsTxt) . "\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600',
            ]) : null);

        $context->permission(new PermissionDefinition('nolviko.ads.view', PermissionType::Bool, default: true));
        $context->permission(new PermissionDefinition('nolviko.ads.hide', PermissionType::Bool, default: false));
        $context->permission(new PermissionDefinition('nolviko.ads.manage', PermissionType::Bool, default: true));
        $context->route(
            new RouteDefinition('nolviko.ads.click', '/ext/nolviko/ads/click/{ad}', ['GET'], ['ad' => '[a-f0-9]{12}']),
            'nolviko.ads.view',
            static fn(Closure $db): ClickHandler => new ClickHandler(
                static fn(): AdsConfig => AdsConfig::load($directory),
                $db,
                $clicks,
            ),
            [new Lazy(Connection::class)],
        );
        $context->route(
            new RouteDefinition(
                AdminHandler::ROUTE,
                '/ext/nolviko/ads/admin',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            'nolviko.ads.manage',
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Router $router,
                Session $session,
                Connection $db,
                ErrorResponder $errors,
                CspNonce $nonce,
            ): AdminHandler => new AdminHandler(
                $translator,
                $views,
                $subjects,
                $admin,
                $csrf,
                $router,
                $session,
                new DbalAdminAudit($db),
                $errors,
                static fn(): AdsConfig => AdsConfig::load($directory),
                static fn(): Connection => $db,
                $clicks,
                $nonce,
            ),
            [
                MessageTranslator::class, TemplateRenderer::class, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, Router::class, Session::class, Connection::class, ErrorResponder::class,
                CspNonce::class,
            ],
        );
        $context->adminPage(AdminHandler::ROUTE);
    }
};
