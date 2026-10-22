<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Flow\FlowDefinition;
use FxInstallments\Flow\FlowDefinitionError;
use FxInstallments\Flow\Step;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The flow loader (docs/PLAN.md §9): the real flow file, YAML errors, the schema, `${config.*}`
 * and `{{config.*}}` substitution, and the placeholder allow-list per slot.
 */
final class FlowDefinitionTest extends TestCase
{
    public function testTheDefaultFlowLoads(): void
    {
        $flow = FlowDefinition::fromFile(App::DEFAULT_FLOW, new ConfigRepository());

        self::assertSame('fx_installments', $flow->name);
        self::assertSame(3, $flow->maxRetries);
        self::assertSame(['greet', 'amount', 'currency', 'installments', 'result'], array_map(static fn (Step $step): string => $step->id, $flow->steps()));
        self::assertSame([Step::SAY, Step::INPUT, Step::INPUT, Step::INPUT, Step::CALL], array_map(static fn (Step $step): string => $step->kind, $flow->steps()));

        $currency = $flow->inputStepFor('currency');
        self::assertSame(['BRL', 'COP', 'MXN', 'EUR'], $currency->buttons);
        $installments = $flow->inputStepFor('installments');
        self::assertTrue($installments->optional);
        self::assertSame(['Just one payment'], $installments->buttons);
        self::assertNull($installments->clarify);
        self::assertSame('How many monthly installments? (default 1)', $installments->askText()->render());
        self::assertSame('Please type a whole number from 1 to 60.', $installments->invalidText('out_of_range')->render());
        self::assertSame('result', $flow->callStep()->id);
        self::assertSame('greet', $flow->firstStep()->id);
    }

    public function testTheDefaultFlowHasDifferentWordingForUnclearAndInvalidAmounts(): void
    {
        $amount = FlowDefinition::fromFile(App::DEFAULT_FLOW, new ConfigRepository())->inputStepFor('amount');

        $unclear = $amount->unclearText()->render();
        $texts = [$unclear];
        foreach (['format', 'not_positive', 'over_max'] as $reason) {
            $texts[] = $amount->invalidText($reason)->render();
        }
        self::assertSame("I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50.", $unclear);
        self::assertSame($texts, array_values(array_unique($texts)), 'each answer has its own text');
        self::assertSame(['message_for_user', 'currency', 'installments'], FlowDefinition::CONVERT_PLACEHOLDERS);
        self::assertSame(['currency'], $amount->invalidText('too_large')->placeholders);
    }

    public function testConfigValuesAreSubstitutedAtLoad(): void
    {
        $flow = FlowDefinition::fromFile(App::DEFAULT_FLOW, new ConfigRepository([
            'bot.max_retries' => 5,
            'installments.max' => 24,
            'installments.default' => 2,
        ]));

        self::assertSame(5, $flow->maxRetries);
        $installments = $flow->inputStepFor('installments');
        self::assertSame('Please type a whole number from 1 to 24.', $installments->retry?->render());
        self::assertSame('How many monthly installments? (default 2)', $installments->askText()->render());
    }

    public function testSpacesInsideAConfigPlaceholderAreAllowed(): void
    {
        $flow = self::load(static function (array &$flow): void {
            $flow['steps'][3]['retry'] = 'From 1 to {{ config.installments.max }}.';
        });

        self::assertSame('From 1 to 60.', $flow->inputStepFor('installments')->retry?->render());
    }

