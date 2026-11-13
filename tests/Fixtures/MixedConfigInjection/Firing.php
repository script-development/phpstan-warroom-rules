<?php

declare(strict_types = 1);

namespace App\Services\ConfigInjection\Firing {
    use Illuminate\Container\Attributes\Config;

    final readonly class PromotedMixed
    {
        public function __construct(
            #[Config('services.mollie.key')]
            private mixed $apiKey,
            #[Config('services.mollie.timeout')]
            $timeout,
        ) {}
    }

    final class MethodParameters
    {
        public function handle(#[Config('app.name')] mixed $name, #[Config('app.debug')] $debug): void {}
    }

    function helper(#[Config('mail.from')] mixed $from): void {}

    final class Closures
    {
        public function run(): void
        {
            $closure = static function(#[Config('queue.default')] mixed $queue): void {};
            $arrow = static fn(#[Config('cache.default')] $store) => $store;
        }
    }
}

namespace App\Services\ConfigInjection\Aliased {
    use Illuminate\Container\Attributes\Config as Setting;
    use SensitiveParameter;

    final readonly class AliasedImport
    {
        public function __construct(
            #[Setting('services.snelstart.key')]
            private mixed $key,
            #[SensitiveParameter, Setting('services.snelstart.secret')]
            private mixed $secret,
        ) {}
    }
}

namespace App\Services\ConfigInjection\CaseFolded {
    use illuminate\container\attributes\config;

    final readonly class CaseFoldedImport
    {
        public function __construct(
            #[config('app.cipher')]
            private mixed $cipher,
        ) {}
    }
}

namespace App\Services\ConfigInjection\AfterOtherParameters {
    use Illuminate\Container\Attributes\Config;

    final class AfterOtherParameters
    {
        public function afterAPlainParameter(string $prefix, #[Config('app.url')] mixed $url): void {}

        public function afterATypedConfigParameter(#[Config('app.locale')] string $locale, #[Config('app.timezone')] mixed $timezone): void {}
    }
}
