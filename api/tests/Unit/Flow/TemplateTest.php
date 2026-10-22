<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use FxInstallments\Flow\FlowDefinitionError;
use FxInstallments\Flow\Template;
use LogicException;
use PHPUnit\Framework\TestCase;

final class TemplateTest extends TestCase
{
    public function testPlaceholdersAreListedOnceInOrder(): void
    {
        $template = Template::compile('{{b}} and {{a.x}} and {{ b }}', ['a.x', 'b'], 'test');

        self::assertSame(['b', 'a.x'], $template->placeholders);
        self::assertSame('2 and 1 and 2', $template->render(['a.x' => '1', 'b' => '2']));
    }

    public function testValuesAreInsertedVerbatimInOnePass(): void
    {
        $template = Template::compile('{{currency}} / {{installments}}', ['currency', 'installments'], 'test');

        // A value that looks like a placeholder, or like markup, is shown as it is.
        self::assertSame(
            '{{installments}} / <script>alert(1)</script>',
            $template->render(['currency' => '{{installments}}', 'installments' => '<script>alert(1)</script>']),
        );
    }

    public function testAMissingValueIsAnEngineBug(): void
    {
        $this->expectException(LogicException::class);

        Template::compile('{{currency}}', ['currency'], 'test')->render([]);
    }

    public function testTextWithoutPlaceholdersRendersAsIs(): void
    {
        self::assertSame('Hi! 100% sure {not a placeholder}', Template::compile('Hi! 100% sure {not a placeholder}', [], 'test')->render());
    }

    public function testAPlaceholderOutsideTheSlotIsRefused(): void
    {
        $this->expectException(FlowDefinitionError::class);
        $this->expectExceptionMessage('steps[1].ask: placeholder {{display.rate}} is not allowed here (allowed: currency)');

        Template::compile('Rate {{display.rate}}', ['currency'], 'steps[1].ask');
    }
}
