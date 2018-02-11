<?php namespace axxapy\Core;

class ErrorHandler {
	const OUTPUT_FORMAT_AUTO = 1;
	const OUTPUT_FORMAT_HTML = 2;
	const OUTPUT_FORMAT_TEXT = 3;
	const OUTPUT_FORMAT_JSON = 4;

	private static $error_types = [
		E_ERROR           => 'ERROR',      // 1 - Fatal run-time errors. These indicate errors that can not be recovered from, such as a memory allocation problem. Execution of the script is halted.
		E_WARNING         => 'WARNING',    // 2 - Run-time warnings (non-fatal errors). Execution of the script is not halted.
		E_PARSE           => 'PARSE',      // 4 - Compile-time parse errors. Parse errors should only be generated by the parser.
		E_NOTICE          => 'NOTICE',     // 8 - Run-time notices. Indicate that the script encountered something that could indicate an error, but could also happen in the normal course of running a script.
		E_CORE_ERROR      => 'ERROR',      // 16 - Fatal errors that occur during PHP's initial startup. This is like an E_ERROR, except it is generated by the core of PHP.
		E_CORE_WARNING    => 'WARNING',    // 32 - Warnings (non-fatal errors) that occur during PHP's initial startup. This is like an E_WARNING, except it is generated by the core of PHP.
		E_COMPILE_ERROR   => 'ERROR',      // 64 - Fatal compile-time errors. This is like an E_ERROR, except it is generated by the Zend Scripting Engine.
		E_COMPILE_WARNING => 'WARNING',    // 128 - Compile-time warnings (non-fatal errors). This is like an E_WARNING, except it is generated by the Zend Scripting Engine.
		E_USER_ERROR      => 'ERROR',      // 256 - User-generated error message. This is like an E_ERROR, except it is generated in PHP code by using the PHP function trigger_error().
		E_USER_WARNING    => 'WARNING',    // 512 - User-generated warning message. This is like an E_WARNING, except it is generated in PHP code by using the PHP function trigger_error().
		E_USER_NOTICE     => 'NOTICE',     // 1024 - User-generated notice message. This is like an E_NOTICE, except it is generated in PHP code by using the PHP function trigger_error().
		E_STRICT          => 'STRICT',     // 2048 - Enable to have PHP suggest changes to your code which will ensure the best interoperability and forward compatibility of your code.
		E_DEPRECATED      => 'DEPRECATED', // 8192 - Run-time notices. Enable this to receive warnings about code that will not work in future versions.
		E_USER_DEPRECATED => 'DEPRECATED', // 16384 - User-generated warning message. This is like an E_DEPRECATED, except it is generated in PHP code by using the PHP function trigger_error().
	];

	private $opened_draw_count = 0;

	private $output_format = self::OUTPUT_FORMAT_AUTO;

	/** @var Callable[] */
	private $exception_handlers = [];

	/** @var array */
	private $error_handlers = [];

	public function __construct($debug_mode = false) {
		set_exception_handler(function (\Throwable $ex) use ($debug_mode) {
			/** @var \Throwable $ex */

			foreach ($this->exception_handlers as $Handler) {
				call_user_func($Handler, $ex, $debug_mode);
			}

			if (!$debug_mode) return false;

			$buf = '';
			do {
				$msg = $ex->getMessage();
				$buf .= $this->format('[' . $ex->getCode() . '] ' . get_class($ex), $ex->getLine(), $ex->getFile(), $msg, $ex->getTrace(), $ex->getPrevious() === null);
			} while (($ex = $ex->getPrevious()) !== null);

			$this->printDebug($buf);

			die(1);
		});

		set_error_handler(function ($type, $msg, $file, $line, $context) use ($debug_mode) {
			if (!($type & error_reporting())) return true;

			foreach ($this->error_handlers as $_handler) {
				if ($type & $_handler[0]) {
					call_user_func($_handler[1], $type, $msg, $file, $line, $context, $debug_mode);
				}
			}

			if (!$debug_mode) return false;

			$err = $this->format($type, $line, $file, $msg, debug_backtrace());

			if ($type & error_reporting()) {
				$this->printDebug($err);
			}

			// do not Use Built-In error handler for production.
			return true;
		}, E_ALL);

		register_shutdown_function(function () use ($debug_mode) {
			$err = error_get_last();
			if ($err['type'] != E_ERROR) return;//catchable fatal error

			foreach ($this->error_handlers as $_handler) {
				if ($err['type'] & $_handler[0]) {
					call_user_func($_handler[1], $err['type'], $err['message'], $err['file'], $err['line'], null, $debug_mode);
				}
			}

			if (!$debug_mode) return;

			$err_msg = $this->prepareDebugInfo(E_ERROR, $err['line'], $err['file'], $err['message'], debug_backtrace());

			$this->printDebug($err_msg);
		});
	}

