<?php
/**
 * Plugin Name: BibleHub Linker
 * Description: Automatically links Bible references to BibleHub.
 * Version: 1.1
 * Author: David Castle
 *
 * @file
 * @brief WordPress plugin that detects Bible references in post content and links them to BibleHub.
 *
 * @details
 * This plugin scans WordPress post content for Bible verse references (e.g., John 3:16, Ps 23, 1 Cor 13:4-7),
 * including support for common abbreviations, multi-word book names, chapter-only references, and optional
 * Bible version suffixes (e.g., NIV, KJV, NLT). It converts matches into hyperlinks pointing to
 * the corresponding page on https://biblehub.com.
 *
 * The plugin avoids altering content within excluded tags such as <a>, <pre>, <code>, <script>, and <style>.
 *
 * Features:
 * - Matches full and abbreviated book names
 * - Supports numeric prefixes (e.g., 1 John, 2 Thess)
 * - Detects and uses Bible versions in references (e.g., "John 3:16 NIV")
 * - Prevents duplicate linking and preserves document structure using DOM parsing
 * - Outputs anchor tags linking to BibleHub-formatted URLs
 *
 * @author David Castle
 * @version 1.1
 * @package BibleHubLinker
 * @license GPLv2 or later
 * @link https://biblehub.com
 */

// Hook into 'the_content' filter to modify post content before display
add_filter('the_content', 'bhl_link_bible_references', 20);

