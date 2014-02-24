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
 * An activity to interface with WebEx.
 *
 * @package   mod_webexactvity
 * @copyright Eric Merrill (merrill@oakland.edu)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webexactivity;

defined('MOODLE_INTERNAL') || die();

/**
 * A class that provides general WebEx services.
 *
 * @package    mod_webexactvity
 * @copyright  2014 Eric Merrill (merrill@oakland.edu)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webex {
    /**
     * Type that represents a Meeting Center meeting.
     */
    const WEBEXACTIVITY_TYPE_MEETING = 1;

    /**
     * Type that represents a Training Center meeting.
     */
    const WEBEXACTIVITY_TYPE_TRAINING = 2;

    /**
     * Type that represents a Support Center meeting.
     */
    const WEBEXACTIVITY_TYPE_SUPPORT = 3;

    /**
     * Status that represents a meeting that has never started.
     */
    const WEBEXACTIVITY_STATUS_NEVER_STARTED = 0;

    /**
     * Status that represents a meeting that has stopped.
     */
    const WEBEXACTIVITY_STATUS_STOPPED = 1;

    /**
     * Status that represents a meeting that is in progress.
     */
    const WEBEXACTIVITY_STATUS_IN_PROGRESS = 2;

    /**
     * Time status that represents a meeting that is upcoming.
     */
    const WEBEXACTIVITY_TIME_UPCOMING = 0;

    /**
     * Time status that represents a meeting that is available.
     */
    const WEBEXACTIVITY_TIME_AVAILABLE = 1;

    /**
     * Time status that represents a meeting that is in progress.
     */
    const WEBEXACTIVITY_TIME_IN_PROGRESS = 2;

    /**
     * Time status that represents a meeting that is in the recent past.
     */
    const WEBEXACTIVITY_TIME_PAST = 3;

    /**
     * Time status that represents a meeting that is in the distant past.
     */
    const WEBEXACTIVITY_TIME_LONG_PAST = 4;

    /** @var mixed Storage for the latest errors from a connection. */
    private $latesterrors = null;

    /**
     * Loads a meeting object of the propper type.
     *
     * @param object|int     $meeting Meeting record, or id of record, to load.
     * @return bool|meeting  A meeting object or false on failure.
     */
    // TODO Delete.
    /*public static function load_meeting($meeting) {
        global $DB;

        if (is_numeric($meeting)) {
            $record = $DB->get_record('webexactivity', array('id' => $meeting));
        } else if (is_object($meeting)) {
            $record = $meeting;
        } else {
            debugging('Unable to load meeting', DEBUG_DEVELOPER);
            return false;
        }

        switch ($record->type) {
            case self::WEBEXACTIVITY_TYPE_MEETING:
                $meeting = new type\meeting_center\meeting($record);
                return $meeting;
                break;
            case self::WEBEXACTIVITY_TYPE_TRAINING:
                $meeting = new type\training_center\meeting($record);
                return $meeting;
                break;
            case self::WEBEXACTIVITY_TYPE_SUPPORT:
                debugging('Support center not yet supported', DEBUG_DEVELOPER);
                break;
            default:
                debugging('Unknown Type', DEBUG_DEVELOPER);

        }

        return false;
    }*/

    /**
     * Create a meeting object of the propper type.
     *
     * @param int     $type  The type to create.
     * @return bool|meeting  A meeting object or false on failure.
     */
    // TODO do this in meeting.
    /*public static function new_meeting($type) {
        switch ($type) {
            case self::WEBEXACTIVITY_TYPE_MEETING:
                return new type\meeting_center\meeting();
                break;
            case self::WEBEXACTIVITY_TYPE_TRAINING:
                return new type\training_center\meeting();
                break;
            case self::WEBEXACTIVITY_TYPE_SUPPORT:
                debugging('Support center not yet supported', DEBUG_DEVELOPER);
                break;
            default:
                debugging('Unknown Type', DEBUG_DEVELOPER);
        }

        return false;
    }*/

    // ---------------------------------------------------
    // User Functions.
    // ---------------------------------------------------
    /**
     * Return a WebEx user object for a given Moodle user.
     *
     * @param object    $moodleuser The moodle user object to base the WebEx user off of.
     * @param bool      $checkauth  If true, connect to WebEx and check/correct the auth of the user.
     * @return bool|webex_user  A webex_user object, or false on failure.
     */
    public function get_webex_user($moodleuser, $checkauth = false) {
        $webexuser = $this->get_webex_user_record($moodleuser);

        // User not in table, make.
        if ($webexuser === false) {
            return false;
        }

        if ($checkauth) {
            $status = $webexuser->check_user_auth();
            if ($status) {
                return $webexuser;
            } else {
                $webexuser->update_password(self::generate_password());
                return $webexuser;
            }
        } else {
            return $webexuser;
        }
    }

    // TODO Should webex_user do all this work?
    /**
     * Return a WebEx user object for a given Moodle user.
     *
     * @param object    $moodleuser The moodle user object to base the WebEx user off of.
     * @return bool|webex_user  A webex_user object, or false on failure.
     */
    public function get_webex_user_record($moodleuser) {
        global $DB;

        if (!is_object($moodleuser) || !isset($moodleuser->id)) {
            return false;
        }

        $webexuser = $DB->get_record('webexactivity_user', array('moodleuserid' => $moodleuser->id));

        if ($webexuser !== false) {
            return new \mod_webexactivity\webex_user($webexuser);
        }

        $prefix = get_config('webexactivity', 'prefix');

        $data = new \stdClass();
        $data->firstname = $moodleuser->firstname;
        $data->lastname = $moodleuser->lastname;
        $data->webexid = $prefix.$moodleuser->username;
        $data->email = $moodleuser->email;
        $data->password = self::generate_password();

        $xml = type\base\xml_gen::create_user($data);

        $response = $this->get_response($xml, false, true);

        if ($response) {
            if (isset($response['use:userId']['0']['#'])) {
                $webexuser = new \mod_webexactivity\webex_user();
                $webexuser->moodleuserid = $moodleuser->id;
                $webexuser->webexuserid = $response['use:userId']['0']['#'];
                $webexuser->webexid = $data->webexid;
                $webexuser->password = $data->password;
                if ($webexuser->save_to_db()) {
                    return $webexuser;
                } else {
                    return false;
                }
            }
        } else {
            // Failure creating user. Check to see if exists.
            if (!isset($this->latesterrors['exception'])) {
                // No info, just end here.
                return false;
            }
            $exception = $this->latesterrors['exception'];
            // User already exists with this username or email.

            if ((stripos($exception, '030004') !== false) || (stripos($exception, '030005') === false)) {
                $xml = type\base\xml_gen::get_user_info($data->webexid);

                if (!($response = $this->get_response($xml))) {
                    return false;
                }

                if (strcasecmp($data->email, $response['use:email']['0']['#']) === 0) {
                    $newwebexuser = new \mod_webexactivity\webex_user();
                    $newwebexuser->moodleuserid = $moodleuser->id;
                    $newwebexuser->webexid = $data->webexid;
                    $newwebexuser->webexuserid = $response['use:userId']['0']['#'];
                    $newwebexuser->password = '';
                    if ($newwebexuser->save_to_db()) {
                        $newwebexuser->update_password(self::generate_password());
                        return $newwebexuser;
                    } else {
                        return false;
                    }
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------
    // Support Functions.
    // ---------------------------------------------------
    /**
     * Return the base URL for the WebEx server.
     *
     * @return string  The base URL.
     */
    public static function get_base_url() {
        $host = get_config('webexactivity', 'url');

        if ($host === false) {
            return false;
        }
        $url = 'https://'.$host.'.webex.com/'.$host;

        return $url;
    }

    /**
     * Generate a password that will pass the WebEx requirements.
     *
     * @return string  The generated password.
     */
    private static function generate_password() {
        $alphabet = "abcdefghijklmnopqrstuwxyzABCDEFGHIJKLMNOPQRSTUWXYZ0123456789";
        $pass = array();
        $length = strlen($alphabet) - 1;
        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $length);
            $pass[] = $alphabet[$n];
        }
        return implode($pass).'!2Da';
    }

    /**
     * Check and update open sessions/meetings from WebEx.
     *
     * @return bool  True on success, false on failure.
     */
    public function update_open_sessions() {
        global $DB;

        $xml = type\base\xml_gen::list_open_sessions();

        $response = $this->get_response($xml);
        if ($response === false) {
            return false;
        }

        $processtime = time();
        $cleartime = $processtime - 60;

        if (is_array($response) && isset($response['ep:services'])) {
            foreach ($response['ep:services'] as $service) {
                foreach ($service['#']['ep:sessions'] as $session) {
                    $session = $session['#'];

                    $meetingkey = $session['ep:sessionKey'][0]['#'];
                    if ($meetingrecord = $DB->get_record('webexactivity', array('meetingkey' => $meetingkey))) {
                        if ($meetingrecord->status !== self::WEBEXACTIVITY_STATUS_IN_PROGRESS) {
                            $meeting = meeting::load($meetingrecord);

                            $meeting->status = self::WEBEXACTIVITY_STATUS_IN_PROGRESS;
                            $meeting->laststatuscheck = $processtime;
                            $meeting->save();
                        }
                    }
                }
            }
        }

        $select = 'laststatuscheck < ? AND status = ?';
        $params = array('lasttime' => $cleartime, 'status' => self::WEBEXACTIVITY_STATUS_IN_PROGRESS);

        if ($meetings = $DB->get_records_select('webexactivity', $select, $params)) {
            foreach ($meetings as $meetingrecord) {
                $meeting = meeting::load($meetingrecord);

                $meeting->status = self::WEBEXACTIVITY_STATUS_STOPPED;
                $meeting->laststatuscheck = $processtime;
                $meeting->save();
            }
        }
    }

    // ---------------------------------------------------
    // Recording Functions.
    // ---------------------------------------------------
    /**
     * Check and update recordings from WebEx.
     *
     * @return bool  True on success, false on failure.
     */
    public function update_recordings() {
        $params = new \stdClass();
        $params->startdate = time() - (365 * 24 * 3600);
        $params->enddate = time() + (12 * 3600);

        $xml = type\base\xml_gen::list_recordings($params);

        if (!($response = $this->get_response($xml))) {
            return false;
        }

        return $this->proccess_recording_response($response);
    }

    /**
     * Process the response of recordings from WebEx.
     *
     * @param array  The response array from WebEx.
     * @return bool  True on success, false on failure.
     */
    public function proccess_recording_response($response) {
        global $DB;

        if (!is_array($response)) {
            return true;
        }

        $recordings = $response['ep:recording'];

        $processall = (boolean)\get_config('webexactivity', 'manageallrecordings');

        foreach ($recordings as $recording) {
            $recording = $recording['#'];

            if (!isset($recording['ep:sessionKey'][0]['#'])) {
                continue;
            }

            $key = $recording['ep:sessionKey'][0]['#'];
            $meeting = $DB->get_record('webexactivity', array('meetingkey' => $key));
            if (!$meeting && !$processall) {
                continue;
            }

            $rec = new \stdClass();
            if ($meeting) {
                $rec->webexid = $meeting->id;
            } else {
                $rec->webexid = null;
            }

            // TODO Convert to use object?
            $rec->meetingkey = $key;
            $rec->recordingid = $recording['ep:recordingID'][0]['#'];
            $rec->hostid = $recording['ep:hostWebExID'][0]['#'];
            $rec->name = $recording['ep:name'][0]['#'];
            $rec->timecreated = strtotime($recording['ep:createTime'][0]['#']);
            $rec->streamurl = $recording['ep:streamURL'][0]['#'];
            $rec->fileurl = $recording['ep:fileURL'][0]['#'];
            $size = $recording['ep:size'][0]['#'];
            $size = floatval($size);
            $size = $size * 1024 * 1024;
            $rec->filesize = (int)$size;
            $rec->duration = $recording['ep:duration'][0]['#'];
            $rec->timemodified = time();

            if ($existing = $DB->get_record('webexactivity_recording', array('recordingid' => $rec->recordingid))) {
                $update = new \stdClass();
                $update->id = $existing->id;
                $update->name = $rec->name;
                $update->streamurl = $rec->streamurl;
                $update->fileurl = $rec->fileurl;
                $update->timemodified = time();

                $DB->update_record('webexactivity_recording', $update);
            } else {
                $rec->id = $DB->insert_record('webexactivity_recording', $rec);

                if ($meeting) {
                    $cm = get_coursemodule_from_instance('webexactivity', $meeting->id);
                    $context = \context_module::instance($cm->id);
                    $params = array(
                        'context' => $context,
                        'objectid' => $rec->id
                    );
                    $event = \mod_webexactivity\event\recording_created::create($params);
                    $event->add_record_snapshot('webexactivity_recording', $rec);
                    $event->add_record_snapshot('webexactivity', $meeting);
                    $event->trigger();
                }

            }
        }

        return true;
    }

    /**
     * Delete 'deleted' recordings from the WebEx server.
     */
    public function remove_deleted_recordings() {
        global $DB;

        $holdtime = get_config('webexactivity', 'recordingtrashtime');

        $params = array('time' => (time() - ($holdtime * 3600)));
        $rs = $DB->get_recordset_select('webexactivity_recording', 'deleted > 0 AND deleted < :time', $params);

        foreach ($rs as $record) {
            $recording = new webex_recording($record);
            print 'Deleting: '.$recording->name."\n";
            $recording->true_delete();
        }

        $rs->close();
    }



    // ---------------------------------------------------
    // Connection Functions.
    // ---------------------------------------------------
    /**
     * Get the response from WebEx for a XML message.
     *
     * @param string           $xml The XML to send to WebEx.
     * @param webex_user|bool  $webexuser The WebEx user to use for auth. False to use the API user.
     * @param bool             $expecterror If true, and error is possibly expected. Supress error message.
     * @return array|bool      XML response (as array). False on failure.
     */
    public function get_response($basexml, $webexuser = false, $expecterror = false) {
        global $USER;

        $xml = type\base\xml_gen::auth_wrap($basexml, $webexuser);

        list($status, $response, $errors) = $this->fetch_response($xml);

        if ($status) {
            return $response;
        } else {
            // Bad user password, reset it and try again.
            if ($webexuser && (isset($errors['exception'])) && ($errors['exception'] === '030002')) {
                $webexuser->update_password(self::generate_password());
                $xml = type\base\xml_gen::auth_wrap($basexml, $webexuser);
                list($status, $response, $errors) = $this->fetch_response($xml);
                if ($status) {
                    return $response;
                }
            }
            if ((isset($errors['exception'])) && ($errors['exception'] === '000015')) {
                return array();
            }

            if (!$expecterror && debugging('Error when processing XML', DEBUG_DEVELOPER)) {
                var_dump($errors);
            }

            return false;
        }
    }

    /**
     * Connects to WebEx and gets a response for the given, full, XML.
     *
     * To be used by get_response().
     *
     * @param string  $xml The XML message to retrieve.
     * @return array  status bool    True on success, false on failure.
     *                response array The XML response in array form.
     *                errors array   An array of errors.
     */
    private function fetch_response($xml) {
        $connector = new service_connector();
        $status = $connector->retrieve($xml);

        if ($status) {
            $response = $connector->get_response_array();
            if (isset($response['serv:message']['#']['serv:body']['0']['#']['serv:bodyContent']['0']['#'])) {
                $response = $response['serv:message']['#']['serv:body']['0']['#']['serv:bodyContent']['0']['#'];
            } else {
                $response = false;
                $status = false;
            }
        } else {
            $response = false;
        }
        $errors = $connector->get_errors();
        $this->latesterrors = $errors;

        return array($status, $response, $errors);
    }
}
