<?php

/**
 * HttpRouter class is responsible for routing incoming HTTP requests to the
 * appropriate request handlers based on the defined routes.
 * It serves as the entry point for processing incoming requests within a web application.
 *
 * Copyright © Elar Must.
 */

namespace Framework\Http;

use Framework\Container\ClassContainer;
use Framework\Http\Mime\MimeTypes;

use Framework\Http\Response;
use Framework\Http\Events\BeforeMiddlewaresEvent;
use Framework\Event\EventDispatcher;
use Framework\Http\RouteRegistry;
use Framework\Utils\RouteUtils;
use Framework\Logger\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use Throwable;

class HttpRouter {
    /**
     * @param ClassContainer $classContainer
     * @param EventDispatcher $EventDispatcher
     * @param RouteRegistry $routeRegistry
     * @param Logger $logger
     */
    public function __construct(
        private ClassContainer $classContainer,
        private EventDispatcher $EventDispatcher,
        private RouteRegistry $routeRegistry,
        private Logger $logger,
        private MimeTypes $mimeTypes
    ) {
    }

    /**
     * Processes an incoming HTTP request and generates an HTTP response.
     *
     * This method handles the following steps:
     * 2. Finds the nearest route match for the request's path.
     * 3. Dispatches a "BeforeMiddlewaresEvent" event for the matched route.
     * 4. Executes the request handler associated with the route.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     *
     * @return ResponseInterface The HTTP response generated as a result of processing the request.
     */
    public function process(ServerRequestInterface $request): ResponseInterface {
        $response = new Response($this->mimeTypes, '', 404);
        $highestMatch = RouteUtils::findNearestMatch($request->getServerParams()['path_info'], $this->routeRegistry->listRoutes(), '/');

        if (!$highestMatch) {
            return $response;
        }

        try {
            $route = clone $this->routeRegistry->getRoute($highestMatch);
            // Dispatch a BeforeMiddlewaresEvent event for the matched route. This event can be used to preprocess the request and response.
            $event = $this->EventDispatcher->dispatch(new BeforeMiddlewaresEvent($request, $response, $route));
            $route = $event->getRoute();
            $request = $event->getRequest();
            $response = $event->getResponse();

            // Get a new RequestHandler instance for this route and delegate the request to it.
            $requestHandler = $this->classContainer->get($route->getRequestHandler(), [$route], useCache: false);
            return $requestHandler->handle($request);
        } catch (Throwable $e) {
            $this->logger->log(LogLevel::ERROR, $e, identifier: 'framework');
            return new Response($this->mimeTypes, '', 500);
        }
    }
}
