<?php
/**
 * Meta-test: every `// translators:` (or block-form) translator
 * comment authored in src/ must be extracted by `wp i18n make-pot`
 * into the resulting .pot file as a `#.` extracted comment alongside
 * its msgid.
 *
 * The POT extractor walks the AST looking for gettext calls and pulls
 * comments that IMMEDIATELY precede each `__()` / `_n()` / `_x()` /
 * etc. — "immediately" being a small line-proximity window. When call
 * sites nest the gettext literal deep inside helper wrappers (e.g.
 * `sprintf( esc_html( Strings::get( Strings::KEY, __( ... ) ) ), ... )`)
 * a `// translators:` comment placed above the OUTER expression is too
 * far from the `__()` call and is silently dropped from the POT.
 *
 * Translators see `%s` with no guidance → bad translation. This test
 * catches it at CI time.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginTranslatorCommentsExtractedTest extends WP_UnitTestCase {

	/**
	 * Match either `// translators: ...` line comments or
	 * `/` + `* translators: ... *` + `/` block comments. Anchored
	 * at start-of-comment, the same way extractors anchor.
	 */
	const COMMENT_RX = '/(?:\/\/|\/\*)\s*translators:\s*(.+?)(?:\*\/|$)/m';

	public function test_every_translator_comment_in_source_is_extracted_into_pot() {

		// 1) Generate a fresh POT from src/. Bin wp-cli is available
		//    in the tests-cli container.
		$src_root  = dirname( __DIR__ ) . '/src';
		$pot_path  = sys_get_temp_dir() . '/trustedlogin-translator-comments.pot';
		if ( is_file( $pot_path ) ) {
			unlink( $pot_path );
		}

		$cmd = sprintf(
			'wp i18n make-pot %s %s --domain=trustedlogin --slug=trustedlogin --skip-js --skip-audit 2>&1',
			escapeshellarg( $src_root ),
			escapeshellarg( $pot_path )
		);
		$output = array();
		$status = 0;
		exec( $cmd, $output, $status );

		$this->assertSame( 0, $status, "wp i18n make-pot failed: " . implode( "\n", $output ) );
		$this->assertFileExists( $pot_path );

		$pot = file_get_contents( $pot_path );

		// 2) Walk every PHP source file and collect (file, line,
		//    comment-text, expected-msgid). We pair each comment
		//    with the FIRST gettext call that follows it within
		//    a reasonable proximity (40 lines covers even the
		//    deeply-wrapped sprintf( esc_html( Strings::get( ... __()))).
		$source_pairs = $this->scan_source_for_commented_gettext_calls( $src_root );

		$this->assertNotEmpty(
			$source_pairs,
			'No translator comments found in src/ — sanity check failed.'
		);

		// 3) For each pair, assert the POT entry for that msgid
		//    has a `#.` extracted-comment line containing the
		//    same translator text.
		$missing = array();
		foreach ( $source_pairs as $pair ) {
			$msgid_block = $this->find_pot_block_for_msgid( $pot, $pair['msgid'] );
			if ( null === $msgid_block ) {
				$missing[] = sprintf(
					"%s:%d (msgid %s) — POT has NO entry at all",
					$pair['file'],
					$pair['line'],
					$this->snip( $pair['msgid'] )
				);
				continue;
			}

			// Normalize whitespace for the contains check — POT
			// wraps long comments across multiple `#.` lines.
			$pot_comment_text = preg_replace(
				'/\s+/',
				' ',
				$this->extract_pot_comments( $msgid_block )
			);
			$source_comment_text = preg_replace( '/\s+/', ' ', $pair['comment'] );

			// Require a substantive overlap. The extractor sometimes
			// wraps lines mid-word; matching on the first ~30 chars
			// of the source comment is the safest signal.
			$needle = trim( substr( $source_comment_text, 0, 30 ) );
			if ( '' === $needle ) {
				continue; // empty `translators:` is harmless
			}
			if ( false === strpos( $pot_comment_text, $needle ) ) {
				$missing[] = sprintf(
					"%s:%d (msgid %s)\n      source comment: %s\n      POT #. lines: %s",
					$pair['file'],
					$pair['line'],
					$this->snip( $pair['msgid'] ),
					$this->snip( $source_comment_text ),
					'' === $pot_comment_text ? '(none)' : $this->snip( $pot_comment_text )
				);
			}
		}

		$this->assertEmpty(
			$missing,
			"Translator comments in src/ that did NOT land in the POT:\n  - "
			. implode( "\n  - ", $missing )
			. "\nFix: move the `// translators:` comment so it immediately"
			. " precedes the `__()` literal — `wp i18n make-pot` doesn't"
			. " scan more than a few lines back."
		);
	}

	// -----------------------------------------------------------------
	//  Helpers
	// -----------------------------------------------------------------

	/**
	 * @return list<array{file: string, line: int, comment: string, msgid: string}>
	 */
	private function scan_source_for_commented_gettext_calls( string $src_root ): array {
		$out = array();
		$files = glob( $src_root . '/*.php' );

		foreach ( $files as $path ) {
			$rel = basename( $path );
			if ( 'Strings.php' === $rel ) {
				continue; // class docblock contains example __() in comments
			}

			$contents = file_get_contents( $path );
			$lines    = explode( "\n", $contents );
			$n        = count( $lines );

			for ( $i = 0; $i < $n; $i++ ) {
				if ( ! preg_match( self::COMMENT_RX, $lines[ $i ], $cm, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				$comment = trim( $cm[1][0] );
				if ( '' === $comment ) {
					continue;
				}
				$comment_offset_on_line = $cm[0][1];

				$msgid      = null;
				$found_line = null;
				$gettext_rx = '/(?:esc_html__|esc_attr__|__|_e|_n|_x|_nx|esc_html_x|esc_attr_x)\s*\(\s*([\x27"])((?:\\\\.|(?!\1).)*?)\1/';

				// 1) Inline case: comment and gettext are on the same
				//    line. Search the substring AFTER the comment for a
				//    gettext call. This is the canonical placement when
				//    using block-form `/* translators: */ __(...)`.
				$rest_of_line = substr( $lines[ $i ], $comment_offset_on_line );
				if ( preg_match( $gettext_rx, $rest_of_line, $gm ) ) {
					$quote = $gm[1];
					$msgid = $quote === "'"
						? str_replace( array( "\\'", "\\\\" ), array( "'", "\\" ), $gm[2] )
						: stripcslashes( $gm[2] );
					$found_line = $i + 1;
				}

				// 2) Otherwise look forward up to 40 lines for the next
				//    gettext call (the multi-line placement pattern).
				if ( null === $msgid ) {
					for ( $j = $i + 1; $j < min( $n, $i + 40 ); $j++ ) {
						if ( preg_match( $gettext_rx, $lines[ $j ], $gm ) ) {
							$quote = $gm[1];
							$msgid = $quote === "'"
								? str_replace( array( "\\'", "\\\\" ), array( "'", "\\" ), $gm[2] )
								: stripcslashes( $gm[2] );
							$found_line = $j + 1;
							break;
						}
					}
				}
				if ( null === $msgid ) {
					continue;
				}

				$out[] = array(
					'file'    => $rel,
					'line'    => $i + 1,
					'comment' => $comment,
					'msgid'   => $msgid,
				);
			}
		}

		return $out;
	}

	/**
	 * Returns the POT block (preceding metadata + msgid + msgstr)
	 * for $msgid, or null if absent.
	 */
	private function find_pot_block_for_msgid( string $pot, string $msgid ): ?string {
		// POT msgids escape backslashes and double-quotes.
		$escaped = addcslashes( $msgid, "\\\"" );

		// Match: (optional preceding metadata lines like #. #: #,)
		// followed by msgid "..." then msgstr "".
		$pat = '/(?:^[#].*\n)*msgid\s+"' . preg_quote( $escaped, '/' ) . '"\s*\nmsgstr/m';
		if ( preg_match( $pat, $pot, $m, PREG_OFFSET_CAPTURE ) ) {
			return $m[0][0];
		}

		// Long msgids that span multiple lines via `"...\n"` continuation.
		// Cheap fallback: find any msgid containing this string.
		$lines = explode( "\n", $pot );
		foreach ( $lines as $idx => $line ) {
			if ( 0 === strpos( $line, 'msgid "' ) && false !== strpos( $line, $escaped ) ) {
				// Walk backwards to collect preceding `#.` / `#:` / `#,` lines.
				$start = $idx;
				while ( $start > 0 && 0 === strpos( $lines[ $start - 1 ], '#' ) ) {
					$start--;
				}
				return implode( "\n", array_slice( $lines, $start, $idx - $start + 2 ) );
			}
		}

		return null;
	}

	/**
	 * Pull `#.` extracted-comment text from a POT entry block.
	 */
	private function extract_pot_comments( string $block ): string {
		$out = array();
		foreach ( explode( "\n", $block ) as $line ) {
			if ( 0 === strpos( $line, '#.' ) ) {
				$out[] = trim( substr( $line, 2 ) );
			}
		}
		return implode( ' ', $out );
	}

	private function snip( string $s, int $len = 70 ): string {
		$s = trim( preg_replace( '/\s+/', ' ', $s ) );
		return strlen( $s ) > $len ? substr( $s, 0, $len - 1 ) . '…' : $s;
	}
}
