<?php

namespace App\Modules\Media\Console;

use App\Modules\Media\Contracts\MediaHostProvider;
use Illuminate\Console\Command;

/**
 * `php artisan media:health` — verifies the media configuration and, for the
 * remote provider, that the Media Host is reachable. Prints only presence
 * ("set" / "MISSING"), never the secret values, so it is safe in CI logs.
 */
class MediaHealthCommand extends Command
{
    protected $signature = 'media:health';

    protected $description = 'Verify media provider config + remote Media Host connectivity (no secrets shown).';

    public function handle(MediaHostProvider $host): int
    {
        $provider = (string) config('media.provider', 'local');
        $this->line("Media provider: <info>{$provider}</info>");

        if ($provider !== 'remote') {
            $this->info('Local provider — no Media Host required. OK.');

            return self::SUCCESS;
        }

        $base = (string) config('media.host.base_url');
        $this->line('Base URL: '.($base !== '' ? $base : '<error>(missing)</error>'));
        foreach (['api_key' => 'API key', 'api_secret' => 'API secret', 'callback_secret' => 'Callback secret'] as $key => $label) {
            $this->line("{$label}: ".(config("media.host.{$key}") ? '<info>set</info>' : '<error>MISSING</error>'));
        }

        $priv = config('media.host.playback_private_key_path');
        $this->line('Playback signing: '.match (true) {
            $priv && is_readable($priv) => '<info>RS256 (key readable)</info>',
            (bool) $priv => '<error>key path set but NOT readable</error>',
            default => '<comment>HS256 fallback (set a key path for production)</comment>',
        });

        if (! $host->isConfigured()) {
            $this->error('Media Host is not fully configured — base_url + api_key + api_secret are required.');

            return self::FAILURE;
        }

        $this->line('Pinging Media Host /health …');
        try {
            $result = $host->health();
            $this->info('Media Host reachable. status='.($result['status'] ?? 'ok'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Media Host health check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
