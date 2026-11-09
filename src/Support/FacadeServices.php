<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Session\Session as SessionStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * The types a facade's service is injected as, so a rule that matches
 * `Auth::user()` also matches `$this->auth->user()`. Each list holds the
 * contracts the service implements — the manager a facade resolves to
 * (`AuthManager`, `CacheManager`, `DatabaseManager`, `QueueManager`,
 * `FilesystemManager`) is a subtype of one — and the driver contract a
 * constructor takes by hand (`Guard`, a cache `Repository`,
 * `ConnectionInterface`). `SessionManager` implements no contract, so it is
 * listed itself.
 */
final class FacadeServices
{
    /** @var array<class-string, list<class-string>> */
    private const array SERVICES = [
        Auth::class => [AuthFactory::class, Guard::class],
        Broadcast::class => [BroadcastFactory::class, Broadcaster::class],
        Bus::class => [BusDispatcher::class],
        Cache::class => [CacheFactory::class, CacheRepository::class],
        Config::class => [ConfigRepository::class],
        DB::class => [ConnectionResolverInterface::class, ConnectionInterface::class],
        Event::class => [EventDispatcher::class],
        Mail::class => [MailFactory::class, Mailer::class],
        Notification::class => [NotificationDispatcher::class],
        \Illuminate\Support\Facades\Queue::class => [QueueFactory::class, Queue::class],
        Response::class => [ResponseFactory::class],
        Session::class => [SessionManager::class, SessionStore::class],
        Storage::class => [FilesystemFactory::class, Filesystem::class],
    ];

    public static function serves(string $facade, Type $type): bool
    {
        foreach (self::SERVICES[$facade] ?? [] as $service) {
            if ((new ObjectType($service))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }

    public static function facadeOf(Type $type): ?string
    {
        foreach (self::SERVICES as $facade => $services) {
            if (self::serves($facade, $type)) {
                return $facade;
            }
        }

        return null;
    }
}
