<?php

declare(strict_types = 1);

/**
 * Decide which `src/` files a pull request's mutation run must cover.
 *
 * Prints one line per decision to STDOUT:
 *   FULL          — mutate everything (a changed path can move results outside its own file)
 *   NONE          — no changed path can move a mutation result (documentation only)
 *   SCOPED <path> — one line per `src/` file to mutate, whole-file
 * Every path's classification goes to STDERR, so the CI log shows why.
 *
 * A changed test is scoped to the `src/` classes it imports; a changed fixture to
 * the classes imported by every test naming its fixture directory. Anything the
 * mapping cannot resolve fails closed to FULL. The push-to-main run is always FULL
 * and remains the backstop for what a mapping by import cannot see (a fixture class
 * reached through the classmap by name from another rule's test).
 *
 * Usage: php bin/mutation-scope.php <base-ref>
 */
const PACKAGE_NAMESPACE = 'ScriptDevelopment\PhpstanWarroomRules\\';

const INERT_PATHS = [
    'LICENSE',
    '.gitignore',
    '.github/CODEOWNERS',
    '.github/dependabot.yml',
    '.github/workflows/release.yml',
];

$base = $argv[1] ?? null;

if ($base === null || $base === '') {
    fwrite(\STDERR, "Usage: mutation-scope.php <base-ref>\n");
    exit(2);
}

$root = (string) getcwd();
$diff = [];
$status = 0;
exec('git -C ' . escapeshellarg($root) . ' diff --name-status --no-renames ' . escapeshellarg($base) . ' HEAD', $diff, $status);

if ($status !== 0) {
    fwrite(\STDERR, "git diff against '{$base}' failed (exit {$status})\n");
    exit(2);
}

if ($diff === []) {
    fwrite(\STDERR, "No changed paths against '{$base}' — an empty diff is an instrument question, refusing to decide\n");
    exit(2);
}

/** @return list<string> */
// Every rule and type test, read once for the whole run: a diff of N fixtures must not reread
// the test tree N times (the fixture branch asks which tests name each fixture directory).
/** @var array<string, string> $testSources relative test path => source */
$testSources = [];

foreach (['tests/Rules', 'tests/Type'] as $testDirectory) {
    foreach (glob($root . '/' . $testDirectory . '/*Test.php') ?: [] as $file) {
        $testSources[mb_substr($file, mb_strlen($root) + 1)] = (string) file_get_contents($file);
    }
}

$importedSources = static function(string $testPath) use ($root, $testSources): array {
    $source = $testSources[$testPath] ?? (string) file_get_contents($root . '/' . $testPath);
    preg_match_all('/^use ' . preg_quote(PACKAGE_NAMESPACE, '/') . '((?:Rules|Type)\\\\\w+);/m', $source, $matches);
    $paths = [];

    foreach ($matches[1] as $class) {
        $path = 'src/' . str_replace('\\', '/', $class) . '.php';

        if (is_file($root . '/' . $path)) {
            $paths[] = $path;
        }
    }

    return $paths;
};

/** @return list<string> */
$testsNamingFixtureDirectory = static function(string $directory) use ($testSources): array {
    $tests = [];
    $pattern = '#Fixtures/' . preg_quote($directory, '#') . '(?![A-Za-z0-9_])#';

    foreach ($testSources as $testPath => $source) {
        if (preg_match($pattern, $source) === 1) {
            $tests[] = $testPath;
        }
    }

    return $tests;
};

$scope = [];
$full = [];
/** @var array<string, list<string>> $fixtureSources fixture directory => the package sources its tests import */
$fixtureSources = [];

foreach ($diff as $line) {
    [$change, $path] = explode("\t", $line, 2) + [1 => ''];

    if (str_ends_with($path, '.md') || \in_array($path, INERT_PATHS, true)) {
        fwrite(\STDERR, "inert   {$change} {$path}\n");

        continue;
    }

    if ($change === 'D') {
        $full[] = "{$path} (deleted — its former reach cannot be read from HEAD)";

        continue;
    }

    if (preg_match('#^src/(Rules|Type)/\w+\.php$#', $path) === 1) {
        $scope[$path] = true;
        fwrite(\STDERR, "scoped  {$change} {$path}\n");

        continue;
    }

    if (preg_match('#^tests/(Rules|Type)/\w+Test\.php$#', $path) === 1) {
        $sources = $importedSources($path);

        if ($sources === []) {
            $full[] = "{$path} (imports no package class, so it may exercise any of them)";

            continue;
        }

        foreach ($sources as $source) {
            $scope[$source] = true;
        }

        fwrite(\STDERR, "scoped  {$change} {$path} -> " . implode(', ', $sources) . "\n");

        continue;
    }

    if (preg_match('#^tests/Fixtures/(\w+)/[\w/]+\.php$#', $path, $fixture) === 1) {
        $namespace = preg_match('/^namespace ([\w\\\]+)\s*[;{]/m', (string) file_get_contents($root . '/' . $path), $declared) === 1
            ? $declared[1]
            : '';

        if (!str_starts_with($namespace, 'App\\')) {
            $full[] = "{$path} (declares '{$namespace}', outside App\\ — a classmapped stub every test can load)";

            continue;
        }

        $fixtureSources[$fixture[1]] ??= array_merge(
            ...array_map($importedSources, $testsNamingFixtureDirectory($fixture[1])),
        );
        $sources = $fixtureSources[$fixture[1]];

        if ($sources === []) {
            $full[] = "{$path} (no test names Fixtures/{$fixture[1]} and imports a package class)";

            continue;
        }

        foreach ($sources as $source) {
            $scope[$source] = true;
        }

        fwrite(\STDERR, "scoped  {$change} {$path} -> " . implode(', ', array_unique($sources)) . "\n");

        continue;
    }

    $full[] = "{$path} (configuration, dependency, support or workflow file)";
}

if ($full !== []) {
    foreach ($full as $reason) {
        fwrite(\STDERR, "full    {$reason}\n");
    }

    echo "FULL\n";
    exit(0);
}

if ($scope === []) {
    echo "NONE\n";
    exit(0);
}

$paths = array_keys($scope);
sort($paths);

foreach ($paths as $path) {
    echo "SCOPED {$path}\n";
}

exit(0);
