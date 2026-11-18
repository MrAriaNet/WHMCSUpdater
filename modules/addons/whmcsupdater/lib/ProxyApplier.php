<?php
/**
 * High-level helper used by hooks to select and apply a proxy for a context.
 */

namespace WHMCSUpdater;

class ProxyApplier
{
    /**
     * Select a proxy and apply environment variables for the given context.
     *
     * @return array{applied:bool,proxy:?object,message:string}
     */
    public static function applyForContext(string $context): array
    {
        require_once __DIR__ . '/bootstrap.php';

        $repo = new ProxyRepository();
        $selector = Settings::makeSelector();
        $selection = $selector->select(true);

        if ($selection['proxy']) {
            $proxy = $selection['proxy'];
            $url = $repo->buildUrl($proxy, Settings::bool('socks5_remote_dns', true));
            ProxyEnv::apply($url, Settings::get('no_proxy', 'localhost,127.0.0.1'));

            $label = $repo->displayLabel($proxy);
            $message = $selection['from_cache']
                ? 'Applied cached proxy: ' . $label
                : 'Selected and applied proxy: ' . $label;

            $repo->log((int) $proxy->id, $label, $context, 'applied', $message);

            return [
                'applied' => true,
                'proxy' => $proxy,
                'message' => $message,
            ];
        }

        $fallback = Settings::get('fallback_behavior', 'direct');
        $error = $selection['error'] !== '' ? $selection['error'] : 'all_proxies_failed';

        if ($fallback === 'abort') {
            $repo->log(null, null, $context, 'aborted', 'No healthy proxy available: ' . $error);

            return [
                'applied' => false,
                'proxy' => null,
                'message' => 'aborted:' . $error,
            ];
        }

        $repo->log(null, null, $context, 'direct', 'No healthy proxy; falling back to direct: ' . $error);

        return [
            'applied' => false,
            'proxy' => null,
            'message' => 'direct:' . $error,
        ];
    }

    public static function restore(): void
    {
        ProxyEnv::restore();
    }
}
