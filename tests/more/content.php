<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Files, Markdown, Preview, Privacy, PrivacyBlocked};

$d = test_dir('content');

// ---------------------------------------------------------------- Markdown ----

check('markdown: email lists, numbered lists and headings', Markdown::toHtml("1. one\n2) two\n\n## Title **b**\n\nplain")
    === "<ol><li>one</li><li>two</li></ol>\n<h3>Title <strong>b</strong></h3>\n<p>plain</p>");

check('markdown: email bullet lists', Markdown::toHtml("- a\n* b") === '<ul><li>a</li><li>b</li></ul>');
$tasks = Markdown::document("- [x] sent\n- [ ] call Alex\n\n![shot](https://acme.example/s.png \"Shot\")");
check('markdown: task lists and images with a title', str_contains($tasks, '<li class="task"><input type="checkbox" disabled checked>sent</li><li class="task"><input type="checkbox" disabled>call Alex</li>')
    && str_contains($tasks, '<img src="https://acme.example/s.png" alt="shot">'), $tasks);

$fm = Markdown::document("---\n# a comment\ntitle: Plan\nsummary: first part\n  and the rest\n# another comment\n---\nbody");
check('markdown: front matter continues values on the next line and skips comments',
    str_contains($fm, '<th>summary</th><td>first part and the rest</td>') && str_contains($fm, '<p>body</p>'), $fm);
$nf = Markdown::document("---\njust words here\n---\nbody");
check('markdown: a --- block that is not YAML is not a header', !str_contains($nf, 'front') && str_contains($nf, '<hr>'), $nf);
check('markdown: a header with only comments is not a header', !str_contains(Markdown::document("---\n# only a comment\n---\nx"), 'front'));

$code = Markdown::document("```php\necho '<b>';\n```\n\n~~~\nplain\n~~~~\n\n```\nnever closed");
check('markdown: fenced code with and without a language, escaped, unclosed fence runs to the end',
    str_contains($code, '<pre><code class="language-php">echo &#039;&lt;b&gt;&#039;;</code></pre>') && str_contains($code, '<pre><code>plain</code></pre>')
    && str_contains($code, '<pre><code>never closed</code></pre>'), $code);

$q = Markdown::document("> quoted\nlazy line\n# heading after\n\n> again\n- item");
check('markdown: block quotes take lazy lines and stop at a new block',
    str_contains($q, '<blockquote><p>quoted' . "\n" . 'lazy line</p></blockquote>') && str_contains($q, '<h1>heading after</h1>')
    && str_contains($q, '<blockquote><p>again</p></blockquote>') && str_contains($q, '<ul><li>item</li></ul>'), $q);

$st = Markdown::document("Big title\n===\n\nSmall title\n---\n\npara line\n## breaks here");
check('markdown: setext headings and paragraphs interrupted by a heading',
    str_contains($st, '<h1>Big title</h1>') && str_contains($st, '<h2>Small title</h2>') && str_contains($st, "<p>para line</p>\n<h2>breaks here</h2>"), $st);

$tb = Markdown::document("| l | c | r |\n|:--|:-:|--:|\n| a | b | c \\| d |\n| short |");
check('markdown: table alignment, escaped pipes and short rows',
    str_contains($tb, '<th>l</th>') && str_contains($tb, '<th style="text-align:center">c</th>') && str_contains($tb, '<td style="text-align:right">c | d</td>')
    && str_contains($tb, '<tr><td>short</td><td style="text-align:center"></td><td style="text-align:right"></td></tr>'), $tb);

$ol = Markdown::document("3. three\n4. four\n\n5. five");
check('markdown: an ordered list keeps its start and becomes loose across a blank line',
    str_contains($ol, '<ol start="3">') && str_contains($ol, '<li><p>five</p></li>'), $ol);
$nest = Markdown::document("- top\n  - nested\n\n  more of top\n- lazy\ncontinued\n- last\n# head");
check('markdown: nested lists, indented paragraphs, lazy continuation, a heading ends the list',
    str_contains($nest, '<ul><li>nested</li></ul>') && str_contains($nest, 'more of top') && str_contains($nest, "lazy\ncontinued")
    && str_contains($nest, '</ul>' . "\n" . '<h1>head</h1>'), $nest);
$empty = Markdown::document("-\nnot lazy");
check('markdown: an empty item does not swallow the next line', str_contains($empty, '<li></li>') && str_contains($empty, '<p>not lazy</p>'), $empty);
$mixed = Markdown::document("- a\n1. b\n\n- c\n\nafter");
check('markdown: a different list type starts a new list', str_contains($mixed, '<ul>') && str_contains($mixed, '<ol>') && str_contains($mixed, '<p>after</p>'), $mixed);

$in = Markdown::inline('![logo](https://acme.example/l.png) ![](http://acme.example/x.png) ![pic](javascript:x) [site](https://acme.example "Acme") [bad](javascript:alert(1)) <https://acme.example/a> see https://acme.example/b. \\*not em\\* `a<b` _em_ ~~gone~~ line  ' . "\nnext");
check('markdown: inline images (https only), links with titles, autolinks, escapes, code, emphasis, breaks',
    str_contains($in, '<img src="https://acme.example/l.png" alt="logo">') && str_contains($in, '[image]') && str_contains($in, '[pic]')
    && str_contains($in, '<a href="https://acme.example" title="Acme">site</a>') && !str_contains($in, 'javascript') && str_contains($in, 'bad')
    && str_contains($in, '<a href="https://acme.example/a">https://acme.example/a</a>') && str_contains($in, '<a href="https://acme.example/b">https://acme.example/b</a>.')
    && str_contains($in, '*not em*') && str_contains($in, '<code>a&lt;b</code>') && str_contains($in, '<em>em</em>') && str_contains($in, '<s>gone</s>')
    && str_contains($in, "<br>\nnext"), $in);

// ---------------------------------------------------------------- Preview ----

$zip = function (array $files) use ($d): string {
    $p = "$d/z" . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE);
    foreach ($files as $n => $c) $z->addFromString($n, $c);
    $z->close();
    return $p;
};
$png = (function () { $im = imagecreatetruecolor(4, 4); ob_start(); imagepng($im); return (string)ob_get_clean(); })();
$W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:v="urn:schemas-microsoft-com:vml"';
$REL = 'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"';

