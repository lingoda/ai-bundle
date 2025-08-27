<?php

declare(strict_types = 1);

use PhpCsFixer\Fixer\Alias\MbStrFunctionsFixer;
use PhpCsFixer\Fixer\Alias\NoAliasFunctionsFixer;
use PhpCsFixer\Fixer\ArrayNotation\NormalizeIndexBraceFixer;
use PhpCsFixer\Fixer\CastNotation\ShortScalarCastFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\Comment\CommentToPhpdocFixer;
use PhpCsFixer\Fixer\Comment\MultilineCommentOpeningClosingFixer;
use PhpCsFixer\Fixer\ControlStructure\NoSuperfluousElseifFixer;
use PhpCsFixer\Fixer\ControlStructure\NoUselessElseFixer;
use PhpCsFixer\Fixer\ControlStructure\YodaStyleFixer;
use PhpCsFixer\Fixer\FunctionNotation\ImplodeCallFixer;
use PhpCsFixer\Fixer\FunctionNotation\MethodArgumentSpaceFixer;
use PhpCsFixer\Fixer\FunctionNotation\NullableTypeDeclarationForDefaultNullValueFixer;
use PhpCsFixer\Fixer\FunctionNotation\UseArrowFunctionsFixer;
use PhpCsFixer\Fixer\LanguageConstruct\CombineConsecutiveIssetsFixer;
use PhpCsFixer\Fixer\LanguageConstruct\CombineConsecutiveUnsetsFixer;
use PhpCsFixer\Fixer\LanguageConstruct\DeclareEqualNormalizeFixer;
use PhpCsFixer\Fixer\ListNotation\ListSyntaxFixer;
use PhpCsFixer\Fixer\Operator\AssignNullCoalescingToCoalesceEqualFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\AlignMultilineCommentFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocAlignFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocNoEmptyReturnFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocOrderFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocSummaryFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocToCommentFixer;
use PhpCsFixer\Fixer\Semicolon\MultilineWhitespaceBeforeSemicolonsFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Strict\StrictComparisonFixer;
use PhpCsFixer\Fixer\Strict\StrictParamFixer;
use PhpCsFixer\Fixer\Whitespace\ArrayIndentationFixer;
use Symplify\CodingStandard\Fixer\Spacing\StandaloneLinePromotedPropertyFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->sets([
        SetList::PSR_12,
        SetList::CLEAN_CODE,
    ]);

    $ecsConfig->rule(AlignMultilineCommentFixer::class);
    $ecsConfig->rule(ArrayIndentationFixer::class);
    $ecsConfig->rule(CombineConsecutiveIssetsFixer::class);
    $ecsConfig->rule(CombineConsecutiveUnsetsFixer::class);
    $ecsConfig->ruleWithConfiguration(ConcatSpaceFixer::class, [
        'spacing' => 'one',
    ]);
    $ecsConfig->ruleWithConfiguration(DeclareEqualNormalizeFixer::class, [
        'space' => 'single',
    ]);
    $ecsConfig->ruleWithConfiguration(ListSyntaxFixer::class, [
        'syntax' => 'short',
    ]);
    $ecsConfig->rule(MbStrFunctionsFixer::class);
    $ecsConfig->ruleWithConfiguration(MethodArgumentSpaceFixer::class, [
        'after_heredoc' => false,
        'keep_multiple_spaces_after_comma' => false,
        'on_multiline' => 'ensure_fully_multiline',
    ]);
    $ecsConfig->rule(MultilineCommentOpeningClosingFixer::class);
    $ecsConfig->ruleWithConfiguration(MultilineWhitespaceBeforeSemicolonsFixer::class, [
        'strategy' => 'new_line_for_chained_calls',
    ]);
    $ecsConfig->rule(NoSuperfluousElseifFixer::class);
    $ecsConfig->rule(NoUselessElseFixer::class);
    $ecsConfig->rule(NullableTypeDeclarationForDefaultNullValueFixer::class);
    $ecsConfig->ruleWithConfiguration(OrderedClassElementsFixer::class, [
        'order' => [
            'use_trait',
            'constant_public',
            'constant_protected',
            'constant_private',
            'property_public',
            'property_protected',
            'property_private',
        ],
        'sort_algorithm' => 'none',
    ]);
    $ecsConfig->rule(PhpdocNoEmptyReturnFixer::class);
    $ecsConfig->rule(PhpdocOrderFixer::class);
    $ecsConfig->rule(CommentToPhpdocFixer::class);
    $ecsConfig->rule(StrictComparisonFixer::class);
    $ecsConfig->rule(StandaloneLinePromotedPropertyFixer::class);
    $ecsConfig->rule(StrictParamFixer::class);
    $ecsConfig->rule(AssignNullCoalescingToCoalesceEqualFixer::class);
    $ecsConfig->rule(ShortScalarCastFixer::class);
    $ecsConfig->rule(NormalizeIndexBraceFixer::class);
    $ecsConfig->rule(ImplodeCallFixer::class);
    $ecsConfig->rule(NoAliasFunctionsFixer::class);
    $ecsConfig->rule(UseArrowFunctionsFixer::class);
    $ecsConfig->rule(DeclareStrictTypesFixer::class);

    $ecsConfig->skip([
        PhpdocAlignFixer::class,
        PhpdocSummaryFixer::class,
        PhpdocToCommentFixer::class,
        YodaStyleFixer::class,
    ]);
};