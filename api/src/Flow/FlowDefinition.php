<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use FxInstallments\Config\ConfigRepository;
use LogicException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The static flow (docs/PLAN.md §9, ADR 0003), loaded from `flows/default.yaml` with
 * symfony/yaml and checked strictly, so a typo in the file fails at load time instead of
 * showing a broken message to a user.
 *
 * **YAML.** Parsed with `Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE` and no other flag: PHP objects,
 * constants and custom tags (`!php/object`, `!php/const`, `!foo`) are refused, and so are
 * duplicate keys and YAML's built-in `!!` tags (`!!binary` could carry bytes that aren't
 * UTF-8). The file may be at most MAX_BYTES of UTF-8, the parsed tree at most MAX_NODES nodes
 * (an alias bomb fails here, after only the top-level keys were checked), and every string in
 * it must be valid UTF-8, so a bad file fails at load, never mid-session.
 *
 * **Config.** Two forms, both limited to CONFIG_KEYS (all integers):
 * - `${config.<key>}` as a whole YAML value is replaced by the config value, typed, before the
 *   schema is checked (`max_retries: ${config.bot.max_retries}`). Anywhere else `${` is an error.
 * - `{{config.<key>}}` inside wording is replaced by the value's decimal digits at load time
 *   (`… from 1 to {{config.installments.max}}.`).
 *
 * **Schema.** Top level: exactly `name`, `max_retries` (1–20), `max_retries_reply`, `steps`.
 * Steps, in order: any `say` steps first; then the input steps, one each for `amount`,
 * `currency` and `installments`, in any order; then exactly one `call: convert` step, last.
 * Every key of every step is checked (unknown keys are errors), and every template may use only
 * the placeholders its slot allows (Template::compile):
 * - wording shown before a conversion: none (config values are already substituted);
 * - `reasons.too_large` (amount), `reasons.not_in_table` (currency) and
 *   `reasons.below_minor_unit` (installments), the answers to a failed conversion:
 *   CONVERT_PLACEHOLDERS (`message_for_user`, `currency`, `installments`);
 * - `say_single`, `say_even`, `say_uneven`: RESULT_PLACEHOLDERS (`display.*`, `currency`,
 *   `installments`), so money in the result comes only from the display block;
 * - `upstream_error`: `message_for_user`.
 */
final class FlowDefinition
{
    public const MAX_BYTES = 65_536;

    /** The config keys the flow file may read; all are integers. */
    public const CONFIG_KEYS = [
        'installments.default', 'installments.max', 'amount.max', 'bot.max_retries', 'bot.session_ttl_minutes',
    ];

    /** What an input step can collect. */
    public const EXPECTING = ['amount', 'currency', 'installments'];

    /**
     * The validation reasons each input step may give its own text for (CONTEXT "validation
     * reason"). `unclear` and `ambiguous` use the step's `clarify` text instead.
     */
    public const REASONS = [
        'amount' => ['format', 'not_positive', 'over_max', 'too_large'],
        'currency' => ['format', 'unknown', 'not_allowed', 'not_in_table'],
        'installments' => ['format', 'out_of_range', 'below_minor_unit'],
    ];

    /**
     * Reasons that come from the conversion, not from reading the answer. `not_in_table` is
     * UPSTREAM_UNSUPPORTED_CODE for a currency missing from a usable rate table (owner decision
     * 2026-10-21: re-ask the currency).
     */
    public const CONVERT_REASONS = ['too_large', 'below_minor_unit', 'not_in_table'];

    public const CONVERT_PLACEHOLDERS = ['message_for_user', 'currency', 'installments'];

    public const RESULT_PLACEHOLDERS = [
        'display.amount_usd', 'display.rate', 'display.converted', 'display.installment',
        'display.last_installment', 'display.regular_count', 'display.rate_as_of', 'currency', 'installments',
    ];

    public const UPSTREAM_PLACEHOLDERS = ['message_for_user'];

    /** The default flow has about 60; aliases count once per use. */
    public const MAX_NODES = 2_000;

    private const MAX_TEXT_CHARS = 600;
    private const MAX_BUTTONS = 8;
    private const MAX_BUTTON_CHARS = 40;
    private const MAX_STEPS = 20;
    private const ID_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';

    /**
     * @param list<Step> $steps
     */
    private function __construct(
        public readonly string $name,
        public readonly int $maxRetries,
        public readonly Template $maxRetriesReply,
        private readonly array $steps,
    ) {
    }

