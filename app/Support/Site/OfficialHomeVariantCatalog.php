<?php

namespace App\Support\Site;

final class OfficialHomeVariantCatalog
{
    /**
     * @return array{
     *     key:string,
     *     number:string,
     *     name:string,
     *     layout:string,
     *     reference:string,
     *     kicker:string,
     *     section_title:string,
     *     section_body:string,
     *     metrics_title:string,
     *     proof:list<string>,
     *     capabilities:list<array{title:string,body:string}>,
     *     metrics:list<array{label:string,value:string}>
     * }
     */
    public function forTheme(string $themeId): array
    {
        $variants = $this->variants();

        return $variants[$themeId] ?? $this->defaultVariant($themeId);
    }

    /**
     * @return array<string, array{
     *     key:string,
     *     number:string,
     *     name:string,
     *     layout:string,
     *     reference:string,
     *     kicker:string,
     *     section_title:string,
     *     section_body:string,
     *     metrics_title:string,
     *     proof:list<string>,
     *     capabilities:list<array{title:string,body:string}>,
     *     metrics:list<array{label:string,value:string}>
     * }>
     */
    private function variants(): array
    {
        return [
            'geoflow-template-01-ink-editorial' => $this->variant('ink-editorial', '01', 'Ink Editorial', 'editorial', 'NYT-style editorial', 'Monochrome authority', 'A restrained official homepage for brands that need credibility, clear positioning, and long-form trust.', 'Governed brand knowledge, structured resources, and AI-ready proof points sit above the article layer.', 'Editorial operating signals'),
            'geoflow-template-02-market-briefing' => $this->variant('market-briefing', '02', 'Market Briefing', 'briefing', 'Bloomberg-style briefing', 'Market intelligence', 'A dense, data-forward homepage for fast-moving teams that want performance signals visible at first glance.', 'Compact cards, market-style metrics, and source evidence turn the official site into a live command surface.', 'Briefing room signals'),
            'geoflow-template-03-salmon-insight' => $this->variant('salmon-insight', '03', 'Salmon Insight', 'warm', 'FT-style insight', 'Warm executive insight', 'A warmer business homepage with calmer pacing, suited to premium services and strategic thought leadership.', 'Brand stories, executive narratives, and GEO evidence are staged like a considered business report.', 'Insight momentum'),
            'geoflow-template-04-red-opinion' => $this->variant('red-opinion', '04', 'Red Opinion', 'opinion', 'Economist-style opinion', 'Opinion-led clarity', 'A pointed homepage for brands that want sharp viewpoints, strong theses, and decisive conversion paths.', 'The page leads with a clear argument, then supports it with capabilities, metrics, and latest resources.', 'Point-of-view signals'),
            'geoflow-template-05-wire-clean' => $this->variant('wire-clean', '05', 'Wire Clean', 'wire', 'Reuters-style wire', 'Clean global wire', 'A clean, high-trust homepage for companies that prefer direct facts, concise proof, and minimal visual noise.', 'The structure favors source quality, credibility, and fast scanning across global official-site content.', 'Wire-quality signals'),
            'geoflow-template-06-public-broadcast' => $this->variant('public-broadcast', '06', 'Public Broadcast', 'broadcast', 'BBC-style broadcast', 'Public service clarity', 'A broadcast-style homepage that balances accessibility, broad navigation, and institutional trust.', 'Useful for education, public-service, and international teams that need clear entry points for many audiences.', 'Broadcast reach'),
            'geoflow-template-07-breaking-red' => $this->variant('breaking-red', '07', 'Breaking Red', 'breaking', 'CNN-style breaking', 'Fast-moving updates', 'A high-energy homepage for newsrooms, campaign teams, and brands that need urgent momentum above the fold.', 'Breaking-style panels make fresh updates and AI visibility signals feel immediate without reverting to a news feed.', 'Live growth signals'),
            'geoflow-template-08-section-blue' => $this->variant('section-blue', '08', 'Section Blue', 'section', 'Guardian-style sections', 'Section-led navigation', 'A section-first homepage that helps users quickly understand solutions, resources, and brand knowledge areas.', 'It is designed for official sites with multiple product lines, audience groups, or regional knowledge sections.', 'Section performance'),
            'geoflow-template-09-tech-spectrum' => $this->variant('tech-spectrum', '09', 'Tech Spectrum', 'spectrum', 'The Verge-style spectrum', 'Technology spectrum', 'A colorful technology homepage for teams that want product energy, AI visibility, and content depth in one page.', 'Gradient panels, signal cards, and resource grids make the official site feel more like a modern product surface.', 'Tech visibility signals'),
            'geoflow-template-10-wired-feature' => $this->variant('wired-feature', '10', 'Wired Feature', 'feature', 'WIRED-style feature', 'Feature story launch', 'A feature-led homepage for brands that want immersive storytelling and strong campaign-style first impressions.', 'The skeleton emphasizes one big narrative, then lets evidence and resources support the story below.', 'Feature impact'),
            'geoflow-template-11-product-newsroom' => $this->variant('product-newsroom', '11', 'Product Newsroom', 'product', 'Apple Newsroom-style product', 'Product newsroom', 'A polished product-newsroom homepage for launches, updates, and brand milestones.', 'Hero, feature cards, and resources are arranged around product clarity rather than a chronological feed.', 'Product launch signals'),
            'geoflow-template-12-saas-gradient' => $this->variant('saas-gradient', '12', 'SaaS Gradient', 'product', 'Stripe-style SaaS', 'SaaS growth system', 'A crisp SaaS homepage with gradient depth, conversion actions, and product-grade evidence modules.', 'It is suited to software, service platforms, and international B2B teams that need modern official-site presence.', 'SaaS operating signals'),
            'geoflow-template-13-linear-system' => $this->variant('linear-system', '13', 'Linear System', 'system', 'Linear-style system', 'Quiet product system', 'A calm, systematic homepage for product teams that value focus, workflow clarity, and structured execution.', 'The skeleton is quieter, with operating-system language and precise cards rather than heavy campaign visuals.', 'System quality signals'),
            'geoflow-template-14-knowledge-paper' => $this->variant('knowledge-paper', '14', 'Knowledge Paper', 'paper', 'Notion-style knowledge', 'Knowledge base homepage', 'A document-like homepage for teams that want the official site to feel like a structured knowledge system.', 'It frames services, resources, and GEO evidence as reusable brand knowledge rather than disposable posts.', 'Knowledge coverage'),
            'geoflow-template-15-reading-medium' => $this->variant('reading-medium', '15', 'Reading Medium', 'reading', 'Medium-style reading', 'Reading-first brand hub', 'A reading-first homepage that suits expert brands, founder-led content, and long-form insight programs.', 'The layout uses generous rhythm and a calmer resource section while keeping conversion actions above the fold.', 'Reader trust signals'),
            'geoflow-template-16-newsletter-letter' => $this->variant('newsletter-letter', '16', 'Newsletter Letter', 'newsletter', 'Substack-style newsletter', 'Letter from the brand', 'A newsletter-inspired homepage for communities, expert operators, and brands with recurring editorial programs.', 'It feels personal and direct while still carrying official website capabilities and measurable GEO signals.', 'Subscriber growth signals'),
            'geoflow-template-17-executive-review' => $this->variant('executive-review', '17', 'Executive Review', 'executive', 'HBR-style review', 'Executive review', 'An executive-facing homepage for advisory, enterprise service, and strategic research brands.', 'The page prioritizes boardroom clarity, proven capabilities, and high-level evidence over visual noise.', 'Executive decision signals'),
            'geoflow-template-18-consulting-insight' => $this->variant('consulting-insight', '18', 'Consulting Insight', 'consulting', 'McKinsey-style insight', 'Consulting insight', 'A consulting-style homepage for firms that need frameworks, outcomes, and credibility in equal measure.', 'It organizes capabilities as a strategic advisory system with metrics and cases supporting the narrative.', 'Advisory impact signals'),
            'geoflow-template-19-tech-review' => $this->variant('tech-review', '19', 'Tech Review', 'review', 'MIT Tech Review-style analysis', 'Research-backed technology', 'A science-and-technology homepage for brands that need analytical depth and future-facing credibility.', 'It balances product signals with research language, evidence panels, and resource-led authority.', 'Technology review signals'),
            'geoflow-template-20-research-journal' => $this->variant('research-journal', '20', 'Research Journal', 'research', 'Nature-style journal', 'Research journal', 'A research-journal homepage for science, healthcare, materials, and technical teams that need rigorous presentation.', 'The skeleton emphasizes evidence quality, methodology, source depth, and citation-friendly resource blocks.', 'Research evidence signals'),
        ];
    }

