<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\DiffGuards;

use PHPUnit\Framework\TestCase;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\CheckResult;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Finding;
use ScriptDevelopment\PhpstanWarroomRules\DiffGuards\Reporter;

final class ReporterTest extends TestCase
{
    public function testWorkflowCommandValuesAreEscaped(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $finding = new Finding('ignore-growth', 'a,b:c%.neon', 3, "100% sure\r\nreally", 'x');

        (new Reporter('github', $output))->result(new CheckResult('ignore-growth', [$finding], 1, 'changed ignore file'), [$finding], 'backend/');
        rewind($output);

        self::assertSame(
            "::error file=backend/a%2Cb%3Ac%25.neon,line=3,title=ignore-growth::100%25 sure%0D%0Areally\nignore-growth: 1 finding over 1 changed ignore file\n",
            stream_get_contents($output),
        );
    }
}
