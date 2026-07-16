<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_diagnosis_results', function (Blueprint $table): void {
            $table->json('snapshot_payload')->nullable();
        });

        DB::table('brand_diagnosis_results')
            ->select([
                'id',
                'run_id',
                'question_id',
                'platform',
                'answer',
                'status',
                'checked_at',
            ])
            ->whereNull('snapshot_payload')
            ->where('status', 'success')
            ->whereNotNull('answer')
            ->where('answer', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($results): void {
                $runBrands = DB::table('brand_diagnosis_runs')
                    ->whereIn('id', $results->pluck('run_id')->filter()->unique())
                    ->pluck('brand_name', 'id');
                $questions = DB::table('brand_diagnosis_questions')
                    ->whereIn('id', $results->pluck('question_id')->filter()->unique())
                    ->pluck('question', 'id');
                $sources = DB::table('brand_diagnosis_sources')
                    ->select(['result_id', 'title', 'url', 'domain'])
                    ->whereIn('result_id', $results->pluck('id'))
                    ->orderBy('id')
                    ->get()
                    ->groupBy('result_id');

                foreach ($results as $result) {
                    $sourceRows = collect($sources->get((int) $result->id, []))
                        ->filter(fn ($source): bool => $this->isHttpUrl((string) ($source->url ?? '')))
                        ->map(static fn ($source): array => [
                            'title' => (string) (($source->title ?? '') ?: ($source->url ?? '')),
                            'url' => (string) ($source->url ?? ''),
                            'domain' => (string) ($source->domain ?? ''),
                        ])
                        ->values()
                        ->all();
                    $payload = [
                        'version' => 1,
                        'brand' => (string) ($runBrands->get((int) $result->run_id) ?? ''),
                        'question' => (string) ($questions->get((int) $result->question_id) ?? ''),
                        'answer' => (string) ($result->answer ?? ''),
                        'platform' => (string) ($result->platform ?? ''),
                        'status' => (string) ($result->status ?? ''),
                        'checked_at' => (string) ($result->checked_at ?? ''),
                        'sources' => $sourceRows,
                    ];

                    DB::table('brand_diagnosis_results')
                        ->where('id', (int) $result->id)
                        ->update([
                            'snapshot_payload' => json_encode(
                                $payload,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                            ),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_diagnosis_results', function (Blueprint $table): void {
            $table->dropColumn('snapshot_payload');
        });
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }
};
