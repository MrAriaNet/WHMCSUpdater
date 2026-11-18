<?php
/**
 * Choose a healthy proxy from the pool using the configured strategy.
 */

namespace WHMCSUpdater;

class ProxySelector
{
    public const CACHE_KEY = 'selected_proxy';
    public const RR_KEY = 'round_robin_index';

    /** @var ProxyRepository */
    private $repo;

    /** @var ProxyChecker */
    private $checker;

    /** @var string */
    private $strategy;

    /** @var int */
    private $maxAttempts;

    /** @var int */
    private $cacheTtl;

    public function __construct(
        ProxyRepository $repo,
        ProxyChecker $checker,
        string $strategy = 'fastest',
        int $maxAttempts = 5,
        int $cacheTtl = 300
    ) {
        $this->repo = $repo;
        $this->checker = $checker;
        $this->strategy = $strategy;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->cacheTtl = max(0, $cacheTtl);
    }

    /**
     * Return a cached healthy proxy when still valid.
     */
    public function getCached(): ?object
    {
        if ($this->cacheTtl <= 0) {
            return null;
        }

        $cached = $this->repo->getState(self::CACHE_KEY);
        if (!is_array($cached) || empty($cached['id']) || empty($cached['expires_at'])) {
            return null;
        }

        if ((int) $cached['expires_at'] < time()) {
            return null;
        }

        $proxy = $this->repo->find((int) $cached['id']);
        if (!$proxy || !(int) $proxy->enabled) {
            return null;
        }

        return $proxy;
    }

    /**
     * Select a healthy proxy, optionally skipping the cache.
     *
     * @return array{proxy:?object,from_cache:bool,attempts:int,error:string}
     */
    public function select(bool $allowCache = true): array
    {
        if ($allowCache) {
            $cached = $this->getCached();
            if ($cached) {
                return [
                    'proxy' => $cached,
                    'from_cache' => true,
                    'attempts' => 0,
                    'error' => '',
                ];
            }
        }

        $candidates = $this->orderCandidates($this->repo->enabled());
        if ($candidates === []) {
            return [
                'proxy' => null,
                'from_cache' => false,
                'attempts' => 0,
                'error' => 'no_enabled_proxies',
            ];
        }

        $attempts = 0;
        foreach ($candidates as $proxy) {
            if ($attempts >= $this->maxAttempts) {
                break;
            }
            $attempts++;

            $result = $this->checker->check($proxy, $this->repo);
            $this->repo->recordCheck(
                (int) $proxy->id,
                $result['ok'],
                $result['latency_ms'],
                $result['error']
            );

            if ($result['ok']) {
                $this->remember((int) $proxy->id);
                // Refresh object so latency/status fields are current for callers.
                $fresh = $this->repo->find((int) $proxy->id) ?: $proxy;

                return [
                    'proxy' => $fresh,
                    'from_cache' => false,
                    'attempts' => $attempts,
                    'error' => '',
                ];
            }
        }

        $this->repo->clearState(self::CACHE_KEY);

        return [
            'proxy' => null,
            'from_cache' => false,
            'attempts' => $attempts,
            'error' => 'all_proxies_failed',
        ];
    }

    /**
     * Force a health check of every enabled proxy and return results.
     *
     * @return array<int, array{proxy:object,result:array}>
     */
    public function checkAll(): array
    {
        $out = [];
        foreach ($this->repo->enabled() as $proxy) {
            $result = $this->checker->check($proxy, $this->repo);
            $this->repo->recordCheck(
                (int) $proxy->id,
                $result['ok'],
                $result['latency_ms'],
                $result['error']
            );
            $out[] = [
                'proxy' => $this->repo->find((int) $proxy->id) ?: $proxy,
                'result' => $result,
            ];
        }

        return $out;
    }

    /**
     * Health-check a single proxy by ID.
     *
     * @return array{proxy:?object,result:?array,error:string}
     */
    public function checkOne(int $id): array
    {
        $proxy = $this->repo->find($id);
        if (!$proxy) {
            return ['proxy' => null, 'result' => null, 'error' => 'not_found'];
        }

        $result = $this->checker->check($proxy, $this->repo);
        $this->repo->recordCheck(
            (int) $proxy->id,
            $result['ok'],
            $result['latency_ms'],
            $result['error']
        );

        return [
            'proxy' => $this->repo->find((int) $proxy->id) ?: $proxy,
            'result' => $result,
            'error' => '',
        ];
    }

    public function invalidateCache(): void
    {
        $this->repo->clearState(self::CACHE_KEY);
    }

    /**
     * @param array<int, object> $proxies
     * @return array<int, object>
     */
    private function orderCandidates(array $proxies): array
    {
        if ($proxies === []) {
            return [];
        }

        switch ($this->strategy) {
            case 'priority':
                // Already ordered by priority,id from the repository.
                return $proxies;

            case 'random':
                shuffle($proxies);
                return $proxies;

            case 'round_robin':
                $index = (int) $this->repo->getState(self::RR_KEY, 0);
                $count = count($proxies);
                $start = $count > 0 ? $index % $count : 0;
                $this->repo->setState(self::RR_KEY, $start + 1);
                return array_merge(
                    array_slice($proxies, $start),
                    array_slice($proxies, 0, $start)
                );

            case 'fastest':
            default:
                usort($proxies, static function ($a, $b) {
                    $aHealthy = ($a->last_status ?? '') === 'healthy';
                    $bHealthy = ($b->last_status ?? '') === 'healthy';
                    if ($aHealthy !== $bHealthy) {
                        return $aHealthy ? -1 : 1;
                    }

                    $aLat = $a->last_latency_ms;
                    $bLat = $b->last_latency_ms;
                    if ($aLat === null && $bLat === null) {
                        return ((int) $a->priority) <=> ((int) $b->priority);
                    }
                    if ($aLat === null) {
                        return 1;
                    }
                    if ($bLat === null) {
                        return -1;
                    }

                    return ((int) $aLat) <=> ((int) $bLat);
                });
                return $proxies;
        }
    }

    private function remember(int $proxyId): void
    {
        if ($this->cacheTtl <= 0) {
            return;
        }

        $this->repo->setState(self::CACHE_KEY, [
            'id' => $proxyId,
            'expires_at' => time() + $this->cacheTtl,
        ]);
    }
}
