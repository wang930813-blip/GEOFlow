<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaSubmission;
use App\Models\SiteCreditAccount;
use App\Models\SiteCreditLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SiteCreditService
{
    public function accountForSite(int $siteId): SiteCreditAccount
    {
        return SiteCreditAccount::query()->firstOrCreate(
            ['site_id' => $siteId],
            [
                'balance' => '0.00',
                'frozen_balance' => '0.00',
                'total_recharged' => '0.00',
                'total_consumed' => '0.00',
            ]
        );
    }

    public function ensureSufficient(int $siteId, mixed $amount): void
    {
        $account = $this->accountForSite($siteId);
        if ($this->moneyToCents($account->balance) < $this->moneyToCents($amount)) {
            throw new RuntimeException('当前站点积分不足');
        }
    }

    public function recharge(int $siteId, string $amount, ?int $operatorAdminId, string $remark = ''): SiteCreditAccount
    {
        return DB::transaction(function () use ($siteId, $amount, $operatorAdminId, $remark): SiteCreditAccount {
            $account = $this->lockedAccount($siteId);
            $amountCents = $this->moneyToCents($amount);
            if ($amountCents <= 0) {
                throw new RuntimeException('充值积分必须大于 0');
            }

            $balance = $this->moneyToCents($account->balance) + $amountCents;
            $totalRecharged = $this->moneyToCents($account->total_recharged) + $amountCents;
            $account->forceFill([
                'balance' => $this->centsToMoney($balance),
                'total_recharged' => $this->centsToMoney($totalRecharged),
            ])->save();

            $this->ledger($siteId, null, 'recharge', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    public function adjust(int $siteId, string $amount, ?int $operatorAdminId, string $remark = ''): SiteCreditAccount
    {
        return DB::transaction(function () use ($siteId, $amount, $operatorAdminId, $remark): SiteCreditAccount {
            $account = $this->lockedAccount($siteId);
            $amountCents = $this->moneyToCents($amount);
            if ($amountCents === 0) {
                throw new RuntimeException('调整积分不能为 0');
            }

            $balance = $this->moneyToCents($account->balance) + $amountCents;
            if ($balance < 0) {
                throw new RuntimeException('扣减后站点积分不能为负数');
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($balance),
            ])->save();

            $this->ledger($siteId, null, 'adjust', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    public function deductForSubmission(MediaSubmission $submission, ?int $operatorAdminId): SiteCreditAccount
    {
        return DB::transaction(function () use ($submission, $operatorAdminId): SiteCreditAccount {
            $account = $this->lockedAccount((int) $submission->site_id);
            $amountCents = $this->moneyToCents($submission->points_amount);
            $balanceCents = $this->moneyToCents($account->balance);
            if ($balanceCents < $amountCents) {
                throw new RuntimeException('当前站点积分不足');
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($balanceCents - $amountCents),
                'total_consumed' => $this->centsToMoney($this->moneyToCents($account->total_consumed) + $amountCents),
            ])->save();

            $this->ledger((int) $submission->site_id, (int) $submission->id, 'deduct', -$amountCents, $account, $operatorAdminId, '媒体投稿扣除');

            return $account;
        });
    }

    public function refundForSubmission(MediaSubmission $submission, ?int $operatorAdminId, string $remark = '媒体投稿失败退回'): SiteCreditAccount
    {
        return DB::transaction(function () use ($submission, $operatorAdminId, $remark): SiteCreditAccount {
            $account = $this->lockedAccount((int) $submission->site_id);
            $amountCents = $this->moneyToCents($submission->points_amount);
            $account->forceFill([
                'balance' => $this->centsToMoney($this->moneyToCents($account->balance) + $amountCents),
                'total_consumed' => $this->centsToMoney(max(0, $this->moneyToCents($account->total_consumed) - $amountCents)),
            ])->save();

            $this->ledger((int) $submission->site_id, (int) $submission->id, 'refund', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    private function lockedAccount(int $siteId): SiteCreditAccount
    {
        $this->accountForSite($siteId);

        return SiteCreditAccount::query()
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ledger(int $siteId, ?int $submissionId, string $type, int $amountCents, SiteCreditAccount $account, ?int $operatorAdminId, string $remark): void
    {
        SiteCreditLedger::query()->create([
            'site_id' => $siteId,
            'submission_id' => $submissionId,
            'type' => $type,
            'amount' => $this->centsToMoney($amountCents),
            'balance_after' => $account->balance,
            'frozen_after' => $account->frozen_balance,
            'operator_admin_id' => $operatorAdminId,
            'remark' => $remark,
            'created_at' => now(),
        ]);
    }

    private function moneyToCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
