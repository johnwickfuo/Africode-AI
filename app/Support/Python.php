<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;

class Python
{
    /**
     * Command line for a python sidecar script.
     *
     * FBref scraping drives a HEADED Chrome (Cloudflare rejects headless,
     * and seleniumbase silently falls back to headless on a display-less
     * Linux box), so browser-driving scripts must run inside an Xvfb
     * virtual display: pass $withDisplay and the whole command is wrapped
     * in `xvfb-run -a` when available. Disable with FBREF_XVFB=false.
     */
    public static function command(array $args, bool $withDisplay = false): array
    {
        $command = [config('africode.python_bin'), ...$args];

        if ($withDisplay
            && config('africode.fbref.xvfb', true)
            && (new ExecutableFinder)->find('xvfb-run') !== null) {
            return ['xvfb-run', '-a', ...$command];
        }

        return $command;
    }
}
