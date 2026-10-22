<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use LogicException;

/**
 * One piece of bot wording from the flow file, with its `{{placeholder}}` names (docs/PLAN.md
 * §9). FlowDefinition compiles every template when the flow is loaded and refuses any
 * placeholder outside the set its slot allows, so a template can only ever show the values the
 * engine hands it: `display.*` strings, the currency code, the installment count, the API's
 * `message_for_user`. No placeholder holds what the user typed.
 *
 * Rendering is a single pass: a value is inserted verbatim and never scanned again, so a value
 * that happens to contain `{{…}}` is shown as typed, not expanded.
 */
final readonly class Template
{
    /** A placeholder: `{{name}}` or `{{a.b}}`, lowercase, optional inner spaces. */
    public const PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/';

    /**
     * @param list<string> $placeholders the distinct names used, in order of first use
     */
    private function __construct(public string $text, public array $placeholders)
    {
    }

    /**
     * @param list<string> $allowed placeholder names this slot may use
     *
     * @throws FlowDefinitionError for a malformed or unlisted placeholder
     */
    public static function compile(string $text, array $allowed, string $where): self
    {
        $leftover = (string) preg_replace(self::PLACEHOLDER, '', $text);
        if (str_contains($leftover, '{{') || str_contains($leftover, '}}')) {
            throw new FlowDefinitionError("{$where}: malformed placeholder (use {{name}})");
        }

        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $names = array_values(array_unique($matches[1]));
        foreach ($names as $name) {
            if (!in_array($name, $allowed, true)) {
                $hint = $allowed === [] ? 'none are allowed here' : 'allowed: ' . implode(', ', $allowed);
                throw new FlowDefinitionError("{$where}: placeholder {{{$name}}} is not allowed here ({$hint})");
            }
        }

        return new self($text, $names);
    }

    /**
     * @param array<string, string> $values one string per placeholder; extra values are ignored
     *
     * @throws LogicException when a placeholder has no value (an engine bug)
     */
    public function render(array $values = []): string
    {
        foreach ($this->placeholders as $name) {
            if (!array_key_exists($name, $values)) {
                throw new LogicException("No value for placeholder {$name}");
            }
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $match): string => $values[$match[1]],
            $this->text,
        );
    }
}
