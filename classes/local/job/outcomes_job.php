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
 * Job class for sending outcome data to individual registrations.
 *
 * @package   enrol_arlo {@link https://docs.moodle.org/dev/Frankenstyle}
 * @copyright 2018 LearningWorks Ltd {@link http://www.learningworks.co.nz}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_arlo\local\job;

defined('MOODLE_INTERNAL') || die();

use enrol_arlo\api;
use enrol_arlo\Arlo\AuthAPI\RequestUri;
use enrol_arlo\local\client;
use enrol_arlo\local\persistent\registration_persistent;
use enrol_arlo\result;
use GuzzleHttp\Psr7\Request;
use moodle_exception;

/**
 * Job class for sending outcome data to individual registrations.
 *
 * @package   enrol_arlo {@link https://docs.moodle.org/dev/Frankenstyle}
 * @copyright 2018 LearningWorks Ltd {@link http://www.learningworks.co.nz}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcomes_job extends job {

    public function run() {
        $trace = self::get_trace();
        $plugin = api::get_enrolment_plugin();
        $pluginconfig = $plugin->get_plugin_config();
        if (!$pluginconfig->get('allowoutcomespushing')) {
            $this->add_reasons(get_string('outcomespushingdisabled', 'enrol_arlo'));
            return false;
        }
        $lockfactory = static::get_lock_factory();
        $lock = $lockfactory->get_lock($this->get_lock_resource(), self::TIME_LOCK_TIMEOUT);
        if ($lock) {
            $jobpersistent = $this->get_job_persistent();
            try {
                $enrolmentinstance = $plugin::get_instance_record($jobpersistent->get('instanceid'));
                if (!$enrolmentinstance) {
                    $jobpersistent->set('disabled', 1);
                    $jobpersistent->save();
                    throw new moodle_exception(get_string('nomatchingenrolmentinstance', 'enrol_arlo'));
                }
                if ($enrolmentinstance->status == ENROL_INSTANCE_DISABLED) {
                    $jobpersistent->set('timelastrequest', time());
                    $jobpersistent->save();
                    $this->add_reasons('Enrolment instance disabled.');
                    return false;
                }
                if (!$pluginconfig->get('allowhiddencourses')) {
                    $course = get_course($enrolmentinstance->courseid);
                    if (!$course->visible) {
                        $jobpersistent->set('timelastrequest', time());
                        $jobpersistent->save();
                        $this->add_reasons('Course is hidden. Allow hidden courses is not set.');
                        return false;
                    }
                }
                $registrations = registration_persistent::get_records(['enrolid' => $enrolmentinstance->id]);
                if ($registrations) {
                    foreach ($registrations as $registrationpersistent) {
                        $result = new result($enrolmentinstance->courseid, $registrationpersistent->to_record());
                        $uri = new RequestUri();
                        $uri->setHost($pluginconfig->get('platform'));
                        $endpoint = $jobpersistent->get('endpoint') . $registrationpersistent->get('id') .'/';
                        $uri->setResourcePath($endpoint);
                        $request = new Request(
                            'PATCH',
                            $uri->output(true),
                            ['Content-type' => 'application/xml; charset=utf-8'],
                            $result->export_to_xml());
                        $response = client::get_instance()->send_request($request);
                        // Failed, likely to be 400 or 409. TODO pull in values from new request.
                        if ($response->getStatusCode() != 200) {
                            $jobpersistent->add_error($response->getReasonPhrase());
                            $registrationpersistent->add_error_message($response->getReasonPhrase());
                            $registrationpersistent->save();
                            // Don't want to break whole job, so moving along.
                            continue;
                        }
                        // We need to set changed properties back on registration record. This is
                        // important as required when determining the use of add or replace in the
                        // patch request.
                        $changed = $result->get_changed();
                        foreach (get_object_vars($changed) as $field => $value) {
                            $registrationpersistent->set($field, $value);
                        }
                        // Reset update flag.
                        $registrationpersistent->set('updatesource', 0);
                        $registrationpersistent->save();
                        // Update scheduling information on persistent after successfull save.
                        $jobpersistent->set('timelastrequest', time());
                        $jobpersistent->save();
                    }
                }
                return true;
            } catch (moodle_exception $exception) {
                debugging($exception->getMessage(), DEBUG_DEVELOPER);
                $this->add_error($exception->getMessage());
                if ($exception instanceof invalid_persistent_exception || $exception instanceof coding_exception) {
                    throw $exception;
                }
                return false;
            } finally {
                $lock->release();
                // Update scheduling information.
                $jobpersistent->set('timelastrequest', time());
                $jobpersistent->update();
            }
        } else {
            throw new moodle_exception('locktimeout');
        }
    }
}
