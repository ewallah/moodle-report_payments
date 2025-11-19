<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for payments report events.
 *
 * @package   report_payments
 * @copyright Medical Access Uganda Limited (e-learning.medical-access.org)
 * @author    Renaat Debleu <info@eWallah.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace report_payments\reportbuilder;

use context_course;
use context_system;
use context_user;
use core_reportbuilder_generator;
use core_reportbuilder_testcase;
use core_reportbuilder\system_report_factory;
use enrol_fee\payment\service_provider;
use report_payments\reportbuilder\datasource\payments;
use report_payments\reportbuilder\local\systemreports\{payments_course, payments_global, payments_user};
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("{$CFG->dirroot}/reportbuilder/tests/helpers.php");


/**
 * Class report payments global report tests
 *
 * @package   report_payments
 * @copyright Medical Access Uganda Limited (e-learning.medical-access.org)
 * @author    Renaat Debleu <info@eWallah.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(local\entities\payment::class)]
#[CoversClass(local\systemreports\payments_global::class)]
#[CoversClass(local\systemreports\payments_user::class)]
#[CoversClass(local\systemreports\payments_course::class)]
final class report_test extends core_reportbuilder_testcase {
    /** @var stdClass Course. */
    private $course;

    /** @var int User. */
    private $userid;

    /**
     * Setup testcase.
     */
    public function setUp(): void {
        global $DB, $CFG;
        parent::setUp();
        require_once("{$CFG->dirroot}/reportbuilder/tests/fixtures/system_report_available.php");
        $this->setAdminUser();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $pgen = $gen->get_plugin_generator('core_payment');
        $this->course = $gen->create_course();
        $userid = $gen->create_user()->id;
        $this->userid = $gen->create_user()->id;

        $feeplugin = enrol_get_plugin('fee');
        $account = $pgen->create_payment_account(['gateways' => 'paypal']);
        $accountid = $account->get('id');
        $data = [
            'courseid' => $this->course->id,
            'customint1' => $accountid,
            'cost' => 250,
            'currency' => 'USD',
            'roleid' => 5,
        ];
        $id = $feeplugin->add_instance($this->course, $data);
        $paymentid = $pgen->create_payment(['accountid' => $accountid, 'amount' => 20, 'userid' => $userid]);
        service_provider::deliver_order('fee', $id, $paymentid, $userid);
        $DB->set_field('user', 'deleted', true, ['id' => $userid]);
        $paymentid = $pgen->create_payment(['accountid' => $accountid, 'amount' => 10, 'userid' => $this->userid]);
        service_provider::deliver_order('fee', $id, $paymentid, $this->userid);
        $records = $DB->get_records('payments', []);
        foreach ($records as $record) {
            $DB->set_field('payments', 'paymentarea', 'fee', ['id' => $record->id]);
        }
    }

    /**
     * Test for report content global
     */
    public function test_content_global(): void {
        /** @var \core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $context = context_system::instance();
        $generator->create_report([
            'name' => 'Payments global',
            'source' => payments_global::class,
            'default' => 0,
            'type' => \core_reportbuilder\local\report\base::TYPE_SYSTEM_REPORT,
            'contextid' => $context->id,
            'component' => 'report_payments',
        ]);

        $report = system_report_factory::create(payments_global::class, $context);
        $this->assertEquals($report->get_initial_sort_column()->get_name(), 'gateway');
        $columns = $report->get_active_columns();
        $this->assertCount(7, $columns);
        $this->assertCount(7, $report->get_active_filters());
        $this->assertCount(7, $report->get_filters());
        $this->assertCount(0, $report->get_active_conditions());
        $this->assertEquals(0, $report->get_applied_filter_count());

        $report->set_initial_sort_column('payment:accountid', SORT_DESC);
        $this->assertEquals($report->get_initial_sort_column(), $columns['payment:accountid']);
    }


    /**
     * Test for report content user
     */
    public function test_content_user(): void {
        $report = system_report_factory::create(payments_user::class, context_user::instance($this->userid));
        $this->assertEquals($report->get_initial_sort_column()->get_name(), 'timecreated');
        $columns = $report->get_active_columns();
        $this->assertCount(5, $columns);
        $this->assertCount(0, $report->get_active_filters());
        $this->assertCount(0, $report->get_filters());
        $this->assertCount(0, $report->get_active_conditions());
        $this->assertEquals(0, $report->get_applied_filter_count());

        $report->set_initial_sort_column('payment:currency', SORT_DESC);
        $this->assertEquals($report->get_initial_sort_column(), $columns['payment:currency']);
    }

    /**
     * Test for report content course
     */
    public function test_content_course(): void {
        $context = context_course::instance($this->course->id);
        $report = system_report_factory::create(payments_user::class, $context);
        $columns = $report->get_active_columns();
        $report->set_initial_sort_column('payment:currency', SORT_DESC);
        $this->assertEquals($report->get_initial_sort_column(), $columns['payment:currency']);
        $this->assertCount(5, $columns);
        $this->assertCount(0, $report->get_active_conditions());
        $this->assertCount(0, $report->get_active_filters());
    }
}
