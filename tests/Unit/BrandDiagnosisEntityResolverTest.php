<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandEntityResolver;
use PHPUnit\Framework\TestCase;

class BrandDiagnosisEntityResolverTest extends TestCase
{
    public function test_product_or_project_brand_is_not_forced_through_company_suffix_rules(): void
    {
        $resolver = new BrandEntityResolver();

        $aliases = $resolver->aliases('策影GEO');

        $this->assertSame(['策影GEO'], $aliases);
        $this->assertTrue($resolver->containsBrandAlias('2026年策影GEO效果怎么样', '策影GEO'));
        $this->assertFalse($resolver->containsBrandAlias('2026年成都GEO优化服务怎么选', '策影GEO'));
    }

    public function test_company_aliases_keep_core_name_without_assuming_every_brand_is_company(): void
    {
        $resolver = new BrandEntityResolver();

        $aliases = $resolver->aliases('新知地（成都）人工智能科技有限公司');

        $this->assertContains('新知地（成都）人工智能科技有限公司', $aliases);
        $this->assertContains('新知地', $aliases);
        $this->assertTrue($resolver->isSameBrand('新知地', '新知地（成都）人工智能科技有限公司'));
        $this->assertFalse($resolver->isSameBrand('成都人工智能', '新知地（成都）人工智能科技有限公司'));
    }

    public function test_canonical_key_merges_full_company_name_and_short_brand_alias(): void
    {
        $resolver = new BrandEntityResolver();

        $this->assertSame(
            $resolver->canonicalKey('四川推来客网络科技有限公司'),
            $resolver->canonicalKey('推来客网络')
        );
        $this->assertTrue($resolver->isSameBrand('推来客网络', '四川推来客网络科技有限公司'));
    }

    public function test_invalid_generic_or_status_brand_names_are_rejected(): void
    {
        $resolver = new BrandEntityResolver();

        $this->assertFalse($resolver->isValidBrandName('本地服务商'));
        $this->assertFalse($resolver->isValidBrandName('暂未提及品牌'));
        $this->assertFalse($resolver->isValidBrandName('成都'));
        $this->assertTrue($resolver->isValidBrandName('四川推来客网络科技有限公司'));
        $this->assertTrue($resolver->isValidBrandName('策影GEO'));
    }
}