    /**
     * @throws FlowDefinitionError
     */
    public static function fromFile(string $path, ConfigRepository $config): self
    {
        $where = basename($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new FlowDefinitionError("{$where}: flow file not found");
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new FlowDefinitionError("{$where}: flow file larger than " . self::MAX_BYTES . ' bytes');
        }
        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new FlowDefinitionError("{$where}: flow file can't be read");
        }

        return self::fromYaml($yaml, $config, $where);
    }

    /**
     * @throws FlowDefinitionError
     */
    public static function fromYaml(string $yaml, ConfigRepository $config, string $where = 'flow'): self
    {
        if (strlen($yaml) > self::MAX_BYTES) {
            throw new FlowDefinitionError("{$where}: flow file larger than " . self::MAX_BYTES . ' bytes');
        }
        if (!mb_check_encoding($yaml, 'UTF-8')) {
            throw new FlowDefinitionError("{$where}: flow file is not valid UTF-8");
        }
        if (preg_match('/(?:^|[\s\[{,])!!/m', $yaml) === 1) {
            // YAML's built-in tags (`!!binary`, `!!str`, `!!float`…): only plain scalars are
            // wanted, and `!!binary` can smuggle bytes that aren't UTF-8.
            throw new FlowDefinitionError("{$where}: YAML tags (!!…) are not allowed");
        }
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            // symfony/yaml messages quote the file's own text (owner data), never user input.
            throw new FlowDefinitionError("{$where}: invalid YAML: " . $e->getMessage());
        }

        // Cheap checks first: the top-level keys, then the size of the parsed tree (aliases are
        // expanded by the parser, so an alias bomb is a huge tree), before anything walks it.
        self::keys(self::map($data, "{$where}: the top level"), ['name', 'max_retries', 'max_retries_reply', 'steps'], [], "{$where}: the top level");
        $nodes = 0;
        self::countNodes($data, $nodes, $where);

        return self::parse(self::substituteConfig($data, $config, $where), $where);
    }

    /**
     * Counts the parsed tree's nodes, stopping as soon as the count passes MAX_NODES.
     */
    private static function countNodes(mixed $value, int &$count, string $where): void
    {
        if (++$count > self::MAX_NODES) {
            throw new FlowDefinitionError("{$where}: the flow has more than " . self::MAX_NODES . ' YAML nodes (aliases included)');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::countNodes($item, $count, $where);
            }
        }
    }

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function step(string $id): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The first step of the flow (`greet` in the default flow); `bot.session_start` records it.
     */
    public function firstStep(): Step
    {
        return $this->steps[0];
    }

    /**
     * The `say` steps a session starts with, in order.
     *
     * @return list<Step>
     */
    public function saySteps(): array
    {
        return array_values(array_filter($this->steps, static fn (Step $step): bool => $step->kind === Step::SAY));
    }

    /**
     * The first input step whose field isn't collected yet, or null when every field is.
     *
     * @param callable(string): bool $isCollected given an `expecting` value
     */
    public function nextInputStep(callable $isCollected): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->isInput() && !$isCollected((string) $step->expecting)) {
                return $step;
            }
        }

        return null;
    }

    public function inputStepFor(string $expecting): Step
    {
        foreach ($this->steps as $step) {
            if ($step->isInput() && $step->expecting === $expecting) {
                return $step;
            }
        }
        throw new LogicException("The flow has no {$expecting} step");
    }

    public function callStep(): Step
    {
        return $this->steps[count($this->steps) - 1];
    }

    private static function parse(mixed $data, string $where): self
    {
        $root = self::map($data, "{$where}: the top level");
        self::keys($root, ['name', 'max_retries', 'max_retries_reply', 'steps'], [], "{$where}: the top level");

        if (!is_string($root['name']) || preg_match(self::ID_PATTERN, $root['name']) !== 1) {
            throw new FlowDefinitionError("{$where}: name must be a lowercase identifier");
        }
        $maxRetries = $root['max_retries'];
        if (!is_int($maxRetries) || $maxRetries < 1 || $maxRetries > 20) {
            throw new FlowDefinitionError("{$where}: max_retries must be an integer from 1 to 20");
        }
        $maxRetriesReply = self::text($root['max_retries_reply'], [], "{$where}: max_retries_reply");

        $list = $root['steps'];
        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_STEPS) {
            throw new FlowDefinitionError("{$where}: steps must be a list of 1 to " . self::MAX_STEPS . ' steps');
        }
        $steps = [];
        foreach ($list as $index => $item) {
            $steps[] = self::parseStep($item, "{$where}: steps[{$index}]");
        }
        self::checkOrder($steps, $where);

        return new self($root['name'], $maxRetries, $maxRetriesReply, $steps);
    }

    private static function parseStep(mixed $data, string $at): Step
    {
        $map = self::map($data, $at);
        $id = $map['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new FlowDefinitionError("{$at}: id must be a lowercase identifier");
        }
        $at .= " ({$id})";

        $kinds = array_values(array_intersect(['say', 'expecting', 'call'], array_map('strval', array_keys($map))));
        if (count($kinds) !== 1) {
            throw new FlowDefinitionError("{$at}: a step has exactly one of say, expecting or call");
        }

        return match ($kinds[0]) {
            'say' => self::parseSayStep($map, $id, $at),
            'expecting' => self::parseInputStep($map, $id, $at),
            default => self::parseCallStep($map, $id, $at),
        };
    }

    /**
     * @param array<string, mixed> $map
     */
    private static function parseSayStep(array $map, string $id, string $at): Step
    {
        self::keys($map, ['id', 'say'], [], $at);

        return new Step($id, Step::SAY, say: self::text($map['say'], [], "{$at}.say"));
    }

    /**
     * @param array<string, mixed> $map
     */
    private static function parseInputStep(array $map, string $id, string $at): Step
    {
        $expecting = $map['expecting'];
        if (!is_string($expecting) || !in_array($expecting, self::EXPECTING, true)) {
            throw new FlowDefinitionError("{$at}: expecting must be one of " . implode(', ', self::EXPECTING));
        }
        // Installments have no unclear reading (a whole number is never ambiguous), so no
        // clarify text; only they have a default, so only they can be optional.
        $hasClarify = $expecting !== 'installments';
        $required = ['id', 'ask', 'expecting', 'retry'];
        if ($hasClarify) {
            $required[] = 'clarify';
        }
        self::keys($map, $required, ['buttons', 'optional', 'reasons'], $at);

        $optional = $map['optional'] ?? false;
        if (!is_bool($optional)) {
            throw new FlowDefinitionError("{$at}: optional must be true or false");
        }
        if ($optional && $expecting !== 'installments') {
            throw new FlowDefinitionError("{$at}: only the installments step can be optional (it has installments.default)");
        }

        $buttons = self::buttons($map['buttons'] ?? [], $optional, "{$at}.buttons");

        $reasons = [];
        $reasonMap = $map['reasons'] ?? [];
        if (!is_array($reasonMap) || ($reasonMap !== [] && array_is_list($reasonMap))) {
            throw new FlowDefinitionError("{$at}.reasons: must be a map of validation reason to text");
        }
        foreach ($reasonMap as $reason => $text) {
            $reason = (string) $reason;
            if (!in_array($reason, self::REASONS[$expecting], true)) {
                throw new FlowDefinitionError("{$at}.reasons: unknown reason {$reason} for {$expecting} (allowed: " . implode(', ', self::REASONS[$expecting]) . ')');
            }
            $allowed = in_array($reason, self::CONVERT_REASONS, true) ? self::CONVERT_PLACEHOLDERS : [];
            $reasons[$reason] = self::text($text, $allowed, "{$at}.reasons.{$reason}");
        }

        return new Step(
            $id,
            Step::INPUT,
            expecting: $expecting,
            ask: self::text($map['ask'], [], "{$at}.ask"),
            retry: self::text($map['retry'], [], "{$at}.retry"),
            clarify: $hasClarify ? self::text($map['clarify'], [], "{$at}.clarify") : null,
            buttons: $buttons,
            optional: $optional,
            reasons: $reasons,
        );
    }

    /**
     * @param array<string, mixed> $map
     */
    private static function parseCallStep(array $map, string $id, string $at): Step
    {
        self::keys($map, ['id', 'call', 'say_single', 'say_even', 'say_uneven', 'stale_note', 'upstream_error'], [], $at);
        if ($map['call'] !== 'convert') {
            throw new FlowDefinitionError("{$at}: call must be convert");
        }

        return new Step(
            $id,
            Step::CALL,
            saySingle: self::text($map['say_single'], self::RESULT_PLACEHOLDERS, "{$at}.say_single"),
            sayEven: self::text($map['say_even'], self::RESULT_PLACEHOLDERS, "{$at}.say_even"),
            sayUneven: self::text($map['say_uneven'], self::RESULT_PLACEHOLDERS, "{$at}.say_uneven"),
            staleNote: self::text($map['stale_note'], [], "{$at}.stale_note"),
            upstreamError: self::text($map['upstream_error'], self::UPSTREAM_PLACEHOLDERS, "{$at}.upstream_error"),
        );
    }

    /**
     * Say steps first, then one input step per field, then the call step, last; ids unique.
     *
     * @param list<Step> $steps
     */
    private static function checkOrder(array $steps, string $where): void
    {
        $ids = array_map(static fn (Step $step): string => $step->id, $steps);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new FlowDefinitionError("{$where}: step ids must be unique");
        }

        $expecting = [];
        $seenInput = false;
        $last = count($steps) - 1;
        foreach ($steps as $index => $step) {
            if ($step->kind === Step::SAY && $seenInput) {
                throw new FlowDefinitionError("{$where}: say step {$step->id} must come before the first question");
            }
            if ($step->kind === Step::CALL && $index !== $last) {
                throw new FlowDefinitionError("{$where}: the call step {$step->id} must be the last step");
            }
            if ($step->isInput()) {
                $seenInput = true;
                $expecting[] = (string) $step->expecting;
            }
        }
        if ($steps[$last]->kind !== Step::CALL) {
            throw new FlowDefinitionError("{$where}: the last step must be call: convert");
        }
        $sorted = $expecting;
        sort($sorted);
        if ($sorted !== self::EXPECTING) {
            throw new FlowDefinitionError("{$where}: the flow needs exactly one step expecting each of " . implode(', ', self::EXPECTING));
        }
    }

    /**
     * @return list<string>
     */
    private static function buttons(mixed $value, bool $optional, string $at): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_BUTTONS) {
            throw new FlowDefinitionError("{$at}: must be a list of at most " . self::MAX_BUTTONS . ' texts');
        }
        foreach ($value as $button) {
            if (!is_string($button) || trim($button) === '' || mb_strlen($button, 'UTF-8') > self::MAX_BUTTON_CHARS
                || preg_match('/[\x00-\x1F\x7F]/', $button) === 1 || str_contains($button, '{{')) {
                throw new FlowDefinitionError("{$at}: each button is a one-line text of 1 to " . self::MAX_BUTTON_CHARS . ' characters, without placeholders');
            }
            if ($optional && preg_match('/^\s*[0-9]+\s*$/D', $button) === 1) {
                // On the optional step a button means "use the default", so it can't be a number.
                throw new FlowDefinitionError("{$at}: a button on the optional step can't be a number");
            }
        }

        return $value;
    }

    /**
     * @param list<string> $allowed placeholders
     */
    private static function text(mixed $value, array $allowed, string $at): Template
    {
        if (!is_string($value) || trim($value) === '') {
            throw new FlowDefinitionError("{$at}: must be a non-empty text");
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_TEXT_CHARS) {
            throw new FlowDefinitionError("{$at}: longer than " . self::MAX_TEXT_CHARS . ' characters');
        }
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
            throw new FlowDefinitionError("{$at}: control characters are not allowed (only line breaks)");
        }

        return Template::compile($value, $allowed, $at);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $at): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new FlowDefinitionError("{$at}: must be a map");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $map
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function keys(array $map, array $required, array $optional, string $at): void
    {
        $present = array_map('strval', array_keys($map));
        $missing = array_diff($required, $present);
        if ($missing !== []) {
            throw new FlowDefinitionError("{$at}: missing " . implode(', ', $missing));
        }
        $unknown = array_diff($present, $required, $optional);
        if ($unknown !== []) {
            throw new FlowDefinitionError("{$at}: unknown key(s) " . implode(', ', $unknown));
        }
    }

    /**
     * Replaces every `${config.<key>}` value, and every `{{config.<key>}}` inside a string,
     * with the config value.
     */
    private static function substituteConfig(mixed $value, ConfigRepository $config, string $where, string $path = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::substituteConfig($item, $config, $where, $path === '' ? (string) $key : "{$path}.{$key}");
            }

            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }

        $at = $path === '' ? $where : "{$where}: {$path}";
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new FlowDefinitionError("{$at}: not valid UTF-8");
        }
        if (preg_match('/^\$\{config\.([a-z0-9_.]+)\}$/D', $value, $match) === 1) {
            return self::configValue($match[1], $config, $at);
        }
        if (str_contains($value, '${')) {
            throw new FlowDefinitionError("{$at}: \${config.<key>} must be the whole value; use {{config.<key>}} inside text");
        }

        return (string) preg_replace_callback(
            '/\{\{\s*config\.([a-z0-9_.]+)\s*\}\}/',
            static fn (array $m): string => (string) self::configValue($m[1], $config, $at),
            $value,
        );
    }

    private static function configValue(string $key, ConfigRepository $config, string $at): int
    {
        if (!in_array($key, self::CONFIG_KEYS, true)) {
            throw new FlowDefinitionError("{$at}: config key {$key} is not available to the flow (allowed: " . implode(', ', self::CONFIG_KEYS) . ')');
        }

        return $config->int($key);
    }
}
