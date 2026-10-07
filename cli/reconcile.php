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
 * Reconciles cohort category role assignments from the command line.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'checks' => false,
    ],
    [
        'h' => 'help',
        'c' => 'checks',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("
Reconcile cohort category role assignments.

Makes every mapping's role assignments match its cohort membership, and releases any assignment
this plugin owns that no mapping accounts for. Safe to run at any time: it is idempotent, and it
never touches role assignments made by hand or by another plugin.

Options:
  -h, --help     Print this help.
  -c, --checks   Print the configuration checks instead of reconciling. Exits 1 if any check
                 reports a warning or a problem, so monitoring can alert on it.

Example:
  php local/cohortcategoryrole/cli/reconcile.php
");
    exit(0);
}

if ($options['checks']) {
    $checks = \local_cohortcategoryrole\local\diagnostics::run();

    foreach ($checks as $check) {
        cli_writeln(sprintf(
            '[%s] %s: %s',
            strtoupper(get_string('state' . $check->state, 'local_cohortcategoryrole')),
            get_string('check' . $check->id, 'local_cohortcategoryrole'),
            get_string('check' . $check->id . '_requirement', 'local_cohortcategoryrole')
        ));
        if ($check->found !== null) {
            $found = html_to_text($check->found, 0, false);
            cli_writeln('    ' . get_string('foundlabel', 'local_cohortcategoryrole') . ' ' . $found);
            // A terminal has room for the items the web page leaves to a detail view.
            foreach ($check->items as $item) {
                cli_writeln('      - ' . html_to_text($item->label, 0, false));
            }
        }
    }

    // A non-zero exit lets monitoring treat a warning or error as a failure.
    $worst = \local_cohortcategoryrole\local\diagnostics::worst_state($checks);
    $failed = in_array($worst, [
        \local_cohortcategoryrole\local\diagnostics::WARNING,
        \local_cohortcategoryrole\local\diagnostics::ERROR,
    ], true);
    exit($failed ? 1 : 0);
}

$totals = \local_cohortcategoryrole\local\sync::reconcile();

cli_writeln(sprintf(
    'Reconciled %d mapping(s): %d assignment(s) added, %d removed, %d orphan(s) released.',
    $totals['mappings'],
    $totals['assigned'],
    $totals['unassigned'],
    $totals['orphans']
));

exit(0);
