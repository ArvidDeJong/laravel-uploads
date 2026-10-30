<?php

/**
 * Guards the rules of the GitHub Pages site in docs/: front matter, Liquid use,
 * single-source facts and the developer credit. Nothing here breaks loudly otherwise.
 */
function docsSitePath(string $path = ''): string
{
    return dirname(__DIR__).'/docs'.($path === '' ? '' : '/'.$path);
}

/**
 * @return array<string, string>
 */
function docsFrontMatter(string $file): array
{
    preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($file), $match);

    $values = [];
    foreach (explode("\n", $match[1] ?? '') as $line) {
        if (preg_match('/^(\w+):\s*(.*)$/', $line, $pair)) {
            $value = trim($pair[2]);

            // An unquoted ": " makes the YAML invalid, and Jekyll then silently ignores all front matter.
            expect(str_contains($value, ': ') && ! str_starts_with($value, '"'))
                ->toBeFalse(basename($file).': quote the value of '.$pair[1]);

            $values[$pair[1]] = trim($value, '"');
        }
    }

    return $values;
}

/**
 * Every Markdown file in docs/ is a page of the site.
 *
 * @return array<int, string>
 */
function docsSitePages(): array
{
    return glob(docsSitePath('*.md')) ?: [];
}

test('every page has a title, a unique description and a unique nav order', function () {
    $pages = docsSitePages();
    $descriptions = [];
    $navOrders = [];

    foreach ($pages as $page) {
        $meta = docsFrontMatter($page);

        expect($meta)->toHaveKeys(['title', 'description', 'nav_order'], basename($page));

        $descriptions[] = $meta['description'];
        $navOrders[] = $meta['nav_order'];
    }

    expect(count($pages))->toBeGreaterThanOrEqual(9);
    expect(array_unique($descriptions))->toHaveCount(count($pages));
    expect(array_unique($navOrders))->toHaveCount(count($pages));
});

test('pages use Liquid only where it is intended', function () {
    foreach (docsSitePages() as $page) {
        if (basename($page) === 'faq.md') {
            continue;
        }

        expect(file_get_contents($page))->not->toMatch('/\{\{|\{%/', basename($page));
    }
});

test('pages link only to pages that exist', function () {
    foreach (docsSitePages() as $page) {
        preg_match_all('/\]\(([a-z-]+\.md)(?:#[^)]*)?\)/', (string) file_get_contents($page), $links);

        foreach ($links[1] as $target) {
            expect(docsSitePath($target))->toBeFile(basename($page).' links to '.$target);
        }
    }
});

test('the FAQ, structured data and llms.txt read from the shared data', function () {
    $faq = (string) file_get_contents(docsSitePath('_data/faq.yml'));

    expect(substr_count($faq, "\n- q: ") + (str_starts_with(ltrim($faq), '- q: ') ? 1 : 0))->toBeGreaterThanOrEqual(6);
    expect(substr_count($faq, '- q: '))->toBe(substr_count($faq, '  a: '));

    expect(file_get_contents(docsSitePath('faq.md')))->toContain('site.data.faq');
    expect(file_get_contents(docsSitePath('llms.txt')))
        ->toContain('permalink: /llms.txt')
        ->toContain('site.data.faq')
        ->toContain('site.pages');
    expect(file_get_contents(docsSitePath('_includes/head_custom.html')))
        ->toContain('"FAQPage"')
        ->toContain('"SoftwareSourceCode"')
        ->toContain('site.data.faq');
});

