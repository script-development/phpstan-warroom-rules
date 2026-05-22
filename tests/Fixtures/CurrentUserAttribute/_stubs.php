<?php

declare(strict_types = 1);

// Stub classes referenced by CurrentUserAttribute fixtures. The rule scopes
// detection on:
//   - PHPStan reflection (ancestor traversal to Illuminate\Routing\Controller)
//   - Type-based receiver matching (Illuminate\Http\Request subtype)
//   - Static-call FQCN resolution (Illuminate\Support\Facades\Auth)
//   - AST-shape match (auth() helper FuncCall receiver)
//
// The composer.json requires illuminate/container + illuminate/support but
// NOT illuminate/routing / illuminate/http — we stub the latter two here so
// PHPStan can resolve fixture types without pulling in a full Laravel install.
// `Illuminate\Container\Attributes\CurrentUser` is real (illuminate/container
// is installed transitively) and is referenced directly by the compliant
// fixture.

namespace Illuminate\Routing {
    class Controller {}
}

namespace Illuminate\Http {
    class Request
    {
        public function user(): ?object
        {
            return null;
        }
    }
}

namespace Illuminate\Foundation\Http {
    use Illuminate\Http\Request;

    class FormRequest extends Request {}
}

namespace App\Models {
    final class User
    {
        public string $name = '';
    }
}

namespace {
    // `auth()` helper is normally declared in `illuminate/foundation`'s
    // helpers file (not in vendor here). Stub the function so fixtures can
    // call it; the concrete return type is irrelevant to the rule
    // (AST-shape matched against FuncCall name).
    if (!\function_exists('auth')) {
        function auth(): object
        {
            return new class {
                public function user(): ?object
                {
                    return null;
                }
            };
        }
    }
}
