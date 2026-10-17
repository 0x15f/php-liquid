<?php

use Keepsuit\Liquid\Compiler\CodeBuilder;

test('generated blocks preserve statement boundaries and intentional blank lines', function (string $source, bool $skipEmptyLines, string $expected) {
    $builder = new CodeBuilder;
    $builder->writeLine('before;')->indent()->writeLines($source, $skipEmptyLines)->dedent()->writeLine('after;');

    expect($builder->getSource())->toBe("before;\n".$expected."after;\n");
})->with([
    'plain block' => ["alpha;\nbeta;", false, "    alpha;\n    beta;\n"],
    'blank lines retained' => ["\nalpha;\n\nbeta;\n\n", false, "\n    alpha;\n\n    beta;\n"],
    'blank lines removed' => ["\nalpha;\n\nbeta;\n\n", true, "    alpha;\n    beta;\n"],
    'whitespace line retained' => ["alpha;\n  \nbeta;", true, "    alpha;\n      \n    beta;\n"],
    'empty block removed' => ["\n\n", true, ''],
]);
