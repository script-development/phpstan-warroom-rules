<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidCredentialCastBypassRule;
use ScriptDevelopment\PhpstanWarroomRules\Tests\Support\ThrowingParser;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function realpath;
use function sprintf;

/**
 * @extends RuleTestCase<ForbidCredentialCastBypassRule>
 */
final class ForbidCredentialCastBypassRuleTest extends RuleTestCase
{
    private const string MESSAGE = "Attribute '%s' on %s carries the '%s' cast, but %s() is a query-builder write that bypasses the cast and stores the raw value. Write it through the model path instead (assign the attribute and save the model).";

    private const string BUILDER_WRITES = __DIR__ . '/../Fixtures/CredentialCastBypass/BuilderWrites.php';

    private const string CLEAN_WRITES = __DIR__ . '/../Fixtures/CredentialCastBypass/CleanWrites.php';

    private const string RAW_TABLE_WRITES = __DIR__ . '/../Fixtures/CredentialCastBypass/RawTableWrites.php';

    private const string SINGLE_UNCAST_WRITE = __DIR__ . '/../Fixtures/CredentialCastBypass/SingleUncastWrite.php';

    private const string UNREADABLE_MESSAGE = 'Cannot verify this write against %s: the PHP declaring %s could not be located or parsed, so the credential-cast map is incomplete and a hashed/encrypted column in this payload would go unreported. Fix the source, or suppress forbidCredentialCastBypass.modelSourceUnreadable here if the write is known safe.';

    private const string INCOMPLETE_MESSAGE = 'Cannot verify this write against %s: %s declares casts in a form this rule cannot read (a casts() return or a $casts default carrying no array literal, such as a class constant or a helper call), so the credential-cast map is incomplete and a hashed/encrypted column in this payload would go unreported. Restate the credential columns as literal string pairs, or suppress forbidCredentialCastBypass.castMapIncomplete here if the write is known safe.';

    private const string CONFIGURED_MODEL_MISSING_MESSAGE = 'Cannot verify this write: credentialCastTableModels maps this table to %s, which does not exist, so no credential-cast map could be read and a hashed/encrypted column in this payload would go unreported. Fix the FQCN in the parameter, or remove the mapping if the table is no longer covered.';

    private const string UNINTERPRETABLE_CAST_WRITES = __DIR__ . '/../Fixtures/CredentialCastBypass/UninterpretableCastWrites.php';

    /**
     * Table-to-model map used by the `DB::table()` tests. Left null so the
     * default (empty map, `DB::table()` silent) is what every other test sees.
     *
     * @var array<string, string>|null
     */
    private ?array $tableModelOverride = null;

    /**
     * When set, `getRule()` injects a parser that throws for any file whose path
     * ends with this suffix — the only way to exercise the unreadable-source
     * branch without shipping a syntactically broken fixture (which would break
     * the classmap for the whole suite).
     */
    private ?string $unparsableFileSuffix = null;

    // ---------------------------------------------------------------- RED ---

