<?php

/**
 * Created by Codex.
 * @Date: 2026-11-26
 * @Time: 11:22:14
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： AdminCreditService.php
 * @Description: 管理账号媒资积分余额和收支流水。
 */

namespace App\Services\MediaDistribution;

use App\Models\AdminCreditAccount;
use App\Models\AdminCreditLedger;
use App\Models\MediaSubmission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminCreditService
{
    public function accountForAdmin(int $adminId, int $siteId): AdminCreditAccount
    {
        return AdminCreditAccount::query()->firstOrCreate(
            ['admin_id' => $adminId, 'site_id' => $siteId],
            [
                'balance' => '0.00',
                'frozen_balance' => '0.00',
                'total_granted' => '0.00',
                'total_consumed' => '0.00',
            ]
        );
    }

    public function ensureSufficient(int $adminId, int $siteId, mixed $amount): void
    {
        $account = $this->accountForAdmin($adminId, $siteId);
        if ($this->moneyToCents($account->balance) < $this->moneyToCents($amount)) {
            throw new RuntimeException('当前账号积分不足');
        }
    }

    public function grant(int $adminId, int $siteId, string $amount, ?int $operatorAdminId, string $remark = ''): AdminCreditAccount
    {
        return DB::transaction(function () use ($adminId, $siteId, $amount, $operatorAdminId, $remark): AdminCreditAccount {
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($amount);
            if ($amountCents <= 0) {
                throw new RuntimeException('发放积分必须大于 0');
            }

            $balance = $this->moneyToCents($account->balance) + $amountCents;
            $totalGranted = $this->moneyToCents($account->total_granted) + $amountCents;
            $account->forceFill([
                'balance' => $this->centsToMoney($balance),
                'total_granted' => $this->centsToMoney($totalGranted),
            ])->save();

            $this->ledger($adminId, $siteId, null, 'grant', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    public function adjust(int $adminId, int $siteId, string $amount, ?int $operatorAdminId, string $remark = ''): AdminCreditAccount
    {
        return DB::transaction(function () use ($adminId, $siteId, $amount, $operatorAdminId, $remark): AdminCreditAccount {
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($amount);
            if ($amountCents === 0) {
                throw new RuntimeException('调整积分不能为 0');
            }

            $balance = $this->moneyToCents($account->balance) + $amountCents;
            if ($balance < 0) {
                throw new RuntimeException('扣减后账号积分不能为负数');
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($balance),
            ])->save();

            $this->ledger($adminId, $siteId, null, 'adjust', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    public function deductForSubmission(MediaSubmission $submission, int $adminId, ?int $operatorAdminId): AdminCreditAccount
    {
        return DB::transaction(function () use ($submission, $adminId, $operatorAdminId): AdminCreditAccount {
            $siteId = (int) $submission->site_id;
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($submission->points_amount);
            $balanceCents = $this->moneyToCents($account->balance);
            if ($balanceCents < $amountCents) {
                throw new RuntimeException('当前账号积分不足');
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($balanceCents - $amountCents),
                'total_consumed' => $this->centsToMoney($this->moneyToCents($account->total_consumed) + $amountCents),
            ])->save();

            $this->ledger($adminId, $siteId, (int) $submission->id, 'deduct', -$amountCents, $account, $operatorAdminId, '媒体投稿扣除');

            return $account;
        });
    }

    /**
     * @Name: refundForSubmission
     * @Description: 锁定积分账户后核对订单流水，按实际扣分一次性退回积分。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-26 11:22:14
     * @UpdateTime: 2026-11-26 11:22:14
     *
     * @Param: MediaSubmission $submission 需要退款的投稿订单
     * @Param: int $adminId 原扣分账号编号
     * @Param: int|null $operatorAdminId 执行退款的管理员编号
     * @Param: string $remark 退款流水备注
     * @Return: AdminCreditAccount 当前积分账户
     * @Throws: \Illuminate\Database\QueryException 积分账户或流水读写失败
     */
    public function refundForSubmission(
        MediaSubmission $submission,
        int $adminId,
        ?int $operatorAdminId,
        string $remark = '媒体投稿失败退回'
    ): AdminCreditAccount {
        return DB::transaction(function () use ($submission, $adminId, $operatorAdminId, $remark): AdminCreditAccount {
            $siteId = (int) $submission->site_id;
            $submissionId = (int) $submission->id;
            // 先锁定账户，再读取扣分和退款流水，避免并发退款重复入账。
            $account = $this->lockedAccount($adminId, $siteId);
            $submissionLedger = AdminCreditLedger::query()
                ->where('admin_id', $adminId)
                ->where('site_id', $siteId)
                ->where('submission_id', $submissionId);

            if ((clone $submissionLedger)->where('type', 'refund')->exists()) {
                return $account;
            }

            // 订单标价不代表已付款，按对应账户的实际扣分流水确定退款金额。
            $amountCents = -$this->moneyToCents(
                $submissionLedger->where('type', 'deduct')->where('amount', '<', 0)->sum('amount')
            );
            if ($amountCents <= 0) {
                return $account;
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($this->moneyToCents($account->balance) + $amountCents),
                'total_consumed' => $this->centsToMoney(max(0, $this->moneyToCents($account->total_consumed) - $amountCents)),
            ])->save();

            $this->ledger($adminId, $siteId, $submissionId, 'refund', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    private function lockedAccount(int $adminId, int $siteId): AdminCreditAccount
    {
        $this->accountForAdmin($adminId, $siteId);

        return AdminCreditAccount::query()
            ->where('admin_id', $adminId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ledger(
        int $adminId,
        int $siteId,
        ?int $submissionId,
        string $type,
        int $amountCents,
        AdminCreditAccount $account,
        ?int $operatorAdminId,
        string $remark
    ): void {
        AdminCreditLedger::query()->create([
            'admin_id' => $adminId,
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
