<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Checks that every string the plugin asks for actually exists.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Checks that every string the plugin asks for actually exists.
 *
 * A missing identifier does not break a page in a way any HTTP status reveals - the page still
 * returns 200 and simply fills with "invalid string identifier" errors. Only rendering catches it,
 * which is why this scans the source instead.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class strings_test extends \advanced_testcase {
    /**
     * Every get_string() call with literal arguments resolves, in this plugin and in core.
     *
     * @return void
     */
    public function test_every_requested_string_exists(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/cohortcategoryrole';
        $manager = get_string_manager();
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $matches = [];
            preg_match_all(
                "/get_string\(\s*'([a-z0-9_:]+)'\s*(?:,\s*'([a-z0-9_]*)')?/i",
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $identifier = $match[1];
                $component = !empty($match[2]) ? $match[2] : 'moodle';

                if (!$manager->string_exists($identifier, $component)) {
                    $missing[] = $identifier . ' (' . $component . ') in ' . $file->getFilename();
                }
            }
        }

        $this->assertSame([], $missing, 'Missing language strings: ' . implode(', ', $missing));
    }

    /**
     * Every string this plugin defines is actually used somewhere.
     *
     * Not a fault in itself, but an unused string is usually a leftover from something removed,
     * and they accumulate quietly.
     *
     * @return void
     */
    public function test_no_unused_strings(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/cohortcategoryrole';

        $strings = [];
        include($root . '/lang/en/local_cohortcategoryrole.php');
        $defined = array_keys($strings);

        $source = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }
            if (
                in_array($file->getExtension(), ['php', 'mustache'], true)
                    && $file->getFilename() !== 'local_cohortcategoryrole.php'
            ) {
                $source .= file_get_contents($file->getPathname());
            }
        }

        $unused = [];
        foreach ($defined as $identifier) {
            // Capability strings are resolved by core from db/access.php, never by name in code.
            if (str_contains($identifier, ':')) {
                continue;
            }
            // Check names, their help text and state labels are built by concatenation at render
            // time, so they never appear as literals. diagnostics_test asserts they all exist.
            if (str_starts_with($identifier, 'check') || str_starts_with($identifier, 'state')) {
                continue;
            }
            // Help strings are requested by the name of the string they document.
            if (str_ends_with($identifier, '_help') || str_ends_with($identifier, '_desc')) {
                continue;
            }
            if (!str_contains($source, "'" . $identifier . "'")) {
                $unused[] = $identifier;
            }
        }

        $this->assertSame([], $unused, 'Unused language strings: ' . implode(', ', $unused));
    }
}
