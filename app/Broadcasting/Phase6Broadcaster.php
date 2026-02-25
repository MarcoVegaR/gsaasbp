<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class Phase6Broadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName((string) $request->channel_name);

        if ($channelName === '' ||
            ($this->isGuardedChannel((string) $request->channel_name) &&
            ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        $result = parent::verifyUserCanAccessChannel($request, $channelName);

        if ($this->isDeniedResult($result)) {
            throw new AccessDeniedHttpException;
        }

        return $result;
    }

    public function validAuthenticationResponse($request, $result)
    {
        if (is_bool($result)) {
            return json_encode($result);
        }

        $channelName = $this->normalizeChannelName((string) $request->channel_name);
        $user = $this->retrieveUser($request, $channelName);

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        return json_encode([
            'channel_data' => [
                'user_id' => $broadcastIdentifier,
                'user_info' => $result,
            ],
        ]);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->logger->info('phase6.broadcast.dispatch', [
            'event' => (string) $event,
            'channels' => $this->formatChannels($channels),
            'payload_keys' => array_values(array_map(
                static fn (string $key): string => $key,
                array_keys(Arr::except($payload, ['payload'])),
            )),
        ]);
    }

    private function isDeniedResult(mixed $result): bool
    {
        return $result === false
            || $result === 0
            || $result === '0'
            || $result === 'false';
    }
}
