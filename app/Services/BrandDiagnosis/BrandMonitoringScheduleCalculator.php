<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:03:04
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandMonitoringScheduleCalculator.php
 *
 * @Description: 计算品牌监测计划的下一个每日、每周或每月执行时间。
 */

namespace App\Services\BrandDiagnosis;

use App\Models\BrandMonitoringPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BrandMonitoringScheduleCalculator
{
    /**
     * @Name: nextOccurrence
     *
     * @Description: 从当前时间之后按计划周期寻找下一次执行时间，并遵守起止日期、时区和周期间隔。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Param: BrandMonitoringPlan $plan 已保存的监测计划
     *
     * @Param: CarbonInterface|null $after 查询下界，默认使用当前时间
     *
     * @Return CarbonImmutable|null 下一次计划时间；周期已结束时返回 null
     *
     * @Throws: InvalidArgumentException 计划周期或日期格式无效
     */
    public function nextOccurrence(BrandMonitoringPlan $plan, ?CarbonInterface $after = null): ?CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $startDate = CarbonImmutable::parse((string) $plan->starts_on, $timezone)->startOfDay();
        $endDate = $plan->ends_on !== null
            ? CarbonImmutable::parse((string) $plan->ends_on, $timezone)->endOfDay()
            : null;
        $afterTime = $after instanceof CarbonInterface
            ? CarbonImmutable::instance($after)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
        $cursor = $afterTime->startOfDay()->lessThan($startDate) ? $startDate : $afterTime->startOfDay();
        $interval = max(1, (int) $plan->frequency_interval);
        $runTime = trim((string) $plan->run_time);
        $weekdays = collect((array) $plan->weekdays)
            ->map(static fn (mixed $day): int => (int) $day)
            ->filter(static fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()
            ->values()
            ->all();

        if (! in_array((string) $plan->frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new \InvalidArgumentException('监测计划周期无效。');
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $runTime)) {
            throw new \InvalidArgumentException('监测计划执行时间无效。');
        }

        $startWeek = $startDate->startOfWeek(CarbonImmutable::MONDAY);
        $searchLimit = $cursor->addYears(10);

        while ($cursor->lessThanOrEqualTo($searchLimit)) {
            if ($endDate instanceof CarbonImmutable && $cursor->greaterThan($endDate)) {
                return null;
            }

            $candidate = $cursor->setTimeFromTimeString($runTime);
            if ($candidate->greaterThan($afterTime) && $this->matchesFrequency($plan, $startDate, $startWeek, $cursor, $interval, $weekdays)) {
                return $candidate;
            }

            $cursor = $cursor->addDay();
        }

        return null;
    }

    /**
     * @Name: matchesFrequency
     *
     * @Description: 按计划间隔判断日期是否属于每日、指定星期或指定月日执行周期。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: BrandMonitoringPlan $plan 监测周期配置
     *
     * @Param: CarbonImmutable $startDate 周期锚点日期
     *
     * @Param: CarbonImmutable $startWeek 周期锚点所在周的周一
     *
     * @Param: CarbonImmutable $date 当前候选日期
     *
     * @Param: int $interval 周期重复间隔
     *
     * @Param: list<int> $weekdays 周期内执行星期
     *
     * @Return bool 候选日期是否命中配置周期
     *
     * @Throws: 无
     */
    private function matchesFrequency(
        BrandMonitoringPlan $plan,
        CarbonImmutable $startDate,
        CarbonImmutable $startWeek,
        CarbonImmutable $date,
        int $interval,
        array $weekdays,
    ): bool {
        return match ((string) $plan->frequency) {
            'daily' => ((int) $startDate->diff($date)->days % $interval) === 0,
            'weekly' => $this->matchesWeek($startWeek, $date, $interval, $weekdays),
            'monthly' => $this->matchesMonth($startDate, $date, $interval, (int) ($plan->month_day ?: $startDate->day)),
            default => false,
        };
    }

    /**
     * @Name: matchesWeek
     *
     * @Description: 以开始日期所在周为锚点检查周间隔和星期选择，默认每周一执行。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: CarbonImmutable $startWeek 周期锚点所在周的周一
     *
     * @Param: CarbonImmutable $date 当前候选日期
     *
     * @Param: int $interval 周间隔
     *
     * @Param: list<int> $weekdays 执行星期
     *
     * @Return bool 日期是否命中周计划
     *
     * @Throws: 无
     */
    private function matchesWeek(CarbonImmutable $startWeek, CarbonImmutable $date, int $interval, array $weekdays): bool
    {
        $weekdays = $weekdays !== [] ? $weekdays : [1];
        $week = $date->startOfWeek(CarbonImmutable::MONDAY);
        $weeksApart = intdiv((int) $startWeek->diff($week)->days, 7);

        return ($weeksApart % $interval) === 0 && in_array($date->dayOfWeek, $weekdays, true);
    }

    /**
     * @Name: matchesMonth
     *
     * @Description: 按月间隔匹配指定日；当月没有指定日期时使用当月最后一天。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: CarbonImmutable $startDate 周期锚点日期
     *
     * @Param: CarbonImmutable $date 当前候选日期
     *
     * @Param: int $interval 月间隔
     *
     * @Param: int $monthDay 月内执行日期
     *
     * @Return bool 日期是否命中月计划
     *
     * @Throws: 无
     */
    private function matchesMonth(CarbonImmutable $startDate, CarbonImmutable $date, int $interval, int $monthDay): bool
    {
        $monthsApart = (($date->year - $startDate->year) * 12) + $date->month - $startDate->month;
        $scheduledDay = min(max(1, $monthDay), $date->daysInMonth);

        return ($monthsApart % $interval) === 0 && $date->day === $scheduledDay;
    }
}
