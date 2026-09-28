<?php

declare(strict_types=1);

namespace N9c\Monitor\Middleware;

use N9c\Monitor\Service\AutoReport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stoesst den automatischen Report an (siehe AutoReport). Veraendert weder
 * Anfrage noch Antwort und bietet keinen eingehenden Endpunkt.
 *
 * WICHTIG (Lehre aus v0.2.0, 24.09.2026): KEIN Konstruktor mit Parametern.
 * TYPO3 erzeugt Middlewares in manchen Situationen per `new` statt ueber den
 * DI-Container (veralteter DI-Cache nach einem Update, Install Tool /
 * Failsafe-Modus). Ein Pflicht-Parameter fuehrt dann zu einem 500-Fehler auf
 * der GANZEN Website. Deshalb werden die Dienste erst hier im process() aus
 * dem Container geholt - klappt das nicht, schaltet sich die Middleware
 * still ab. Monitoring darf eine Seite nie kaputt machen.
 */
final class AutoReportMiddleware implements MiddlewareInterface
{
    private static bool $scheduled = false;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        try {
            if (self::$scheduled) {
                return $response;
            }
            $container = GeneralUtility::getContainer();
            if (!$container->has(AutoReport::class)) {
                return $response;
            }
            /** @var AutoReport $autoReport */
            $autoReport = $container->get(AutoReport::class);
            if ($autoReport->isDue()) {
                self::$scheduled = true;
                register_shutdown_function(static function () use ($autoReport): void {
                    try {
                        $autoReport->runAfterResponse();
                    } catch (\Throwable) {
                    }
                });
            }
        } catch (\Throwable) {
            // Monitoring darf eine Seite niemals kaputt machen
        }
        return $response;
    }
}
