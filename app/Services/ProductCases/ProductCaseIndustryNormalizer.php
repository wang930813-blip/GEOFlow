<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;

class ProductCaseIndustryNormalizer
{
    /**
     * @var array<string,list<string>>
     */
    private const KEYWORDS_BY_INDUSTRY = [
        '食品、饮料' => [
            '茶业',
            '茶叶',
            '茶品牌',
            '餐饮',
            '食品',
            '饮料',
            '本地生活',
            '地方菜',
            '小吃',
            '酒水',
        ],
        '传媒、广电' => [
            '影视',
            '影视制作',
            '宣传片',
            '视频制作',
            '短视频',
            '广告',
            '传媒',
            '广电',
            '品牌片',
            '拍摄',
        ],
        '机械及行业设备' => [
            '工业制造',
            '通风设备',
            '风机设备',
            '风机',
            '通风',
            '机械',
            '行业设备',
            '工业设备',
            '设备制造',
        ],
        '建筑、建材' => [
            '家居家装',
            '门窗',
            '系统门窗',
            '建材',
            '建筑',
            '装修',
            '装饰',
        ],
        '交通运输' => [
            '物流',
            '物流运输',
            '供应链',
            '运输',
            '货运',
        ],
        '汽摩及配件' => [
            '汽车服务',
            '汽车养护',
            '汽配',
            '汽摩',
            '汽车维修',
        ],
        '商务服务' => [
            '企业服务',
            '咨询',
            '财税',
            '法律服务',
            '人力资源',
        ],
        '教育培训' => [
            '教育',
            '培训',
            '课程',
            '课堂',
        ],
        '医药健康' => [
            '健康',
            '医疗',
            '医药',
            '养生',
        ],
        '数码、电脑' => [
            '数码',
            '电脑',
            '软件',
            'saas',
            '互联网',
        ],
    ];

    public function normalize(string $industry): string
    {
        $industry = $this->clean($industry);
        if ($industry === '') {
            return '其他';
        }

        foreach (ProductCase::industryOptions() as $option) {
            if ($industry === $option || str_contains($industry, $option)) {
                return $option;
            }
        }

        $haystack = $this->searchableText($industry);
        foreach (self::KEYWORDS_BY_INDUSTRY as $targetIndustry => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $this->searchableText($keyword))) {
                    return $targetIndustry;
                }
            }
        }

        foreach ($this->tokens($industry) as $token) {
            foreach (ProductCase::industryOptions() as $option) {
                if ($token !== '' && (str_contains($option, $token) || str_contains($token, $option))) {
                    return $option;
                }
            }
        }

        return '其他';
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function searchableText(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\/／、,，|｜\-—_]+/u', '', $value), 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[\s\/／、,，|｜\-—_]+/u', $value) ?: [],
            static fn (string $token): bool => trim($token) !== ''
        ));
    }
}