    /**
     * @return iterable<string, array{Closure(array<string, mixed>): void, string}>
     */
    public static function invalidFlows(): iterable
    {
        // Top level.
        yield 'missing max_retries_reply' => [static function (array &$f): void { unset($f['max_retries_reply']); }, 'missing max_retries_reply'];
        yield 'unknown top-level key' => [static function (array &$f): void { $f['version'] = 2; }, 'unknown key(s) version'];
        yield 'bad name' => [static function (array &$f): void { $f['name'] = 'FX Flow'; }, 'name must be a lowercase identifier'];
        yield 'max_retries as text' => [static function (array &$f): void { $f['max_retries'] = '3'; }, 'max_retries must be an integer'];
        yield 'max_retries 0' => [static function (array &$f): void { $f['max_retries'] = 0; }, 'max_retries must be an integer from 1 to 20'];
        yield 'steps not a list' => [static function (array &$f): void { $f['steps'] = ['greet' => ['id' => 'greet', 'say' => 'Hi']]; }, 'steps must be a list'];

        // Config.
        yield 'unknown config key' => [static function (array &$f): void { $f['max_retries'] = '${config.ratelimit.per_minute}'; }, 'config key ratelimit.per_minute is not available'];
        yield 'config value inside text' => [static function (array &$f): void { $f['steps'][0]['say'] = 'Up to ${config.bot.max_retries} tries'; }, 'must be the whole value'];
        yield 'unknown config placeholder' => [static function (array &$f): void { $f['steps'][1]['ask'] = 'Up to {{config.admin.token}}'; }, 'config key admin.token is not available'];
        yield 'config value where text is expected' => [static function (array &$f): void { $f['steps'][0]['say'] = '${config.amount.max}'; }, 'steps[0] (greet).say: must be a non-empty text'];

        // Steps.
        yield 'step without id' => [static function (array &$f): void { unset($f['steps'][0]['id']); }, 'id must be a lowercase identifier'];
        yield 'two kinds in one step' => [static function (array &$f): void { $f['steps'][0]['expecting'] = 'amount'; }, 'exactly one of say, expecting or call'];
        yield 'amount without retry' => [static function (array &$f): void { unset($f['steps'][1]['retry']); }, 'missing retry'];
        yield 'amount without clarify' => [static function (array &$f): void { unset($f['steps'][1]['clarify']); }, 'missing clarify'];
        yield 'clarify on installments' => [static function (array &$f): void { $f['steps'][3]['clarify'] = 'Which?'; }, 'unknown key(s) clarify'];
        yield 'unknown step key' => [static function (array &$f): void { $f['steps'][2]['tooltip'] = 'x'; }, 'unknown key(s) tooltip'];
        yield 'unknown expecting' => [static function (array &$f): void { $f['steps'][2]['expecting'] = 'country'; }, 'expecting must be one of'];
        yield 'optional amount' => [static function (array &$f): void { $f['steps'][1]['optional'] = true; }, 'only the installments step can be optional'];
        yield 'optional as text' => [static function (array &$f): void { $f['steps'][3]['optional'] = 'yes'; }, 'optional must be true or false'];
        yield 'numeric button on the optional step' => [static function (array &$f): void { $f['steps'][3]['buttons'] = ['3']; }, "a button on the optional step can't be a number"];
        yield 'too many buttons' => [static function (array &$f): void { $f['steps'][2]['buttons'] = array_fill(0, 9, 'BRL'); }, 'at most 8'];
        yield 'button with a placeholder' => [static function (array &$f): void { $f['steps'][2]['buttons'] = ['{{currency}}']; }, 'without placeholders'];
        yield 'button too long' => [static function (array &$f): void { $f['steps'][2]['buttons'] = [str_repeat('x', 41)]; }, '1 to 40 characters'];
        yield 'unknown reason' => [static function (array &$f): void { $f['steps'][1]['reasons']['ambiguous'] = 'x'; }, 'unknown reason ambiguous for amount'];
        yield 'reason of another step' => [static function (array &$f): void { $f['steps'][2]['reasons']['below_minor_unit'] = 'x'; }, 'unknown reason below_minor_unit for currency'];
        yield 'reasons as a list' => [static function (array &$f): void { $f['steps'][1]['reasons'] = ['x']; }, 'must be a map of validation reason to text'];
        yield 'result without say_uneven' => [static function (array &$f): void { unset($f['steps'][4]['say_uneven']); }, 'missing say_uneven'];
        yield 'result without upstream_error' => [static function (array &$f): void { unset($f['steps'][4]['upstream_error']); }, 'missing upstream_error'];
        yield 'another call' => [static function (array &$f): void { $f['steps'][4]['call'] = 'rates'; }, 'call must be convert'];

        // Order and completeness.
        yield 'duplicate ids' => [static function (array &$f): void { $f['steps'][2]['id'] = 'amount'; }, 'step ids must be unique'];
        yield 'two amount steps' => [static function (array &$f): void { $f['steps'][2] = ['id' => 'amount2'] + $f['steps'][1]; }, 'exactly one step expecting each of'];
        yield 'no currency step' => [static function (array &$f): void { array_splice($f['steps'], 2, 1); }, 'exactly one step expecting each of'];
        yield 'no call step' => [static function (array &$f): void { array_pop($f['steps']); }, 'the last step must be call: convert'];
        yield 'a step after the call' => [static function (array &$f): void { $f['steps'][] = ['id' => 'bye', 'say' => 'Bye']; }, 'the call step result must be the last step'];
        yield 'say after a question' => [static function (array &$f): void { array_splice($f['steps'], 2, 0, [['id' => 'note', 'say' => 'Noted']]); }, 'say step note must come before the first question'];
        yield 'call in the middle' => [static function (array &$f): void { $call = array_pop($f['steps']); array_splice($f['steps'], 1, 0, [$call]); }, 'must be the last step'];

        // Texts.
        yield 'empty text' => [static function (array &$f): void { $f['steps'][0]['say'] = '  '; }, 'must be a non-empty text'];
        yield 'tab in a text' => [static function (array &$f): void { $f['steps'][0]['say'] = "Hi\tthere"; }, 'control characters are not allowed'];
        yield 'text too long' => [static function (array &$f): void { $f['steps'][0]['say'] = str_repeat('a', 601); }, 'longer than 600'];
    }

