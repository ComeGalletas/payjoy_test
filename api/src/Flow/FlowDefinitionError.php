<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use InvalidArgumentException;

/**
 * The flow file can't be used: bad YAML, a missing or unknown key, a wrong type, an unknown
 * `${config.*}` key or `{{placeholder}}`. The message names the file and the YAML path, never
 * anything a user typed (the flow file is owner data).
 */
final class FlowDefinitionError extends InvalidArgumentException
{
}
