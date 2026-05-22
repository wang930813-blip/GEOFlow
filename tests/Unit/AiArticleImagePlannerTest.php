<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiArticleImagePlanner;
use Tests\TestCase;

class AiArticleImagePlannerTest extends TestCase
{
    public function test_it_inserts_remote_images_after_requested_paragraph_positions(): void
    {
        $planner = app(AiArticleImagePlanner::class);
        $content = implode("\n\n", [
            '# Title',
            'First paragraph about private traffic.',
            'Second paragraph about course delivery.',
            'Third paragraph about conversion.',
        ]);

        $result = $planner->insertGeneratedImages($content, [
            [
                'paragraph_after' => 1,
                'alt' => 'Private traffic funnel',
                'url' => 'https://cdn.example.com/funnel.webp',
            ],
            [
                'paragraph_after' => 3,
                'alt' => 'Course live room',
                'url' => 'https://cdn.example.com/live-room.webp',
            ],
        ]);

        $this->assertSame(2, substr_count($result, '!['));
        $this->assertStringContainsString("First paragraph about private traffic.\n\n![Private traffic funnel](https://cdn.example.com/funnel.webp)\n\nSecond paragraph", $result);
        $this->assertStringContainsString("Third paragraph about conversion.\n\n![Course live room](https://cdn.example.com/live-room.webp)", $result);
    }

    public function test_it_clamps_invalid_positions_and_escapes_markdown_alt_text(): void
    {
        $planner = app(AiArticleImagePlanner::class);
        $content = "One\n\nTwo";

        $result = $planner->insertGeneratedImages($content, [
            [
                'paragraph_after' => 99,
                'alt' => 'Bad [alt](x)',
                'url' => 'https://cdn.example.com/a.webp',
            ],
            [
                'paragraph_after' => -5,
                'alt' => '',
                'url' => 'https://cdn.example.com/b.webp',
            ],
        ]);

        $this->assertStringContainsString('![Bad alt x](https://cdn.example.com/a.webp)', $result);
        $this->assertStringContainsString('![AI generated image](https://cdn.example.com/b.webp)', $result);
    }
}
