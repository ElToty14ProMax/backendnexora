<?php

namespace App\Services;

class RoadmapRules
{
    public static function currentStep(array $levelCounts): array
    {
        $steps = [
            ['step' => 1, 'capacity' => 20, 'requirements' => []],
            ['step' => 2, 'capacity' => 50, 'requirements' => [[2, 5]]],
            ['step' => 3, 'capacity' => 100, 'requirements' => [[3, 5], [2, 10]]],
            ['step' => 4, 'capacity' => 200, 'requirements' => [[4, 5], [3, 10], [2, 20]]],
            ['step' => 5, 'capacity' => 350, 'requirements' => [[5, 5], [4, 10], [3, 20]]],
            ['step' => 6, 'capacity' => 500, 'requirements' => [[6, 5], [5, 10], [4, 20]]],
            ['step' => 7, 'capacity' => 750, 'requirements' => [[7, 5], [6, 10], [5, 20]]],
            ['step' => 8, 'capacity' => 1000, 'requirements' => [[8, 5], [7, 10], [6, 20]]],
            ['step' => 9, 'capacity' => 2000, 'requirements' => [[9, 5], [8, 10], [7, 20]]],
            ['step' => 10, 'capacity' => 5000, 'requirements' => [[10, 5], [9, 10], [8, 20]]],
        ];

        $current = $steps[0];
        foreach ($steps as $step) {
            $ok = true;
            foreach ($step['requirements'] as [$level, $users]) {
                if (self::countAtOrAbove($levelCounts, $level) < $users) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $current = $step;
            }
        }
        return $current;
    }

    private static function countAtOrAbove(array $levelCounts, int $level): int
    {
        $total = 0;
        foreach ($levelCounts as $currentLevel => $count) {
            if ((int) $currentLevel >= $level) {
                $total += (int) $count;
            }
        }
        return $total;
    }
}