	/**
	 * @param int $output_format self::OUTPUT_FORMAT_*
	 *
	 * @return $this
	 */
	public function setOutputFormat($output_format) {
		if (!in_array($output_format, [self::OUTPUT_FORMAT_AUTO, self::OUTPUT_FORMAT_HTML, self::OUTPUT_FORMAT_TEXT])) {
			trigger_error('Wrong output format: ' . var_export($output_format, true), E_USER_WARNING);
			return $this;
		}
		$this->output_format = (int)$output_format;
		return $this;
	}

	public function addExceptionHandler(Callable $Handler) {
		$this->exception_handlers[] = $Handler;
		return $this;
	}

	public function addErrorHandler(Callable $Handler, $error_level = E_ALL) {
		$this->error_handlers[] = [$error_level, $Handler];
		return $this;
	}

	public static function getErrorNameByType($type) {
		return isset(self::$error_types[$type]) ? self::$error_types[$type] : $type;
	}

	private function beautify_trace($backtrace, $size_max = 0) {
		$f_size = function ($arr, $key) {
			return isset($arr[$key]) ? strlen($arr[$key]) : 0;
		};

		$backtrace_size = count($backtrace);
		if ($backtrace_size <= 0) return [];

		$out = [];

		$size_backtrace     = [
			'file'  => max(array_map($f_size, $backtrace, array_fill(0, $backtrace_size, 'file'))),
			'line'  => max(array_map($f_size, $backtrace, array_fill(0, $backtrace_size, 'line'))),
			'func'  => max(array_map($f_size, $backtrace, array_fill(0, $backtrace_size, 'function'))),
			'class' => max(array_map($f_size, $backtrace, array_fill(0, $backtrace_size, 'class'))),
		];
		$backtrace_tpl      = "%-{$size_backtrace['class']}s -> %-{$size_backtrace['func']}s %-{$size_backtrace['file']}s : %-{$size_backtrace['line']}s";
		$size_max_backtrace = strlen(sprintf($backtrace_tpl, '', '', '', ''));
		$size_max           = max($size_max, $size_max_backtrace);
		foreach ($backtrace as $trace) {
			$out[] = sprintf(
				$backtrace_tpl . ' ' . str_repeat(' ', $size_max - $size_max_backtrace),
				isset($trace['class']) ? $trace['class'] : '',
				isset($trace['function']) ? $trace['function'] : '',
				isset($trace['file']) ? $trace['file'] : '',
				isset($trace['line']) ? $trace['line'] : ''
			);
		}

		return $out;
	}

	private function simple_trace(array $backtrace, $shift = true) {
		if ($shift) {
			array_shift($backtrace);
		}
		$trace = [];
		foreach ($backtrace as $i => $step) {
			$class = '';
			if (isset($step['class'])) {
				$class = "{$step['class']}{$step['type']}";
			}
			$args = [];
			if (isset($step['args'])) {
				foreach ($step['args'] as $arg) {
					$type = gettype($arg);
					switch ($type) {
						case 'array':
							$args[] = "{$type}(" . count($arg) . ")";
							break;
						case 'integer':
						case 'double':
						case 'float':
						case 'boolean':
						case 'NULL':
							$args[] = var_export($arg, true);
							break;
						case 'string':
							if (strlen($arg) > 10) {
								$arg = substr(trim($arg), 0, 10) . '...';
							}
							$args[] = "'{$arg}'";
							break;
						case 'object':
							$args[] = get_class($arg);
							break;
						default:
							$args[] = $type;
							break;
					}
				}
			}
			$args      = implode(', ', $args);
			$trace_i   = [];
			$trace_i[] = "#{$i}";
			if (isset($step['file'])) {
				$filename_relative = $step['file'];//str_replace(self::getFilesPrefix(), '.' . DIRECTORY_SEPARATOR, $step['file']);
				$trace_i[]         = "{$filename_relative}";
				if (isset($step['line'])) {
					$trace_i[] = "({$step['line']}):";
				}
			}
			$trace_i[] = ": {$class}{$step['function']}({$args})";
			$trace[]   = implode(' ', $trace_i);
		}
		$trace = implode(PHP_EOL, $trace) . PHP_EOL;
		return $trace;
	}

