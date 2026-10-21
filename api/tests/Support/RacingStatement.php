<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use PDOException;
use PDOStatement;

/**
 * The statement class of RacingPdo: notes the SQLSTATE of every failed execute().
 */
final class RacingStatement extends PDOStatement
{
    public ?RacingPdo $owner = null;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $e) {
            if ($this->owner !== null) {
                $this->owner->failures[] = (string) $e->getCode();
            }
            throw $e;
        }
    }
}
