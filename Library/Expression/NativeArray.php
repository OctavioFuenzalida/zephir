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
 * NativeArray
 *
 * Resolves expressions that create arrays
 */
class NativeArray
{
	protected $_expecting = true;

	protected $_readOnly = false;

	protected $_expectingVariable;

	/**
	 * Sets if the variable must be resolved into a direct variable symbol
	 * create a temporary value or ignore the return value
	 *
	 * @param boolean $expecting
	 * @param Variable $expectingVariable
	 */
	public function setExpectReturn($expecting, Variable $expectingVariable=null)
	{
		$this->_expecting = $expecting;
		$this->_expectingVariable = $expectingVariable;
	}

	/**
	 * Sets if the result of the evaluated expression is read only
	 *
	 * @param boolean $readOnly
	 */
	public function setReadOnly($readOnly)
	{
		$this->_readOnly = $readOnly;
	}

	/**
	 * Resolves an item to be added in an array
	 *
	 * @param \CompiledExpression $exprCompiled
	 * @param \CompilationContext $compilationContext
	 * @return \Variable
	 */
	public function getArrayValue($exprCompiled, CompilationContext $compilationContext)
	{
		$codePrinter = $compilationContext->codePrinter;

		switch ($exprCompiled->getType()) {

			case 'int':
			case 'uint':
			case 'long':
				$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
				$codePrinter->output('ZVAL_LONG(' . $tempVar->getName() . ', ' . $exprCompiled->getCode() . ');');
				return $tempVar;

			case 'char':
			case 'uchar':
				$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
				$codePrinter->output('ZVAL_LONG(' . $tempVar->getName() . ', \'' . $exprCompiled->getCode() . '\');');
				return $tempVar;

			case 'double':
				$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
				$codePrinter->output('ZVAL_DOUBLE(' . $tempVar->getName() . ', ' . $exprCompiled->getCode() . ');');
				return $tempVar;

			case 'bool':
				if ($exprCompiled->getCode() == 'true') {
					return new GlobalConstant('ZEPHIR_GLOBAL(global_true)');
				} else {
					return new GlobalConstant('ZEPHIR_GLOBAL(global_false)');
				}

			case 'null':
				return new GlobalConstant('ZEPHIR_GLOBAL(global_null)');

			case 'string':
			case 'ulong':
				$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
				$codePrinter->output('ZVAL_STRING(' . $tempVar->getName() . ', "' . $exprCompiled->getCode() . '", 1);');
				return $tempVar;

			case 'array':
				return $compilationContext->symbolTable->getVariableForRead($exprCompiled->getCode(), $compilationContext, $exprCompiled->getOriginal());

			case 'variable':
				$itemVariable = $compilationContext->symbolTable->getVariableForRead($exprCompiled->getCode(), $compilationContext, $exprCompiled->getOriginal());
				switch ($itemVariable->getType()) {

					case 'int':
					case 'uint':
					case 'long':
					case 'ulong':
						$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
						$codePrinter->output('ZVAL_LONG(' . $tempVar->getName() . ', ' . $itemVariable->getName() . ');');
						return $tempVar;

					case 'double':
						$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
						$codePrinter->output('ZVAL_DOUBLE(' . $tempVar->getName() . ', ' . $itemVariable->getName() . ');');
						return $tempVar;

					case 'bool':
						$tempVar = $compilationContext->symbolTable->getTempVariableForWrite('variable', $compilationContext);
						$codePrinter->output('ZVAL_BOOL(' . $tempVar->getName() . ', ' . $itemVariable->getName() . ');');
						return $tempVar;

					case 'string':
					case 'variable':
					case 'array':
						return $itemVariable;

					default:
						throw new CompilerException("Unknown " . $itemVariable->getType(), $itemVariable);
				}
				break;

			default:
				throw new CompilerException("Unknown", $exprCompiled);
		}
	}

