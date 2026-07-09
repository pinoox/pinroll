<?php

namespace Pinoox\Pinroll\Support;

final class PushSteps
{
    /** @var list<string> */
    private static array $labels = [];

    private static int $current = 0;

    /**
     * @param list<string> $labels
     */
    public static function outline(array $labels): void
    {
        self::$labels = $labels;
        self::$current = 0;

        if ($labels === []) {
            return;
        }

        PushProgress::log('Plan', PushConsole::STYLE_PLAN);
        foreach ($labels as $index => $label) {
            PushProgress::log(($index + 1) . '. ' . $label, PushConsole::STYLE_PLAN_ITEM);
        }
        PushProgress::blank();
    }

    public static function start(string $label): void
    {
        self::$current++;
        $total = count(self::$labels);

        if ($total > 0) {
            PushProgress::log(PushConsole::formatStepBadge(self::$current, $total, $label), PushConsole::STYLE_RAW);

            return;
        }

        PushProgress::log($label, PushConsole::STYLE_STEP);
    }

    public static function done(string $detail = ''): void
    {
        PushProgress::success($detail !== '' ? $detail : 'done');
    }
}
