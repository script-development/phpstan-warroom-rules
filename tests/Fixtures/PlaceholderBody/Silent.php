<?php

declare(strict_types = 1);

namespace App\Services\Placeholders;

use RuntimeException;

use function sprintf;

interface Exporter
{
    public function export(): string;
}

abstract class Base
{
    abstract public function handle(): void;
}

final class Silent extends Base implements Exporter
{
    public function handle(): void {}

    public function export(): string
    {
        return '... existing code ...';
    }

    /**
     * Throws when the driver says the feature is not implemented.
     */
    public function documentsNotImplementedInProse(): int
    {
        return 1;
    }

    public function bareEllipsisMeansIntentionallyEmpty(): void
    {
        // ...
    }

    public function proseAboutTheRestOfTheCode(): void
    {
        // the rest of the code assumes a tenant is set before this
    }

    public function nineWordElisionReadsAsProse(): void
    {
        // ... keep the remaining guards as they were ...
    }

    public function refusalWithAReason(): void
    {
        throw new RuntimeException('Method not implemented by Laravel.');
    }

    public function notImplementedClassWithAReason(): void
    {
        throw new NotImplementedException('Lambda storage does not support uploading.');
    }

    public function stubMessageAboutStubbing(): void
    {
        throw new RuntimeException('Cannot stub a final class');
    }

    public function throwIsNotTheOnlyStatement(): void
    {
        $this->handle();

        throw new RuntimeException('Not implemented');
    }

    public function deadCodeAfterAPlaceholderThrow(): int
    {
        throw new RuntimeException('Not implemented');

        return 1;
    }

    public function throwsAnExistingException(RuntimeException $e): void
    {
        throw $e;
    }

    public function nonLiteralMessage(string $what): void
    {
        throw new RuntimeException(sprintf('%s not implemented', $what));
    }

    public function messageIsNotTheFirstArgument(): void
    {
        throw new RuntimeException(code: 7);
    }

    public function dynamicClassWithoutAMessage(string $class): void
    {
        throw new $class;
    }
}
