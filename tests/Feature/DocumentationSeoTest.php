<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentationSeoTest extends TestCase
{
    public function test_every_documentation_page_has_a_unique_title_and_meta_description(): void
    {
        $pages = collect(File::files(resource_path('js/pages/documentation/api')))
            ->merge(File::files(resource_path('js/pages/documentation/guides')));

        $titles = [];
        $descriptions = [];

        foreach ($pages as $page) {
            $source = File::get($page->getPathname());

            $this->assertMatchesRegularExpression('/<Head title="([^"]+)">/', $source, "{$page->getFilename()} has no title.");
            $this->assertMatchesRegularExpression('/name="description"\s+content="([^"]{70,170})"/', $source, "{$page->getFilename()} has no meta description of 70 to 170 characters.");

            preg_match('/<Head title="([^"]+)">/', $source, $title);
            preg_match('/name="description"\s+content="([^"]+)"/', $source, $description);

            $titles[$page->getFilename().' ('.$page->getPath().')'] = $title[1];
            $descriptions[] = $description[1];
        }

        $this->assertCount(24, $titles);
        $this->assertSame(count($titles), count(array_unique($titles)), 'Two documentation pages share a title.');
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), 'Two documentation pages share a meta description.');
    }

    public function test_documentation_layout_renders_a_self_canonical(): void
    {
        $layout = File::get(resource_path('js/layouts/documentation/DocumentationLayout.vue'));

        $this->assertStringContainsString('<link head-key="canonical" rel="canonical" :href="canonicalPath" />', $layout);
    }
}