	private function getCodePart($file, $line, $surround_lines = 3) {
		if ($file == 'Unknown' || !file_exists($file)) return false;
		$file = file($file);
		if (!$file) return false;
		$count_before = $line > $surround_lines + 1 ? $line - $surround_lines - 1 : $line - 1;
		if ($count_before) {
			$file = array_slice($file, $count_before);
		}
		if (count($file) + $surround_lines * 2 + 1 > $surround_lines) {
			$file = array_slice($file, 0, $surround_lines * 2 + 1);
		}
		$array_keys = array_fill($count_before + 1, count($file), 1);
		return array_combine(array_keys($array_keys), $file);
	}

	private function format_cli($type, $line, $file, $message, $backtrace, $is_last) {
		$type       = self::getErrorNameByType($type);
		$first_line = "{$type} │ {$file} : {$line}";

		$message = explode("\n", $message);

		$code = $this->getCodePart($file, $line);

		$size_max = max(strlen($first_line), max(array_map('strlen', $message)));
		$trace    = $this->beautify_trace($backtrace, $size_max);
		$size_max = max($size_max, max(array_map('strlen', $trace)));

		$hor_line = str_repeat('─', $size_max + 2);

		$outbuff = '';

		if ($this->opened_draw_count) {
			$outbuff .= "  " . str_repeat('↑', $size_max) . " \n";
		}

		$left_top_angle  = $this->opened_draw_count ? '├' : "\n┌";
		$right_top_angle = $this->opened_draw_count ? '┤' : '┐';

		$first     = $left_top_angle . str_repeat('─', strlen($type) + 2) . '┬';
		$rest_line = str_repeat('─', $size_max - strlen($type) - 1);
		$outbuff .= $first . $rest_line . $right_top_angle . "\n";
		$outbuff .= '│ ' . $first_line . str_repeat(' ', $size_max - strlen($first_line) + 3) . "│\n";
		$outbuff .= '├' . str_repeat('─', strlen($type) + 2) . '┴' . $rest_line . "┤\n";

		foreach ($message as $str) {
			$outbuff .= "│ {$str}" . str_repeat(' ', $size_max - strlen($str)) . " │\n";
		}

		if ($code) {
			$outbuff .= "├{$hor_line}┘\n";
			$lines_size = max(array_map('strlen', array_keys($code)));
			$pointer    = str_repeat('>', $lines_size);
			foreach ($code as $line_num => $line_code) {
				if ($line_num == $line) {
					$outbuff .= sprintf("│\033[41m {$pointer} \033[0m\e[1;31m%s\033[0m", $line_code);
				} else {
					$outbuff .= sprintf("│ %-{$lines_size}s %s", $line_num, $line_code);
				}
			}
			$outbuff .= "├{$hor_line}┐\n";
		} else {
			$outbuff .= "├{$hor_line}┤\n";
		}

		$outbuff .= "│ " . implode(" │\n│ ", $trace) . " │\n";
		if ($is_last) {
			$outbuff .= "└{$hor_line}┘\n";
			$this->opened_draw_count = 0;
		} else {
			$outbuff .= "├{$hor_line}┤\n";
			$this->opened_draw_count++;
		}

		return $outbuff;
	}

