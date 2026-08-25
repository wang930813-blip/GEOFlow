<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisQuestionLabeler;
use Tests\TestCase;

class BrandDiagnosisQuestionLabelerTest extends TestCase
{
    public function test_core_term_ignores_stored_value_when_it_is_the_full_question(): void
    {
        $labeler = new BrandDiagnosisQuestionLabeler;

        $this->assertSame(
            '武城煊饼',
            $labeler->coreTerm('武城煊饼哪家店味道正宗口碑好', '武城煊饼哪家店味道正宗口碑好')
        );

        $this->assertSame(
            '武城煊饼和普通烧饼',
            $labeler->coreTerm('武城煊饼和普通烧饼口感上有什么区别', '武城煊饼和普通烧饼口感上有什么区别')
        );

        $this->assertSame(
            '山东武城旅游伴手礼面点',
            $labeler->coreTerm('去山东武城旅游带什么伴手礼面点比较有特色')
        );

        $this->assertSame(
            '武城煊饼真空包装',
            $labeler->coreTerm('武城煊饼能不能买到真空包装寄给外地朋友')
        );

        $this->assertSame(
            '山东德州武城地方特色面点小吃',
            $labeler->coreTerm('山东德州武城有什么好吃的地方特色面点小吃推荐')
        );
    }
}
