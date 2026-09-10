<?php

namespace App\EventListener;

use App\Service\DefaultUserInitializer;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class DefaultUserInitializerListener
{
    private bool $initialized = false;

    public function __construct(private DefaultUserInitializer $initializer)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Ne s'exécute que pour les requêtes HTTP principales (pas les sous-requêtes)
        if (!$event->isMainRequest()) {
            return;
        }

        // Éviter d'appeler plusieurs fois pendant la même requête
        if ($this->initialized) {
            return;
        }

        $this->initializer->initialize();
        $this->initialized = true;
    }
}