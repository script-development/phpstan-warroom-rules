<?php

declare(strict_types = 1);

// Stub of Laravel's HTTP client surface for the UntimedHttpClient fixtures.
// composer.json does not require illuminate/http (no shipped rule needs it at
// analysis time), so the `Http` facade + `PendingRequest` are stubbed here with
// their real FQCNs — classmap-autoloaded via autoload-dev, so PHPStan resolves
// them during fixture analysis. Mirrors the CurrentUserAttribute fixtures'
// approach of stubbing Illuminate\Http\Request rather than pulling the package.

namespace Illuminate\Http\Client {
    class Response {}

    // The injected entry point (`app('http')`), typically constructor-promoted
    // as a `Factory` property. Its builder methods return a PendingRequest; it
    // also proxies the send verbs. This is the dominant fleet HTTP idiom.
    class Factory
    {
        public function timeout(int $seconds): PendingRequest
        {
            return new PendingRequest;
        }

        public function connectTimeout(int $seconds): PendingRequest
        {
            return new PendingRequest;
        }

        public function withToken(string $token): PendingRequest
        {
            return new PendingRequest;
        }

        /**
         * @param array<string, mixed> $options
         */
        public function withOptions(array $options): PendingRequest
        {
            return new PendingRequest;
        }

        public function baseUrl(string $url): PendingRequest
        {
            return new PendingRequest;
        }

        /**
         * @param array<string, mixed> $query
         */
        public function get(string $url, array $query = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function post(string $url, array $data = []): Response
        {
            return new Response;
        }
    }

    class PendingRequest
    {
        public function timeout(int $seconds): static
        {
            return $this;
        }

        public function connectTimeout(int $seconds): static
        {
            return $this;
        }

        public function withToken(string $token): static
        {
            return $this;
        }

        /**
         * @param array<string, mixed> $options
         */
        public function withOptions(array $options): static
        {
            return $this;
        }

        public function baseUrl(string $url): static
        {
            return $this;
        }

        /**
         * @param array<string, mixed> $query
         */
        public function get(string $url, array $query = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function post(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function put(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function patch(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function delete(string $url, array $data = []): Response
        {
            return new Response;
        }

        public function head(string $url): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $options
         */
        public function send(string $method, string $url, array $options = []): Response
        {
            return new Response;
        }
    }
}

namespace Illuminate\Support\Facades {
    use Illuminate\Http\Client\PendingRequest;
    use Illuminate\Http\Client\Response;

    class Http
    {
        public static function timeout(int $seconds): PendingRequest
        {
            return new PendingRequest;
        }

        public static function connectTimeout(int $seconds): PendingRequest
        {
            return new PendingRequest;
        }

        public static function withToken(string $token): PendingRequest
        {
            return new PendingRequest;
        }

        /**
         * @param array<string, mixed> $options
         */
        public static function withOptions(array $options): PendingRequest
        {
            return new PendingRequest;
        }

        public static function baseUrl(string $url): PendingRequest
        {
            return new PendingRequest;
        }

        /**
         * @param array<string, mixed> $query
         */
        public static function get(string $url, array $query = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public static function post(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public static function put(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public static function patch(string $url, array $data = []): Response
        {
            return new Response;
        }

        /**
         * @param array<string, mixed> $data
         */
        public static function delete(string $url, array $data = []): Response
        {
            return new Response;
        }

        public static function head(string $url): Response
        {
            return new Response;
        }
    }
}
