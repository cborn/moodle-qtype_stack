<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stack.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../maximaparser/utils.php');
require_once(__DIR__ . '/../maximaparser/MP_classes.php');
require_once(__DIR__ . '/cassession2.class.php');

/**
 * Class to parse user-entered data into CAS sessions.
 *
 * @copyright  2012 University of Birmingham
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stack_cas_keyval {

    /** @var Holds the raw text as entered by a question author. */
    private $raw;

    /** @var array of stack_ast_container_silent */
    private $statements;

    /** @var bool */
    private $valid;

    /** @var string HTML error message that can be displayed to the user. */
    private $errors;

    public function __construct($raw) {
        $this->raw          = $raw;
        $this->statements   = array();

        if (!is_string($raw)) {
            throw new stack_exception('stack_cas_keyval: raw must be a string.');
        }
    }

    private function validate($inputs) {

        if (empty($this->raw) or '' == trim($this->raw)) {
            $this->valid = true;
            return true;
        }

        // CAS keyval may not contain @ or $.
        if (strpos($this->raw, '@') !== false || strpos($this->raw, '$') !== false) {
            $this->errors = stack_string('illegalcaschars');
            $this->valid = false;
            return false;
        }

        // Subtle one: must protect things inside strings before we do QMCHAR tricks.
        $str = $this->raw;
        $strings = stack_utils::all_substring_strings($str);
        foreach ($strings as $key => $string) {
            $str = str_replace('"'.$string.'"', '[STR:'.$key.']', $str);
        }

        $str = str_replace('?', 'QMCHAR', $str);

        foreach ($strings as $key => $string) {
            $str = str_replace('[STR:'.$key.']', '"' .$string . '"', $str);
        }

        // 6/10/18 No longer split by line change, split by statement.
        // Allow writing of loops and other long statements onto multiple lines.
        $ast = maxima_parser_utils::parse_and_insert_missing_semicolons($str);
        if (!$ast instanceof MP_Root) {
            // If not then it is a SyntaxError.
            $this->errors = $ast->getMessage();
            $this->valid = false;
            return false;
        }

        $ast = maxima_parser_utils::strip_comments($ast);

        $this->valid   = true;
        $this->statements   = array();
        foreach ($ast->items as $item) {
            $cs = stack_ast_container_silent::make_from_teacher_ast($item, $item->toString(), '',
                    new stack_cas_security(), array());
            $this->valid = $this->valid && $cs->get_valid();
            $this->errors = array_merge($this->errors, $cs->get_errors(true));
            $this->statements[] = $cs;
        }

        // Prevent reference to inputs in the values of the question variables.
        if (is_array($inputs)) {
            $usage = $this->get_variable_usage();
            foreach (array_merge($usage['read'],$usage['write']) as $key => $used) {
                if (in_array($key, $inputs)) {
                    $this->valid = false;
                    $this->errors .= stack_string('stackCas_inputsdefined', $key);
                }
            }
        }
    }

    /*
     * @array $inputs Holds an array of the input names which are forbidden as keys.
     * @bool $inputstrict Decides if we should forbid any reference to the inputs in the values of variables.
     */
    public function get_valid($inputs = null) {
        if (null === $this->valid || is_array($inputs)) {
            $this->validate($inputs);
        }
        return $this->valid;
    }

    public function get_errors($casdebug=false) {
        if (null === $this->valid) {
            $this->validate(null);
        }
        if ($casdebug) {
            return $this->errors.$this->session->get_debuginfo();
        }
        return $this->errors;
    }

    public function instantiate() {
        if (null === $this->valid) {
            $this->validate(null);
        }
        $cs = new stack_cas_cassession2($this->statements, $this->options, $this->seed);
        $cs->instantiate();
    }

    public function get_session() {
        if (null === $this->valid) {
            $this->validate(null);
        }
        return new stack_cas_cassession2($this->statements, $this->options, $this->seed);
    }

    public function get_variable_usage(array &$update_array = array()): array {
        foreach ($this->statements as $statement) {
            $update_array = $statement->get_variable_usage($update_array);
        }
        return $update_array;
    }


    /**
     * Remove the ast, and other clutter from casstrings, so we can test equality cleanly and dump values.
     */
    public function test_clean() {
        $this->session->test_clean();
        return true;
    }
}