    /**
     * @param Closure(array<string, mixed>): void $mutate
     */
    #[DataProvider('invalidFlows')]
    public function testAnInvalidFlowIsRefusedAtLoad(Closure $mutate, string $message): void
    {
        $this->expectException(FlowDefinitionError::class);
        $this->expectExceptionMessage($message);

        self::load($mutate);
    }

    /**
     * Every slot accepts only its own placeholders (docs/PLAN.md §9: money in templates comes
     * only from `display.*`; nothing the user typed can be shown).
     *
     * @return iterable<string, array{Closure(array<string, mixed>): void, string}>
     */
    public static function forbiddenPlaceholders(): iterable
    {
        $set = static fn (int $step, string $key, string $text): Closure => static function (array &$f) use ($step, $key, $text): void {
            $f['steps'][$step][$key] = $text;
        };
        yield 'display in a question' => [$set(1, 'ask', 'Total? {{display.converted}}'), '{{display.converted}} is not allowed here (none are allowed here)'];
        yield 'currency in greet' => [$set(0, 'say', 'Hi {{currency}}'), '{{currency}} is not allowed here'];
        yield 'message_for_user in retry' => [$set(3, 'retry', '{{message_for_user}}'), '{{message_for_user}} is not allowed here'];
        yield 'the typed text' => [$set(4, 'say_single', 'You typed {{text}}'), '{{text}} is not allowed here'];
        yield 'the raw rate' => [$set(4, 'say_even', 'Rate {{rate}}'), '{{rate}} is not allowed here'];
        yield 'a display boolean' => [$set(4, 'say_even', 'Even: {{display.even}}'), '{{display.even}} is not allowed here'];
        yield 'session id' => [$set(4, 'say_uneven', '{{session_id}}'), '{{session_id}} is not allowed here'];
        yield 'display in the stale note' => [$set(4, 'stale_note', 'Rate {{display.rate}}'), '{{display.rate}} is not allowed here'];
        yield 'display in upstream_error' => [$set(4, 'upstream_error', '{{display.converted}}'), '{{display.converted}} is not allowed here'];
        yield 'display in a conversion retry' => [static function (array &$f): void {
            $f['steps'][3]['reasons']['below_minor_unit'] = '{{display.installment}}';
        }, '{{display.installment}} is not allowed here'];
        yield 'currency in a reading retry' => [static function (array &$f): void {
            $f['steps'][1]['reasons']['over_max'] = 'Too much for {{currency}}';
        }, '{{currency}} is not allowed here'];
        yield 'max_retries_reply' => [static function (array &$f): void {
            $f['max_retries_reply'] = 'Bye {{currency}}';
        }, '{{currency}} is not allowed here'];
        yield 'uppercase name' => [$set(0, 'say', 'Hi {{Currency}}'), 'malformed placeholder'];
        yield 'unclosed' => [$set(0, 'say', 'Hi {{currency'), 'malformed placeholder'];
        yield 'stray closing braces' => [$set(0, 'say', 'Hi }}'), 'malformed placeholder'];
        yield 'nested' => [$set(4, 'say_single', '{{display.{{currency}}}}'), 'malformed placeholder'];
    }

