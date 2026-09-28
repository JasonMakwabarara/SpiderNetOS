<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class BudgetExceededException extends AgentRuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(string $scope, array $detail = [])
    {
        parent::__construct("Budget exceeded ({$scope}).", $scope, 402, $detail);
    }
}