// Word
$docx = $zip([
    'word/document.xml' => "<w:document $W><w:body>"
        . '<w:p><w:pPr><w:pStyle w:val="MyHead"/><w:jc w:val="center"/></w:pPr><w:r><w:t>Styled heading</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="DocTitle"/></w:pPr><w:r><w:t>The title</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="Outlined"/></w:pPr><w:r><w:t>Outline level</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Plain Title</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="ListNumber"/><w:numPr><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>first</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="ListNumber"/></w:pPr><w:r><w:t>second</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="ListBullet"/></w:pPr><w:r><w:t>bullet</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="ListBullet"/></w:pPr></w:p>'
        . '<w:p/>'
        . '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:i/><w:u w:val="single"/><w:strike/><w:b w:val="0"/></w:rPr><w:t>styled</w:t><w:tab/><w:t>x</w:t><w:br/><w:noBreakHyphen/><w:lastRenderedPageBreak/></w:r>'
        . '<w:hyperlink r:id="rLink"><w:r><w:t>a link</w:t></w:r></w:hyperlink><w:hyperlink r:id="rLocal"><w:r><w:t>local</w:t></w:r></w:hyperlink><w:r><w:t></w:t></w:r></w:p>'
        . '<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>wide</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        . '<w:sdt><w:sdtContent><w:p><w:r><w:t>in a content control</w:t></w:r></w:p></w:sdtContent></w:sdt>'
        . '<w:p><w:r><w:pict><v:imagedata r:id="rImg"/></w:pict></w:r><w:r><w:drawing><a:blip r:embed="rBig"/></w:drawing></w:r><w:r><w:drawing><a:blip r:embed="rTxt"/></w:drawing></w:r><w:r><w:drawing><a:blip r:embed="rNone"/></w:drawing></w:r></w:p>'
        . '<w:bookmarkStart/></w:body></w:document>',
    'word/styles.xml' => "<w:styles $W>"
        . '<w:style w:type="paragraph" w:styleId="MyHead"><w:name w:val="Titre 3"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="DocTitle"><w:name w:val="Title"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Outlined"><w:name w:val="Custom"/><w:pPr><w:outlineLvl w:val="1"/></w:pPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>',
    'word/_rels/document.xml.rels' => "<Relationships $REL><Relationship Id=\"rLink\" Target=\"https://acme.example/x\" TargetMode=\"External\"/>"
        . '<Relationship Id="rLocal" Target="#bookmark" TargetMode="External"/><Relationship Id="rImg" Target="/word/media/a.png"/>'
        . '<Relationship Id="rBig" Target="../word/./media/big.png"/><Relationship Id="rTxt" Target="media/notes.txt"/></Relationships>',
    'word/media/a.png' => $png, 'word/media/big.png' => str_repeat('x', Preview::MAX_IMAGE + 10), 'word/media/notes.txt' => 'not an image',
]);
$dh = Preview::docx($docx);
check('preview: Word styles by name, title and outline level, alignment',
    str_contains($dh, '<h3 style="text-align:center">Styled heading</h3>') && str_contains($dh, '<h1>The title</h1>') && str_contains($dh, '<h2>Outline level</h2>')
    && str_contains($dh, '<h1>Plain Title</h1>'), $dh);
check('preview: Word numbered and bullet lists, empty paragraphs', str_contains($dh, '<ol><li>first</li><li>second</li></ol><ul><li>bullet</li></ul><p>&nbsp;</p><p>&nbsp;</p>'), $dh);
check('preview: Word run styles, tabs, breaks, external links only to the web',
    str_contains($dh, '<p style="text-align:right"><s><u><em>styled &emsp;x<br>-</em></u></s><a href="https://acme.example/x">a link</a>local</p>'), $dh);
check('preview: Word merged cells and content controls', str_contains($dh, '<td colspan="2"><p>wide</p></td>') && str_contains($dh, '<p>in a content control</p>'), $dh);
check('preview: Word images: VML ones shown, too large or unknown types dropped', substr_count($dh, '<img src="data:image/png;base64,') === 1, $dh);
check('preview: Word file without a document', (function () use ($zip) { try { Preview::docx($zip(['word/other.xml' => '<x/>'])); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'no document'); } })());
check('preview: broken XML is no document', (function () use ($zip) { try { Preview::docx($zip(['word/document.xml' => '<w:document><unclosed'])); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'no document'); } })());
check('preview: a file that is not a zip is refused', (function () use ($d) { file_put_contents("$d/bad.docx", 'nope'); try { Preview::docx("$d/bad.docx"); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'not a valid Office file'); } })());

// Excel
$S = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
$bigRows = ''; for ($i = 1; $i <= 1002; $i++) $bigRows .= "<row r=\"$i\"><c r=\"A$i\"><v>$i</v></c></row>";
$xlsx = $zip([
    'xl/workbook.xml' => "<workbook $S><sheets><sheet name=\"Budget\" r:id=\"rId1\"/><sheet name=\"Ghost\" r:id=\"rIdX\"/><sheet name=\"Missing\" r:id=\"rId2\"/><sheet name=\"Vide\" r:id=\"rId3\"/><sheet name=\"Long\" r:id=\"rId4\"/></sheets></workbook>",
    'xl/_rels/workbook.xml.rels' => "<Relationships $REL><Relationship Id=\"rId1\" Target=\"worksheets/sheet1.xml\"/><Relationship Id=\"rId2\" Target=\"worksheets/nothere.xml\"/>"
        . '<Relationship Id="rId3" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Target="/xl/worksheets/sheet4.xml"/></Relationships>',
    'xl/sharedStrings.xml' => "<sst $S><si><t>Poste</t></si><si><r><t>Mon</t></r><r><t>tant</t></r></si></sst>",
    'xl/worksheets/sheet1.xml' => "<worksheet $S><sheetData>"
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        . '<row><c t="inlineStr"><is><t>Inline</t></is></c><c t="b"><v>1</v></c><c t="b"><v>0</v></c><c><v>3.14159265358979</v></c><c r="BZ2"><v>far</v></c><c r="F2"/></row>'
        . '</sheetData></worksheet>',
    'xl/worksheets/sheet3.xml' => "<worksheet $S><sheetData><row r=\"1\"><c r=\"A1\"><v></v></c></row></sheetData></worksheet>",
    'xl/worksheets/sheet4.xml' => "<worksheet $S><sheetData>$bigRows</sheetData></worksheet>",
]);
$xh = Preview::xlsx($xlsx);
check('preview: Excel sheets by name, shared and inline strings, booleans, rounding, far columns dropped',
    str_contains($xh, '<div class="sheet-name">Budget</div>') && str_contains($xh, '<td>Poste</td><td>Montant</td>')
    && str_contains($xh, '<td>Inline</td><td>TRUE</td><td>FALSE</td><td class="num">3.141593</td>') && !str_contains($xh, 'far')
    && !str_contains($xh, 'Ghost') && !str_contains($xh, 'Missing'), $xh);
check('preview: Excel empty sheet and the first 1,000 rows only', str_contains($xh, '<div class="sheet-name">Vide</div><p class="muted">(empty)</p>')
    && str_contains($xh, 'First 1,000 rows shown.') && str_contains($xh, '<td class="num">1000</td>') && !str_contains($xh, '<td class="num">1001</td>'));
check('preview: Excel without a workbook falls back to the sheet files, empty workbook said so',
    str_contains(Preview::xlsx($zip(['xl/worksheets/sheet1.xml' => "<worksheet $S><sheetData><row><c><v>7</v></c></row></sheetData></worksheet>"])), 'Sheet 1')
    && Preview::xlsx($zip(['other.txt' => 'x'])) === '<p class="muted">(empty workbook)</p>');

