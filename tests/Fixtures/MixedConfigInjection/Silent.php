<?php

declare(strict_types = 1);

namespace App\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_PARAMETER)]
    final readonly class Config
    {
        public function __construct(
            public string $key,
        ) {}
    }
}

namespace App\Services\ConfigInjection\Silent {
    use Illuminate\Container\Attributes\Config;
    use SensitiveParameter;
    use Stringable;

    final readonly class ConcreteTypes
    {
        /**
         * @param list<string> $hosts
         */
        public function __construct(
            #[Config('services.mollie.key')]
            private ?string $apiKey,
            #[Config('services.mollie.timeout')]
            private int $timeout,
            #[Config('services.mollie.profile')]
            private int|string|null $profile,
            #[Config('services.mollie.hosts')]
            private array $hosts,
            #[Config('services.mollie.enabled')]
            private bool $enabled,
            #[Config('services.mollie.shape')]
            private array|string|null $shape,
            #[Config('services.mollie.label')]
            private Stringable $label,
        ) {}
    }

    final class MixedWithoutTheConfigAttribute
    {
        public function validate(string $attribute, mixed $value): void {}

        public function sensitive(#[SensitiveParameter] mixed $secret): void {}

        public function untyped($value): void {}
    }
}

namespace App\Services\ConfigInjection\LocalAttribute {
    use App\Attributes\Config;

    final readonly class ALocalAttributeNamedConfig
    {
        public function __construct(
            #[Config('app.name')]
            private mixed $name,
        ) {}
    }
}