	private function format($type, $line, $file, $message, $backtrace, $is_last = true) {
		if (($this->output_format == self::OUTPUT_FORMAT_AUTO && php_sapi_name() === 'cli') || $this->output_format == self::OUTPUT_FORMAT_TEXT) {
			return $this->format_cli($type, $line, $file, $message, $backtrace, $is_last);
		} elseif ($this->output_format == self::OUTPUT_FORMAT_JSON) {
			return $this->format_json($type, $line, $file, $message, $backtrace);
		}

		$colors = [
			'red',
			E_NOTICE     => 'green',
			E_WARNING    => 'orange',
			E_ERROR      => 'red',
			E_DEPRECATED => 'lightgrey',
		];

		$codepart  = '';
		$_codepart = $this->getCodePart($file, $line);
		if ($_codepart) {
			$lines_size = max(array_map('strlen', array_keys($_codepart)));
			$pointer    = str_repeat('>', $lines_size);
			foreach ($_codepart as $line_num => $line_code) {
				if ($line_num == $line) {
					$codepart .= sprintf("<font color=red>{$pointer} %s</font>", $line_code);
				} else {
					$codepart .= sprintf("<font color=grey>%-{$lines_size}s</font> %s", $line_num, $line_code);
				}
			}
		}

		$data = [
			'color'    => isset($colors[$type]) ? $colors[$type] : $colors[0],
			'code'     => self::getErrorNameByType($type),
			'line'     => $line,
			'file'     => $file,
			'message'  => $message,
			'trace'    => implode("\n", $this->beautify_trace($backtrace)),
			'codepart' => trim($codepart),
		];

		$outbuff = <<<EOL
		<ul class="PHPError" style="position: relative; box-shadow: 0px 0px 3px 1px grey; border-radius: 7px; list-style: none; z-index: 9999999; font-family: monospace; font-size: 12px; background-color: white; color: black; border: 1px solid black; padding: 0; margin: 10px;">
			<li style="display: block; border-bottom: 1px black solid; ">
				<span style="border-right: 1px black solid; padding: 5px; display: inline-block; color: {$data['color']}">{$data['code']}</span>
				<span style="padding: 5px;">{$data['file']}:{$data['line']}</span>
			<li style="display: block; border-bottom: 1px black solid; padding: 5px;">{$data['message']}
			<li style="display: block; border-bottom: 1px black solid; padding: 5px; white-space: pre; overflow: auto;">{$data['codepart']}</li>
			<li style="display: block; white-space: pre; padding: 5px;">{$data['trace']}</li>
EOL;
		if (!$is_last) {
			$this->opened_draw_count++;
			return $outbuff;
		} else {
			$outbuff .= '</ul>';
			for (; $this->opened_draw_count > 0; $this->opened_draw_count--) {
				$outbuff .= '</ul>';
			}
		}

		return $outbuff;
	}

	public function format_json($type, $line, $file, $message, $backtrace) {
		$message = str_replace(array("\r", "\n"), '', $message);
		$message = substr($message, 0, 170);
		$data = [
			'argv' => (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) ? implode(' ', $_SERVER['argv']) : '',
			'sapi_name' => php_sapi_name(),
			'timestamp' => date("U"),
			'type' => $type,
			'error' => $message,
			'trace' => $this->simple_trace($backtrace, true),
			'script_filename' => $file,
			'line' => $line,
			'GLOBALS' => array(
				'_GET' => var_export($_GET, true),
				'_POST' => var_export($_POST, true),
				'_COOKIE' => var_export($_COOKIE, true),
				'_FILES' => var_export($_FILES, true),
				'_SERVER' => var_export($_SERVER, true),
				'_ENV' => var_export($_ENV, true),
				'_REQUEST' => var_export($_REQUEST, true),
			),
		];
		return json_encode($data);
	}

	public function formatException(\Throwable $ex, $format = self::OUTPUT_FORMAT_HTML) {
		switch ($format) {
			case self::OUTPUT_FORMAT_HTML:
				return $this->format('[' . $ex->getCode() . '] ' . get_class($ex), $ex->getLine(), $ex->getFile(), $ex->getMessage(), $ex->getTrace(), $ex->getPrevious() === null);

			case self::OUTPUT_FORMAT_TEXT:
				return $this->format_cli('[' . $ex->getCode() . '] ' . get_class($ex), $ex->getLine(), $ex->getFile(), $ex->getMessage(), $ex->getTrace(), $ex->getPrevious() === null);

			case self::OUTPUT_FORMAT_JSON:
				return $this->format_json('[' . $ex->getCode() . '] ' . get_class($ex), $ex->getLine(), $ex->getFile(), $ex->getMessage(), $ex->getTrace(), $ex->getPrevious() === null);
		}
	}

	private function printDebug($str) {
		if (PHP_SAPI == 'cli') {
			fwrite(STDERR, $str);
		} else {
			echo $str;
		}
	}
}
