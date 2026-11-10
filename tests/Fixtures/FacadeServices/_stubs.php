<?php

declare(strict_types = 1);

// Managers a facade resolves to whose packages (illuminate/auth,
// illuminate/session) are not installed here, declared with the contracts the
// real classes implement. Fixtures inject them the way the facade-to-service
// Rector rewrite does.

namespace Illuminate\Auth {
    use Illuminate\Contracts\Auth\Factory;
    use Illuminate\Contracts\Auth\Guard;

    class AuthManager implements Factory
    {
        public function guard($name = null): Guard
        {
            throw new \LogicException;
        }

        public function shouldUse($name): void {}

        public function user(): ?object
        {
            return null;
        }

        public function login(object $user): void {}
    }
}

namespace Illuminate\Session {
    class SessionManager
    {
        public function put(string $key, mixed $value): void {}
    }
}