	/**
	 * Compiles an array initialization
	 *
	 * @param array $expression
	 * @param \CompilationContext $compilationContext
	 * @return \CompiledExpression
	 */
	public function compile($expression, CompilationContext $compilationContext)
	{

		/**
		 * Resolves the symbol that expects the value
		 */
		if ($this->_expecting) {
			if ($this->_expectingVariable) {
				$symbolVariable = $this->_expectingVariable;
				$symbolVariable->initVariant($compilationContext);
				if ($symbolVariable->getType() != 'variable' && $symbolVariable->getType() != 'array') {
					throw new CompilerException("Cannot use variable type: " . $symbolVariable->getType() . " as an array", $expression);
				}
			} else {
				$symbolVariable = $compilationContext->symbolTable->getTempVariableForWrite('array', $compilationContext, $expression);
			}
		} else {
			$symbolVariable = $compilationContext->symbolTable->getTempVariableForWrite('array', $compilationContext, $expression);
		}

		/*+
		 * Mark the variable as an array
		 */
		$symbolVariable->setDynamicTypes('array');

		$codePrinter = $compilationContext->codePrinter;

		/**
		 * This calculates a prime number bigger than the current array size to possibly
		 * reduce hash collisions when adding new members to the array
		 */
		$arrayLength = intval(count($expression['left']) * 1.25);
		if (!function_exists('gmp_nextprime')) {
			$codePrinter->output('array_init_size(' . $symbolVariable->getName() . ', ' . ($arrayLength + 1) . ');');
		} else {
			$codePrinter->output('array_init_size(' . $symbolVariable->getName() . ', ' . gmp_strval(gmp_nextprime($arrayLength)) . ');');
		}

		foreach ($expression['left'] as $item) {
			if (isset($item['key'])) {
				$key = null;
				switch ($item['key']['type']) {
					case 'string':
						$expr = new Expression($item['value']);
						$resolvedExpr = $expr->compile($compilationContext);
						switch ($resolvedExpr->getType()) {

							case 'int':
							case 'uint':
							case 'long':
							case 'ulong':
								$codePrinter->output('add_assoc_long_ex(' . $symbolVariable->getName() . ', SS("' . $item['key']['value'] . '"), ' . $resolvedExpr->getCode() . ');');
								break;

							case 'double':
								$codePrinter->output('add_assoc_double_ex(' . $symbolVariable->getName() . ', SS("' . $item['key']['value'] . '"), ' . $resolvedExpr->getCode() . ');');
								break;

							case 'bool':
								$compilationContext->headersManager->add('kernel/array');
								if ($resolvedExpr->getCode() == 'true') {
									$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &ZEPHIR_GLOBAL(global_true), PH_COPY | PH_SEPARATE);');
								} else {
									$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &ZEPHIR_GLOBAL(global_false), PH_COPY | PH_SEPARATE);');
								}
								break;

							case 'string':
								$codePrinter->output('add_assoc_stringl_ex(' . $symbolVariable->getName() . ', SS("' . $item['key']['value'] . '"), SL("' . $resolvedExpr->getCode() . '"), 1);');
								break;

							case 'null':
								$compilationContext->headersManager->add('kernel/array');
								$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &ZEPHIR_GLOBAL(global_null), PH_COPY | PH_SEPARATE);');
								break;

							case 'array':
								$compilationContext->headersManager->add('kernel/array');
								$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
								$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &' . $valueVariable->getName() . ', PH_COPY | PH_SEPARATE);');
								if ($valueVariable->isTemporal()) {
									$valueVariable->setIdle(true);
								}
								break;

							case 'variable':
								$compilationContext->headersManager->add('kernel/array');
								$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
								$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &' . $valueVariable->getName() . ', PH_COPY | PH_SEPARATE);');
								if ($valueVariable->isTemporal()) {
									$valueVariable->setIdle(true);
								}
								break;

