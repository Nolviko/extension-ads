<?php

declare(strict_types=1);

namespace NolvikoExt\Ads;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/ext/nolviko/ads/admin` (2026-11-26): ads list, add/edit form, ads.txt and settings. Administrators only, with
 * a password confirmation from the last 15 minutes; every change is audited. Ad code is raw HTML by design (like
 * every forum ad manager), which is why only administrators reach this page.
 */
final class AdminHandler implements RouteHandler
{
    public const ROUTE = 'nolviko.ads.admin';

    private const FLASH = '_nolviko_ads_notice';

    /**
     * @param Closure(): AdsConfig $config
     * @param Closure(): Connection $db
     */
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly Router $router,
        private readonly Session $session,
        private readonly DbalAdminAudit $audit,
        private readonly ErrorResponder $errors,
        private readonly Closure $config,
        private readonly Closure $db,
        private readonly string $clicksTable,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows('admin.access')) {
            return $subject->userId === null
                ? InstallerPage::redirect($this->router->generate(CoreRoutes::LOGIN)) : $this->errors->denied();
        }

        $tab = (string) $request->query->get('tab', 'ads');
        $tab = in_array($tab, ['ads', 'edit', 'adstxt', 'settings'], true) ? $tab : 'ads';
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->admin->isFresh()) {
                $error = $this->text('reauth');
            } else {
                try {
                    $next = $this->save($request->request->all(), (int) $subject->userId);

                    return InstallerPage::redirect($this->router->generate(self::ROUTE) . '?tab=' . $next);
                } catch (Throwable) {
                    $error = $this->text('error_failed');
                }
            }
        }

        $notice = $this->session->get(self::FLASH);
        $this->session->remove(self::FLASH);
        $body = $this->tabs($tab)
            . (is_string($notice) ? '<p role="status">' . $this->e($notice) . '</p>' : '')
            . ($error === null ? '' : '<p role="alert">' . $this->e($error) . '</p>')
            . ($this->admin->isFresh() ? '' : '<p>' . $this->e($this->text('reauth_hint')) . ' <a href="'
                . $this->e($this->router->generate(CoreRoutes::ADMIN_REAUTH)) . '">'
                . $this->e($this->translator->translate('admin.reauth.title')) . '</a></p>')
            . match ($tab) {
                'edit' => $this->editTab((string) $request->query->get('ad', '')),
                'adstxt' => $this->adsTxtTab(),
                'settings' => $this->settingsTab(),
                default => $this->listTab(),
            };
        $html = $this->views->render('extensions/admin_page.twig', [
            'title' => $this->text('title'),
            'nav' => AdminNavigation::items($this->router, $this->translator, CoreRoutes::ADMIN_EXTENSIONS),
            'body' => new SafeHtml($body),
        ]);

        return new Response($html, $error === null ? 200 : 422, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return string The tab to go back to. */
    private function save(array $input, int $userId): string
    {
        $config = ($this->config)();
        $data = $config->data;
        $action = (string) ($input['do'] ?? '');
        $id = is_string($input['id'] ?? null) ? $input['id'] : '';
        $field = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        switch ($action) {
            case 'save_ad':
                $ad = AdsConfig::cleanAd([
                    'id' => $id, 'name' => $field('name'), 'enabled' => $field('enabled') === '1',
                    'position' => $field('position'), 'after' => (int) $field('after'),
                    'repeat' => $field('repeat') === '1', 'type' => $field('type'), 'code' => $field('code'),
                    'image' => $field('image'), 'link' => $field('link'), 'alt' => $field('alt'),
                    'title' => $field('title'), 'body' => $field('body'), 'audience' => $field('audience'),
                    'device' => $field('device'), 'start' => $field('start'), 'end' => $field('end'),
                    'weight' => (int) $field('weight'),
                ]);
                $found = false;
                foreach ($data['ads'] as $index => $existing) {
                    if ($existing['id'] === $ad['id']) {
                        $data['ads'][$index] = $ad;
                        $found = true;
                    }
                }
                if (!$found) {
                    $data['ads'][] = $ad;
                }
                break;
            case 'delete_ad':
                $keep = static fn(array $ad): bool => $ad['id'] !== $id;
                $data['ads'] = array_values(array_filter($data['ads'], $keep));
                break;
            case 'toggle_ad':
                foreach ($data['ads'] as $index => $ad) {
                    if ($ad['id'] === $id) {
                        $data['ads'][$index]['enabled'] = !$ad['enabled'];
                    }
                }
                break;
            case 'ads_txt':
                $data['ads_txt'] = $field('ads_txt');
                break;
            case 'settings':
                $data['label'] = $field('label') === '1';
                $data['adsense'] = $field('adsense') === '1';
                $data['hosts'] = preg_split('/\s+/', trim($field('hosts'))) ?: [];
                $data['excluded'] = preg_split('/\s+/', trim($field('excluded'))) ?: [];
                break;
            default:
                return 'ads';
        }
        $config->save($data);
        $this->audit->record($userId, 'nolviko.ads.' . $action);
        $this->session->set(self::FLASH, $this->text('saved'));

        return match ($action) {
            'ads_txt' => 'adstxt',
            'settings' => 'settings',
            default => 'ads',
        };
    }

    private function listTab(): string
    {
        $ads = ($this->config)()->data['ads'];
        try {
            $clicks = ClickHandler::totals(($this->db)(), $this->clicksTable);
        } catch (Throwable) {
            $clicks = [];
        }
        $html = '<p><a class="btn btn-sm" href="' . $this->e($this->router->generate(self::ROUTE) . '?tab=edit') . '">'
            . $this->e($this->text('add')) . '</a></p>';
        if ($ads === []) {
            return $html . '<section class="panel panel-pad"><p>' . $this->e($this->text('none')) . '</p>'
                . '<p class="meta">' . $this->e($this->text('none_help')) . '</p></section>';
        }
        $html .= '<div class="panel"><table class="tbl"><thead><tr><th>' . $this->e($this->text('name')) . '</th><th>'
            . $this->e($this->text('position')) . '</th><th>' . $this->e($this->text('status')) . '</th><th>'
            . $this->e($this->text('clicks')) . '</th><th></th></tr></thead><tbody>';
        foreach ($ads as $ad) {
            $where = $this->text('position_' . $ad['position'])
                . (AdsConfig::POSITIONS[$ad['position']][1] ? ' · ' . $this->text($ad['repeat'] ? 'every' : 'after')
                    . ' ' . $ad['after'] : '');
            $html .= '<tr><td><a href="' . $this->e($this->router->generate(self::ROUTE) . '?tab=edit&ad=' . $ad['id'])
                . '"><bdi>' . $this->e($ad['name']) . '</bdi></a><br><span class="meta">'
                . $this->e($this->text('type_' . $ad['type']) . ' · ' . $this->text('audience_' . $ad['audience']))
                . '</span></td><td>' . $this->e($where) . '</td><td><span class="chip'
                . ($ad['enabled'] ? ' chip-new' : '') . '">' . $this->e($this->text($ad['enabled'] ? 'on' : 'off'))
                . '</span></td><td>' . ($ad['type'] === 'code' ? '–' : (int) ($clicks[$ad['id']] ?? 0)) . '</td><td>'
                . $this->form('toggle_ad', $ad['id'], $this->text($ad['enabled'] ? 'disable' : 'enable'), 'btn-s')
                . $this->form('delete_ad', $ad['id'], $this->text('delete'), 'btn-d') . '</td></tr>';
        }

        return $html . '</tbody></table></div><p class="meta">' . $this->e($this->text('clicks_note')) . '</p>';
    }

    private function editTab(string $id): string
    {
        $ad = null;
        foreach (($this->config)()->data['ads'] as $existing) {
            if ($existing['id'] === $id) {
                $ad = $existing;
            }
        }
        $ad ??= AdsConfig::cleanAd(['id' => '', 'name' => '']);
        $new = $id === '' || $ad['id'] !== $id;
        $select = function (string $name, array $options, string $current, string $prefix): string {
            $html = '<select id="ad-' . $name . '" name="' . $name . '">';
            foreach ($options as $option) {
                $html .= '<option value="' . $this->e($option) . '"' . ($option === $current ? ' selected' : '') . '>'
                    . $this->e($this->text($prefix . $option)) . '</option>';
            }

            return $html . '</select>';
        };
        $input = fn(string $name, string $value, string $type = 'text', string $extra = ''): string
            => '<p><label for="ad-' . $name . '">' . $this->e($this->text('field_' . $name)) . '</label><input id="ad-'
                . $name . '" name="' . $name . '" type="' . $type . '" value="' . $this->e($value) . '"' . $extra
                . '></p>';

        return '<form method="post" action="' . $this->e($this->router->generate(self::ROUTE))
            . '" class="panel panel-pad stack">' . $this->token() . '<input type="hidden" name="do" value="save_ad">'
            . '<input type="hidden" name="id" value="' . $this->e($new ? '' : $ad['id']) . '"><h2>'
            . $this->e($this->text($new ? 'add' : 'edit')) . '</h2>'
            . $input('name', $new ? '' : $ad['name'], 'text', ' maxlength="120" required')
            . '<p><label><input type="checkbox" name="enabled" value="1"' . ($ad['enabled'] ? ' checked' : '') . '> '
            . $this->e($this->text('field_enabled')) . '</label></p>'
            . '<p><label for="ad-position">' . $this->e($this->text('field_position')) . '</label>'
            . $select('position', array_keys(AdsConfig::POSITIONS), $ad['position'], 'position_')
            . '<span class="hint">' . $this->e($this->text('position_help')) . '</span></p>'
            . $input('after', (string) $ad['after'], 'number', ' min="1" max="100"')
            . '<p><label><input type="checkbox" name="repeat" value="1"' . ($ad['repeat'] ? ' checked' : '') . '> '
            . $this->e($this->text('field_repeat')) . '</label></p>'
            . '<p><label for="ad-type">' . $this->e($this->text('field_type')) . '</label>'
            . $select('type', AdsConfig::TYPES, $ad['type'], 'type_') . '</p>'
            . '<p><label for="ad-code">' . $this->e($this->text('field_code')) . '</label><textarea id="ad-code"'
            . ' name="code" rows="7" spellcheck="false">' . $this->e($ad['code']) . '</textarea><span class="hint">'
            . $this->e($this->text('code_help')) . '</span></p>'
            . $input('image', $ad['image'], 'text', ' placeholder="https://"') . $input('alt', $ad['alt'])
            . $input('title', $ad['title']) . $input('body', $ad['body'])
            . $input('link', $ad['link'], 'text', ' placeholder="https://"')
            . '<p><label for="ad-audience">' . $this->e($this->text('field_audience')) . '</label>'
            . $select('audience', AdsConfig::AUDIENCES, $ad['audience'], 'audience_') . '<span class="hint">'
            . $this->e($this->text('audience_help')) . '</span></p>'
            . '<p><label for="ad-device">' . $this->e($this->text('field_device')) . '</label>'
            . $select('device', AdsConfig::DEVICES, $ad['device'], 'device_') . '</p>'
            . $input('start', $ad['start'], 'date') . $input('end', $ad['end'], 'date')
            . $input('weight', (string) $ad['weight'], 'number', ' min="1" max="10"')
            . '<p><button type="submit"' . ($this->admin->isFresh() ? '' : ' disabled') . '>'
            . $this->e($this->text('save')) . '</button></p></form>';
    }

    private function adsTxtTab(): string
    {
        return '<form method="post" action="' . $this->e($this->router->generate(self::ROUTE))
            . '" class="panel panel-pad stack">' . $this->token() . '<input type="hidden" name="do" value="ads_txt">'
            . '<p><label for="ads-txt">ads.txt</label><textarea id="ads-txt" name="ads_txt" rows="8" spellcheck="false"'
            . ' placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0">'
            . $this->e(($this->config)()->data['ads_txt']) . '</textarea><span class="hint">'
            . $this->e($this->text('ads_txt_help')) . '</span></p><p><button type="submit"'
            . ($this->admin->isFresh() ? '' : ' disabled') . '>' . $this->e($this->text('save'))
            . '</button></p></form>';
    }

    private function settingsTab(): string
    {
        $data = ($this->config)()->data;

        return '<form method="post" action="' . $this->e($this->router->generate(self::ROUTE))
            . '" class="panel panel-pad stack">' . $this->token() . '<input type="hidden" name="do" value="settings">'
            . '<p><label><input type="checkbox" name="label" value="1"' . ($data['label'] ? ' checked' : '') . '> '
            . $this->e($this->text('field_label')) . '</label></p>'
            . '<p><label><input type="checkbox" name="adsense" value="1"' . ($data['adsense'] ? ' checked' : '') . '> '
            . $this->e($this->text('field_adsense')) . '</label><span class="hint">'
            . $this->e($this->text('adsense_help')) . '</span></p>'
            . '<p><label for="ads-hosts">' . $this->e($this->text('field_hosts')) . '</label><textarea id="ads-hosts"'
            . ' name="hosts" rows="3" spellcheck="false" placeholder="https://ads.example.com">'
            . $this->e(implode("\n", $data['hosts'])) . '</textarea><span class="hint">'
            . $this->e($this->text('hosts_help')) . '</span></p>'
            . '<p><label for="ads-excluded">' . $this->e($this->text('field_excluded')) . '</label><textarea'
            . ' id="ads-excluded" name="excluded" rows="3" spellcheck="false" placeholder="/calendar">'
            . $this->e(implode("\n", $data['excluded'])) . '</textarea><span class="hint">'
            . $this->e($this->text('excluded_help')) . '</span></p>'
            . '<p class="meta">' . $this->e($this->text('hide_help')) . '</p>'
            . '<p><button type="submit"' . ($this->admin->isFresh() ? '' : ' disabled') . '>'
            . $this->e($this->text('save')) . '</button></p></form>';
    }

    private function tabs(string $current): string
    {
        $html = '<nav class="tabs" aria-label="' . $this->e($this->text('title')) . '">';
        foreach (['ads' => 'tab_ads', 'adstxt' => 'tab_adstxt', 'settings' => 'tab_settings'] as $tab => $key) {
            $html .= '<a href="' . $this->e($this->router->generate(self::ROUTE) . '?tab=' . $tab) . '"'
                . ($current === $tab || ($tab === 'ads' && $current === 'edit') ? ' aria-current="page"' : '') . '>'
                . $this->e($this->text($key)) . '</a> ';
        }

        return $html . '</nav>';
    }

    private function form(string $action, string $id, string $label, string $class): string
    {
        return '<form method="post" class="inline-form" action="' . $this->e($this->router->generate(self::ROUTE))
            . '">' . $this->token() . '<input type="hidden" name="do" value="' . $action . '"><input type="hidden"'
            . ' name="id" value="' . $this->e($id) . '"><button class="' . $class . ' btn-sm" type="submit"'
            . ($this->admin->isFresh() ? '' : ' disabled') . '>' . $this->e($label) . '</button></form> ';
    }

    private function token(): string
    {
        return '<input type="hidden" name="' . $this->e(CsrfTokens::FIELD) . '" value="'
            . $this->e($this->csrf->token()) . '">';
    }

    private function text(string $key): string
    {
        return $this->translator->translate('nolviko.ads.' . $key);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
