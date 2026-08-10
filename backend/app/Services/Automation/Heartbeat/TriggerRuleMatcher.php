<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TickContext;

class TriggerRuleMatcher
{
    /**
     * @param  list<TriggerRule>  $rules
     */
    public function firstMatchedRule(array $rules, TickContext $tick): ?TriggerRule
    {
        foreach ($rules as $rule) {
            if ($rule->isDue($tick)) {
                return $rule;
            }
        }

        return null;
    }
}
