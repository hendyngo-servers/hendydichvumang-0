<?php
declare(strict_types=1);

use Hendy\Database;
use Hendy\Env;
use Hendy\Realtime;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

require dirname(__DIR__) . '/vendor/autoload.php';
foreach (['Env.php', 'Database.php', 'TelegramAuth.php', 'Realtime.php'] as $file) {
    require dirname(__DIR__) . '/app/' . $file;
}
Env::load(dirname(__DIR__) . '/.env');

// Ratchet 0.4.4 uses dynamic connection properties; hide vendor-only PHP 8.3 deprecations.
error_reporting(E_ALL & ~E_DEPRECATED);

// The web service applies schema migrations. Realtime waits for its durable tables.
$waitSeconds = max(0, Env::int('DB_STARTUP_WAIT_SECONDS', 90));
$deadline = microtime(true) + $waitSeconds;
$lastDatabaseError = null;
do {
    try {
        Database::check();
        $schemaReady = Database::value("SELECT to_regclass('public.realtime_events')") !== null
            && Database::value("SELECT to_regclass('public.realtime_tickets')") !== null;
        if ($schemaReady) break;
    } catch (Throwable $error) {
        $lastDatabaseError = $error;
    }
    if (microtime(true) >= $deadline) {
        $reason = $lastDatabaseError !== null ? ' Chi tiết: ' . $lastDatabaseError->getMessage() : '';
        throw new RuntimeException('PostgreSQL hoặc schema realtime chưa sẵn sàng.' . $reason);
    }
    sleep(2);
} while (true);

final class AdminRealtimeServer implements MessageComponentInterface
{
    /** @var SplObjectStorage<ConnectionInterface, array{authenticated:bool,lastSeen:float}> */
    private SplObjectStorage $clients;
    private int $lastId = 0;
    private int $maxConnections;
    /** @var list<string> */
    private array $allowedOrigins;

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
        $this->lastId = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM realtime_events');
        $this->maxConnections = max(1, min(2000, Env::int('WEBSOCKET_MAX_CONNECTIONS', 250)));

        $configured = array_filter(array_map(
            'trim',
            explode(',', (string) Env::get('WEBSOCKET_ALLOWED_ORIGINS', ''))
        ));
        if ($configured === []) {
            foreach (['APP_URL', 'ADMIN_URL', 'WEBAPP_URL'] as $key) {
                $origin = rtrim((string) Env::get($key, ''), '/');
                if ($origin !== '') $configured[] = $origin;
            }
        }
        $this->allowedOrigins = array_values(array_unique(array_map(
            static fn(string $origin): string => rtrim($origin, '/'),
            $configured
        )));
        if ($this->allowedOrigins === []) {
            throw new RuntimeException('WEBSOCKET_ALLOWED_ORIGINS chưa được cấu hình; từ chối mở WebSocket công khai.');
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $origin = '';
        try {
            $origin = rtrim((string) $conn->httpRequest->getHeaderLine('Origin'), '/');
        } catch (Throwable) {
            // The connection is rejected below if the browser Origin is absent.
        }

        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            $conn->send(json_encode(['type' => 'origin.rejected']));
            $conn->close();
            return;
        }
        if ($this->clients->count() >= $this->maxConnections) {
            $conn->send(json_encode(['type' => 'server.busy']));
            $conn->close();
            return;
        }

        $this->clients->attach($conn, [
            'authenticated' => false,
            'userId' => null,
            'audience' => null,
            'lastSeen' => microtime(true),
        ]);
        $conn->send(json_encode(['type' => 'auth.required']));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (!$this->clients->contains($from)) {
            $from->close();
            return;
        }

        $raw = (string) $msg;
        if (strlen($raw) > 4096) {
            $from->send(json_encode(['type' => 'message.too_large']));
            $from->close();
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            $from->send(json_encode(['type' => 'message.invalid']));
            return;
        }

        $state = $this->clients[$from];
        $state['lastSeen'] = microtime(true);

        if (!$state['authenticated']) {
            $identity = ($data['type'] === 'auth' && is_string($data['ticket'] ?? null))
                ? Realtime::consumeTicket($data['ticket'])
                : null;
            if ($identity === null) {
                $from->send(json_encode(['type' => 'auth.failed']));
                $from->close();
                return;
            }
            $state['authenticated'] = true;
            $state['userId'] = $identity['userId'];
            $state['audience'] = $identity['audience'];
            $this->clients[$from] = $state;
            $from->send(json_encode([
                'type' => 'realtime.ready',
                'audience' => $identity['audience'],
                'heartbeatSeconds' => 25,
            ]));
            return;
        }

