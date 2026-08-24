<?php

declare(strict_types = 1);

/**
 * Analysis anchors for FQCNs this package deliberately does NOT depend on.
 *
 * WR-0854. Six rules carry a `Foo::class` constant naming a Laravel or Symfony
 * class that exists only in a CONSUMER's tree — `illuminate/http` and
 * `symfony/http-kernel` are in neither `require` nor `require-dev`, and adding
 * them is not an option: `tests/Fixtures/*` hand-declares the same FQCNs and a
 * second declaration in one autoload map is a fatal collision. Without a
 * declaration PHPStan reports `class.notFound` on every one of them the moment
 * the dev autoloader (which classmaps those fixtures) is out of the picture —
 * i.e. on the tree consumers actually install.
 *
 * These declarations are read by `phpstan.neon.dist` ONLY. `extension.neon` is
 * what consumers include, so nothing here reaches a consumer's analysis and
 * nothing here is autoloadable at runtime (the file sits outside every
 * autoload path). Bodies are empty on purpose: `src/` only ever takes the name,
 * never calls a member, and an empty body keeps that honest — a rule that
 * starts calling a method on one of these gets an error rather than a stub's
 * blessing.
 *
 * The CI leg `check-production-tree` is what makes this real: it installs
 * production dependencies only and analyses, so a seventh anchor added without
 * a declaration here fails the merge gate.
 */

namespace Illuminate\Http;

class Request {}

class JsonResponse {}

namespace Illuminate\Http\Client;

class Factory {}

namespace Illuminate\Http\Resources\Json;

class JsonResource {}

namespace Illuminate\Foundation\Http;

class FormRequest {}

namespace Symfony\Component\HttpKernel\Exception;

interface HttpExceptionInterface {}
