<?php

declare(strict_types=1);

namespace NolvikoExt\Ads;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Http\RouteHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `GET /ext/nolviko/ads/click/{ad}` (2026-11-26): counts one click per ad and day, then sends the visitor to the
 * ad's link with 302. Only links of saved image and text ads are followed (never an address from the request), so
 * the route cannot be used as an open redirect. Crawlers are not counted.
 */
final class ClickHandler implements RouteHandler
{
    /**
     * @param Closure(): AdsConfig $config
     * @param Closure(): Connection $db
     */
    public function __construct(
        private readonly Closure $config,
        private readonly Closure $db,
        private readonly string $table,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $id = (string) ($parameters['ad'] ?? '');
        $target = null;
        foreach (($this->config)()->data['ads'] as $ad) {
            if ($ad['id'] === $id && $ad['type'] !== 'code' && $ad['link'] !== '') {
                $target = $ad['link'];
            }
        }
        if ($target === null) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }
        $agent = strtolower((string) $request->headers->get('User-Agent', ''));
        if ($agent !== '' && preg_match('~bot|crawl|spider|slurp|preview|headless~', $agent) !== 1) {
            try {
                self::count(($this->db)(), $this->table, $id, (int) date('Ymd'));
            } catch (Throwable) {
                // A statistic: the visitor still reaches the ad.
            }
        }
        $location = str_starts_with($target, '/') ? $request->getBaseUrl() . $target : $target;

        return new Response('', Response::HTTP_FOUND, ['Location' => $location,
            'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public static function count(Connection $db, string $table, string $id, int $day): void
    {
        $updated = $db->executeStatement(
            'UPDATE ' . $table . ' SET clicks = clicks + 1 WHERE ad = ? AND day = ?',
            [$id, $day],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
        if ($updated === 0) {
            $db->insert($table, ['ad' => $id, 'day' => $day, 'clicks' => 1]);
        }
    }

    /** @return array<string, int> Ad ID => clicks in the last `$days` days. */
    public static function totals(Connection $db, string $table, int $days = 30): array
    {
        $totals = [];
        $since = (int) date('Ymd', strtotime('-' . $days . ' days'));
        foreach (
            $db->fetchAllAssociative(
                'SELECT ad, SUM(clicks) AS c FROM ' . $table . ' WHERE day >= ? GROUP BY ad',
                [$since],
                [ParameterType::INTEGER],
            ) as $row
        ) {
            $totals[(string) $row['ad']] = (int) $row['c'];
        }

        return $totals;
    }
}
