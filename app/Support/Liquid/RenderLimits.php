<?php

namespace App\Support\Liquid;

use Keepsuit\Liquid\Render\ResourceLimits;

/**
 * The resource limits of one render, with a time limit on top of the limits
 * of the engine. Each rendered block counts toward the render score, so the
 * check runs inside each loop.
 */
class RenderLimits extends ResourceLimits
{
    private float $deadline;

    public function __construct(float $seconds, int $renderLength, int $renderScore, int $assignScore)
    {
        parent::__construct(
            renderLengthLimit: $renderLength,
            cumulativeRenderScoreLimit: $renderScore,
            cumulativeAssignScoreLimit: $assignScore,
        );

        $this->deadline = hrtime(true) / 1e9 + $seconds;
    }

    public function incrementRenderScore(int $amount = 1): ResourceLimits
    {
        parent::incrementRenderScore($amount);

        if (hrtime(true) / 1e9 > $this->deadline) {
            $this->throwLimitReachedException();
        }

        return $this;
    }
}
