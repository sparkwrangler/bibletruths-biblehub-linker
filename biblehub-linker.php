<?php
/**
 * Plugin Name: BibleHub Linker
 * Description: Automatically links Bible references to BibleHub.
 * Version: 1.0
 * Author: David Castle
 */

add_filter('the_content', 'bhl_link_bible_references', 20);

function bhl_link_bible_references($content) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    $xpath = new DOMXPath($doc);

    // Tags to exclude
    $excludeTags = ['a', 'pre', 'code', 'script', 'style'];
    $excludeXPath = implode(' or ', array_map(fn($tag) => "ancestor::{$tag}", $excludeTags));

    $textNodes = $xpath->query("//text()[not($excludeXPath)]");

    // Book and abbreviation map
    $bookMap = [
        'genesis' => ['gen'],
        'exodus' => ['ex', 'exod'],
        '1 chronicles' => ['1 chron'],
        '2 chronicles' => ['2 chron'],
        'psalms' => ['ps', 'psa'],
        'proverbs' => ['prov', 'pr'],
        'isaiah' => ['isa'],
        'matthew' => ['mat', 'matt'],
        'mark' => ['mk'],
        'luke' => ['lk'],
        'john' => ['jn', 'jhn'],
        'romans' => ['rom'],
        '1 corinthians' => ['1 cor'],
        '2 corinthians' => ['2 cor'],
        '1 thessalonians' => ['1 thess', '1 thes'],
        '2 thessalonians' => ['2 thess', '2 thes'],
        'song of solomon' => ['song', 'song of songs'],
        '1 john' => ['1 jn'],
        '2 john' => ['2 jn'],
        '3 john' => ['3 jn'],
        'revelation' => ['rev'],
    ];

    $abbrevToBook = [];
    foreach ($bookMap as $book => $abbrevs) {
        $abbrevToBook[$book] = $book;
        foreach ($abbrevs as $abbr) {
            $abbrevToBook[$abbr] = $book;
        }
    }

    uksort($abbrevToBook, fn($a, $b) => strlen($b) - strlen($a));
    $bookRegex = implode('|', array_map(fn($b) => preg_quote($b, '/'), array_keys($abbrevToBook)));

    $bibleVersions = ['kjv', 'niv', 'nlt', 'esv', 'nasb', 'csb', 'net', 'web'];

    $pattern = '/\b(?:(1|2|3)\s)?(' . $bookRegex . ')[\s\.]+(\d+)(?::(\d+(?:-\d+)?))?(?:[\s\-\[\(]*(' . implode('|', array_map('strtoupper', $bibleVersions)) . ')[\]\)]*)?/i';

    foreach ($textNodes as $textNode) {
        $original = $textNode->nodeValue;

        $newHtml = preg_replace_callback($pattern, function ($matches) use ($abbrevToBook) {
            $prefix = isset($matches[1]) ? $matches[1] . ' ' : '';
            $rawBook = strtolower(trim($matches[2]));

            $book = $prefix . ($abbrevToBook[$rawBook] ?? $rawBook);

            $bookPath = str_replace(' ', '_', strtolower($book));

            $chapter = $matches[3];
            $verse = $matches[4] ?? null;
            $version = strtolower($matches[5] ?? 'parallel');

            // Fix proper title case: capitalize each word, preserving numeric prefixes
            $refTextBook = implode(' ', array_map(function($word) {
                return is_numeric($word) ? $word : ucfirst($word);
            }, explode(' ', $book)));

            $refText = $refTextBook . ' ' . $chapter . ($verse ? ':' . $verse : '');
            if (isset($matches[5])) {
                $refText .= ' ' . strtoupper($version);
            }

            if (isset($matches[5])) {
                $refText .= ' ' . strtoupper($version);
            }

            // Trim extraneous whitespace from previous operations
            $refText = trim($refText);

            if ($version === 'parallel') {
                $url = "https://biblehub.com/$bookPath/$chapter";
            } else {
                $url = "https://biblehub.com/$version/$bookPath/$chapter";
            }
            $url .= $verse ? "-$verse.htm" : ".htm";

            return "<a href=\"$url\" target=\"_blank\" rel=\"noopener noreferrer\">$refText</a>";
        }, $original);

        if ($newHtml !== $original) {
            $fragment = $doc->createDocumentFragment();
            @$fragment->appendXML($newHtml);
            $textNode->parentNode->replaceChild($fragment, $textNode);
        }
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $newContent = '';
    foreach ($body->childNodes as $child) {
        $newContent .= $doc->saveHTML($child);
    }

    return $newContent;
}
