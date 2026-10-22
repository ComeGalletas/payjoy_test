<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use InvalidArgumentException;

/**
 * The CLI front-end (docs/PLAN.md §7): `php bin/bot.php`, interactive or piped, channel `cli`.
 * It calls the flow engine in-process, the same engine `POST /bot/message` uses, so a scripted
 * run exercises the real flow, sessions, events and conversion path.
 *
 * Output is the bot's replies verbatim, one line per reply part, then the buttons as
 * `[BRL] [COP] …` when the step has any. Interactive runs (a terminal on stdin) also print a
 * `> ` prompt; piped runs don't, so their output is just the conversation. Every input line is
 * one message (its line ending removed).
 *
 * Exit status: 0 when the session completed; 1 when it was abandoned or the input ended first.
 */
final class BotCli
{
    public const CHANNEL = 'cli';

    public function __construct(private readonly FlowEngine $engine)
    {
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run($input, $output, bool $interactive = false): int
    {
        if (!is_resource($input) || !is_resource($output)) {
            throw new InvalidArgumentException('BotCli needs an input and an output stream');
        }

        $reply = $this->engine->handle(null, '', self::CHANNEL);
        $this->show($output, $reply);
        while (!$reply->done()) {
            if ($interactive) {
                fwrite($output, '> ');
            }
            $line = fgets($input);
            if ($line === false) {
                return 1;
            }
            $reply = $this->engine->handle($reply->sessionId, rtrim($line, "\r\n"), self::CHANNEL);
            $this->show($output, $reply);
        }

        return $reply->status === Session::COMPLETED ? 0 : 1;
    }

    /**
     * @param resource $output
     */
    private function show($output, BotReply $reply): void
    {
        $text = $reply->reply . "\n";
        if ($reply->buttons !== [] && !$reply->done()) {
            $text .= implode(' ', array_map(static fn (string $button): string => "[{$button}]", $reply->buttons)) . "\n";
        }
        fwrite($output, $text);
    }
}