							default:
								throw new CompilerException("Invalid value type: " . $resolvedExpr->getType(), $item['value']);
						}
						break;

					case 'int':
					case 'uint':
					case 'long':
					case 'ulong':
						$expr = new Expression($item['value']);
						$resolvedExpr = $expr->compile($compilationContext);
						switch ($resolvedExpr->getType()) {

							case 'int':
							case 'uint':
							case 'long':
							case 'ulong':
								$codePrinter->output('add_index_long(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getCode() . ');');
								break;

							case 'bool':
								$compilationContext->headersManager->add('kernel/array');
								$codePrinter->output('add_index_bool(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getBooleanCode() . ');');
								if ($resolvedExpr->getCode() == 'true') {
									$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &ZEPHIR_GLOBAL(global_true), PH_COPY, "' . Compiler::getShortUserPath($expression['file']) . '", ' . $expression['line'] . ');');
								} else {
									$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &ZEPHIR_GLOBAL(global_false), PH_COPY, "' . Compiler::getShortUserPath($expression['file']) . '", ' . $expression['line'] . ');');
								}
								break;

							case 'double':
								$codePrinter->output('add_index_double(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getCode() . ');');
								break;

							case 'null':
								$compilationContext->headersManager->add('kernel/array');
								$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &ZEPHIR_GLOBAL(global_null), PH_COPY, "' . Compiler::getShortUserPath($expression['file']) . '", ' . $expression['line'] . ');');
								break;

							case 'string':
								$codePrinter->output('add_index_stringl(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', SL("' . $resolvedExpr->getCode() . '"), 1);');
								break;

							case 'array':
								$compilationContext->headersManager->add('kernel/array');
								$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
								$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &' . $valueVariable->getName() . ', PH_COPY, "' . Compiler::getShortUserPath($expression['file']) . '", ' . $expression['line'] . ');');
								if ($valueVariable->isTemporal()) {
									$valueVariable->setIdle(true);
								}
								break;

							case 'variable':
								$compilationContext->headersManager->add('kernel/array');
								$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
								$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &' . $valueVariable->getName() . ', PH_COPY, "' . Compiler::getShortUserPath($expression['file']) . '", ' . $expression['line'] . ');');
								if ($valueVariable->isTemporal()) {
									$valueVariable->setIdle(true);
								}
								break;

							default:
								throw new CompilerException("Invalid value type: " . $item['value']['type'], $item['value']);
						}
						break;

					case 'variable':
						$variableVariable = $compilationContext->symbolTable->getVariableForRead($item['key']['value'], $compilationContext, $item['key']);
						switch ($variableVariable->getType()) {
							case 'int':
							case 'uint':
							case 'long':
							case 'ulong':
								$expr = new Expression($item['value']);
								$resolvedExpr = $expr->compile($compilationContext);
								switch ($resolvedExpr->getType()) {
									case 'int':
									case 'uint':
									case 'long':
									case 'ulong':
										$codePrinter->output('add_index_double(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getCode() . ');');
										break;
										
									case 'bool':
										$codePrinter->output('add_index_bool(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getBooleanCode() . ');');
										break;

									case 'double':
										$codePrinter->output('add_index_double(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', ' . $resolvedExpr->getCode() . ');');
										break;

									case 'null':
										$codePrinter->output('add_index_null(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ');');
										break;

									case 'string':
										$codePrinter->output('add_index_stringl(' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', SL("' . $resolvedExpr->getCode() . '"), 1);');
										break;

									case 'variable':
										$compilationContext->headersManager->add('kernel/array');
										$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
										$codePrinter->output('zephir_array_update_long(&' . $symbolVariable->getName() . ', ' . $item['key']['value'] . ', &' . $valueVariable->getName() . ', PH_COPY, "' . Compiler::getShortUserPath($item['file']) . '", ' . $item['line'] . ');');
										if ($valueVariable->isTemporal()) {
											$valueVariable->setIdle(true);
										}
										break;
									default:
										throw new CompilerException("Invalid value type: " . $item['value']['type'], $item['value']);
								}
								break;

							case 'string':
								$expr = new Expression($item['value']);
								$resolvedExpr = $expr->compile($compilationContext);
								switch ($resolvedExpr->getType()) {
									case 'int':
									case 'uint':
									case 'long':
									case 'ulong':
										$codePrinter->output('add_assoc_long_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getCode() . ');');
										break;

									case 'double':
										$codePrinter->output('add_assoc_double_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getCode() . ');');
										break;

									case 'bool':
										$codePrinter->output('add_assoc_bool_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getBooleanCode() . ');');
										break;

									case 'string':
										$codePrinter->output('add_assoc_stringl_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . ') + 1, SL("' . $resolvedExpr->getCode() . '"), 1);');
										break;

									case 'null':
										$codePrinter->output('add_assoc_null_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . ') + 1);');
										break;

									case 'variable':
										$compilationContext->headersManager->add('kernel/array');
										$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
										$codePrinter->output('zephir_array_update_string(&' . $symbolVariable->getName() . ', SL("' . $item['key']['value'] . '"), &' . $valueVariable->getName() . ', PH_COPY);');
										if ($valueVariable->isTemporal()) {
											$valueVariable->setIdle(true);
										}
										break;
									default:
										throw new CompilerException("Invalid value type: " . $item['value']['type'], $item['value']);
								}
								break;

							case 'variable':
								$expr = new Expression($item['value']);
								$resolvedExpr = $expr->compile($compilationContext);
								switch ($resolvedExpr->getType()) {
									/*case 'int':
									case 'uint':
									case 'long':
									case 'ulong':
										$codePrinter->output('add_assoc_long_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getCode() . ');');
										break;
									case 'double':
										$codePrinter->output('add_assoc_double_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getCode() . ');');
										break;
									case 'bool':
										$codePrinter->output('add_assoc_bool_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . '), ' . $resolvedExpr->getBooleanCode() . ');');
										break;
									case 'string':
										$codePrinter->output('add_assoc_stringl_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . ') + 1, SL("' . $resolvedExpr->getCode() . '"), 1);');
										break;
									case 'null':
										$codePrinter->output('add_assoc_null_ex(' . $symbolVariable->getName() . ', Z_STRVAL_P(' . $item['key']['value'] . '), Z_STRLEN_P(' . $item['key']['value'] . ') + 1);');
										break;*/
									case 'variable':
										$compilationContext->headersManager->add('kernel/array');
										$valueVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
										$codePrinter->output('zephir_array_update_zval(&' . $symbolVariable->getName() . ', ' . $variableVariable->getName() . ', &' . $valueVariable->getName() . ', PH_COPY);');
										if ($valueVariable->isTemporal()) {
											$valueVariable->setIdle(true);
										}
										break;
									default:
										throw new CompilerException("Invalid value type: " . $item['value']['type'], $item['value']);
								}
								break;
							default:
								throw new CompilerException("Cannot use variable type: " . $variableVariable->getType(). " as array index", $item['key']);
						}
						break;

					default:
						throw new CompilerException("Invalid key type: " . $item['key']['type'], $item['key']);
				}
			} else {
				$expr = new Expression($item['value']);
				$resolvedExpr = $expr->compile($compilationContext);
				$itemVariable = $this->getArrayValue($resolvedExpr, $compilationContext);
				$compilationContext->headersManager->add('kernel/array');
				$codePrinter->output('zephir_array_fast_append(' . $symbolVariable->getName() . ', ' . $itemVariable->getName() . ');');
				if ($itemVariable->isTemporal()) {
					$itemVariable->setIdle(true);
				}
			}
		}

		//return new CompiledExpression('array', $symbolVariable->getRealName(), $expression);
		return new CompiledExpression('variable', $symbolVariable->getRealName(), $expression);
	}

}
