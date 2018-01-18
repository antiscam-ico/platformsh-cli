<?php
namespace Platformsh\Cli\Console;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\ConnectionFailedException;
use Platformsh\Cli\Exception\HttpException;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\PermissionDeniedException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventSubscriber implements EventSubscriberInterface
{
    protected $config;

    /**
     * @param \Platformsh\Cli\Service\Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [ConsoleEvents::EXCEPTION => 'onException'];
    }

    /**
     * React to any console exceptions.
     *
     * @param ConsoleExceptionEvent    $event
     */
    public function onException(ConsoleExceptionEvent $event)
    {
        $exception = $event->getException();

        // Replace Guzzle connect exceptions with a friendlier message. This
        // also prevents the user from seeing two exceptions (one direct from
        // Guzzle, one from RingPHP).
        if ($exception instanceof ConnectException && strpos($exception->getMessage(), 'cURL error 6') !== false) {
            $request = $exception->getRequest();
            $event->setException(new ConnectionFailedException(
                "Failed to connect to host: " . $request->getHost()
                . " \nPlease check your Internet connection.",
                $request
            ));
            $event->stopPropagation();
        }

        // Handle Guzzle exceptions, i.e. HTTP 4xx or 5xx errors.
        if (($exception instanceof ClientException || $exception instanceof ServerException)
            && ($response = $exception->getResponse())) {
            $request = $exception->getRequest();
            $requestConfig = $request->getConfig();
            $response->getBody()->seek(0);
            $json = (array) json_decode($response->getBody()->getContents(), true);

            // Create a friendlier message for the OAuth2 "Invalid refresh token"
            // error.
            if ($response->getStatusCode() === 400
                && $requestConfig['auth'] === 'oauth2'
                && isset($json['error_description'])
                && $json['error_description'] === 'Invalid refresh token') {
                $event->setException(new LoginRequiredException(
                    'Invalid refresh token.',
                    $request,
                    $response,
                    $this->config
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 401 && $requestConfig['auth'] === 'oauth2') {
                $event->setException(new LoginRequiredException(
                    'Unauthorized.',
                    $request,
                    $response,
                    $this->config
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 403 && $requestConfig['auth'] === 'oauth2') {
                $event->setException(new PermissionDeniedException(
                    "Permission denied. Check your project or environment permissions.",
                    $request,
                    $response
                ));
                $event->stopPropagation();
            } else {
                $event->setException(new HttpException(null, $request, $response));
                $event->stopPropagation();
            }
        }

        // When an environment is found to be in the wrong state, perhaps our
        // cache is old - we should invalidate it.
        if ($exception instanceof EnvironmentStateException) {
            (new Api())->clearEnvironmentsCache($exception->getEnvironment()->project);
        }
    }
}