// PowerPoint
$P = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
$sp = fn(string $ph, string $paras) => '<p:sp><p:nvSpPr><p:nvPr>' . ($ph !== '' ? "<p:ph type=\"$ph\"/>" : '') . "</p:nvPr></p:nvSpPr><p:txBody>$paras</p:txBody></p:sp>";
$pptx = $zip([
    'ppt/presentation.xml' => "<p:presentation $P><p:sldIdLst><p:sldId r:id=\"s2\"/><p:sldId r:id=\"s1\"/><p:sldId r:id=\"sX\"/><p:sldId r:id=\"s3\"/></p:sldIdLst></p:presentation>",
    'ppt/_rels/presentation.xml.rels' => "<Relationships $REL><Relationship Id=\"s1\" Target=\"slides/slide1.xml\"/><Relationship Id=\"s2\" Target=\"slides/slide2.xml\"/><Relationship Id=\"s3\" Target=\"slides/gone.xml\"/></Relationships>",
    'ppt/slides/slide1.xml' => "<p:sld $P><p:cSld><p:spTree><p:nvGrpSpPr/>"
        . $sp('ctrTitle', '<a:p><a:r><a:t>Kick-off</a:t></a:r></a:p>')
        . $sp('subTitle', '<a:p><a:r><a:t>Acme</a:t></a:r></a:p>')
        . $sp('', '<a:p><a:r><a:rPr b="1"/><a:t>Bold</a:t></a:r><a:br/><a:r><a:rPr i="1"/><a:t>It</a:t></a:r><a:fld><a:t>3</a:t></a:fld></a:p>')
        . $sp('', '<a:p><a:r><a:t>one</a:t></a:r></a:p><a:p><a:pPr lvl="1"/><a:r><a:t>two</a:t></a:r></a:p><a:p><a:r><a:t> </a:t></a:r></a:p>')
        . $sp('', '<a:p></a:p>')
        . '<p:grpSp>' . $sp('title', '<a:p><a:r><a:t>Grouped</a:t></a:r></a:p>') . '<p:pic><p:blipFill><a:blip r:embed="img"/></p:blipFill></p:pic><p:pic><p:blipFill><a:blip r:embed="nope"/></p:blipFill></p:pic></p:grpSp>'
        . '<p:graphicFrame><a:graphic><a:graphicData><a:tbl><a:tr><a:tc><a:txBody><a:p><a:r><a:t>H</a:t></a:r></a:p></a:txBody></a:tc></a:tr><a:tr><a:tc><a:txBody><a:p><a:r><a:t>9</a:t></a:r></a:p></a:txBody></a:tc></a:tr></a:tbl></a:graphicData></a:graphic></p:graphicFrame>'
        . '<p:graphicFrame/><p:cxnSp/></p:spTree></p:cSld></p:sld>',
    'ppt/slides/_rels/slide1.xml.rels' => "<Relationships $REL><Relationship Id=\"img\" Target=\"../media/i.png\"/></Relationships>",
    'ppt/media/i.png' => $png,
    'ppt/slides/slide2.xml' => "<p:sld $P><p:cSld><p:spTree/></p:cSld></p:sld>",
]);
$ph = Preview::pptx($pptx);
check('preview: PowerPoint in presentation order, titles, subtitles, bold and italic, levels, tables and pictures',
    preg_match('#<section class="slide"><span class="num">1</span><p class="muted">\(no text\)</p></section><section class="slide"><span class="num">2</span><h2>Kick-off</h2>#', $ph) === 1
    && str_contains($ph, '<p class="muted">Acme</p>') && str_contains($ph, '<p><strong>Bold</strong><br><em>It</em>3</p>')
    && str_contains($ph, '<ul><li>one</li><li style="margin-left:1.4em">two</li></ul>') && str_contains($ph, '<h2>Grouped</h2><p><img src="data:image/png;base64,')
    && str_contains($ph, '<th>H</th>') && str_contains($ph, '<td class="num">9</td>') && substr_count($ph, 'class="slide"') === 2, $ph);
check('preview: PowerPoint without slides', Preview::pptx($zip(['ppt/other.xml' => '<x/>'])) === '<p class="muted">(no slides)</p>');

// OpenDocument
$O = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:xlink="http://www.w3.org/1999/xlink"';
$odt = $zip(['content.xml' => "<office:document-content $O><office:body><office:text>"
    . '<text:h>No level</text:h><text:h text:outline-level="9">Deep</text:h>'
    . '<text:p>a<text:s text:c="3"/>b<text:tab/>c<text:line-break/><text:span>span</text:span> <text:a xlink:href="https://acme.example">web</text:a> <text:a xlink:href="file:///etc">local</text:a><!-- note --><text:bookmark/></text:p>'
    . '<text:p></text:p><text:p><draw:frame><draw:image xlink:href="Pictures/a.png"/></draw:frame></text:p>'
    . '<text:list><text:list-item><text:p>item one</text:p></text:list-item><text:list-item><text:p>item two</text:p></text:list-item><text:list-item><text:p>three</text:p><text:p>more</text:p></text:list-item></text:list>'
    . '<table:table table:name="T"><table:table-row><table:table-cell table:number-columns-repeated="2"><text:p>x</text:p></table:table-cell><table:covered-table-cell/><table:table-cell table:number-columns-repeated="500"/></table:table-row><table:table-row><table:table-cell/></table:table-row></table:table>'
    . '<text:section><text:p>in a section</text:p></text:section>'
    . '<draw:frame><draw:text-box><text:p>boxed</text:p></draw:text-box><draw:image xlink:href="Pictures/a.png"/></draw:frame><draw:custom-shape><text:p>shape</text:p></draw:custom-shape>'
    . '<text:sequence-decls/></office:text></office:body></office:document-content>', 'Pictures/a.png' => $png]);
$oh = Preview::odf($odt);
check('preview: OpenDocument levels, spaces, tabs, breaks, spans, web links only',
    str_contains($oh, '<h1>No level</h1>') && str_contains($oh, '<h6>Deep</h6>')
    && str_contains($oh, '<p>a   b &emsp;c<br>span <a href="https://acme.example">web</a> local</p>'), $oh);
check('preview: OpenDocument images, lists, tables (repeated cells, trailing blanks trimmed), sections, frames and shapes',
    substr_count($oh, '<p><img src="data:image/png;base64,') === 2 && str_contains($oh, '<ul><li>item one</li><li>item two</li><li><p>three</p><p>more</p></li></ul>')
    && str_contains($oh, '<tr><td>x</td><td>x</td></tr></table>') && !str_contains($oh, 'sheet-name')
    && str_contains($oh, '<p>in a section</p>') && str_contains($oh, '<p>boxed</p>') && str_contains($oh, '<p>shape</p>'), $oh);
$ods = Preview::odf($zip(['content.xml' => "<office:document-content $O><office:body><office:spreadsheet><table:table table:name=\"Feuille\"><table:table-row><table:table-cell><text:p>1</text:p></table:table-cell></table:table-row></table:table></office:spreadsheet></office:body></office:document-content>"]));
check('preview: OpenDocument spreadsheets name their sheets', str_contains($ods, '<div class="sheet-name">Feuille</div>') && str_contains($ods, '<td class="num">1</td>'), $ods);
$odp = Preview::odf($zip(['content.xml' => "<office:document-content $O><office:body><office:presentation><draw:page><draw:frame><draw:text-box><text:p>Slide A</text:p></draw:text-box></draw:frame></draw:page><draw:page/></office:presentation></office:body></office:document-content>"]));
check('preview: OpenDocument presentations as slides', str_contains($odp, '<section class="slide"><span class="num">1</span><p>Slide A</p></section><section class="slide"><span class="num">2</span></section>'), $odp);
check('preview: OpenDocument empty body and missing content',
    Preview::odf($zip(['content.xml' => "<office:document-content $O><office:body/></office:document-content>"])) === '<p class="muted">(empty)</p>'
    && (function () use ($zip) { try { Preview::odf($zip(['mimetype' => 'x'])); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'no content'); } })());

