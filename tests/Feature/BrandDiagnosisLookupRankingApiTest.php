<?php

namespace Tests\Feature;

use App\Models\BrandDiagnosisRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BrandDiagnosisLookupRankingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('brand_diagnosis.lookup_api.enabled', true);
        Config::set('brand_diagnosis.lookup_api.api_key', 'test-lookup-key');
    }

    public function test_lookup_api_returns_rankings_for_all_models_and_selected_model(): void
    {
        $this->createLookupRunWithRankingData();

        $allResponse = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?'.http_build_query([
                'brand_word' => 'Ranking API Brand',
                'include' => 'rankings',
            ]))
            ->assertOk()
            ->assertJsonPath('data.rankings.model', 'all')
            ->assertJsonPath('data.rankings.mention_rate.0.brand', 'Competitor Alpha')
            ->assertJsonPath('data.rankings.mention_rate.0.rate', 100)
            ->assertJsonPath('data.rankings.mention_count.0.brand', 'Competitor Alpha')
            ->assertJsonPath('data.rankings.mention_count.0.count', 5)
            ->assertJsonPath('data.rankings.average_rank.0.brand', 'Ranking API Brand')
            ->assertJsonPath('data.rankings.average_rank.0.rank', '1.5')
            ->assertJsonPath('data.rankings.by_model.0.model', 'doubao')
            ->assertJsonPath('data.rankings.by_model.1.model', 'deepseek')
            ->assertJsonPath('data.rankings.by_model.2.model', 'qianwen')
            ->assertJsonPath('data.rankings.by_model.3.model', 'wenxin')
            ->assertJsonPath('data.module_status.rankings', 'included');

        $this->assertCount(4, $allResponse->json('data.rankings.by_model'));

        $doubaoResponse = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?'.http_build_query([
                'brand_word' => 'Ranking API Brand',
                'include' => 'rankings',
                'model' => 'doubao',
            ]))
            ->assertOk()
            ->assertJsonPath('data.rankings.model', 'doubao')
            ->assertJsonPath('data.rankings.mention_rate.0.brand', 'Competitor Alpha')
            ->assertJsonPath('data.rankings.mention_rate.0.rate', 100)
            ->assertJsonPath('data.rankings.average_rank.0.brand', 'Ranking API Brand')
            ->assertJsonPath('data.rankings.average_rank.0.rank', '1')
            ->assertJsonPath('data.rankings.by_model.0.model', 'doubao')
            ->assertJsonPath('data.module_status.rankings', 'included');

        $this->assertCount(1, $doubaoResponse->json('data.rankings.by_model'));
    }

    public function test_lookup_api_filters_competitors_by_model_and_returns_model_groups(): void
    {
        $this->createLookupRunWithRankingData();

        $allResponse = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?'.http_build_query([
                'brand_word' => 'Ranking API Brand',
                'include' => 'competitors',
            ]))
            ->assertOk()
            ->assertJsonPath('data.competitors.model', 'all')
            ->assertJsonPath('data.competitors.rows.0.brand_name', 'Competitor Alpha')
            ->assertJsonPath('data.competitors.rows.0.mention_count', 5)
            ->assertJsonPath('data.competitors.rows.1.brand_name', 'Competitor Beta')
            ->assertJsonPath('data.competitors.rows.1.mention_count', 4)
            ->assertJsonPath('data.competitors.by_model.0.model', 'doubao')
            ->assertJsonPath('data.competitors.by_model.0.rows.0.brand_name', 'Competitor Alpha')
            ->assertJsonPath('data.competitors.by_model.1.model', 'deepseek')
            ->assertJsonPath('data.competitors.by_model.1.rows.0.brand_name', 'Competitor Beta')
            ->assertJsonPath('data.module_status.competitors', 'included');

        $this->assertCount(4, $allResponse->json('data.competitors.by_model'));

        $doubaoResponse = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?'.http_build_query([
                'brand_word' => 'Ranking API Brand',
                'include' => 'competitors',
                'model' => 'doubao',
            ]))
            ->assertOk()
            ->assertJsonPath('data.competitors.model', 'doubao')
            ->assertJsonPath('data.competitors.rows.0.brand_name', 'Competitor Alpha')
            ->assertJsonPath('data.competitors.rows.0.mention_count', 4)
            ->assertJsonPath('data.competitors.by_model.0.model', 'doubao')
            ->assertJsonPath('data.module_status.competitors', 'included');

        $this->assertCount(1, $doubaoResponse->json('data.competitors.rows'));
        $this->assertCount(1, $doubaoResponse->json('data.competitors.by_model'));

        $emptyModelResponse = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?'.http_build_query([
                'brand_word' => 'Ranking API Brand',
                'include' => 'competitors',
                'model' => 'qianwen',
            ]))
            ->assertOk()
            ->assertJsonPath('data.competitors.model', 'qianwen')
            ->assertJsonPath('data.module_status.competitors', 'not_run');

        $this->assertSame([], $emptyModelResponse->json('data.competitors.rows'));
        $this->assertSame([], $emptyModelResponse->json('data.competitors.by_model.0.rows'));
    }

    private function createLookupRunWithRankingData(): BrandDiagnosisRun
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => 'Ranking API Brand',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'completed',
            'brand_profile' => 'Ranking API Brand provides GEO analytics.',
            'brand_profile_status' => 'success',
            'total_questions' => 2,
            'completed_questions' => 2,
            'brand_score' => 80,
            'mention_rate' => 67,
            'average_rank' => 1.5,
            'mention_count' => 2,
            'sentiment_rate' => 100,
        ]);

        $questionOne = $run->questions()->create([
            'site_id' => null,
            'question' => 'Which GEO analytics brands are recommended?',
            'question_type' => 'brand',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $questionTwo = $run->questions()->create([
            'site_id' => null,
            'question' => 'Which alternatives are popular?',
            'question_type' => 'competitor',
            'sort_order' => 2,
            'status' => 'completed',
        ]);

        $doubaoOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => 'Doubao recommends Ranking API Brand and Competitor Alpha.',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
        ]);
        $doubaoTwo = $questionTwo->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => 'Doubao mentions Competitor Alpha.',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
        ]);
        $deepseekOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => 'success',
            'answer' => 'DeepSeek recommends Competitor Beta, Ranking API Brand, and Competitor Alpha.',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
        ]);

        $doubaoOne->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => 'Ranking API Brand',
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => 'Competitor Alpha',
                'mention_count' => 2,
                'mention_rank' => 2,
                'sentiment' => 'positive',
                'source_count' => 2,
                'is_target_brand' => false,
            ],
        ]);
        $doubaoTwo->brandMentions()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'question_id' => $questionTwo->id,
            'platform' => 'doubao',
            'brand_name' => 'Competitor Alpha',
            'mention_count' => 2,
            'mention_rank' => 3,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
        ]);
        $deepseekOne->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'deepseek',
                'brand_name' => 'Ranking API Brand',
                'mention_count' => 1,
                'mention_rank' => 2,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'deepseek',
                'brand_name' => 'Competitor Alpha',
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'deepseek',
                'brand_name' => 'Competitor Beta',
                'mention_count' => 4,
                'mention_rank' => 4,
                'sentiment' => 'negative',
                'source_count' => 2,
                'is_target_brand' => false,
            ],
        ]);

        return $run;
    }
}