function bhl_link_bible_references($content) {
    // Suppress warnings from malformed HTML
    libxml_use_internal_errors(true);

    // Load the post content into a DOMDocument for structured parsing
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    $xpath = new DOMXPath($doc);

    // List of HTML tags to ignore when replacing Bible references
    $excludeTags = ['a', 'pre', 'code', 'script', 'style'];

    // Create an XPath expression that excludes text within the above tags
    $excludeXPath = implode(' or ', array_map(fn($tag) => "ancestor::{$tag}", $excludeTags));

    // Get all text nodes that are not inside excluded tags
    $textNodes = $xpath->query("//text()[not($excludeXPath)]");

    // Map of full book names to their common abbreviations
    $bookMap = [
        'genesis' => ['gen'],
        'exodus' => ['ex','exo','exod'],
        'leviticus' => ['lev'],
        'numbers' => ['num'],
        'deuteronomy' => ['deut', 'deu'],
        'joshua' => ['josh', 'jos'],
        'judges' => ['judg', 'jud'],
        'ruth' => ['ruth'],
        '1_samuel' => ['1_sam', '1sa'],
        '2_samuel' => ['2_sam', '2sa'],
        '1_kings' => ['1_kngs', '1ki'],
        '2_kings' => ['2_kngs', '2ki'],
        '1_chronicles' => ['1_chr', '1ch', '1 chron'],
        '2_chronicles' => ['2_chr', '2ch', '2 chron'],
        'ezra' => ['ezra','ezr'],
        'nehemiah' => ['neh'],
        'esther' => ['est'],
        'job' => ['job'],
        'psalms' => ['psalm','psa','pss','ps'],
        'proverbs' => ['prov', 'pro'],
        'ecclesiastes' => ['eccl','ecc'],
        'song' => ['song of solomon', 'song of songs'],
        'isaiah' => ['isa'],
        'jeremiah' => ['jer'],
        'lamentations' => ['lam'],
        'ezekiel' => ['ezek','eze'],
        'daniel' => ['dan'],
        'hosea' => ['hos'],
        'joel' => ['joel'],
        'amos' => ['amos','amo'],
        'obadiah' => ['obad','oba'],
        'jonah' => ['jon'],
        'micah' => ['mic'],
        'nahum' => ['nah'],
        'habakkuk' => ['hab'],
        'zephaniah' => ['zeph','zep'],
        'haggai' => ['hag'],
        'zechariah' => ['zech','zec'],
        'malachi' => ['mal'],
        'matthew' => ['mat', 'matt'],
        'mark' => ['mk','mar'],
        'luke' => ['lk','luk'],
        'john' => ['joh','jn', 'jhn'],
	'acts' => ['act'],
        'romans' => ['rom'],
        '1 corinthians' => ['1 cor','1co'],
        '2 corinthians' => ['2 cor','2co'],
        'galatians' => ['gal'],
        'ephesians' => ['eph'],
	'philippians' => ['phil', 'php'],
        '1 thessalonians' => ['1 thess', '1 thes','1th'],
        '2 thessalonians' => ['2 thess', '2 thes','2th'],
        '1 timothy' => ['1 tim','1ti'],
        '2 timothy' => ['2 tim','2ti'],
        'titus' => ['tit'],
        'philemon' => ['plmn','pmn'],
        'hebrews' => ['heb'],
        'james' => ['jas'],
	'1 peter' => ['1 pet','1pe'],
        '2 peter' => ['2 pet','2pe'],
        '1 john' => ['1 jn','1jo'],
        '2 john' => ['2 jn','2jo'],
        '3 john' => ['3 jn','3jo'],
	'jude' => ['jude','jde'],
        'revelation' => ['rev'],
    ];

    // Create a reverse lookup for abbreviation to full book name
    $abbrevToBook = [];
    foreach ($bookMap as $book => $abbrevs) {
        $abbrevToBook[$book] = $book;
        foreach ($abbrevs as $abbr) {
            $abbrevToBook[$abbr] = $book;
        }
    }

    // Sort the book keys by length (longest first) to match multi-word books correctly
    uksort($abbrevToBook, fn($a, $b) => strlen($b) - strlen($a));

    // Create a regex pattern for all known book names and abbreviations
    $bookRegex = implode('|', array_map(fn($b) => preg_quote($b, '/'), array_keys($abbrevToBook)));

    // Supported Bible versions used on BibleHub (uppercase for regex match)
    $bibleVersions = ['kjv', 'niv', 'nlt', 'esv', 'nasb', 'csb', 'net', 'web'];

    /**
     * @var string $pattern
      * @brief Regular expression pattern for matching Bible verse references in post content.
      *
      * @details
      * This pattern identifies and captures common Bible verse formats in post content.
      * It supports:
      * - Optional numeric book prefixes (e.g., "1", "2", "3" for "1 John", "2 Corinthians")
      * - Full and abbreviated book names (e.g., "John", "Jn", "1 Thess", "Song of Solomon")
      * - Required chapter numbers and optional verses or verse ranges (e.g., "John 3", "John 3:16", "1 Cor 13:4-7")
      * - Optional Bible version indicators (e.g., "KJV", "NLT", "NIV") with flexible formatting (e.g., "John 3:16 (NLT)")
      *
      * Regex structure:
      * @code
      * \b
      * (?:(1|2|3)\s+)?             	// Optional numeric prefix (e.g., "1 ", "2 ")
      * (bookRegex)                 	// Full or abbreviated book name
      * [\s\.]+                     	// Space or period after book name
      * (\d+)                       	// Chapter number (required)
      * (?::(\d+(?:-\d+)?))?        	// Optional verse number or verse range
      * (?:[\s\-\[\(]*([A-Z]+)[\]\)]*)? // Optional Bible version (e.g., KJV, NLT), enclosed in brackets or preceded by dash/space
      * @endcode
      *
      * The pattern is used with case-insensitive and Unicode-aware matching and is integrated with a callback function
      * that converts matched references into hyperlinks to the appropriate page on BibleHub.
      */
    $pattern = '/\b(?:(1|2|3)\s)?(' . $bookRegex . ')[\s\.]+(\d+)(?::(\d+(?:-\d+)?))?(?:[\s\-\[\(]*(' . implode('|', array_map('strtoupper', $bibleVersions)) . ')[\]\)]*)?/i';

    // Process each eligible text node
    foreach ($textNodes as $textNode) {
        $original = $textNode->nodeValue;

        // Replace matched Bible references with links to BibleHub
        $newHtml = preg_replace_callback($pattern, function ($matches) use ($abbrevToBook) {
            // Extract optional numeric prefix (e.g., "1" for "1 John")
            $prefix = isset($matches[1]) ? $matches[1] . ' ' : '';

            // Normalize the matched book abbreviation
            $rawBook = strtolower(trim($matches[2]));
            $mappedBook = $abbrevToBook[$rawBook] ?? $rawBook;

            // Avoid double-prefixing (e.g., "1 1 John")
            if (preg_match('/^(1|2|3)\s/i', $mappedBook)) {
                $book = $mappedBook;
            } else {
                $book = $prefix . $mappedBook;
            }

            // Convert book name to BibleHub URL format (e.g., "1 john" → "1_john")
            $bookPath = str_replace(' ', '_', strtolower(trim($book)));

            // Get chapter and optional verse
            $chapter = $matches[3];
            $verse = $matches[4] ?? null;

            // Get Bible version or use default "parallel"
            $version = strtolower($matches[5] ?? 'parallel');

            // Capitalize book name properly (e.g., "1 john" → "1 John")
            $refTextBook = implode(' ', array_map(function($word) {
                return is_numeric($word) ? $word : ucfirst($word);
            }, explode(' ', $book)));

            // Build the reference display text (e.g., "1 John 4:8 NLT")
            $refText = $refTextBook . ' ' . $chapter . ($verse ? ':' . $verse : '');
            if (isset($matches[5])) {
                $refText .= ' ' . strtoupper($version);
            }

            // Trim to remove any extra spacing
            $refText = trim($refText);
	    // Enclose within nobr tag to keep from wrapping references
	    $refText = '<nobr>' . $refText . '</nobr>';

            // Construct the BibleHub URL
            // TODO: Add Biblegateway url for MSG and PHILLIPS versions
	    // - chapter and verse (with optional version) --> parallel verse lookup
            // - chapter (with optional verse range) and version --> version chapter
            // - chapter only (without verse or version) --> default to NLT version
            if (!is_null($verse) && !strpos($verse, '-')) {
                $url = "https://biblehub.com/$bookPath/$chapter-$verse.htm";
            } elseif ($version !== 'parallel') {
                $url = "https://biblehub.com/$version/$bookPath/$chapter.htm";
            } else {
                $url = "https://biblehub.com/nlt/$bookPath/$chapter.htm";
            }

            // Return the anchor tag for the matched reference
            return "<a href=\"$url\" target=\"_blank\" rel=\"noopener noreferrer\">$refText</a>";
        }, $original);

        // If replacements were made, insert updated HTML into the DOM
        if ($newHtml !== $original) {
            $fragment = $doc->createDocumentFragment();
            @$fragment->appendXML($newHtml);
            $textNode->parentNode->replaceChild($fragment, $textNode);
        }
    }

    // Extract modified content from the <body> tag and return as post content
    $body = $doc->getElementsByTagName('body')->item(0);
    $newContent = '';
    foreach ($body->childNodes as $child) {
        $newContent .= $doc->saveHTML($child);
    }

    return $newContent;
}
