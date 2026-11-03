<?php

namespace Tests\Fixtures\Security;

use Livewire\Attributes\Authorize;
use Livewire\Attributes\Json;
use Livewire\Component;

final class AuthorizationRegressionComponent extends Component
{
    public static int $actionEffects = 0;

    public static array $actionArguments = [];

    public static int $jsonEffects = 0;

    #[Authorize('security-regression-reordered', ['first', 'second'])]
    public function reorderedAction(string $first, string $second): void
    {
        self::$actionArguments = [$first, $second];
        self::$actionEffects++;
    }

    #[Authorize('security-regression-json')]
    #[Json]
    public function jsonAction(): string
    {
        self::$jsonEffects++;

        return 'executed';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
