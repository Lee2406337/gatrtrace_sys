<?php
namespace App;

final class EventExpander
{
    public function expand(string $frequency, ?string $baseline, int $year, int $month): array
    {
        return match (EventFrequency::tryFrom($frequency)) {
            EventFrequency::Monthly => $this->expandMonthly($baseline, $year, $month),
            EventFrequency::Yearly => $this->expandYearly($baseline, $year, $month),
            EventFrequency::HalfYear => $this->expandHalfYear($baseline, $year, $month),
            EventFrequency::Weekly => $this->expandWeekly($baseline, $year, $month),
            EventFrequency::TwoYear => $this->expandMultiYear($baseline, 2, $year, $month),
            EventFrequency::ThreeYear => $this->expandMultiYear($baseline, 3, $year, $month),
            default => [],
        };
    }

    private function expandMonthly(?string $baseline, int $year, int $month): array
    {
        $parsed = BaselineFormat::parseMonthlyDay((string) $baseline);
        if ($parsed === null) {
            return [];
        }
        $day = $parsed['eom'] ? $this->daysInMonth($year, $month) : $parsed['day'];
        return [$this->clampDay($year, $month, $day)];
    }

    private function expandYearly(?string $baseline, int $year, int $month): array
    {
        $md = BaselineFormat::parseMonthDay((string) $baseline);
        if ($md === null || $md['mm'] !== $month) {
            return [];
        }
        return [$this->clampDay($year, $month, $md['dd'])];
    }

    private function expandHalfYear(?string $baseline, int $year, int $month): array
    {
        $groups = BaselineFormat::parseHalfYear((string) $baseline);
        if ($groups === null) {
            return [];
        }
        $out = [];
        foreach ($groups as $md) {
            if ($md['mm'] === $month) {
                $out[] = $this->clampDay($year, $month, $md['dd']);
            }
        }
        return $out;
    }

    private function expandWeekly(?string $baseline, int $year, int $month): array
    {
        $target = BaselineFormat::parseWeekday((string) $baseline); // ISO-8601: 1=週一 .. 7=週日
        if ($target === null) {
            return [];
        }
        $out = [];
        $last = $this->daysInMonth($year, $month);
        for ($day = 1; $day <= $last; $day++) {
            $iso = (int) date('N', mktime(0, 0, 0, $month, $day, $year));
            if ($iso === $target) {
                $out[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return $out;
    }

    private function expandMultiYear(?string $baseline, int $period, int $year, int $month): array
    {
        $d = BaselineFormat::parseFullDate((string) $baseline);
        if ($d === null) {
            return [];
        }
        if ($month !== $d['m'] || $year < $d['y'] || ($year - $d['y']) % $period !== 0) {
            return [];
        }
        return [$this->clampDay($year, $month, $d['d'])];
    }

    private function daysInMonth(int $year, int $month): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }

    private function clampDay(int $year, int $month, int $day): string
    {
        $day = max(1, min($day, $this->daysInMonth($year, $month)));
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
