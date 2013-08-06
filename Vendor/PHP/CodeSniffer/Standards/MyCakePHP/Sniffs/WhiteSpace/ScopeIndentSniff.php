<?php

class MyCakePHP_Sniffs_WhiteSpace_ScopeIndentSniff extends CakePHP_Sniffs_WhiteSpace_ScopeIndentSniff {

/**
 * Processes this test, when one of its tokens is encountered.
 *
 * @param PHP_CodeSniffer_File $phpcsFile All the tokens found in the document.
 * @param int $stackPtr The position of the current token
 *    in the stack passed in $tokens.
 * @return void
 */
	public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();

		// If this is an inline condition (ie. there is no scope opener), then
		// return, as this is not a new scope.
		if (isset($tokens[$stackPtr]['scope_opener']) === false) {
			return;
		}

		if ($tokens[$stackPtr]['code'] === T_ELSE) {
			$next = $phpcsFile->findNext(
				PHP_CodeSniffer_Tokens::$emptyTokens,
				($stackPtr + 1),
				null,
				true
			);

			// We will handle the T_IF token in another call to process.
			if ($tokens[$next]['code'] === T_IF) {
				return;
			}
		}

		// Find the first token on this line.
		$firstToken = $stackPtr;
		for ($i = $stackPtr; $i >= 0; $i--) {
			// Record the first code token on the line.
			if (in_array($tokens[$i]['code'], PHP_CodeSniffer_Tokens::$emptyTokens) === false) {
				$firstToken = $i;
			}

			// It's the start of the line, so we've found our first php token.
			if ($tokens[$i]['column'] === 1) {
				break;
			}
		}

		// Based on the conditions that surround this token, determine the
		// indent that we expect this current content to be.
		$expectedIndent = $this->_calculateExpectedIndent($tokens, $firstToken);

		$scopeOpener = $tokens[$stackPtr]['scope_opener'];
		$scopeCloser = $tokens[$stackPtr]['scope_closer'];

		// Some scopes are expected not to have indents.
		if (in_array($tokens[$firstToken]['code'], $this->_nonIndentingScopes) === false) {
			$indent = ($expectedIndent + $this->indent);
		} else {
			$indent = $expectedIndent;
		}

		$newline = false;
		$commentOpen = false;
		$inHereDoc = false;

