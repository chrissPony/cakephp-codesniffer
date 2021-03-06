<?php

define('T_OPEN_PARENTHESIS', 1004);
define('T_CLOSE_PARENTHESIS', 1005);

/**
 * A class that can remove unncessary use imports in PHP class files.
 *
 * Modified version of http://stackoverflow.com/questions/9895502/easiest-way-to-detect-remove-unused-use-statements-from-php-codebase
 */
class UseStatementSanitizer {

	public $content;

	public function __construct($file) {
		$this->content = token_get_all(file_get_contents($file));
		foreach ($this->content as $key => $val) {
			if (!is_string($val) || !in_array($val, array('(', ')'))) {
				continue;
			}

			$line = 1;
			$i = $key - 1;
			while (isset($this->content[$i])) {
				if (is_array($this->content[$i])) {
					$line = $this->content[$i][2];
					break;
				}
				$i--;
			}

   		if ($val === '(') {
   			$val = array(
				 	T_OPEN_PARENTHESIS,
					$val,
					$line
				);
   		}
   		if ($val === ')') {
   			$val = array(
				 	T_CLOSE_PARENTHESIS,
					$val,
					$line
				);
   		}

   		$this->content[$key] = $val;
		}

		// we don't need and want them while parsing
		$this->removeTokens(T_COMMENT);
		$this->removeTokens(T_WHITESPACE);
	}

	/**
	 * UseStatementSanitizer::getUnused()
	 *
	 * @return array
	 */
	public function getUnused() {
		$uses = $this->getUseStatements();
		$usages = $this->getUsages();
		$unused = array();

		foreach ($uses as $use) {
			if (!in_array($use, $usages)) {
				$unused[] = $use;
			}
		}
		return $unused;
	}

	/**
	 * UseStatementSanitizer::getUsages()
	 *
	 * @return array
	 */
	public function getUsages() {
		$usages = array();

		foreach ($this->content as $key => $token) {
			if (is_string($token)) {
				continue;
			}
			$t = $this->content;

			// for static calls
			if ($token[0] == T_DOUBLE_COLON) {
				// only if it is NOT full or half qualified namespace
				if ($t[$key - 2][0] != T_NAMESPACE) {
					if (!is_array($t[$key - 1])) {
						continue;
					}
					$usages[] = $t[$key - 1][1];
				}
			}

			// for object instanciations
			if ($token[0] == T_NEW) {
				if ($t[$key + 2][0] != T_NAMESPACE) {
					$usages[] = $t[$key + 1][1];
				}
			}

			// for class extensions
			if ($token[0] == T_EXTENDS || $token[0] == T_IMPLEMENTS) {
				if ($t[$key + 2][0] != T_NAMESPACE) {
					$useStatements = $this->_getUseStatements(array($key));
					$classes = $this->_extractFromUseStatements($useStatements);

					foreach ($classes as $class) {
						$usages[] = $class;
					}
				}
			}

			// for catch blocks
			if ($token[0] == T_CATCH) {
				if ($t[$key + 3][0] != T_NAMESPACE) {
					$usages[] = $t[$key + 2][1];
				}
			}

			// for instance of
			if ($token[0] == T_INSTANCEOF) {
				if ($t[$key + 1][0] != T_NAMESPACE) {
					$usages[] = $t[$key + 1][1];
				}
			}

			// for object typehints
			if ($token[0] == T_OPEN_PARENTHESIS) {
				$deep = 0;
				$start = $key;
				$end = 0;
				$i = $start + 1;
				while (isset($t[$i])) {
					if ($t[$i][0] == T_OPEN_PARENTHESIS) {
						$deep++;
					}

					if ($t[$i][0] == T_CLOSE_PARENTHESIS) {
						if ($deep === 0) {
							$end = $i;
							break;
						}
						$deep--;
					}
					$i++;
				}

				$classes = $this->_extractFromParentheses($start + 1, $end - 1);
				foreach ($classes as $class) {
					$usages[] = $class;
				}
			}
		}

		$use = $this->_extractFromUse();
		$usages = array_merge($usages, $use);

		return array_values(array_unique($usages));
	}

	protected function _extractFromUse() {
		$tokenUses = $this->_getTokenUses(T_USE, 1);

		$useStatements = $this->_getUseStatements($tokenUses);
		return $useStatements;
	}

	protected function _extractFromParentheses($start, $end) {
		if ($end <= $start) {
			return array();
		}

		$t = $this->content;

		$classes = array();
		for ($i = $start; $i <= $end; $i++) {
			if (is_string($t[$i])) {
				// comma
				continue;
			}
			if ($t[$i][0] != T_STRING) {
				continue;
			}

			$class = $t[$i][1];
			$classes[] = $class;
		}
		return $classes;
	}

	protected function _getTokenUses($tokenKey, $onlyLevel = null) {
		$tokenUses = array();
		$level = 0;
		foreach ($this->content as $key => $token) {
			// for traits, only first level uses should be captured
			if (is_string($token)) {
				if ($token == '{') {
					$level++;
				}
				if ($token == '}') {
					$level--;
				}
			}

			// capture all use statements besides trait-uses in class
			if ($onlyLevel !== null && $level !== $onlyLevel) {
				continue;
			}
			if (!is_string($token) && $token[0] == $tokenKey) {
				$tokenUses[] = $key;
			}
		}
		return $tokenUses;
	}

	protected function _getUseStatements($tokenUses) {
		$useStatements = array();

		// get rid of uses in lambda functions
		foreach ($tokenUses as $key => $tokenKey) {
			$i = $tokenKey;
			$char = '';
			$useStatements[$key] = '';

			while ($char !== ';' && $char !== '{') {
				++$i;
				$char = is_string($this->content[$i]) ? $this->content[$i] : $this->content[$i][1];
				if ($char === '{') {
					break;
				}

				if (!is_string($this->content[$i]) && $this->content[$i][0] == T_AS) {
					$useStatements[$key] .= ' AS ';
				} else {
					$useStatements[$key] .= $char;
				}

				if ($char === '(') {
					unset($useStatements[$key]);
					break;
				}
			}
		}

		return $this->_extractFromUseStatements($useStatements);
	}

	protected function _extractFromUseStatements($useStatements) {
		$allUses = array();

		// get all use statements
		foreach ($useStatements as $fullStmt) {
			$fullStmt = rtrim($fullStmt, ';');
			$fullStmt = preg_replace('/^.+ AS /', '', $fullStmt);
			$fullStmt = explode(',', $fullStmt);

			foreach ($fullStmt as $singleStmt) {
				// $singleStmt only for full qualified use
				$fqUses[] = $singleStmt;

				$singleStmt = explode('\\', $singleStmt);
				$allUses[] = array_pop($singleStmt);
			}
		}
		return $allUses;
	}

	/**
	 * UseStatementSanitizer::getUseStatements()
	 *
	 * @return array
	 */
	public function getUseStatements() {
		$tokenUses = $this->_getTokenUses(T_USE, 0);

		return $this->_getUseStatements($tokenUses);
	}

	/**
	 * UseStatementSanitizer::removeTokens()
	 *
	 * @param int $tokenId
	 * @return void
	 */
	public function removeTokens($tokenId) {
		foreach ($this->content as $key => $token) {
			if (isset($token[0]) && $token[0] == $tokenId) {
				unset($this->content[$key]);
			}
		}
		// reindex
		$this->content = array_values($this->content);
	}

}