    /**
     * @return array{
     *     key:string,
     *     number:string,
     *     name:string,
     *     layout:string,
     *     reference:string,
     *     kicker:string,
     *     section_title:string,
     *     section_body:string,
     *     metrics_title:string,
     *     proof:list<string>,
     *     capabilities:list<array{title:string,body:string}>,
     *     metrics:list<array{label:string,value:string}>
     * }
     */
    private function variant(string $key, string $number, string $name, string $layout, string $reference, string $kicker, string $sectionTitle, string $sectionBody, string $metricsTitle): array
    {
        return [
            'key' => $key,
            'number' => $number,
            'name' => $name,
            'layout' => $layout,
            'reference' => $reference,
            'kicker' => $kicker,
            'section_title' => $sectionTitle,
            'section_body' => $sectionBody,
            'metrics_title' => $metricsTitle,
            'proof' => ['AI visibility', 'Brand evidence', 'Official resources'],
            'capabilities' => [
                ['title' => 'Brand positioning', 'body' => 'Shape a clear official-site message that AI answers and human visitors can both trust.'],
                ['title' => 'AI visibility', 'body' => 'Present diagnosis, source evidence, and answer presence as visible website modules.'],
                ['title' => 'Content system', 'body' => 'Turn articles, cases, and knowledge assets into structured brand resources.'],
                ['title' => 'Growth workflow', 'body' => 'Connect official-site content with publishing, monitoring, and conversion actions.'],
            ],
            'metrics' => [
                ['label' => 'AI platforms', 'value' => '9+'],
                ['label' => 'Content assets', 'value' => '1,200+'],
                ['label' => 'Brand signals', 'value' => '860+'],
            ],
        ];
    }

    /**
     * @return array{
     *     key:string,
     *     number:string,
     *     name:string,
     *     layout:string,
     *     reference:string,
     *     kicker:string,
     *     section_title:string,
     *     section_body:string,
     *     metrics_title:string,
     *     proof:list<string>,
     *     capabilities:list<array{title:string,body:string}>,
     *     metrics:list<array{label:string,value:string}>
     * }
     */
    private function defaultVariant(string $themeId): array
    {
        return $this->variant(
            str($themeId)->after('geoflow-template-')->replace('_', '-')->toString(),
            'GF',
            'Official Website',
            'default',
            'GEOFlow',
            'Official website system',
            'A dedicated official homepage for brand growth and AI-search visibility.',
            'The structure keeps core GEOFlow data contracts while avoiding raw default module output.',
            'Official growth signals'
        );
    }
}
