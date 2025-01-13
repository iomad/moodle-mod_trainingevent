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
 * mod_trainingevent attendance Modal form.
 *
 * @package     mod_trainingevent
 * @copyright  2024 E-Learn Design
 * @author     Derick Turner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
namespace mod_trainingevent\form;

use context;
use context_system;
use context_course;
use context_module;
use core_form\dynamic_form;
use moodle_url;
use moodle_exception;

/**
 * Class attendance_form used for to store the company MS attendance value.
 *
 * @package mod_trainingevent
 * @copyright  2024 E-Learn Design
 * @author     Derick Turner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance extends dynamic_form {

    /** @var int companyid */
    protected $companyid;
    protected $trainingeventid;
    protected $waitlisted;

    /**
     * Process the form submission, used if form was submitted via AJAX.
     *
     * @return array
     */
    public function process_dynamic_submission(): array {
        global $DB, $USER, $COURSE;

        // Get the info from the form.
        $data = $this->get_data();
        $returnmessage = "";
        $dorefresh = $data->dorefresh;

        if (!empty($data->attendanceid)) {
            $record = $DB->get_record('trainingevent_users', ['id' => $data->attendanceid,
                                                              'trainingeventid' => $data->trainingeventid,
                                                              'userid' => $data->userid]);
        } else {
            $record = (object) ['userid' => $data->userid,
                                'trainingeventid' => $data->trainingeventid,
                                'waitlisted' => $data->waitlisted];
        }

        if (!empty($record->id) &&
            !empty($data->removeme)) {

            // Remove the user from the training event.
            $DB->delete_records('trainingevent_users', ['id' => $record->id]);
            $dorefresh = true;

            if (!empty($record->approved)) {
                // Fire an event if they were already approved.
                $eventother = ['waitlisted' => $data->waitlisted];
                $event = \mod_trainingevent\event\user_removed::create(['context' => context_module::instance($data->cmid),
                                                                        'userid' => $data->userid,
                                                                        'relateduserid' => $USER->id,
                                                                        'objectid' => $data->trainingeventid,
                                                                        'courseid' => $COURSE->id,
                                                                        'companyid' => $data->companyid,
                                                                        'other' => $eventother]);
                $event->trigger();
                $returnmessage = get_string('unattend_successful', 'mod_trainingevent');
            } else {
                // Fire an event if they weren't approved yet.
                $eventother = ['waitlisted' => $data->waitlisted];
                $event = \mod_trainingevent\event\attendance_withdrawn::create(['context' => context_module::instance($data->cmid),
                                                                                'userid' => $data->userid,
                                                                                'relateduserid' => $USER->id,
                                                                                'objectid' => $data->trainingeventid,
                                                                                'courseid' => $COURSE->id,
                                                                                'companyid' => $data->companyid,
                                                                                'other' => $eventother]);
                $event->trigger();
                $returnmessage = get_string('removerequest_successfull', 'mod_trainingevent');
            }
                
        } else if (!empty($data->requesttype) &&
                   !empty($data->removeme)) {
            // User removing request - duplicate in case they were never actually added to the event.
            $dorefresh = true;

            // Fire an event for this.
            $eventother = ['waitlisted' => $data->waitlisted];
            $event = \mod_trainingevent\event\attendance_withdrawn::create(['context' => context_module::instance($data->cmid),
                                                                            'userid' => $data->userid,
                                                                            'relateduserid' => $USER->id,
                                                                            'objectid' => $data->trainingeventid,
                                                                            'courseid' => $COURSE->id,
                                                                            'companyid' => $data->companyid,
                                                                            'other' => $eventother]);
            $event->trigger();
            $returnmessage = get_string('removerequest_successfull', 'mod_trainingevent');
        } else if (empty($data->removeme)) {

            // Adding or updating
            $record->booking_notes = $data->booking_notes;

            // Deal with the rest of this.
            if (empty($record->id)) {
                if (!empty($data->requesttype)) {
                    // We need to go through approval.
                    $record->approved = 0;

                    // Fire an event for this.
                    $eventother = ['waitlisted' => $data->waitlisted];
                    $event = \block_iomad_approve_access\event\manager_approved::create(['context' => context_module::instance($data->cmid),
                                                                                'userid' => $data->userid,
                                                                                'relateduserid' => $USER->id,
                                                                                'objectid' => $data->trainingeventid,
                                                                                'courseid' => $COURSE->id,
                                                                                'companyid' => $data->companyid,
                                                                                'other' => $eventother]);
                    $event->trigger();

                    // Set up the return message.
                    $returnmessage = get_string('request_success', 'mod_trainingevent');
                    if ($requesttype == 2) {
                        // Additional request.
                        $returnmessage = get_string('requestagain_success', 'mod_trainingevent');
                    }
                } if (empty($record->id)) {
                    // Automatically approved as not required.
                    $record->approved = 1;

                    // Fire an event for this.
                    $eventother = ['waitlisted' => $data->waitlisted];
                    $event = \mod_trainingevent\event\user_attending::create(['context' => context_module::instance($data->cmid),
                                                                              'userid' => $data->userid,
                                                                              'relateduserid' => $USER->id,
                                                                              'objectid' => $data->trainingeventid,
                                                                              'courseid' => $COURSE->id,
                                                                              'companyid' => $data->companyid,
                                                                              'other' => $eventother]);
                    $event->trigger();
                    if (empty($data->waitlisted)) {
                        $returnmessage = get_string('attend_successful', 'mod_trainingevent');
                    } else {
                        $returnmessage = get_string('attend_waitlist_successful', 'mod_trainingevent');
                    }
                }

                // Add the record.
                $record->id = $DB->insert_record('trainingevent_users', $record);
            } else {
                // Updating an existing booking
                $dorefesh = false;
                $DB->update_record('trainingevent_users', $record);
                $returnmessage = get_string('updateattendance_successful', 'mod_trainingevent');
            }
        }

        // Return stuff the the JS.
        return [
            'result' => true,
            'returnmessage' => $returnmessage,
            'dorefresh' => $dorefresh
        ];
    }

    /**
     * Define the form
     */
    public function definition () {
        global $CFG;

        $attending = $this->optional_param('attendanceid', 0, PARAM_INT);
        $waitlisted = $this->optional_param('waitlisted', 0, PARAM_INT);
        $approvaltype = $this->optional_param('approvaltype', 0, PARAM_INT);

        $mform = $this->_form;

        $mform->addElement('hidden', 'companyid');
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('hidden', 'trainingeventid');
        $mform->setType('attendanceid', PARAM_INT);
        $mform->addElement('hidden', 'attendanceid');
        $mform->setType('trainingeventid', PARAM_INT);
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'waitlisted');
        $mform->setType('waitlisted', PARAM_INT);
        $mform->addElement('hidden', 'requesttype');
        $mform->setType('requesttype', PARAM_INT);
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'dorefresh');
        $mform->setType('dorefresh', PARAM_BOOL);

        // Add the options field.
        $mform->addElement('textarea', 'booking_notes', get_string('bookingnotes', 'mod_trainingevent'), 'wrap="virtual" rows="5" cols="5"');
        if (!empty($attending)) {
            $removemestring = get_string('unattend', 'mod_trainingevent');
            if ($approvaltype != 0) {
                $removemestring = get_string('removerequest', 'mod_trainingevent');
            }
            $mform->addElement('advcheckbox', 'removeme', $removemestring, ' ', [], [0,1]);
        } else {
            $mform->addElement('hidden', 'removeme', 0);
            $mform->setType('removeme', PARAM_INT);
        }
        //$mform->hideIf('removeme', 'infoonly', true);
    }

    /**
     * Load in existing data as form defaults (not applicable).
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB, $USER;

        $companyid = $this->optional_param('companyid', 0, PARAM_INT);
        $trainingeventid = $this->optional_param('trainingeventid', 0, PARAM_INT);
        $cmid = $this->optional_param('cmid', 0, PARAM_INT);
        $waitlisted = $this->optional_param('waitlisted', 0, PARAM_INT);
        $attendanceid = $this->optional_param('attendanceid', 0, PARAM_INT);
        $cmid = $this->optional_param('cmid', 0, PARAM_INT);
        $requesttype = $this->optional_param('requesttype', 0, PARAM_INT);
        $dorefresh = $this->optional_param('dorefresh', false, PARAM_INT);
        $trainingeventid = $this->optional_param('trainingeventid', 0, PARAM_INT);
        $userid = $this->optional_param('userid', 0, PARAM_INT);
        $booking_notes = "";

        // Do we already have one?
        if ($attendancerec = $DB->get_record('trainingevent_users', ['trainingeventid' => $trainingeventid, 'userid' => $userid])) {
            $attendanceid = $attendancerec->id;
            $waitlisted = $attendancerec->waitlisted;
            $booking_notes = $attendancerec->booking_notes;
        }

        // Send it.
        $data = [
            'companyid' => $companyid,
            'attendanceid' => $attendanceid,
            'waitlisted' => $waitlisted,
            'booking_notes' => $booking_notes,
            'cmid' => $cmid,
            'requesttype' => $requesttype,
            'dorefresh' => $dorefresh,
            'userid' => $userid,
            'trainingeventid' => $trainingeventid,
        ];
        $this->set_data($data);

    }

    /**
     * Check if current user has access to this form, otherwise throw exception.
     *
     * @return void
     * @throws moodle_exception
     */
    protected function check_access_for_dynamic_submission(): void {
    }

    /**
     * Return form context
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {      
    global $COURSE;
/*        $trainingeventid = $this->optional_param('trainingeventid', 0, PARAM_INT);

        $trainingevent = $DB->get_record('trainingevent', ['id' => $trainingeventid]);
*/
        $coursecontext = \context_course::instance($COURSE->id);

        return $coursecontext;
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $cmid = $this->optional_param('cmid', 0, PARAM_INT);
        return new moodle_url('/mod/trainingevent/view.php', ['id' => $cmid]);
    }
}
