<?php

/*
 +--------------------------------------------------------------------------+
 | Zephir Language                                                          |
 +--------------------------------------------------------------------------+
 | Copyright (c) 2013-2014 Zephir Team and contributors                     |
 +--------------------------------------------------------------------------+
 | This source file is subject the MIT license, that is bundled with        |
 | this package in the file LICENSE, and is available through the           |
 | world-wide-web at the following url:                                     |
 | http://zephir-lang.com/license.html                                      |
 |                                                                          |
 | If you did not receive a copy of the MIT license and are unable          |
 | to obtain it through the world-wide-web, please send a note to           |
 | license@zephir-lang.com so we can mail you a copy immediately.           |
 +--------------------------------------------------------------------------+
*/

/**
 * Require
 *
 * Includes a plain PHP file
 */
class RequireOperator extends BaseOperator
{

	/**
	 *
	 * @param array $expression
	 * @param \CompilationContext $compilationContext
	 * @return \CompiledExpression
	 */
	public function compile($expression, CompilationContext $compilationContext)
	{

		$expr = new Expression($expression['left']);
		$expr->setReadOnly(true);
		$expr->setExpectReturn(true);

		$exprPath = $expr->compile($compilationContext);
		if ($exprPath->getType() == 'variable') {

			$exprVariable = $compilationContext->symbolTable->getVariableForRead($exprPath->getCode(), $compilationContext, $expression);
			if ($exprVariable->getType() == 'variable') {
				if ($exprVariable->hasDifferentDynamicType(array('undefined', 'string'))) {
					$compilationContext->logger->warning('Possible attempt to use invalid type as path in "require" operator', 'non-valid-require', $expression);
				}
			}

		}

		$symbolVariable = $this->getExpected($compilationContext, $expression);
		if ($symbolVariable) {
			if ($symbolVariable->getType() != 'variable') {
				throw new CompilerException("Objects can only be cloned into dynamic variables", $expression);
			}
		}

		$compilationContext->headersManager->add('kernel/require');

		$codePrinter = $compilationContext->codePrinter;

		if ($symbolVariable) {
			$codePrinter->output('if (zephir_require_ret(' . $symbolVariable->getName() . ', ' . $exprPath->getCode() . ' TSRMLS_CC) == FAILURE) {');
		} else {
			$codePrinter->output('if (zephir_require(' . $exprPath->getCode() . ' TSRMLS_CC) == FAILURE) {');
		}
		$codePrinter->output("\t" . 'RETURN_MM_NULL();');
		$codePrinter->output('}');

		if ($symbolVariable) {
			return new CompiledExpression('variable', $symbolVariable->getName(), $expression);
		}

		return new CompiledExpression('null', null, $expression);
	}

}