    public function testHashedCastColumnInBuilderUpdateIsFlagged(): void
    {
        $this->analyse([self::BUILDER_WRITES], [
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 23],
            [sprintf(self::MESSAGE, 'api_token', 'App\Models\CredentialCastBypass\User', 'encrypted', 'update'), 28],
            [sprintf(self::MESSAGE, 'recovery_codes', 'App\Models\CredentialCastBypass\User', 'encrypted:array', 'update'), 33],
            [sprintf(self::MESSAGE, 'secret', 'App\Models\CredentialCastBypass\ApiKey', 'encrypted', 'update'), 38],
            [sprintf(self::MESSAGE, 'passphrase', 'App\Models\CredentialCastBypass\Vault', 'hashed', 'update'), 43],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 50],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'insert'), 55],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'upsert'), 63],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'updateOrInsert'), 68],
            [sprintf(self::MESSAGE, 'secret', 'App\Models\CredentialCastBypass\ApiKey', 'encrypted', 'update'), 73],
            [sprintf(self::MESSAGE, 'trait_secret', 'App\Models\CredentialCastBypass\TraitCastModel', 'hashed', 'update'), 78],
            [sprintf(self::MESSAGE, 'trait_notes', 'App\Models\CredentialCastBypass\TraitCastModel', 'encrypted', 'update'), 83],
            [sprintf(self::MESSAGE, 'api_token', 'App\Models\CredentialCastBypass\User', 'encrypted', 'update'), 88],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 88],
            // crit round 2, issue 1 — the leaf's OWN cast, added through
            // `array_merge(parent::casts(), …)`. Silent before the fix while
            // line 97's inherited cast fired, so the same model was
            // half-enforced.
            [sprintf(self::MESSAGE, 'composed_secret', 'App\Models\CredentialCastBypass\ComposedCastModel', 'hashed', 'update'), 93],
            [sprintf(self::MESSAGE, 'passphrase', 'App\Models\CredentialCastBypass\ComposedCastModel', 'hashed', 'update'), 98],
            [sprintf(self::MESSAGE, 'spread_secret', 'App\Models\CredentialCastBypass\SpreadCastModel', 'encrypted', 'update'), 103],
            // Composed maps must contribute their OWN pairs and nothing else —
            // the nested and callback literals on this model are pinned clean in
            // testModelPathAndNonCredentialWritesAreClean.
            [sprintf(self::MESSAGE, 'real_secret', 'App\Models\CredentialCastBypass\NestedLiteralCastModel', 'hashed', 'update'), 108],
        ]);
    }

    public function testRawTableWriteIsFlaggedOnlyForMappedTables(): void
    {
        $this->tableModelOverride = ['users' => 'App\Models\CredentialCastBypass\User'];

        $this->analyse([self::RAW_TABLE_WRITES], [
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 18],
            [sprintf(self::MESSAGE, 'api_token', 'App\Models\CredentialCastBypass\User', 'encrypted', 'update'), 23],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 33],
        ]);
    }

    // -------------------------------------------------------------- GREEN ---

    public function testModelPathAndNonCredentialWritesAreClean(): void
    {
        $this->analyse([self::CLEAN_WRITES], []);
    }

    public function testRawTableWritesAreSilentWithTheDefaultEmptyMap(): void
    {
        $this->analyse([self::RAW_TABLE_WRITES], []);
    }

    /**
     * crit round 1, issue 3. A declaring source that cannot be parsed yields the
     * same empty cast set as a model that genuinely declares none, so treating
     * the two alike makes the rule fail OPEN — silently passing every write to a
     * model it can no longer read. Doctrine requires MISSING to be a distinct
     * outcome from FAILED, so this reports under its own identifier.
     *
     * Both directions in ONE fixture: the same write site is silent under a
     * working parser (below) and loud when the model's PHP cannot be read.
     */
    public function testUnreadableModelSourceIsReportedRatherThanSilentlySkipped(): void
    {
        $this->unparsableFileSuffix = 'CredentialCastBypass/Article.php';

        $this->analyse([self::SINGLE_UNCAST_WRITE], [
            [
                sprintf(
                    self::UNREADABLE_MESSAGE,
                    'App\Models\CredentialCastBypass\Article',
                    'App\Models\CredentialCastBypass\Article',
                ),
                18,
            ],
        ]);
    }

    public function testTheSameWriteIsSilentWhenTheModelSourceParses(): void
    {
        $this->analyse([self::SINGLE_UNCAST_WRITE], []);
    }

    /**
     * crit round 2, issue 1 — the OTHER direction. `declaredCasts()` requires an
     * array literal; a `casts()` return or `$casts` default that carries none
     * (`return self::CASTS;`) was read as "declares nothing", so the write
     * passed silently. The source is readable, so `modelSourceUnreadable` never
     * fired either: the declaration SHAPE is what cannot be read, and it gets
     * its own identifier because the remediation is different.
     *
     * Reported regardless of payload, like the unreadable-source diagnostic —
     * neither payload below names a credential column, which is the point: with
     * an incomplete map the rule cannot claim the payload is clean.
     */
    public function testCastDeclarationsCarryingNoArrayLiteralAreReportedRatherThanReadAsCastless(): void
    {
        $this->analyse([self::UNINTERPRETABLE_CAST_WRITES], [
            [
                sprintf(
                    self::INCOMPLETE_MESSAGE,
                    'App\Models\CredentialCastBypass\ConstantCastModel',
                    'App\Models\CredentialCastBypass\ConstantCastModel',
                ),
                21,
            ],
            [
                sprintf(
                    self::INCOMPLETE_MESSAGE,
                    'App\Models\CredentialCastBypass\ConstantCastPropertyModel',
                    'App\Models\CredentialCastBypass\ConstantCastPropertyModel',
                ),
                26,
            ],
        ]);
    }

    /**
     * crit round 2, issue 2. `hasClass()` returning false was answered with the
     * same empty cast set as "this table is not mapped", so a typo or a stale
     * rename in `credentialCastTableModels` silently and permanently disarmed
     * the rule for that table — the exact fail-open shape the unreadable-source
     * identifier exists to prevent, arriving through configuration instead of
     * source.
     *
     * The three `users` sites fire; `articles` (unmapped) and the non-literal
     * table argument stay silent, so this also pins that a bad mapping does not
     * spray the diagnostic over tables it was never configured for.
     */
    public function testAMistypedConfiguredModelIsReportedRatherThanTreatedAsUnmapped(): void
    {
        $this->tableModelOverride = ['users' => 'App\Models\CredentialCastBypass\Uzer'];

        $message = sprintf(self::CONFIGURED_MODEL_MISSING_MESSAGE, 'App\Models\CredentialCastBypass\Uzer');

        $this->analyse([self::RAW_TABLE_WRITES], [
            [$message, 18],
            [$message, 23],
            [$message, 28],
            [$message, 33],
        ]);
    }

    // ---------------------------------------------------------- DENOMINATOR ---

    /**
     * § Null-Result corollary 1 — the green fixtures above report zero both when
     * the rule is correctly silent and when the fixture file is empty, renamed
     * or stopped being parsed. Assert the population the clean assertions are
     * measuring is non-zero, and that the red fixture really does carry a write
     * per flagged line.
     */
    public function testFixturePopulationIsNonZero(): void
    {
        $writeCalls = static function(string $file): int {
            $source = file_get_contents($file);

            self::assertNotFalse($source, sprintf('Fixture %s could not be read.', $file));

            return preg_match_all('/(->|::)(update|insert|insertOrIgnore|insertGetId|upsert|updateOrInsert|create|updateOrCreate|save)\(/', $source);
        };

        self::assertGreaterThanOrEqual(17, $writeCalls(self::BUILDER_WRITES), 'The violating fixture lost write sites.');
        self::assertGreaterThanOrEqual(14, $writeCalls(self::CLEAN_WRITES), 'The clean fixture lost write sites, so its zero proves nothing.');
        self::assertGreaterThanOrEqual(7, $writeCalls(self::RAW_TABLE_WRITES), 'The raw-table fixture lost write sites.');
    }

    /**
     * This rule is the first in the package to resolve a class FQCN to a FILE
     * and parse it, so it is uniquely sensitive to an FQCN declared twice in the
     * fixture corpus: reflection picks one declaration, and which one is
     * classmap-order dependent. Measured — the model fixtures were first written
     * in `App\Models`, where `User` already collided with three other rules'
     * stubs; the suite passed on one installed tree and failed on another purely
     * because `composer update` reordered the classmap.
     *
     * A comment saying "do not collide" would not survive; this does.
     */
    public function testEveryFixtureModelResolvesToThisRuleSOwnFixtureDirectory(): void
    {
        $models = [
            'App\Models\CredentialCastBypass\User',
            'App\Models\CredentialCastBypass\ApiKey',
            'App\Models\CredentialCastBypass\Vault',
            'App\Models\CredentialCastBypass\Article',
            'App\Models\CredentialCastBypass\OverridingVault',
            'App\Models\CredentialCastBypass\NearMissCastModel',
            'App\Models\CredentialCastBypass\AbstractCredentialHolder',
            'App\Models\CredentialCastBypass\TraitCastModel',
            'App\Models\CredentialCastBypass\TraitOverriddenCastModel',
            'App\Models\CredentialCastBypass\HasHashedSecret',
            'App\Models\CredentialCastBypass\HasEncryptedNotesProperty',
            'App\Models\CredentialCastBypass\ComposesHashedSecret',
            'App\Models\CredentialCastBypass\ComposedCastModel',
            'App\Models\CredentialCastBypass\SpreadCastModel',
            'App\Models\CredentialCastBypass\ConstantCastModel',
            'App\Models\CredentialCastBypass\ConstantCastPropertyModel',
            'App\Models\CredentialCastBypass\NestedLiteralCastModel',
        ];

        $reflectionProvider = self::createReflectionProvider();
        $expectedDirectory = realpath(__DIR__ . '/../Fixtures/CredentialCastBypass');

        self::assertNotFalse($expectedDirectory, 'The fixture directory is missing.');

        foreach ($models as $model) {
            self::assertTrue(
                $reflectionProvider->hasClass($model),
                sprintf('Fixture model %s does not resolve at all.', $model),
            );

            self::assertSame(
                $expectedDirectory,
                dirname((string) $reflectionProvider->getClass($model)->getFileName()),
                sprintf(
                    '%s resolves to a file outside this rule\'s fixture directory, so another fixture declares the same FQCN. The rule reads casts from the resolved FILE, so it would silently read the wrong model.',
                    $model,
                ),
            );
        }
    }

    protected function getRule(): Rule
    {
        $parser = self::getContainer()->getService('defaultAnalysisParser');

        self::assertInstanceOf(Parser::class, $parser);

        if ($this->unparsableFileSuffix !== null) {
            $parser = new ThrowingParser($parser, $this->unparsableFileSuffix);
        }

        return new ForbidCredentialCastBypassRule(
            self::createReflectionProvider(),
            $parser,
            $this->tableModelOverride ?? [],
        );
    }
}
