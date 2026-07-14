<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

/**
 * Isolates the PHP runtime operations required for an immediately flushed SSE response.
 */
class StreamEmitter
{
    /**
     * Disable server-side buffering and compression for the lifetime of the stream.
     *
     * @return void
     */
    public function prepareEnvironment(): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- A chat stream may run indefinitely.
        set_time_limit(0);
        // Keep PHP alive long enough for the transformer to observe the disconnect and close the upstream stream.
        ignore_user_abort(true);

        if (\function_exists('apache_setenv')) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged,Generic.PHP.NoSilencedErrors.Discouraged -- Prevent Apache from gzip-buffering SSE without leaking a warning into the stream.
            @apache_setenv('no-gzip', '1');
        }

        // phpcs:disable Magento2.Functions.DiscouragedFunction.Discouraged,Generic.PHP.NoSilencedErrors.Discouraged
        // These guarded runtime settings are required so each SSE frame reaches the client immediately.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        // phpcs:enable Magento2.Functions.DiscouragedFunction.Discouraged,Generic.PHP.NoSilencedErrors.Discouraged

        while (ob_get_level() > 0) {
            $level = ob_get_level();
            // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- A non-removable third-party buffer must not corrupt SSE output.
            if (!@ob_end_clean() || ob_get_level() >= $level) {
                break;
            }
        }

        ob_implicit_flush(true);
    }

    /**
     * Write and flush one complete SSE payload.
     *
     * @param string $payload
     * @return void
     */
    public function emit(string $payload): void
    {
        // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput -- Streaming requires direct incremental output.
        echo $payload;
        flush();
    }

    /**
     * Determine whether the downstream client disconnected.
     *
     * @return bool
     */
    public function isClientAborted(): bool
    {
        return connection_aborted() === 1;
    }
}
