<?php

namespace App\Services\BrandDiagnosis;

class BrandDiagnosisQuestionLabeler
{
    public function coreTerm(string $question, string $storedCoreTerm = ''): string
    {
        $storedCoreTerm = $this->clean($storedCoreTerm);
        if ($storedCoreTerm !== '' && ! $this->isFullQuestionLikeCoreTerm($question, $storedCoreTerm)) {
            return mb_strimwidth($storedCoreTerm, 0, 120, '', 'UTF-8');
        }

        $term = $this->clean($question);
        $term = preg_replace('/[？?。.!！]+$/u', '', $term) ?? $term;
        $term = preg_replace('/^(适合|面向|市面上|有没有|能同时做|能做|想找|需要|如果需要|去)/u', '', $term) ?? $term;
        $term = preg_replace('/能不能买到(.+?)寄给.*$/u', '$1', $term) ?? $term;
        $term = preg_replace('/带什么/u', '', $term) ?? $term;
        $term = preg_replace('/有什么好吃的/u', '', $term) ?? $term;

        $suffixPatterns = [
            '/的口碑排行是怎样的$/u',
            '/哪家店.*$/u',
            '/哪家.*口碑好$/u',
            '/口感上有什么区别$/u',
            '/有什么区别$/u',
            '/有哪些推荐$/u',
            '/有哪些可选$/u',
            '/哪家口碑好$/u',
            '/哪家比较好$/u',
            '/哪家靠谱$/u',
            '/哪家好$/u',
            '/怎么选择$/u',
            '/怎么选$/u',
            '/有哪些$/u',
            '/口碑怎么样$/u',
            '/怎么样$/u',
            '/靠谱吗$/u',
            '/可靠吗$/u',
            '/推荐吗$/u',
            '/推荐$/u',
            '/比较有特色$/u',
            '/可选吗$/u',
            '/是什么$/u',
        ];

        foreach ($suffixPatterns as $pattern) {
            $term = preg_replace($pattern, '', $term) ?? $term;
        }

        $term = trim((string) preg_replace('/\s+/u', '', $term));

        return mb_strimwidth($term, 0, 120, '', 'UTF-8');
    }

    public function questionType(string $question, string $storedType = ''): string
    {
        $storedType = $this->clean($storedType);
        if ($storedType !== '' && $storedType !== '其他') {
            return mb_strimwidth($storedType, 0, 80, '', 'UTF-8');
        }

        $question = $this->clean($question);
        if (preg_match('/哪家好|哪家靠谱|怎么选|怎么选择|有哪些|推荐|可选/u', $question)) {
            return '选择';
        }
        if (preg_match('/口碑|评价|排行|怎么样/u', $question)) {
            return '口碑';
        }
        if (preg_match('/靠谱吗|可靠|值不值得|真实吗/u', $question)) {
            return '可信判断';
        }
        if (preg_match('/适合|面向|场景|行业/u', $question)) {
            return '场景';
        }

        return mb_strimwidth($storedType, 0, 80, '', 'UTF-8');
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', '', $value));
    }

    private function isFullQuestionLikeCoreTerm(string $question, string $coreTerm): bool
    {
        $question = preg_replace('/[？?。.!！]+$/u', '', $this->clean($question)) ?? $this->clean($question);
        $coreTerm = preg_replace('/[？?。.!！]+$/u', '', $this->clean($coreTerm)) ?? $this->clean($coreTerm);

        if ($question === '' || $coreTerm === '') {
            return false;
        }

        if ($question === $coreTerm) {
            return true;
        }

        if (mb_strlen($coreTerm, 'UTF-8') < 12) {
            return false;
        }

        return mb_strlen($coreTerm, 'UTF-8') >= (int) floor(mb_strlen($question, 'UTF-8') * 0.8)
            && (str_contains($question, $coreTerm) || str_contains($coreTerm, $question));
    }
}
