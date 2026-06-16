<?php

namespace App\Support\AdminDashboard;

use Illuminate\Support\Collection;

final class B2BIndustryWebsiteCatalog
{
    /**
     * @return list<array{key: string, name: string, initials: string, tone: string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'tianzhu', 'name' => '天助网', 'initials' => 'TZ', 'tone' => 'from-sky-50 to-blue-100 text-blue-700 ring-blue-200'],
            ['key' => 'bafang', 'name' => '八方资源网', 'initials' => 'BF', 'tone' => 'from-emerald-50 to-teal-100 text-emerald-700 ring-emerald-200'],
            ['key' => 'wuyou', 'name' => '无忧商务网', 'initials' => 'WY', 'tone' => 'from-orange-50 to-amber-100 text-orange-700 ring-orange-200'],
            ['key' => 'k2', 'name' => 'K2商务网', 'initials' => 'K2', 'tone' => 'from-violet-50 to-purple-100 text-violet-700 ring-violet-200'],
            ['key' => 'lingshang', 'name' => '领商网', 'initials' => 'LS', 'tone' => 'from-rose-50 to-pink-100 text-rose-700 ring-rose-200'],
            ['key' => 'wanjia', 'name' => '万家商务网', 'initials' => 'WJ', 'tone' => 'from-cyan-50 to-sky-100 text-cyan-700 ring-cyan-200'],
            ['key' => 'jiuzhou', 'name' => '九州资源网', 'initials' => 'JZ', 'tone' => 'from-lime-50 to-green-100 text-lime-700 ring-lime-200'],
            ['key' => 'chaxun123', 'name' => '查询123', 'initials' => '123', 'tone' => 'from-slate-50 to-gray-100 text-slate-700 ring-slate-200'],
            ['key' => 'shangji', 'name' => '商机导航', 'initials' => 'SJ', 'tone' => 'from-indigo-50 to-blue-100 text-indigo-700 ring-indigo-200'],
            ['key' => 'yifengcha', 'name' => '蚁蜂查', 'initials' => 'YF', 'tone' => 'from-yellow-50 to-amber-100 text-yellow-700 ring-yellow-200'],
        ];
    }

    public function exists(string $key): bool
    {
        return Collection::make($this->all())->contains(fn (array $website): bool => $website['key'] === $key);
    }
}
