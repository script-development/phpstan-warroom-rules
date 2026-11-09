<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Application;

/**
 * Every way the comparison cannot be made exits 2 and names why; none of them is a pass.
 */
final class CannotRunTest extends TestCase
{
    private const string MIGRATION = 'database/migrations/2026_01_01_000000_create_users_table.php';

    public function testNoBaseIsMissingNotAPass(): void
    {
        $repository = $this->repository();

        $this->assertCannotRun('MISSING — --base=<ref> is required', $repository->root);
        $this->assertCannotRun('MISSING — --base=<ref> is required', $repository->root, '--base=');
    }

    public function testABaseThatDoesNotResolveIsMissing(): void
    {
        $repository = $this->repository();

        $this->assertCannotRun("MISSING — Base 'origin/main' does not resolve to a commit here", $repository->root, '--base=origin/main');
    }

    public function testABaseThatIsNotACommitIsMissing(): void
    {
        $repository = $this->repository();

        $this->assertCannotRun("MISSING — Base 'main^{tree}' does not resolve to a commit here", $repository->root, '--base=main^{tree}');
    }

    public function testABaseSharingNoHistoryIsMissing(): void
    {
        $repository = $this->repository();
        $repository->git('checkout', '--quiet', '--orphan', 'unrelated');
        $repository->commit('unrelated root');

        $this->assertCannotRun("MISSING — 'main' and HEAD share no merge base", $repository->root, '--base=main');
    }

    public function testADirectoryOutsideAnyRepositoryIsMissing(): void
    {
        $directory = sys_get_temp_dir() . '/diff-guards-norepo-' . bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $this->assertCannotRun('is not inside a git work tree', $directory, '--base=main');
        } finally {
            rmdir($directory);
        }
    }

    public function testADirectoryThatDoesNotExistIsMissing(): void
    {
        $this->assertCannotRun('MISSING — /nonexistent/diff-guards is not a directory', '/nonexistent/diff-guards', '--base=main');
    }

    public function testAnIgnoreFileThatDoesNotParseIsInvalid(): void
    {
        $repository = $this->repository()->branch('feature')->write('phpstan.neon', "parameters:\n  ignoreErrors: [\n")->commit();

        $this->assertCannotRun('INVALID — phpstan.neon does not parse as NEON', $repository->root, '--base=main');
    }

    public function testAnExplicitConfigThatDoesNotExistIsMissing(): void
    {
        $repository = $this->repository();

        $this->assertCannotRun('MISSING — Config file nope.json does not exist', $repository->root, '--base=main', '--config=nope.json');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'not JSON' => ['{', 'is not valid JSON'];
        yield 'a list' => ['["x"]', 'must hold a JSON object'];
        yield 'a scalar' => ['3', 'must hold a JSON object'];
        yield 'unknown key' => ['{"migration": ["db"]}', "unknown key(s): migration.\n"];
        yield 'migrations not a list' => ['{"migrations": "database/migrations"}', "'migrations' must be a non-empty list of paths"];
        yield 'migrations empty' => ['{"migrations": []}', "'migrations' must be a non-empty list of paths"];
        yield 'migrations a map' => ['{"migrations": {"a": "b"}}', "'migrations' must be a non-empty list of paths"];
        yield 'phpstanConfigs blank entry' => ['{"phpstanConfigs": [" / "]}', "every 'phpstanConfigs' entry must be a non-empty path"];
        yield 'phpstanConfigs non-string entry' => ['{"phpstanConfigs": [3]}', "every 'phpstanConfigs' entry must be a non-empty path"];
        yield 'waivers a map' => ['{"waivers": {"a": 1}}', "'waivers' must be a list"];
        yield 'waiver not an object' => ['{"waivers": ["x"]}', 'waiver #0 must be an object with only check, path and reason'];
        yield 'waiver unknown key' => ['{"waivers": [{"check": "edited-migration", "path": "x", "reason": "y", "until": "z"}]}', 'waiver #0 must be an object'];
        yield 'waiver unknown check' => ['{"waivers": [{"check": "edited-migrations", "path": "x", "reason": "y"}]}', 'waiver #0 names no known check (edited-migration, ignore-growth)'];
        yield 'waiver no path' => ['{"waivers": [{"check": "edited-migration", "reason": "y"}]}', 'waiver #0 names no path'];
        yield 'waiver blank path' => ['{"waivers": [{"check": "edited-migration", "path": " ", "reason": "y"}]}', 'waiver #0 names no path'];
        yield 'waiver no reason' => ['{"waivers": [{"check": "edited-migration", "path": "x"}]}', 'waiver #0 for x carries no reason'];
        yield 'waiver blank reason' => ['{"waivers": [{"check": "edited-migration", "path": "x", "reason": "  "}]}', 'waiver #0 for x carries no reason'];
        yield 'second waiver bad' => ['{"waivers": [{"check": "edited-migration", "path": "x", "reason": "y"}, {"check": "ignore-growth", "path": "z", "reason": ""}]}', 'waiver #1 for z carries no reason'];
    }

    #[DataProvider('invalidConfigs')]
    public function testAnInvalidConfigIsRefusedWhole(string $json, string $reason): void
    {
        $repository = $this->repository()->branch('feature')->write(self::MIGRATION, '<?php // edited')->write('warroom-diff-guards.json', $json)->commit();

        $this->assertCannotRun('INVALID — warroom-diff-guards.json', $repository->root, '--base=main');
        $this->assertCannotRun($reason, $repository->root, '--base=main');
    }

    public function testAnUnknownArgumentOrFormatIsInvalid(): void
    {
        $repository = $this->repository();

        $this->assertCannotRun("INVALID — Unknown argument '--bsae=main'", $repository->root, '--bsae=main');
        $this->assertCannotRun("INVALID — Unknown --format 'json'", $repository->root, '--base=main', '--format=json');
        $this->assertCannotRun('INVALID — --base needs a value', $repository->root, '--base');
        $this->assertCannotRun("INVALID — Unknown argument 'x--base=main'.\nUsage: warroom-diff-guards", $repository->root, 'x--base=main');
    }

    public function testCannotRunIsAnAnnotationInGithubFormat(): void
    {
        $repository = $this->repository();
        [$exit, $output] = $this->guard($repository->root, '--format=github', '--base=missing');

        self::assertSame(2, $exit);
        self::assertSame("::error title=diff-guards MISSING::diff-guards: MISSING — Base 'missing' does not resolve to a commit here. Fetch it first.\n", $output);
    }

    private function repository(): GitRepository
    {
        return (new GitRepository)->write(self::MIGRATION, '<?php')->write('phpstan.neon', "parameters:\n    level: max\n")->commit('base');
    }

    private function assertCannotRun(string $expected, string $directory, string ...$arguments): void
    {
        [$exit, $output] = $this->guard($directory, ...$arguments);

        self::assertSame(Application::CANNOT_RUN, $exit, $output);
        self::assertStringStartsWith('diff-guards: ', $output);
        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString('PASSED', $output);
    }

    /**
     * @return array{int, string}
     */
    private function guard(string $directory, string ...$arguments): array
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $exit = (new Application)->run(array_values($arguments), $directory, $output);
        rewind($output);

        return [$exit, (string) stream_get_contents($output)];
    }
}