        if ($data['type'] === 'ping') {
            $this->clients[$from] = $state;
            $from->send(json_encode(['type' => 'pong', 'timestamp' => (int) (microtime(true) * 1000)]));
            return;
        }

        // Read-only channel: clients cannot publish or request arbitrary database events.
        $this->clients[$from] = $state;
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log('WebSocket: ' . $e->getMessage());
        $conn->close();
    }

    public function publish(): void
    {
        do {
            $events = Database::all(
                'SELECT id, event_type, audience, payload, created_at
                 FROM realtime_events WHERE id > ? ORDER BY id ASC LIMIT 500',
                [$this->lastId]
            );

            foreach ($events as $event) {
                $eventId = (int) $event['id'];
                $this->lastId = max($this->lastId, $eventId);
                $eventAudience = (string) $event['audience'];
                if (!in_array($eventAudience, ['admin', 'users', 'all'], true)) continue;

                $message = json_encode([
                    'id' => $eventId,
                    'type' => (string) $event['event_type'],
                    'payload' => is_string($event['payload'])
                        ? json_decode($event['payload'], true)
                        : $event['payload'],
                    'createdAt' => $event['created_at'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                foreach ($this->clients as $client) {
                    $state = $this->clients[$client];
                    $canReceive = $state['authenticated'] && (
                        $eventAudience === 'all'
                        || ($eventAudience === 'admin' && $state['audience'] === 'admin')
                        || ($eventAudience === 'users' && in_array($state['audience'], ['users', 'admin'], true))
                    );
                    if ($canReceive) {
                        try {
                            $client->send($message);
                        } catch (Throwable $error) {
                            error_log('WebSocket send failed: ' . $error->getMessage());
                            $client->close();
                        }
                    }
                }
            }
        } while (count($events) === 500);
    }

    public function sweep(): void
    {
        $now = microtime(true);
        foreach ($this->clients as $client) {
            $state = $this->clients[$client];
            // Browser sends an application-level ping every 25 seconds.
            if (($now - $state['lastSeen']) > 90) {
                $client->close();
                $this->clients->detach($client);
            }
        }

        Database::run("DELETE FROM realtime_events WHERE created_at < NOW() - INTERVAL '2 days'");
        Database::run("DELETE FROM realtime_tickets WHERE expires_at < NOW() OR consumed_at IS NOT NULL");
    }

    public function connectionCount(): int
    {
        return $this->clients->count();
    }

    public function maxConnections(): int
    {
        return $this->maxConnections;
    }
}

// Take the durable cursor before LISTEN, then replay once immediately after
// subscribing. This closes the startup window in which a NOTIFY could be missed.
$host = (string) Env::get('WEBSOCKET_BIND', '0.0.0.0');
$port = max(1, min(65535, Env::int('WEBSOCKET_PORT', Env::int('PORT', 10000))));
$server = new AdminRealtimeServer();
Realtime::listen();
$server->publish();
$loop = Loop::get();
$socket = new SocketServer($host . ':' . $port, [], $loop);
$io = new IoServer(new HttpServer(new WsServer($server)), $socket, $loop);

// PostgreSQL NOTIFY wakes this lightweight loop. A 5-second cursor scan is kept
// as a safety net for notifications lost during a process/network restart.
Loop::addPeriodicTimer(0.1, static function () use ($server): void {
    try {
        if (Realtime::drainNotifications() > 0) $server->publish();
    } catch (Throwable $error) {
        error_log('Realtime notification drain failed: ' . $error->getMessage());
    }
});
Loop::addPeriodicTimer(5.0, static function () use ($server): void {
    try {
        $server->publish();
    } catch (Throwable $error) {
        error_log('Realtime fallback poll failed: ' . $error->getMessage());
    }
});
Loop::addPeriodicTimer(60.0, static function () use ($server): void {
    try {
        $server->sweep();
    } catch (Throwable $error) {
        error_log('Realtime cleanup failed: ' . $error->getMessage());
    }
});

echo "Hendy realtime WebSocket listening on {$host}:{$port}; allowed origins="
    . implode(',', $serverOrigins = array_map('htmlspecialchars', explode(',', (string) Env::get('WEBSOCKET_ALLOWED_ORIGINS', ''))))
    . "; active connections=" . $server->connectionCount()
    . "; max connections=" . $server->maxConnections() . "\n";
$io->run();