		// Only loop over the content beween the opening and closing brace, not
		// the braces themselves.
		for ($i = ($scopeOpener + 1); $i < $scopeCloser; $i++) {

			// If this token is another scope, skip it as it will be handled by
			// another call to this sniff.
			if (in_array($tokens[$i]['code'], PHP_CodeSniffer_Tokens::$scopeOpeners) === true) {
				if (isset($tokens[$i]['scope_opener']) === true) {
					$i = $tokens[$i]['scope_closer'];

					// If the scope closer is followed by a semi-colon, the semi-colon is part
					// of the closer and should also be ignored. This most commonly happens with
					// CASE statements that end with "break;", where we don't want to stop
					// ignoring at the break, but rather at the semi-colon.
					$nextToken = $phpcsFile->findNext(PHP_CodeSniffer_Tokens::$emptyTokens, ($i + 1), null, true);
					if ($tokens[$nextToken]['code'] === T_SEMICOLON) {
						$i = $nextToken;
					}
				} else {
					// If this token does not have a scope_opener indice, then
					// it's probably an inline scope, so let's skip to the next
					// semicolon. Inline scopes include inline if's, abstract
					// methods etc.
					$nextToken = $phpcsFile->findNext(T_SEMICOLON, $i, $scopeCloser);
					if ($nextToken !== false) {
						$i = $nextToken;
					}
				}
				continue;
			}

			// If this is a HEREDOC then we need to ignore it as the
			// whitespace before the contents within the HEREDOC are
			// considered part of the content.
			if ($tokens[$i]['code'] === T_START_HEREDOC) {
				$inHereDoc = true;
				continue;
			} else if ($inHereDoc === true) {
				if ($tokens[$i]['code'] === T_END_HEREDOC) {
					$inHereDoc = false;
				}
				continue;
			}

			if ($tokens[$i]['column'] === 1) {
				// We started a newline.
				$newline = true;
			}

			if ($newline === true && $tokens[$i]['code'] !== T_WHITESPACE) {
				// If we started a newline and we find a token that is not
				// whitespace, then this must be the first token on the line that
				// must be indented.
				$newline = false;
				$firstToken = $i;

				$column = $tokens[$firstToken]['column'];

				// Special case for non-PHP code.
				if ($tokens[$firstToken]['code'] === T_INLINE_HTML) {
					$trimmedContentLength = strlen(ltrim($tokens[$firstToken]['content']));
					if ($trimmedContentLength === 0) {
						continue;
					}

					$contentLength = strlen($tokens[$firstToken]['content']);
					$column = ($contentLength - $trimmedContentLength + 1);
				}

				// If we're starting a new PHP block that has the scope closer
				// as the next token we'll skip the remaining checks as the scope is closed.
				if (
					$tokens[$firstToken]['code'] === T_OPEN_TAG &&
					$scopeCloser == $firstToken + 1
				) {
					continue;
				}

				// Check to see if this constant string spans multiple lines.
				// If so, then make sure that the strings on lines other than the
				// first line are indented appropriately, based on their whitespace.
				if (in_array($tokens[$firstToken]['code'], PHP_CodeSniffer_Tokens::$stringTokens) === true) {
					if (in_array($tokens[($firstToken - 1)]['code'], PHP_CodeSniffer_Tokens::$stringTokens) === true) {
						// If we find a string that directly follows another string
						// then its just a string that spans multiple lines, so we
						// don't need to check for indenting.
						continue;
					}
				}

				// This is a special condition for T_DOC_COMMENT and C-style
				// comments, which contain whitespace between each line.
				$comments = array(
					T_COMMENT,
					T_DOC_COMMENT
				);

				$isDocComment = false;
				if (in_array($tokens[$firstToken]['code'], $comments) === true) {
					$content = trim($tokens[$firstToken]['content']);
					if (preg_match('|^/\*|', $content) !== 0) {
						// Check to see if the end of the comment is on the same line
						// as the start of the comment. If it is, then we don't
						// have to worry about opening a comment.
						if (preg_match('|\*/$|', $content) === 0) {
							// We don't have to calculate the column for the
							// start of the comment as there is a whitespace
							// token before it.
							$commentOpen = true;
							$isDocComment = (substr($content, 0, 3) === '/**');
						}
					} elseif ($commentOpen === true) {
						if ($content === '') {
							// We are in a comment, but this line has nothing on it
							// so let's skip it.
							continue;
						}

						$contentLength = strlen($tokens[$firstToken]['content']);
						$trimmedContentLength = strlen(ltrim($tokens[$firstToken]['content']));

						$column = ($contentLength - $trimmedContentLength + 1);
						if (preg_match('|\*/$|', $content) !== 0) {
							$commentOpen = false;
						}
					}
				}

				if ($isDocComment === true) {
					// Doc block comments should be indented same than the
					// code that precedes them.
					if ($indent !== $column) {
						$error = 'Doc blocks must be indented the same than the code that precedes them.';
						$phpcsFile->addError($error, $firstToken, 'DocCommentStartColumn');
					}
				} else if ($column !== $indent) {
					if ($this->exact === true || $column < $indent) {
						$type  = 'IncorrectExact';
						$error = 'Line indented incorrectly; expected ';
						if ($this->exact === false) {
							$error .= 'at least ';
							$type = 'Incorrect';
						}

						$error .= '%s spaces, found %s';
						$data = array(
							($indent - 1),
							($column - 1),
						);
						$phpcsFile->addError($error, $firstToken, $type, $data);
					}
				}
			}
		}
	}

}