<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Tests\Rules;

use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Parser\Parser;
use PHPStan\Parser\RichParser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ReflectionClass;
use ScriptDevelopment\PhpstanWarroomRules\Collectors\CacheKeyTaintCollector;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidCredentialCastBypassRule;
use ScriptDevelopment\PhpstanWarroomRules\Rules\ForbidCredentialDerivedCacheKeyRule;

use function count;
use function file_get_contents;
use function preg_match_all;
use function sprintf;

/**
 * The fixtures port laravel-skeleton's `CacheKeyCredential` corpus (war-room
 * enforcement queue #24): the twelve violation shapes, the two shapes that read
 * the credential to call a provider and key the cache by id, and — in
 * `TypeResolved.php` and `FlowShapes.php` — the shapes that corpus's
 * name-matching scanner gets wrong in either direction.
 *
 * @extends RuleTestCase<ForbidCredentialDerivedCacheKeyRule>
 */
final class ForbidCredentialDerivedCacheKeyRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/CredentialDerivedCacheKey/';

    private const string MODELS = self::FIXTURES . 'Models.php';

    private const string MESSAGE = "Cache key passed to %s() derives from the encrypted attribute %s. Key the cache by the owning entity's id (war-room Principle 10): a digest of the credential is still keyed by it, lands in the cache store, and outlives a rotation.";

    private const string VAULT_KEY = 'App\CredentialDerivedCacheKey\Models\Vault::$api_key';

    private ?CacheKeyTaintCollector $collectorOverride = null;

    private ?ForbidCredentialDerivedCacheKeyRule $ruleOverride = null;

    public function testEverySingleClassViolationShapeReports(): void
    {
        $this->analyse([self::MODELS, self::FIXTURES . 'DirectKeys.php'], [
            [self::message('get', 'DirectKeys.php', 17), 17],
            [self::message('remember', 'DirectKeys.php', 34), 34],
            [self::message('remember', 'DirectKeys.php', 47), 47],
            [self::message('cache', 'DirectKeys.php', 61), 61],
            [sprintf(self::MESSAGE, 'many', 'App\CredentialDerivedCacheKey\Models\Vault::$settings (read at DirectKeys.php:81)'), 81],
            [sprintf(self::MESSAGE, 'tags', 'App\CredentialDerivedCacheKey\Models\Ledger::$iban (read at DirectKeys.php:98)'), 98],
            [self::message('get', 'DirectKeys.php', 116), 116],
            [self::message('get', 'DirectKeys.php', 134), 134],
        ]);
    }

    /**
     * The queue #24 seed: the digest is computed in a key object's constructor,
     * and all three cache calls that use it — one of them in a private method
     * handed the object — report the read that built it.
     */
    public function testADigestBuiltInsideAKeyObjectReportsAtEveryCacheCallUsingIt(): void
    {
        $this->analyse([self::MODELS, self::FIXTURES . 'HashedKeyObject.php'], [
            [self::message('lock', 'HashedKeyObject.php', 28), 36],
            [self::message('get', 'HashedKeyObject.php', 28), 39],
            [self::message('put', 'HashedKeyObject.php', 28), 49],
        ]);
    }

    public function testAKeyDerivedInAnotherClassReportsAtTheCacheCall(): void
    {
        $this->analyse([self::MODELS, self::FIXTURES . 'KeyObjects.php'], [
            [self::message('get', 'KeyObjects.php', 30), 22],
            [self::message('get', 'KeyObjects.php', 61), 51],
            [self::message('get', 'KeyObjects.php', 107), 87],
        ]);
    }

    /**
     * Both shapes READ the credential — the control below proves it, so a rule
     * that stopped seeing reads at all could not pass here by accident.
     */
    public function testShapesThatReadTheCredentialOnlyToCallTheProviderStayQuiet(): void
    {
        $source = file_get_contents(self::FIXTURES . 'CorrectKeys.php');

        self::assertNotFalse($source);
        self::assertSame(2, preg_match_all('/\$vault->api_key\)/', $source));

        $this->analyse([self::MODELS, self::FIXTURES . 'CorrectKeys.php'], []);
    }

    /**
     * Each shape here is one the name-matching scanner gets wrong: it reports
     * `Widget::$api_key` (not encrypted) and the id-keyed helper call, and it
     * misses the docblock-typed handle, the array-access read and the trait.
     */
    public function testTypeResolutionSettlesWhatAttributeNamesCannot(): void
    {
        $this->analyse([self::MODELS, self::FIXTURES . 'TypeResolved.php'], [
            [self::message('get', 'TypeResolved.php', 46), 46],
            [self::message('get', 'TypeResolved.php', 63), 63],
            [self::message('get', 'TypeResolved.php', 78), 92],
            [self::message('get', 'TypeResolved.php', 132), 132],
        ]);
    }

    /**
     * One method per propagation or sink arm; the count assertion keeps the
     * `leaks…` population and this list from drifting apart.
     */
    public function testEveryPropagationAndSinkArmReportsOnlyWhereItLeaks(): void
    {
        $expected = [
            [self::message('get', 'FlowShapes.php', 36), 38],
            [self::message('get', 'FlowShapes.php', 44), 46],
            [self::message('get', 'FlowShapes.php', 51), 53],
            [self::message('forget', 'FlowShapes.php', 58), 59],
            [self::message('forget', 'FlowShapes.php', 65), 66],
            [self::message('get', 'FlowShapes.php', 72), 74],
            [self::message('get', 'FlowShapes.php', 79), 81],
            [self::message('get', 'FlowShapes.php', 86), 88],
            [self::message('get', 'FlowShapes.php', 93), 93],
            [sprintf(self::MESSAGE, 'get', 'App\CredentialDerivedCacheKey\Models\Ledger::$iban (read at FlowShapes.php:98)'), 98],
            [self::message('get', 'FlowShapes.php', 103), 103],
            [self::message('get', 'FlowShapes.php', 108), 108],
            [self::message('get', 'FlowShapes.php', 113), 113],
            [self::message('tooManyAttempts', 'FlowShapes.php', 118), 118],
            [self::message('hit', 'FlowShapes.php', 123), 123],
            [self::message('get', 'FlowShapes.php', 128), 128],
            [self::message('get', 'FlowShapes.php', 133), 133],
            [self::message('get', 'FlowShapes.php', 138), 138],
            [self::message('putMany', 'FlowShapes.php', 148), 148],
            [self::message('many', 'FlowShapes.php', 153), 153],
            [self::message('get', 'FlowShapes.php', 159), 158],
            [sprintf(self::MESSAGE, 'get', 'App\CredentialDerivedCacheKey\Models\Ledger::$iban (read at FlowShapes.php:166), ' . self::VAULT_KEY . ' (read at FlowShapes.php:166)'), 166],
            [self::message('get', 'FlowShapes.php', 171), 171],
            [sprintf(self::MESSAGE, 'get', self::VAULT_KEY . ' (read at FlowShapes.php:347), App\CredentialDerivedCacheKey\Models\Vault::$settings (read at FlowShapes.php:355)'), 176],
            [sprintf(self::MESSAGE, 'tags', 'App\CredentialDerivedCacheKey\Models\Ledger::$iban (read at FlowShapes.php:181)'), 181],
            [self::message('get', 'FlowShapes.php', 186), 186],
            [self::message('get', 'FlowShapes.php', 191), 191],
            [self::message('get', 'FlowShapes.php', 196), 196],
            [sprintf(self::MESSAGE, 'get', 'App\CredentialDerivedCacheKey\Models\Ledger::$history (read at FlowShapes.php:201)'), 201],
            [self::message('forget', 'FlowShapes.php', 143), 301],
            [self::message('forget', 'FlowShapes.php', 390), 373],
        ];

        $source = file_get_contents(self::FIXTURES . 'FlowShapes.php');

        self::assertNotFalse($source);
        self::assertSame(count($expected) - 1, preg_match_all('/function leaks/', $source), 'Every leaks… method reports exactly once — the helper-parameter one inside the helper it calls — plus the trait sink ForgetsBySecret reaches and ForgetsById does not.');
        self::assertGreaterThan(5, preg_match_all('/function keeps/', $source));

        $this->analyse([self::MODELS, self::FIXTURES . 'FlowShapes.php'], $expected);
    }

    /**
     * The model is not in the analysed set here, as in a consumer whose result
     * cache narrowed the run to the changed file. The collector must read casts
     * through the NEON-wired credential-cast rule, whose parser keeps method
     * bodies (WR-1462); a stripping parser would read `Vault::casts()` as empty
     * and this rule would report nothing.
     */
    public function testResolvesFromExtensionNeonAndReadsCastsOfAModelOutsideTheAnalysedSet(): void
    {
        $container = self::getContainer();
        $rule = $container->getByType(ForbidCredentialDerivedCacheKeyRule::class);
        $castRule = $container->getByType(ForbidCredentialCastBypassRule::class);

        self::assertSame($castRule, (new ReflectionClass($rule))->getProperty('castReader')->getValue($rule));
        self::assertInstanceOf(RichParser::class, (new ReflectionClass($castRule))->getProperty('parser')->getValue($castRule));

        $this->ruleOverride = $rule;
        $this->collectorOverride = $container->getByType(CacheKeyTaintCollector::class);

        $this->analyse([self::FIXTURES . 'KeyObjects.php'], [
            [self::message('get', 'KeyObjects.php', 30), 22],
            [self::message('get', 'KeyObjects.php', 61), 51],
            [self::message('get', 'KeyObjects.php', 107), 87],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }

    protected function getRule(): Rule
    {
        if ($this->ruleOverride !== null) {
            return $this->ruleOverride;
        }

        $parser = self::getContainer()->getService('defaultAnalysisParser');

        self::assertInstanceOf(Parser::class, $parser);

        return new ForbidCredentialDerivedCacheKeyRule(new ForbidCredentialCastBypassRule(self::createReflectionProvider(), $parser));
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [$this->collectorOverride ?? new CacheKeyTaintCollector(self::createReflectionProvider())];
    }

    private static function message(string $method, string $file, int $readLine): string
    {
        return sprintf(self::MESSAGE, $method, self::VAULT_KEY . sprintf(' (read at %s:%d)', $file, $readLine));
    }
}