test('the YAML files build, because one bad value fails the whole Pages build', function () {
    // An unquoted ": " makes the YAML invalid. Jekyll then aborts the build and GitHub keeps
    // serving the last version that did build, so the site looks fine while it is months old.
    foreach (['_config.yml', '_data/faq.yml'] as $file) {
        $path = docsSitePath($file);

        if (! is_file($path)) {
            continue;
        }

        $blockIndent = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            $indent = strlen($line) - strlen(ltrim($line));

            if ($blockIndent !== null) {
                // Inside a > or | block every line is text, whatever it contains.
                if (trim($line) === '' || $indent > $blockIndent) {
                    continue;
                }

                $blockIndent = null;
            }

            if (! preg_match('/^(\s*)(?:-\s+)?(\w+):(?:\s+(\S.*))?$/', $line, $pair)) {
                continue;
            }

            $value = trim($pair[3] ?? '');

            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, '>') || str_starts_with($value, '|')) {
                $blockIndent = strlen($pair[1]);

                continue;
            }

            $quoted = str_starts_with($value, '"')
                || str_starts_with($value, "'")
                || str_starts_with($value, '[');

            expect(str_contains($value, ': ') && ! $quoted)
                ->toBeFalse($file.' line '.($number + 1).': quote the value of '.$pair[2]);
        }
    }
});

test('the config holds the package facts and the sitemap plugin', function () {
    expect(file_get_contents(docsSitePath('_config.yml')))
        ->toContain('- jekyll-sitemap')
        ->toContain('name: darvis/laravel-uploads')
        ->toContain('company: ARVID.NL')
        ->toContain('url: https://arvid.nl')
        ->not->toContain('footer_content');
});

test('docs has no README that duplicates the site index', function () {
    expect(docsSitePath('README.md'))->not->toBeFile();
});

test('the pages a beginner needs exist and are linked from the index', function () {
    $index = (string) file_get_contents(docsSitePath('index.md'));

    foreach (['installation', 'quick-start', 'the-upload-model', 'conversions-and-pdf', 'private-files', 'livewire', 'administration', 'testing', 'troubleshooting', 'faq'] as $page) {
        expect(docsSitePath($page.'.md'))->toBeFile();
        expect($index)->toContain(']('.$page.'.md)');
    }

    expect(file_get_contents(docsSitePath('installation.md')))->toContain('## Check that it works');
});

test('the FAQ stays at six to ten questions', function () {
    $count = substr_count((string) file_get_contents(docsSitePath('_data/faq.yml')), '- q: ');

    expect($count)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(10);
});

test('anchors in links between pages point at a heading that exists', function () {
    foreach (docsSitePages() as $page) {
        preg_match_all('/\]\(([a-z-]+\.md)?#([a-z0-9-]+)\)/', (string) file_get_contents($page), $links, PREG_SET_ORDER);

        foreach ($links as $link) {
            $target = $link[1] === '' ? $page : docsSitePath($link[1]);
            preg_match_all('/^#{2,4} (.+)$/m', (string) file_get_contents($target), $headings);

            $anchors = array_map(
                fn (string $heading): string => trim((string) preg_replace('/[^a-z0-9 -]/', '', strtolower($heading))),
                $headings[1],
            );
            $anchors = array_map(fn (string $anchor): string => str_replace(' ', '-', $anchor), $anchors);

            expect(in_array($link[2], $anchors, true))
                ->toBeTrue(basename($page).' links to #'.$link[2].', which is not a heading in '.basename($target));
        }
    }
});

test('the documented config keys match the config file', function () {
    $config = require dirname(__DIR__).'/config/uploads.php';
    $installation = (string) file_get_contents(docsSitePath('installation.md'));

    foreach (array_keys($config) as $key) {
        expect($installation)->toContain('`'.$key);
    }

    // Keys stay in alphabetical order, in the file and in the config reader.
    $keys = array_keys($config);
    $sorted = $keys;
    sort($sorted);
    expect($keys)->toBe($sorted);
});

test('the footer credits ARVID.NL without a personal name', function () {
    $footer = (string) file_get_contents(docsSitePath('_includes/footer_custom.html'));

    expect($footer)->toContain('site.developer.company')
        ->toContain('site.developer.url')
        ->not->toContain('Arvid de Jong')
        ->not->toMatch('/developed by|made by/i');

    expect(file_get_contents(docsSitePath('_includes/head_custom.html')))->not->toContain('"Person"');
});
