<?php

/**
 * Created by Codex.
 * @Date: 2026-10-29
 * @Time: 10:42:45
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： domestic-console.php
 * @Description: 保留策影GEO国内版媒体分发和 AiToEarn 补偿调度。
 */

use Illuminate\Support\Facades\Schedule;

Schedule::command('media-distribution:sync-submissions --limit=100')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('self-media:sync-aitoearn-flows --limit=50')
    ->everyMinute()
    ->withoutOverlapping();
