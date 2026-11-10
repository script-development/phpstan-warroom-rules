<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Illuminate\Auth\AuthManager;
use Illuminate\Cache\CacheManager;
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
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
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
use Illuminate\Support\Facades\Queue as QueueFacade;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ObjectType;
use PHPUnit\Framework\Attributes\DataProvider;
use ScriptDevelopment\PhpstanWarroomRules\Support\FacadeServices;
use stdClass;

final class FacadeServicesTest extends PHPStanTestCase
{
    /**
     * Every listed type, and the manager each facade resolves to.
     *
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function services(): iterable
    {
        yield 'Auth: its manager' => [Auth::class, AuthManager::class];
        yield 'Auth: the factory contract' => [Auth::class, AuthFactory::class];
        yield 'Auth: a guard' => [Auth::class, Guard::class];
        yield 'Broadcast: the factory contract' => [Broadcast::class, BroadcastFactory::class];
        yield 'Broadcast: a broadcaster' => [Broadcast::class, Broadcaster::class];
        yield 'Bus: the dispatcher contract' => [Bus::class, BusDispatcher::class];
        yield 'Cache: its manager' => [Cache::class, CacheManager::class];
        yield 'Cache: the factory contract' => [Cache::class, CacheFactory::class];
        yield 'Cache: a repository' => [Cache::class, CacheRepository::class];
        yield 'Config: the repository contract' => [Config::class, ConfigRepository::class];
        yield 'DB: its manager' => [DB::class, DatabaseManager::class];
        yield 'DB: the resolver contract' => [DB::class, ConnectionResolverInterface::class];
        yield 'DB: a connection' => [DB::class, ConnectionInterface::class];
        yield 'Event: the dispatcher contract' => [Event::class, EventDispatcher::class];
        yield 'Mail: the factory contract' => [Mail::class, MailFactory::class];
        yield 'Mail: a mailer' => [Mail::class, Mailer::class];
        yield 'Notification: the dispatcher contract' => [Notification::class, NotificationDispatcher::class];
        yield 'Queue: the factory contract' => [QueueFacade::class, QueueFactory::class];
        yield 'Queue: a queue' => [QueueFacade::class, Queue::class];
        yield 'Response: the factory contract' => [Response::class, ResponseFactory::class];
        yield 'Session: its manager' => [Session::class, SessionManager::class];
        yield 'Session: a store' => [Session::class, SessionStore::class];
        yield 'Storage: its manager' => [Storage::class, FilesystemManager::class];
        yield 'Storage: the factory contract' => [Storage::class, FilesystemFactory::class];
        yield 'Storage: a disk' => [Storage::class, Filesystem::class];
    }

    #[DataProvider('services')]
    public function testAnInjectedServiceServesItsFacade(string $facade, string $service): void
    {
        self::assertTrue(FacadeServices::serves($facade, new ObjectType($service)));
        self::assertSame($facade, FacadeServices::facadeOf(new ObjectType($service)));
    }

    public function testAnUnrelatedTypeServesNoFacade(): void
    {
        self::assertFalse(FacadeServices::serves(Auth::class, new ObjectType(stdClass::class)));
        self::assertNull(FacadeServices::facadeOf(new ObjectType(stdClass::class)));
    }

    public function testAFacadeWithNoListedServiceServesNothing(): void
    {
        self::assertFalse(FacadeServices::serves(Request::class, new ObjectType(Guard::class)));
    }
}
