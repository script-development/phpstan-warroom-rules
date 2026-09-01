<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ReflectionClass;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidCredentialCastBypassRule;
use ScriptDevelopment\PhpstanWarroomRules\Tests\Support\ThrowingParser;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function is_string;
use function preg_match;
use function preg_match_all;
use function realpath;
use function sprintf;
use function str_starts_with;

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

    private const string CAST_DISPATCH_WRITES = __DIR__ . '/../Fixtures/CredentialCastBypass/CastDispatchWrites.php';

    private const string DISPATCH_NAMESPACE = 'App\Models\CredentialCastBypass\Dispatch\\';

    /** The one write site in the shape fixture with no table row — see its test. */
    private const string DOCUMENTED_FALSE_NEGATIVE = 'MergesCastsInConstructor';

    /**
     * The cast-declaration shape table, in the fixture's own order.
     *
     * `payload` is fixture CONTENT — the columns that shape's write names.
     * `naive` is what a merge-every-declaration reading of the ancestry would
     * flag, recorded ONLY as the denominator for
     * `testTheRuleAgreesWithPhpsOwnCastResolutionForEveryDeclarationShape`: it
     * is never asserted as behaviour, and its job is to fail loudly if the table
     * ever stops distinguishing the two readings. The EXPECTATION is computed
     * from PHP, never written here.
     *
     * @var array<string, array{payload: list<string>, naive: list<string>}>
     */
    private const array CAST_DISPATCH_TABLE = [
        // Both readings agree: the declaration that runs is the only one there is.
        'LeafMethod' => ['payload' => ['password'], 'naive' => ['password']],
        'InheritedMethod' => ['payload' => ['password'], 'naive' => ['password']],
        // Dispatch stops at the override, so the parent's `password` cast does
        // not exist — the merge reading invents it.
        'ReplacingOverride' => ['payload' => ['password'], 'naive' => ['password']],
        // The same replacement naming the parent's own column: a key collision
        // hides the merge reading's error, which is why the row above exists.
        'ReplacingOverrideSameColumn' => ['payload' => ['password'], 'naive' => []],
        'PassThroughOverride' => ['payload' => ['password'], 'naive' => ['password']],
        'ComposingOverride' => ['payload' => ['password', 'api_token'], 'naive' => ['password', 'api_token']],
        'SpreadingOverride' => ['payload' => ['password', 'api_token'], 'naive' => ['password', 'api_token']],
        // Nearest wins on a shared column: the leaf downgrades what it composed.
        'ComposingOverrideShadowingParent' => ['payload' => ['password'], 'naive' => ['password']],
        // Two hops from the declaration, through a silent middle.
        'TwoHopInherited' => ['payload' => ['password'], 'naive' => ['password']],
        // A foreign static call is not `parent::casts()`, so the walk stops.
        'ReplacingOverrideWithForeignStaticCall' => ['payload' => ['password'], 'naive' => ['password']],
        // Trait adaptation: a first-match walk over the imported traits picks the
        // EXCLUDED declaration and reports a cast the model does not have.
        'TraitMethodExcludedByInsteadOf' => ['payload' => ['password'], 'naive' => ['password']],
        // A `parent::casts()` whose result is discarded contributes nothing.
        'DiscardedParentCastsCall' => ['payload' => ['password'], 'naive' => ['password']],
        // ...but one captured in a variable does — the fail-open guard.
        'ParentCastsCapturedInVariable' => ['payload' => ['password', 'api_token'], 'naive' => ['password', 'api_token']],
        // Branches disagreeing on one column: the credential cast wins.
        'ConditionalReturnsDisagreeing' => ['payload' => ['password'], 'naive' => ['password']],
        'PropertyBase' => ['payload' => ['password'], 'naive' => ['password']],
        // A property IS inherited, unlike a replaced method body.
        'InheritedProperty' => ['payload' => ['password'], 'naive' => ['password']],
        // PHP keeps ONE property declaration; the merge reading keeps both.
        'RedeclaringProperty' => ['payload' => ['password'], 'naive' => ['password']],
        // `array_merge($this->casts, $this->casts())` — the method always wins,
        // whichever form the author wrote first.
        'PropertyThenMethod' => ['payload' => ['password'], 'naive' => []],
        // The method half wins even when the method comes from a TRAIT and the
        // property from the class — a shape no formatter can reorder away.
        'TraitMethodAndClassProperty' => ['payload' => ['password'], 'naive' => ['password']],
        // A class-declared `casts()` means the trait's body never runs.
        'TraitMethodOverridden' => ['payload' => ['trait_method_secret'], 'naive' => ['trait_method_secret']],
        'TraitMethodInherited' => ['payload' => ['trait_method_secret'], 'naive' => ['trait_method_secret']],
        'GrandMethodBase' => ['payload' => ['grand_secret'], 'naive' => ['grand_secret']],
        'MidReplacing' => ['payload' => ['grand_secret'], 'naive' => ['grand_secret']],
        // Composes with a parent that CUT the chain: walking up on a parent call
        // is not walking the whole ancestry.
        'LeafComposingOverMidReplacing' => [
            'payload' => ['grand_secret', 'mid_plain', 'leaf_secret'],
            'naive' => ['grand_secret', 'leaf_secret'],
        ],
    ];

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
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 24],
            [sprintf(self::MESSAGE, 'api_token', 'App\Models\CredentialCastBypass\User', 'encrypted', 'update'), 29],
            [sprintf(self::MESSAGE, 'recovery_codes', 'App\Models\CredentialCastBypass\User', 'encrypted:array', 'update'), 34],
            [sprintf(self::MESSAGE, 'secret', 'App\Models\CredentialCastBypass\ApiKey', 'encrypted', 'update'), 39],
            [sprintf(self::MESSAGE, 'passphrase', 'App\Models\CredentialCastBypass\Vault', 'hashed', 'update'), 44],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 51],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'insert'), 56],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'upsert'), 64],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'updateOrInsert'), 69],
            [sprintf(self::MESSAGE, 'secret', 'App\Models\CredentialCastBypass\ApiKey', 'encrypted', 'update'), 74],
            [sprintf(self::MESSAGE, 'trait_secret', 'App\Models\CredentialCastBypass\TraitCastModel', 'hashed', 'update'), 79],
            [sprintf(self::MESSAGE, 'trait_notes', 'App\Models\CredentialCastBypass\TraitCastModel', 'encrypted', 'update'), 84],
            [sprintf(self::MESSAGE, 'api_token', 'App\Models\CredentialCastBypass\User', 'encrypted', 'update'), 89],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 89],
            // crit round 2, issue 1 — the leaf's OWN cast, added through
            // `array_merge(parent::casts(), …)`. Silent before the fix while
            // line 97's inherited cast fired, so the same model was
            // half-enforced.
            [sprintf(self::MESSAGE, 'composed_secret', 'App\Models\CredentialCastBypass\ComposedCastModel', 'hashed', 'update'), 94],
            [sprintf(self::MESSAGE, 'passphrase', 'App\Models\CredentialCastBypass\ComposedCastModel', 'hashed', 'update'), 99],
            [sprintf(self::MESSAGE, 'spread_secret', 'App\Models\CredentialCastBypass\SpreadCastModel', 'encrypted', 'update'), 104],
            // Composed maps must contribute their OWN pairs and nothing else —
            // the nested and callback literals on this model are pinned clean in
            // testModelPathAndNonCredentialWritesAreClean.
            [sprintf(self::MESSAGE, 'real_secret', 'App\Models\CredentialCastBypass\NestedLiteralCastModel', 'hashed', 'update'), 109],
            // Verbs that were on the list with no site of their own, so a
            // regression dropping either would not have shown up anywhere.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'insertOrIgnore'), 119],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'insertGetId'), 124],
            // The increment family: the counter column is innocent, the EXTRA
            // payload is an ordinary uncast write that reaches SQL through
            // `update(array_merge($columns, $extra))`.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'increment'), 135],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'decrement'), 140],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'incrementEach'), 145],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'decrementEach'), 150],
            // A NAMED argument sits at a different index than its parameter's
            // position, so a position-only reading is silent here.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'increment'), 164],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 169],
            // A named argument AFTER the payload must not blind the payload's own
            // positional read.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'upsert'), 178],
            // Postgres-only payload writes, forwarded by Eloquent's __call.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'updateFrom'), 188],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'insertOrIgnoreReturning'), 193],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'incrementOrCreate'), 203],
            // MODEL receivers — the one family where the model path bypasses
            // casts, so the receiver type gate must not exclude them.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'increment'), 215],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'decrementEach'), 220],
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'incrementQuietly'), 225],
            // Union receiver, castless branch first: reading only the first
            // branch reports nothing while the other branch writes plaintext.
            [sprintf(self::MESSAGE, 'password', 'App\Models\CredentialCastBypass\User', 'hashed', 'update'), 239],
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

    // ------------------------------------------------- DECLARATION SHAPES ---

    /**
     * The rule's cast map must equal the one PHP and Laravel would actually
     * build, for every shape a consumer model can declare casts in. Nothing here
     * asserts a hand-written expectation: for each shape the expectation is
     * COMPUTED from PHP — the property default PHP itself resolved
     * (`getDefaultProperties()`, which honours a redeclaration replacing its
     * parent's and a class default replacing a trait's) merged under a real
     * virtual dispatch of `casts()`, in the order
     * `HasAttributes::initializeHasAttributes()` merges them.
     *
     * Why the expectation is computed rather than written: a hand-written one
     * encodes the author's reading of Laravel, which is exactly what was wrong.
     * A merge-every-declaration reading of the ancestry, leaf wins, is wrong on
     * NINE of these shapes — eight inventing a credential cast the model does not
     * have, one calling a readable declaration unreadable — each masked in the
     * older fixtures by a key collision. Resolving the method half by first match
     * over the imported traits is wrong on two OTHERS (a trait `insteadof`, and a
     * discarded `parent::casts()`), which is why shapes refuting both readings are
     * kept: a table that only refutes an abandoned reading measures nothing. A rule
     * that invents a credential cast blocks a consumer's CI on a correct write;
     * on a security rule that spends the gate's authority faster than a missed
     * catch.
     *
     * The `naive` column of the table records what that merge-everything reading
     * would flag. It is never asserted as behaviour — it is the DENOMINATOR: if
     * the two readings stopped disagreeing on most of these rows, this test
     * would be measuring nothing, and the count assertion below fails rather
     * than passing quietly.
     */
    public function testTheRuleAgreesWithPhpsOwnCastResolutionForEveryDeclarationShape(): void
    {
        $rowsExpectingAnError = 0;
        $rowsWhereTheTwoReadingsDisagree = 0;

        foreach (self::CAST_DISPATCH_TABLE as $model => $row) {
            $flagged = array_keys($this->credentialCastsInPayloadOrder($model, $row['payload']));

            if ($flagged !== []) {
                $rowsExpectingAnError++;
            }

            if ($flagged !== array_values(array_intersect($row['payload'], $row['naive']))) {
                $rowsWhereTheTwoReadingsDisagree++;
            }
        }

        self::assertGreaterThanOrEqual(
            8,
            $rowsExpectingAnError,
            'The shape table stopped expecting errors, so a rule reporting nothing at all would pass it.',
        );

        self::assertGreaterThanOrEqual(
            6,
            $rowsWhereTheTwoReadingsDisagree,
            'The shape table no longer distinguishes PHP\'s resolution from a merge-every-declaration reading, so it can no longer catch the defect it exists for.',
        );

        $this->analyse([self::CAST_DISPATCH_WRITES], $this->expectedCastDispatchErrors());
    }

    /**
     * `mergeCasts()` at construct time is an accepted false NEGATIVE: no
     * declaration exists to read, so this write is silent even though the
     * constructed model really does carry the cast. Documented rather than
     * diagnosed, on measured grounds — across the war-room fleet `mergeCasts()`
     * appears in application code exactly once, inside a copy-pasted
     * `newInstance()` override that propagates a map the rule already reads, and
     * `withCasts()` once, on a non-credential column. A diagnostic here would
     * fire on neither a real bypass nor nothing at all: its only fleet target
     * today is a false positive.
     *
     * Pinned so that changing it is a visible decision rather than a drift.
     */
    public function testMergeCastsAtConstructTimeIsAnAcceptedFalseNegative(): void
    {
        $lines = $this->castDispatchWriteLines();

        $reflection = new ReflectionClass(self::DISPATCH_NAMESPACE . self::DOCUMENTED_FALSE_NEGATIVE);

        // The declaration halves the rule CAN read are both empty here, which is
        // why it stays silent — not because the fixture forgot to declare a cast.
        self::assertSame([], $reflection->getDefaultProperties()['casts'] ?? null);
        self::assertSame([], $this->credentialCastsAccordingToPhp($reflection->getName()));
        self::assertArrayHasKey(self::DOCUMENTED_FALSE_NEGATIVE, $lines);

        $this->analyse([self::CAST_DISPATCH_WRITES], $this->expectedCastDispatchErrors());
    }

    /**
     * Every payload slot names a parameter that EXISTS on the Laravel method, at
     * the position the rule reads.
     *
     * The rule addresses a payload by name first and position second, so each
     * slot is two claims about `illuminate/database`: that a parameter of that
     * name exists, and that it sits at that index. A Laravel rename or a
     * reordered signature would break the named lookup silently — the rule would
     * simply stop seeing named payloads, with no error anywhere — and a shifted
     * position would make it read the wrong argument. Neither shows up in any
     * other test here, because the fixtures call these methods positionally with
     * the arguments the rule already expects.
     *
     * Verbs are matched against whichever of the three receiver classes declares
     * them; the increment family exists on more than one, and the parameter names
     * agree, so the first hit is authoritative.
     */
    public function testEveryPayloadSlotMatchesTheLaravelParameterItNames(): void
    {
        $declarers = [QueryBuilder::class, EloquentBuilder::class, Model::class];
        $checked = 0;

        foreach ($this->writeMethodSlots() as $method => $slots) {
            $reflection = null;

            foreach ($declarers as $class) {
                if ((new ReflectionClass($class))->hasMethod($method)) {
                    $reflection = (new ReflectionClass($class))->getMethod($method);

                    break;
                }
            }

            self::assertNotNull(
                $reflection,
                sprintf('The rule reads payloads from %s(), which no Laravel receiver class declares.', $method),
            );

            $parameters = $reflection->getParameters();

            foreach ($slots as [$name, $position]) {
                self::assertArrayHasKey(
                    $position,
                    $parameters,
                    sprintf('%s() has no parameter at position %d.', $method, $position),
                );
                self::assertSame(
                    $name,
                    $parameters[$position]->getName(),
                    sprintf(
                        '%s() parameter %d is $%s, not $%s — the rule\'s named-argument lookup would silently never match.',
                        $method,
                        $position,
                        $parameters[$position]->getName(),
                        $name,
                    ),
                );
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(
            20,
            $checked,
            'The slot map stopped being read, so this test would pass against an empty rule.',
        );
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

            return preg_match_all('/(->|::)(update|insert|insertOrIgnore|insertGetId|upsert|updateOrInsert|updateFrom|insertOrIgnoreReturning|increment|decrement|incrementEach|decrementEach|incrementQuietly|decrementQuietly|incrementEachQuietly|decrementEachQuietly|incrementOrCreate|create|updateOrCreate|save)\(/', $source);
        };

        self::assertGreaterThanOrEqual(34, $writeCalls(self::BUILDER_WRITES), 'The violating fixture lost write sites.');
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
            // The shape table's classes share ONE file, so a duplicate FQCN
            // would redirect every row of it at once rather than one model.
            ...array_map(
                static fn(string $shape): string => self::DISPATCH_NAMESPACE . $shape,
                [
                    ...array_keys(self::CAST_DISPATCH_TABLE),
                    self::DOCUMENTED_FALSE_NEGATIVE,
                    'MethodBase',
                    'SilentMiddle',
                    'ForeignCastSource',
                    'PropertyBase',
                    'GrandMethodBase',
                    'MidReplacing',
                    'DeclaresSecretViaMethod',
                    'DeclaresPlainPasswordViaMethod',
                    'DeclaresPlainPasswordViaTraitToBeExcluded',
                    'DeclaresHashedPasswordViaTraitToBeExcluded',
                ],
            ),
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

    /**
     * The rule's own payload-slot map, read off the rule rather than restated —
     * a copy here would drift and this test would then verify the copy.
     *
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private function writeMethodSlots(): array
    {
        $slots = (new ReflectionClass(ForbidCredentialCastBypassRule::class))->getConstant('WRITE_METHODS');

        self::assertIsArray($slots);

        return $slots;
    }

    /**
     * PHP's own answer for one model, as `column => cast`, restricted to the
     * credential casts. Built the way Laravel builds it — property default
     * first, a real dispatch of `casts()` second — and deliberately NOT by
     * asking the rule.
     *
     * `newInstanceWithoutConstructor()` because a constructed Eloquent model
     * boots its traits, which needs a container this suite does not have; the
     * `casts()` body of a fixture model touches no state.
     *
     * @return array<string, string>
     */
    private function credentialCastsAccordingToPhp(string $fqcn): array
    {
        $reflection = new ReflectionClass($fqcn);

        $property = $reflection->getDefaultProperties()['casts'] ?? [];

        self::assertIsArray($property);

        $dispatched = $reflection->getMethod('casts')->invoke($reflection->newInstanceWithoutConstructor());

        self::assertIsArray($dispatched);

        $credentialCasts = [];

        foreach (array_merge($property, $dispatched) as $column => $cast) {
            if (!is_string($column) || !is_string($cast)) {
                continue;
            }

            if ($cast === 'hashed' || $cast === 'encrypted' || str_starts_with($cast, 'encrypted:')) {
                $credentialCasts[$column] = $cast;
            }
        }

        return $credentialCasts;
    }

    /**
     * `short model name => line` for every write site in the shape fixture,
     * reconciled against the table so a pattern that silently stops matching
     * cannot shrink the population instead of failing.
     *
     * @return array<string, int>
     */
    private function castDispatchWriteLines(): array
    {
        $source = file_get_contents(self::CAST_DISPATCH_WRITES);

        self::assertNotFalse($source, 'The shape write fixture could not be read.');

        $lines = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (preg_match('/^\s+([A-Za-z]+)::query\(\)->update\(/', $line, $matches) === 1) {
                $lines[$matches[1]] = $index + 1;
            }
        }

        self::assertSame(
            [...array_keys(self::CAST_DISPATCH_TABLE), self::DOCUMENTED_FALSE_NEGATIVE],
            array_keys($lines),
            'The shape fixture and the shape table have drifted apart — every write site must have a table row, in order, and the only site without one is the documented false negative.',
        );

        return $lines;
    }

    /**
     * The credential casts PHP resolves for one shape, restricted to the
     * columns its write payload names and ordered the way the rule walks that
     * payload — which is the order it emits errors within one line.
     *
     * @param list<string> $payload
     *
     * @return array<string, string>
     */
    private function credentialCastsInPayloadOrder(string $model, array $payload): array
    {
        $truth = $this->credentialCastsAccordingToPhp(self::DISPATCH_NAMESPACE . $model);
        $flagged = [];

        foreach ($payload as $column) {
            if (array_key_exists($column, $truth)) {
                $flagged[$column] = $truth[$column];
            }
        }

        return $flagged;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function expectedCastDispatchErrors(): array
    {
        $lines = $this->castDispatchWriteLines();
        $expected = [];

        foreach (self::CAST_DISPATCH_TABLE as $model => $row) {
            foreach ($this->credentialCastsInPayloadOrder($model, $row['payload']) as $column => $cast) {
                $expected[] = [
                    sprintf(self::MESSAGE, $column, self::DISPATCH_NAMESPACE . $model, $cast, 'update'),
                    $lines[$model],
                ];
            }
        }

        return $expected;
    }
}