// CSV, HTML
$many = "n\n" . implode("\n", range(1, 2100)) . "\n";
$cv = Preview::csv($many);
check('preview: CSV shows the first 2,000 rows and says so', str_contains($cv, 'First 2,000 rows shown') && str_contains($cv, '<td class="num">1999</td>') && !str_contains($cv, '<td class="num">2001</td>'));
check('preview: CSV tabs detected, empty CSV', str_contains(Preview::csv("a\tb\n1\t2\n"), '<th>a</th><th>b</th>') && Preview::csv('') === '<p class="muted">(empty)</p>');
check('preview: an HTML page without a head gets a base anyway', Preview::htmlPage('<p>x</p>') === '<!doctype html><meta charset="utf-8"><base target="_blank"><p>x</p>'
    && str_contains(Preview::htmlPage('<html><head lang="fr"><title>t</title></head></html>'), '<head lang="fr"><base target="_blank"><title>'));

// render(): each kind through one entry point
$envBefore = array_map(fn($k) => [$k, getenv($k)], ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_EMBEDDINGS', 'TALIXIO_NOTIFY']);
putenv("TALIXIO_DATA_DIR=$d/pv"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_EMBEDDINGS=off'); putenv('TALIXIO_NOTIFY=none');
$pa = App::fromEnv();
$pv = new Preview($pa);
$file = function (string $name, string $content, string $kind = 'text', string $mime = '', bool $note = false, ?string $text = null) use ($d) {
    $p = "$d/" . bin2hex(random_bytes(3)) . '-' . $name; file_put_contents($p, $content);
    return ['name' => $name, 'path' => $p, 'kind' => $kind, 'mime' => $mime, 'note' => $note ? 1 : 0, 'text' => $text];
};
check('preview: renders text and office files only', Preview::renders(['kind' => 'office']) && !Preview::renders(['kind' => 'image']));
$r = $pv->render($file('Note', "\xEF\xBB\xBF# Bonjour", 'text', 'text/markdown', true));
check('preview: a note is rendered as Markdown (byte-order mark dropped)', str_contains($r, '<article class="doc"><h1>Bonjour</h1></article>') && str_contains($r, '<title>Note</title>'), $r);
check('preview: an HTML page by its type', str_contains($pv->render($file('page', '<head></head><p>hi</p>', 'text', 'text/html')), '<head><base target="_blank">'));
$eml = $pv->render($file('mail.eml', "From: Alex <alex@acme.example>\r\nTo: team@acme.example\r\nSubject: =?UTF-8?B?w4l0w6k=?=\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><head><style>p{}</style><title>T</title></head><body><p>Bonjour</p><script>alert(1)</script></body></html>"));
check('preview: an email with its headers and its HTML body, page parts dropped',
    str_contains($eml, '<tr><th>From</th><td>Alex &lt;alex@acme.example&gt;</td></tr>') && str_contains($eml, '<tr><th>Subject</th><td>Été</td></tr>')
    && str_contains($eml, '<p>Bonjour</p>') && !str_contains($eml, '<script') && !str_contains($eml, 'alert(1)') && !str_contains($eml, 'p{}') && !str_contains($eml, '<title>T') && !str_contains($eml, '<th>Cc</th>'), $eml);
$eml2 = $pv->render($file('m', "From: a@acme.example\r\nContent-Type: text/plain\r\n\r\nplain <body>", 'text', 'message/rfc822'));
check('preview: a plain-text email', str_contains($eml2, '<pre class="code">plain &lt;body&gt;</pre>'), $eml2);
check('preview: a TSV file, a CSV by its type', str_contains($pv->render($file('t.tsv', "a;b\tc\n")), '<th>a;b</th><th>c</th>') && str_contains($pv->render($file('data', "x,y\n", 'text', 'text/csv')), '<th>x</th><th>y</th>'));
check('preview: JSON pretty-printed, broken JSON as it is', str_contains($pv->render($file('a.json', '{"a":[1]}')), "{\n    &quot;a&quot;: [\n        1\n    ]\n}")
    && str_contains($pv->render($file('b.json', '{oops')), '<pre class="code">{oops</pre>'));
check('preview: Office files by extension', str_contains($pv->render($file('d.docx', (string)file_get_contents($docx), 'office')), 'Styled heading')
    && str_contains($pv->render($file('x.xlsx', (string)file_get_contents($xlsx), 'office')), 'Budget') && str_contains($pv->render($file('p.pptx', (string)file_get_contents($pptx), 'office')), 'Kick-off')
    && str_contains($pv->render($file('o.odt', (string)file_get_contents($odt), 'office')), 'No level'));
check('preview: other text in Windows-1252 shown as UTF-8', str_contains($pv->render($file('readme.log', "caf\xE9 <x>")), '<pre class="code">café &lt;x&gt;</pre>'));
check('preview: other kinds show the extracted text', str_contains($pv->render($file('old.doc', 'bin', 'office', '', false, 'extracted words')), '<pre class="code">extracted words</pre>'));
$bad = $pv->render($file('broken.docx', 'not a zip', 'office', '', false, 'kept text'));
check('preview: a broken file says why, with its text when there is one', str_contains($bad, 'This file could not be shown (not a valid Office file)') && str_contains($bad, '<h3>Text</h3><pre class="code">kept text</pre>'), $bad);
check('preview: a broken file without text', !str_contains($pv->render($file('broken.xlsx', 'nope', 'office')), '<h3>Text</h3>'));


// ---------------------------------------------------------------- Privacy ----

putenv("TALIXIO_DATA_DIR=$d/pr");
$a = App::fromEnv();
$c = $a->cfg;
$pr = $a->privacy();
$savedPrivacy = [Privacy::$taint, Privacy::$trace, Privacy::$onEvent, Privacy::$allowOnce, Privacy::$purpose, App::$inWorker, Privacy::$untrusted, Privacy::$privateRead, Privacy::$links];
$isBlocked = function (callable $fn): bool { try { $fn(); return false; } catch (PrivacyBlocked) { return true; } };
$out = fn(string $url, string $purpose = 'model', int $bytes = 100) => Privacy::as($purpose, fn() => $pr->outbound($url, $bytes));

$conf = $a->spaces()->save(['name' => 'Dossier Acme', 'folder' => '/Clients/Acme', 'privacy' => 'confidential']);
$sec = $a->spaces()->save(['name' => 'Vault', 'folder' => '/Vault', 'privacy' => 'secret']);
$loose = $a->spaces()->save(['name' => 'Loose secret', 'privacy' => 'secret']);
$odd = $a->spaces()->save(['name' => 'Odd']);
$a->store->q("UPDATE spaces SET privacy = 'top' WHERE id = ?", [(int)$odd['id']]);
check('privacy: a stored level that is not one of the three counts as internal', $pr->spaceLevel((int)$odd['id']) === 'internal' && $pr->spaceLevel(0) === 'internal');
check('privacy: a file that is gone is internal, a message has its space\'s level',
    $pr->docLevel(['kind' => 'file', 'ref' => 999999]) === 'internal' && $pr->docLevel(['kind' => 'message', 'space' => (int)$conf['id']]) === 'confidential');

$a->space = (int)$loose['id'];
check('privacy: a secret space without a folder sees no secret folder', $pr->hidden('secret', null, '/Vault') && !$pr->hidden('internal'));
$a->space = (int)$sec['id'];
check('privacy: inside a secret space its own folder is readable, other secret spaces are not',
    !$pr->hidden('secret', null, '/Vault/notes') && $pr->hidden('secret', null, '/Elsewhere') && $pr->hidden('secret', null, null) && $pr->hidden('secret', (int)$loose['id']) && !$pr->hidden('secret', (int)$sec['id']));
$a->space = 0;
Privacy::$taint = 0;
$hits = $pr->readable([['folder' => '/Clients/Acme/2026'], ['folder' => '/Vault', 'name' => 'key.txt']]);
check('privacy: a hit without a name still raises the level; secret hits are left out from elsewhere', count($hits) === 1 && $pr->effective() === 'confidential');
Privacy::$taint = 0;

$a->store->q("INSERT INTO docs(kind, ref, ts, label, space, text) VALUES('message', 0, 1, 'm', ?, 'acme budget')", [(int)$conf['id']]);
$confDoc = (int)$a->store->q('SELECT MAX(id) FROM docs')->fetchColumn();
$a->store->q("INSERT INTO docs(kind, ref, ts, label, space, text) VALUES('message', 0, 1, 'm', ?, 'vault code')", [(int)$loose['id']]);
$secDoc = (int)$a->store->q('SELECT MAX(id) FROM docs')->fetchColumn();
$c->embeddings = 'sovereign';
$noOut = $pr->noOutsideEmbedding();
check('privacy: a European indexing service may index confidential content, never secret', in_array($secDoc, $noOut, true) && !in_array($confDoc, $noOut, true));
$c->embeddings = 'openai';
$noOut = $pr->noOutsideEmbedding();
check('privacy: other indexing services get neither', in_array($secDoc, $noOut, true) && in_array($confDoc, $noOut, true));

Privacy::$trace = null;
check('privacy: no trace, nothing recorded', Privacy::end() === null && (Privacy::blocked('x') ?? true) && Privacy::$trace === null);
Privacy::begin();
check('privacy: an empty trace is no trace', Privacy::end() === null);

// services
$c->ntfyUrl = 'https://ntfy.acme.example/topic'; $c->searxngUrl = 'https://search.acme.example'; $c->openaiBaseUrl = 'https://llm.acme.example/v1';
file_put_contents("$d/pr/mcp.json", json_encode(['mcpServers' => ['Acme CRM' => ['url' => 'https://crm.acme.example/mcp'], 'local tool' => ['command' => 'true']]]));
check('privacy: own notification, search, model and connector servers are recognised',
    $pr->service('https://ntfy.acme.example/topic')['id'] === 'ntfy' && $pr->service('https://search.acme.example/?q=x')['name'] === 'SearXNG (search.acme.example)'
    && $pr->service('https://llm.acme.example/v1/chat')['id'] === 'model:llm.acme.example' && $pr->service('https://crm.acme.example/mcp')['id'] === 'mcp:acme_crm'
    && $pr->service('https://other.acme.example/')['id'] === 'web' && $pr->service('http://localhost:8080/')['name'] === 'this server'
    && $pr->service('https://api.mistral.ai/v1/chat')['id'] === 'mistral');
$c->ollamaUrl = 'http://127.0.0.1:11434';
check('privacy: only the Ollama port is named Ollama', $pr->service('http://127.0.0.1:11434/api/chat')['name'] === 'Ollama' && $pr->service('http://127.0.0.1:8080/')['name'] === 'this server');

$grade = function (string $provider, string $base = 'https://api.openai.com/v1') use ($c, $pr): string { $c->provider = $provider; $c->openaiBaseUrl = $base; return $pr->modelGrade(); };
check('privacy: a European provider is sovereign, an unknown model server is unknown',
    $grade('sovereign', 'https://api.mistral.ai/v1') === 'sovereign' && $grade('openai', 'https://llm.acme.example/v1') === 'unknown' && $grade('openai') === 'business');
$c->sovereignOnly = true;
check('privacy: sovereign only: a US model is refused at every level', $grade('openai') === 'business' && $pr->modelBlocked('internal') === 'sovereign-only'
    && $grade('sovereign', 'https://api.mistral.ai/v1') === 'sovereign' && $pr->modelBlocked('internal') === null && $pr->modelBlocked('confidential') === null);
Privacy::begin();
check('privacy: sovereign only: a US service is blocked and journaled', $isBlocked(fn() => $out('https://api.search.brave.com/res', 'search'))
    && (int)$a->store->q("SELECT blocked FROM egress WHERE service = 'brave' ORDER BY id DESC LIMIT 1")->fetchColumn() === 1);
$c->sovereignOnly = false;

// what each request is for, at the internal level
$events = [];
Privacy::$onEvent = function (array $e) use (&$events) { $events[] = $e; };
foreach (['https://api.telegram.org/bot/x', 'https://www.googleapis.com/calendar', 'https://graph.microsoft.com/me', 'https://api.spotify.com/v1/me', 'https://fcm.googleapis.com/send',
    'https://ntfy.acme.example/topic', 'https://search.acme.example/', 'https://crm.acme.example/mcp', 'https://other.acme.example/', 'https://api.voyageai.com/v1', 'https://api.mistral.ai/v1/chat', 'https://api.mistral.ai/v1/chat'] as $u) $out($u, 'model', 10);
$out('http://localhost:9000/x', 'transcription');
$out('http://127.0.0.1:11434/api/embed', 'embeddings');
$out('http://localhost:9000/v1/chat', 'model');
$tr = Privacy::end();
$purposes = array_column($tr['out'], 'purpose');
check('privacy: requests not to the model are named by what they are for',
    $purposes === ['chat', 'calendar', 'calendar', 'music', 'notify', 'notify', 'search', 'connector', 'web', 'other', 'model'], json_encode($purposes));
check('privacy: repeated requests add up in the trace', end($tr['out'])['calls'] === 2 && end($tr['out'])['bytes'] === 20);
check('privacy: local work is in the trace, the local model only when it is Ollama', $tr['local'] === ['Transcription', 'Ollama']);
check('privacy: each request is told as it leaves', count(array_filter($events, fn($e) => !$e['local'])) === 12 && $events[0]['purpose'] === 'chat');

// the journal survives a broken table, and old lines go
$a->store->q('ALTER TABLE egress RENAME TO egress_off');
check('privacy: a journal that cannot be written does not stop the request', !$isBlocked(fn() => $out('https://other.acme.example/x', 'web')));
$a->store->q('ALTER TABLE egress_off RENAME TO egress');
$a->store->q("INSERT INTO egress(ts, service, purpose, bytes, space, level, blocked) VALUES(?, 'web', 'web', 1, 0, 'internal', 0)", [time() - 200 * 86400]);
check('privacy: journal lines older than 180 days are purged', $pr->purge() === 1);
$jn = $pr->journal();
$byName = array_column($jn['week'], 'name', 'service');
check('privacy: the journal names connectors, custom models and unknown services',
    ($byName['mcp:acme_crm'] ?? '') === 'Connector acme_crm' && ($byName['web'] ?? '') === 'web' && ($byName['telegram'] ?? '') === 'Telegram' && count($pr->journalRows()) >= 12);
$c->provider = 'openai'; $c->openaiBaseUrl = 'https://llm.acme.example/v1';
$out('https://llm.acme.example/v1/chat');
check('privacy: a custom model server is journaled by its host', in_array('llm.acme.example', array_column($pr->journal()['today'], 'name'), true));

// a confidential space with a European model
$a->space = (int)$conf['id'];
$c->provider = 'sovereign'; $c->openaiBaseUrl = 'https://api.mistral.ai/v1';
Privacy::begin();
Privacy::doc('Budget.xlsx', 300); Privacy::doc('Budget.xlsx', 200);
check('confidential: the European model, its indexing and transcription get the content; others do not',
    !$isBlocked(fn() => $out('https://api.mistral.ai/v1/chat')) && !$isBlocked(fn() => $out('https://api.mistral.ai/v1/embeddings', 'embeddings'))
    && $isBlocked(fn() => $out('https://api.openai.com/v1/embeddings', 'embeddings')) && $isBlocked(fn() => $out('https://other.acme.example/', 'web')));
$tr = Privacy::end();
check('privacy: a document read twice is counted once, with all its characters', $tr['docs'] === [['name' => 'Budget.xlsx', 'chars' => 500]] && count($tr['blocked']) === 2);
$c->provider = 'anthropic'; $c->anthropicBaseUrl = '';
check('privacy: the Claude API by default', $pr->modelService() === 'anthropic' && $pr->modelGrade() === 'business');
$c->provider = 'echo';
check('privacy page: a model on this server', $pr->flows()[0]['status'] === 'local' && $pr->flows()[0]['name'] === 'Model on this server');

// tools
check('privacy: an approved action needs no gate', Privacy::approved(fn() => $pr->toolGate('web_search', ['query' => 'x'])) === null);
check('privacy: confidential: browser, connectors and scripts with internet wait for approval',
    str_contains((string)$pr->toolGate('browser_open', ['url' => 'https://acme.example']), 'Open the web page https://acme.example')
    && str_contains((string)$pr->toolGate('mcp_acme_crm_find', []), 'Use the connector tool mcp_acme_crm_find')
    && str_contains((string)$pr->toolGate('browser_click', []), 'Use the web browser (click)')
    && $pr->toolGate('script_run', ['name' => 'report']) === null);
$c->shellNetwork = true;
check('privacy: a script with internet is an outside tool', str_contains((string)$pr->toolGate('script_run', ['name' => 'report']), 'Run the script "report" in the sandbox with internet'));
$c->shellNetwork = false;
App::$inWorker = true;
check('privacy: confidential work in the background cannot wait for an approval', str_contains((string)$pr->toolGate('web_search', ['query' => 'x']), "can't be awaited here"));
App::$inWorker = false;
$a->space = 0;

// the privacy page, for a range of configurations
$flows = fn() => array_column($pr->flows(), null, 'id');
$c->provider = 'sovereign'; $c->openaiBaseUrl = 'https://api.mistral.ai/v1'; $c->sovereign = 'mistral';
$c->transcribe = 'sovereign'; $c->embeddings = 'sovereign'; $c->search = 'searxng';
$c->notify = ['telegram', 'ntfy', 'email', 'signal', 'webpush']; $c->telegramToken = '1:abc'; $c->telegramAllowed = [42]; $c->notifyNeutral = false;
$c->smtpHost = 'smtp.acme.example'; $c->emailFrom = 'alex@acme.example'; $c->signalNumber = '+33600000000'; $c->signalAllowed = ['+33600000001'];
$a->webPush()->subscribe('https://fcm.googleapis.com/fcm/send/abc', 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', 'test');
$c->googleClientId = 'gid'; $c->googleClientSecret = 'gsecret'; $c->microsoftClientId = 'mid'; $c->microsoftClientSecret = 'msecret';
file_put_contents("$d/pr/google.json", '{"refresh_token":"r"}'); file_put_contents("$d/pr/microsoft.json", '{"refresh_token":"r"}');
$fs = $a->files()->save(tap_tmp('hello Alex'), 'note.txt', 'text/plain', '/Shared');
$a->shares()->create(['file' => $fs], ['password' => 'abcd']);
$f = $flows();
check('privacy page: a European model, transcription and indexing', $f['model']['status'] === 'good' && str_contains($f['model']['why'], 'European provider') && $f['transcription']['status'] === 'good' && $f['embeddings']['status'] === 'good', json_encode($f['model']));
check('privacy page: notifications with content, each channel', $f['telegram']['status'] === 'bad' && str_contains($f['telegram']['what'], 'conversations you have there') && $f['ntfy']['status'] === 'good'
    && $f['email']['status'] === 'warn' && $f['signal']['status'] === 'good' && $f['push']['status'] === 'good' && $f['search']['name'] === 'Web search (SearXNG)');
check('privacy page: connectors, calendars, password-protected links', $f['mcp:acme_crm']['why'] !== $f['mcp:local_tool']['why'] && isset($f['google'], $f['microsoft']) && $f['shares']['status'] === 'good');
$ov = $pr->overview();
check('privacy page: everything in Europe or here', $ov['model_grade'] === 'sovereign' && $ov['level'] === 'personal' && $ov['european'] === false);
check('privacy page: the record lists the strict spaces', str_contains($pr->register(), 'Vault: Secret') && str_contains($pr->register(), 'Dossier Acme: Confidential'));

$c->notifyNeutral = true; $c->ntfyUrl = 'https://ntfy.sh/acme'; $c->telegramAllowed = [];
$c->provider = 'gemini'; $c->openaiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai'; $c->geminiPaid = false; $c->transcribe = 'gemini'; $c->embeddings = 'gemini'; $c->search = 'gemini';
$a->shares()->create(['file' => $fs], []);
$f = $flows();
check('privacy page: the free Gemini tier everywhere', $f['model']['status'] === 'bad' && $f['transcription']['status'] === 'bad' && $f['embeddings']['status'] === 'bad' && $f['search']['status'] === 'warn');
check('privacy page: neutral notifications, public ntfy, an open link', $f['telegram']['status'] === 'warn' && $f['telegram']['fix'] === [] && $f['ntfy']['status'] === 'good' && $f['email']['status'] === 'good'
    && $f['shares']['status'] === 'warn' && str_contains($f['shares']['why'], '1 of them without a password'));
$c->geminiPaid = true;
$f = $flows();
check('privacy page: a paid Gemini plan', $f['model']['status'] === 'good' && $f['transcription']['status'] === 'good' && $f['embeddings']['status'] === 'good' && $f['search']['status'] === 'good');
$c->provider = 'claude-code'; $c->transcribe = 'openai'; $c->embeddings = 'voyage'; $c->search = 'brave'; $c->notifyNeutral = false;
$f = $flows();
check('privacy page: a personal subscription, OpenAI transcription, Voyage indexing, Brave search, public ntfy with content',
    $f['model']['status'] === 'bad' && $f['transcription']['name'] === 'Transcription (OpenAI)' && $f['embeddings']['name'] === 'Search by meaning (Voyage AI)'
    && $f['search']['name'] === 'Web search (Brave)' && $f['ntfy']['status'] === 'bad');
$c->provider = 'openai'; $c->openaiBaseUrl = 'https://llm.acme.example/v1'; $c->transcribe = 'local'; $c->embeddings = 'ollama'; $c->search = 'off'; $c->notify = [];
$f = $flows();
check('privacy page: an unknown model server, local transcription and indexing, no search',
    $f['model']['status'] === 'warn' && $f['model']['name'] === 'llm.acme.example' && $f['transcription']['status'] === 'local' && $f['embeddings']['status'] === 'local' && !isset($f['search']));
$c->embeddings = 'openai'; $c->search = 'tavily';
$f = $flows();
check('privacy page: OpenAI indexing and Tavily search', $f['embeddings']['name'] === 'Search by meaning (OpenAI)' && $f['search']['name'] === 'Web search (Tavily)');

[Privacy::$taint, Privacy::$trace, Privacy::$onEvent, Privacy::$allowOnce, Privacy::$purpose, App::$inWorker, Privacy::$untrusted, Privacy::$privateRead, Privacy::$links] = $savedPrivacy;


// ---------------------------------------------------------------- Files ----

putenv("TALIXIO_DATA_DIR=$d/fl");
$fa = App::fromEnv();
$fc = $fa->cfg;
$files = $fa->files();
$fake = new FakeModel();
$fake->script = fn() => 'A page that reads: Invoice 42 from Acme to Alex.';
$fa->providerOverride = $fake;
$scanPdf = function (): string { // one page holding only a picture: a scan, no text layer
    $im = imagecreatetruecolor(200, 100); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagestring($im, 5, 10, 40, 'Invoice 42', imagecolorallocate($im, 0, 0, 0));
    ob_start(); imagejpeg($im, null, 90); $jpg = (string)ob_get_clean();
    $content = "q 200 0 0 100 0 0 cm /Im0 Do Q";
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
        "<< /Type /XObject /Subtype /Image /Width 200 /Height 100 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpg) . " >>\nstream\n$jpg\nendstream",
        "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream"];
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
};
$scan = $scanPdf();

$noName = $files->save(tap_tmp('plain words'), 'folder/', '', '/Inbox');
check('files: a name that cleans to nothing becomes "file", its type is guessed', ($files->get($noName)['name'] ?? '') === 'file' && $files->get($noName)['mime'] === 'text/plain');
$brokenDocx = $files->save(tap_tmp('not a zip'), 'Contrat.docx', 'application/octet-stream', '/Inbox');
check('files: a file that cannot be read is marked failed and says why', (function () use ($files, $brokenDocx) { try { $files->text($brokenDocx); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'not a valid Office'); } })()
    && $files->get($brokenDocx)['status'] === 'failed' && str_starts_with((string)$files->get($brokenDocx)['text'], '(could not read this file: not a valid Office'));
$bin = $files->save(tap_tmp("\x00\x01\x02binary"), 'blob.bin', 'application/x-thing', '/Inbox');
check('files: other files have no text', $files->get($bin)['kind'] === 'other' && $files->text($bin) === '' && $files->parts('', [$bin])[0]['text'] === "[Attached document #$bin: blob.bin]\n(binary file, cannot be read)");

// scans: read by the model when image reading is on
$fc->provider = 'anthropic'; $fc->visionIndex = true;
$scanId = $files->save(tap_tmp($scan), 'scan.pdf', 'application/pdf', '/Inbox');
$scanText = $files->text($scanId);
check('files: a scanned PDF is read page by page by the model', $scanText === 'A page that reads: Invoice 42 from Acme to Alex.' && count($fake->calls) === 1
    && ($fake->calls[0]['messages'][0]['content'][1]['mime'] ?? '') === 'image/jpeg', $scanText);
$fake->script = fn() => '   ';
$blank = $files->save(tap_tmp($scan), 'blank.pdf', 'application/pdf', '/Inbox');
check('files: a scan the model reads nothing in says so, and is not indexed', str_starts_with($files->text($blank), '(No text found in this PDF')
    && (int)$fa->store->q("SELECT COUNT(*) FROM docs WHERE kind = 'file' AND ref = ?", [$blank])->fetchColumn() === 0);
$fake->script = function () { throw new \RuntimeException('model down'); };
$down = $files->save(tap_tmp($scan), 'down.pdf', 'application/pdf', '/Inbox');
check('files: a model error while reading a scan is not fatal', str_starts_with($files->text($down), '(No text found'));
$fake->script = function () { throw new \Talixio\Abort('stopped'); };
$stopped = $files->save(tap_tmp($scan), 'stopped.pdf', 'application/pdf', '/Inbox');
check('files: stopping the work stops the reading', (function () use ($files, $stopped) { try { $files->text($stopped); return false; } catch (\Talixio\Abort) { return true; } })());
$fc->visionIndex = false;
$off = $files->save(tap_tmp($scan), 'off.pdf', 'application/pdf', '/Inbox');
check('files: with image reading off a scan has no text', str_contains($files->text($off), 'image reading is off'));
$fc->provider = 'echo';

// text of each kind
$fc->provider = 'anthropic'; $fc->visionIndex = true;
$fake->script = fn() => 'A receipt from Acme.';
$imgRead = $files->save(tap_tmp($png), 'receipt.png', 'image/png', '/Inbox');
check('files: images are described by the model', $files->text($imgRead) === "Image description:\nA receipt from Acme." && $files->imagePart($imgRead)['mime'] === 'image/png');
$gif = $files->save(tap_tmp($png), 'odd.bmp', 'image/bmp', '/Inbox');
check('files: images in other formats are sent as JPEG', $files->imagePart($gif)['mime'] === 'image/jpeg');
$fc->provider = 'echo';
check('files: no description without a model', $files->text($files->save(tap_tmp($png), 'b.png', 'image/png', '/Inbox')) === '');
$ofText = fn(string $zipPath, string $name) => $files->text($files->save(tap_tmp((string)file_get_contents($zipPath)), $name, '', '/Inbox'));
check('files: text of Word, Excel, PowerPoint and OpenDocument files', str_contains($ofText($docx, 'a.docx'), 'Styled heading') && str_contains($ofText($xlsx, 'b.xlsx'), "--- Sheet 1\nPoste\tMontant")
    && str_contains($ofText($pptx, 'c.pptx'), "--- Slide 1\n") && str_contains($ofText($odt, 'd.odt'), 'No level'));
check('files: text of a web page and of an email', str_contains($files->text($files->save(tap_tmp('<html><body><h1>Hello</h1><p>Alex</p></body></html>'), 'p.html', 'text/html', '/Inbox')), 'Alex')
    && str_starts_with($files->text($files->save(tap_tmp("From: Alex <alex@acme.example>\r\nTo: b@acme.example\r\nSubject: Hi\r\n\r\nBody text"), 'm.eml', 'message/rfc822', '/Inbox')), "From: Alex <alex@acme.example>\nTo: b@acme.example\nDate: \nSubject: Hi\n\nBody text"));
$rep = $files->save(tap_tmp('first version'), 'r.txt', 'text/plain', '/Inbox');
$files->text($rep);
$after = $files->replace($rep, tap_tmp('second version'));
check('files: replaced content is read again', $after['status'] === 'pending' && $files->text($rep) === 'second version');

check('files: page marks only for text with pages', Files::withPageMarks('one page') === 'one page' && Files::withPageMarks("a\fb\f") === "[page 1]\na\n\n[page 2]\nb");
$docsBefore = (int)$fa->store->q('SELECT COUNT(*) FROM docs')->fetchColumn();
$files->index(999999);
check('files: indexing a file that is gone does nothing', (int)$fa->store->q('SELECT COUNT(*) FROM docs')->fetchColumn() === $docsBefore);

// thumbnails and pages
check('files: no thumbnail for a missing file or a file without pages', $files->thumb(999999) === null && $files->thumb($bin) === null && $files->pdfPageCount($bin) === 0 && $files->pdfPageCount(999999) === 0);
$t1 = $files->thumb($scanId);
check('files: a PDF thumbnail from its first page, cached', $t1 !== null && is_file($t1) && max(getimagesize($t1)[0], getimagesize($t1)[1]) <= 160 && $files->thumb($scanId) === $t1);
$imgFile = $files->save(tap_tmp($png), 'dot.png', 'image/png', '/Inbox');
check('files: an image thumbnail', $files->thumb($imgFile) !== null);
$fakeImg = $files->save(tap_tmp('not an image'), 'fake.png', 'image/png', '/Inbox');
check('files: an image that cannot be decoded has no thumbnail', $files->thumb($fakeImg) === null);
check('files: a PDF has its page count; pages out of range cannot be rendered', $files->pdfPageCount($scanId) === 1
    && (function () use ($files, $scanId) { try { $files->pdfPage($scanId, 5); return false; } catch (\RuntimeException $e) { return $e->getMessage() === 'could not render this page'; } })()
    && (function () use ($files, $bin) { try { $files->pdfPage($bin, 1); return false; } catch (\InvalidArgumentException) { return true; } })());
$brokenPdf = $files->save(tap_tmp('%PDF-1.4 broken'), 'broken.pdf', 'application/pdf', '/Inbox');
check('files: a broken PDF has no thumbnail', $files->thumb($brokenPdf) === null);
if (Files::officeBinary()) {
    $rtf = $files->save(tap_tmp('{\rtf1\ansi Bonjour Alex from Acme\par}'), 'lettre.rtf', 'application/rtf', '/Inbox');
    check('files: older Office formats are read through LibreOffice', str_contains($files->text($rtf), 'Bonjour Alex from Acme') && $files->pdfPageCount($rtf) === 1);
    $gone = $files->save(tap_tmp('x'), 'gone.doc', 'application/msword', '/Inbox');
    $fa->store->q('UPDATE files SET path = ? WHERE id = ?', ["$d/fl/nowhere.doc", $gone]);
    check('files: an Office file that cannot be converted has no pages', @$files->pdfPageCount($gone) === 0);
}

// zips
$fa->library()->ensureFolder('/Projects/Empty');
$z1 = $files->save(tap_tmp('one'), 'plan.txt', 'text/plain', '/Projects/A');
$z2 = $files->save(tap_tmp('two'), 'Plan2.txt', 'text/plain', '/Projects/A');
$fa->store->q("UPDATE files SET name = 'Plan.txt' WHERE id = ?", [$z2]); // a clash from before names were unique in a folder
$z3 = $files->save(tap_tmp('three'), 'lost.txt', 'text/plain', '/Projects/A');
$fa->store->q('UPDATE files SET path = ? WHERE id = ?', ["$d/fl/lost-file", $z3]);
$zipPath = $files->zipFolder('/Projects');
$zr = new ZipArchive(); $zr->open($zipPath); $names = []; for ($i = 0; $i < $zr->numFiles; $i++) $names[] = $zr->getNameIndex($i); $zr->close(); @unlink($zipPath);
sort($names);
check('files: a zip keeps the tree, renames clashing names and skips missing files', $names === ['A/Plan.txt', "A/plan ($z1).txt"] || $names === ["A/Plan ($z2).txt", 'A/plan.txt'], json_encode($names));
$emptyZip = $files->zipFolder('/Projects/Empty');
$zr = new ZipArchive(); $zr->open($emptyZip); $en = $zr->getNameIndex(0); $zr->close(); @unlink($emptyZip);
check('files: an empty folder downloads as an empty folder', $en === 'Empty/');
$fa->store->q('UPDATE files SET size = 5000000000 WHERE id = ?', [$z1]);
check('files: a folder over 4 GB is not zipped', (function () use ($files) { try { $files->zipFolder('/Projects'); return false; } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), 'over 4 GB'); } })());

