<?php

namespace Nexphant\Runtime\EventLoop;

use Nexphant\Support\Extension\ExtensionDetector;

class EventLoopFactory
{
    public static function create(?string $preferred = null): EventLoopInterface
    {
        $override = getenv('NEXPHANT_LOOP') ?: $preferred;
        
        if ($override === 'select') {
            return new SelectEventLoop();
        }
        
        if ($override === 'event' && ExtensionDetector::has('event')) {
            return new EventEventLoop();
        }
        
        if ($override === 'ev' && ExtensionDetector::has('ev')) {
            return new EvEventLoop();
        }
        
        if ($override === 'uv' && ExtensionDetector::has('uv')) {
            return new UvEventLoop();
        }

        if (ExtensionDetector::has('event')) {
            return new EventEventLoop();
        }

        if (ExtensionDetector::has('ev')) {
            return new EvEventLoop();
        }

        if (ExtensionDetector::has('uv')) {
            return new UvEventLoop();
        }

        return new SelectEventLoop();
    }
}
