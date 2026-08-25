<?php

namespace App\Support\AdminDashboard;

use Illuminate\Support\Collection;

final class B2BIndustryWebsiteCatalog
{
    /**
     * @return list<array{key: string, name: string, logo: string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'tianzhu', 'name' => '天助网', 'logo' => 'assets/b2b-sites/01.png'],
            ['key' => 'bafang', 'name' => '八方资源网', 'logo' => 'assets/b2b-sites/02.png'],
            ['key' => 'wuyou', 'name' => '无忧商务网', 'logo' => 'assets/b2b-sites/03.png'],
            ['key' => 'k2', 'name' => 'K2商务网', 'logo' => 'assets/b2b-sites/04.png'],
            ['key' => 'lingshang', 'name' => '领商网', 'logo' => 'assets/b2b-sites/05.png'],
            ['key' => 'wanjia', 'name' => '万家商务网', 'logo' => 'assets/b2b-sites/06.png'],
            ['key' => 'jiuzhou', 'name' => '九州资源网', 'logo' => 'assets/b2b-sites/07.png'],
            ['key' => 'chaxun123', 'name' => '查询123', 'logo' => 'assets/b2b-sites/08.png'],
            ['key' => 'shangji', 'name' => '商机导航', 'logo' => 'assets/b2b-sites/09.png'],
            ['key' => 'yifengcha', 'name' => '蚁蜂查', 'logo' => 'assets/b2b-sites/10.png'],
        ];
    }

    public function exists(string $key): bool
    {
        return Collection::make($this->all())->contains(fn (array $website): bool => $website['key'] === $key);
    }
}