// attachments
$long = $files->save(tap_tmp(str_repeat('word ', 9000)), 'long.txt', 'text/plain', '/Inbox');
$pp = $files->parts('', [999999, $long]);
check('files: attachments: no text part, missing files skipped, long ones truncated', count($pp) === 1 && str_contains($pp[0]['text'], 'truncated; use file_read with id ' . $long));
$audio = $files->save(tap_tmp('fake audio'), 'memo.mp3', 'audio/mpeg', '/Inbox');
$fa->store->q('UPDATE files SET duration = 75 WHERE id = ?', [$audio]);
$fa->store->q('UPDATE files SET duration = 3725 WHERE id = ?', [$long]);
check('files: references show durations', $files->reference([$audio, $long, 999999]) === "[attached audio #$audio: memo.mp3, 01:15]\n[attached text #$long: long.txt, 1:02:05]");

// the background indexer
$fa->store->q("UPDATE files SET status = 'processing' WHERE id = ?", [$long]);
$files->recoverInterrupted();
check('files: files left half-read go back to the queue', $files->get($long)['status'] === 'pending');
$fc->autoFile = 'off';
check('files: no time, nothing read', $files->processPending(0) === 0 && $files->get($long)['status'] === 'pending');
$fa->store->q("UPDATE files SET status = 'pending' WHERE id = ?", [$brokenDocx]);
$fa->store->q("UPDATE files SET status = 'ready' WHERE status = 'pending' AND id NOT IN (?, ?)", [$long, $brokenDocx]);
$fake->script = fn() => '{"folder": "/Inbox", "name": "long.txt", "why": "fine"}';
$fc->autoFile = 'suggest';
$n = $files->processPending(30);
check('files: waiting files are read (failures logged) and then filed', $n === 2 && $files->get($long)['status'] === 'ready' && $files->get($brokenDocx)['status'] === 'failed'
    && (int)$fa->store->q('SELECT COUNT(*) FROM files WHERE suggestion IS NOT NULL')->fetchColumn() > 0);
$fc->autoFile = 'off';
$fa->store->q("UPDATE files SET status = 'ready' WHERE status = 'pending'");
$files->startIndexer();
$pending = $files->save(tap_tmp('queued words'), 'queued.txt', 'text/plain', '/Inbox');
$files->startIndexer();
$files->startIndexer(); // still running (or just finished): no second one
$until = microtime(true) + 20;
while ($files->get($pending)['status'] === 'pending' && microtime(true) < $until) usleep(200_000);
check('files: the worker starts one background indexer when files wait', $files->get($pending)['status'] === 'ready' && $files->get($pending)['text'] === 'queued words');
$ix = new \ReflectionProperty(Files::class, 'indexer');
$until = microtime(true) + 20;
while (is_resource($ix->getValue()) && proc_get_status($ix->getValue())['running'] && microtime(true) < $until) usleep(200_000);
$files->startIndexer(); // the finished one is closed, and with nothing waiting no new one starts
check('files: a finished indexer is collected', $ix->getValue() === null);

foreach ($envBefore as [$k, $v]) putenv($v === false ? $k : "$k=$v");

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
