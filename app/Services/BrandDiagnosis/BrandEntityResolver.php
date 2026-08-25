<?php

namespace App\Services\BrandDiagnosis;

class BrandEntityResolver
{
    /**
     * @return list<string>
     */
    public function aliases(string $brandName): array
    {
        $rawBrandName = $this->normalizeWhitespace($brandName, false);
        if ($rawBrandName === '') {
            return [];
        }

        $normalizedBrandName = $this->normalizeWhitespace($brandName);
        $aliases = [$rawBrandName];
        if ($normalizedBrandName !== $rawBrandName) {
            $aliases[] = $normalizedBrandName;
        }

        $withoutParentheses = $this->stripParenthetical($rawBrandName);
        if ($withoutParentheses !== '') {
            $aliases[] = $withoutParentheses;
        }

        if ($this->looksLikeOrganization($rawBrandName)) {
            $organizationCore = $this->stripOrganizationSuffix($withoutParentheses !== '' ? $withoutParentheses : $rawBrandName);
            if ($organizationCore !== '') {
                $aliases[] = $organizationCore;
            }

            $businessCore = $this->stripBusinessTerms($organizationCore);
            if ($businessCore !== '') {
                $aliases[] = $businessCore;
            }
        }

        return collect($aliases)
            ->map(fn (string $alias): string => $this->normalizeWhitespace($alias, false))
            ->filter(fn (string $alias): bool => $alias !== '' && $this->isValidAlias($alias))
            ->unique(fn (string $alias): string => mb_strtolower($alias, 'UTF-8'))
            ->values()
            ->all();
    }

    public function containsBrandAlias(string $text, string $brandName): bool
    {
        foreach ($this->aliases($brandName) as $alias) {
            if (mb_strlen($alias, 'UTF-8') < 2) {
                continue;
            }

            if (mb_stripos($text, $alias, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    public function isSameBrand(string $brandName, string $targetBrandName): bool
    {
        $brandKey = $this->canonicalKey($brandName);
        $targetKey = $this->canonicalKey($targetBrandName);

        return $brandKey !== '' && $targetKey !== '' && $brandKey === $targetKey;
    }

    public function canonicalName(string $brandName): string
    {
        $aliases = $this->aliases($brandName);

        return $aliases[0] ?? $this->normalizeWhitespace($brandName, false);
    }

    public function canonicalKey(string $brandName): string
    {
        $brandName = $this->normalizeWhitespace($brandName);
        if ($brandName === '' || ! $this->isValidBrandName($brandName)) {
            return '';
        }

        $key = $this->stripParenthetical($brandName);
        $key = $key !== '' ? $key : $brandName;
        $key = $this->stripOrganizationSuffix($key);
        $key = $this->stripBusinessTerms($key);
        $key = $this->stripLocationPrefix($key);
        $key = $this->normalizeWhitespace($key);

        return mb_strtolower($key !== '' ? $key : $brandName, 'UTF-8');
    }

    public function isValidBrandName(string $brandName): bool
    {
        $brandName = $this->normalizeWhitespace($brandName, false);
        if ($brandName === '') {
            return false;
        }

        $length = mb_strlen($brandName, 'UTF-8');
        if ($length < 2 || $length > 80) {
            return false;
        }

        $lower = mb_strtolower($brandName, 'UTF-8');
        foreach ($this->invalidExactNames() as $invalidName) {
            if ($lower === mb_strtolower($invalidName, 'UTF-8')) {
                return false;
            }
        }

        foreach ($this->invalidFragments() as $fragment) {
            if (mb_stripos($brandName, $fragment, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        return preg_match('/[\p{Han}A-Za-z0-9]/u', $brandName) === 1;
    }

    /**
     * @return array{canonical_name:string,canonical_key:string,aliases:list<string>,entity_type:string,confidence:float}
     */
    public function profile(string $brandName): array
    {
        $aliases = $this->aliases($brandName);
        $canonicalName = $aliases[0] ?? $this->normalizeWhitespace($brandName, false);

        return [
            'canonical_name' => $canonicalName,
            'canonical_key' => $this->canonicalKey($brandName),
            'aliases' => $aliases,
            'entity_type' => $this->looksLikeOrganization($brandName) ? 'organization' : 'brand',
            'confidence' => $this->looksLikeOrganization($brandName) ? 0.7 : 0.5,
        ];
    }

    private function normalizeWhitespace(string $value, bool $normalizeParentheses = true): string
    {
        $value = trim($value);
        if ($normalizeParentheses) {
            $value = str_replace(['（', '）'], ['(', ')'], $value);
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function stripParenthetical(string $value): string
    {
        $stripped = preg_replace('/[\(（][^\)）]*[\)）]/u', '', $value) ?? $value;

        return $this->normalizeWhitespace($stripped);
    }

    private function stripOrganizationSuffix(string $value): string
    {
        $suffixPattern = '/(?:股份有限公司|有限责任公司|有限公司|集团有限公司|集团|研究院|研究所|工作室|服务部|中心|门店|旗舰店|官网)$/u';
        $stripped = preg_replace($suffixPattern, '', $this->normalizeWhitespace($value)) ?? $value;

        return $this->normalizeWhitespace($stripped);
    }

    private function stripBusinessTerms(string $value): string
    {
        $termsPattern = '/(?:人工智能|信息技术|网络科技|软件开发|科技服务|科技|技术|信息|网络|软件|数字|智能|数据|服务|开发)+$/u';
        $stripped = preg_replace($termsPattern, '', $this->normalizeWhitespace($value)) ?? $value;

        return $this->normalizeWhitespace($stripped);
    }

    private function stripLocationPrefix(string $value): string
    {
        $stripped = preg_replace('/^(?:四川省?|成都(?:市)?|北京(?:市)?|上海(?:市)?|深圳(?:市)?|广州(?:市)?|杭州(?:市)?|重庆(?:市)?)/u', '', $value) ?? $value;

        return $this->normalizeWhitespace($stripped);
    }

    private function looksLikeOrganization(string $brandName): bool
    {
        foreach (['公司', '集团', '工作室', '服务部', '研究院', '研究所', '中心', '门店', '旗舰店'] as $word) {
            if (mb_stripos($brandName, $word, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function isValidAlias(string $alias): bool
    {
        return $this->isValidBrandName($alias) && mb_strlen($this->canonicalKey($alias), 'UTF-8') >= 2;
    }

    /**
     * @return list<string>
     */
    private function invalidExactNames(): array
    {
        return [
            '成都',
            '四川',
            '北京',
            '上海',
            '深圳',
            '广州',
            '杭州',
            '本地服务商',
            '服务商',
            '公司',
            '品牌',
            '竞品',
            '暂无竞品',
            '无',
        ];
    }

    /**
     * @return list<string>
     */
    private function invalidFragments(): array
    {
        return [
            '未提及品牌',
            '没有提及',
            '暂未提及',
            '暂未出现',
            '无法确认',
            '未检索到',
            '不存在的竞品',
        ];
    }
}
