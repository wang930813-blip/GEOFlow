<?php

namespace App\Support;

use App\Models\PlatformPlan;
use App\Models\SiteSetting;

final class AdminRegistrationSettings
{
    public const ENABLED = 'admin_registration_enabled';

    public const EXPERIENCE_PLAN_ID = 'admin_registration_experience_plan_id';

    /**
     * @return array{enabled:bool,experience_plan_id:int|null}
     */
    public function all(): array
    {
        try {
            $stored = SiteSetting::query()
                ->withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->whereIn('setting_key', [self::ENABLED, self::EXPERIENCE_PLAN_ID])
                ->pluck('setting_value', 'setting_key')
                ->all();
        } catch (\Throwable) {
            $stored = [];
        }

        $planId = (int) ($stored[self::EXPERIENCE_PLAN_ID] ?? 0);

        return [
            'enabled' => in_array((string) ($stored[self::ENABLED] ?? ''), ['1', 'true', 'on'], true),
            'experience_plan_id' => $planId > 0 ? $planId : null,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->all()['enabled'];
    }

    public function experiencePlan(): ?PlatformPlan
    {
        $planId = $this->all()['experience_plan_id'];
        if ($planId === null) {
            return null;
        }

        return PlatformPlan::query()
            ->with('entitlements')
            ->whereKey($planId)
            ->where('status', 'active')
            ->whereIn('audience', ['direct', 'both'])
            ->first();
    }

    public function canRegister(): bool
    {
        return $this->isEnabled() && $this->experiencePlan() instanceof PlatformPlan;
    }

    /**
     * @param  array{enabled?:bool,experience_plan_id?:int|null}  $payload
     */
    public function update(array $payload): void
    {
        $values = [
            self::ENABLED => ! empty($payload['enabled']) ? '1' : '0',
            self::EXPERIENCE_PLAN_ID => (string) ((int) ($payload['experience_plan_id'] ?? 0)),
        ];

        SiteSetting::withoutEvents(function () use ($values): void {
            foreach ($values as $key => $value) {
                SiteSetting::query()
                    ->withoutGlobalScope('current_site')
                    ->updateOrCreate(
                        ['site_id' => null, 'setting_key' => $key],
                        ['site_id' => null, 'setting_value' => $value]
                    );
            }
        });
    }
}