    /**
     * @param Closure(array<string, mixed>): void $mutate
     */
    #[DataProvider('forbiddenPlaceholders')]
    public function testATemplateCanUseOnlyItsSlotsPlaceholders(Closure $mutate, string $message): void
    {
        $this->expectException(FlowDefinitionError::class);
        $this->expectExceptionMessage($message);

        self::load($mutate);
    }

    public function testTheAllowedPlaceholdersLoad(): void
    {
        $flow = self::load(static function (array &$f): void {
            $f['steps'][3]['reasons']['below_minor_unit'] = '{{message_for_user}} {{installments}} {{currency}}';
            $f['steps'][1]['reasons']['too_large'] = '{{message_for_user}} {{currency}}';
            $f['steps'][4]['say_single'] = implode(' ', array_map(static fn (string $name): string => '{{' . $name . '}}', FlowDefinition::RESULT_PLACEHOLDERS));
            $f['steps'][4]['upstream_error'] = 'Sorry: {{ message_for_user }}';
        });

        self::assertSame(FlowDefinition::RESULT_PLACEHOLDERS, $flow->callStep()->saySingle?->placeholders);
        self::assertSame('Sorry: down', $flow->callStep()->upstreamError?->render(['message_for_user' => 'down']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badYaml(): iterable
    {
        yield 'syntax error' => ["name: fx\nsteps: [\n", 'invalid YAML'];
        yield 'php object tag' => ["name: !php/object 'O:8:\"stdClass\":0:{}'\n", 'invalid YAML'];
        yield 'php constant tag' => ["name: !php/const PHP_VERSION\n", 'invalid YAML'];
        yield 'custom tag' => ["name: !shout fx\n", 'invalid YAML'];
        yield 'duplicate key' => ["name: a\nname: b\n", 'invalid YAML'];
        yield 'not UTF-8' => ["name: caf\xE9\n", 'not valid UTF-8'];
        yield 'too large' => [str_repeat('#', FlowDefinition::MAX_BYTES + 1), 'larger than'];
        yield 'a scalar' => ['fx', 'the top level: must be a map'];
        yield 'empty' => ['', 'the top level: must be a map'];
    }

    #[DataProvider('badYaml')]
    public function testBadYamlIsRefused(string $yaml, string $message): void
    {
        $this->expectException(FlowDefinitionError::class);
        $this->expectExceptionMessage($message);

        FlowDefinition::fromYaml($yaml, new ConfigRepository(), 'test.yaml');
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(FlowDefinitionError::class);
        $this->expectExceptionMessage('missing.yaml: flow file not found');

        FlowDefinition::fromFile(TestEnvironment::tempDir('flow') . '/missing.yaml', new ConfigRepository());
    }

    public function testTheInputStepsMayComeInAnyOrder(): void
    {
        $flow = self::load(static function (array &$f): void {
            [$f['steps'][1], $f['steps'][2]] = [$f['steps'][2], $f['steps'][1]];
        });

        self::assertSame('currency', $flow->nextInputStep(static fn (): bool => false)?->id);
        self::assertSame('amount', $flow->nextInputStep(static fn (string $field): bool => $field === 'currency')?->id);
        self::assertNull($flow->nextInputStep(static fn (): bool => true));
    }

    /**
     * The default flow file, changed by $mutate, dumped and loaded again.
     *
     * @param Closure(array<string, mixed>): void $mutate
     */
    private static function load(Closure $mutate): FlowDefinition
    {
        $flow = Yaml::parse((string) file_get_contents(App::DEFAULT_FLOW));
        self::assertIsArray($flow);
        $mutate($flow);

        return FlowDefinition::fromYaml(Yaml::dump($flow, 10, 2), new ConfigRepository(), 'test.yaml');
    }
}
